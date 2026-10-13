# Capture evidence

A capture tool renders a source site and records what it observed beside the
captured HTML: which copy of the page is the mobile document, which control
opens which panel, how a gallery cycles, which items a collection filter
shows, the rendered colour of an icon. Blocks Engine reads that evidence to
produce native, editable blocks instead of a frozen snapshot.

This page is Blocks Engine's **producer-neutral input contract** for that
evidence. Any capture tool may emit it. The names are published once in
[`CaptureEvidence`](../../src/Contract/CaptureEvidence.php); the transformer
never spells them anywhere else, and
`tests/contract/capture-evidence-vocabulary.php` keeps this page, the
constants and their use in `src/` identical.

## Namespace

- Every attribute, class, id suffix and probe token starts with
  `data-blocks-engine-capture-` (id suffixes with `--data-blocks-engine-capture-`).
  Classes use the same prefix as attributes on purpose: `blocks-engine-*`
  classes are the engine's own generated classes, which conversion strips or
  ignores as source styling, so capture classes must stay outside it.
- Sidecar schemas are `blocks-engine-capture/<name>/v<n>`.
- A producer that observes something this page does not list may still use
  the namespace; the transformer ignores names it does not read. Other
  consumers follow the same rule: for example Static Site Importer reads
  `data-blocks-engine-capture-device-style`,
  `data-blocks-engine-capture-selected-document` and
  `data-blocks-engine-capture-member-login`.
- Attribute values are case-sensitive and trimmed unless noted. A flag
  attribute is present-or-absent; its value is ignored.

## Names

### Prefixes

| Name | Kind | Meaning |
| ---- | ---- | ------- |
| `data-blocks-engine-capture-` | attribute prefix | Every capture attribute. Author selectors negating a capture attribute on `html`/`:root` are runtime document state and stay live. |
| `--data-blocks-engine-capture-` | id suffix prefix | A non-default document variant's copy of a shared source id: `<id>--data-blocks-engine-capture-mobile`, or `<id>--data-blocks-engine-capture-<variant>` for `site-document-variant-<variant>` and declared device documents. The transformer applies the same suffix and points variant-scoped author id selectors at it. |

### Document variants

| Name | Kind | Value | Meaning |
| ---- | ---- | ----- | ------- |
| `data-blocks-engine-capture-document-scope` | attribute (flag) | ignored | The element is a body-equivalent document root. With `device-document` it declares a device profile. |
| `data-blocks-engine-capture-device-document` | attribute | `[a-z][a-z0-9_-]{0,63}` | Profile id of a declared document scope. |
| `data-blocks-engine-capture-mobile-document` | class | — | Root of the mobile copy of a responsive capture. |
| `data-blocks-engine-capture-desktop-document` | class | — | Root of the desktop (default) copy of a responsive capture. |
| `data-blocks-engine-capture-responsive-counterpart-` | class prefix | 12 lowercase hex digits | Pairs an element in the default document with its counterpart in another variant. |
| `data-blocks-engine-capture-responsive-source` | attribute | source id | Source identity of a responsive counterpart; ignored when comparing shared chrome. |
| `data-blocks-engine-capture-source-media` | attribute on `<style>`/`<link>` | media query | The authored media condition, independent of a serialized `media` that only records the inactive document's activation. |

### Dialogs and dropdowns

| Name | Kind | Value | Meaning |
| ---- | ---- | ----- | ------- |
| `data-blocks-engine-capture-dialog-trigger` | attribute | binding key | The control that opens a captured panel. |
| `data-blocks-engine-capture-dialog-panel` | attribute | binding key | The captured panel; equals its trigger's key. |
| `data-blocks-engine-capture-dialog-close` | attribute | binding key | A close control for that dialog. |
| `data-blocks-engine-capture-dialog-panel-state` | attribute on the trigger | JSON | Snapshot of the opened panel state. |
| `data-blocks-engine-capture-dialog-ancestor-state` | attribute on the trigger | JSON list of `{selector, tag, depth, closed, opened}` | Ancestor attributes toggled with the panel. |
| `data-blocks-engine-capture-dialog-ancestor-unverified` | attribute | reason, e.g. `ancestor-limit` | Ancestor bindings could not be verified. Carried onto the native dialog block. |
| `data-blocks-engine-capture-observed-placement` | attribute (flag) | ignored | The dropdown panel was observed in place; the native dialog stays there. |
| `data-blocks-engine-capture-dropdown` | class | — | An in-place dropdown panel. |
| `data-blocks-engine-capture-dialog` | class | — | A captured dialog panel wrapper. |
| `data-blocks-engine-capture-disclosure` | class | — | A captured `<details>` disclosure wrapper. |

### Disclosures

| Name | Kind | Value | Meaning |
| ---- | ---- | ----- | ------- |
| `data-blocks-engine-capture-disclosure-runtime` | attribute (flag) on `<script>` | ignored | The capture's disclosure interpreter. Retired only when its body is the recognized interpreter (below) and every binding is native-proven. |
| `data-blocks-engine-capture-disclosure-label` | attribute | text | Observed accessible label of a disclosure control. |
| `data-blocks-engine-capture-disclosure-open-` | attribute prefix | `<prefix><svg attribute>` = open-state value | SVG attribute value observed when open. |
| `data-blocks-engine-capture-disclosure-closed-` | attribute prefix | `<prefix><svg attribute>` = closed-state value | SVG attribute value observed when closed. |
| `data-blocks-engine-capture-local-disclosure` | attribute | `true` | The panel is driven by the capture's local disclosure shim. |
| `data-blocks-engine-capture-local-disclosure-runtime` | attribute (flag) on `<script>` | ignored | That shim; retired when every local disclosure is native. |
| `data-blocks-engine-capture-exclusive-disclosures` | attribute | `true` | Disclosures in the container are mutually exclusive. |

### Listboxes

| Name | Kind | Value | Meaning |
| ---- | ---- | ----- | ------- |
| `data-blocks-engine-capture-listbox-trigger` | attribute on `<button>` | binding key | Opens a captured listbox. |
| `data-blocks-engine-capture-listbox-panel` | attribute | binding key | The option panel; equals its trigger's key. |

### Galleries

See [Captured image galleries](../captured-galleries.md).

| Name | Kind | Value | Meaning |
| ---- | ---- | ----- | ------- |
| `data-blocks-engine-capture-gallery` | attribute (flag) | ignored | A captured gallery root. |
| `data-blocks-engine-capture-gallery-sequence` | attribute | JSON frame sequence | The observed frame cycle. |
| `data-blocks-engine-capture-gallery-slot` | attribute | integer | Image slot offset relative to the current frame. |
| `data-blocks-engine-capture-gallery-stage` | attribute (flag) | ignored | The gallery stage. |
| `data-blocks-engine-capture-gallery-initial` | attribute | integer | Initial frame of a gallery list. |
| `data-blocks-engine-capture-gallery-display` | attribute | CSS `display` keyword | Observed display of a gallery item. |
| `data-blocks-engine-capture-gallery-direction` | attribute on `<button>` | `-1` or `1` | Previous or next control. |
| `data-blocks-engine-capture-gallery-index` | attribute (emitted) | integer | Source frame index the transformer carries on an emitted gallery item. |
| `data-blocks-engine-capture-gallery-source` | attribute | selector | Binds the root to its interaction evidence. |
| `data-blocks-engine-capture-gallery-capture-width` | attribute | integer px | Viewport width the root was captured at. |
| `data-blocks-engine-capture-gallery-runtime` | attribute (flag) on `<script>` | ignored | The capture's gallery helper. May coexist with a retired disclosure interpreter only when its body is the recognized helper (below). |

### Collections and filters

See [Verified editable collection filters](../collection-filters.md).

| Name | Kind | Value | Meaning |
| ---- | ---- | ----- | ------- |
| `data-blocks-engine-capture-collection` | attribute | collection id | The item container. |
| `data-blocks-engine-capture-collection-field` | attribute | collection id | The search field. |
| `data-blocks-engine-capture-collection-category-control` | attribute | collection id | A category control. |
| `data-blocks-engine-capture-collection-index` | attribute | integer | Category index of a category control. |
| `data-blocks-engine-capture-collection-empty` | attribute | collection id | The empty state. |
| `data-blocks-engine-capture-collection-item` | attribute | item key | A collection item; must match the evidence key. |
| `data-blocks-engine-capture-collection-members` | attribute | JSON list of category indices | Category membership of an item. |
| `data-blocks-engine-capture-collection-status` | attribute | copy id | A status label. |
| `data-blocks-engine-capture-collection-runtime` | attribute (flag) on `<script>` | ignored | The capture's collection helper; retired once the collection is native. |
| `data-blocks-engine-capture-collection-visibility` | attribute (flag) on `<style>` | ignored | The capture's collection visibility rules; retired with the helper. |
| `data-blocks-engine-capture-status-template` | attribute (read and emitted) | text with `{count}`/`{query}` | Status label template. The native filter runtime writes the label from it. |
| `data-blocks-engine-capture-status-hide-zero` | attribute (flag) | ignored | The status label hides at a zero count. |
| `data-blocks-engine-capture-no-match` | probe token | — | A finite-bootstrap status template containing it is not projected. |
| `data-blocks-engine-capture-finite-probe` | probe token | — | A finite-bootstrap status template containing it is not projected. |

### Choice groups and native controls

See [Native control state](../native-control-state.md).

| Name | Kind | Value | Meaning |
| ---- | ---- | ----- | ------- |
| `data-blocks-engine-capture-choice-index` | attribute | integer | Capture-internal option index; stripped from projected choice markup. |
| `data-blocks-engine-capture-native-control-state` | attribute | JSON, version 1 | Observed native control state facts. Never reaches output. |
| `data-blocks-engine-capture-native-control-runtime` | attribute (flag) on `<script>` | ignored | The capture's native control helper. |

### Presentation and diagnostics

| Name | Kind | Value | Meaning |
| ---- | ---- | ----- | ------- |
| `data-blocks-engine-capture-glyph-color` | attribute | `#rrggbb` (any case) | Rendered glyph colour of an icon; read and removed. See [Social icon glyph colour](../glyph-color.md). |
| `data-blocks-engine-capture-anchor-unresolved` | attribute | text | An anchor the capture could not resolve; ignored when comparing render-equivalent markup. |

### Sidecar schemas

| Name | Kind | Meaning |
| ---- | ---- | ------- |
| `blocks-engine-capture/captured-interactions/v1` | `interaction-states.json` `schema` | Captured interaction report: dialogs, galleries, selectable sets, choice groups, filters. |
| `blocks-engine-capture/capture-receipt/v1` | capture receipt `schema` | Routes the report pages to captured documents. |
| `blocks-engine-capture/source-interactivity/v1` | receipt `sourceInteractivity.schema` | Source interactivity summary. |
| `blocks-engine-capture/finite-bootstrap/v1` | filter `finiteBootstrap.schema` | Finite collection bootstrap record. |
| `blocks-engine-capture/collection-status/v1` | filter `finiteBootstrap.status.schema` | Collection status labels. |

### Recognized capture runtimes

A runtime marker alone never establishes ownership of a script. The
transformer retires or tolerates a capture helper only when the sha256 of its
trimmed body is one of these. The bodies are published as
`tests/fixtures/capture-disclosure-runtime.js` and
`tests/fixtures/capture-gallery-runtime.js`.

| Name | Kind | Meaning |
| ---- | ---- | ------- |
| `3d350f5508bed44c931291f9f38e5d8d7c361d5d3ea729c51f1fe11b70ce75dc` | disclosure interpreter sha256 | The site-independent disclosure interpreter (`disclosure-runtime`). |
| `0a6bf62a6d9d6a04faf10e933d2362bbbdf3b5c8351c6452174f98bf29f723aa` | gallery helper sha256 | The gallery helper (`gallery-runtime`) that may coexist with it. |

## Equivalence

Moving to this vocabulary lost no behavior. The gate
`tests/contract/capture-evidence-equivalence.php` replays every entry-point
call the test suite made with the previous producer-specific names (a corpus
harvested from the last commit before the move), renamed by the published
rule, and requires byte-identical compile results, WordPress site plans,
projected files and exceptions from the pre-move source with the same rule
applied. See `tools/capture-evidence-equivalence/README.md` for how the
corpus and expectations were produced and how to regenerate them.
