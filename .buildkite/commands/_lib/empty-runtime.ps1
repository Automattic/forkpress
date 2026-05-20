# Dot-source from a Windows BK PowerShell script to expose
# `Use-EmptyRuntimeBundle`.
#
# Usage:
#   . "$PSScriptRoot\_lib\empty-runtime.ps1"
#   Use-EmptyRuntimeBundle {
#       cargo build --release --target $TARGET -p forkpress-cli --bin forkpress --locked
#   }
#
# Writes an empty `empty-runtime.tar.gz` next to the caller's cwd and
# points `FORKPRESS_RUNTIME_BUNDLE` at it for the duration of the
# scriptblock. forkpress-cli's `build.rs` reads that env var, sees the
# empty file, and skips embedding a static PHP runtime — Windows
# runners can't build static PHP, and release-publish embeds the
# prebuilt bundle separately, so this lets cargo test/build with
# "external runtime" semantics in CI.

function Use-EmptyRuntimeBundle {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory)][scriptblock]$Action
    )
    $bundle = Join-Path $pwd 'empty-runtime.tar.gz'
    $previous = $env:FORKPRESS_RUNTIME_BUNDLE
    Set-Content -Path $bundle -Value '' -NoNewline
    $env:FORKPRESS_RUNTIME_BUNDLE = $bundle
    try {
        & $Action
    }
    finally {
        Remove-Item -LiteralPath $bundle -Force -ErrorAction SilentlyContinue
        $env:FORKPRESS_RUNTIME_BUNDLE = $previous
    }
}
