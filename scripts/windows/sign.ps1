<#
.SYNOPSIS
Signs Windows artifacts with Azure Trusted Signing.

.DESCRIPTION
SHA256-only Authenticode signing via `signtool.exe /dlib /dmdf`. No PFX
fallback -- forkpress is a new app with a single signing identity, so the
script throws loudly when Azure Trusted Signing is not configured rather
than silently producing unsigned binaries.

The CI Toolkit plugin's `setup_azure_trusted_signing.ps1` materializes
`signtool.exe` + the Azure DLib and exports `SIGNTOOL_PATH`,
`AZURE_CODE_SIGNING_DLIB`, `AZURE_METADATA_JSON`. This script invokes
the setup once (per process) when those vars aren't already set, then
signs each `-Files` entry.

The `/debug` flag is always on: without it, remote auth/quota/network
failures from Azure collapse to a generic `SignTool Error`.
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string[]] $Files
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$REQUIRED_AZURE_ENV_VARS = @(
    'AZURE_TENANT_ID',
    'AZURE_CLIENT_ID',
    'AZURE_CLIENT_SECRET',
    'AZURE_ENDPOINT',
    'AZURE_CODE_SIGNING_ACCOUNT',
    'AZURE_CERTIFICATE_PROFILE'
)

$missing = $REQUIRED_AZURE_ENV_VARS | Where-Object {
    [string]::IsNullOrWhiteSpace([Environment]::GetEnvironmentVariable($_))
}
if ($missing) {
    throw "Azure Trusted Signing env vars missing: $($missing -join ', '). forkpress signs Windows artifacts via Azure only -- there is no PFX fallback."
}

if (-not $env:SIGNTOOL_PATH -or -not $env:AZURE_CODE_SIGNING_DLIB -or -not $env:AZURE_METADATA_JSON) {
    $setupScript = (Get-Command setup_azure_trusted_signing.ps1 -ErrorAction Stop).Source
    Write-Output "--- :lock: Setting up Azure Trusted Signing ($setupScript)"
    & $setupScript
    if ($LASTEXITCODE -ne 0) {
        throw "setup_azure_trusted_signing.ps1 failed: $LASTEXITCODE"
    }
}

$timestampServer = if ($env:AZURE_TIMESTAMP_SERVER) { $env:AZURE_TIMESTAMP_SERVER } else { 'http://timestamp.acs.microsoft.com' }
$fileDigest = if ($env:AZURE_FILE_DIGEST) { $env:AZURE_FILE_DIGEST } else { 'SHA256' }
$timestampDigest = if ($env:AZURE_TIMESTAMP_DIGEST) { $env:AZURE_TIMESTAMP_DIGEST } else { 'SHA256' }

foreach ($file in $Files) {
    if (-not (Test-Path -LiteralPath $file)) {
        throw "Cannot sign missing file: $file"
    }

    Write-Output "--- :lock: Signing $file"
    & $env:SIGNTOOL_PATH sign `
        /v `
        /debug `
        /fd $fileDigest `
        /tr $timestampServer `
        /td $timestampDigest `
        /dlib $env:AZURE_CODE_SIGNING_DLIB `
        /dmdf $env:AZURE_METADATA_JSON `
        $file
    if ($LASTEXITCODE -ne 0) {
        throw "signtool failed with exit code $LASTEXITCODE for $file"
    }

    $signature = Get-AuthenticodeSignature -LiteralPath $file
    if ($signature.Status -ne 'Valid') {
        throw "Authenticode signature for $file is $($signature.Status)."
    }
}
