# Fremde Bestandteile / Third-party components

| Bestandteil | Wo | Lizenz |
|---|---|---|
| [qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator) 2.0.4, (c) 2009 Kazuhiko Arase | eingebettet in `server/vatnotify/index.html` (QR-Code der Anleitung) | MIT |
| Chakra Petch | `server/vatnotify/fonts/` | SIL Open Font License 1.1, Text in `fonts/LICENSE-ChakraPetch.txt` |
| IBM Plex Sans, IBM Plex Mono | `server/vatnotify/fonts/` | SIL Open Font License 1.1, Text in `fonts/LICENSE-IBMPlex.txt` |

Die Schriftdateien stammen aus den Paketen `@fontsource/chakra-petch`,
`@fontsource/ibm-plex-sans` und `@fontsource/ibm-plex-mono`.

`test/FakeVpilotApi.cs` ist ein eigener Stellvertreter der Plugin-Schnittstelle
von vPilot. Er dient nur dazu, das Plugin ohne vPilot zu bauen und zu testen,
und enthält keinen Code aus vPilot. vPilot selbst und seine Dateien sind nicht
Teil dieses Repositorys.
