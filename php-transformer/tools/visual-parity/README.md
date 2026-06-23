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

## Output

The JSON report includes normalized config, source and target probe snapshots, and a deterministic comparison summary. Default probes cover button, link, nav/menu, and card-like candidates. Each match records text, href, bounding box, and computed styles for display, color, background color, border, border radius, padding, font size, and font weight.

## Smoke Test

```sh
npm test
```
