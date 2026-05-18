#!/usr/bin/env node
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const defaultManifest = 'runtime/cow/plugin-compat/popular-wordpress-plugins.json';
const sourceUrl =
	'https://api.wordpress.org/plugins/info/1.2/?action=query_plugins&request[browse]=popular&request[page]=1&request[per_page]=100&request[fields][description]=0&request[fields][sections]=0&request[fields][compatibility]=0&request[fields][ratings]=0&request[fields][icons]=0&request[fields][banners]=0';

const semanticRecipes = new Map([
	['advanced-custom-fields', ['acf-field-definitions']],
	['elementor', ['elementor-widget-trees']],
	['the-events-calendar', ['events-calendar-event-graphs']],
	['woocommerce', ['woocommerce-hpos-orders']],
	['wordpress-seo', ['yoast-indexables']],
]);

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
	main(process.argv.slice(2)).catch((error) => {
		console.error(error.message);
		process.exit(1);
	});
}

async function main(argv) {
	const [command = 'validate', path = defaultManifest] = argv;
	if (command === 'refresh') {
		await refresh(path);
		return;
	}
	if (command === 'validate') {
		validate(JSON.parse(readFileSync(resolve(repoRoot, path), 'utf8')));
		console.log(`Popular plugin compatibility manifest is valid: ${path}`);
		return;
	}
	printUsage();
	process.exit(command === '--help' || command === 'help' ? 0 : 1);
}

async function refresh(path) {
	const response = await fetch(sourceUrl, {
		headers: {
			'User-Agent': 'ForkPress popular plugin compatibility manifest',
		},
	});
	if (!response.ok) {
		throw new Error(`WordPress.org plugin API returned ${response.status}`);
	}
	const payload = await response.json();
	const plugins = Array.isArray(payload.plugins) ? payload.plugins : [];
	if (plugins.length !== 100) {
		throw new Error(`Expected 100 popular plugins, got ${plugins.length}`);
	}

	const manifest = {
		schema: 1,
		source: {
			name: 'WordPress.org Plugin Directory popular browse',
			url: sourceUrl,
			retrieved_at: new Date().toISOString(),
			per_page: 100,
		},
		support_policy: {
			install: 'wp-admin-plugin-install',
			default_merge: 'generic-db-file-merge-with-plugin-validator-unchecked-audit',
			semantic_recipes: [
				'acf-field-definitions',
				'elementor-widget-trees',
				'events-calendar-event-graphs',
				'woocommerce-hpos-orders',
				'yoast-indexables',
			],
		},
		plugins: plugins.map((plugin, index) => pluginRecord(plugin, index + 1)),
	};
	validate(manifest);
	writeFileSync(resolve(repoRoot, path), `${JSON.stringify(manifest, null, 2)}\n`);
	console.log(`Wrote ${path}`);
}

function pluginRecord(plugin, rank) {
	const slug = requiredString(plugin.slug, `plugin ${rank} slug`);
	const recipes = semanticRecipes.get(slug) ?? [];
	return {
		rank,
		slug,
		name: requiredString(plugin.name, `${slug} name`),
		version: optionalString(plugin.version),
		tested: optionalString(plugin.tested),
		requires_php: optionalString(plugin.requires_php),
		active_installs: Number.isFinite(plugin.active_installs) ? plugin.active_installs : null,
		download_link: optionalString(plugin.download_link),
		install_support: 'wp-admin-plugin-install',
		merge_support: recipes.length > 0 ? 'semantic-recipe-plus-generic-audit' : 'generic-audit',
		semantic_recipes: recipes,
	};
}

function validate(manifest) {
	if (manifest.schema !== 1) {
		throw new Error('Manifest schema must be 1.');
	}
	if (!manifest.source || manifest.source.per_page !== 100 || !manifest.source.url) {
		throw new Error('Manifest source metadata must include url and per_page = 100.');
	}
	if (manifest.support_policy?.install !== 'wp-admin-plugin-install') {
		throw new Error('Manifest support policy must declare wp-admin plugin install support.');
	}
	if (manifest.support_policy?.default_merge !== 'generic-db-file-merge-with-plugin-validator-unchecked-audit') {
		throw new Error('Manifest support policy must declare the default generic merge audit behavior.');
	}
	if (!Array.isArray(manifest.plugins) || manifest.plugins.length !== 100) {
		throw new Error('Manifest must contain exactly 100 plugin records.');
	}

	const slugs = new Set();
	for (let index = 0; index < manifest.plugins.length; index += 1) {
		const plugin = manifest.plugins[index];
		const rank = index + 1;
		if (plugin.rank !== rank) {
			throw new Error(`Plugin at index ${index} must have rank ${rank}.`);
		}
		const slug = requiredString(plugin.slug, `plugin ${rank} slug`);
		if (slugs.has(slug)) {
			throw new Error(`Duplicate plugin slug: ${slug}`);
		}
		slugs.add(slug);
		requiredString(plugin.name, `${slug} name`);
		if (plugin.install_support !== 'wp-admin-plugin-install') {
			throw new Error(`${slug} must declare install support.`);
		}
		if (!['generic-audit', 'semantic-recipe-plus-generic-audit'].includes(plugin.merge_support)) {
			throw new Error(`${slug} has unsupported merge support: ${plugin.merge_support}`);
		}
		const recipes = semanticRecipes.get(slug) ?? [];
		if (JSON.stringify(plugin.semantic_recipes ?? []) !== JSON.stringify(recipes)) {
			throw new Error(`${slug} semantic recipe list is out of date.`);
		}
		if (recipes.length > 0 && plugin.merge_support !== 'semantic-recipe-plus-generic-audit') {
			throw new Error(`${slug} must use semantic recipe merge support.`);
		}
	}
}

function requiredString(value, label) {
	if (typeof value !== 'string' || value.trim() === '') {
		throw new Error(`Missing ${label}.`);
	}
	return value;
}

function optionalString(value) {
	return typeof value === 'string' && value.trim() !== '' ? value : null;
}

function printUsage() {
	console.log(`Usage:
  node scripts/popular-plugin-compat.mjs refresh [manifest]
  node scripts/popular-plugin-compat.mjs validate [manifest]`);
}
