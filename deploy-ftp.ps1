# Despliegue por FTP del proyecto PHP (sin SSH ni git en el servidor).
# PHP no se compila: se suben los ficheros tal cual. Nunca sube .env ni var/.
#
# Uso:
#   $env:NUTRIPLAN_FTP_PASSWORD = "tu-clave"   # o la pide por pantalla
#   .\deploy-ftp.ps1                        # actualizacion normal (sin vendor/)
#   .\deploy-ftp.ps1 -Vendor                # incluye vendor/ (tras cambiar composer)
#   .\deploy-ftp.ps1 -Zip                   # empaqueta nutriplan-vX.zip para el autoinstalador (no sube nada)
#   .\deploy-ftp.ps1 -FirstInstall -Vendor   # primera vez: + .env.example y checklist
param([switch]$Vendor, [switch]$FirstInstall, [switch]$Zip)

# ---------------- CONFIG ----------------
$FtpHost   = "ftp.tu-servidor.com"  # host FTP del panel
$FtpUser   = "usuario@tu-dominio.com"
$RemoteDir = "/nutriplan"            # raiz de la app en el hosting (DocumentRoot -> public/)
$UseFtps   = $false                  # $true si tu hosting exige FTP explicito con TLS
# ----------------------------------------

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $Root

if (-not (Get-Command curl.exe -ErrorAction SilentlyContinue)) { Write-Error "Falta curl.exe (viene con Windows 10+)." }

# Ficheros que NUNCA salen de tu maquina
$excluded = @(".env", "test-login.php") | ForEach-Object { $_.ToLower() }

# La clave FTP solo se pide al subir (el modo -Zip no la necesita).
# ponytail: la clave con ':' rompe --user; si la tuya lo lleva, cambiala en el panel
$ftpBase = "ftp://$FtpHost$RemoteDir"
$ssl = @(); if ($UseFtps) { $ssl = @("--ssl") }

function Send-File([string]$local, [string]$remote) {
  $url = "$ftpBase/$remote"
  & curl.exe --fail --silent --show-error --ftp-create-dirs @ssl --user "${FtpUser}:${pass}" -T $local $url
  if ($LASTEXITCODE -ne 0) { Write-Error "Fallo la subida de $remote" }
}

Write-Host "==> 1/2 Comprobando proyecto..."
foreach ($req in @("public/index.php", "config.php", "composer.json", "data/recipes.json", "vendor/autoload.php")) {
  if (-not (Test-Path (Join-Path $Root $req))) { Write-Error "Falta $req en local." }
}
Write-Host "    OK: ficheros base presentes."

if ($Zip) {
  if (-not (Test-Path (Join-Path $Root "installer.php"))) { Write-Error "Falta installer.php en local." }
  Write-Host "==> Empaquetando ZIP para el autoinstalador..."
  $ver = (Get-Content (Join-Path $Root "version.txt") -Raw).Trim()
  $zipName = "nutriplan-v$ver.zip"
  $zipPath = Join-Path $Root $zipName
  $stage = Join-Path ([IO.Path]::GetTempPath()) "nutriplan-stage"
  if (Test-Path $stage) { Remove-Item $stage -Recurse -Force }
  $dirs = @("public", "src", "templates", "data", "database")
  if ($Vendor) { $dirs += "vendor" }
  foreach ($d in $dirs) {
    Copy-Item (Join-Path $Root $d) (Join-Path $stage $d) -Recurse -Force
  }
  foreach ($f in @("composer.json", "composer.lock", "config.php", "version.txt")) {
    Copy-Item (Join-Path $Root $f) $stage -Force
  }
  Get-ChildItem $stage -Recurse -File | Where-Object { $excluded -contains $_.Name.ToLower() } | Remove-Item -Force
  if (Test-Path $zipPath) { Remove-Item $zipPath -Force }
  Compress-Archive -Path (Join-Path $stage "*") -DestinationPath $zipPath -CompressionLevel Optimal
  Remove-Item $stage -Recurse -Force
  Write-Host "OK: $zipName listo en $Root"
  Write-Host "Sube $zipName + installer.php a tu hosting y abre installer.php en el navegador."
  exit 0
}

$pass = $env:NUTRIPLAN_FTP_PASSWORD
if (-not $pass) {
  $sec = Read-Host "Clave FTP de $FtpUser" -AsSecureString
  $pass = [Runtime.InteropServices.Marshal]::PtrToAutoString([Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec))
}
# ponytail: la clave con ':' rompe --user; si la tuya lo lleva, cambiala en el panel
Write-Host "==> 2/2 Subiendo por FTP a $ftpBase ..."
$dirs = @("public", "src", "templates", "data", "database")
if ($Vendor) { $dirs += "vendor" }
$files = foreach ($d in $dirs) {
  Get-ChildItem $d -Recurse -File | Where-Object { $excluded -notcontains $_.Name.ToLower() }
}
$files += Get-Item composer.json, composer.lock, config.php, version.txt
$count = 0
foreach ($f in $files) {
  $rel = $f.FullName.Substring($Root.Length + 1).Replace('\', '/')
  Send-File $f.FullName $rel
  $count++
}
if ($FirstInstall) {
  Send-File (Join-Path $Root ".env.example") ".env.example"
  $count++
  Write-Host ""
  Write-Host "--- PRIMERA INSTALACION: checklist en DirectAdmin ---"
  Write-Host "1. Dominio con DocumentRoot apuntando a $RemoteDir/public, PHP 8.1+ con pdo_mysql, openssl, curl, mbstring."
  Write-Host "2. Importa database/schema.sql en tu MySQL (phpMyAdmin) y crea .env copiando .env.example (DB_*, APP_URL, GOOGLE_CLIENT_ID)."
  Write-Host "3. En Google Cloud Console anade tu dominio a 'Origenes de JavaScript autorizados' del cliente."
  Write-Host "4. Abre tu dominio: veras el login con Google y luego el onboarding de 4 pasos."
  Write-Host "------------------------------------------------------"
  Write-Host ""
}
Write-Host "OK: $count ficheros desplegados (vendor incluido: $Vendor)."
