import { readFileSync } from 'node:fs';

import { defineCollection } from 'astro:content';
import { glob } from 'astro/loaders';
import { docsSchema } from '@astrojs/starlight/schema';

type GenerateIdContext = {
	entry: string;
	base: URL;
	data: Record<string, unknown>;
};

const docs = defineCollection({
	loader: glob({
		base: '.',
		pattern: [
			'README.{md,mdx}',
			'docs/**/*.{md,mdx}',
			'crates/**/README.{md,mdx}',
			'experiments/**/README.{md,mdx}',
			'installer/**/README.{md,mdx}',
			'packages/**/README.{md,mdx}',
			'components/**/README.{md,mdx}',
			'runtime/**/README.{md,mdx}',
			'scripts/**/README.{md,mdx}',
			'wp-plugin/**/README.{md,mdx}',
		],
		generateId: (context) => {
			ensureTitle(context);
			return routeIdForEntry(context.entry);
		},
	}),
	schema: docsSchema(),
});

export const collections = { docs };

function ensureTitle(context: GenerateIdContext) {
	if (typeof context.data.title === 'string' && context.data.title.trim() !== '') {
		return;
	}

	context.data.title =
		inferTitleFromMarkdown(readFileSync(new URL(encodeURI(context.entry), context.base), 'utf8')) ??
		titleFromRouteId(routeIdForEntry(context.entry));
}

function routeIdForEntry(entry: string) {
	const normalized = entry.replace(/\\/g, '/').replace(/\.(md|mdx)$/i, '');

	if (normalized === 'README') {
		return 'index';
	}

	if (normalized.endsWith('/README')) {
		return normalized.slice(0, -'/README'.length);
	}

	return normalized;
}

function inferTitleFromMarkdown(markdown: string) {
	const lines = markdown.replace(/^\uFEFF/, '').split(/\r?\n/);
	let lineIndex = frontmatterEndLine(lines);
	let fenceMarker: '`' | '~' | undefined;

	for (; lineIndex < lines.length; lineIndex += 1) {
		const line = lines[lineIndex];
		const trimmed = line.trimStart();
		const fence = trimmed.match(/^(`{3,}|~{3,})/);

		if (fence) {
			const marker = fence[1][0] as '`' | '~';
			fenceMarker = fenceMarker === marker ? undefined : marker;
			continue;
		}

		if (fenceMarker) {
			continue;
		}

		const h1 = line.match(/^#\s+(.+?)\s*#*\s*$/);
		if (h1) {
			return cleanMarkdownTitle(h1[1]);
		}
	}
}

function frontmatterEndLine(lines: string[]) {
	if (lines[0]?.trim() !== '---') {
		return 0;
	}

	for (let lineIndex = 1; lineIndex < lines.length; lineIndex += 1) {
		if (lines[lineIndex].trim() === '---') {
			return lineIndex + 1;
		}
	}

	return 0;
}

function cleanMarkdownTitle(title: string) {
	return title
		.replace(/`([^`]+)`/g, '$1')
		.replace(/\[([^\]]+)\]\([^)]+\)/g, '$1')
		.replace(/[*_~]/g, '')
		.trim();
}

function titleFromRouteId(routeId: string) {
	if (routeId === 'index') {
		return 'ForkPress';
	}

	return routeId
		.split('/')
		.at(-1)!
		.replace(/[-_]+/g, ' ')
		.replace(/\b\w/g, (letter) => letter.toUpperCase());
}
