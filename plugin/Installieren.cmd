@echo off
setlocal
title VatTrackr Notify
echo.
echo   VatTrackr Notify - Installation
echo   ===============================
echo.

rem Dieses Skript kopiert das Plugin in den Plugins-Ordner von vPilot und hebt die
rem Sperre auf, die Windows auf heruntergeladene Dateien legt. Es aendert sonst nichts.

set "SRC=%~dp0"
if not exist "%SRC%VatTrackrNotify.dll" goto :notunzipped

rem --- vPilot-Ordner suchen: erst der Eintrag von vPilot selbst, dann der uebliche Ort
set "VP="
reg query "HKCU\Software\vPilot" /v Install_Dir >nul 2>&1
if errorlevel 1 goto :usual
for /f "tokens=2,*" %%a in ('reg query "HKCU\Software\vPilot" /v Install_Dir') do if /i "%%a"=="REG_SZ" set "VP=%%b"
if not defined VP goto :usual
if exist "%VP%\vPilot.exe" goto :found
:usual
set "VP=%LOCALAPPDATA%\vPilot"
:found
if not exist "%VP%\vPilot.exe" goto :novpilot

rem --- vPilot darf nicht laufen, sonst ist die Datei gesperrt
set "RUNNING="
tasklist /fi "imagename eq vPilot.exe" /nh >"%TEMP%\vtn-tasks.txt" 2>nul
for /f "usebackq tokens=1" %%a in ("%TEMP%\vtn-tasks.txt") do if /i "%%a"=="vPilot.exe" set "RUNNING=1"
del "%TEMP%\vtn-tasks.txt" >nul 2>&1
if defined RUNNING goto :running

rem --- Update: eine vorhandene Einstellungsdatei mit Schluessel bleibt erhalten. Kommt das
rem     Paket frisch von der Einrichtungsseite (Pair= ist gefuellt), wird sie ersetzt.
set "KEEP="
set "OLDKEY="
set "NEWPAIR="
if not exist "%VP%\Plugins\VatTrackrNotify.ini" goto :copy
for /f "usebackq tokens=1,* delims==" %%a in ("%VP%\Plugins\VatTrackrNotify.ini") do if /i "%%a"=="Key" if not "%%b"=="" set "OLDKEY=1"
for /f "usebackq tokens=1,* delims==" %%a in ("%SRC%VatTrackrNotify.ini") do if /i "%%a"=="Pair" if not "%%b"=="" set "NEWPAIR=1"
if not defined OLDKEY goto :copy
if defined NEWPAIR goto :copy
set "KEEP=1"

:copy
if not exist "%VP%\Plugins" mkdir "%VP%\Plugins"
copy /y "%SRC%VatTrackrNotify.dll" "%VP%\Plugins\" >nul
if errorlevel 1 goto :copyfailed
if defined KEEP goto :unblock
copy /y "%SRC%VatTrackrNotify.ini" "%VP%\Plugins\" >nul
if errorlevel 1 goto :copyfailed

:unblock
rem --- Sperre fuer heruntergeladene Dateien aufheben, sonst laedt vPilot die DLL nicht
set "VTN_DLL=%VP%\Plugins\VatTrackrNotify.dll"
powershell -NoProfile -Command "Unblock-File -LiteralPath $env:VTN_DLL" >nul 2>&1
powershell -NoProfile -Command "if (Get-Item -LiteralPath $env:VTN_DLL -Stream Zone.Identifier -ErrorAction SilentlyContinue) { exit 1 } else { exit 0 }" >nul 2>&1
if errorlevel 1 goto :blocked

echo   Fertig. Das Plugin liegt in:
echo   Done. The plugin is in:
echo     %VP%\Plugins
echo.
if defined KEEP goto :updated
echo   Starte jetzt vPilot. Die Einrichtung geht im Browser weiter.
echo   Now start vPilot. The setup continues in your browser.
echo.
pause
exit /b 0

:updated
echo   Deine bisherige Einrichtung bleibt erhalten. Starte jetzt vPilot.
echo   Your existing setup is kept. Now start vPilot.
echo.
pause
exit /b 0

:notunzipped
echo   Bitte zuerst das Paket entpacken: Rechtsklick auf die ZIP-Datei, "Alle extrahieren".
echo   Dann diese Datei im entpackten Ordner noch einmal starten.
echo.
echo   Please unzip the package first: right-click the ZIP file, "Extract all".
echo   Then run this file again from the unzipped folder.
goto :failed

:novpilot
echo   vPilot wurde nicht gefunden. Ist es installiert?
echo   vPilot was not found. Is it installed?
goto :failed

:running
echo   vPilot laeuft noch. Bitte vPilot beenden und diese Datei noch einmal starten.
echo   vPilot is still running. Please close vPilot and run this file again.
goto :failed

:copyfailed
echo   Die Dateien liessen sich nicht kopieren nach:
echo   The files could not be copied to:
echo     %VP%\Plugins
goto :failed

:blocked
echo   Die Dateien sind kopiert, aber Windows sperrt die DLL noch.
echo   Bitte von Hand freigeben: Rechtsklick auf VatTrackrNotify.dll, "Eigenschaften",
echo   unten den Haken bei "Zulassen" setzen, OK. Der Ordner:
echo.
echo   The files are copied, but Windows still blocks the DLL.
echo   Please unblock it by hand: right-click VatTrackrNotify.dll, "Properties",
echo   tick "Unblock" at the bottom, OK. The folder:
echo     %VP%\Plugins
goto :failed

:failed
echo.
pause
exit /b 1
