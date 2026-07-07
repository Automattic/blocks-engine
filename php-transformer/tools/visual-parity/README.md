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

Install the tool dependencies and Chromium browser once per checkout:

```sh
npm ci --prefix php-transformer/tools/visual-parity
npm --prefix php-transformer/tools/visual-parity run install:browsers
node php-transformer/tools/visual-parity/bin/dom-box-provider.mjs --preflight
```

Capture DOM-box evidence for a generated static HTML artifact root:

```sh
HOMEBOY_DOM_BOX_CAPTURE_COMMAND='node php-transformer/tools/visual-parity/bin/dom-box-provider.mjs' \
homeboy tunnel artifact-origin dom-boxes \
  --root=<artifact-root> \
  --entrypoint=index.html \
  --report=<report.json>
```

Use the generated `.fig -> HTML` artifact directory as `<artifact-root>`. The directory must contain `index.html` and any referenced CSS/assets. Keep committed docs and PR descriptions on placeholders such as `<fisiostetic-html-artifact-root>`, `<fse-html-artifact-root>`, and `<tt5-html-artifact-root>`; put machine-local paths only in local operator notes.

Example artifact captures:

```sh
HOMEBOY_DOM_BOX_CAPTURE_COMMAND='node php-transformer/tools/visual-parity/bin/dom-box-provider.mjs' \
homeboy tunnel artifact-origin dom-boxes \
  --root=<fisiostetic-html-artifact-root> \
  --entrypoint=index.html \
  --report=<fisiostetic-dom-box-report.json>

HOMEBOY_DOM_BOX_CAPTURE_COMMAND='node php-transformer/tools/visual-parity/bin/dom-box-provider.mjs' \
homeboy tunnel artifact-origin dom-boxes \
  --root=<fse-html-artifact-root> \
  --entrypoint=index.html \
  --report=<fse-dom-box-report.json>

HOMEBOY_DOM_BOX_CAPTURE_COMMAND='node php-transformer/tools/visual-parity/bin/dom-box-provider.mjs' \
homeboy tunnel artifact-origin dom-boxes \
  --root=<tt5-html-artifact-root> \
  --entrypoint=index.html \
  --report=<tt5-dom-box-report.json>
```

The report is repeatable when the artifact root, entrypoint, browser version, viewport defaults, and node identity attributes stay fixed. Attach the JSON report to the Homeboy run, issue, or PR evidence surface so the next operator can compare generated HTML structure and positions without re-running the full transform.

The fixture matrix runner preflights this provider before running transforms when DOM-box capture is enabled, so missing Node dependencies or Playwright browser installs fail fast with the install command above instead of producing partial matrix artifacts.

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

## Screenshot provider

`bin/screenshot-provider.mjs` captures PNG screenshots for static HTML pages served from an artifact-origin base URL and writes a JSON manifest to stdout.

```sh
HOMEBOY_SCREENSHOT_BASE_URL=https://example.test \
HOMEBOY_SCREENSHOT_PAGE_PATHS_JSON='["/index.html"]' \
HOMEBOY_SCREENSHOT_OUTPUT_DIR=tmp/screenshots \
node bin/screenshot-provider.mjs --viewport=1440x900
```

Equivalent flag-only form:

```sh
node bin/screenshot-provider.mjs \
  --base-url=https://example.test \
  --page-path=/index.html \
  --output-dir=tmp/screenshots \
  --viewport=1440x900
```

Expected PNG paths are deterministic from page paths, for example `tmp/screenshots/index.html.png`. The stdout manifest uses schema `blocks-engine.visual-parity.screenshots.v1` and records each `page_path`, `page_url`, PNG `path`, `filename`, and `exists` flag.

## Output

The JSON report includes normalized config, source and target probe snapshots, and a deterministic comparison summary. Default probes cover button, link, nav/menu, and card-like candidates. Each match records text, href, bounding box, and computed styles for display, color, background color, border, border radius, padding, font size, and font weight.

## Smoke Test

```sh
npm test
```
