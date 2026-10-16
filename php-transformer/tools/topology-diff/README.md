# Topology diff

Compares each fixture's source element tree with the element tree the engine
renders for it, and ranks the divergences across the corpus.

Author CSS is written against the source tree. Wherever the rendered tree adds,
removes, wraps or retags an element, child and sibling combinators, `:nth-*`
and tag selectors stop matching what the author targeted. This report names
those places (see #2890).

```sh
composer topology-diff                                   # all of fixtures/websites
composer topology-diff -- --fixture <dir> --top 20       # one capture or fixture
composer topology-diff -- --output <report.json>         # machine-readable report
```

Each fixture directory is compiled the way an import compiles it (every HTML,
CSS, JS and SVG file through `ArtifactCompiler`). The source tree is
`index.html`'s `<body>`; the rendered tree is the compiled saved block markup.

## Baseline gate

`tools/topology-diff/baseline.json` holds every fixture's divergence counts.
CI (`PHP Transformer / Source topology baseline`, four shards) fails when a
change moves any count:

- **Rising count:** the change adds, removes, wraps or retags elements author
  CSS cannot see. Remove the divergence, or explain it in the pull request
  and update the baseline.
- **Falling count:** an improvement. Lower the baseline in the same pull
  request so it is kept:

```sh
COMPOSER_PROCESS_TIMEOUT=0 composer topology-diff -- --update-baseline tools/topology-diff/baseline.json
```

Run one shard locally with `--shard <i>/4 --baseline tools/topology-diff/baseline.json`.

## Divergence kinds

| Kind | Meaning |
| --- | --- |
| `added <tag> [marker]` | A rendered element with no source counterpart. What it wraps is aligned in its place. The marker is the engine class naming why it exists, without its per-site hash. |
| `removed <tag>` | A source element with no rendered counterpart (counted once at its subtree root). |
| `removed-wrapper <tag>` | A source wrapper the engine dropped while keeping its children. |
| `retagged <from>><to> [marker]` | A source element rendered under another tag. |

Identity is tag, id and source classes; engine-owned classes (`wp-*`,
`blocks-engine-*`, `be-*`, `has-*`, `is-*`, alignment) are ignored.

## Limits

- Saved markup, not the WordPress-rendered page: dynamic blocks (for example
  `core/navigation`) have no saved element tree. They are counted as
  `dynamic blocks (not compared)`, and their source elements show as removed.
- The editor canvas adds its own layers; it is not compared here.
- Only each fixture's `index.html` is compared.
