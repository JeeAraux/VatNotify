#!/usr/bin/env python3
"""Browsertest der Anleitungsseite (Ablauf 1.1) gegen den echten PHP-Code und den Telegram-Stellvertreter.

Vorher bauen:  sh test/build.sh && sh web/build.sh
Aufruf:        python3 test/test_guide.py [Ordner fuer Bildschirmfotos]
"""
import hashlib
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import time
import zipfile

from playwright.sync_api import sync_playwright

import test_server as ts

HERE = os.path.dirname(os.path.abspath(__file__))
SHOTS = sys.argv[1] if len(sys.argv) > 1 else os.path.join(HERE, "..", "build", "shots")
URL = f"{ts.BASE}/index.html"
WIN = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36"
PLUGIN = os.path.join(HERE, "..", "plugin")
problems = []


def watch(page):
    def on_console(m):
        # Der Browser protokolliert jede 4xx-Antwort; die sind in den Fehlerfall-Tests gewollt.
        if m.type in ("error", "warning") and "the server responded with a status of 4" not in m.text:
            problems.append(f"console {m.type}: {m.text}")
    page.on("console", on_console)
    page.on("pageerror", lambda e: problems.append(f"pageerror: {e}"))
    page.on("requestfailed", lambda r: problems.append(f"requestfailed: {r.url}"))


def shot(page, name, full=False):
    page.wait_for_timeout(350)
    page.screenshot(path=os.path.join(SHOTS, name + ".png"), full_page=full)


def state(page, n):
    return page.get_attribute(f"#s{n}", "data-state")


def states(page):
    return [state(page, i) for i in (1, 2, 3, 4)]


def wait_state(page, n, want, timeout=10000):
    page.wait_for_function(f"document.querySelector('#s{n}').dataset.state === '{want}'", timeout=timeout)


def stored(page, name):
    return page.evaluate(f"JSON.parse(localStorage.getItem('vtn-setup')).{name}")


def code_from_bot():
    return re.search(r"<b>(\d{4})</b>", ts.last_text()).group(1)


def plugin_poll(token, v="1.1.0"):
    """Was das Plugin alle paar Sekunden tut, solange es keinen Schluessel hat."""
    return ts.api("device_poll", token=token, v=v)[1]


def decode_qr(png_bytes):
    try:
        import cv2
        import numpy as np
    except ImportError:
        return None
    img = cv2.imdecode(np.frombuffer(png_bytes, np.uint8), cv2.IMREAD_COLOR)
    img = cv2.copyMakeBorder(img, 40, 40, 40, 40, cv2.BORDER_CONSTANT, value=(255, 255, 255))
    text, _, _ = cv2.QRCodeDetector().detectAndDecode(img)
    return text


def no_sideways(page):
    return page.evaluate("document.documentElement.scrollWidth <= window.innerWidth")


def main():
    os.makedirs(SHOTS, exist_ok=True)
    work = tempfile.mkdtemp(prefix="vtn-web-")
    root = os.path.join(work, "notify")
    shutil.copytree(ts.SRC, root)
    open(os.path.join(root, "config.php"), "w").write("<?php return " + ts.php_array({
        "bot_token": ts.TOKEN, "admin_secret": ts.ADMIN, "telegram_api": ts.TG,
        "base_url": "https://example.test/notify", "guide_url": f"{ts.BASE}/index.html",
        "vatsim_data_url": f"{ts.TG}/vatsim-data.json"}) + ";\n")
    procs = [
        subprocess.Popen([sys.executable, os.path.join(HERE, "fake_telegram.py"), str(ts.TG_PORT)]),
        subprocess.Popen(["php", "-S", f"127.0.0.1:{ts.PHP_PORT}", "-t", root],
                         stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL),
    ]
    try:
        time.sleep(1.2)
        with sync_playwright() as pw:
            browser = pw.chromium.launch(executable_path="/usr/bin/google-chrome", headless=True,
                                         args=["--no-proxy-server"])
            run(browser, root)
            browser.close()
    finally:
        for p in procs:
            p.terminate()
        shutil.rmtree(work, ignore_errors=True)
    ts.check("keine Fehler in der Browser-Konsole", not problems, "\n".join(problems))
    print(f"\n{ts.passed} bestanden, {len(ts.failed)} fehlgeschlagen")
    if ts.failed:
        print("Fehlgeschlagen:", ", ".join(ts.failed))
        sys.exit(1)


def desktop(browser, **kw):
    opts = dict(viewport={"width": 1180, "height": 900}, user_agent=WIN, locale="de-DE", accept_downloads=True)
    opts.update(kw)
    ctx = browser.new_context(**opts)
    page = ctx.new_page()
    watch(page)
    return ctx, page


def download_package(page):
    with page.expect_download() as dl:
        page.click("#b-download")
    return dl.value


def run(browser, root):
    print("Dienst noch nicht eingerichtet")
    ctx = browser.new_context(viewport={"width": 1180, "height": 900}, user_agent=WIN, locale="de-DE")
    page = ctx.new_page()
    page.goto(URL)
    page.wait_for_selector("#alert:not([hidden])")
    ts.check("Hinweis, wenn der Dienst nicht eingerichtet ist", "noch nicht fertig eingerichtet" in page.inner_text("#alert")
             and page.is_disabled("#b-download"))
    ctx.close()
    ts.http("GET", f"{ts.BASE}/setup.php?secret={ts.ADMIN}")

    # ------------------------------------------------------------------ Hauptweg
    print("Kompletter Weg am PC (deutsch)")
    ctx, page = desktop(browser)
    page.goto(URL)
    page.wait_for_function("document.fonts.ready.then(() => true)")
    page.wait_for_selector("#t-version:not(:empty)")
    ts.check("Startzustand: Schritt 1 aktiv, Rest wartet", states(page) == ["active", "todo", "todo", "todo"])
    ts.check("deutsche Ueberschrift", "Dein Handy meldet sich" in page.inner_text("h1"))
    ts.check("Fusszeile nennt die Plugin-Version 1.1.0", "1.1.0" in page.inner_text("#t-version"))
    ts.check("Fusszeile verlinkt den Quelltext", page.is_visible("#a-source") and page.is_visible("#a-privacy")
             and page.get_attribute("#a-source", "href") == "https://github.com/JeeAraux/VatNotify" and page.inner_text("#a-source") == "Quelltext")
    ts.check("Schriften geladen", page.evaluate("document.fonts.check('700 40px \"Chakra Petch\"') && document.fonts.check('400 16px \"IBM Plex Sans\"')"))
    ts.check("unter Windows kein Hinweis auf einen anderen PC", not page.is_visible("#t-windows"))
    cards = page.locator("#pile .ping")
    pile = page.inner_text("#pile")
    ts.check("fuenf Beispiel-Meldungen neben dem Einstieg", cards.count() == 5 and all(cards.nth(i).is_visible() for i in range(5))
             and "Contact me: EDDM_N_APP" in pile and "Beispiele" in page.inner_text("#pile figcaption"))
    lib = open(os.path.join(ts.SRC, "lib.php"), encoding="utf-8").read()
    parts = ["Contact me: ", "Bitte melde dich auf ", "Private Nachricht von ", "SELCAL von ", "ist jetzt online",
             "Abbestellen: /unwatch ", "Verbindung zu VATSIM getrennt", "vPilot ist nicht mehr mit dem Netzwerk verbunden."]
    ts.check("Wortlaut der Beispiele stimmt mit den echten Bot-Nachrichten ueberein", all(x in pile and x in lib for x in parts),
             str([x for x in parts if x not in pile or x not in lib]))
    box = [cards.nth(i).bounding_box() for i in range(5)]
    ts.check("die Meldungen ueberlappen sich, ohne Text zu verdecken",
             all(box[i + 1]["y"] < box[i]["y"] + box[i]["height"] for i in range(4))
             and all(page.locator("#pile .ping-text").nth(i).bounding_box()["y"] + page.locator("#pile .ping-text").nth(i).bounding_box()["height"]
                     <= box[i + 1]["y"] + 8 for i in range(4)))
    ts.check("Stapel steht rechts neben dem Text", box[0]["x"] > page.locator("h1").bounding_box()["x"] + 400)
    ts.check("vor dem Download fragt die Seite den Dienst nicht ab", page.evaluate("localStorage.getItem('vtn-setup')") in (None, "")
             or not stored(page, "token"))
    shot(page, "01-start")

    dl = download_package(page)
    ts.check("Paket heisst VatTrackrNotify.zip", dl.suggested_filename == "VatTrackrNotify.zip")
    z = zipfile.ZipFile(dl.path())
    ts.check("ZIP ist unversehrt", z.testzip() is None)
    ts.check("ZIP enthaelt Installationsprogramm, Plugin, Einstellungen und Readme",
             sorted(z.namelist()) == ["Installieren.cmd", "README.txt", "VatTrackrNotify.dll", "VatTrackrNotify.ini"], str(z.namelist()))
    ts.check("Plugin im ZIP ist bytegleich mit dem gebauten",
             z.read("VatTrackrNotify.dll") == open(os.path.join(root, "download", "VatTrackrNotify.dll"), "rb").read())
    ts.check("Installieren.cmd ist bytegleich mit der Vorlage (CRLF, ASCII)",
             z.read("Installieren.cmd") == open(os.path.join(PLUGIN, "Installieren.cmd"), "rb").read()
             and b"\r\n" in z.read("Installieren.cmd"))
    ts.check("README ist bytegleich mit der Vorlage", z.read("README.txt") == open(os.path.join(PLUGIN, "README.txt"), "rb").read())
    token = stored(page, "token")
    ini = z.read("VatTrackrNotify.ini").decode()
    ts.check("ini traegt das Token dieser Einrichtung und keinen Schluessel",
             re.fullmatch(r"[a-f0-9]{32}", token or "") is not None and f"\r\nPair={token}\r\n" in ini and "\r\nKey=\r\n" in ini, ini)
    ts.check("ini nennt die Adresse des Dienstes passend zum Ort der Seite", f"\r\nServer={ts.BASE}/api.php\r\n" in ini, ini)
    ts.check("ini hat durchgehend Windows-Zeilenenden", "\n" not in ini.replace("\r\n", ""))
    r = subprocess.run(["unzip", "-t", dl.path()], capture_output=True, text=True)
    ts.check("auch unzip haelt das Paket fuer in Ordnung", r.returncode == 0, r.stdout + r.stderr)
    wait_state(page, 1, "done")
    ts.check("Schritt 2 aktiv und wartet auf das Plugin", state(page, 2) == "active" and "Warte auf dein Plugin" in page.inner_text("#t-plugin"))
    ts.check("Schritt 2 nennt Installieren.cmd, Handweg ist eingeklappt",
             "Installieren.cmd" in page.inner_text("#s2 .sub") and not page.evaluate("document.querySelector('#d-manual').open"))
    shot(page, "02-install")
    page.click("#d-manual summary")
    ts.check("Handweg nennt Ordner und das Freigeben der DLL",
             "%LOCALAPPDATA%\\vPilot\\Plugins" in page.inner_text("#d-manual") and "Zulassen" in page.inner_text("#d-manual"))
    shot(page, "03-install-manual", full=True)
    page.click("#d-manual summary")

    page.wait_for_timeout(2500)                                # die Seite hat inzwischen mindestens einmal gefragt
    r = plugin_poll(token)                                     # vPilot startet, das Plugin fragt mit dem Token aus der ini
    ts.check("Plugin sieht, dass die Seite offen ist (oeffnet also keinen Browser)", r.get("status") == "pending" and r.get("page") is True, str(r))
    wait_state(page, 2, "done")
    ts.check("Seite merkt das Plugin von selbst, Schritt 3 aktiv", state(page, 3) == "active" and page.is_visible("#p-qr"))
    link = page.get_attribute("#a-open", "href")
    ts.check("Telegram-Link traegt dasselbe Token", link == f"https://t.me/VatTrackrTestBot?start={token}", link)
    qr_text = decode_qr(page.locator("#qr").screenshot())
    ts.check("QR-Code ist lesbar und fuehrt zum selben Link", qr_text in (link, None), str(qr_text))
    if qr_text is None:
        print("       (QR-Dekodierung uebersprungen: OpenCV fehlt)")
    shot(page, "04-qr")

    ts.tg_reset()
    ts.bot(100, f"/start {token}", "de")                       # Nutzer tippt in Telegram auf "Starten"
    page.wait_for_selector("#p-code:not([hidden])", timeout=8000)
    ts.check("Code-Eingabe erscheint von selbst und hat den Fokus", page.evaluate("document.activeElement.id") == "code")
    code = code_from_bot()
    wrong = "%04d" % ((int(code) + 1) % 10000)
    page.fill("#code", wrong)
    page.wait_for_selector("#t-code-err:not([hidden])")
    ts.check("falscher Code: klare Meldung mit Restversuchen", "noch 4 Versuche" in page.inner_text("#t-code-err"))
    shot(page, "05-code")
    page.fill("#code", code)                                   # vierte Ziffer sendet von selbst ab
    wait_state(page, 3, "done")
    key = stored(page, "key")
    ts.check("Schritt 4 aktiv, Plugin holt sich noch den Schluessel",
             state(page, 4) == "active" and "holt sich gerade den Schlüssel" in page.inner_text("#t-linked"))
    ts.check("Bot bestaetigt die Verbindung", "Telegram ist verbunden" in ts.last_text(), ts.last_text())
    ts.check("erledigte Schritte lassen sich nach der Kopplung nicht mehr oeffnen", not page.is_visible("[data-reopen='1']"))

    r = plugin_poll(token)                                     # das Plugin holt den Schluessel ab ...
    ts.check("Plugin bekommt denselben Schluessel wie die Seite", r.get("status") == "done" and r.get("key") == key, str(r))
    ts.api("notify", key=key, type="hello", v="1.1.0")         # ... und meldet sich damit
    page.wait_for_function("document.querySelector('#t-linked').dataset.kind === 'ok'", timeout=10000)
    ts.check("Seite zeigt das verbundene Plugin mit Version", "Version 1.1.0" in page.inner_text("#t-linked"))
    shot(page, "06-test")

    ts.tg_reset()
    page.click("#b-test")
    page.wait_for_selector("#p-ask:not([hidden])")
    ts.check("Testnachricht geht an Telegram", "Testnachricht" in ts.last_text() and "Schau auf dein Handy" in page.inner_text("#t-test"))
    page.click("#b-no")
    ts.check("'Nein' klappt die Hilfe auf", page.evaluate("document.querySelector('#d-help4').open"))
    page.click("#b-yes")
    page.wait_for_selector("#done:not([hidden])")
    done = page.inner_text("#done")
    ts.check("alle vier LEDs an, Abschluss sichtbar", states(page) == ["done"] * 4 and "Guten Flug" in page.inner_text("#done h2"))
    ts.check("Abschluss nennt Ton-Tipp, /settings, /atc und /watch",
             "Benachrichtigungston" in done and "/settings" in done and "/atc EDDM" in done and "/watch EDDM" in done)
    pkg = page.get_attribute("#a-pkg", "href")
    code_ = page.evaluate("(u) => fetch(u).then(r => r.arrayBuffer().then(b => b.byteLength > 10000 ? r.status : 0))", pkg)
    ts.check("Abschluss verlinkt das Paket fuer Updates, und der Dienst liefert es aus",
             pkg == "download/VatTrackrNotify.zip" and code_ == 200 and "Plugin aktualisieren" in done, f"{pkg} {code_}")
    shot(page, "07-done", full=True)

    page.click(".lang button[data-lang=en]")
    ts.check("Umschalten auf Englisch", "Your phone pings you" in page.inner_text("h1") and page.get_attribute("html", "lang") == "en"
             and "Have a good flight" in page.inner_text("#done h2"))
    pile = page.inner_text("#pile")
    parts = ["Contact me: ", "Please call on ", "Private message from ", "SELCAL from ", "is now online",
             "Stop watching: /unwatch ", "Disconnected from VATSIM", "vPilot is no longer connected to the network."]
    ts.check("englische Beispiele stimmen ebenfalls mit dem Bot ueberein", all(x in pile and x in lib for x in parts),
             str([x for x in parts if x not in pile or x not in lib]))
    shot(page, "08-done-en", full=True)
    page.reload()
    page.wait_for_selector("#done:not([hidden])")
    ts.check("Stand und Sprache bleiben nach dem Neuladen", "Your phone pings you" in page.inner_text("h1"))

    ts.bot(100, "/stop")
    page.reload()
    page.wait_for_selector("#alert:not([hidden])")
    ts.check("nach /stop: Seite erkennt den ungueltigen Schluessel", "no longer valid" in page.inner_text("#alert") and state(page, 1) == "active")
    ctx.close()

    # ------------------------------------------------------------------ Plugin zuerst
    print("Vom Plugin geoeffnet (Paket ohne Token, z. B. nach /stop)")
    _, r = ts.api("device_start", v="1.1.0")
    token = r["token"]
    ts.check("Plugin bekommt die Adresse der Anleitung mit Token", r.get("url") == f"{URL}?pair={token}" and r.get("page") is False, str(r))
    ctx, page = desktop(browser)
    page.goto(r["url"])
    wait_state(page, 3, "active")
    ts.check("Seite springt direkt zu Telegram", states(page) == ["done", "done", "active", "todo"] and page.is_visible("#p-qr"))
    ts.check("Adresszeile ist wieder sauber", page.evaluate("location.search") == "")
    ts.check("QR-Link traegt das Token des Plugins", page.get_attribute("#a-open", "href").endswith(f"start={token}"))
    page.wait_for_timeout(2500)
    ts.check("Plugin sieht die offene Seite", plugin_poll(token).get("page") is True)
    page.reload()
    wait_state(page, 3, "active")
    ts.check("nach dem Neuladen geht es bei Telegram weiter", page.get_attribute("#a-open", "href").endswith(f"start={token}"))

    print("Fuenf falsche Codes, dann neuer Code")
    ts.tg_reset()
    ts.bot(150, f"/start {token}", "de")
    page.wait_for_selector("#p-code:not([hidden])", timeout=8000)
    bad = "0000" if code_from_bot() != "0000" else "1111"
    for i in range(5):
        page.fill("#code", "")
        page.fill("#code", bad)
        page.wait_for_timeout(600)
    page.wait_for_selector("#t-fail:not([hidden])", timeout=8000)
    ts.check("nach 5 falschen Codes: Hinweis und wieder der QR-Code",
             "Zu viele falsche Codes" in page.inner_text("#t-fail") and page.is_visible("#p-qr") and not page.is_visible("#p-code"))
    shot(page, "09-failed")
    ts.tg_reset()
    ts.bot(150, f"/start {token}", "de")                       # noch einmal scannen
    page.wait_for_selector("#p-code:not([hidden])", timeout=8000)
    ts.check("neuer Code: Eingabe ist leer und ohne alte Fehlermeldung",
             page.input_value("#code") == "" and not page.is_visible("#t-code-err") and not page.is_visible("#t-fail"))
    page.fill("#code", code_from_bot())
    wait_state(page, 3, "done")
    key = stored(page, "key")
    r = plugin_poll(token)
    ts.check("Plugin holt den Schluessel ab", r.get("key") == key, str(r))
    page.click("#b-test")
    page.wait_for_selector("#p-ask:not([hidden])")
    page.click("#b-yes")
    page.wait_for_timeout(600)
    ts.check("getestet, aber Plugin noch nicht gemeldet: Schritt 4 bleibt offen", state(page, 4) == "active" and not page.is_visible("#done"))
    ts.api("notify", key=key, type="hello", v="1.1.0")
    page.wait_for_selector("#done:not([hidden])", timeout=10000)
    ts.check("sobald sich das Plugin meldet, ist alles erledigt", states(page) == ["done"] * 4)
    ctx.close()

    # ------------------------------------------------------------------ Ersatzweg und Neuladen
    print("Ersatz-Download, Neuladen, Schritt 1 wieder oeffnen")
    ctx, page = desktop(browser)
    page.goto(URL)
    page.wait_for_selector("#t-version:not(:empty)")
    before = len(problems)
    page.route("**/download/VatTrackrNotify.dll", lambda route: route.abort())
    page.click("#b-download")
    page.wait_for_selector("#p-dl-fallback:not([hidden])")
    del problems[before:]                                      # der absichtlich abgebrochene Abruf zaehlt nicht
    token = stored(page, "token")
    ini_text = page.evaluate("(u) => fetch(u).then(r => r.text())", page.get_attribute("#a-ini", "href"))
    cmd_text = page.evaluate("(u) => fetch(u).then(r => r.text())", page.get_attribute("#a-cmd", "href"))
    ts.check("Ersatzweg bietet die drei Dateien einzeln an, ini mit Token",
             page.get_attribute("#a-dll", "href") == "download/VatTrackrNotify.dll" and f"Pair={token}" in ini_text
             and cmd_text.encode() == open(os.path.join(PLUGIN, "Installieren.cmd"), "rb").read())
    ts.check("Ersatzweg geht erst nach 'Weiter' zum naechsten Schritt", state(page, 1) == "active")
    shot(page, "10-fallback")
    page.click("#b-fb-next")
    ts.check("Ersatzweg fuehrt zu Schritt 2", states(page)[:2] == ["done", "active"])
    page.unroute("**/download/VatTrackrNotify.dll")
    page.reload()
    wait_state(page, 2, "active")
    ts.check("Stand bleibt nach dem Neuladen, Token unveraendert", stored(page, "token") == token)
    page.click("[data-reopen='1']")
    ts.check("Schritt 1 laesst sich wieder oeffnen", page.is_visible("#b-download") and page.is_visible("#b-dl-next"))
    dl = download_package(page)
    ini = zipfile.ZipFile(dl.path()).read("VatTrackrNotify.ini").decode()
    ts.check("erneuter Download nutzt dasselbe Token", f"\r\nPair={token}\r\n" in ini)
    ts.check("danach ist wieder Schritt 2 offen", not page.is_visible("#b-download") and page.is_visible("#t-plugin"))

    print("Einrichtung abgelaufen")
    path = os.path.join(root, "data", "pair", hashlib.sha256(token.encode()).hexdigest() + ".php")
    head, body = open(path).read().split("\n", 1)
    d = json.loads(body)
    d["created"] -= 4000
    open(path, "w").write(head + "\n" + json.dumps(d))
    page.wait_for_selector("#alert:not([hidden])", timeout=8000)
    ts.check("abgelaufen: klare Meldung, zurueck zu Schritt 1",
             "60 Minuten" in page.inner_text("#alert") and states(page) == ["active", "todo", "todo", "todo"] and not stored(page, "token"))
    dl = download_package(page)
    ts.check("neuer Download holt ein frisches Token und raeumt die Meldung weg",
             stored(page, "token") not in ("", token) and not page.is_visible("#alert"))
    ctx.close()

    # ------------------------------------------------------------------ Handy
    print("Handy-Ansicht (englisch)")
    ctx = browser.new_context(viewport={"width": 390, "height": 844}, locale="en-GB", has_touch=True, is_mobile=True,
                              device_scale_factor=2)
    page = ctx.new_page()
    watch(page)
    page.goto(URL)
    page.wait_for_function("document.fonts.ready.then(() => true)")
    page.wait_for_selector("#t-version:not(:empty)")
    ts.check("Sprache folgt dem Browser", "Your phone pings you" in page.inner_text("h1"))
    ts.check("ohne Windows erscheint der Hinweis auf den vPilot-PC", page.is_visible("#t-windows") and "only runs on Windows" in page.inner_text("#t-windows"))
    ts.check("kein waagerechtes Scrollen", no_sideways(page))
    ts.check("Beispiel-Meldungen stehen am Handy unter dem Text", page.locator("#pile .ping").first.bounding_box()["y"]
             > page.locator(".needs").bounding_box()["y"] and page.locator("#pile .ping").count() == 5)
    shot(page, "11-mobile-start", full=True)
    _, r = ts.api("device_start", v="1.1.0")
    page.goto(r["url"])                                        # Adresse vom Plugin, am Handy geoeffnet
    wait_state(page, 3, "active")
    ts.check("kein waagerechtes Scrollen mit QR-Code", no_sideways(page))
    shot(page, "12-mobile-qr", full=True)
    ts.tg_reset()
    ts.bot(300, f"/start {r['token']}", "en")
    page.wait_for_selector("#p-code:not([hidden])", timeout=8000)
    page.fill("#code", code_from_bot())
    wait_state(page, 3, "done")
    ts.check("kein waagerechtes Scrollen in Schritt 4", no_sideways(page))
    shot(page, "13-mobile-test", full=True)
    ctx.close()


if __name__ == "__main__":
    main()
