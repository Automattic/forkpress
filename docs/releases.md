# Releases

ForkPress releases are driven by GitHub Actions. The release flow separates
metadata preparation, pre-merge verification, and publish-time artifact
creation.

## Release flow

1. Start a release with `Release: prepare` or the local `release:prepare`
   script.
2. Review and merge the generated release PR.
3. `Release: publish` builds the final artifacts from the merge commit, creates
   the tag and GitHub release, and updates the Homebrew formula for stable
   releases.

Tag pushes do not publish ForkPress releases. The publish workflow creates the
release tag after the release PR is merged.

## Prepare

Run the `Release: prepare` workflow from GitHub Actions and pass the release
version, for example:

```text
0.1.15
```

The workflow updates crate metadata, `Cargo.lock`, and Windows installer
metadata, then opens a release PR named `Release vX.Y.Z`.

The same operation can be run locally from a clean `trunk` checkout:

```bash
npm run release:prepare -- 0.1.15
```

The local command requires `gh` authentication and pushes the release branch.

## Verify

Opening or updating a `release/vX.Y.Z` PR runs `Release: verify`.

`Release: verify` checks the release metadata and tag state, builds every
release target, packages the artifacts, uploads workflow artifacts, and
smoke-tests the packages. It does not create tags, GitHub releases, or Homebrew
updates.

The release targets are:

| Platform | Target |
| --- | --- |
| macOS Apple silicon | `aarch64-apple-darwin` |
| macOS Intel | `x86_64-apple-darwin` |
| Linux ARM64 | `aarch64-unknown-linux-musl` |
| Linux x86_64 | `x86_64-unknown-linux-musl` |
| Windows x86_64 | `x86_64-pc-windows-msvc` |

## Publish

Merging a release PR runs `Release: publish`.

The publish workflow checks out the PR merge commit, resolves it to an exact
SHA, validates release metadata, rejects an already-existing tag, rebuilds the
release matrix, and creates:

- the annotated `vX.Y.Z` tag;
- the GitHub release;
- macOS, Linux, and Windows release artifacts;
- `SHA256SUMS`;
- the Homebrew formula update for stable releases.

Prereleases create a GitHub prerelease and skip Homebrew.

Publish intentionally rebuilds the artifacts after merge instead of reusing the
pre-merge verify artifacts. That means release PRs build once for verification
and again for the final release, but the published binaries come from the exact
commit that is tagged.

## Manual publish recovery

Use manual publish only when the release metadata has already landed on `trunk`
but publishing did not complete.

```bash
gh workflow run "Release: publish" \
  --repo Automattic/forkpress \
  --ref trunk \
  -f release_ref=<commit-or-ref>
```

`release_ref` is the commit or ref to publish. The workflow checks it out,
resolves it to an exact SHA, verifies that it is reachable from `origin/trunk`,
derives the version and tag from release metadata at that ref, and refuses to
continue if the derived tag already exists.

Prefer an exact release PR merge commit SHA for recovery.

The `--ref trunk` part selects the workflow definition to run. The
`release_ref` input selects what commit is released.

## Required secrets

`RELEASE_PREPARE_TOKEN` is optional but recommended. When present, it is used by
`Release: prepare` to push the release branch and create the PR. Without it, the
workflow falls back to `GITHUB_TOKEN`, but PR-triggered checks may require manual
intervention.

`HOMEBREW_TAP_TOKEN` is required for stable publish runs because stable releases
update `Automattic/homebrew-tap`.

`BUILDKITE_API_TOKEN` is required for publish runs. The publish workflow waits
for the passed Buildkite build for the exact release commit and downloads these
signed artifacts before packaging the GitHub release assets:

- `aarch64-apple-darwin`: signed and notarized `forkpress`
- `x86_64-pc-windows-msvc`: signed `forkpress.exe`, which GitHub Actions wraps
  into the release zip and installer

Linux and `x86_64-apple-darwin` release targets are still built in GitHub
Actions until their Buildkite artifacts are release-grade. The current
Buildkite macOS x86_64 job runs on Apple Silicon with an empty runtime as a
cross-build smoke check because there is no Intel mac queue.

Windows signing is currently optional:

| Secret | Purpose |
| --- | --- |
| `AZURE_TENANT_ID` | Azure tenant for Trusted Signing. |
| `AZURE_CLIENT_ID` | Azure client used by Trusted Signing. |
| `AZURE_CLIENT_SECRET` | Azure client secret used by Trusted Signing. |
| `AZURE_ENDPOINT` | Azure Trusted Signing endpoint. |
| `AZURE_CODE_SIGNING_ACCOUNT` | Azure Trusted Signing account name. |
| `AZURE_CERTIFICATE_PROFILE` | Azure certificate profile name. |

When the Azure signing secrets are present, the workflow signs `forkpress.exe`
and `ForkPressSetup.exe`. When they are missing, the workflow warns and
publishes unsigned Windows artifacts.

Production credential and code-signing follow-up is tracked in
[issue #59](https://github.com/Automattic/forkpress/issues/59).
