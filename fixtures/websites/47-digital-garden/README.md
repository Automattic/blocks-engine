# 🌱 Mara's Garden

A **digital garden** — a personal knowledge base / second brain in the
[Obsidian](https://obsidian.md) → [Quartz](https://quartz.jzhao.xyz/) lineage. The
source-of-truth is a folder of interlinked Markdown notes; the committed HTML is the
static site a generator produces from them.

By **Mara Okonkwo**, a backend engineer who keeps drifting between four topics that turn
out to be the same topic: distributed systems, note-taking, Stoicism, and an actual
vegetable garden.

> A garden is not a finished thing. It is a relationship.

---

## What's in here

```
47-digital-garden/
├── quartz.config.ts          # Quartz 4 config — theme, plugins, the illusion's source of truth
├── README.md                 # you are here
│
├── notes/                    # ── THE ACTUAL CONTENT ──  hand-written Markdown
│   ├── index.md              # garden home / Map of Content (MOC) — "Start Here"
│   ├── on-tending-a-digital-garden.md      🌳 evergreen
│   ├── evergreen-notes.md                   🌳 evergreen
│   ├── book-how-to-take-smart-notes.md      🌿 budding   (literature / book note)
│   ├── idempotency-is-a-superpower.md       🌳 evergreen (code block)
│   ├── the-two-generals-problem.md          🌿 budding
│   ├── why-i-stopped-trusting-wall-clocks.md 🌿 budding  (code block)
│   ├── the-dichotomy-of-control.md          🌳 evergreen
│   ├── amor-fati.md                          🌿 budding
│   ├── compost-is-a-distributed-system.md    🌿 budding
│   └── three-sisters-planting.md             🌰 seedling (footnotes live in the book note)
│
├── index.html                # ── RENDERED OUTPUT ──  generated home / MOC
├── <one .html per note>      # generated note pages, wikilinks resolved to <a href>
├── tags.html                 # generated tag + recency index
├── css/garden.css            # vanilla stylesheet, light + dark themes
└── js/garden.js              # search, SVG graph view, hover-preview, theme toggle
```

The `.md` files in `notes/` are the point. The `.html` files are what a build produces
from them — open `index.html` directly via `file://` to browse the garden.

## Note conventions

### Frontmatter

Every note opens with YAML frontmatter:

```yaml
---
title: "Idempotency Is a Superpower"
aliases: ["Idempotency", "Idempotent Operations"]
created: 2024-03-01
updated: 2026-06-10
tags: [evergreen, distributed-systems, engineering]
---
```

### Wikilinks

Notes interlink with `[[wikilinks]]`, the way Obsidian and Quartz resolve them:

```markdown
See [[The Two Generals Problem]] for the proof that this uncertainty is fundamental.
```

The link target is a note's **title** (or any of its `aliases`). The build resolves
`[[The Two Generals Problem]]` → `the-two-generals-problem.html`. In the rendered HTML
these become `<a class="internal">` links, and each page gets a **"Linked references"**
(backlinks) section listing every note that links *to* it — derived automatically from the
graph of wikilinks.

### Growth states (ripeness, not folders)

Notes are tagged by *confidence*, not filed into topic folders, because a note like
`compost-is-a-distributed-system` is legitimately both a gardening note and a systems note:

| State | Tag | Meaning |
|-------|-----|---------|
| 🌰 Seedling  | `seedling`  | fleeting, half-formed, may be wrong |
| 🌿 Budding   | `budding`   | actively worked on, fairly confident |
| 🌳 Evergreen | `evergreen` | tended for a long time, stands behind it |

## The rendered site

`css/garden.css` + `js/garden.js` are dependency-free and run from `file://`. The site has:

- **Note search / filter** — type in the sidebar box (or press <kbd>/</kbd>); arrow-keys + Enter to navigate results.
- **SVG graph view** — a small force-directed map of the whole garden in the right rail; the current note is highlighted and hovering any node spotlights its neighborhood.
- **Hover-preview popovers** — hover (or focus) a `[[wikilink]]` to peek at the linked note's title, ripeness, tags, and summary before clicking.
- **Backlinks** — "Linked references" on every note.
- **Light / dark theme** — toggle in the sidebar, persisted to `localStorage`, defaults to your OS preference.
- Accessible markup (skip link, ARIA, focus-visible) and `prefers-reduced-motion` respected.

## Build command (illustrative)

This is a real-shaped Quartz 4 project. With the toolchain installed you'd regenerate the
HTML from `notes/` with:

```bash
# one-time
npx quartz create

# build + live-reload preview
npx quartz build --serve        # → http://localhost:8080

# build static output for deploy
npx quartz build
```

`quartz.config.ts` is the configuration that build reads — fonts, the green/gold theme,
and the plugin chain (`ObsidianFlavoredMarkdown` for wikilinks, `CrawlLinks` for backlinks,
`SyntaxHighlighting`, `TableOfContents`, etc.). The `.html` files committed here are a
faithful representation of that output, hand-rendered so the garden is browsable with no
build step.

---

*Tended on Sundays. Twenty minutes: re-read one old note, fix one link, pull one weed.*
