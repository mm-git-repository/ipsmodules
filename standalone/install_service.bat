@echo off
:: PIXOO Energy Viewer - Windows Service Installation
:: Muss als Administrator ausgeführt werden!

net session >nul 2>&1
if %errorlevel% neq 0 (
    echo FEHLER: Bitte als Administrator ausfuehren!
    echo Rechtsklick auf diese Datei ^> "Als Administrator ausfuehren"
    pause
    exit /b 1
)

set SERVICE_NAME=PixooEnergyViewer
set SCRIPT_DIR=%~dp0
set PYTHON_EXE=python

:: Prüfe ob nssm vorhanden ist
where nssm >nul 2>&1
if %errorlevel% neq 0 (
    echo NSSM nicht gefunden. Installiere via winget...
    winget install --id nssm.nssm --accept-package-agreements --accept-source-agreements
    if %errorlevel% neq 0 (
        echo FEHLER: NSSM konnte nicht installiert werden.
        echo Bitte manuell installieren: https://nssm.cc/download
        pause
        exit /b 1
    )
)

:: Finde Python-Pfad
for /f "tokens=*" %%i in ('where python 2^>nul') do set PYTHON_EXE=%%i
echo Python: %PYTHON_EXE%
echo Script: %SCRIPT_DIR%sma_pixoo_display.py

:: Entferne alten Service falls vorhanden
nssm stop %SERVICE_NAME% >nul 2>&1
nssm remove %SERVICE_NAME% confirm >nul 2>&1

:: Installiere Service
nssm install %SERVICE_NAME% "%PYTHON_EXE%" "-u" "%SCRIPT_DIR%sma_pixoo_display.py"
nssm set %SERVICE_NAME% AppDirectory "%SCRIPT_DIR%"
nssm set %SERVICE_NAME% DisplayName "PIXOO Energy Viewer"
nssm set %SERVICE_NAME% Description "Zeigt Energiedaten auf Divoom Pixoo-64 an"
nssm set %SERVICE_NAME% Start SERVICE_AUTO_START
nssm set %SERVICE_NAME% AppStdout "%SCRIPT_DIR%service.log"
nssm set %SERVICE_NAME% AppStderr "%SCRIPT_DIR%service.log"
nssm set %SERVICE_NAME% AppStdoutCreationDisposition 2
nssm set %SERVICE_NAME% AppStderrCreationDisposition 2
nssm set %SERVICE_NAME% AppRotateFiles 1
nssm set %SERVICE_NAME% AppRotateBytes 1048576
nssm set %SERVICE_NAME% AppRestartDelay 5000

:: Starte Service
nssm start %SERVICE_NAME%

echo.
echo ========================================
echo  Service "%SERVICE_NAME%" installiert!
echo ========================================
echo.
echo  Status:    nssm status %SERVICE_NAME%
echo  Stoppen:   nssm stop %SERVICE_NAME%
echo  Starten:   nssm start %SERVICE_NAME%
echo  Log:       %SCRIPT_DIR%service.log
echo  Entfernen: nssm remove %SERVICE_NAME% confirm
echo.
pause
