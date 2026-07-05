@echo off
:: PIXOO Energy Viewer - Windows Service Deinstallation
:: Muss als Administrator ausgeführt werden!

net session >nul 2>&1
if %errorlevel% neq 0 (
    echo FEHLER: Bitte als Administrator ausfuehren!
    pause
    exit /b 1
)

set SERVICE_NAME=PixooEnergyViewer

nssm stop %SERVICE_NAME%
nssm remove %SERVICE_NAME% confirm

echo.
echo Service "%SERVICE_NAME%" entfernt.
pause
