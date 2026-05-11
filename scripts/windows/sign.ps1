<#
.SYNOPSIS
Signs Windows release artifacts when a code-signing certificate is available.
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string[]] $Files,
    [string] $CertificateBase64 = $env:WINDOWS_CODESIGN_CERT_BASE64,
    [string] $CertificatePassword = $env:WINDOWS_CODESIGN_PASSWORD,
    [string] $TimestampUrl = 'http://timestamp.digicert.com'
)

$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($CertificateBase64)) {
    Write-Host 'WINDOWS_CODESIGN_CERT_BASE64 is not set; skipping Authenticode signing.'
    exit 0
}

$sdkRoot = "${env:ProgramFiles(x86)}\Windows Kits\10\bin"
$signtool = Get-ChildItem -LiteralPath $sdkRoot -Recurse -Filter signtool.exe -ErrorAction SilentlyContinue |
    Where-Object { $_.FullName -match '\\x64\\signtool\.exe$' } |
    Sort-Object FullName -Descending |
    Select-Object -First 1
if (-not $signtool) {
    throw "signtool.exe was not found under $sdkRoot"
}

$tempRoot = if ($env:RUNNER_TEMP) { $env:RUNNER_TEMP } else { $env:TEMP }
$pfx = Join-Path $tempRoot 'forkpress-codesign.pfx'
[IO.File]::WriteAllBytes($pfx, [Convert]::FromBase64String($CertificateBase64))

try {
    foreach ($file in $Files) {
        if (-not (Test-Path -LiteralPath $file)) {
            throw "Cannot sign missing file: $file"
        }
        & $signtool.FullName sign `
            /fd SHA256 `
            /td SHA256 `
            /tr $TimestampUrl `
            /f $pfx `
            /p $CertificatePassword `
            $file
        if ($LASTEXITCODE -ne 0) {
            throw "signtool failed with exit code $LASTEXITCODE for $file"
        }

        $signature = Get-AuthenticodeSignature -LiteralPath $file
        if ($signature.Status -ne 'Valid') {
            throw "Authenticode signature for $file is $($signature.Status)."
        }
    }
} finally {
    Remove-Item -Force -LiteralPath $pfx -ErrorAction SilentlyContinue
}
