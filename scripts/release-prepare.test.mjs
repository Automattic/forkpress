import assert from 'node:assert/strict';
import test from 'node:test';

import {
	changedFilesFromStatus,
	formatChangelogItem,
	releasePullRequestBody,
	requireChangedReleaseFiles,
} from './release-prepare.mjs';
import {
	ReleaseMetadataError,
	cargoLock,
	installerManifest,
	productionCrateManifests,
} from './release-metadata.mjs';

test('parses changed files from git status porcelain output', () => {
	assert.deepEqual(
		[...changedFilesFromStatus(' M Cargo.lock\nM  crates/forkpress-cli/Cargo.toml\n')],
		['Cargo.lock', 'crates/forkpress-cli/Cargo.toml'],
	);
});

test('requires release metadata and Cargo.lock to be dirty', () => {
	const expectedFiles = [...productionCrateManifests, installerManifest, cargoLock];
	const status = expectedFiles.map((path) => ` M ${path}`).join('\n');

	assert.doesNotThrow(() => requireChangedReleaseFiles(expectedFiles, status));
});

test('rejects release prepare when an edited metadata file is missing from git status', () => {
	const [missingFile, ...changedFiles] = productionCrateManifests;
	const status = [...changedFiles, installerManifest, cargoLock].map((path) => ` M ${path}`).join('\n');

	assert.throws(
		() => requireChangedReleaseFiles([missingFile, ...changedFiles, installerManifest, cargoLock], status),
		ReleaseMetadataError,
	);
});

test('formats changelog items from merge commit subjects', () => {
	assert.equal(
		formatChangelogItem('[codex] Harden merge reliability gates and semantic coverage (#46)'),
		'Harden merge reliability gates and semantic coverage ([#46](https://github.com/Automattic/forkpress/pull/46))',
	);
	assert.equal(
		formatChangelogItem('Polish branch merge UI and harden audit follow-ups'),
		'Polish branch merge UI and harden audit follow-ups',
	);
});

test('renders release pull request body with changelog and next steps', () => {
	const body = releasePullRequestBody({
		tag: 'v0.1.14',
		branch: 'release/v0.1.14',
		previousTag: 'v0.1.13-windows-cow.3',
		isPrerelease: false,
		changelogItems: [
			'Add Linux XFS loop storage fallback ([#41](https://github.com/Automattic/forkpress/pull/41))',
		],
	});

	assert.match(body, /^## Release `v0\.1\.14`/);
	assert.match(body, /\*\*Changelog draft:\*\*/);
	assert.match(
		body,
		/\*\*Full changelog:\*\* https:\/\/github\.com\/Automattic\/forkpress\/compare\/v0\.1\.13-windows-cow\.3\.\.\.release\/v0\.1\.14/,
	);
	assert.match(body, /2\. \*\*Push\*\* any additional edits to this branch \(`release\/v0\.1\.14`\)\./);
	assert.match(body, /3\. \*\*Merge\*\* this pull request to publish `v0\.1\.14`\./);
	assert.match(
		body,
		/Merging will automatically build ForkPress binaries, create a GitHub release, and update the Homebrew formula\./,
	);
	assert.doesNotMatch(body, /Do not create or push/);
});

test('renders prerelease pull request body without Homebrew publishing', () => {
	const body = releasePullRequestBody({
		tag: 'v0.1.14-rc.1',
		branch: 'release/v0.1.14-rc.1',
		previousTag: 'v0.1.13',
		isPrerelease: true,
		changelogItems: [],
	});

	assert.match(
		body,
		/Merging will automatically build ForkPress binaries and create a GitHub prerelease\. Homebrew is skipped for prereleases\./,
	);
	assert.doesNotMatch(body, /Update the Homebrew formula/);
});
