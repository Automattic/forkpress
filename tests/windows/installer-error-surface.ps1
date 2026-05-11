$ErrorActionPreference = 'Stop'

$repoRoot = Resolve-Path (Join-Path $PSScriptRoot '..\..')

function Assert-ScriptParses {
    param([string] $Path)

    $tokens = $null
    $errors = $null
    [System.Management.Automation.Language.Parser]::ParseFile($Path, [ref] $tokens, [ref] $errors) | Out-Null
    if ($errors.Count -gt 0) {
        $details = ($errors | ForEach-Object { "$($_.Extent.StartLineNumber): $($_.Message)" }) -join "`n"
        throw "$Path has PowerShell parse errors:`n$details"
    }
}

function Assert-Contains {
    param(
        [string] $Text,
        [string] $Pattern,
        [string] $Message
    )

    if ($Text -notmatch $Pattern) {
        throw $Message
    }
}

$installPath = Join-Path $repoRoot 'scripts\windows\install.ps1'
$setupPath = Join-Path $repoRoot 'scripts\windows\setup-dev-drive.ps1'
$releasePath = Join-Path $repoRoot '.github\workflows\release.yml'

Assert-ScriptParses -Path $installPath
Assert-ScriptParses -Path $setupPath

$install = Get-Content -Raw -LiteralPath $installPath
$setup = Get-Content -Raw -LiteralPath $setupPath
$release = Get-Content -Raw -LiteralPath $releasePath

Assert-Contains $install '\[switch\]\s+\$NoPauseOnError' 'install.ps1 must keep a CI-safe no-pause switch.'
Assert-Contains $install 'trap\s*\{' 'install.ps1 must have a top-level trap so installer errors stay visible.'
Assert-Contains $install 'Read-Host ''Press Enter to close this ForkPress setup window''' 'install.ps1 must pause after visible installer failures.'
Assert-Contains $install 'Start-Transcript' 'install.ps1 must write a useful installer transcript.'
Assert-Contains $install '''-PreflightOnly''' 'install.ps1 must run storage preflight before mutating Dev Drive storage.'

Assert-Contains $setup '\[switch\]\s+\$PreflightOnly' 'setup-dev-drive.ps1 must expose preflight-only mode.'
Assert-Contains $setup 'Invoke-ForkPressDevDrivePreflight' 'setup-dev-drive.ps1 must run prerequisite checks before storage mutation.'
Assert-Contains $setup 'Get-ForkPressMinimumMemoryBytes' 'setup-dev-drive.ps1 must centralize the Dev Drive RAM threshold.'
Assert-Contains $setup '8000000000' 'Dev Drive RAM threshold should use decimal 8 GB, not 8 GiB.'
Assert-Contains $setup 'ForkPress Dev Drive setup cannot continue' 'setup-dev-drive.ps1 must print a visible red fatal error.'

Assert-Contains $release '-NoPauseOnError' 'release smoke tests must pass -NoPauseOnError so CI cannot hang on a visible error prompt.'

Write-Host 'Windows installer error-surface checks passed.'
