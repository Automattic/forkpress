import assert from 'node:assert/strict';
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';

import {
	ReleaseMetadataError,
	assertReleaseVersion,
	cargoLock,
	installerManifest,
	isPrereleaseVersion,
	productionCrateManifests,
	productionPackageNames,
	readCargoLockPackageVersions,
	releaseBranchForVersion,
	setCargoTomlVersion,
	setInstallerVersion,
	tagForVersion,
	validateReleaseMetadata,
} from './release-metadata.mjs';

test('validates release versions and derived names', () => {
	assert.equal(assertReleaseVersion('0.1.13'), '0.1.13');
	assert.equal(tagForVersion('0.2.0'), 'v0.2.0');
	assert.equal(releaseBranchForVersion('1.0.0'), 'release/v1.0.0');
	assert.equal(isPrereleaseVersion('0.1.14'), false);
	assert.equal(isPrereleaseVersion('0.1.14-rc.1'), true);
	assert.equal(isPrereleaseVersion('0.1.14+build.1'), false);
	assert.throws(() => assertReleaseVersion('v0.1.13'), ReleaseMetadataError);
	assert.throws(() => assertReleaseVersion('1.2'), ReleaseMetadataError);
});

test('updates anchored release metadata lines', () => {
	assert.equal(
		setCargoTomlVersion('[package]\nname = "forkpress-cli"\nversion = "0.1.12"\n', '0.1.13'),
		'[package]\nname = "forkpress-cli"\nversion = "0.1.13"\n',
	);
	assert.equal(
		setInstallerVersion('#define AppName "ForkPress"\n#define AppVersion "0.1.12"\n', '0.1.13'),
		'#define AppName "ForkPress"\n#define AppVersion "0.1.13"\n',
	);
});

test('reads selected package versions from Cargo.lock', () => {
	const versions = readCargoLockPackageVersions(
		`[[package]]
name = "forkpress-cli"
version = "0.1.13"

[[package]]
name = "other"
version = "1.0.0"
`,
		['forkpress-cli'],
	);

	assert.deepEqual([...versions.entries()], [['forkpress-cli', '0.1.13']]);
});

test('validates consistent release metadata in a project fixture', (t) => {
	const project = createProject(t, '0.1.13');

	assert.deepEqual(validateReleaseMetadata(project, { version: '0.1.13' }), {
		version: '0.1.13',
		tag: 'v0.1.13',
		branch: 'release/v0.1.13',
		isPrerelease: false,
		files: [...productionCrateManifests, installerManifest, cargoLock],
	});
});

test('rejects mismatched release branch metadata', (t) => {
	const project = createProject(t, '0.1.13');

	assert.throws(
		() => validateReleaseMetadata(project, { releaseBranch: 'release/v0.2.0' }),
		/does not match metadata version/,
	);
});

function createProject(t, version) {
	const projectRoot = mkdtempSync(path.join(tmpdir(), 'forkpress-release-'));
	t.after(() => rmSync(projectRoot, { recursive: true, force: true }));

	for (const manifest of productionCrateManifests) {
		writeFixture(
			projectRoot,
			manifest,
			`[package]
name = "${manifest.match(/crates\/([^/]+)\//)[1]}"
version = "${version}"
edition = "2024"
`,
		);
	}
	writeFixture(
		projectRoot,
		installerManifest,
		`#define AppName "ForkPress"
#define AppVersion "${version}"
`,
	);
	writeFixture(
		projectRoot,
		cargoLock,
		productionPackageNames
			.map(
				(packageName) => `[[package]]
name = "${packageName}"
version = "${version}"
`,
			)
			.join('\n'),
	);

	return projectRoot;
}

function writeFixture(projectRoot, relativePath, contents) {
	const fullPath = path.join(projectRoot, relativePath);
	mkdirSync(path.dirname(fullPath), { recursive: true });
	writeFileSync(fullPath, contents);
}
