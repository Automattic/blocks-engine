# Visual Parity Report Contract

Visual parity tooling should exchange product-neutral reports instead of importer-specific audit arrays. The report schema is `blocks-engine/php-transformer/visual-parity-report/v1`; the fixture/config schema is `blocks-engine/php-transformer/visual-parity-fixture/v1`.

Schemas live at:

- `docs/contracts/php-transformer-visual-parity-report.schema.json`
- `docs/contracts/php-transformer-visual-parity-fixture.schema.json`

## Report Shape

Required top-level keys:

```json
{
  "schema": "blocks-engine/php-transformer/visual-parity-report/v1",
  "status": "warning",
  "severity": "warning",
  "source_render": {
    "kind": "source",
    "route": "/",
    "html_path": "source/index.html",
    "renderer": "playwright"
  },
  "target_render": {
    "kind": "target",
    "route": "/",
    "url": "https://example.test/",
    "renderer": "wordpress"
  },
  "viewports": [
    {
      "id": "desktop",
      "width": 1440,
      "height": 1000,
      "source_screenshot_path": "screens/source-desktop.png",
      "target_screenshot_path": "screens/target-desktop.png",
      "diff_screenshot_path": "screens/diff-desktop.png"
    }
  ],
  "matches": [],
  "findings": [],
  "recommendations": []
}
```

`source_render` and `target_render` describe how each side was rendered: route, URL, HTML/artifact path, renderer, ref/commit, environment, generated timestamp, and screenshot path when available. They do not encode WordPress post IDs, theme slugs, deployment IDs, or product-owned persistence policy.

`viewports` records the viewport contract and optional per-viewport source, target, and diff screenshot paths. Screenshot paths are artifact-relative when possible so CI systems can publish them without rewriting local machine paths.

`matches` records DOM candidate matches. Every match has a `kind` of `generic`, `button`, `menu`, `card`, or `form`, source/target selectors, confidence, optional viewport, selector evidence, DOM summary, and optional kind-specific fields.

Kind-specific fields are generic UI facts:

- `button`: label, href, variant, icon position.
- `menu`: orientation, item count, labels, submenu presence.
- `card`: heading, media presence, link presence, action count.
- `form`: action, method, control count, control types, required count.

`computed_style_deltas` records selector-scoped style changes, including viewport, source/target selectors, CSS property, source value, target value, delta, severity, and selector evidence.

`visual_diff` records optional image-diff metrics: mismatch percent, mismatch pixels, total pixels, SSIM, threshold, aggregate diff screenshot path, and per-viewport metrics. Omit metrics when a runner cannot produce them.

`findings` is the reviewer-facing issue list. Findings carry severity, category, summary, viewport, component kind, selector evidence, style deltas, visual metrics, and linked recommendation IDs.

`recommendations` contains product-neutral repair advice with priority, summary, rationale, selector evidence, and linked finding IDs. A downstream importer may translate recommendations into patches, comments, or acceptance gates at its own boundary.

## Fixture/Config Shape

Fixtures configure a parity run without choosing a downstream product:

```json
{
  "schema": "blocks-engine/php-transformer/visual-parity-fixture/v1",
  "name": "hero-and-form-parity",
  "source": {
    "html_path": "fixtures/source/index.html",
    "renderer": "playwright"
  },
  "target": {
    "url": "https://example.test/",
    "renderer": "wordpress",
    "wait_for_selector": "main"
  },
  "viewports": [
    { "id": "mobile", "width": 390, "height": 844 },
    { "id": "desktop", "width": 1440, "height": 1000 }
  ],
  "capture": [
    { "kind": "button", "selector": ".hero .button" },
    { "kind": "menu", "selector": "nav" },
    { "kind": "card", "selector": ".feature-card" },
    { "kind": "form", "selector": "form" }
  ],
  "matchers": [
    { "kind": "selector", "source_selector": ".hero .button", "target_selector": ".wp-block-button__link" },
    { "kind": "accessibility", "role": "navigation", "min_confidence": 0.9 }
  ],
  "thresholds": {
    "max_mismatch_percent": 0.5,
    "max_style_deltas": 4,
    "min_match_confidence": 0.75,
    "severity_gate": "error"
  }
}
```

Fixtures may include `expectations` for harness assertions against report paths, but the canonical contract is the generated report shape. Runners own browser startup, authentication, screenshot capture, and artifact publication.
