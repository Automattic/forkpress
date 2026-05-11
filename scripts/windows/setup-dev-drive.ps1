<#
.SYNOPSIS
Creates a ForkPress ReFS Dev Drive VHDX for copy-on-write branch storage.

.DESCRIPTION
This script is intended for a bare Windows laptop setup flow. If it is not
already elevated, it relaunches itself with a UAC prompt. It creates a dynamic
VHDX under the current user's LocalAppData folder, attaches it, formats it as
ReFS/Dev Drive when the OS supports Dev Drive formatting, and mounts it at a
normal user-visible folder.
#>

[CmdletBinding()]
param(
    [string] $VhdPath = "$env:LOCALAPPDATA\ForkPress\Storage\forkpress-dev-drive.vhdx",
    [string] $MountPath = "$env:USERPROFILE\ForkPressDevDrive",
    [UInt32] $SizeGB = 128,
    [switch] $EnableProjFS
)

$ErrorActionPreference = 'Stop'

function Test-Administrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = [Security.Principal.WindowsPrincipal]::new($identity)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Invoke-DiskPartScript {
    param([string] $Script)

    $scriptPath = Join-Path $env:TEMP "forkpress-diskpart-$PID.txt"
    Set-Content -Path $scriptPath -Value $Script -Encoding ASCII
    try {
        $output = & diskpart.exe /s $scriptPath 2>&1
        if ($LASTEXITCODE -ne 0) {
            throw "diskpart failed with exit code $LASTEXITCODE`n$output"
        }
    } finally {
        Remove-Item -Path $scriptPath -Force -ErrorAction SilentlyContinue
    }
}

if (-not (Test-Administrator)) {
    $startArgs = @(
        '-NoProfile',
        '-ExecutionPolicy', 'Bypass',
        '-File', "`"$PSCommandPath`"",
        '-VhdPath', "`"$VhdPath`"",
        '-MountPath', "`"$MountPath`"",
        '-SizeGB', "$SizeGB"
    )
    if ($EnableProjFS) {
        $startArgs += '-EnableProjFS'
    }
    Start-Process -FilePath 'powershell.exe' -Verb RunAs -ArgumentList $startArgs
    exit
}

if ($SizeGB -lt 50) {
    throw 'Dev Drive volumes must be at least 50 GB.'
}

$VhdPath = [System.IO.Path]::GetFullPath($VhdPath)
$MountPath = [System.IO.Path]::GetFullPath($MountPath)
if (-not $MountPath.EndsWith('\')) {
    $MountPath = "$MountPath\"
}

New-Item -ItemType Directory -Force -Path (Split-Path -Parent $VhdPath) | Out-Null
New-Item -ItemType Directory -Force -Path $MountPath | Out-Null

if ($EnableProjFS) {
    $feature = Get-WindowsOptionalFeature -Online -FeatureName Client-ProjFS
    if ($feature.State -ne 'Enabled') {
        $result = Enable-WindowsOptionalFeature -Online -FeatureName Client-ProjFS -NoRestart
        if ($result.RestartNeeded) {
            Write-Host 'Windows enabled ProjFS and requested a reboot.'
        }
    }
}

if (-not (Test-Path -LiteralPath $VhdPath)) {
    $sizeMB = [UInt64] $SizeGB * 1024
    Invoke-DiskPartScript @"
create vdisk file="$VhdPath" maximum=$sizeMB type=expandable
"@
}

$image = Get-DiskImage -ImagePath $VhdPath -ErrorAction SilentlyContinue
if (-not $image -or -not $image.Attached) {
    Mount-DiskImage -ImagePath $VhdPath -ErrorAction Stop | Out-Null
}

$disk = Get-DiskImage -ImagePath $VhdPath | Get-Disk
if ($disk.PartitionStyle -eq 'RAW') {
    Initialize-Disk -Number $disk.Number -PartitionStyle GPT | Out-Null
    $partition = New-Partition -DiskNumber $disk.Number -UseMaximumSize
} else {
    $partition = Get-Partition -DiskNumber $disk.Number |
        Where-Object { $_.Type -ne 'Reserved' } |
        Select-Object -First 1
}

if (-not $partition) {
    throw "No usable partition found on $VhdPath"
}

$volume = $partition | Get-Volume -ErrorAction SilentlyContinue
if (-not $volume -or -not $volume.FileSystem) {
    $formatParams = @{
        Partition = $partition
        FileSystem = 'ReFS'
        NewFileSystemLabel = 'ForkPress'
        Confirm = $false
    }
    if ((Get-Command Format-Volume).Parameters.ContainsKey('DevDrive')) {
        $formatParams['DevDrive'] = $true
    }
    Format-Volume @formatParams | Out-Null
}

$partition = Get-Partition -DiskNumber $disk.Number -PartitionNumber $partition.PartitionNumber
$paths = @($partition.AccessPaths)
if ($paths -notcontains $MountPath) {
    Add-PartitionAccessPath `
        -DiskNumber $disk.Number `
        -PartitionNumber $partition.PartitionNumber `
        -AccessPath $MountPath
}

Write-Host ''
Write-Host 'ForkPress Dev Drive is ready.'
Write-Host "  VHDX:  $VhdPath"
Write-Host "  Mount: $MountPath"
Write-Host ''
Write-Host 'Create or move ForkPress projects under that mount path, then run:'
Write-Host "  cd `"$MountPath`""
Write-Host '  forkpress init'
