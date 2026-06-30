# Visual Parity Probe Engine

Generic probe extraction for comparing a source static HTML fixture against a transformed or materialized target output. The utility is product-neutral and does not require WordPress.

## Install

```sh
npm install
npx playwright install chromium
```

Run from `php-transformer/tools/visual-parity/`.

## CLI

```sh
node bin/visual-parity.mjs \
  --source tests/fixtures/source.html \
  --target tests/fixtures/target.html \
  --viewport 390x844 \
  --viewport desktop=1280x720 \
  --selector primary=.primary-link \
  --output tmp/visual-parity-smoke.json
```

Equivalent JSON config:

```json
{
  "source": "tests/fixtures/source.html",
  "target": "tests/fixtures/target.html",
  "viewports": [
    { "name": "mobile", "width": 390, "height": 844 },
    { "name": "desktop", "width": 1280, "height": 720 }
  ],
  "selectors": [
    { "name": "primary", "selector": ".primary-link" }
  ],
  "output": "tmp/visual-parity-smoke.json"
}
```

Then run:

```sh
node bin/visual-parity.mjs --config visual-parity.config.json
```

## DOM box provider

`bin/dom-box-provider.mjs` captures per-node DOM boxes for Homeboy's `artifact-origin dom-boxes` flow. Node identity is keyed off a configurable attribute, so the provider is product-neutral and not tied to any single source format.

```sh
HOMEBOY_DOM_BOX_BASE_URL=https://example.test \
HOMEBOY_DOM_BOX_PAGE_PATHS_JSON='["/index.html"]' \
node bin/dom-box-provider.mjs --node-id-attr=data-node-id --node-name-attr=data-node-name
```

| Setting | Env var | Flag | Default |
| --- | --- | --- | --- |
| Node id attribute | `HOMEBOY_DOM_BOX_NODE_ID_ATTR` | `--node-id-attr` | `data-figma-node-id` |
| Node name attributes | `HOMEBOY_DOM_BOX_NODE_NAME_ATTR` | `--node-name-attr` | `data-figma-node-name,data-figma-name` |

The node id attribute drives element enumeration, selector generation, and id reads. Node name attributes are tried in order; `aria-label` is always appended as a final generic fallback. The defaults stay backward-compatible with the figma-transformer's `data-figma-*` output, so existing figma callers work with no changes; non-figma consumers override the attributes to match their own emitter.

## Output

The JSON report includes normalized config, source and target probe snapshots, and a deterministic comparison summary. Default probes cover button, link, nav/menu, and card-like candidates. Each match records text, href, bounding box, and computed styles for display, color, background color, border, border radius, padding, font size, and font weight.

## Smoke Test

```sh
npm test
```
