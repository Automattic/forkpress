#!/usr/bin/env node
import { execFileSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

import {
	ReleaseMetadataError,
	assertReleaseVersion,
	cargoLock,
	installerManifest,
	isPrereleaseVersion,
	productionCrateManifests,
	releaseBranchForVersion,
	tagForVersion,
	updateReleaseMetadata,
	validateReleaseMetadata,
} from './release-metadata.mjs';

const scriptDir = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(scriptDir, '..');

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
	main(process.argv.slice(2));
}

export function main(argv) {
	try {
		const version = parseArgs(argv);
		const tag = tagForVersion(version);
		const branch = releaseBranchForVersion(version);

		requireCleanWorktree();
		requireCommand('gh');
		fetchReleaseBase();
		requireRefMissing(branch, tag);
		const releasePullRequest = releasePullRequestFor(tag, branch);

		run('git', ['switch', '--create', branch, 'origin/trunk']);
		const changed = updateReleaseMetadata(repoRoot, version);
		run('cargo', ['update', '--workspace']);
		validateReleaseMetadata(repoRoot, { version, releaseBranch: branch });
		requireExpectedChanges(changed);

		run('git', ['add', ...productionCrateManifests, installerManifest, cargoLock]);
		run('git', [
			'commit',
			'-m',
			`Prepare release ${tag}`,
			'-m',
			`Update ForkPress release metadata for ${tag}.`,
		]);
		run('git', ['push', '--set-upstream', 'origin', branch]);
		run('gh', [
			'pr',
			'create',
			'--base',
			'trunk',
			'--head',
			branch,
			'--title',
			releasePullRequest.title,
			'--body',
			releasePullRequest.body,
		]);
		console.log(`Opened release PR for ${tag}.`);
	} catch (error) {
		if (error instanceof ReleaseMetadataError) {
			console.error(error.message);
			process.exit(1);
		}
		throw error;
	}
}

function parseArgs(argv) {
	if (argv.length !== 1 || argv[0] === '--help') {
		printUsage();
		process.exit(argv[0] === '--help' ? 0 : 1);
	}
	return assertReleaseVersion(argv[0]);
}

function releasePullRequestFor(tag, branch) {
	const previousTag = latestReleaseTag(tag);
	return {
		title: `Release ${tag}`,
		body: releasePullRequestBody({
			tag,
			branch,
			previousTag,
			isPrerelease: isPrereleaseVersion(tag.slice(1)),
			changelogItems: changelogItemsSince(previousTag),
		}),
	};
}

function latestReleaseTag(tag) {
	return (
		runOutput('git', ['tag', '--merged', 'origin/trunk', '--list', 'v[0-9]*', '--sort=-v:refname'])
			.split('\n')
			.map((line) => line.trim())
			.find((candidate) => candidate && candidate !== tag) ?? null
	);
}

function changelogItemsSince(previousTag) {
	if (!previousTag) {
		return [];
	}

	return runOutput('git', ['log', '--reverse', '--first-parent', '--pretty=%s', `${previousTag}..origin/trunk`])
		.trim()
		.split('\n')
		.map(formatChangelogItem)
		.filter(Boolean);
}

export function releasePullRequestBody({ tag, branch, previousTag, isPrerelease = false, changelogItems }) {
	const fullChangelogUrl = previousTag
		? `https://github.com/Automattic/forkpress/compare/${previousTag}...${branch}`
		: `https://github.com/Automattic/forkpress/commits/${branch}`;
	const publishSummary = isPrerelease
		? 'Merging will automatically build ForkPress binaries and create a GitHub prerelease. Homebrew is skipped for prereleases.'
		: 'Merging will automatically build ForkPress binaries, create a GitHub release, and update the Homebrew formula.';

	return `## Release \`${tag}\`

Version bump and release metadata update for \`${tag}\`.

**Changelog draft:**
${renderChangelogDraft(changelogItems)}

**Full changelog:** ${fullChangelogUrl}

## Next steps

1. **Review** the changes in this pull request.
2. **Push** any additional edits to this branch (\`${branch}\`).
3. **Merge** this pull request to publish \`${tag}\`.

${publishSummary}`;
}

function renderChangelogDraft(changelogItems) {
	if (changelogItems.length === 0) {
		return '* No merged changes detected since the previous release.';
	}
	return changelogItems.map((item) => `* ${item}`).join('\n');
}

export function formatChangelogItem(subject) {
	return subject
		.trim()
		.replace(/^\[codex\]\s*/i, '')
		.replace(
			/\s+\(#(\d+)\)$/,
			(_, pullRequestNumber) =>
				` ([#${pullRequestNumber}](https://github.com/Automattic/forkpress/pull/${pullRequestNumber}))`,
		);
}

function printUsage() {
	console.log(`Usage: node scripts/release-prepare.mjs <version>

Example:
  node scripts/release-prepare.mjs 0.1.13
`);
}

function requireCleanWorktree() {
	const status = runOutput('git', ['status', '--porcelain']);
	if (status.trim() !== '') {
		throw new ReleaseMetadataError('Release prepare requires a clean worktree.');
	}
}

function requireCommand(command) {
	try {
		run(command, ['--version'], { stdio: 'ignore' });
	} catch {
		throw new ReleaseMetadataError(`Required command is not available: ${command}`);
	}
}

function fetchReleaseBase() {
	run('git', ['fetch', '--prune', 'origin', 'trunk:refs/remotes/origin/trunk']);
	run('git', ['fetch', '--tags', 'origin']);
}

function requireRefMissing(branch, tag) {
	if (gitRefExists(`refs/heads/${branch}`)) {
		throw new ReleaseMetadataError(`Local branch already exists: ${branch}`);
	}
	if (remoteRefExists(['--heads', 'origin', branch])) {
		throw new ReleaseMetadataError(`Remote branch already exists: ${branch}`);
	}
	if (gitRefExists(`refs/tags/${tag}`)) {
		throw new ReleaseMetadataError(`Local tag already exists: ${tag}`);
	}
	if (remoteRefExists(['--tags', 'origin', tag])) {
		throw new ReleaseMetadataError(`Remote tag already exists: ${tag}`);
	}
}

function gitRefExists(ref) {
	try {
		run('git', ['show-ref', '--verify', '--quiet', ref], { stdio: 'ignore' });
		return true;
	} catch {
		return false;
	}
}

function remoteRefExists(args) {
	try {
		run('git', ['ls-remote', '--exit-code', ...args], { stdio: 'ignore' });
		return true;
	} catch {
		return false;
	}
}

function requireExpectedChanges(initialChangedFiles) {
	const status = runOutput('git', ['status', '--porcelain']);
	requireChangedReleaseFiles([...initialChangedFiles, cargoLock], status);
}

export function requireChangedReleaseFiles(expectedFiles, status) {
	if (status.trim() === '') {
		throw new ReleaseMetadataError('Release metadata is already at the requested version.');
	}

	const changedFiles = changedFilesFromStatus(status);
	for (const file of expectedFiles) {
		if (!changedFiles.has(file)) {
			throw new ReleaseMetadataError(`Expected release file was not changed: ${file}`);
		}
	}
}

export function changedFilesFromStatus(status) {
	return new Set(
		status
			.split('\n')
			.map((line) => line.slice(3))
			.filter(Boolean),
	);
}

function run(command, args, options = {}) {
	return execFileSync(command, args, {
		cwd: repoRoot,
		stdio: 'inherit',
		...options,
	});
}

function runOutput(command, args) {
	return execFileSync(command, args, {
		cwd: repoRoot,
		encoding: 'utf8',
	});
}
