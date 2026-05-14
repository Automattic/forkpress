import starlight from '@astrojs/starlight';
import { defineConfig } from 'astro/config';
import starlightLlmsTxt from 'starlight-llms-txt';

import rewriteDocLinks from './src/remark-rewrite-doc-links.mjs';
import removePageTitleHeading from './src/remark-remove-page-title-heading.mjs';

const site = process.env.DOCS_SITE ?? 'https://automattic.github.io';
const base = process.env.DOCS_BASE ?? '/forkpress';

export default defineConfig({
	site,
	base,
	output: 'static',
	trailingSlash: 'always',
	markdown: {
		remarkPlugins: [rewriteDocLinks, removePageTitleHeading],
	},
	integrations: [
		starlight({
			title: 'ForkPress',
			description: 'Static binary WordPress branch previews with copy-on-write storage.',
			editLink: {
				baseUrl: 'https://github.com/Automattic/forkpress/edit/trunk/',
			},
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
					label: 'Architecture',
					items: [
						{ label: 'Storage Drivers', slug: 'docs/storage-drivers' },
						{ label: 'Lazy Overlay COW', slug: 'docs/lazy-overlay-cow' },
						{ label: 'Windows COW Setup', slug: 'docs/windows-cow' },
					],
				},
				{
					label: 'Project',
					items: [{ label: 'Documentation Site', slug: 'docs/documentation-site' }],
				},
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
