#!/usr/bin/env node
import { execFileSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

import {
	ReleaseMetadataError,
	assertReleaseVersion,
	cargoLock,
	installerManifest,
	productionCrateManifests,
	releaseBranchForVersion,
	tagForVersion,
	updateReleaseMetadata,
	validateReleaseMetadata,
} from './release-metadata.mjs';

const scriptDir = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(scriptDir, '..');

if (import.meta.url === pathToFileURL(process.argv[1]).href) {
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
			`Prepare release ${tag}`,
			'--body',
			`Updates ForkPress release metadata for ${tag}.`,
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
	const status = runOutput('git', ['status', '--porcelain']).trim();
	if (status === '') {
		throw new ReleaseMetadataError('Release metadata is already at the requested version.');
	}

	const changedFiles = new Set(
		status
			.split('\n')
			.map((line) => line.slice(3))
			.filter(Boolean),
	);
	for (const file of [...initialChangedFiles, cargoLock]) {
		if (!changedFiles.has(file)) {
			throw new ReleaseMetadataError(`Expected release file was not changed: ${file}`);
		}
	}
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
