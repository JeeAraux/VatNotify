#!/bin/sh
# Probelauf von Installieren.cmd unter Wine (cmd, reg, tasklist, find, findstr; PowerShell ist dort nur ein Platzhalter).
# Ersetzt keinen Test unter echtem Windows, findet aber Syntax- und Ablauffehler.
# Aufruf: sh test/test_installer.sh   (braucht wine64)
cd "$(dirname "$0")/.."
ROOT=$(pwd)
export WINEPREFIX=${WINEPREFIX:-/tmp/wt/prefix} WINEDEBUG=-all DISPLAY=
WINE=$(command -v wine64 || echo /usr/lib/wine/wine64)
[ -d "$WINEPREFIX" ] || "$WINE" wineboot -i >/dev/null 2>&1
C="$WINEPREFIX/drive_c"
USERDIR=$(ls -d "$C"/users/*/ | grep -v Public | head -1)
VP="${USERDIR}AppData/Local/vPilot"
PKG="$C/pkg"
pass=0; fail=0
ok() { if [ "$2" = "1" ]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1"; fi; }
has() { grep -q "$1" "$2" 2>/dev/null && echo 1 || echo 0; }
run() { (cd "$PKG" && timeout 60 "$WINE" cmd /c Installieren.cmd </dev/null >"$ROOT/build/installer.out" 2>&1; echo $? >"$ROOT/build/installer.rc"); tr -d '\r' <"$ROOT/build/installer.out" >"$ROOT/build/installer.txt"; RC=$(cat "$ROOT/build/installer.rc"); }
fresh() { rm -rf "$PKG" "$VP"; mkdir -p "$PKG"; cp plugin/Installieren.cmd plugin/VatTrackrNotify.ini plugin/README.txt build/VatTrackrNotify.dll "$PKG/"; }
mkdir -p build

echo "Paket nicht entpackt (DLL fehlt neben dem Skript)"
fresh; rm "$PKG/VatTrackrNotify.dll"
run; ok "Hinweis aufs Entpacken, Fehlercode" "$([ "$RC" != 0 ] && [ "$(has 'Alle extrahieren' build/installer.txt)" = 1 ] && echo 1 || echo 0)"

echo "vPilot nicht installiert"
fresh
run; ok "Hinweis, Fehlercode, nichts kopiert" "$([ "$RC" != 0 ] && [ "$(has 'vPilot wurde nicht gefunden' build/installer.txt)" = 1 ] && [ ! -d "$VP/Plugins" ] && echo 1 || echo 0)"

echo "Erste Installation"
fresh; mkdir -p "$VP"; : > "$VP/vPilot.exe"
sed -i 's/^Pair=\r$/Pair=0123456789abcdef0123456789abcdef\r/' "$PKG/VatTrackrNotify.ini"
run; sed 's/^/       | /' build/installer.txt; echo
ok "DLL bytegleich im Plugins-Ordner" "$(cmp -s build/VatTrackrNotify.dll "$VP/Plugins/VatTrackrNotify.dll" && echo 1 || echo 0)"
ok "ini mit Token im Plugins-Ordner" "$(has '^Pair=0123456789abcdef0123456789abcdef' "$VP/Plugins/VatTrackrNotify.ini")"
ok "Meldung nennt den Ordner und den Browser" "$([ "$(has 'vPilot.Plugins' build/installer.txt)" = 1 ] && [ "$(has 'geht im Browser weiter' build/installer.txt)" = 1 ] && echo 1 || echo 0)"
ok "Erfolg (Code 0)" "$([ "$RC" = 0 ] && echo 1 || echo 0)"

echo "Update: vorhandene ini mit Schluessel, Paket ohne Token"
fresh; mkdir -p "$VP/Plugins"; : > "$VP/vPilot.exe"
printf '[VatTrackrNotify]\r\nKey=VT-AAAA-BBBB-CCCC-DDDD\r\nServer=https://vattrackr.de/vatnotify/api.php\r\nRadioMessages=0\r\n' > "$VP/Plugins/VatTrackrNotify.ini"
echo old > "$VP/Plugins/VatTrackrNotify.dll"
run
ok "DLL ersetzt" "$(cmp -s build/VatTrackrNotify.dll "$VP/Plugins/VatTrackrNotify.dll" && echo 1 || echo 0)"
ok "ini mit Schluessel und eigenen Einstellungen bleibt" "$([ "$(has '^Key=VT-AAAA-BBBB-CCCC-DDDD' "$VP/Plugins/VatTrackrNotify.ini")" = 1 ] && [ "$(has '^RadioMessages=0' "$VP/Plugins/VatTrackrNotify.ini")" = 1 ] && echo 1 || echo 0)"
ok "Meldung: Einrichtung bleibt erhalten" "$([ "$RC" = 0 ] && [ "$(has 'bleibt erhalten' build/installer.txt)" = 1 ] && [ "$(has 'geht im Browser weiter' build/installer.txt)" = 0 ] && echo 1 || echo 0)"

echo "Neue Einrichtung: vorhandene ini mit Schluessel, Paket mit Token von der Seite"
sed -i 's/^Pair=\r$/Pair=fedcba9876543210fedcba9876543210\r/' "$PKG/VatTrackrNotify.ini"
run
ok "ini wird ersetzt" "$([ "$(has '^Pair=fedcba9876543210fedcba9876543210' "$VP/Plugins/VatTrackrNotify.ini")" = 1 ] && [ "$(has 'AAAA' "$VP/Plugins/VatTrackrNotify.ini")" = 0 ] && echo 1 || echo 0)"
ok "Meldung verweist auf den Browser" "$([ "$RC" = 0 ] && [ "$(has 'geht im Browser weiter' build/installer.txt)" = 1 ] && echo 1 || echo 0)"

echo "Vorhandene ini ohne Schluessel (Einrichtung nie abgeschlossen), Paket ohne Token"
fresh; mkdir -p "$VP/Plugins"; : > "$VP/vPilot.exe"
printf '[VatTrackrNotify]\r\nKey=\r\nPair=00000000000000000000000000000000\r\n' > "$VP/Plugins/VatTrackrNotify.ini"
run
ok "ini wird durch die Vorlage ersetzt" "$(cmp -s plugin/VatTrackrNotify.ini "$VP/Plugins/VatTrackrNotify.ini" && echo 1 || echo 0)"

echo "Ordner mit Leerzeichen und Klammern"
fresh; mkdir -p "$VP"; : > "$VP/vPilot.exe"
mv "$PKG" "$C/Neuer Ordner (2)"; PKG="$C/Neuer Ordner (2)"
run
ok "Installation klappt auch von dort" "$([ "$RC" = 0 ] && cmp -s build/VatTrackrNotify.dll "$VP/Plugins/VatTrackrNotify.dll" && echo 1 || echo 0)"
run
ok "zweiter Lauf von dort: ini ohne Schluessel wird wieder ersetzt" "$([ "$RC" = 0 ] && cmp -s plugin/VatTrackrNotify.ini "$VP/Plugins/VatTrackrNotify.ini" && echo 1 || echo 0)"
printf '[VatTrackrNotify]\r\nKey=VT-AAAA-BBBB-CCCC-DDDD\r\n' > "$VP/Plugins/VatTrackrNotify.ini"
run
ok "Update von dort: Schluessel bleibt" "$([ "$RC" = 0 ] && [ "$(has '^Key=VT-AAAA' "$VP/Plugins/VatTrackrNotify.ini")" = 1 ] && [ "$(has 'bleibt erhalten' build/installer.txt)" = 1 ] && echo 1 || echo 0)"
sed -i 's/^Pair=\r$/Pair=fedcba9876543210fedcba9876543210\r/' "$PKG/VatTrackrNotify.ini"
run
ok "Paket mit Token von dort: ini wird ersetzt" "$([ "$RC" = 0 ] && [ "$(has '^Pair=fedcba' "$VP/Plugins/VatTrackrNotify.ini")" = 1 ] && [ "$(has 'AAAA' "$VP/Plugins/VatTrackrNotify.ini")" = 0 ] && echo 1 || echo 0)"
rm -rf "$PKG"; PKG="$C/pkg"

echo "vPilot-Ordner aus der Registrierung"
fresh; ALT="$C/Spiele/vPilot (neu)"; mkdir -p "$ALT"; : > "$ALT/vPilot.exe"
"$WINE" reg add 'HKCU\Software\vPilot' /v Install_Dir /t REG_SZ /d 'C:\Spiele\vPilot (neu)' /f >/dev/null 2>&1
run
ok "nimmt den eingetragenen Ordner (mit Leerzeichen und Klammern)" "$([ "$RC" = 0 ] && cmp -s build/VatTrackrNotify.dll "$ALT/Plugins/VatTrackrNotify.dll" && [ ! -d "$VP" ] && echo 1 || echo 0)"
rm -rf "$ALT"; mkdir -p "$VP"; : > "$VP/vPilot.exe"
run
ok "Eintrag zeigt ins Leere: nimmt den ueblichen Ordner" "$([ "$RC" = 0 ] && cmp -s build/VatTrackrNotify.dll "$VP/Plugins/VatTrackrNotify.dll" && echo 1 || echo 0)"
"$WINE" reg delete 'HKCU\Software\vPilot' /f >/dev/null 2>&1

echo "vPilot laeuft noch"
fresh; mkdir -p "$VP/Plugins"; cp "$C/windows/system32/cmd.exe" "$VP/vPilot.exe"; echo old > "$VP/Plugins/VatTrackrNotify.dll"
(sleep 25 | "$WINE" "$VP/vPilot.exe" /k >/dev/null 2>&1) &
sleep 4
run
ok "bricht ab und fasst nichts an" "$([ "$RC" != 0 ] && [ "$(has 'vPilot laeuft noch' build/installer.txt)" = 1 ] && [ "$(cat "$VP/Plugins/VatTrackrNotify.dll")" = old ] && echo 1 || echo 0)"
wait

echo
echo "$pass bestanden, $fail fehlgeschlagen"
[ "$fail" = 0 ]
