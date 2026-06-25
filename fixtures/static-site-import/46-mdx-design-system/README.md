# Halo UI — Documentation

The **MDX-powered documentation site** for **Halo UI**, the open-source design
system and React component library that powers every [Lumen Labs](https://lumenlabs.dev)
product (Aperture, Relay, and the marketing site).

This is the `apps/docs` workspace of the Halo UI monorepo. The docs are written
in **MDX** — Markdown prose interleaved with the very React components they
document — and compiled to static HTML with **Astro + `@astrojs/mdx`**.

> **Why MDX?** A design system's docs *are* a product surface. Writing them in
> MDX means every prop table, color swatch, and live example is the real
> component, imported and rendered inline — never a screenshot that drifts out
> of date.

---

## What's in here

```
46-mdx-design-system/
├── package.json                 # the stack: astro, @astrojs/mdx, @astrojs/react
├── astro.config.mjs             # MDX + React + sitemap integration, rehype slugs/anchors
│
├── src/
│   ├── content/
│   │   ├── config.ts            # Zod schema validating every page's frontmatter
│   │   └── docs/                # ← THE SOURCE: real .mdx files
│   │       ├── index.mdx                  # docs home / install
│   │       ├── foundations/
│   │       │   ├── color.mdx
│   │       │   └── typography.mdx
│   │       └── components/
│   │           ├── button.mdx
│   │           ├── input.mdx
│   │           ├── modal.mdx
│   │           └── card.mdx
│   │
│   └── components/              # JSX/Astro/TSX sources the MDX imports
│       ├── DocsLayout.astro     # sidebar + topbar + TOC shell
│       ├── SearchPalette.astro  # ⌘K command palette
│       ├── Callout.jsx          # <Callout type="warning">…</Callout>
│       ├── PropsTable.jsx       # <PropsTable rows={[…]} />
│       ├── Swatch.jsx           # <Swatch /> + <SwatchGrid>
│       ├── CodePreview.jsx      # live render + copyable source
│       ├── Tabs.jsx             # <Tabs labels={[…]}><Tab>…
│       ├── DoDont.jsx           # do / don't guidance cards
│       └── Button.tsx           # the real @halo-ui/react Button (illustrative)
│
├── tokens/                      # Style Dictionary token pipeline
│   ├── config.json
│   └── src/color.json
│
├── index.html                   # ← RENDERED OUTPUT (what MDX compiles to)
├── color.html
├── typography.html
├── button.html
├── input.html
├── modal.html
├── card.html
├── css/halo.css                 # compiled theme + token CSS variables
└── js/halo.js                   # runtime: search, copy, TOC, theme, modal demo
```

### Two layers, on purpose

1. **The `.mdx` source** (`src/content/docs/**`) is the authored content. Open
   any file to see authentic MDX: YAML frontmatter, `import { Callout } from
   '@components/Callout'`, `export const meta = {…}`, and JSX components
   (`<PropsTable>`, `<Swatch>`, `<CodePreview>`, `<Tabs>`, `<DoDont>`) mixed into
   Markdown prose.
2. **The rendered HTML** (`*.html` at the root) is exactly what those MDX files
   compile to. It's plain, framework-free HTML/CSS/JS so the docs are browsable
   by opening `index.html` directly — no build step, no server.

The two are kept faithfully in sync: every Callout, props table, swatch grid,
code preview, and do/don't pair in the HTML corresponds to a JSX component
invocation in the matching `.mdx` file.

---

## Running it locally

The published docs are static — **just open `index.html`** in any browser.

To work on the *source* the way the Lumen Labs team does:

```bash
pnpm install
pnpm dev        # astro dev server at http://localhost:4321
pnpm build      # static build to dist/
pnpm tokens:build   # regenerate CSS variables from tokens/src
```

---

## Features of the rendered docs

- **Persistent left sidebar** grouped by Overview / Foundations / Components.
- **On-page table of contents** with scroll-spy highlighting the current section.
- **⌘K command palette** search across all pages, tags, and descriptions
  (also `/` to open, arrow keys to navigate).
- **Copy buttons** on every code block and code preview.
- **Live component playground** — the Modal page opens a real, focus-trapped
  dialog (Escape to close, Tab trapped inside, focus restored on close).
- **Light / dark theme toggle** that persists to `localStorage` and respects
  `prefers-color-scheme`.
- **Accessible & responsive** — semantic landmarks, ARIA on tabs/dialogs,
  `prefers-reduced-motion` honored, mobile nav drawer.

---

## License

MIT © Lumen Labs. Halo UI and these docs are open source.
