# Busy Bears Mobile CTA Evidence

Captured 2026-09-07 at 390x844 with Playwright Chromium 140.0.7339.16.

| Surface | Selector | Foreground | Background | Bounding box |
| --- | --- | --- | --- | --- |
| Source | `a._link_1fj1u_58...wixui-button` (`GET A QUOTE`) | `rgb(0, 0, 0)` | `rgb(0, 119, 204)` | `24, 692.328125, 161.109375 x 45.859375` |
| Installed result | `a.wp-block-button__link.wp-element-button` (`GET A QUOTE`) | `rgb(247, 249, 251)` | `rgba(0, 0, 0, 0)` | `24, 822.671875, 161.109375 x 45.171875` |
| Candidate | `a.wp-block-button__link.wp-element-button` (`Quote`) | `rgb(0, 0, 0)` | `rgb(0, 119, 204)` | `8, -4, 128.421875 x 42` |

The candidate is the `HtmlTransformer` output for a generic anchor whose removed inner label surface owns the foreground, background, padding, and `::after` content. It emits one native `core/button`, has no CTA HTML fallback, and its WordPress block-validity report is `pass`. The source-equivalent blue/black paint is projected to the native link; the generated content and its margin are projected to the same link's `::after`.

## Inputs

- Blocks Engine revision: `c3c8bf4d` (`origin/trunk` at worktree creation).
- Installed importer result: `http://localhost:8929/`; read-only Studio artifact: `/Users/chubes/Studio/busybears-20260907`.
- Source: `https://www.busybearscleaning.com/`.
- Supplied evidence: `/var/folders/lr/c_cmmt7s0592m4njz99v5yb40000gn/T/opencode/busy-bears-package/mobile-cta.json`, `followup.json`, `review2.json`, `review2-source-home-390.png`, and `review2-imported-home-390.png`.

## Artifacts

- Fresh source image: `/var/folders/lr/c_cmmt7s0592m4njz99v5yb40000gn/T/opencode/busy-bears-package/fresh-source-home-390.png`.
- Fresh installed-result image: `/var/folders/lr/c_cmmt7s0592m4njz99v5yb40000gn/T/opencode/busy-bears-package/fresh-imported-home-390.png`.
- Fresh candidate image: `/var/folders/lr/c_cmmt7s0592m4njz99v5yb40000gn/T/opencode/busy-bears-package/fresh-candidate-inner-surface-390.png`.

## Commands And Results

```text
php tests/unit/button-style-resolver.php
# Button style resolver tests: 54 passed

php -l src/HtmlToBlocks/Style/AuthorStylesheetProjector.php
php -l tests/unit/button-style-resolver.php
# No syntax errors detected
```

The browser probes opened only the public source and installed result, then rendered the candidate from the actual transformer serialized markup and generated CSS. Neither installed site nor source artifacts were modified.
