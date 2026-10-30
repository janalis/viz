import { execFileSync } from 'node:child_process'
import { defineConfig } from 'vitepress'

const repo = 'https://github.com/janalis/viz'
const base = '/viz/'
const site = 'https://janalis.github.io' + base

// Latest release tag, shown in the nav ("dev" outside a git checkout).
function version(): string {
  try {
    return execFileSync('git', ['describe', '--tags', '--abbrev=0'], { stdio: ['ignore', 'pipe', 'ignore'] }).toString().trim()
  } catch {
    return 'dev'
  }
}

export default defineConfig({
  title: 'Viz',
  description: 'Mermaid, Graphviz and PlantUML for PHP: native rendering to SVG, PNG and PDF, with a CLI fallback, and conversion between the three.',
  lang: 'en-US',
  base,
  cleanUrls: true,
  lastUpdated: true,
  sitemap: { hostname: site },
  head: [
    ['link', { rel: 'icon', type: 'image/png', sizes: '32x32', href: base + 'favicon-32.png' }],
    ['link', { rel: 'apple-touch-icon', href: base + 'apple-touch-icon.png' }],
    ['meta', { name: 'theme-color', content: '#314EBC' }],
    ['meta', { property: 'og:type', content: 'website' }],
    ['meta', { property: 'og:site_name', content: 'Viz' }],
    ['meta', { property: 'og:image', content: site + 'og.png' }],
    ['meta', { name: 'twitter:card', content: 'summary_large_image' }],
  ],
  themeConfig: {
    logo: { src: '/logo.png', alt: 'Viz' },
    nav: [
      { text: 'Guide', link: '/guide/introduction', activeMatch: '/guide/' },
      { text: 'Compatibility', link: '/mermaid-compatibility', activeMatch: '-compatibility' },
      {
        text: version(),
        items: [
          { text: 'Changelog', link: '/changelog' },
          { text: 'Releases', link: repo + '/releases' },
          { text: 'Packagist', link: 'https://packagist.org/packages/janalis/viz' },
        ],
      },
    ],
    sidebar: [
      {
        text: 'Guide',
        items: [
          { text: 'What is Viz?', link: '/guide/introduction' },
          { text: 'Installation', link: '/guide/installation' },
          { text: 'Rendering and export', link: '/guide/rendering' },
          { text: 'Conversion', link: '/conversion' },
          { text: 'Upgrading from 0.1', link: '/guide/upgrading' },
        ],
      },
      {
        text: 'Compatibility',
        items: [
          { text: 'Mermaid', link: '/mermaid-compatibility' },
          { text: 'Mermaid history', link: '/mermaid-compatibility-history' },
          { text: 'Graphviz', link: '/graphviz-compatibility' },
          { text: 'PlantUML', link: '/plantuml-compatibility' },
        ],
      },
      {
        text: 'Project',
        items: [
          { text: 'Development', link: '/guide/development' },
          { text: 'Releasing', link: '/guide/releasing' },
          { text: 'Changelog', link: '/changelog' },
        ],
      },
    ],
    search: { provider: 'local' },
    editLink: {
      pattern: 'https://github.com/janalis/viz/edit/main/docs/:path',
      text: 'Edit this page on GitHub',
    },
    socialLinks: [{ icon: 'github', link: repo }],
    outline: { level: [2, 3] },
    footer: {
      message: 'Released under the MIT License.',
      copyright: 'Ported parts of Mermaid and PlantUML keep their notices; the native Graphviz is written from published papers, not from Graphviz\'s source.',
    },
  },
})
