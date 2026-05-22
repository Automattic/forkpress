import starlight from '@astrojs/starlight';
import { defineConfig } from 'astro/config';
import mermaid from 'astro-mermaid';
import starlightLlmsTxt from 'starlight-llms-txt';

import rewriteDocLinks from './src/remark-rewrite-doc-links.mjs';
import removePageTitleHeading from './src/remark-remove-page-title-heading.mjs';

const site = process.env.DOCS_SITE ?? 'https://automattic.github.io';
const base = process.env.DOCS_BASE ?? '/forkpress';
const normalizedBase = base.replace(/\/$/, '');
const socialPreviewImageVersion = '2026-05-22-2x';
const socialPreviewImage = new URL(
	`${normalizedBase}/social-preview.png?v=${socialPreviewImageVersion}`,
	site,
).href;
const socialPreviewTitle = 'ForkPress: Branch WordPress like code';

export default defineConfig({
	site,
	base,
	output: 'static',
	outDir: 'docs-dist',
	trailingSlash: 'always',
	markdown: {
		remarkPlugins: [rewriteDocLinks, removePageTitleHeading],
	},
	integrations: [
		mermaid({
			autoTheme: true,
			enableLog: false,
		}),
		starlight({
			title: 'ForkPress',
			description:
				'ForkPress branches local WordPress sites into isolated previews, then merges file and database changes back with audit trails for safer parallel work.',
			editLink: {
				baseUrl: 'https://github.com/Automattic/forkpress/edit/trunk/',
			},
			head: [
				{ tag: 'meta', attrs: { property: 'og:title', content: socialPreviewTitle } },
				{ tag: 'meta', attrs: { property: 'og:image', content: socialPreviewImage } },
				{ tag: 'meta', attrs: { property: 'og:image:secure_url', content: socialPreviewImage } },
				{ tag: 'meta', attrs: { property: 'og:image:type', content: 'image/png' } },
				{ tag: 'meta', attrs: { property: 'og:image:width', content: '2400' } },
				{ tag: 'meta', attrs: { property: 'og:image:height', content: '1260' } },
				{
					tag: 'meta',
					attrs: {
						property: 'og:image:alt',
						content:
							'ForkPress: Branch WordPress like code. Preview isolated changes and merge files and databases with audit trails.',
					},
				},
				{ tag: 'meta', attrs: { name: 'twitter:title', content: socialPreviewTitle } },
				{ tag: 'meta', attrs: { name: 'twitter:image', content: socialPreviewImage } },
				{
					tag: 'meta',
					attrs: {
						name: 'twitter:image:alt',
						content:
							'ForkPress: Branch WordPress like code. Preview isolated changes and merge files and databases with audit trails.',
					},
				},
			],
			pagefind: true,
			social: [
				{
					icon: 'github',
					label: 'GitHub',
					href: 'https://github.com/Automattic/forkpress',
				},
			],
			sidebar: [
				{ label: 'Overview', slug: 'index' },
				{
					label: 'CLI commands',
					collapsed: true,
					items: [
						{ label: 'Overview', slug: 'docs/cli/index' },
						{ label: 'forkpress init', slug: 'docs/cli/init' },
						{ label: 'forkpress serve', slug: 'docs/cli/serve' },
						{ label: 'forkpress start', slug: 'docs/cli/start' },
						{ label: 'forkpress stop', slug: 'docs/cli/stop' },
						{ label: 'forkpress server', slug: 'docs/cli/server' },
						{ label: 'forkpress branch', slug: 'docs/cli/branch' },
						{ label: 'forkpress branchctl', slug: 'docs/cli/branchctl' },
						{ label: 'forkpress remote', slug: 'docs/cli/remote' },
						{ label: 'forkpress clone', slug: 'docs/cli/clone' },
						{ label: 'forkpress pull', slug: 'docs/cli/pull' },
						{ label: 'forkpress commit', slug: 'docs/cli/commit' },
						{ label: 'forkpress push', slug: 'docs/cli/push' },
						{ label: 'forkpress agents', slug: 'docs/cli/agents' },
						{ label: 'forkpress git', slug: 'docs/cli/git' },
						{ label: 'forkpress logs', slug: 'docs/cli/logs' },
						{ label: 'forkpress doctor', slug: 'docs/cli/doctor' },
						{ label: 'forkpress storage', slug: 'docs/cli/storage' },
					],
				},
				{
					label: 'Core workflows',
					items: [
						{ label: 'Branching', slug: 'docs/branching' },
						{ label: 'Remote sites', slug: 'docs/remote-sites' },
						{ label: 'Merging', slug: 'docs/merging' },
						{ label: 'Conflict review', slug: 'docs/conflict-review' },
						{ label: 'Plugin validator recipes', slug: 'docs/plugin-validator-recipes' },
						{ label: 'Plugin validator contract', slug: 'docs/plugin-merge-validators' },
						{ label: 'Top plugin support', slug: 'docs/top-plugin-support' },
						{ label: 'Git workflow', slug: 'docs/git-workflow' },
						{ label: 'Agents', slug: 'docs/agents' },
					],
				},
				{
					label: 'Merge internals',
					collapsed: true,
					items: [
						{ label: 'Reliability matrix', slug: 'docs/merge-reliability' },
						{ label: 'Stale audit workflow', slug: 'docs/stale-audit-workflow' },
						{ label: 'Crash consistency', slug: 'docs/merge-crash-consistency' },
						{ label: 'Repair policy', slug: 'docs/merge-repair-policy' },
						{ label: 'Test speed', slug: 'docs/merge-test-speed' },
					],
				},
				{
					label: 'Storage',
					items: [
						{ label: 'Overview', slug: 'docs/storage/overview' },
						{ label: 'macOS', slug: 'docs/storage/macos' },
						{ label: 'Linux', slug: 'docs/storage/linux' },
						{ label: 'Windows', slug: 'docs/storage/windows' },
						{ label: 'Engines', slug: 'docs/storage/engines' },
					],
				},
				{
					label: 'Reference',
					items: [
						{ label: 'Installation', slug: 'docs/installation' },
						{ label: 'Commands', slug: 'docs/commands' },
						{ label: 'Logs', slug: 'docs/logs' },
					],
				},
				{
					label: 'Project',
					items: [
						{ label: 'Architecture', slug: 'docs/architecture' },
						{ label: 'Releases', slug: 'docs/releases' },
						{ label: 'Development', slug: 'docs/development' },
						{ label: 'Documentation site', slug: 'docs/documentation-site' },
					],
				},
				{ label: 'Experiments', slug: 'docs/experiments' },
			],
			plugins: [
				starlightLlmsTxt({
					projectName: 'ForkPress',
					description:
						'ForkPress ships static binaries for local WordPress branch previews backed by copy-on-write storage and Git worktrees.',
				}),
			],
		}),
	],
});
