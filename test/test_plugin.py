#!/usr/bin/env python3
"""Ende-zu-Ende-Test: Stellvertreter-vPilot -> Plugin -> PHP-Server -> Stellvertreter-Telegram.

Vorher bauen:  sh test/build.sh
Aufruf:        python3 test/test_plugin.py

Die Tests laufen unter Mono. Sie pruefen das Verhalten des Plugins, nicht, ob jede
verwendete Methode unter Windows existiert; dafuer sorgt der Bau gegen die
Referenz-Bibliotheken des .NET Framework (siehe build.sh).
"""
import os
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import time

import test_server as ts

HERE = os.path.dirname(os.path.abspath(__file__))
BUILD = os.path.join(HERE, "..", "build")
SERVER = f"{ts.BASE}/api.php"
OPEN_RE = re.compile(r"Opened the setup page|Could not open the browser")


def plugins(host):
    return os.path.join(BUILD, host, "Plugins")


def ini_path(host):
    return os.path.join(plugins(host), "VatTrackrNotify.ini")


def write_ini(host, key="", server=SERVER, pair="", extra="OpenBrowser=0"):
    with open(ini_path(host), "w", newline="\r\n") as f:
        f.write(f"; Test\n[VatTrackrNotify]\nKey = {key}\nPair={pair}\nServer={server}\n{extra}\n")


def read_ini(host):
    values = {}
    if os.path.exists(ini_path(host)):
        for line in open(ini_path(host), encoding="utf-8-sig").read().splitlines():
            if "=" in line and not line.lstrip().startswith(";"):
                k, v = line.split("=", 1)
                values[k.strip().lower()] = v.strip()
    return values


def host_env(extra_path=None):
    # Kein Test darf das Internet erreichen: alles ausser dem lokalen Rechner laeuft ins Leere.
    dead = "http://127.0.0.1:9"
    env = dict(os.environ, no_proxy="127.0.0.1,localhost", NO_PROXY="127.0.0.1,localhost",
               http_proxy=dead, https_proxy=dead, HTTP_PROXY=dead, HTTPS_PROXY=dead)
    if extra_path:
        env["PATH"] = extra_path + os.pathsep + env["PATH"]
    return env


def run_host(host, *args, timeout=90, extra_path=None):
    exe = os.path.join(BUILD, host, "FakeHost.exe")
    r = subprocess.run(["mono", exe, plugins(host), *args], capture_output=True, text=True,
                       timeout=timeout, env=host_env(extra_path))
    return r.returncode, r.stdout + r.stderr


def start_host(host, *args, extra_path=None):
    exe = os.path.join(BUILD, host, "FakeHost.exe")
    return subprocess.Popen(["mono", exe, plugins(host), *args], stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                            text=True, env=host_env(extra_path))


def finish(proc, timeout=90):
    out, _ = proc.communicate(timeout=timeout)
    return proc.returncode, out


def log_text(host):
    path = os.path.join(plugins(host), "VatTrackrNotify.log")
    return open(path).read() if os.path.exists(path) else ""


def wait_for(cond, seconds=15, step=0.5):
    end = time.time() + seconds
    while time.time() < end:
        value = cond()
        if value:
            return value
        time.sleep(step)
    return cond()


def texts():
    return [c["params"]["text"] for c in ts.tg_calls("sendMessage")]


def connect_telegram(chat, token):
    """Was der Nutzer auf der Seite und im Bot tut: Link oeffnen, Code eingeben."""
    ts.bot(chat, f"/start {token}", "de")
    code = re.search(r"<b>(\d{4})</b>", ts.last_text()).group(1)
    return ts.api("pair_confirm", token=token, code=code)[1].get("key", "")


def session(host, label, chat):
    print(f"Sitzung mit {label}")
    key = ts.newkey(chat)
    write_ini(host, key)
    ts.tg_reset()
    code, out = run_host(host)
    ts.check("Host laeuft sauber durch", code == 0 and "Exception" not in out, out)
    ts.check("Plugin meldet sich im vPilot-Protokoll", "[debug] VatTrackr Notify: Version 1.1.0 loaded." in out, out)
    t = texts()
    joined = "\n---\n".join(t)
    ts.check("genau 6 Nachrichten (hello, contact, radio, selcal, private, disconnect)", len(t) == 6, f"{len(t)}:\n{joined}")
    ts.check("Reihenfolge bleibt erhalten", len(t) == 6 and "Plugin verbunden" in t[0] and "Contact me" in t[1]
             and "Funk-Text" in t[2] and "SELCAL" in t[3] and "Private Nachricht" in t[4] and "getrennt" in t[5], joined)
    ts.check("Contact me mit Absender und Frequenz", "Contact me: EDMM_CTR" in joined and "<b>132.555</b>" in joined)
    ts.check("Funk-Text nur fuers eigene Callsign, Frequenz 23725 -> 123.725",
             "cleared to land runway 26R" in joined and "auf 123.725" in joined and "must be ignored" not in joined, joined)
    ts.check("SELCAL-Frequenz 31800 -> 131.800", "SELCAL von EGGX_FSS</b> auf 131.800" in joined, joined)
    log = log_text(host)
    ts.check("Logdatei nennt Start und Verbindung", "Version 1.1.0 loaded." in log and "Connected to the notification service." in log, log)
    ts.check("Logdatei enthaelt weder Schluessel noch Nachrichten", key not in log and "contact me" not in log.lower() and "EDMM" not in log, log)
    ts.check("Umlaute, Anfuehrungszeichen, Backslash, Emoji kommen heil an",
             'Grüße – ça va? "quoted" back\\slash &lt;tag&gt; &amp;amp; 😀 Tab' in joined, joined)
    ts.check("vorhandener Schluessel bleibt in der ini stehen", read_ini(host).get("key") == key, str(read_ini(host)))
    return key


def main():
    if not os.path.exists(os.path.join(BUILD, "VatTrackrNotify.dll")):
        sys.exit("Bitte zuerst bauen: sh test/build.sh")
    work = tempfile.mkdtemp(prefix="vtn-e2e-")
    root = os.path.join(work, "notify")
    shutil.copytree(ts.SRC, root)
    config = {"bot_token": ts.TOKEN, "admin_secret": ts.ADMIN, "telegram_api": ts.TG,
              "base_url": "https://example.test/notify", "guide_url": f"{ts.BASE}/",
              "vatsim_data_url": f"{ts.TG}/vatsim-data.json"}
    cfg_path = os.path.join(root, "config.php")
    open(cfg_path, "w").write("<?php return " + ts.php_array(config) + ";\n")
    procs = [
        subprocess.Popen([sys.executable, os.path.join(HERE, "fake_telegram.py"), str(ts.TG_PORT)]),
        subprocess.Popen(["php", "-S", f"127.0.0.1:{ts.PHP_PORT}", "-t", root],
                         stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL),
    ]
    try:
        time.sleep(1.2)
        ts.http("GET", f"{ts.BASE}/setup.php?secret={ts.ADMIN}")

        key = session("host", "nachgebauter vPilot-Schnittstelle", 700)
        session("hostv", "abweichenden Ereignis-Signaturen (Robustheit)", 701)

        print("Einrichtung: Paket von der Anleitungsseite (Token in der ini)")
        _, r = ts.api("pair_start")
        token = r["token"]
        write_ini("host", pair=token)
        ts.tg_reset()
        proc = start_host("host", "sitzung:14")
        seen = wait_for(lambda: ts.api("pair_status", token=token)[1].get("plugin"), 10)
        ts.check("Seite sieht das Plugin, sobald vPilot startet", seen is True)
        new_key = connect_telegram(710, token)
        got = wait_for(lambda: read_ini("host").get("key") == new_key, 10)
        ts.check("Plugin holt sich den Schluessel und schreibt ihn in die ini", got and new_key.startswith("VT-"), str(read_ini("host")))
        ts.check("Token steht danach nicht mehr in der ini", read_ini("host").get("pair") == "", str(read_ini("host")))
        ts.check("uebrige Zeilen der ini bleiben stehen", read_ini("host").get("server") == SERVER and read_ini("host").get("openbrowser") == "0")
        code, out = finish(proc)
        t = "\n".join(texts())
        ts.check("danach laeuft alles: Plugin verbunden, Nachrichten kommen an",
                 code == 0 and "Plugin verbunden" in t and "Contact me: EDMM_CTR" in t and "getrennt" in t, out + t)
        ts.check("Logdatei beschreibt die Einrichtung, ohne den Schluessel zu nennen",
                 "Waiting for the setup" in log_text("host") and "Telegram is connected" in log_text("host") and new_key not in log_text("host"),
                 log_text("host"))

        print("Einrichtung: Paket ohne Token, Plugin beginnt selbst und oeffnet den Browser")
        write_ini("host", extra="")                     # wie das Paket fuer alle: weder Schluessel noch Token
        fake_bin = os.path.join(work, "bin")
        os.makedirs(fake_bin)
        opened = os.path.join(work, "opened.txt")
        for name in ("xdg-open", "gnome-open", "kfmclient", "open"):
            script = os.path.join(fake_bin, name)
            open(script, "w").write(f"#!/bin/sh\necho \"$@\" >> {opened}\n")
            os.chmod(script, os.stat(script).st_mode | stat.S_IEXEC)
        ts.tg_reset()
        proc = start_host("host", "sitzung:22", extra_path=fake_bin)
        tok = wait_for(lambda: read_ini("host").get("pair"), 10)
        ts.check("ohne Schluessel und Token: Plugin beginnt die Einrichtung selbst und merkt sich das Token",
                 bool(tok) and re.fullmatch(r"[a-f0-9]{32}", tok or "") is not None, str(read_ini("host")))
        # Unter Mono laesst sich das Oeffnen selbst nicht nachstellen; geprueft wird, dass das Plugin
        # es nach ein paar Sekunden versucht, und zwar mit der richtigen Adresse.
        tried = wait_for(lambda: OPEN_RE.search(log_text("host")), 16)
        ts.check("nach ein paar Sekunden ohne offene Seite will es die Anleitung im Browser oeffnen",
                 bool(tried) and (f"{ts.BASE}/?pair={tok}" in log_text("host")), log_text("host"))
        key2 = connect_telegram(711, tok)
        got = wait_for(lambda: read_ini("host").get("key") == key2, 10)
        ts.check("Schluessel kommt an und wird gespeichert", bool(got), str(read_ini("host")))
        code, out = finish(proc)
        ts.check("und die Nachrichten laufen", code == 0 and "Contact me: EDMM_CTR" in "\n".join(texts()), out)
        ts.check("der Browser wird nur einmal geoeffnet", len(OPEN_RE.findall(log_text("host"))) == 1, log_text("host"))

        print("Einrichtung: Sonderfaelle")
        write_ini("host", pair="0" * 32)
        proc = start_host("host", "warten:9")
        tok = wait_for(lambda: read_ini("host").get("pair") not in ("", None, "0" * 32) and read_ini("host").get("pair"), 9)
        code, out = finish(proc)
        ts.check("abgelaufenes Token: Plugin holt sich ein neues", code == 0 and bool(tok), str(read_ini("host")) + out)

        write_ini("host", "VT-AAAA-BBBB-CCCC-DDDD")
        ts.tg_reset()
        code, out = run_host("host", "warten:8")
        ts.check("ungueltiger Schluessel: Plugin vergisst ihn und beginnt die Einrichtung neu",
                 code == 0 and "no longer valid" in out and "Waiting for the setup" in out
                 and read_ini("host").get("key") == "" and len(read_ini("host").get("pair", "")) == 32, out + str(read_ini("host")))
        ts.check("ungueltiger Schluessel: nichts wird gesendet", texts() == [])

        write_ini("host", pair="", extra="OpenBrowser=0")
        code, out = run_host("host", "warten:12", extra_path=fake_bin)
        ts.check("OpenBrowser=0: Browser bleibt zu, Adresse steht in der Logdatei",
                 not OPEN_RE.search(log_text("host")) and "Waiting for the setup to be completed: " + ts.BASE in log_text("host"),
                 log_text("host"))

        broken_bin = os.path.join(work, "broken")
        os.makedirs(broken_bin)
        for name in ("xdg-open", "gnome-open", "kfmclient", "open"):
            script = os.path.join(broken_bin, name)
            open(script, "w").write("#!/bin/sh\nexit 1\n")
            os.chmod(script, os.stat(script).st_mode | stat.S_IEXEC)
        write_ini("host", extra="")
        code, out = run_host("host", "warten:12", extra_path=broken_bin)
        ts.check("scheitert das Oeffnen des Browsers, laeuft das Plugin trotzdem weiter",
                 code == 0 and "Exception" not in out and "Waiting for the setup" in out and "Setup failed" not in out, out)

        os.remove(ini_path("host"))
        code, out = run_host("host", "warten:2")
        ts.check("ganz ohne ini: Plugin startet mit Standardwerten und stuerzt nicht ab",
                 code == 0 and "Version 1.1.0 loaded." in out and "Could not start" not in out, out)

        print("Stoerfaelle")
        write_ini("host", key)
        ts.tg_reset()
        code, out = run_host("host", "nur-laden")
        ts.check("erneuter Start: hello ohne zweite Telegram-Nachricht", code == 0 and texts() == [], out)

        write_ini("host", key, extra="OpenBrowser=0\nPrivateMessages=0\nSelcal = off\nDisconnect=nein")
        ts.tg_reset()
        code, out = run_host("host")
        t = texts()
        ts.check("in der ini abgeschaltete Arten werden nicht gesendet", len(t) == 1 and "Funk-Text" in t[0], "\n".join(t))

        write_ini("host", '"' + key + '"')
        ts.tg_reset()
        code, out = run_host("host", "nur-laden")
        ts.check("Schluessel in Anfuehrungszeichen wird verstanden", code == 0 and "no longer valid" not in out and "Could not start" not in out, out)

        write_ini("host", key, server="http://example.org/api.php")
        ts.tg_reset()
        code, out = run_host("host", "nur-laden")
        ts.check("unverschluesselte fremde Adresse wird nicht benutzt", code == 0 and texts() == [], out)

        open(os.path.join(root, "redir.php"), "w").write("<?php header('Location: /api.php', true, 301);\n")
        write_ini("host", key, server=f"{ts.BASE}/redir.php", extra="OpenBrowser=0\nSelcal=0\nRadioMessages=0\nDisconnect=0")
        ts.tg_reset()
        code, out = run_host("host")
        t = texts()
        ts.check("Umleitung (301): Nachricht kommt trotzdem an", code == 0 and len(t) == 2 and "Contact me: EDMM_CTR" in t[0], out + "\n".join(t))

        write_ini("host", key, server=f"{ts.BASE}/gibtsnicht.php")
        code, out = run_host("host", "nur-laden")
        ts.check("falsche Adresse (404): Hinweis in vPilot statt Schweigen", code == 0 and "answered HTTP 404" in out, out)

        config["plugin_latest"] = "1.3.0"
        open(cfg_path, "w").write("<?php return " + ts.php_array(config) + ";\n")
        write_ini("host", key)
        ts.tg_reset()
        code, out = run_host("host", "nur-laden")
        ts.check("Update-Hinweis erscheint in vPilot und per Telegram",
                 "Version 1.3.0 is available" in out and "Neue Plugin-Version 1.3.0" in "\n".join(texts()), out + "\n".join(texts()))

        write_ini("host", key, server="http://127.0.0.1:9/api.php")
        start = time.time()
        code, out = run_host("host", "nur-laden")
        ts.check("Server nicht erreichbar: vPilot-Start wird nicht aufgehalten",
                 code == 0 and "Version 1.1.0 loaded" in out and time.time() - start < 8, f"{time.time() - start:.1f}s {out}")
    finally:
        for p in procs:
            p.terminate()
        shutil.rmtree(work, ignore_errors=True)
    print(f"\n{ts.passed} bestanden, {len(ts.failed)} fehlgeschlagen")
    if ts.failed:
        print("Fehlgeschlagen:", ", ".join(ts.failed))
        sys.exit(1)


if __name__ == "__main__":
    main()
