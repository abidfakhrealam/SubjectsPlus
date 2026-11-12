<#
.SYNOPSIS
  Build the SubjectsPlus stable release archives on Windows (PowerShell 5.1+ or PowerShell 7).

.DESCRIPTION
  Same result as build/package-release.sh:
    dist\SubjectsPlus-<version>-stable.zip
    dist\SubjectsPlus-<version>-stable.tar.gz   (needs tar.exe, included with Windows 10 1803+)
    dist\SHA256SUMS.txt
  Runs php -l on every PHP file and the front-end bundle check first. Needs php.exe and git.exe on PATH
  (e.g. C:\wamp64\bin\php\php8.3.x). Only committed files are packed (git archive HEAD).

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File build\package-release.ps1
  powershell -ExecutionPolicy Bypass -File build\package-release.ps1 -Version 4.6.0
#>
param(
  [string]$Version = ''
)
$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root

if (-not $Version) {
  $Version = (& php -r 'include "control/includes/version.php"; echo $sp_version;').Trim()
}
$Name  = "SubjectsPlus-$Version-stable"
$Dist  = Join-Path $Root 'dist'
$Stage = Join-Path ([IO.Path]::GetTempPath()) ("sp-release-" + [guid]::NewGuid())
Write-Host "==> Packaging $Name"

# 1. checks
Write-Host "==> php -l on all PHP files"
$bad = 0
Get-ChildItem -Path $Root -Recurse -Filter *.php -File |
  Where-Object { $_.FullName -notmatch '[\\/](\.git|node_modules|dist)[\\/]' } |
  ForEach-Object {
    $out = & php -l $_.FullName 2>&1
    if ($LASTEXITCODE -ne 0) { Write-Host $out; $bad++ }
  }
if ($bad -gt 0) { throw "$bad PHP file(s) with syntax errors" }
Write-Host "    all files OK"
Write-Host "==> building / verifying front-end bundles"
& php build/build-assets.php
if ($LASTEXITCODE -ne 0) { throw 'front-end bundle check failed' }

# 2. export the committed tree
New-Item -ItemType Directory -Force -Path $Dist, $Stage | Out-Null
$Tree = Join-Path $Stage $Name
$TarTmp = Join-Path $Stage 'src.tar'
& git archive --format=tar -o $TarTmp HEAD
if ($LASTEXITCODE -ne 0) { throw 'git archive failed (run inside the git checkout)' }
New-Item -ItemType Directory -Force -Path $Tree | Out-Null
& tar -xf $TarTmp -C $Tree
Remove-Item $TarTmp

# 3. strip development files
$remove = @('.git', '.github', 'node_modules', 'tests', 'build', 'dist', '.gitignore', '.gitattributes', '.gitmodules',
            '.editorconfig', '.snyk', '.env', 'package.json', 'package-lock.json', 'phpstan.neon.dist', 'phpstan-baseline.neon',
            'control\includes\config.php')
foreach ($r in $remove) {
  $p = Join-Path $Tree $r
  if (Test-Path $p) { Remove-Item -Recurse -Force $p }
}
Get-ChildItem (Join-Path $Tree 'assets\cache') -File -Force |
  Where-Object { $_.Name -notin @('.htaccess', '.cache', '.gitkeep') } | Remove-Item -Force
foreach ($d in @('assets\cache', 'assets\users', 'assets\records', 'assets\images\guide_thumbs', 'control\lti_controller\temp_files')) {
  $p = Join-Path $Tree $d
  New-Item -ItemType Directory -Force -Path $p | Out-Null
  if (-not (Get-ChildItem $p -Force)) { New-Item -ItemType File -Path (Join-Path $p '.gitkeep') | Out-Null }
}

# 4. archives
$Zip = Join-Path $Dist "$Name.zip"
$Tgz = Join-Path $Dist "$Name.tar.gz"
Remove-Item -Force -ErrorAction SilentlyContinue $Zip, $Tgz
Add-Type -AssemblyName System.IO.Compression.FileSystem
[IO.Compression.ZipFile]::CreateFromDirectory($Tree, $Zip, [IO.Compression.CompressionLevel]::Optimal, $true)
& tar -czf $Tgz -C $Stage $Name
if ($LASTEXITCODE -ne 0) { throw 'tar failed' }

# 5. checksums (same format as sha256sum: "<hash>  <file>")
$lines = foreach ($f in @($Zip, $Tgz)) {
  "{0}  {1}" -f (Get-FileHash -Algorithm SHA256 $f).Hash.ToLower(), (Split-Path $f -Leaf)
}
Set-Content -Path (Join-Path $Dist 'SHA256SUMS.txt') -Value $lines -Encoding ascii
Remove-Item -Recurse -Force $Stage
Write-Host "==> done"
Get-ChildItem $Zip, $Tgz | Format-Table Name, Length
Get-Content (Join-Path $Dist 'SHA256SUMS.txt')
