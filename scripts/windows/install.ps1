<#
.SYNOPSIS
Installs ForkPress for the current Windows user and prepares COW storage.
#>

[CmdletBinding()]
param(
    [string] $SourceRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')),
    [string] $InstallRoot = "$env:LOCALAPPDATA\ForkPress",
    [string] $VhdPath = "$env:ProgramData\ForkPress\Storage\forkpress-dev-drive.vhdx",
    [string] $MountPath = "$env:USERPROFILE\ForkPressDevDrive",
    [string] $SiteName = 'My ForkPress Site',
    [UInt32] $SizeGB = 128,
    [switch] $AllowPlainReFS,
    [switch] $FailOnRebootRequired,
    [switch] $SkipAutoMount,
    [switch] $SkipDevDrive
)

$ErrorActionPreference = 'Stop'

function Write-Step {
    param([string] $Message)
    Write-Host ''
    Write-Host "==> $Message"
}

function New-Shortcut {
    param(
        [string] $Path,
        [string] $TargetPath,
        [string] $Arguments = '',
        [string] $WorkingDirectory = ''
    )

    $shell = New-Object -ComObject WScript.Shell
    $shortcut = $shell.CreateShortcut($Path)
    $shortcut.TargetPath = $TargetPath
    $shortcut.Arguments = $Arguments
    if ($WorkingDirectory) {
        $shortcut.WorkingDirectory = $WorkingDirectory
    }
    $shortcut.Save()
}

function Add-UserPath {
    param([string] $PathToAdd)

    $current = [Environment]::GetEnvironmentVariable('Path', 'User')
    $entries = @()
    if ($current) {
        $entries = $current -split ';' | Where-Object { $_ }
    }
    if ($entries -notcontains $PathToAdd) {
        $next = ($entries + $PathToAdd) -join ';'
        [Environment]::SetEnvironmentVariable('Path', $next, 'User')
    }
    $env:Path = "$PathToAdd;$env:Path"
}

function Test-Administrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = [Security.Principal.WindowsPrincipal]::new($identity)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Test-VcRedistInstalled {
    $keys = @(
        'HKLM:\SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\X64',
        'HKLM:\SOFTWARE\WOW6432Node\Microsoft\VisualStudio\14.0\VC\Runtimes\X64'
    )

    foreach ($key in $keys) {
        $runtime = Get-ItemProperty -Path $key -ErrorAction SilentlyContinue
        if ($runtime -and $runtime.Installed -eq 1) {
            return $true
        }
    }

    return $false
}

function ConvertTo-PowerShellEncodedCommand {
    param([string] $Command)

    return [Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes($Command))
}

function ConvertTo-PowerShellSingleQuotedString {
    param([string] $Value)

    return "'$($Value -replace "'", "''")'"
}

function Register-ForkPressSetupResume {
    param(
        [string] $InstallScript,
        [string] $SourceRoot,
        [string] $InstallRoot,
        [string] $VhdPath,
        [string] $MountPath,
        [string] $SiteName,
        [UInt32] $SizeGB,
        [switch] $AllowPlainReFS,
        [switch] $FailOnRebootRequired,
        [switch] $SkipAutoMount
    )

    $args = @(
        '-NoProfile',
        '-ExecutionPolicy', 'Bypass',
        '-File', "`"$InstallScript`"",
        '-SourceRoot', "`"$SourceRoot`"",
        '-InstallRoot', "`"$InstallRoot`"",
        '-VhdPath', "`"$VhdPath`"",
        '-MountPath', "`"$MountPath`"",
        '-SiteName', "`"$SiteName`"",
        '-SizeGB', "$SizeGB"
    )
    if ($AllowPlainReFS) {
        $args += '-AllowPlainReFS'
    }
    if ($FailOnRebootRequired) {
        $args += '-FailOnRebootRequired'
    }
    if ($SkipAutoMount) {
        $args += '-SkipAutoMount'
    }
    $powerShellPath = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
    $resumeCommand = "Start-Process -FilePath $(ConvertTo-PowerShellSingleQuotedString $powerShellPath) -Verb RunAs -ArgumentList $(ConvertTo-PowerShellSingleQuotedString ($args -join ' '))"
    $command = "`"$powerShellPath`" -NoProfile -ExecutionPolicy Bypass -EncodedCommand $(ConvertTo-PowerShellEncodedCommand $resumeCommand)"
    $runOnce = 'HKCU:\Software\Microsoft\Windows\CurrentVersion\RunOnce'
    New-Item -Path $runOnce -Force | Out-Null
    Set-ItemProperty -Path $runOnce -Name 'ForkPressSetupResume' -Value $command
}

function Show-ForkPressRebootPrompt {
    $message = 'Windows needs to restart before ForkPress setup can finish. Setup will resume automatically after you sign in again. Restart now?'
    try {
        $shell = New-Object -ComObject WScript.Shell
        $choice = $shell.Popup($message, 0, 'ForkPress Setup', 0x4 + 0x40)
        if ($choice -eq 6) {
            & shutdown.exe /r /t 30 /c 'ForkPress setup will resume after restart.'
        }
    } catch {
        Write-Host $message
    }
}

Write-Host 'ForkPress Windows Setup'
Write-Host 'This installs ForkPress and prepares native Windows COW storage.'

if (-not $SkipDevDrive -and -not (Test-Administrator)) {
    throw 'ForkPress setup must run elevated to create a Windows Dev Drive. Use ForkPressSetup.exe for the guided installer flow.'
}

$SourceRoot = [System.IO.Path]::GetFullPath($SourceRoot)
$InstallRoot = [System.IO.Path]::GetFullPath($InstallRoot)
$VhdPath = [System.IO.Path]::GetFullPath($VhdPath)
$MountPath = [System.IO.Path]::GetFullPath($MountPath)
$currentUserId = [Security.Principal.WindowsIdentity]::GetCurrent().Name
$binDir = Join-Path $InstallRoot 'bin'
$logDir = Join-Path $InstallRoot 'Logs'
$scriptsDir = Join-Path $InstallRoot 'scripts\windows'
$forkpressSource = Join-Path $SourceRoot 'forkpress.exe'
if (-not (Test-Path -LiteralPath $forkpressSource)) {
    $forkpressSource = Join-Path $SourceRoot 'bin\forkpress.exe'
}
if (-not (Test-Path -LiteralPath $forkpressSource)) {
    $forkpressSource = Join-Path $SourceRoot 'target\x86_64-pc-windows-msvc\release\forkpress.exe'
}
if (-not (Test-Path -LiteralPath $forkpressSource)) {
    throw "Could not find forkpress.exe under $SourceRoot"
}

Write-Step 'Installing files'
New-Item -ItemType Directory -Force -Path $binDir, $logDir, $scriptsDir | Out-Null
$forkpressDest = Join-Path $binDir 'forkpress.exe'
if ([System.IO.Path]::GetFullPath($forkpressSource) -ne [System.IO.Path]::GetFullPath($forkpressDest)) {
    Copy-Item -Force -LiteralPath $forkpressSource -Destination $forkpressDest
}
$setupSource = Join-Path $PSScriptRoot 'setup-dev-drive.ps1'
$setupDest = Join-Path $scriptsDir 'setup-dev-drive.ps1'
if ([System.IO.Path]::GetFullPath($setupSource) -ne [System.IO.Path]::GetFullPath($setupDest)) {
    Copy-Item -Force -LiteralPath $setupSource -Destination $setupDest
}

Write-Step 'Adding ForkPress to your user PATH'
Add-UserPath $binDir

Write-Step 'Installing Microsoft Visual C++ runtime'
if (Test-VcRedistInstalled) {
    Write-Host 'Microsoft Visual C++ runtime is already installed.'
} else {
    $redist = Join-Path $SourceRoot 'vendor\vc_redist.x64.exe'
    if (-not (Test-Path -LiteralPath $redist)) {
        throw "Microsoft Visual C++ runtime is not installed and the bundled installer is missing: $redist"
    }
    $redistProcess = Start-Process -FilePath $redist -ArgumentList '/install', '/quiet', '/norestart' -Wait -PassThru
    if ($redistProcess.ExitCode -notin @(0, 3010)) {
        throw "VC++ Redistributable installer failed with exit code $($redistProcess.ExitCode)"
    }
    if ($redistProcess.ExitCode -eq 3010) {
        Register-ForkPressSetupResume `
            -InstallScript (Join-Path $scriptsDir 'install.ps1') `
            -SourceRoot $InstallRoot `
            -InstallRoot $InstallRoot `
            -VhdPath $VhdPath `
            -MountPath $MountPath `
            -SiteName $SiteName `
            -SizeGB $SizeGB `
            -AllowPlainReFS:$AllowPlainReFS `
            -FailOnRebootRequired:$FailOnRebootRequired `
            -SkipAutoMount:$SkipAutoMount
        Write-Host ''
        Write-Host 'Windows needs a restart before ForkPress setup can finish.'
        Write-Host 'Setup will resume automatically after you sign in again.'
        if ($FailOnRebootRequired) {
            throw 'Windows requested a restart before ForkPress setup could finish.'
        }
        Show-ForkPressRebootPrompt
        exit 0
    }
}

if (-not $SkipDevDrive) {
    Write-Step 'Preparing ForkPress Dev Drive'
    $setupScript = Join-Path $scriptsDir 'setup-dev-drive.ps1'
    $powerShellPath = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
    $setupArgs = @(
        '-NoProfile',
        '-ExecutionPolicy', 'Bypass',
        '-File', $setupScript,
        '-VhdPath', $VhdPath,
        '-MountPath', $MountPath,
        '-SizeGB', "$SizeGB",
        '-AutoMountUserId', $currentUserId,
        '-LogPath', (Join-Path $logDir 'setup-dev-drive.log')
    )
    if ($AllowPlainReFS) {
        $setupArgs += '-AllowPlainReFS'
    }
    if ($SkipAutoMount) {
        $setupArgs += '-SkipAutoMount'
    }
    & $powerShellPath @setupArgs
    if ($LASTEXITCODE -ne 0) {
        throw "Dev Drive setup failed with exit code $LASTEXITCODE"
    }
}

$sitesDir = Join-Path $MountPath 'Sites'
New-Item -ItemType Directory -Force -Path $sitesDir | Out-Null
$siteDir = Join-Path $sitesDir $SiteName
New-Item -ItemType Directory -Force -Path $siteDir | Out-Null
$siteManifest = Join-Path $siteDir '.forkpress\site.toml'
if (-not (Test-Path -LiteralPath $siteManifest)) {
    Write-Step 'Creating your first ForkPress site'
    Push-Location $siteDir
    try {
        & $forkpressDest init --work-dir (Join-Path $siteDir '.forkpress') --site-title $SiteName
        if ($LASTEXITCODE -ne 0) {
            throw "forkpress init failed with exit code $LASTEXITCODE"
        }
    } finally {
        Pop-Location
    }
}

Write-Step 'Creating shortcuts'
$desktop = [Environment]::GetFolderPath('DesktopDirectory')
$startMenu = Join-Path ([Environment]::GetFolderPath('Programs')) 'ForkPress'
New-Item -ItemType Directory -Force -Path $startMenu | Out-Null

$siteLiteral = ConvertTo-PowerShellSingleQuotedString $siteDir
$binPrefixLiteral = ConvertTo-PowerShellSingleQuotedString "$binDir;"
$forkpressLiteral = ConvertTo-PowerShellSingleQuotedString $forkpressDest
$siteUrlLiteral = ConvertTo-PowerShellSingleQuotedString 'http://127.0.0.1:18080/'
$shellCommand = "Set-Location -LiteralPath $siteLiteral; `$env:Path = $binPrefixLiteral + `$env:Path; Write-Host 'ForkPress is ready in this site folder.'"
$shellArgs = "-NoExit -ExecutionPolicy Bypass -EncodedCommand $(ConvertTo-PowerShellEncodedCommand $shellCommand)"
$startCommand = "Set-Location -LiteralPath $siteLiteral; `$env:Path = $binPrefixLiteral + `$env:Path; & $forkpressLiteral start --background; if (`$LASTEXITCODE -eq 0) { Start-Process $siteUrlLiteral } else { Read-Host 'ForkPress could not start. Press Enter to close' }"
$startArgs = "-NoExit -ExecutionPolicy Bypass -EncodedCommand $(ConvertTo-PowerShellEncodedCommand $startCommand)"
New-Shortcut -Path (Join-Path $desktop 'ForkPress Shell.lnk') -TargetPath 'powershell.exe' -Arguments $shellArgs -WorkingDirectory $siteDir
New-Shortcut -Path (Join-Path $startMenu 'ForkPress Shell.lnk') -TargetPath 'powershell.exe' -Arguments $shellArgs -WorkingDirectory $siteDir
New-Shortcut -Path (Join-Path $desktop 'Start ForkPress Site.lnk') -TargetPath 'powershell.exe' -Arguments $startArgs -WorkingDirectory $siteDir
New-Shortcut -Path (Join-Path $startMenu 'Start ForkPress Site.lnk') -TargetPath 'powershell.exe' -Arguments $startArgs -WorkingDirectory $siteDir
New-Shortcut -Path (Join-Path $desktop 'ForkPress Dev Drive.lnk') -TargetPath 'explorer.exe' -Arguments "`"$MountPath`""
New-Shortcut -Path (Join-Path $startMenu 'ForkPress Dev Drive.lnk') -TargetPath 'explorer.exe' -Arguments "`"$MountPath`""

Write-Host ''
Write-Host 'ForkPress is installed.'
Write-Host "  Command: $(Join-Path $binDir 'forkpress.exe')"
Write-Host "  Dev Drive: $MountPath"
Write-Host "  Site: $siteDir"
Write-Host ''
Write-Host 'Open "Start ForkPress Site" from your desktop or Start Menu.'
