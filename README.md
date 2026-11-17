# VatTrackr Notify

Schickt dir eine Telegram-Nachricht aufs Handy, sobald dich auf VATSIM jemand in vPilot anschreibt.
Kostenlos, ein Angebot von [VatTrackr](https://vattrackr.de/). Einrichtung für Piloten: **https://vattrackr.de/vatnotify/**

*English summary: a small vPilot plugin plus a PHP service that forwards "contact me" requests, private messages, SELCAL calls and disconnects to your phone via a Telegram bot. This repository holds the complete source so that anyone can check what the plugin sends and what the server keeps. The setup guide is available in English at the address above.*

## Warum der Quelltext offen liegt

Das Plugin läuft in vPilot und sieht, was dich dort erreicht. Hier kann jeder nachlesen, was davon das Plugin überträgt und was der Server speichert.

## Was es meldet

- Contact me und alle anderen privaten Nachrichten
- Funk-Text, in dem dein Callsign vorkommt
- SELCAL-Rufe
- den Abbruch der Verbindung zu VATSIM

Dazu kann der Bot auf Zuruf zeigen, wer an einem Flughafen online ist (`/atc EDDM`), und Bescheid geben, sobald dort jemand online geht (`/watch EDDM`). Das geht auch ohne Plugin.

Die Benachrichtigung ersetzt nicht deine Anwesenheit. Es gelten die Regeln von VATSIM zu unbeaufsichtigten Verbindungen.

## Wie es funktioniert

```
vPilot ──Ereignis──> Plugin ──HTTPS──> api.php ──Bot-API──> Telegram ──> dein Handy
```

1. **Plugin** (`plugin/Plugin.cs`): hängt sich an vier Ereignisse von vPilot. Bei Funk-Text prüft es schon am PC, ob dein Callsign vorkommt; alles andere auf der Frequenz verlässt deinen Rechner nicht.
2. **Übertragung**: Art der Meldung, Callsign des Absenders, Text, Frequenz und dein Schlüssel gehen per HTTPS an `api.php`.
3. **Server** (`server/vatnotify/`): prüft den Schlüssel, baut daraus die Telegram-Nachricht und reicht sie an die Bot-Schnittstelle von Telegram weiter. Danach ist die Nachricht auf dem Server nicht mehr vorhanden.
4. **Kopplung**: Das Plugin bekommt seinen Schlüssel über ein Einmal-Token, sobald du den Bot in Telegram gestartet und den Code auf der Anleitungsseite bestätigt hast.

## Was der Server speichert

Je Nutzer, in Dateien unter `server/vatnotify/data/`:

| Was | Wozu |
|---|---|
| Telegram-Chat-ID | damit der Bot dir schreiben kann |
| SHA-256-Prüfwert deines Schlüssels | der Schlüssel selbst liegt nur in deiner ini |
| Sprache und die vier Schalter aus `/settings` | |
| Flughäfen aus `/watch` | |
| Zeitpunkt der Einrichtung und der letzten Meldung, Plugin-Version | für `/status` und den Update-Hinweis |
| Zähler je Schlüssel | Begrenzung gegen Missbrauch |

Für die Begrenzung von Kopplungs- und Fehlversuchen entsteht außerdem ein mit einem Serverschlüssel gebildeter Hashwert der IP-Adresse. Er wird nach spätestens einer Stunde gelöscht.

**Nicht gespeichert und nicht protokolliert** werden Nachrichtentexte, Callsigns der Absender, dein Callsign, deine CID oder dein Name. Aus den öffentlichen VATSIM-Daten merkt sich der Server für `/atc` und `/watch` nur Callsign, Frequenz und Anmeldezeit der Lotsenstationen sowie den Kennbuchstaben der ATIS.

`/stop` im Bot löscht alles sofort. Ohne Aktivität geschieht das nach 365 Tagen von selbst.

Zum Nachprüfen: Der Weg einer Meldung steht in `api.php` unter `case 'notify'`, die gespeicherten Felder in `lib.php` (`vtn_bind`, `vtn_forget`, `vtn_gc`). Die Logdatei des Plugins (`VatTrackrNotify.log`) enthält weder Schlüssel noch Nachrichten.

## Aufbau

| Ordner | Inhalt |
|---|---|
| `plugin/` | Quelltext des vPilot-Plugins, `Installieren.cmd`, Vorlage der ini, README des Pakets |
| `server/vatnotify/` | der Ordner, wie er auf dem Webspace liegt: API, Bot (`webhook.php`), Einrichtung (`setup.php`), `cron.php`, fertige Anleitungsseite, Schriften, Downloads |
| `web/` | Quelle der Anleitungsseite und ihr Bauskript |
| `test/` | automatische Tests für Server, Plugin, Anleitungsseite und Installationsskript |

## Die fertige DLL prüfen

Die Datei, die die Anleitungsseite ausliefert, liegt hier unter `server/vatnotify/download/VatTrackrNotify.dll`.

Version 1.1.0, SHA-256:

```
08f0b7a3b98c73ad71df9f3addb299703d61c50b3edde4aafa5fdbf2e31617cb
```

Unter Windows vergleichst du mit `Get-FileHash VatTrackrNotify.dll` in der PowerShell.

Die DLL lässt sich aus diesem Quelltext nachbauen: `sh test/build.sh` unter Linux mit Mono 6.8.0.105 (`mcs`) ergibt Byte für Byte dieselbe Datei, also denselben Prüfwert. Andere Compiler, etwa der von Windows in `plugin\build.cmd`, erzeugen eine gleichwertige, aber nicht bytegleiche DLL. Mit einem Decompiler wie ILSpy lässt sich die fertige Datei auch direkt ansehen.

## Bauen und testen

Unter Linux, mit PHP, Python 3 samt Playwright, Mono (`mono-mcs`, `mono-devel`), Node und für den Probelauf des Installationsskripts Wine:

```
(cd vendor && npm install)
export NETFX=/usr/lib/mono/4.5-api
sh test/build.sh && sh web/build.sh
cd test && python3 test_server.py && python3 test_plugin.py && python3 test_guide.py
sh test_installer.sh
```

Das Plugin wird gegen die Referenz-Bibliotheken des .NET Framework gebaut, nicht gegen Monos eigene, damit keine Methode hineingerät, die es unter Windows nicht gibt. Für den Bau unter Windows gibt es `plugin\build.cmd`; es nutzt den C#-Compiler von Windows und die Schnittstellen-Datei aus der eigenen vPilot-Installation.

Die Tests laufen gegen Stellvertreter: einen nachgebauten vPilot (`test/FakeHost.cs`), eine nachgebaute Telegram-Schnittstelle (`test/fake_telegram.py`) und Wine statt Windows. Sie sprechen nie mit einem echten Dienst.

## Selbst betreiben

Voraussetzung: Webspace mit PHP ab 8.0, cURL und HTTPS.

1. Bei `@BotFather` in Telegram einen Bot anlegen.
2. Den Ordner `server/vatnotify` hochladen.
3. `config.sample.php` nach `config.php` kopieren, `bot_token` und `admin_secret` eintragen, `guide_url` anpassen.
4. `setup.php?secret=…` aufrufen. Die Seite meldet den Bot bei Telegram an.
5. Für `/watch` einen Cronjob anlegen, der `cron.php` jede Minute aufruft. `setup.php` nennt die Zeile dafür.

Die Adresse des eigenen Dienstes schreibt die Anleitungsseite in die ini jedes Pakets. Im Plugin steht als Rückfall `https://vattrackr.de/vatnotify/` (`DefaultServer`, `SetupUrl` in `plugin/Plugin.cs`); wer einen eigenen Dienst betreibt, ändert das und baut neu.

## Lizenz

Copyright (C) 2026 VatTrackr

VatTrackr Notify ist freie Software unter der **GNU General Public License, Version 3 oder (nach deiner Wahl) jeder späteren Version**. Der Text steht in `LICENSE`. Es gibt keine Gewährleistung.

Weil das Plugin von vPilot geladen wird, das selbst keine freie Software ist, gilt zusätzlich die Erlaubnis in `LICENSE-ADDITIONAL-PERMISSION.md`.

Fremde Bestandteile und ihre Lizenzen stehen in `THIRD-PARTY.md`.

## Hinweis

VatTrackr ist ein privates Projekt und steht in keiner Verbindung zu VATSIM, zu vPilot oder zu Telegram.
