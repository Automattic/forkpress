<#
.SYNOPSIS
Creates the Windows zip package with a double-click setup entry point.
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string] $ForkPressExe,
    [string] $Output = 'forkpress-x86_64-pc-windows-msvc.zip',
    [string] $StageDir = '',
    [string] $VcRedistPath = $env:FORKPRESS_VC_REDIST,
    [switch] $KeepStage
)

$ErrorActionPreference = 'Stop'

$repoRoot = Resolve-Path (Join-Path $PSScriptRoot '..\..')
if ([string]::IsNullOrWhiteSpace($StageDir)) {
    $stage = Join-Path $env:TEMP "forkpress-windows-package-$PID"
} else {
    $stage = $StageDir
}
Remove-Item -Recurse -Force -LiteralPath $stage -ErrorAction SilentlyContinue
New-Item -ItemType Directory -Force -Path $stage | Out-Null

Copy-Item -Force -LiteralPath $ForkPressExe -Destination (Join-Path $stage 'forkpress.exe')

$vendorDest = Join-Path $stage 'vendor'
New-Item -ItemType Directory -Force -Path $vendorDest | Out-Null
if ([string]::IsNullOrWhiteSpace($VcRedistPath)) {
    $cacheDir = Join-Path $repoRoot '.build\windows-prereqs'
    New-Item -ItemType Directory -Force -Path $cacheDir | Out-Null
    $VcRedistPath = Join-Path $cacheDir 'vc_redist.x64.exe'
    if (-not (Test-Path -LiteralPath $VcRedistPath)) {
        Write-Host '==> Downloading Microsoft Visual C++ Redistributable'
        Invoke-WebRequest -Uri 'https://aka.ms/vs/17/release/vc_redist.x64.exe' -OutFile $VcRedistPath
    }
}
Copy-Item -Force -LiteralPath $VcRedistPath -Destination (Join-Path $vendorDest 'vc_redist.x64.exe')

$scriptDest = Join-Path $stage 'scripts\windows'
New-Item -ItemType Directory -Force -Path $scriptDest | Out-Null
Copy-Item -Force -LiteralPath (Join-Path $PSScriptRoot 'install.ps1') -Destination $scriptDest
Copy-Item -Force -LiteralPath (Join-Path $PSScriptRoot 'setup-dev-drive.ps1') -Destination $scriptDest

Copy-Item -Force -LiteralPath (Join-Path $repoRoot 'docs\storage\windows.md') -Destination (Join-Path $stage 'README-WINDOWS.md')

Remove-Item -Force -LiteralPath $Output -ErrorAction SilentlyContinue
Compress-Archive -Path (Join-Path $stage '*') -DestinationPath $Output
if (-not $KeepStage) {
    Remove-Item -Recurse -Force -LiteralPath $stage
}

Write-Host "Created $Output"
