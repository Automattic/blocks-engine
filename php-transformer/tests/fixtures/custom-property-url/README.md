# Custom-property URL base regression (#3931)

This standalone neutral reduction has two sibling stylesheet directories and one
SVG. `definitions/style.css` defines `--icon`; `consumers/style.css` substitutes
it in `mask-image`. The browser resolves the URL against the consuming sheet.
No original site content is needed.

`tests/unit/custom-property-url.php` compiles and resolves the fixture and checks
that every emitted definition points location-independently at the materialized
SVG. `tools/visual-parity/tests/custom-property-url.mjs` serves the resulting CSS
with the fixture's original DOM (isolating URL resolution from element lowering),
checks Chromium's computed mask URL, and requests the SVG.

Recorded results (both tests run against the unmodified base and the fix):

- Base: PHP regression fails with `--icon: url(img/x.svg)`. Browser regression
  fails: computed `mask-image` ends in
  `/themes/neutral/assets/consumers/img/x.svg` rather than the materialized path.
- Fixed: PHP regression passes with
  `--icon: url(/themes/neutral/assets/definitions/img/x.svg)`. Chromium computes
  `url("http://127.0.0.1:<port>/themes/neutral/assets/definitions/img/x.svg")`;
  the SVG request returns 200.

The full Composer test suite and existing parity fixture corpus passed with the
fix. Ordinary CSS URLs remain stylesheet-relative; destination-dependent custom
property URLs cannot have integrity hashes computed before theme resolution.
