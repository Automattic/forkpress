import assert from 'node:assert/strict';
import test from 'node:test';

import {
	ReleaseMetadataError,
	cargoLock,
	installerManifest,
	productionCrateManifests,
} from './release-metadata.mjs';
import { changedFilesFromStatus, requireChangedReleaseFiles } from './release-prepare.mjs';

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
