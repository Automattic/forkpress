<#
.SYNOPSIS
Creates and attaches the ForkPress ReFS Dev Drive VHDX.

.DESCRIPTION
This script is the elevated storage setup used by the Windows installer. It is
idempotent: an existing VHDX is reused only after the mounted volume is verified
as ReFS/Dev Drive storage. It also registers a logon scheduled task so the VHDX
is reattached after reboot.
#>

[CmdletBinding()]
param(
    [string] $VhdPath = "$env:ProgramData\ForkPress\Storage\forkpress-dev-drive.vhdx",
    [string] $MountPath = "$env:USERPROFILE\ForkPressDevDrive",
    [UInt32] $SizeGB = 128,
    [switch] $AttachOnly,
    [switch] $PreflightOnly,
    [switch] $SkipAutoMount,
    [switch] $AllowPlainReFS,
    [string] $AutoMountUserId = '',
    [string] $LogPath = "$env:LOCALAPPDATA\ForkPress\Logs\setup-dev-drive.log"
)

$ErrorActionPreference = 'Stop'

function Write-Step {
    param([string] $Message)
    Write-Host "==> $Message"
}

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

function Invoke-CheckedNativeCommand {
    param(
        [string] $FilePath,
        [string[]] $Arguments = @()
    )

    $output = & $FilePath @Arguments 2>&1
    $exitCode = $LASTEXITCODE
    if ($output) {
        $output | ForEach-Object { Write-Host $_ }
    }
    if ($exitCode -ne 0) {
        throw "$FilePath $($Arguments -join ' ') failed with exit code $exitCode`n$($output -join "`n")"
    }
    return @($output | ForEach-Object { "$_" })
}

function ConvertTo-PowerShellEncodedCommand {
    param([string] $Command)

    return [Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes($Command))
}

function ConvertTo-PowerShellSingleQuotedString {
    param([string] $Value)

    return "'$($Value -replace "'", "''")'"
}

function Test-TrustedDevDriveQuery {
    param([string[]] $QueryOutput)

    $queryText = (($QueryOutput | ForEach-Object { "$_" }) -join "`n").ToLowerInvariant()
    if ($queryText -match 'not\s+(a\s+)?(trusted\s+)?(dev drive|developer volume)' -or
        $queryText -match 'untrusted') {
        return $false
    }

    if ($queryText -match 'this\s+is\s+a\s+trusted\s+(dev drive|developer volume)') {
        return $true
    }

    if ($queryText -match '(dev drive|developer volume)\s*:\s*(yes|true)' -and
        $queryText -match 'trusted\s*:\s*(yes|true)') {
        return $true
    }

    return $false
}

function Assert-ForkPressTrustedDevDrive {
    param([string] $MountPath)

    $query = Invoke-CheckedNativeCommand -FilePath 'fsutil.exe' -Arguments @('devdrv', 'query', $MountPath)
    if (-not (Test-TrustedDevDriveQuery -QueryOutput $query)) {
        throw "Windows did not report $MountPath as a trusted Dev Drive. Remove the VHDX and run ForkPress Setup again, or rerun with -AllowPlainReFS only for local testing."
    }
}

function Protect-ForkPressVhdPath {
    param([string] $VhdPath)

    $protectedRoot = [System.IO.Path]::GetFullPath((Join-Path $env:ProgramData 'ForkPress'))
    $protectedRootPrefix = $protectedRoot.TrimEnd('\') + '\'
    $storageDir = Split-Path -Parent $VhdPath
    $storageDir = [System.IO.Path]::GetFullPath($storageDir)
    $vhdExistsBeforeProtection = Test-Path -LiteralPath $VhdPath
    if ($vhdExistsBeforeProtection) {
        $item = Get-Item -LiteralPath $VhdPath -Force
        if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) {
            throw "Refusing to use reparse-point VHDX path: $VhdPath"
        }
        if (-not (Test-ForkPressProtectedAcl -Path $VhdPath)) {
            throw "Refusing to reuse an existing ForkPress VHDX that was not already protected: $VhdPath. Remove it as an administrator and run setup again."
        }
    }

    $directoriesToProtect = @()
    if ($storageDir.Equals($protectedRoot, [StringComparison]::OrdinalIgnoreCase) -or
        $storageDir.StartsWith($protectedRootPrefix, [StringComparison]::OrdinalIgnoreCase)) {
        $directoriesToProtect += $protectedRoot
    }
    $directoriesToProtect += $storageDir

    foreach ($directory in $directoriesToProtect) {
        if (Test-Path -LiteralPath $directory) {
            $item = Get-Item -LiteralPath $directory -Force
            if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) {
                throw "Refusing to use reparse-point directory for ForkPress VHDX storage: $directory"
            }
        }
        New-Item -ItemType Directory -Force -Path $directory | Out-Null
        $item = Get-Item -LiteralPath $directory -Force
        if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) {
            throw "Refusing to use reparse-point directory for ForkPress VHDX storage: $directory"
        }
        Set-ForkPressProtectedAcl -Path $directory -Container
    }

    if (Test-Path -LiteralPath $VhdPath) {
        $item = Get-Item -LiteralPath $VhdPath -Force
        if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) {
            throw "Refusing to use reparse-point VHDX path: $VhdPath"
        }
        Set-ForkPressProtectedAcl -Path $VhdPath
    }
}

function Set-ForkPressProtectedAcl {
    param(
        [string] $Path,
        [switch] $Container
    )

    $acl = Get-Acl -LiteralPath $Path
    $acl.SetAccessRuleProtection($true, $false)
    $administratorsSid = [System.Security.Principal.SecurityIdentifier]::new('S-1-5-32-544')
    $acl.SetOwner($administratorsSid)
    foreach ($rule in @($acl.Access)) {
        $acl.RemoveAccessRuleSpecific($rule) | Out-Null
    }

    $inheritanceFlags = [System.Security.AccessControl.InheritanceFlags]::None
    if ($Container) {
        $inheritanceFlags = [System.Security.AccessControl.InheritanceFlags]::ContainerInherit -bor [System.Security.AccessControl.InheritanceFlags]::ObjectInherit
    }
    $propagationFlags = [System.Security.AccessControl.PropagationFlags]::None
    $rights = [System.Security.AccessControl.FileSystemRights]::FullControl
    $allow = [System.Security.AccessControl.AccessControlType]::Allow
    foreach ($sidValue in @('S-1-5-18', 'S-1-5-32-544')) {
        $sid = [System.Security.Principal.SecurityIdentifier]::new($sidValue)
        $rule = [System.Security.AccessControl.FileSystemAccessRule]::new(
            $sid,
            $rights,
            $inheritanceFlags,
            $propagationFlags,
            $allow
        )
        $acl.AddAccessRule($rule)
    }
    Set-Acl -LiteralPath $Path -AclObject $acl
    Assert-ForkPressProtectedAcl -Path $Path
}

function ConvertTo-SidValue {
    param([System.Security.Principal.IdentityReference] $Identity)

    try {
        return $Identity.Translate([System.Security.Principal.SecurityIdentifier]).Value
    } catch {
        return $Identity.Value
    }
}

function Convert-OwnerToSidValue {
    param([string] $Owner)

    if ($Owner -match '^S-\d-') {
        return $Owner
    }
    try {
        return ([System.Security.Principal.NTAccount]::new($Owner)).Translate([System.Security.Principal.SecurityIdentifier]).Value
    } catch {
        return $Owner
    }
}

function Assert-ForkPressProtectedAcl {
    param([string] $Path)

    $allowed = @('S-1-5-18', 'S-1-5-32-544')
    $acl = Get-Acl -LiteralPath $Path
    $ownerSid = Convert-OwnerToSidValue -Owner $acl.Owner
    if ($allowed -notcontains $ownerSid) {
        throw "Protected ForkPress path has unexpected owner ${ownerSid}: $Path"
    }
    foreach ($rule in @($acl.Access)) {
        $sid = ConvertTo-SidValue -Identity $rule.IdentityReference
        if ($allowed -notcontains $sid -or
            $rule.AccessControlType -ne [System.Security.AccessControl.AccessControlType]::Allow -or
            (($rule.FileSystemRights -band [System.Security.AccessControl.FileSystemRights]::FullControl) -ne [System.Security.AccessControl.FileSystemRights]::FullControl)) {
            throw "Protected ForkPress path has unexpected ACL entry $($rule.IdentityReference): $Path"
        }
    }
}

function Test-ForkPressProtectedAcl {
    param([string] $Path)

    try {
        Assert-ForkPressProtectedAcl -Path $Path
        return $true
    } catch {
        return $false
    }
}

function Assert-AutoMountVhdPathIsProtected {
    param([string] $VhdPath)

    $protectedRoot = [System.IO.Path]::GetFullPath((Join-Path $env:ProgramData 'ForkPress'))
    $protectedRootPrefix = $protectedRoot.TrimEnd('\') + '\'
    $fullVhdPath = [System.IO.Path]::GetFullPath($VhdPath)
    if (-not ($fullVhdPath.StartsWith($protectedRootPrefix, [StringComparison]::OrdinalIgnoreCase))) {
        throw "Persistent auto-mount requires the VHDX to live under protected storage: $protectedRoot"
    }
}

function Grant-ForkPressDevDriveAccess {
    param(
        [string] $MountPath,
        [string] $UserId
    )

    Invoke-CheckedNativeCommand -FilePath 'icacls.exe' -Arguments @(
        $MountPath,
        '/grant',
        "${UserId}:(OI)(CI)F"
    ) | Out-Null
}

function Wait-DiskImageDisk {
    param([string] $ImagePath)

    $deadline = (Get-Date).AddSeconds(15)
    do {
        $disk = Get-DiskImage -ImagePath $ImagePath | Get-Disk -ErrorAction SilentlyContinue
        if ($disk) {
            return $disk
        }
        Start-Sleep -Milliseconds 250
    } while ((Get-Date) -lt $deadline)

    throw "Timed out waiting for attached VHDX disk: $ImagePath"
}

function Test-ForkPressVhdMountedAtPath {
    param(
        [string] $VhdPath,
        [string] $MountPath
    )

    try {
        $image = Get-DiskImage -ImagePath $VhdPath -ErrorAction Stop
        if (-not $image.Attached) {
            return $false
        }
        $disk = $image | Get-Disk -ErrorAction Stop
        $paths = Get-Partition -DiskNumber $disk.Number |
            ForEach-Object { $_.AccessPaths } |
            Where-Object { $_ }
        return $paths -contains $MountPath
    } catch {
        return $false
    }
}

function Get-ForkPressMinimumMemoryBytes {
    # Microsoft documents Dev Drive as requiring 8 GB. Use decimal GB so normal
    # 8 GB laptops are not rejected because Windows reports slightly under 8 GiB.
    return [UInt64]8000000000
}

function Format-ForkPressDecimalGB {
    param([UInt64] $Bytes)

    return ('{0:N1} GB' -f ($Bytes / 1000000000))
}

function New-ForkPressPreflightResult {
    param(
        [string] $Name,
        [bool] $Passed,
        [string] $Details
    )

    [pscustomobject]@{
        Name = $Name
        Passed = $Passed
        Details = $Details
    }
}

function Write-ForkPressPreflightResult {
    param([object] $Result)

    if ($Result.Passed) {
        Write-Host ("  [OK]   {0}: {1}" -f $Result.Name, $Result.Details) -ForegroundColor Green
    } else {
        Write-Host ("  [FAIL] {0}: {1}" -f $Result.Name, $Result.Details) -ForegroundColor Red
    }
}

function Invoke-ForkPressDevDrivePreflight {
    param(
        [string] $VhdPath,
        [string] $MountPath,
        [UInt32] $SizeGB,
        [switch] $AttachOnly,
        [switch] $SkipAutoMount,
        [switch] $AllowPlainReFS
    )

    Write-Step 'Running Windows prerequisite checks'
    $results = @()

    $isAdmin = Test-Administrator
    $results += New-ForkPressPreflightResult `
        -Name 'Administrator' `
        -Passed $isAdmin `
        -Details $(if ($isAdmin) { 'setup is running elevated' } else { 'right-click ForkPressSetup.exe and choose Run as administrator' })

    $sizeOk = $AttachOnly -or $SizeGB -ge 50
    $results += New-ForkPressPreflightResult `
        -Name 'Dev Drive size' `
        -Passed $sizeOk `
        -Details $(if ($sizeOk) { "$SizeGB GB VHDX maximum requested" } else { 'Dev Drive volumes must be at least 50 GB' })

    if (-not $SkipAutoMount) {
        try {
            Assert-AutoMountVhdPathIsProtected -VhdPath $VhdPath
            $results += New-ForkPressPreflightResult `
                -Name 'Protected storage path' `
                -Passed $true `
                -Details "VHDX will live under $env:ProgramData\ForkPress"
        } catch {
            $results += New-ForkPressPreflightResult `
                -Name 'Protected storage path' `
                -Passed $false `
                -Details $_.Exception.Message
        }
    }

    if (-not $AllowPlainReFS) {
        try {
            $formatVolume = Get-Command Format-Volume -ErrorAction Stop
            $hasDevDrive = $formatVolume.Parameters.ContainsKey('DevDrive')
            $results += New-ForkPressPreflightResult `
                -Name 'Dev Drive PowerShell support' `
                -Passed $hasDevDrive `
                -Details $(if ($hasDevDrive) { 'Format-Volume supports -DevDrive' } else { 'update Windows 11, reboot, then run ForkPress Setup again' })
        } catch {
            $results += New-ForkPressPreflightResult `
                -Name 'Dev Drive PowerShell support' `
                -Passed $false `
                -Details 'Format-Volume is unavailable; update Windows 11, reboot, then run ForkPress Setup again'
        }

        try {
            $os = Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion'
            $build = [int] $os.CurrentBuildNumber
            $ubr = if ($os.UBR -ne $null) { [int] $os.UBR } else { 0 }
            $buildOk = $build -gt 22621 -or ($build -eq 22621 -and $ubr -ge 2338)
            $results += New-ForkPressPreflightResult `
                -Name 'Windows build' `
                -Passed $buildOk `
                -Details $(if ($buildOk) { "current build is $build.$ubr" } else { "needs Windows 11 build 22621.2338 or newer; current build is $build.$ubr" })
        } catch {
            $results += New-ForkPressPreflightResult `
                -Name 'Windows build' `
                -Passed $false `
                -Details "could not read Windows build: $($_.Exception.Message)"
        }
    }

    if (-not $AttachOnly) {
        try {
            $memory = Get-CimInstance Win32_ComputerSystem
            $totalMemory = [UInt64] $memory.TotalPhysicalMemory
            $minimumMemory = Get-ForkPressMinimumMemoryBytes
            $memoryOk = $totalMemory -ge $minimumMemory
            $results += New-ForkPressPreflightResult `
                -Name 'Memory' `
                -Passed $memoryOk `
                -Details $(if ($memoryOk) { "$(Format-ForkPressDecimalGB $totalMemory) detected; 8.0 GB minimum" } else { "$(Format-ForkPressDecimalGB $totalMemory) detected; ForkPress Dev Drive setup needs at least 8.0 GB" })
        } catch {
            $results += New-ForkPressPreflightResult `
                -Name 'Memory' `
                -Passed $false `
                -Details "could not read installed memory: $($_.Exception.Message)"
        }

        try {
            $hostDriveName = [System.IO.Path]::GetPathRoot($VhdPath).Substring(0, 1)
            $hostDrive = Get-PSDrive -Name $hostDriveName -ErrorAction Stop
            $freeOk = $hostDrive.Free -ge 50GB
            $results += New-ForkPressPreflightResult `
                -Name 'Free disk space' `
                -Passed $freeOk `
                -Details $(if ($freeOk) { "$(Format-ForkPressDecimalGB ([UInt64] $hostDrive.Free)) free on $($hostDrive.Name):; 50 GB minimum" } else { "$(Format-ForkPressDecimalGB ([UInt64] $hostDrive.Free)) free on $($hostDrive.Name):; ForkPress needs at least 50 GB" })
        } catch {
            $results += New-ForkPressPreflightResult `
                -Name 'Free disk space' `
                -Passed $false `
                -Details "could not inspect VHDX host drive: $($_.Exception.Message)"
        }
    }

    try {
        if (Test-Path -LiteralPath $MountPath) {
            $existing = Get-ChildItem -LiteralPath $MountPath -Force -ErrorAction SilentlyContinue | Select-Object -First 1
            $mountOk = -not $existing -or (Test-ForkPressVhdMountedAtPath -VhdPath $VhdPath -MountPath $MountPath)
            $results += New-ForkPressPreflightResult `
                -Name 'Mount folder' `
                -Passed $mountOk `
                -Details $(if ($mountOk) { "$MountPath is ready" } else { "$MountPath is not empty and is not the ForkPress Dev Drive" })
        } else {
            $results += New-ForkPressPreflightResult `
                -Name 'Mount folder' `
                -Passed $true `
                -Details "$MountPath will be created"
        }
    } catch {
        $results += New-ForkPressPreflightResult `
            -Name 'Mount folder' `
            -Passed $false `
            -Details "could not inspect mount folder: $($_.Exception.Message)"
    }

    foreach ($result in $results) {
        Write-ForkPressPreflightResult -Result $result
    }

    $failures = @($results | Where-Object { -not $_.Passed })
    if ($failures.Count -gt 0) {
        $details = ($failures | ForEach-Object { " - $($_.Name): $($_.Details)" }) -join "`n"
        throw "ForkPress setup cannot continue because prerequisite checks failed:`n$details"
    }
}

function Register-ForkPressDevDriveAutoMount {
    param(
        [string] $VhdPath,
        [string] $MountPath,
        [string] $AutoMountUserId
    )

    Write-Step 'Registering Dev Drive auto-mount'
    $vhdLiteral = ConvertTo-PowerShellSingleQuotedString $VhdPath
    $mountLiteral = ConvertTo-PowerShellSingleQuotedString $MountPath
    $attachCommand = @"
`$ErrorActionPreference = 'Stop'
`$vhdPath = $vhdLiteral
`$mountPath = $mountLiteral
`$image = Get-DiskImage -ImagePath `$vhdPath -ErrorAction SilentlyContinue
if (-not `$image -or -not `$image.Attached) {
    Mount-DiskImage -ImagePath `$vhdPath -ErrorAction Stop | Out-Null
}
`$disk = Get-DiskImage -ImagePath `$vhdPath | Get-Disk -ErrorAction Stop
`$partition = Get-Partition -DiskNumber `$disk.Number | Where-Object { `$_.Type -ne 'Reserved' } | Select-Object -First 1
if (`$partition -and @(`$partition.AccessPaths) -notcontains `$mountPath) {
    Add-PartitionAccessPath -DiskNumber `$disk.Number -PartitionNumber `$partition.PartitionNumber -AccessPath `$mountPath
}
"@
    $encodedCommand = ConvertTo-PowerShellEncodedCommand $attachCommand
    $powerShellPath = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
    $action = New-ScheduledTaskAction -Execute $powerShellPath -Argument "-NoProfile -ExecutionPolicy Bypass -EncodedCommand $encodedCommand"
    $trigger = New-ScheduledTaskTrigger -AtLogOn
    if ([string]::IsNullOrWhiteSpace($AutoMountUserId)) {
        $AutoMountUserId = [Security.Principal.WindowsIdentity]::GetCurrent().Name
    }
    $principal = New-ScheduledTaskPrincipal -UserId $AutoMountUserId -LogonType Interactive -RunLevel Highest
    Register-ScheduledTask `
        -TaskName 'ForkPress Attach Dev Drive' `
        -Action $action `
        -Trigger $trigger `
        -Principal $principal `
        -Description 'Attach the ForkPress Dev Drive VHDX at user logon.' `
        -Force | Out-Null
}

$transcriptStarted = $false
$setupExitCode = 0
try {
    if ($LogPath) {
        New-Item -ItemType Directory -Force -Path (Split-Path -Parent $LogPath) | Out-Null
        Start-Transcript -Path $LogPath -Append | Out-Null
        $transcriptStarted = $true
    }

    $VhdPath = [System.IO.Path]::GetFullPath($VhdPath)
    $MountPath = [System.IO.Path]::GetFullPath($MountPath)
    if (-not $MountPath.EndsWith('\')) {
        $MountPath = "$MountPath\"
    }

    Invoke-ForkPressDevDrivePreflight `
        -VhdPath $VhdPath `
        -MountPath $MountPath `
        -SizeGB $SizeGB `
        -AttachOnly:$AttachOnly `
        -SkipAutoMount:$SkipAutoMount `
        -AllowPlainReFS:$AllowPlainReFS

    if ($PreflightOnly) {
        Write-Host ''
        Write-Host 'ForkPress prerequisite checks passed.'
        return
    }

    if (-not (Test-Administrator)) {
        throw 'ForkPress Dev Drive setup must run from an elevated PowerShell session. Use ForkPressSetup.exe for the guided installer flow.'
    }

    if (-not $AttachOnly -and $SizeGB -lt 50) {
        throw 'Dev Drive volumes must be at least 50 GB.'
    }

    if (-not $SkipAutoMount) {
        Assert-AutoMountVhdPathIsProtected -VhdPath $VhdPath
    }

    if (-not $AllowPlainReFS) {
        $formatVolume = Get-Command Format-Volume -ErrorAction Stop
        if (-not $formatVolume.Parameters.ContainsKey('DevDrive')) {
            throw 'This Windows build does not expose Format-Volume -DevDrive. Update Windows 11, reboot, then run ForkPress Setup again.'
        }

        $os = Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion'
        $build = [int] $os.CurrentBuildNumber
        $ubr = if ($os.UBR -ne $null) { [int] $os.UBR } else { 0 }
        if ($build -lt 22621 -or ($build -eq 22621 -and $ubr -lt 2338)) {
            throw "ForkPress Dev Drive setup needs Windows 11 build 22621.2338 or newer. Current build is $build.$ubr."
        }
    }

    if (-not $AttachOnly) {
        $memory = Get-CimInstance Win32_ComputerSystem
        if ([UInt64] $memory.TotalPhysicalMemory -lt (Get-ForkPressMinimumMemoryBytes)) {
            throw 'ForkPress Dev Drive setup needs at least 8 GB of RAM.'
        }

        $hostDrive = Get-PSDrive -Name ([System.IO.Path]::GetPathRoot($VhdPath).Substring(0, 1))
        if ($hostDrive.Free -lt 50GB) {
            throw "ForkPress Dev Drive setup needs at least 50 GB free on $($hostDrive.Name):."
        }
    }

    Protect-ForkPressVhdPath -VhdPath $VhdPath
    if (Test-Path -LiteralPath $MountPath) {
        $existing = Get-ChildItem -LiteralPath $MountPath -Force -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($existing -and -not (Test-ForkPressVhdMountedAtPath -VhdPath $VhdPath -MountPath $MountPath)) {
            throw "Mount path $MountPath is not empty and is not the ForkPress Dev Drive. Choose an empty folder or remove its contents."
        }
    } else {
        New-Item -ItemType Directory -Force -Path $MountPath | Out-Null
    }

    if (-not $AllowPlainReFS) {
        Write-Step 'Enabling Windows Dev Drive support'
        Invoke-CheckedNativeCommand -FilePath 'fsutil.exe' -Arguments @('devdrv', 'enable', '/allowAv') | Out-Null
    }

    if (-not (Test-Path -LiteralPath $VhdPath)) {
        if ($AttachOnly) {
            throw "No ForkPress VHDX exists at $VhdPath."
        }
        Write-Step 'Creating dynamic VHDX'
        $sizeMB = [UInt64] $SizeGB * 1024
        Invoke-DiskPartScript @"
create vdisk file="$VhdPath" maximum=$sizeMB type=expandable
"@
        Set-ForkPressProtectedAcl -Path $VhdPath
    }

    Write-Step 'Attaching VHDX'
    $image = Get-DiskImage -ImagePath $VhdPath -ErrorAction SilentlyContinue
    if (-not $image -or -not $image.Attached) {
        Mount-DiskImage -ImagePath $VhdPath -ErrorAction Stop | Out-Null
    }

    $disk = Wait-DiskImageDisk $VhdPath
    if ($disk.IsOffline) {
        Set-Disk -Number $disk.Number -IsOffline $false
    }
    if ($disk.IsReadOnly) {
        Set-Disk -Number $disk.Number -IsReadOnly $false
    }

    if ($disk.PartitionStyle -eq 'RAW') {
        if ($AttachOnly) {
            throw "The ForkPress VHDX exists but is not initialized: $VhdPath"
        }
        Write-Step 'Initializing VHDX'
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
        if ($AttachOnly) {
            throw "The ForkPress VHDX partition exists but is not formatted: $VhdPath"
        }
        Write-Step 'Formatting as Dev Drive'
        $formatParams = @{
            Partition = $partition
            FileSystem = 'ReFS'
            NewFileSystemLabel = 'ForkPress'
            Confirm = $false
        }
        if (-not $AllowPlainReFS) {
            $formatParams['DevDrive'] = $true
        }
        Format-Volume @formatParams | Out-Null
    } elseif ($volume.FileSystem -ne 'ReFS') {
        throw "Existing ForkPress VHDX is formatted as $($volume.FileSystem), not ReFS. Remove $VhdPath and run setup again."
    }

    $partition = Get-Partition -DiskNumber $disk.Number -PartitionNumber $partition.PartitionNumber
    $paths = @($partition.AccessPaths)
    if ($paths -notcontains $MountPath) {
        Write-Step 'Mounting Dev Drive folder'
        Add-PartitionAccessPath `
            -DiskNumber $disk.Number `
            -PartitionNumber $partition.PartitionNumber `
            -AccessPath $MountPath
    }

    if (-not $AllowPlainReFS) {
        Write-Step 'Trusting Dev Drive'
        Invoke-CheckedNativeCommand -FilePath 'fsutil.exe' -Arguments @('devdrv', 'trust', '/f', $MountPath) | Out-Null
        Assert-ForkPressTrustedDevDrive -MountPath $MountPath
    }

    if ([string]::IsNullOrWhiteSpace($AutoMountUserId)) {
        $AutoMountUserId = [Security.Principal.WindowsIdentity]::GetCurrent().Name
    }
    Write-Step 'Granting user access to Dev Drive'
    Grant-ForkPressDevDriveAccess -MountPath $MountPath -UserId $AutoMountUserId

    if (-not $SkipAutoMount) {
        Register-ForkPressDevDriveAutoMount -VhdPath $VhdPath -MountPath $MountPath -AutoMountUserId $AutoMountUserId
    }

    Write-Host ''
    Write-Host 'ForkPress Dev Drive is ready.'
    Write-Host "  VHDX:  $VhdPath"
    Write-Host "  Mount: $MountPath"
    Write-Host ''
} catch {
    $message = "$_"
    if ($_.Exception -and $_.Exception.Message) {
        $message = $_.Exception.Message
    }

    Write-Host ''
    Write-Host 'ForkPress Dev Drive setup cannot continue.' -ForegroundColor Red
    Write-Host ''
    Write-Host $message -ForegroundColor Red
    if ($LogPath) {
        Write-Host ''
        Write-Host "Log file: $LogPath"
    }
    $setupExitCode = 1
} finally {
    if ($transcriptStarted) {
        Stop-Transcript | Out-Null
    }
}

if ($setupExitCode -ne 0) {
    exit $setupExitCode
}
