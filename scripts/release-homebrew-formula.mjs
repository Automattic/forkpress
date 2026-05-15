#!/usr/bin/env node
import { readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

import { ReleaseMetadataError, assertReleaseVersion, tagForVersion } from './release-metadata.mjs';

export const homebrewAssets = [
	{
		condition: ['on_macos', 'on_arm'],
		name: 'forkpress-aarch64-apple-darwin.tar.gz',
	},
	{
		condition: ['on_macos', 'on_intel'],
		name: 'forkpress-x86_64-apple-darwin.tar.gz',
	},
	{
		condition: ['on_linux', 'on_arm'],
		name: 'forkpress-aarch64-unknown-linux-musl.tar.gz',
	},
	{
		condition: ['on_linux', 'on_intel'],
		name: 'forkpress-x86_64-unknown-linux-musl.tar.gz',
	},
];

if (import.meta.url === pathToFileURL(process.argv[1]).href) {
	main(process.argv.slice(2));
}

export function main(argv) {
	try {
		if (argv.length !== 2 || argv[0] === '--help') {
			printUsage();
			process.exit(argv[0] === '--help' ? 0 : 1);
		}
		const [version, sumsPath] = argv;
		const sums = parseSha256Sums(readFileSync(sumsPath, 'utf8'));
		process.stdout.write(generateHomebrewFormula(version, sums));
	} catch (error) {
		if (error instanceof ReleaseMetadataError) {
			console.error(error.message);
			process.exit(1);
		}
		throw error;
	}
}

export function parseSha256Sums(contents) {
	const sums = new Map();
	for (const line of contents.split(/\r?\n/)) {
		if (line.trim() === '') {
			continue;
		}
		const match = line.match(/^([0-9a-fA-F]{64})\s+\*?(.+)$/);
		if (!match) {
			throw new ReleaseMetadataError(`Invalid SHA256SUMS line: ${line}`);
		}
		sums.set(match[2], match[1].toLowerCase());
	}
	return sums;
}

export function generateHomebrewFormula(version, sums) {
	assertReleaseVersion(version);
	const tag = tagForVersion(version);
	const assetStanzas = groupedFormulaAssetStanzas(tag, sums);

	return `class Forkpress < Formula
  desc "Single-binary WordPress branching environment"
  homepage "https://github.com/Automattic/forkpress"
  version "${version}"
  license "GPL-2.0-only"

${assetStanzas.join('\n\n')}

  def install
    bin.install "forkpress"
  end

  test do
    assert_match version.to_s, shell_output("#{bin}/forkpress --version")
  end
end
`;
}

function groupedFormulaAssetStanzas(tag, sums) {
	const byPlatform = new Map();
	for (const asset of homebrewAssets) {
		const [platform, arch] = asset.condition;
		const sha256 = sums.get(asset.name);
		if (!sha256) {
			throw new ReleaseMetadataError(`SHA256SUMS is missing ${asset.name}.`);
		}
		if (!byPlatform.has(platform)) {
			byPlatform.set(platform, []);
		}
		byPlatform.get(platform).push(formulaAssetArchStanza(tag, arch, asset.name, sha256));
	}
	return [...byPlatform.entries()].map(
		([platform, stanzas]) => `  ${platform} do
${stanzas.join('\n\n')}
  end`,
	);
}

function formulaAssetArchStanza(tag, arch, name, sha256) {
	return `    ${arch} do
      url "https://github.com/Automattic/forkpress/releases/download/${tag}/${name}"
      sha256 "${sha256}"
    end`;
}

function printUsage() {
	console.log(`Usage: node scripts/release-homebrew-formula.mjs <version> <SHA256SUMS>

Example:
  node scripts/release-homebrew-formula.mjs 0.1.13 SHA256SUMS > forkpress.rb
`);
}
