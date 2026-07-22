# Figma WordPress Acceptance Matrix v2

Run the canonical production corpus from a fresh checkout:

```sh
php scripts/production-acceptance-matrix.php --manifest=path/to/private-fixtures.json --output=artifacts/figma-wordpress-acceptance
```

Run any non-empty collection of operator-owned `.fig` files with the same fail-closed evaluator:

```sh
php scripts/production-acceptance-matrix.php --profile=manifest --manifest=path/to/private-fixtures.json --output=artifacts/ad-hoc-figma-wordpress-acceptance
```

The default `production` profile requires `fse-pilot-build-theme`, `twenty-twenty-five-community`, and `fisiostetic`. The `manifest` profile evaluates every supplied fixture in manifest order. Fixture IDs are unique lowercase slugs. Input paths may remain outside the repository; `summary.json` never includes them.

Each fixture supplies a deployable `blocks-engine/wordpress-site-plan/v2` and evidence for three independently measured conversion boundaries:

1. **Figma to HTML:** `decode`, `normalize`, `emit`, `figma_html_desktop_parity`, and `figma_html_mobile_parity`.
2. **HTML to WordPress blocks:** `import`, `editor_validity`, `fallback`, `html_wordpress_desktop_parity`, and `html_wordpress_mobile_parity`.
3. **End to end:** `figma_wordpress_desktop_parity`, `figma_wordpress_mobile_parity`, and `responsive_selection`.

Parity evidence uses schema `blocks-engine/figma-wordpress-stage-evidence/v1`, exact fixture/stage/source identities, resolvable screenshot and diff references, and the corresponding `comparison` value: `figma_html`, `html_wordpress`, or `figma_wordpress`. Every diff must report zero pixel and geometry differences.

All evidence stages remain mandatory. Missing inputs, malformed plans, unresolved references, invalid blocks, fallback blocks, nonzero parity differences, and incomplete fixture accounting fail closed with stable stage-specific reason codes.

## Figma Producer

Run the Figma-side producer into the same repository-artifact root used by the evaluator:

```sh
cd figma-transformer
php scripts/figma-fixture-matrix.php --fixture=/path/to/private.fig --output-dir=../artifacts/figma-wordpress-acceptance --parity-report=../artifacts/figma-wordpress-acceptance/figures/{fixture}-parity.json
```

Completed fixture records retain their existing output and add `acceptance_readiness` (`blocks-engine/figma-transformer/acceptance-readiness/v1`). Its stage files are written to `<output-dir>/<fixture>/acceptance-readiness/{decode,normalize,emit,figma_html_desktop_parity,figma_html_mobile_parity,responsive_selection}.json`. Use those paths for the corresponding `evidence` entries in the evaluator manifest.

The matrix emits only Figma facts. It derives decode, normalize, emit, responsive source-frame, and Figma-to-HTML parity evidence from transform diagnostics and an explicit operator-provided parity report. That report carries distinct `breakpoints` entries for desktop and mobile; each entry has `viewport.device_hint`, `source.screenshot_path`, `generated.screenshot_path`, and `artifacts.report_path`. The referenced exact diff report contains integer `pixel_difference_count` and `geometry_difference_count` metrics. Acceptance evidence records hashes for all three artifacts. Unscoped single-viewport evidence cannot satisfy both stages. Screenshots and exact diff reports are supplied by external browser tooling; generated HTML captures are never source screenshots. SSI supplies import, editor, fallback, HTML-to-WordPress, and Figma-to-WordPress stages.
