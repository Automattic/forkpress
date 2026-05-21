# Surfaces the signed Windows binary that release-publish packages into the
# GitHub release zip and installer.

$ErrorActionPreference = 'Stop'

$TARGET = 'x86_64-pc-windows-msvc'

Write-Output "--- :crab: Installing Rust via rustup"
. "$PSScriptRoot\_lib\install-rust.ps1" -Target $TARGET

Write-Output "--- :package: Building Windows runtime bundle ($TARGET)"
& "$PSScriptRoot\..\..\scripts\windows\build-dist.ps1" -Target $TARGET
if ($LASTEXITCODE -ne 0) { throw "build-dist.ps1 failed: $LASTEXITCODE" }

Write-Output "--- :crab: cargo build --release forkpress.exe ($TARGET)"
cargo build --release --target $TARGET -p forkpress-cli --bin forkpress --locked
if ($LASTEXITCODE -ne 0) { throw "cargo build --release failed: $LASTEXITCODE" }

Get-Item "target/$TARGET/release/forkpress.exe" | Format-List Name, Length, LastWriteTime

$requiredSigningVars = @(
    'AZURE_TENANT_ID',
    'AZURE_CLIENT_ID',
    'AZURE_CLIENT_SECRET',
    'AZURE_ENDPOINT',
    'AZURE_CODE_SIGNING_ACCOUNT',
    'AZURE_CERTIFICATE_PROFILE'
)
$missingSigningVars = $requiredSigningVars | Where-Object {
    [string]::IsNullOrWhiteSpace([Environment]::GetEnvironmentVariable($_))
}
if ($missingSigningVars) {
    Write-Output "::warning::Azure Trusted Signing env vars are not configured; uploading unsigned Windows build artifact."
} else {
    Write-Output "--- :lock: Signing forkpress.exe ($TARGET)"
    & "$PSScriptRoot\..\..\scripts\windows\sign.ps1" -Files "target/$TARGET/release/forkpress.exe"
    if ($LASTEXITCODE -ne 0) { throw "sign.ps1 failed: $LASTEXITCODE" }
}

Write-Output "--- :test_tube: forkpress.exe smoke"
& "target/$TARGET/release/forkpress.exe" --version
if ($LASTEXITCODE -ne 0) { throw "forkpress.exe --version failed: $LASTEXITCODE" }
