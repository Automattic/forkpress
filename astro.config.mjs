import starlight from '@astrojs/starlight';
import { defineConfig } from 'astro/config';
import mermaid from 'astro-mermaid';
import starlightLlmsTxt from 'starlight-llms-txt';

import rewriteDocLinks from './src/remark-rewrite-doc-links.mjs';
import removePageTitleHeading from './src/remark-remove-page-title-heading.mjs';

const site = process.env.DOCS_SITE ?? 'https://automattic.github.io';
const base = process.env.DOCS_BASE ?? '/forkpress';
const normalizedBase = base.replace(/\/$/, '');
const socialPreviewImage = new URL(`${normalizedBase}/social-preview.png`, site).href;

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
				'Branch, preview, and merge WordPress file and database changes with local copy-on-write worktrees.',
			editLink: {
				baseUrl: 'https://github.com/Automattic/forkpress/edit/trunk/',
			},
			head: [
				{ tag: 'meta', attrs: { property: 'og:image', content: socialPreviewImage } },
				{ tag: 'meta', attrs: { property: 'og:image:width', content: '1200' } },
				{ tag: 'meta', attrs: { property: 'og:image:height', content: '630' } },
				{
					tag: 'meta',
					attrs: {
						property: 'og:image:alt',
						content:
							'ForkPress: Branch WordPress like code. Preview isolated changes and merge files and databases with audit trails.',
					},
				},
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
					label: 'CLI',
					items: [
						{ label: 'Overview', slug: 'docs/cli/index' },
						{ label: 'init', slug: 'docs/cli/init' },
						{ label: 'serve', slug: 'docs/cli/serve' },
						{ label: 'start', slug: 'docs/cli/start' },
						{ label: 'stop', slug: 'docs/cli/stop' },
						{ label: 'server', slug: 'docs/cli/server' },
						{ label: 'branch', slug: 'docs/cli/branch' },
						{ label: 'branchctl', slug: 'docs/cli/branchctl' },
						{ label: 'remote', slug: 'docs/cli/remote' },
						{ label: 'clone', slug: 'docs/cli/clone' },
						{ label: 'pull', slug: 'docs/cli/pull' },
						{ label: 'commit', slug: 'docs/cli/commit' },
						{ label: 'push', slug: 'docs/cli/push' },
						{ label: 'agents', slug: 'docs/cli/agents' },
						{ label: 'git', slug: 'docs/cli/git' },
						{ label: 'logs', slug: 'docs/cli/logs' },
						{ label: 'doctor', slug: 'docs/cli/doctor' },
						{ label: 'storage', slug: 'docs/cli/storage' },
					],
				},
				{
					label: 'Core workflows',
					items: [
						{ label: 'Branching', slug: 'docs/branching' },
						{ label: 'Remote sites', slug: 'docs/remote-sites' },
						{ label: 'Merging', slug: 'docs/merging' },
						{ label: 'Plugin validator recipes', slug: 'docs/plugin-validator-recipes' },
						{ label: 'Top plugin support', slug: 'docs/top-plugin-support' },
						{ label: 'Git workflow', slug: 'docs/git-workflow' },
						{ label: 'Agents', slug: 'docs/agents' },
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
