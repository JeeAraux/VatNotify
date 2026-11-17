#!/bin/sh
# Baut die Anleitungsseite (bindet die QR-Bibliothek ein) und legt Schriften und Plugin-Dateien dazu.
set -e
cd "$(dirname "$0")/.."
V=vendor/node_modules
OUT=server/vatnotify
npx --prefix vendor terser $V/qrcode-generator/dist/qrcode.js -c -m -o build/qrcode.min.js
python3 - <<'PY'
import json
src = open('web/index.src.html', encoding='utf-8').read()
lib = open('build/qrcode.min.js', encoding='utf-8').read().strip()
assert '__QR_LIB__' in src and '</script' not in lib
def embed(path):
    # als JavaScript-Zeichenkette; "</" entschaerft, damit der Skriptblock nicht vorzeitig endet
    text = open(path, encoding='ascii', newline='').read()
    assert '\r\n' in text
    return json.dumps(text).replace('</', '<\\/')
page = src.replace('__QR_LIB__', lib)
for mark, path in (('__INI__', 'plugin/VatTrackrNotify.ini'), ('__CMD__', 'plugin/Installieren.cmd'), ('__README__', 'plugin/README.txt')):
    assert page.count(mark) == 1, mark
    page = page.replace(mark, embed(path))
open('server/vatnotify/index.html', 'w', encoding='utf-8').write(page)
PY
mkdir -p $OUT/fonts $OUT/download
cp $V/@fontsource/chakra-petch/files/chakra-petch-latin-600-normal.woff2 $V/@fontsource/chakra-petch/files/chakra-petch-latin-700-normal.woff2 $OUT/fonts/
cp $V/@fontsource/ibm-plex-sans/files/ibm-plex-sans-latin-400-normal.woff2 $V/@fontsource/ibm-plex-sans/files/ibm-plex-sans-latin-600-normal.woff2 $OUT/fonts/
cp $V/@fontsource/ibm-plex-mono/files/ibm-plex-mono-latin-500-normal.woff2 $OUT/fonts/
cp $V/@fontsource/chakra-petch/LICENSE $OUT/fonts/LICENSE-ChakraPetch.txt
cp $V/@fontsource/ibm-plex-sans/LICENSE $OUT/fonts/LICENSE-IBMPlex.txt
rm -f $OUT/download/*
cp build/VatTrackrNotify.dll $OUT/download/
# Paket fuer alle (ohne Token), auch fuer Updates: ein vorhandener Schluessel bleibt erhalten,
# ohne Schluessel beginnt das Plugin die Einrichtung selbst und oeffnet die Anleitung
mkdir -p build/pkg && rm -f build/pkg/* && cp build/VatTrackrNotify.dll plugin/VatTrackrNotify.ini plugin/Installieren.cmd plugin/README.txt build/pkg/
(cd build/pkg && zip -q -X ../../$OUT/download/VatTrackrNotify.zip Installieren.cmd VatTrackrNotify.dll VatTrackrNotify.ini README.txt)
ls -l $OUT/index.html $OUT/fonts $OUT/download
