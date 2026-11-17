#!/usr/bin/env python3
"""Durchlauftest fuer die Server-Skripte gegen einen Stellvertreter der Telegram-API.

Aufruf:  python3 test_server.py           (startet PHP und den Stellvertreter selbst)
"""
import glob
import hashlib
import hmac
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.join(HERE, "..", "server", "vatnotify")
PHP_PORT, TG_PORT = 9090, 9091
BASE = f"http://127.0.0.1:{PHP_PORT}"
TG = f"http://127.0.0.1:{TG_PORT}"
TOKEN = "123456789:TEST_abcdefghijklmnopqrstuvwxyz012345"
ADMIN = "admin-secret-for-tests-0001"
SECRET = hmac.new(TOKEN.encode(), b"vtn-webhook", hashlib.sha256).hexdigest()
CRON = hmac.new(TOKEN.encode(), b"vtn-cron", hashlib.sha256).hexdigest()[:32]

passed = 0
failed = []


def check(name, cond, detail=""):
    global passed
    if cond:
        passed += 1
        print(f"  ok   {name}")
    else:
        failed.append(name)
        print(f"  FAIL {name}  {detail}")


def http(method, url, data=None, headers=None, as_json=True):
    body = None
    hdrs = dict(headers or {})
    if data is not None:
        if as_json:
            body = json.dumps(data).encode()
            hdrs.setdefault("Content-Type", "application/json")
        else:
            body = urllib.parse.urlencode(data).encode()
            hdrs.setdefault("Content-Type", "application/x-www-form-urlencoded")
    req = urllib.request.Request(url, data=body, method=method, headers=hdrs)
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    try:
        with opener.open(req, timeout=15) as r:
            return r.status, r.read().decode(), dict(r.headers)
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode(), dict(e.headers)


def api(action, method="POST", **params):
    code, text, hdrs = http(method, f"{BASE}/api.php?action={action}", params if method == "POST" else None)
    try:
        return code, json.loads(text)
    except ValueError:
        return code, {"_raw": text}


def tg_calls(method=None):
    calls = json.loads(http("GET", f"{TG}/_calls")[1])
    return [c for c in calls if method is None or c["method"] == method]


def tg_reset():
    http("POST", f"{TG}/_reset", {})


def last_text():
    msgs = tg_calls("sendMessage")
    return msgs[-1]["params"]["text"] if msgs else ""


def bot(chat, text, lang="de", chat_type="private", secret=SECRET):
    update = {"update_id": int(time.time() * 1000) % 10**9, "message": {
        "message_id": 1, "date": int(time.time()), "text": text,
        "chat": {"id": chat, "type": chat_type},
        "from": {"id": chat, "is_bot": False, "first_name": "Test", "language_code": lang}}}
    hdr = {"X-Telegram-Bot-Api-Secret-Token": secret} if secret else {}
    return http("POST", f"{BASE}/webhook.php", update, hdr)


def pair(chat, lang="de"):
    """Kompletter Kopplungsweg wie auf der Anleitungsseite; liefert den Schluessel."""
    _, r = api("pair_start")
    token = r["token"]
    bot(chat, f"/start {token}", lang)
    code = re.search(r"<b>(\d{4})</b>", last_text()).group(1)
    _, r = api("pair_confirm", token=token, code=code)
    return r["key"], token


def main():
    work = tempfile.mkdtemp(prefix="vtn-test-")
    root = os.path.join(work, "notify")
    shutil.copytree(SRC, root)
    with open(os.path.join(root, "config.php"), "w") as f:
        f.write("<?php return " + php_array({
            "bot_token": TOKEN, "admin_secret": ADMIN, "telegram_api": TG,
            "base_url": "https://example.test/notify", "guide_url": "https://example.test/notify/",
            "allowed_origins": ["https://vattrackr.de"], "rate_per_minute": 6, "rate_per_day": 300,
            "vatsim_data_url": f"{TG}/vatsim-data.json", "watch_max": 3,
        }) + ";\n")
    procs = [
        subprocess.Popen([sys.executable, os.path.join(HERE, "fake_telegram.py"), str(TG_PORT)]),
        subprocess.Popen(["php", "-S", f"127.0.0.1:{PHP_PORT}", "-t", root],
                         stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL),
    ]
    try:
        time.sleep(1.2)
        run(root)
    finally:
        for p in procs:
            p.terminate()
        shutil.rmtree(work, ignore_errors=True)
    print(f"\n{passed} bestanden, {len(failed)} fehlgeschlagen")
    if failed:
        print("Fehlgeschlagen:", ", ".join(failed))
        sys.exit(1)


def php_array(d):
    def conv(v):
        if isinstance(v, bool):
            return "true" if v else "false"
        if isinstance(v, (int, float)):
            return str(v)
        if isinstance(v, list):
            return "[" + ", ".join(conv(x) for x in v) + "]"
        return "'" + str(v).replace("\\", "\\\\").replace("'", "\\'") + "'"
    return "[" + ", ".join(f"'{k}' => {conv(v)}" for k, v in d.items()) + "]"


def run(root):
    print("Einrichtung")
    code, r = api("info", "GET")
    check("info vor setup meldet setup_missing", r.get("error") == "setup_missing", str(r))
    code, r = api("pair_start")
    check("pair_start vor setup abgelehnt", code == 503, str(code))
    code, text, _ = http("GET", f"{BASE}/setup.php?secret=falsch")
    check("setup mit falschem Passwort gesperrt", code == 403)
    code, text, _ = http("GET", f"{BASE}/setup.php?secret={ADMIN}")
    check("setup laeuft durch", code == 200 and "FEHLER" not in text and "Fertig" in text, text)
    check("setup weist auf die fehlende regelmaessige Pruefung hin", "[OFFEN] cron.php" in text, text)
    hook = tg_calls("setWebhook")
    check("Webhook mit Geheimnis gesetzt", len(hook) == 1 and hook[0]["params"]["secret_token"] == SECRET
          and hook[0]["params"]["url"] == "https://example.test/notify/webhook.php")
    check("Webhook nimmt auch Tastendruecke an", hook[0]["params"]["allowed_updates"] == ["message", "callback_query"])
    check("setup nennt die Adresse fuer cron.php", f"/cron.php?key={CRON}" in text, text)
    check("Befehlslisten gesetzt", len(tg_calls("setMyCommands")) == 2)
    code, r = api("info", "GET")
    check("info nennt Bot", r.get("ok") and r.get("bot") == "VatTrackrTestBot", str(r))

    print("Webhook-Schutz")
    code, _, _ = bot(100, "/start", secret=None)
    check("Webhook ohne Geheimnis abgelehnt", code == 403)
    code, _, _ = bot(100, "/start", secret="x" * 64)
    check("Webhook mit falschem Geheimnis abgelehnt", code == 403)
    tg_reset()
    code, _, _ = http("POST", f"{BASE}/webhook.php", {"update_id": 1, "edited_message": {}},
                      {"X-Telegram-Bot-Api-Secret-Token": SECRET})
    check("unbekannte Update-Art wird still angenommen", code == 200 and not tg_calls("sendMessage"))

    print("Bot ohne Kopplung")
    tg_reset()
    bot(100, "/start", "de")
    check("Begruessung deutsch", "Zur Einrichtung" in last_text() and "example.test/notify" in last_text(), last_text())
    bot(101, "/start", "fr")
    check("Begruessung englisch fuer andere Sprachen", "To set it up" in last_text())
    bot(100, "/status")
    check("/status ohne Kopplung", "noch nicht verbunden" in last_text())
    bot(100, "/stop")
    check("/stop ohne Daten", "nichts gespeichert" in last_text())
    tg_reset()
    bot(-500, "hallo", chat_type="group")
    check("Gruppen werden ignoriert", not tg_calls("sendMessage"))

    print("Kopplung ueber die Anleitungsseite")
    code, r = api("pair_start")
    token = r.get("token", "")
    check("pair_start liefert Token und Link", code == 200 and re.fullmatch(r"[a-f0-9]{32}", token) is not None
          and r["link"] == f"https://t.me/VatTrackrTestBot?start={token}", str(r))
    code, r = api("pair_status", token=token)
    check("Status pending", r.get("status") == "pending")
    code, r = api("pair_confirm", token=token, code="0000")
    check("Bestaetigen vor Telegram-Schritt abgelehnt", code == 409 and "key" not in r, str(r))
    tg_reset()
    bot(100, f"/start {token}", "de")
    m = re.search(r"<b>(\d{4})</b>", last_text())
    check("Bot nennt vierstelligen Code", m is not None, last_text())
    code4 = m.group(1)
    code, r = api("pair_status", token=token)
    check("Status code, Code wird nicht verraten", r.get("status") == "code" and code4 not in json.dumps(r), str(r))
    wrong = "%04d" % ((int(code4) + 1) % 10000)
    code, r = api("pair_confirm", token=token, code=wrong)
    check("falscher Code abgelehnt", code == 400 and r.get("error") == "wrong_code" and r.get("left") == 4, str(r))
    tg_reset()
    code, r = api("pair_confirm", token=token, code=code4)
    key = r.get("key", "")
    check("richtiger Code liefert Schluessel", code == 200 and re.fullmatch(r"VT(-[A-Z2-9]{4}){4}", key) is not None, str(r))
    check("Bot bestaetigt Verbindung", "Telegram ist verbunden" in last_text(), last_text())
    code, r = api("pair_status", token=token)
    check("Status done mit Schluessel (Seite neu geladen)", r.get("status") == "done" and r.get("key") == key)
    code, r = api("pair_confirm", token=token, code="9999")
    check("erneutes Bestaetigen ist harmlos", code == 200 and r.get("key") == key)

    print("Schluessel und Status")
    code, r = api("status", key=key)
    check("status: Plugin noch nicht gesehen", code == 200 and r.get("seen") is None, str(r))
    code, r = api("status", key=key.replace("-", " ").lower())
    check("Schluessel in anderer Schreibweise akzeptiert", code == 200)
    code, r = api("status", key="VT-AAAA-BBBB-CCCC-DDDD")
    check("unbekannter Schluessel -> 401", code == 401)
    code, r = api("status", key="kaputt")
    check("ungueltiger Schluessel -> 401", code == 401)
    bot(100, "/status")
    check("/status: Plugin fehlt noch", "noch nicht gemeldet" in last_text())

    print("Meldungen des Plugins")
    tg_reset()
    code, r = api("notify", key=key, type="hello", v="1.1.0")
    check("hello angenommen", code == 200 and r.get("latest") == "1.1.0", str(r))
    check("erstes hello meldet sich in Telegram", "Plugin verbunden" in last_text() and "1.1.0" in last_text())
    tg_reset()
    api("notify", key=key, type="hello", v="1.1.0")
    check("zweites hello bleibt still", not tg_calls("sendMessage"))
    code, r = api("status", key=key)
    check("status: Plugin gesehen", r.get("seen") is not None and r.get("version") == "1.1.0", str(r))

    code, r = api("notify", key=key, type="private", **{"from": "edmm_ctr"}, text="Please contact me on 132.55")
    t = last_text()
    check("Contact me erkannt", code == 200 and "Contact me: EDMM_CTR" in t and "<b>132.550</b>" in t, t)
    msg = tg_calls("sendMessage")[-1]["params"]
    check("geht an den richtigen Chat, als HTML", msg["chat_id"] == 100 and msg["parse_mode"] == "HTML")

    api("notify", key=key, type="private", **{"from": "DLH4AB"}, text="hi <b>there</b> & {from} {freq}")
    t = last_text()
    check("HTML wird entschaerft, Platzhalter bleiben Text",
          "Private Nachricht von DLH4AB" in t and "&lt;b&gt;there&lt;/b&gt; &amp; {from} {freq}" in t, t)

    api("notify", key=key, type="private", **{"from": "VATSIM1_SUP"}, text="Are you there? Contact 122.800")
    check("Supervisor wird hervorgehoben", "Supervisor VATSIM1_SUP" in last_text(), last_text())

    api("notify", key=key, type="radio", **{"from": "EDDM_TWR"}, text="DLH123 wind 270 5 kt", freq="118.705")
    t = last_text()
    check("Funk-Text mit Frequenz", "Funk-Text von EDDM_TWR</b> auf 118.705" in t and "DLH123 wind" in t, t)

    api("notify", key=key, type="selcal", **{"from": "EGGX_FSS"}, freq="131.800")
    check("SELCAL", "SELCAL von EGGX_FSS</b> auf 131.800" in last_text(), last_text())

    code, r = api("notify", key=key, type="disconnect")
    check("Disconnect", code == 200 and "Verbindung zu VATSIM getrennt" in last_text())

    # Limit: 6 je Minute; bisher gezaehlt: contact, private, sup, radio, selcal, disconnect = 6
    code, r = api("notify", key=key, type="private", **{"from": "X"}, text="zu viel")
    check("Limit je Minute greift (429)", code == 429 and r.get("error") == "rate", f"{code} {r}")
    code, r = api("test", key=key)
    check("Test zaehlt ebenfalls gegen das Limit", code == 429)

    code, r = api("notify", key=key, type="quatsch")
    check("unbekannter Typ -> 400", code == 400)
    code, r = api("notify", key=key, type="private", **{"from": "A"}, text="")
    check("leerer Text -> 400", code == 400)
    code, r = api("notify", "GET")
    check("notify nur per POST", code == 405)
    code, text, _ = http("POST", f"{BASE}/api.php?action=notify", {"key": key, "text": "x" * 9000})
    check("zu grosser Aufruf -> 413", code == 413)
    code, r = api("gibtsnicht")
    check("unbekannte Aktion -> 404", code == 404)

    print("Formular-Kodierung und CORS (Anleitungsseite auf anderer Domain)")
    code, text, hdrs = http("POST", f"{BASE}/api.php", {"action": "status", "key": key}, {"Origin": "https://vattrackr.de"}, as_json=False)
    check("Formular-POST funktioniert", code == 200 and json.loads(text).get("ok") is True, text)
    check("erlaubte Herkunft bekommt CORS-Kopf", hdrs.get("Access-Control-Allow-Origin") == "https://vattrackr.de")
    code, text, hdrs = http("POST", f"{BASE}/api.php", {"action": "status", "key": key}, {"Origin": "https://boese.example"}, as_json=False)
    check("fremde Herkunft bekommt keinen CORS-Kopf", "Access-Control-Allow-Origin" not in hdrs)
    code, text, hdrs = http("OPTIONS", f"{BASE}/api.php", None, {"Origin": "https://vattrackr.de"})
    check("OPTIONS -> 204", code == 204)

    print("Bot-Befehle mit Kopplung")
    bot(100, "/status")
    check("/status zeigt Stand", "Alles eingerichtet" in last_text() and "Version 1.1.0" in last_text(), last_text())
    bot(100, "/lang en")
    check("/lang en", "Language: English" in last_text())
    bot(100, "/status")
    check("danach englisch", "All set" in last_text(), last_text())
    bot(100, "/lang xx")
    check("/lang ohne gueltigen Wert erklaert sich", "/lang de" in last_text())
    bot(100, "/hilfe@VatTrackrTestBot")
    check("unbekannter Befehl zeigt Hilfe", "Commands" in last_text())
    tg_reset()
    bot(100, "/newkey")
    m = re.search(r"<code>(VT(?:-[A-Z2-9]{4}){4})</code>", last_text())
    check("/newkey zeigt neuen Schluessel", m is not None, last_text())
    key2 = m.group(1)
    code, r = api("status", key=key)
    check("alter Schluessel ungueltig", code == 401)
    code, r = api("status", key=key2)
    check("neuer Schluessel gilt", code == 200)

    print("Datenschutz und Aufraeumen")
    files = glob.glob(os.path.join(root, "data", "keys", "*.php"))
    blob = "".join(open(f).read() for f in glob.glob(os.path.join(root, "data", "**", "*.php"), recursive=True))
    check("Schluessel liegt nur als Hash vor", key2 not in blob and key2.replace("-", "") not in blob)
    check("keine Nachrichteninhalte gespeichert", "contact me" not in blob.lower() and "DLH123" not in blob and "EDMM" not in blob)
    name = os.path.basename(files[0])
    code, text, _ = http("GET", f"{BASE}/data/keys/{name}")
    check("Datendatei verraet bei Direktabruf nichts", code == 403 and text.strip() == "", f"{code} {text[:60]}")
    code, text, _ = http("GET", f"{BASE}/config.php")
    check("config.php gibt nichts aus", TOKEN not in text and ADMIN not in text)
    code, text, _ = http("GET", f"{BASE}/lib.php")
    check("lib.php gibt nichts aus", text.strip() == "")

    tg_reset()
    bot(100, "/stop")
    check("/stop loescht", "gelöscht" in last_text() or "deleted" in last_text(), last_text())
    code, r = api("status", key=key2)
    check("nach /stop ist der Schluessel weg", code == 401)
    left = glob.glob(os.path.join(root, "data", "keys", "*.php")) + glob.glob(os.path.join(root, "data", "chats", "*.php"))
    check("keine Nutzerdateien mehr vorhanden", left == [], str(left))

    print("Sonderfaelle")
    k3, _ = pair(400, "en")
    http("POST", f"{TG}/_block", {"chat_id": 400})      # Nutzer blockiert den Bot nach der Einrichtung
    code, r = api("notify", key=k3, type="private", **{"from": "A_CTR"}, text="hello")
    check("blockierter Bot -> 410", code == 410 and r.get("error") == "gone", f"{code} {r}")
    check("Daten des blockierenden Nutzers geloescht", api("status", key=k3)[0] == 401)

    _, r = api("pair_start")
    tok = r["token"]
    bot(401, f"/start {tok}", "de")
    code4 = re.search(r"<b>(\d{4})</b>", last_text()).group(1)
    http("POST", f"{TG}/_block", {"chat_id": 401})      # blockiert zwischen Code und Bestaetigung
    code, r = api("pair_confirm", token=tok, code=code4)
    check("Kopplung mit blockiertem Bot liefert keinen toten Schluessel", code == 410 and "key" not in r, f"{code} {r}")

    _, r = api("pair_start")
    tok = r["token"]
    bot(200, f"/start {tok}", "de")
    for i in range(5):
        code, r = api("pair_confirm", token=tok, code="00000")
    check("nach 5 falschen Codes gesperrt", r.get("status") == "failed", str(r))
    code, r = api("pair_status", token=tok)
    check("Status failed", r.get("status") == "failed")
    tg_reset()
    bot(200, f"/start {tok}", "de")
    m = re.search(r"<b>(\d{4})</b>", last_text())
    check("im Bot gibt es danach einen neuen Code", m is not None, last_text())
    code, r = api("pair_confirm", token=tok, code=m.group(1))
    check("mit dem neuen Code klappt die Einrichtung", code == 200 and r.get("key", "").startswith("VT-"), str(r))
    bot(200, "/stop")

    _, r = api("pair_start")
    tok = r["token"]
    sid = hashlib.sha256(tok.encode()).hexdigest()
    path = os.path.join(root, "data", "pair", sid + ".php")
    raw = open(path).read()
    head, body = raw.split("\n", 1)
    d = json.loads(body)
    d["created"] -= 4000
    open(path, "w").write(head + "\n" + json.dumps(d))
    code, r = api("pair_status", token=tok)
    check("abgelaufene Kopplung", r.get("status") == "expired")
    tg_reset()
    bot(200, f"/start {tok}", "de")
    check("Bot meldet abgelaufenen Link", "abgelaufen" in last_text(), last_text())
    code, r = api("pair_status", token="zzz")
    check("kaputtes Token -> 400", code == 400)

    # erneute Kopplung desselben Chats ersetzt den Schluessel
    ka, _ = pair(300)
    kb, _ = pair(300)
    check("neue Kopplung ersetzt alten Schluessel", ka != kb and api("status", key=ka)[0] == 401 and api("status", key=kb)[0] == 200)

    code, text, _ = http("GET", f"{BASE}/setup.php?secret={ADMIN}&action=stats")
    check("Statistik", code == 200 and re.search(r"Nutzer mit Schluessel:\s+\d+", text) is not None
          and "Letzter Lauf von cron.php:" in text, text)

    run_v11(root)

    for i in range(13):
        code, r = api("pair_start")
    check("zu viele Kopplungen je IP gebremst", code == 429, str(code))


# ---------------------------------------------------------------- Version 1.1

def tap(chat, data, message_id=55):
    """Nutzer tippt auf eine Taste unter einer Bot-Nachricht."""
    update = {"update_id": int(time.time() * 1000) % 10**9, "callback_query": {
        "id": "cb1", "data": data, "from": {"id": chat, "language_code": "de"},
        "message": {"message_id": message_id, "chat": {"id": chat, "type": "private"}}}}
    return http("POST", f"{BASE}/webhook.php", update, {"X-Telegram-Bot-Api-Secret-Token": SECRET})


def newkey(chat, lang="de"):
    tg_reset()
    bot(chat, "/newkey", lang)
    return re.search(r"<code>(VT(?:-[A-Z2-9]{4}){4})</code>", last_text()).group(1)


def feed(root, controllers=None, atis=None, down=False):
    """Setzt den Stellvertreter-Datenfeed und leert den Zwischenspeicher des Servers."""
    http("POST", f"{TG}/_feed", {"down": True} if down else {"controllers": controllers or [], "atis": atis or []})
    cache = os.path.join(root, "data", "meta", "atc.php")
    if os.path.exists(cache):
        os.remove(cache)


def station(callsign, freq, minutes_ago=5, facility=4):
    t = time.gmtime(time.time() - minutes_ago * 60)
    return {"cid": 1234567, "name": "Max Mustermann", "callsign": callsign, "frequency": freq, "facility": facility,
            "rating": 5, "logon_time": time.strftime("%Y-%m-%dT%H:%M:%S", t) + ".1234567Z"}


def cron(key=None):
    return http("GET", f"{BASE}/cron.php?key={CRON if key is None else key}")


def run_v11(root):
    print("1.1: Einrichtung muss nach dem Update neu laufen")
    meta = os.path.join(root, "data", "meta", "bot.php")
    raw = open(meta).read()
    open(meta, "w").write(raw.replace('"v":2', '"v":1'))
    code, r = api("info", "GET")
    check("alte Einrichtung wird erkannt", r.get("ok") is False and r.get("error") == "setup_outdated", str(r))
    open(meta, "w").write(raw)
    code, r = api("info", "GET")
    check("aktuelle Einrichtung: ok, Version 1.1.0", r.get("ok") is True and r.get("latest") == "1.1.0", str(r))

    print("1.1: Plugin koppelt sich selbst (Paket von der Anleitungsseite)")
    _, r = api("pair_start")
    token = r["token"]
    code, r = api("pair_status", token=token)
    check("Seite: Plugin noch nicht gesehen", r.get("status") == "pending" and r.get("plugin") is False, str(r))
    code, r = api("device_poll", token=token, v="1.1.0")
    check("Plugin fragt: Seite ist offen, Adresse zum Oeffnen liegt bei",
          r.get("status") == "pending" and r.get("page") is True and r.get("url") == f"https://example.test/notify/?pair={token}", str(r))
    code, r = api("pair_status", token=token)
    check("Seite sieht das Plugin", r.get("plugin") is True, str(r))
    tg_reset()
    bot(600, f"/start {token}", "de")
    code4 = re.search(r"<b>(\d{4})</b>", last_text()).group(1)
    code, r = api("device_poll", token=token, v="1.1.0")
    check("Plugin sieht: Code steht aus, noch kein Schluessel", r.get("status") == "code" and "key" not in r, str(r))
    code, r = api("pair_confirm", token=token, code=code4)
    key = r.get("key", "")
    code, r = api("device_poll", token=token, v="1.1.0")
    check("Plugin bekommt den Schluessel", r.get("status") == "done" and r.get("key") == key and key.startswith("VT-"), str(r))
    check("Bot bestaetigt: Plugin holt den Schluessel selbst", "holt sich den Schlüssel jetzt selbst" in last_text(), last_text())
    code, r = api("notify", key=key, type="hello", v="1.1.0")
    check("hello mit diesem Schluessel", code == 200 and r.get("latest") == "1.1.0", str(r))
    check("Begruessung nennt /settings", "/settings" in last_text(), last_text())
    code, r = api("device_poll", token="0" * 32, v="1.1.0")
    check("unbekanntes Token -> expired", r.get("status") == "expired", str(r))
    code, r = api("device_poll", "GET")
    check("device_poll nur per POST", code == 405)

    print("1.1: Plugin ohne Token beginnt selbst")
    code, r = api("device_start", v="1.1.0")
    tok2 = r.get("token", "")
    check("device_start liefert Token und Adresse", re.fullmatch(r"[a-f0-9]{32}", tok2) is not None
          and r.get("url") == f"https://example.test/notify/?pair={tok2}" and r.get("page") is False, str(r))
    code, r = api("device_poll", token=tok2, v="1.1.0")
    check("ohne offene Seite meldet der Server page=false", r.get("page") is False, str(r))
    code, r = api("pair_status", token=tok2)
    check("Seite, vom Plugin geoeffnet, sieht das Plugin sofort", r.get("plugin") is True and r.get("status") == "pending", str(r))

    print("1.1: Einstellungen im Bot")
    tg_reset()
    bot(777, "/settings", "de")
    check("/settings ohne Kopplung", "noch nicht verbunden" in last_text())
    tg_reset()
    bot(600, "/settings", "de")
    msg = tg_calls("sendMessage")[-1]["params"]
    rows = msg.get("reply_markup", {}).get("inline_keyboard", [])
    check("/settings zeigt vier eingeschaltete Schalter", len(rows) == 4 and all(r[0]["text"].startswith("✅") for r in rows)
          and [r[0]["callback_data"] for r in rows] == ["opt:private", "opt:radio", "opt:selcal", "opt:disconnect"], str(rows))
    tg_reset()
    tap(600, "opt:radio")
    edit = tg_calls("editMessageReplyMarkup")
    ans = tg_calls("answerCallbackQuery")
    check("Tippen schaltet um und aktualisiert die Tasten", len(edit) == 1 and edit[0]["params"]["message_id"] == 55
          and edit[0]["params"]["reply_markup"]["inline_keyboard"][1][0]["text"].startswith("⬜")
          and len(ans) == 1 and "aus" in ans[0]["params"].get("text", ""), str(edit) + str(ans))
    tg_reset()
    code, r = api("notify", key=key, type="radio", **{"from": "EDDM_TWR"}, text="DLH1 hallo", freq="118.705")
    check("abgeschaltete Art wird nicht gesendet", code == 200 and r.get("muted") is True and not tg_calls("sendMessage"), str(r))
    code, r = api("notify", key=key, type="private", **{"from": "EDDM_TWR"}, text="hallo")
    check("andere Arten kommen weiter an", code == 200 and "Private Nachricht" in last_text())
    key = newkey(600)
    tg_reset()
    code, r = api("notify", key=key, type="radio", **{"from": "EDDM_TWR"}, text="DLH1 hallo", freq="118.705")
    check("Einstellung bleibt nach neuem Schluessel erhalten", r.get("muted") is True, str(r))
    tap(600, "opt:radio")
    code, r = api("notify", key=key, type="radio", **{"from": "EDDM_TWR"}, text="DLH1 hallo", freq="118.705")
    check("wieder eingeschaltet", "muted" not in r and "Funk-Text" in last_text(), str(r))
    tg_reset()
    tap(600, "opt:quatsch")
    tap(778, "opt:radio")
    check("unbekannte Taste oder fremder Chat aendert nichts", not tg_calls("editMessageReplyMarkup") and len(tg_calls("answerCallbackQuery")) == 2)

    print("1.1: Update-Hinweis per Telegram")
    tg_reset()
    api("notify", key=key, type="hello", v="1.0.0")
    t = "\n".join(c["params"]["text"] for c in tg_calls("sendMessage"))
    check("altes Plugin bekommt den Hinweis", "Neue Plugin-Version 1.1.0" in t and "Du nutzt 1.0.0" in t, t)
    check("Hinweis nennt das Paket und Installieren.cmd",
          "https://example.test/notify/download/VatTrackrNotify.zip" in t and "Installieren.cmd" in t, t)
    tg_reset()
    api("notify", key=key, type="hello", v="1.0.0")
    check("aber nur einmal", not tg_calls("sendMessage"))
    api("notify", key=key, type="hello", v="1.1.0")
    check("aktuelles Plugin bekommt keinen", not tg_calls("sendMessage"))

    print("1.1: /atc")
    feed(root, [station("EDDM_TWR", "118.705"), station("EDDM_N_APP", "123.9"), station("EDDF_TWR", "119.900"),
                station("EDDM_OBS", "199.998", facility=0), station("EDMM_ZUG_CTR", "132.555")],
         [{"callsign": "EDDM_ATIS", "frequency": "123.130", "atis_code": "K", "text_atis": ["x"]}])
    tg_reset()
    bot(500, "/atc EDDM", "de")
    t = last_text()
    check("/atc zeigt die Stationen des Flughafens", "<b>EDDM</b> ist besetzt" in t and "EDDM_TWR  118.705" in t
          and "EDDM_N_APP  123.900" in t and "EDDF" not in t and "OBS" not in t, t)
    check("mit ATIS und Stand", "ATIS: EDDM_ATIS  123.130 (Info K)" in t and re.search(r"Stand \d\d:\d\d UTC", t) is not None, t)
    bot(500, "/atc edmm", "de")
    check("Center und Kleinschreibung", "EDMM_ZUG_CTR  132.555" in last_text(), last_text())
    bot(500, "/atc EDDM_TWR", "en")
    check("ganzes Rufzeichen wird aufs Kuerzel gekuerzt", "EDDM_N_APP" in last_text())
    bot(501, "/atc EDDS", "en")
    check("niemand online (englisch)", "Nobody is online at <b>EDDS</b>" in last_text(), last_text())
    bot(500, "/atc", "de")
    check("/atc ohne Angabe erklaert sich", "/atc EDDM" in last_text() and "Kürzel" in last_text())
    bot(500, "/atc !!", "de")
    check("unlesbare Angabe erklaert sich", "/atc EDDM" in last_text())
    feed(root, down=True)
    bot(500, "/atc EDDM", "de")
    check("Feed gestoert: klare Meldung", "nicht erreichbar" in last_text(), last_text())

    print("1.1: /watch und cron.php")
    feed(root, [station("EDDM_TWR", "118.705", minutes_ago=10)])
    tg_reset()
    bot(500, "/watch", "de")
    check("/watch ohne Liste erklaert sich", "/watch EDDM" in last_text())
    bot(500, "/watch eddm", "de")
    t = last_text()
    check("/watch ohne vPilot-Kopplung moeglich, zeigt gleich den Stand", "sobald bei <b>EDDM</b>" in t and "EDDM_TWR  118.705" in t, t)
    check("Hinweis, wenn die automatische Pruefung nicht laeuft", "läuft auf diesem Server gerade nicht" in t, t)
    bot(500, "/watch EDDM", "de")
    check("doppelt", "beobachtest du schon" in last_text())
    code, text, _ = cron("falsch")
    check("cron.php ohne richtigen Schluessel gesperrt", code == 403)
    tg_reset()
    code, text, _ = cron()
    check("cron.php laeuft", code == 200 and text.startswith("ok beobachtet=1 daten=ja"), text)
    r = subprocess.run(["php", os.path.join(root, "cron.php")], capture_output=True, text=True, timeout=30,
                       env={k: v for k, v in os.environ.items() if not k.lower().endswith("_proxy")})
    check("cron.php laeuft auch als Befehl vom Server aus, ohne Schluessel", r.returncode == 0 and r.stdout.startswith("ok beobachtet=1"), r.stdout + r.stderr)
    check("wer beim Abonnieren schon online war, wird nicht noch einmal gemeldet", not tg_calls("sendMessage"), text)
    bot(500, "/watch EDDS", "de")
    check("jetzt ohne Hinweis, die Pruefung laeuft", "läuft auf diesem Server gerade nicht" not in last_text(), last_text())
    bot(500, "/watch", "de")
    check("Liste", "Du beobachtest: <b>EDDM, EDDS</b>" in last_text(), last_text())

    feed(root, [station("EDDM_TWR", "118.705", minutes_ago=12), station("EDDS_TWR", "118.800", minutes_ago=2),
                station("EDDS_GND", "121.915", minutes_ago=120), station("EDDF_TWR", "119.900", minutes_ago=1)])
    tg_reset()
    code, text, _ = cron()
    msgs = tg_calls("sendMessage")
    check("neue Station wird gemeldet, alte und fremde nicht", len(msgs) == 1 and msgs[0]["params"]["chat_id"] == 500
          and "<b>EDDS_TWR</b> ist jetzt online auf 118.800" in msgs[0]["params"]["text"]
          and "/unwatch EDDS" in msgs[0]["params"]["text"], text + str(msgs))
    tg_reset()
    cron()
    check("keine doppelte Meldung beim naechsten Lauf", not tg_calls("sendMessage"))
    feed(root, [station("EDDS_TWR", "118.800", minutes_ago=0)])
    cron()
    check("kurz weg und wieder da: 15 Minuten Ruhe", not tg_calls("sendMessage"))

    bot(502, "/watch EDDS", "en")                     # zweiter Beobachter, englisch
    feed(root, [station("EDDS_N_APP", "119.200", minutes_ago=1)])
    tg_reset()
    cron()
    texts = {c["params"]["chat_id"]: c["params"]["text"] for c in tg_calls("sendMessage")}
    check("beide Beobachter in ihrer Sprache", set(texts) == {500, 502} and "ist jetzt online" in texts[500]
          and "is now online on 119.200" in texts[502], str(texts))

    bot(500, "/watch EDDF", "de")
    bot(500, "/watch EDDK", "de")
    check("Hoechstzahl (hier 3)", "Mehr als 3 Einträge" in last_text(), last_text())
    bot(500, "/watch e", "de")
    check("unlesbares Kuerzel", "nicht verstanden" in last_text())
    bot(500, "/unwatch EDDF", "de")
    check("/unwatch", "<b>EDDF</b> beobachte ich nicht mehr" in last_text())
    bot(500, "/unwatch EDDF", "de")
    check("/unwatch fuer Unbekanntes", "steht nicht auf deiner Liste" in last_text())

    k500 = newkey(500)
    chat_file = os.path.join(root, "data", "chats", "500.php")
    check("Kopplung laesst die Beobachtungsliste stehen", '"watch":["EDDM","EDDS"]' in open(chat_file).read(), open(chat_file).read())
    bot(500, "/status", "de")
    check("/status nennt auch die Liste", "Du beobachtest: <b>EDDM, EDDS</b>" in last_text(), last_text())

    blob = "".join(open(f).read() for f in glob.glob(os.path.join(root, "data", "**", "*.php"), recursive=True))
    check("keine Namen oder Mitgliedsnummern von Lotsen gespeichert", "Mustermann" not in blob and "1234567" not in blob)

    tg_reset()
    bot(500, "/stop", "de")
    check("/stop loescht auch die Liste und nennt das Plugin", "Beobachtungsliste" in last_text() and "VatTrackrNotify.dll" in last_text(), last_text())
    check("Schluessel und Datensatz weg", api("status", key=k500)[0] == 401 and not os.path.exists(chat_file))
    idx = open(os.path.join(root, "data", "watch", "EDDS.php")).read()
    check("Index kennt nur noch den zweiten Beobachter", "502" in idx and "500" not in idx, idx)
    check("leerer Index verschwindet", not os.path.exists(os.path.join(root, "data", "watch", "EDDM.php")))

    http("POST", f"{TG}/_block", {"chat_id": 502})     # zweiter Beobachter blockiert den Bot
    feed(root, [station("EDDS_DEL", "121.775", minutes_ago=1)])
    cron()
    check("blockierender Beobachter wird ausgetragen", not os.path.exists(os.path.join(root, "data", "watch", "EDDS.php"))
          and not os.path.exists(os.path.join(root, "data", "chats", "502.php")))
    code, text, _ = cron()
    check("ohne Beobachter fragt cron.php den Feed gar nicht ab", "beobachtet=0 daten=nein" in text, text)


if __name__ == "__main__":
    main()
