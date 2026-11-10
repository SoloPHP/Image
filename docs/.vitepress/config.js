import { defineConfig } from 'vitepress'

const description = 'Immutable GD images for PHP: EXIF orientation, bounded resizing, JPEG/PNG/WebP encoding and smaller-only optimization.'

export default defineConfig({
  title: 'Solo Image',
  description,
  base: '/Image/',

  head: [
    ['link', { rel: 'icon', type: 'image/svg+xml', href: '/Image/logo.svg' }],
    ['meta', { name: 'theme-color', content: '#d946ef' }],
    ['meta', { property: 'og:type', content: 'website' }],
    ['meta', { property: 'og:title', content: 'Solo Image' }],
    ['meta', { property: 'og:description', content: description }],
  ],

  themeConfig: {
    logo: '/logo.svg',

    nav: [
      { text: 'Guide', link: '/guide/' },
      { text: 'API', link: '/guide/api' },
      { text: 'Details', link: '/guide/details' },
      { text: 'v1.0.0-RC2', link: 'https://github.com/SoloPHP/Image/releases' },
    ],

    sidebar: [
      {
        text: 'Solo Image · 1.0',
        items: [
          { text: 'Getting started', link: '/guide/' },
          { text: 'API reference', link: '/guide/api' },
          { text: 'Behavior in detail', link: '/guide/details' },
        ],
      },
    ],

    socialLinks: [
      { icon: 'github', link: 'https://github.com/SoloPHP/Image' },
    ],

    footer: {
      message: 'MIT licensed.',
      copyright: 'Copyright © 2026 SoloPHP',
    },

    editLink: {
      pattern: 'https://github.com/SoloPHP/Image/edit/main/docs/:path',
      text: 'Edit this page on GitHub',
    },

    search: {
      provider: 'local',
    },
  },
})
