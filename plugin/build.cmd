@echo off
setlocal
rem VatTrackr Notify - Plugin aus dem Quelltext bauen.
rem Braucht kein Visual Studio: nutzt den C#-Compiler, der zu Windows gehoert,
rem und die Plugin-Schnittstelle aus deiner vPilot-Installation.

set "CSC=%WINDIR%\Microsoft.NET\Framework64\v4.0.30319\csc.exe"
if not exist "%CSC%" set "CSC=%WINDIR%\Microsoft.NET\Framework\v4.0.30319\csc.exe"
if not exist "%CSC%" (
  echo C#-Compiler nicht gefunden: %CSC%
  pause
  exit /b 1
)

set "VP="
for /f "tokens=2,*" %%a in ('reg query "HKCU\Software\vPilot" /v Install_Dir 2^>nul ^| find "Install_Dir"') do set "VP=%%b"
if not defined VP set "VP=%LOCALAPPDATA%\vPilot"
set "REF=%VP%\RossCarlson.Vatsim.Vpilot.Plugins.dll"
if not exist "%REF%" (
  echo vPilot-Schnittstelle nicht gefunden: %REF%
  echo Ist vPilot installiert?
  pause
  exit /b 1
)

"%CSC%" /nologo /target:library /optimize+ /out:"%~dp0VatTrackrNotify.dll" /reference:"%REF%" "%~dp0Plugin.cs"
if errorlevel 1 (
  echo Build fehlgeschlagen.
  pause
  exit /b 1
)

echo.
echo Fertig: %~dp0VatTrackrNotify.dll
pause
