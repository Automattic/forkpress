import { readFileSync, writeFileSync } from 'node:fs';

export const productionCrateManifests = [
	'crates/forkpress-cli/Cargo.toml',
	'crates/forkpress-core/Cargo.toml',
	'crates/forkpress-runtime/Cargo.toml',
	'crates/forkpress-storage/Cargo.toml',
	'crates/forkpress-server/Cargo.toml',
	'crates/forkpress-git/Cargo.toml',
];

export const productionPackageNames = productionCrateManifests
	.map((path) => path.match(/crates\/([^/]+)\/Cargo\.toml$/)?.[1])
	.filter(Boolean)
	.sort();

export const installerManifest = 'installer/windows/ForkPress.iss';
export const cargoLock = 'Cargo.lock';

const cargoVersionLine = /^version = "([^"]+)"$/m;
const installerVersionLine = /^#define AppVersion "([^"]+)"$/m;
const semverPattern =
	/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*)?(?:\+[0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*)?$/;

export class ReleaseMetadataError extends Error {
	constructor(message) {
		super(message);
		this.name = 'ReleaseMetadataError';
	}
}

export function assertReleaseVersion(version) {
	if (!version || version.startsWith('v')) {
		throw new ReleaseMetadataError('Release version must be passed without a leading v.');
	}
	if (!semverPattern.test(version)) {
		throw new ReleaseMetadataError(
			`Release version must be a semantic version such as 0.1.13 or 0.2.0: ${version}`,
		);
	}
	return version;
}

export function tagForVersion(version) {
	return `v${assertReleaseVersion(version)}`;
}

export function isPrereleaseVersion(version) {
	return assertReleaseVersion(version).split('+')[0].includes('-');
}

export function releaseBranchForVersion(version) {
	return `release/${tagForVersion(version)}`;
}

export function readCargoTomlVersion(contents, path = 'Cargo.toml') {
	const match = contents.match(cargoVersionLine);
	if (!match) {
		throw new ReleaseMetadataError(`Could not find package version in ${path}.`);
	}
	return match[1];
}

export function setCargoTomlVersion(contents, version, path = 'Cargo.toml') {
	assertReleaseVersion(version);
	if (!cargoVersionLine.test(contents)) {
		throw new ReleaseMetadataError(`Could not find package version in ${path}.`);
	}
	return contents.replace(cargoVersionLine, `version = "${version}"`);
}

export function readInstallerVersion(contents, path = installerManifest) {
	const match = contents.match(installerVersionLine);
	if (!match) {
		throw new ReleaseMetadataError(`Could not find AppVersion in ${path}.`);
	}
	return match[1];
}

export function setInstallerVersion(contents, version, path = installerManifest) {
	assertReleaseVersion(version);
	if (!installerVersionLine.test(contents)) {
		throw new ReleaseMetadataError(`Could not find AppVersion in ${path}.`);
	}
	return contents.replace(installerVersionLine, `#define AppVersion "${version}"`);
}

export function readCargoLockPackageVersions(contents, packageNames) {
	const requested = new Set(packageNames);
	const versions = new Map();
	const blocks = contents.split(/\n(?=\[\[package\]\]\n)/);
	for (const block of blocks) {
		const name = block.match(/^name = "([^"]+)"$/m)?.[1];
		if (!name || !requested.has(name)) {
			continue;
		}
		const version = block.match(/^version = "([^"]+)"$/m)?.[1];
		if (!version) {
			throw new ReleaseMetadataError(`Could not find Cargo.lock version for ${name}.`);
		}
		versions.set(name, version);
	}
	return versions;
}

export function updateReleaseMetadata(repoRoot, version) {
	assertReleaseVersion(version);
	const changed = [];

	for (const path of productionCrateManifests) {
		const fullPath = `${repoRoot}/${path}`;
		const before = readFileSync(fullPath, 'utf8');
		const after = setCargoTomlVersion(before, version, path);
		if (after !== before) {
			writeFileSync(fullPath, after);
			changed.push(path);
		}
	}

	const installerPath = `${repoRoot}/${installerManifest}`;
	const installerBefore = readFileSync(installerPath, 'utf8');
	const installerAfter = setInstallerVersion(installerBefore, version, installerManifest);
	if (installerAfter !== installerBefore) {
		writeFileSync(installerPath, installerAfter);
		changed.push(installerManifest);
	}

	return changed;
}

export function validateReleaseMetadata(repoRoot, options = {}) {
	const expectedVersion = options.version ? assertReleaseVersion(options.version) : null;
	const crateVersions = new Map();

	for (const path of productionCrateManifests) {
		const version = readCargoTomlVersion(readFileSync(`${repoRoot}/${path}`, 'utf8'), path);
		crateVersions.set(path, version);
	}

	const versions = new Set(crateVersions.values());
	if (versions.size !== 1) {
		const rendered = [...crateVersions.entries()]
			.map(([path, version]) => `${path}: ${version}`)
			.join('\n');
		throw new ReleaseMetadataError(`ForkPress crate versions disagree:\n${rendered}`);
	}

	const version = [...versions][0];
	assertReleaseVersion(version);
	if (expectedVersion && version !== expectedVersion) {
		throw new ReleaseMetadataError(
			`ForkPress crate version ${version} does not match requested release ${expectedVersion}.`,
		);
	}

	const installerVersion = readInstallerVersion(
		readFileSync(`${repoRoot}/${installerManifest}`, 'utf8'),
		installerManifest,
	);
	if (installerVersion !== version) {
		throw new ReleaseMetadataError(
			`${installerManifest} AppVersion ${installerVersion} does not match crate version ${version}.`,
		);
	}

	const lockVersions = readCargoLockPackageVersions(
		readFileSync(`${repoRoot}/${cargoLock}`, 'utf8'),
		productionPackageNames,
	);
	for (const packageName of productionPackageNames) {
		const lockVersion = lockVersions.get(packageName);
		if (!lockVersion) {
			throw new ReleaseMetadataError(`Cargo.lock is missing ${packageName}.`);
		}
		if (lockVersion !== version) {
			throw new ReleaseMetadataError(
				`Cargo.lock has ${packageName} ${lockVersion}, expected ${version}.`,
			);
		}
	}

	const tag = tagForVersion(version);
	if (options.releaseBranch) {
		const expectedBranch = releaseBranchForVersion(version);
		if (options.releaseBranch !== expectedBranch) {
			throw new ReleaseMetadataError(
				`Release branch ${options.releaseBranch} does not match metadata version ${version}; expected ${expectedBranch}.`,
			);
		}
	}

	return {
		version,
		tag,
		branch: releaseBranchForVersion(version),
		isPrerelease: isPrereleaseVersion(version),
		files: [
			...productionCrateManifests,
			installerManifest,
			cargoLock,
		],
	};
}
