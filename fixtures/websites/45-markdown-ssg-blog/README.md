# Cold Start

A software-craft blog about distributed systems, storage engines, and the
quiet engineering that keeps production alive. Built with
[Hugo](https://gohugo.io/), the static site generator.

> Notes on building systems that survive contact with reality.

## What this is

This repository is a **Hugo project**. The content is authored as Markdown
files with YAML frontmatter under `content/`, and Hugo renders them into a
fully static HTML site. There is no database, no server-side code, and no
client-side framework — the published site is plain HTML, CSS, and a sprinkle
of progressive-enhancement JavaScript that you can open directly from disk.

The rendered HTML output that Hugo would produce is committed alongside the
source so the site is browsable without a build step. Open `index.html` in a
browser to read it.

## Project structure

```
.
├── config.toml                  # Hugo site configuration (title, menus, taxonomies, params)
├── README.md                    # this file
│
├── content/                     # ── MARKDOWN SOURCE (the real content) ──
│   ├── _index.md                #    home page body
│   ├── about.md                 #    the About page
│   └── posts/                   #    blog posts, one Markdown file each
│       ├── write-ahead-log.md
│       ├── lsm-vs-btree.md
│       ├── retries-make-outages-worse.md
│       ├── idempotency-keys.md
│       ├── backpressure.md
│       ├── consensus-without-tears.md
│       └── the-cost-of-an-fsync.md
│
├── data/
│   └── authors.yml              # author metadata, referenced by `author:` in frontmatter
│
├── css/
│   └── style.css                # the "ember" theme stylesheet
├── js/
│   └── main.js                  # progressive enhancement (reading bar, active nav, reveal)
│
├── index.html                   # ── RENDERED OUTPUT (what `hugo` builds) ──
├── about.html
├── tags.html                    #    archive / taxonomy listing
├── index.xml                    #    generated RSS feed
└── posts/
    ├── write-ahead-log.html
    ├── lsm-vs-btree.html
    ├── retries-make-outages-worse.html
    ├── idempotency-keys.html
    ├── backpressure.html
    ├── consensus-without-tears.html
    └── the-cost-of-an-fsync.html
```

Each rendered `posts/<slug>.html` is the build output of the corresponding
`content/posts/<slug>.md`. The HTML is a faithful rendering of the Markdown
source: same headings, lists, code blocks, blockquotes, tables, and links.

## Frontmatter

Every post carries YAML frontmatter that Hugo uses to build listings,
taxonomies, and metadata:

```yaml
---
title: "The Write-Ahead Log Is the Database"
date: 2024-02-12T08:30:00Z
author: mara          # key into data/authors.yml
draft: false
description: "..."
tags: ["storage", "durability", "databases", "internals"]
series: ["Storage Internals"]
---
```

Taxonomies (`tags`, `series`) are declared in `config.toml`. Authors are looked
up from `data/authors.yml` by the `author` key.

## Building (illustrative)

With Hugo installed, the usual workflow is:

```bash
# Live-reloading dev server at http://localhost:1313
hugo server -D

# Build the static site into ./public
hugo --minify

# Create a new post from the archetype
hugo new posts/my-new-post.md
```

> **Note:** the build output in this repo is committed at the project root
> (rather than the conventional `public/`) so the site can be opened directly
> via `file://` with no tooling. In a normal Hugo deployment you would publish
> the contents of `public/` to your host.

## Theme

The theme is called **ember** — a clean, readable, long-form blog theme with a
serif display face ([Source Serif 4](https://fonts.google.com/specimen/Source+Serif+4)),
a sans body ([Inter](https://fonts.google.com/specimen/Inter)), monospaced code
([JetBrains Mono](https://fonts.google.com/specimen/JetBrains+Mono)), automatic
light/dark mode via `prefers-color-scheme`, and full `prefers-reduced-motion`
support. All artwork is inline SVG or CSS — there are no remote images.

## License

Content © 2024 Cold Start. Code samples in posts are released into the public
domain; use them freely.
