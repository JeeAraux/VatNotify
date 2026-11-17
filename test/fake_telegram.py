#!/usr/bin/env python3
"""Stellvertreter fuer die Telegram-Bot-API: nimmt Aufrufe an und merkt sie sich.

GET  /_calls   -> alle bisherigen Aufrufe als JSON
POST /_reset   -> Liste leeren
POST /bot<token>/<methode>  -> antwortet wie Telegram
GET  /vatsim-data.json      -> Stellvertreter fuer den VATSIM-Datenfeed
POST /_feed                 -> {"controllers": [...], "atis": [...]} setzen; {"down": true} = Feed gestoert
POST /_block   -> {"chat_id": n}: dieser Chat gilt ab jetzt als "Bot blockiert" (403)
"""
import json
import sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

CALLS = []
BLOCKED = set()
FEED = {"controllers": [], "atis": []}


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def _send(self, code, obj):
        body = json.dumps(obj).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if self.path == "/_calls":
            self._send(200, CALLS)
        elif self.path == "/vatsim-data.json":
            if FEED.get("down"):
                self._send(500, {"error": "down"})
            else:
                self._send(200, {"general": {"version": 3}, "pilots": [], "controllers": FEED.get("controllers", []),
                                 "atis": FEED.get("atis", []), "servers": [], "prefiles": []})
        else:
            self._send(404, {"ok": False})

    def do_POST(self):
        length = int(self.headers.get("Content-Length") or 0)
        raw = self.rfile.read(length) if length else b""
        if self.path == "/_reset":
            CALLS.clear()
            self._send(200, {"ok": True})
            return
        if self.path == "/_feed":
            FEED.clear()
            FEED.update(json.loads(raw))
            self._send(200, {"ok": True})
            return
        if self.path == "/_block":
            BLOCKED.add(json.loads(raw)["chat_id"])
            self._send(200, {"ok": True})
            return
        parts = self.path.strip("/").split("/")
        if len(parts) != 2 or not parts[0].startswith("bot"):
            self._send(404, {"ok": False, "error_code": 404, "description": "Not Found"})
            return
        try:
            params = json.loads(raw or b"{}")
        except ValueError:
            params = {}
        method = parts[1]
        CALLS.append({"token": parts[0][3:], "method": method, "params": params})
        if method == "getMe":
            self._send(200, {"ok": True, "result": {"id": 4242, "is_bot": True, "username": "VatTrackrTestBot"}})
        elif method == "sendMessage" and params.get("chat_id") in BLOCKED:
            self._send(403, {"ok": False, "error_code": 403, "description": "Forbidden: bot was blocked by the user"})
        elif method == "getWebhookInfo":
            hooks = [c for c in CALLS if c["method"] == "setWebhook"]
            self._send(200, {"ok": True, "result": {"url": hooks[-1]["params"]["url"] if hooks else ""}})
        else:
            self._send(200, {"ok": True, "result": True})


if __name__ == "__main__":
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 9091
    ThreadingHTTPServer(("127.0.0.1", port), Handler).serve_forever()
