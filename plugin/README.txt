VatTrackr Notify 1.1.0 - vPilot plugin
======================================

DEUTSCH
-------
Schickt dir eine Telegram-Nachricht aufs Handy, sobald dich auf VATSIM ein Lotse
anschreibt (Contact me), dich jemand privat anschreibt, ein SELCAL kommt oder die
Verbindung abbricht.

So geht es:
  1. vPilot beenden.
  2. Dieses Paket entpacken (Rechtsklick auf die ZIP-Datei, "Alle extrahieren").
  3. Im entpackten Ordner "Installieren.cmd" doppelt anklicken. Fragt Windows nach,
     bestaetige mit "Ausfuehren".
  4. vPilot starten. Die Einrichtungsseite oeffnet sich im Browser, falls sie nicht
     schon offen ist. Dort verbindest du Telegram: QR-Code scannen, Code eingeben, fertig.

Die Anleitung mit Bildern:  https://vattrackr.de/vatnotify/

Lieber von Hand?
  VatTrackrNotify.dll und VatTrackrNotify.ini in den Plugins-Ordner von vPilot legen:
    %LOCALAPPDATA%\vPilot\Plugins
  Dann Rechtsklick auf VatTrackrNotify.dll > Eigenschaften > unten den Haken bei
  "Zulassen" setzen > OK. Windows sperrt heruntergeladene DLL-Dateien, und vPilot
  laedt das Plugin sonst nicht.

Kontrolle: Nach dem Start von vPilot liegt im Plugins-Ordner die Datei
VatTrackrNotify.log. Darin steht, ob das Plugin laeuft. Fehlt die Datei, hat vPilot
das Plugin nicht geladen.

Update: vPilot beenden, das neue Paket entpacken und "Installieren.cmd" starten.
Deine Verbindung zu Telegram bleibt dabei bestehen.

Was gemeldet wird, stellst du im Bot mit /settings ein.
Abmelden und alle Daten loeschen: dem Bot /stop schicken und beide Dateien aus dem
Plugins-Ordner entfernen.

Wichtig: Die Benachrichtigung ersetzt nicht deine Anwesenheit. Es gelten die VATSIM-Regeln
zu unbeaufsichtigten Verbindungen; auf eine Kontaktaufforderung musst du zuegig reagieren.


ENGLISH
-------
Sends a Telegram message to your phone as soon as a controller messages you on VATSIM
(contact me), someone sends you a private message, a SELCAL arrives or the connection drops.

How to set it up:
  1. Close vPilot.
  2. Unzip this package (right-click the ZIP file, "Extract all").
  3. In the unzipped folder, double-click "Installieren.cmd". If Windows asks,
     confirm with "Run".
  4. Start vPilot. The setup page opens in your browser unless it is open already.
     Connect Telegram there: scan the QR code, enter the code, done.

The guide with pictures:  https://vattrackr.de/vatnotify/

Rather by hand?
  Put VatTrackrNotify.dll and VatTrackrNotify.ini into vPilot's Plugins folder:
    %LOCALAPPDATA%\vPilot\Plugins
  Then right-click VatTrackrNotify.dll > Properties > tick "Unblock" at the bottom > OK.
  Windows blocks downloaded DLL files, and vPilot will not load the plugin otherwise.

Check: after vPilot has started, the Plugins folder contains the file
VatTrackrNotify.log. It says whether the plugin is running. If the file is missing,
vPilot did not load the plugin.

Updating: close vPilot, unzip the new package and run "Installieren.cmd". Your
Telegram connection stays in place.

Choose what is reported with /settings in the bot.
To sign off and delete all your data: send /stop to the bot and remove both files from
the Plugins folder.

Important: a notification does not replace being there. VATSIM's rules on unattended
connections apply; you must respond promptly to a contact request.
