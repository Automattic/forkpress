#!/usr/bin/env node
import { appendFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

import { ReleaseMetadataError, validateReleaseMetadata } from './release-metadata.mjs';

const scriptDir = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(scriptDir, '..');

if (import.meta.url === pathToFileURL(process.argv[1]).href) {
	main(process.argv.slice(2));
}

export function main(argv) {
	try {
		const options = parseArgs(argv);
		const metadata = validateReleaseMetadata(repoRoot, {
			version: options.version,
			releaseBranch: options.releaseBranch,
		});
		if (options.githubOutput) {
			appendFileSync(process.env.GITHUB_OUTPUT, `version=${metadata.version}\n`);
			appendFileSync(process.env.GITHUB_OUTPUT, `tag=${metadata.tag}\n`);
			appendFileSync(process.env.GITHUB_OUTPUT, `branch=${metadata.branch}\n`);
		}
		if (options.printVersion) {
			console.log(metadata.version);
			return;
		}
		console.log(`Release metadata is valid for ${metadata.tag}.`);
	} catch (error) {
		if (error instanceof ReleaseMetadataError) {
			console.error(error.message);
			process.exit(1);
		}
		throw error;
	}
}

function parseArgs(argv) {
	const options = {
		version: null,
		releaseBranch: null,
		githubOutput: false,
		printVersion: false,
	};
	for (let index = 0; index < argv.length; index += 1) {
		const arg = argv[index];
		if (arg === '--release-branch') {
			options.releaseBranch = readValue(argv, index, arg);
			index += 1;
		} else if (arg === '--github-output') {
			if (!process.env.GITHUB_OUTPUT) {
				throw new ReleaseMetadataError('--github-output requires GITHUB_OUTPUT.');
			}
			options.githubOutput = true;
		} else if (arg === '--print-version') {
			options.printVersion = true;
		} else if (arg === '--help') {
			printUsage();
			process.exit(0);
		} else if (arg.startsWith('-')) {
			throw new ReleaseMetadataError(`Unknown option: ${arg}`);
		} else if (!options.version) {
			options.version = arg;
		} else {
			throw new ReleaseMetadataError(`Unexpected argument: ${arg}`);
		}
	}
	return options;
}

function readValue(argv, index, option) {
	const value = argv[index + 1];
	if (!value || value.startsWith('-')) {
		throw new ReleaseMetadataError(`${option} requires a value.`);
	}
	return value;
}

function printUsage() {
	console.log(`Usage: node scripts/release-validate.mjs [version] [options]

Options:
  --release-branch <branch>  Verify branch name matches release/v<version>.
  --github-output           Write version, tag, and branch to GITHUB_OUTPUT.
  --print-version           Print the validated version only.
`);
}
