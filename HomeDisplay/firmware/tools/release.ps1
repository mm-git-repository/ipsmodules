# Baut die Waveshare-Firmware und legt sie signiert unter release/ ab.
# Danach "git update", in IP-Symcon die Bibliothek neu laden; die Anzeigen holen das Programm über das Netz.
param(
    [string]$Key = $(if ($env:HOMEDISPLAY_OTA_KEY) { $env:HOMEDISPLAY_OTA_KEY } else { Join-Path $HOME '.homedisplay\ota-private.pem' }),
    [switch]$SkipBuild
)

$ErrorActionPreference = 'Stop'
$firmware = Split-Path -Parent $PSScriptRoot

if (-not (Test-Path $Key)) {
    throw "Privater OTA-Schluessel fehlt: $Key (Parameter -Key oder HOMEDISPLAY_OTA_KEY setzen)"
}

Push-Location $firmware
try {
    if (-not $SkipBuild) {
        eim run "idf.py build" v5.3.2
        if ($LASTEXITCODE -ne 0) { throw "Build fehlgeschlagen" }
    }
    python (Join-Path $PSScriptRoot 'sign_release.py') --key $Key
    if ($LASTEXITCODE -ne 0) { throw "Signieren fehlgeschlagen" }
} finally {
    Pop-Location
}
