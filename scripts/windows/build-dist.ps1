<#
.SYNOPSIS
Builds the Windows runtime bundle input consumed by forkpress.exe.

.DESCRIPTION
ForkPress embeds PHP into its Rust binary. Linux/macOS use static-php-cli;
Windows uses the official PHP for Windows NTS x64 zip and writes a php.ini that
enables the extensions needed by the WordPress/SQLite runtime.
#>

[CmdletBinding()]
param(
    [string] $Target = $env:FORKPRESS_TARGET,
    [string] $PhpZipUrl = $env:FORKPRESS_WINDOWS_PHP_ZIP_URL,
    [string] $DistDir = $env:FORKPRESS_DIST_DIR,
    [string] $BuildDir = $env:FORKPRESS_BUILD_DIR
)

$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($Target)) {
    $Target = 'x86_64-pc-windows-msvc'
}
if ([string]::IsNullOrWhiteSpace($PhpZipUrl)) {
    $PhpZipUrl = 'https://windows.php.net/downloads/releases/latest/php-8.3-nts-Win32-vs16-x64-latest.zip'
}

$repoRoot = Resolve-Path (Join-Path $PSScriptRoot '..\..')
if ([string]::IsNullOrWhiteSpace($DistDir)) {
    $DistDir = Join-Path $repoRoot "dist\$Target"
}
if ([string]::IsNullOrWhiteSpace($BuildDir)) {
    $BuildDir = Join-Path $repoRoot ".build\$Target"
}

$binDir = Join-Path $DistDir 'bin'
$phpZip = Join-Path $BuildDir 'php-windows.zip'
$phpExtract = Join-Path $BuildDir 'php'

New-Item -ItemType Directory -Force -Path $BuildDir, $binDir | Out-Null

if (-not (Test-Path -LiteralPath $phpZip)) {
    Write-Host "==> Downloading PHP for Windows"
    Invoke-WebRequest -Uri $PhpZipUrl -OutFile $phpZip
}

if (-not (Test-Path -LiteralPath (Join-Path $phpExtract 'php.exe'))) {
    Write-Host "==> Extracting PHP"
    if (Test-Path -LiteralPath $phpExtract) {
        Remove-Item -Recurse -Force -LiteralPath $phpExtract
    }
    New-Item -ItemType Directory -Force -Path $phpExtract | Out-Null
    Expand-Archive -Path $phpZip -DestinationPath $phpExtract
}

Write-Host "==> Copying PHP runtime"
Remove-Item -Recurse -Force -LiteralPath $binDir -ErrorAction SilentlyContinue
New-Item -ItemType Directory -Force -Path $binDir | Out-Null
Copy-Item -Recurse -Force -Path (Join-Path $phpExtract '*') -Destination $binDir

$extensions = @(
    'curl',
    'exif',
    'fileinfo',
    'mbstring',
    'openssl',
    'pdo_sqlite',
    'sqlite3',
    'zip'
)

$phpIni = @(
    'memory_limit=512M',
    'max_execution_time=120',
    'extension_dir=ext'
)
foreach ($extension in $extensions) {
    $dll = Join-Path $binDir "ext\php_$extension.dll"
    if (Test-Path -LiteralPath $dll) {
        $phpIni += "extension=$extension"
    }
}
$phpIni += @(
    'variables_order=GPCS',
    'date.timezone=UTC'
)
Set-Content -Path (Join-Path $binDir 'php.ini') -Value $phpIni -Encoding ASCII

$php = Join-Path $binDir 'php.exe'
$requiredModules = @('PDO', 'pdo_sqlite', 'sqlite3', 'curl', 'mbstring', 'openssl', 'zip')
$modules = & $php -m
if ($LASTEXITCODE -ne 0) {
    throw "Bundled php.exe failed to run. Install the Microsoft Visual C++ Redistributable or use scripts\windows\install.ps1."
}
foreach ($module in $requiredModules) {
    if ($modules -notcontains $module) {
        throw "Bundled php.exe is missing required module: $module"
    }
}

Write-Host "dist/$Target/ ready:"
Get-Item $php | Format-List FullName,Length
Write-Host ''
Write-Host 'Next: cargo build --release --target x86_64-pc-windows-msvc -p forkpress-cli --bin forkpress'
