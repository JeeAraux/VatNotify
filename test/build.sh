#!/bin/sh
# Baut das Plugin und die Test-Stellvertreter mit Mono (C#-5-Sprachstand wie der Windows-eigene Compiler).
set -e
cd "$(dirname "$0")/.."
OUT=build
rm -rf "$OUT" && mkdir -p "$OUT/ref" "$OUT/host/Plugins" "$OUT/hostv/Plugins"

API=RossCarlson.Vatsim.Vpilot.Plugins.dll

# Das Plugin wird gegen die Referenz-Bibliotheken des .NET Framework 4.5 gebaut (Paket mono-devel),
# NICHT gegen Monos eigene Bibliotheken: die kennen Methoden, die es unter Windows nicht gibt.
NETFX="${NETFX:-/usr/lib/mono/4.5-api}"
if [ ! -f "$NETFX/mscorlib.dll" ]; then
  echo "Referenz-Bibliotheken fehlen: $NETFX (Paket mono-devel installieren oder NETFX setzen)" >&2
  exit 1
fi

# Schnittstelle (nur als Bau-Referenz) und Plugin
mcs -nologo -target:library -langversion:5 -out:"$OUT/ref/$API" test/FakeVpilotApi.cs
mcs -nologo -noconfig -nostdlib -target:library -langversion:5 -optimize+ -warnaserror \
    -r:"$NETFX/mscorlib.dll" -r:"$NETFX/System.dll" -r:"$OUT/ref/$API" -out:"$OUT/VatTrackrNotify.dll" plugin/Plugin.cs

# Stellvertreter-vPilot in zwei Fassungen (normale und abweichende Ereignis-Signaturen)
cp "$OUT/ref/$API" "$OUT/host/$API"
mcs -nologo -target:exe -langversion:5 -r:"$OUT/host/$API" -out:"$OUT/host/FakeHost.exe" test/FakeHost.cs
mcs -nologo -target:library -langversion:5 -define:VARIANT -out:"$OUT/hostv/$API" test/FakeVpilotApi.cs
mcs -nologo -target:exe -langversion:5 -define:VARIANT -r:"$OUT/hostv/$API" -out:"$OUT/hostv/FakeHost.exe" test/FakeHost.cs

cp "$OUT/VatTrackrNotify.dll" "$OUT/host/Plugins/"
cp "$OUT/VatTrackrNotify.dll" "$OUT/hostv/Plugins/"
ls -l "$OUT/VatTrackrNotify.dll"
