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
  the namespace; the transformer ignores names it does not read.
- Attribute values are case-sensitive and trimmed unless noted. A flag
  attribute is present-or-absent; its value is ignored.

## Names

Two audiences read this vocabulary:

- **Consumed by Blocks Engine**: every section below except the last. The
  transformer reads (and, where marked, emits) these names.
- **Published for downstream importers**: the last section. Blocks Engine
  never reads these names; it publishes them so importers such as Static Site
  Importer and every producer share one spelling. They are flagged in
  `CaptureEvidence::PUBLISHED_FOR_DOWNSTREAM`, and the contract test fails if
  the transformer starts reading one without moving it to a consumed section.

### Prefixes

| Name | Kind | Meaning |
| ---- | ---- | ------- |
| `data-blocks-engine-capture-` | attribute prefix | Every capture attribute. Author selectors negating a capture attribute on `html`/`:root` are runtime document state and stay live. |
| `--data-blocks-engine-capture-` | id suffix prefix | A non-default document variant's copy of a shared source id: `<id>--data-blocks-engine-capture-mobile`, or `<id>--data-blocks-engine-capture-<variant>` for `site-document-variant-<variant>` and declared device documents. The transformer applies the same suffix and points variant-scoped author id selectors at it. |

### Emoji runtime

| Name | Kind | Value | Meaning |
| ---- | ---- | ----- | ------- |
| `data-blocks-engine-capture-runtime-emoji` | attribute on `<html>` | `wordpress` (exact) | Capture restored WordPress runtime emoji images to their alt text. If any compiled page carries this value, the generated theme keeps core's emoji runtime, including editor support. Otherwise native emoji is preserved as before. Texturization remains disabled in both cases. |

### Document variants

| Name | Kind | Value | Meaning |
| ---- | ---- | ----- | ------- |
| `data-blocks-engine-capture-document-scope` | attribute (flag) | ignored | The element is a body-equivalent document root. With `device-document` it declares a device profile. |
| `data-blocks-engine-capture-device-document` | attribute | `[a-z][a-z0-9_-]{0,63}` | Profile id of a declared document scope. |
| `data-blocks-engine-capture-mobile-document` | class | — | Root of the mobile copy of a responsive capture. |
| `data-blocks-engine-capture-desktop-document` | class | — | Root of the desktop (default) copy of a responsive capture. |
| `data-blocks-engine-capture-responsive-counterpart-` | class prefix | 12 lowercase hex digits | Pairs an element in the default document with its counterpart in another variant. |
| `data-blocks-engine-capture-responsive-source` | attribute | source id | Source identity of a responsive counterpart; ignored when comparing shared chrome. |
| `data-blocks-engine-capture-device-style` | attribute on `<style>`/`<link>` | space-separated profile ids | Device profiles that activate the stylesheet. Document occurrences and their projected slices retain these keys in generated frontend links. |
| `data-blocks-engine-capture-source-media` | attribute on `<style>`/`<link>` | media query | The authored media condition, independent of a serialized `media` that only records the inactive document's activation. |

### Dialogs and dropdowns

| Name | Kind | Value | Meaning |
| ---- | ---- | ----- | ------- |
| `data-blocks-engine-capture-dialog-trigger` | attribute | binding key | The control that opens a captured panel. |
| `data-blocks-engine-capture-dialog-panel` | attribute | binding key | The captured panel; equals its trigger's key. |
| `data-blocks-engine-capture-dialog-close` | attribute | binding key | A close control for that dialog. |
| `data-blocks-engine-capture-dialog-panel-state` | attribute on the trigger | JSON | Snapshot of the opened panel state. |
| `data-blocks-engine-capture-existing-panel` | attribute on an existing panel | JSON `{resting, opened}` snapshots | Preserves resting and opened `style`, `class`, and `hidden` values, and optionally resting `html`; consumers restore the resting values before static projection and after open/close replay. |
| `data-blocks-engine-capture-resting-panel` | attribute on an existing panel | presence | Replay has exposed the resting presentation. Together with a native navigation container and its resting class snapshot, proves that Core can replace the captured controls. |
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
| `data-blocks-engine-capture-gallery-frame` | attribute | integer | One captured frame inside the gallery stage; the gallery runtime toggles its `hidden` state. |
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
| `data-blocks-engine-capture-choice-group` | attribute | integer | Config key of a choice group; its transitions are in the choice runtime. Stripped from projected markup. |
| `data-blocks-engine-capture-choice-index` | attribute | integer | Capture-internal option index; stripped from projected choice markup. |
| `data-blocks-engine-capture-choice-runtime` | attribute (flag) on `<script>` | ignored | The capture's choice-group runtime. Its `configs[key].transitions[index]` is the group's markup after selecting choice `index`. Retired when its body is the recognized runtime (below) and every marked group projects. |
| `data-blocks-engine-capture-inert-control` | attribute on a control | `true` | Activation had no observable effect during capture. Treat as static markup, not lost behavior; stripped from output. Other values do not establish inertness. |
| `data-blocks-engine-capture-native-control-state` | attribute | JSON, version 1 | Observed native control state facts. Never reaches output. |
| `data-blocks-engine-capture-native-control-runtime` | attribute (flag) on `<script>` | ignored | The capture's native control helper. |

### Presentation and diagnostics

| Name | Kind | Value | Meaning |
| ---- | ---- | ----- | ------- |
| `data-blocks-engine-capture-glyph-color` | attribute | `#rrggbb` (any case) | Rendered glyph colour of an icon; read and removed. See [Social icon glyph colour](../glyph-color.md). |
| `data-blocks-engine-capture-anchor-unresolved` | attribute | text | An anchor the capture could not resolve; ignored when comparing render-equivalent markup. |
| `data-blocks-engine-capture-viewport-entrance` | attribute | JSON | Recorded entrance states of an element the source reveals as it scrolls into view; layout shells carrying it are labelled "Scroll reveal". |
| `data-blocks-engine-capture-viewport-entrances` | attribute | presence | Marks the capture's own entrance activator `<script>`. It addresses entrance evidence, not source behavior, so runtime-selector analysis excludes it. |

### Component replay

| Name | Kind | Value | Meaning |
| ---- | ---- | ----- | ------- |
| `data-blocks-engine-capture-tabs` | attribute (flag) | ignored | Captured semantic tablist, with observed lazy panels materialized beside it. |
| `data-blocks-engine-capture-component-runtime` | attribute on `<script>` | ignored | Portable tab replay helper; retired only when its exact recognized body is replaced by native tabs. |
| `data-blocks-engine-capture-component-carousel-runtime` | attribute on `<script>` | ignored | Component carousel replay helper, with state HTML and click transitions in its config. |

### Sidecar schemas

| Name | Kind | Meaning |
| ---- | ---- | ------- |
| `blocks-engine-capture/captured-interactions/v2` | `interaction-states.json` `schema` | Component-owned interaction states and replay evidence (see below). |
| `blocks-engine-capture/capture-receipt/v1` | capture receipt `schema` | Routes the report pages to captured documents. |
| `blocks-engine-capture/source-interactivity/v1` | receipt `sourceInteractivity.schema` | Source interactivity summary. |
| `blocks-engine-capture/finite-bootstrap/v1` | filter `finiteBootstrap.schema` | Finite collection bootstrap record. |
| `blocks-engine-capture/collection-status/v1` | filter `finiteBootstrap.status.schema` | Collection status labels. |

### Runtime scripts

The disclosure body is the current DLA producer interpreter, including its authored
resting-CSS check for reused non-dropdown panels with concealed triggers. Such
panels can remain visible inline on desktop while opening as overlays on mobile.
Blocks Engine currently keeps that page's runtime and wiring unchanged and emits
`captured_disclosure_resting_inline_runtime_kept`: its native dialog replacement
does not reproduce this responsive presentation. This applies by capture evidence,
not by framework class names, including when the panel is hidden at capture width.


A capture may ship helper scripts that keep captured interactions working in
the static page. Blocks Engine replaces them with native blocks only when it
can prove it owns them, and a marker attribute alone never proves that. The
body has to be the exact published script.

**Producer requirement.** Emit each helper as one inline `<script>` that
carries its marker attribute, with the **exact** published body as its text
content. Write the body byte for byte from the fixture file. Do not minify,
reformat, template or concatenate it. Leading and trailing whitespace is
allowed.

| Name | Kind | Meaning |
| ---- | ---- | ------- |
| `086fc8248547e7c1109e0f6a28e691cea966f7057b3e36eaa8ccad177d2cf737` | sha256 of the disclosure interpreter | Body: [`tests/fixtures/capture-disclosure-runtime.js`](../../tests/fixtures/capture-disclosure-runtime.js), marker `data-blocks-engine-capture-disclosure-runtime` (value `true`), in `<head>`. |
| `456af76323601e51d851d3a4d6fe4ff6e3989c9969562f425fd72103c5c57c6b` | sha256 of the gallery helper | Body: [`tests/fixtures/capture-gallery-runtime.js`](../../tests/fixtures/capture-gallery-runtime.js), marker `data-blocks-engine-capture-gallery-runtime`, in `<head>`. |
| `82806634d813434b72ca5cbc9532c88f26aa4bd3cec880c2e6c12580dd6849eb` | sha256 of the choice-group runtime | Body: [`tests/fixtures/capture-choice-runtime.js`](../../tests/fixtures/capture-choice-runtime.js) with the JSON config list in place of its empty config list (`var configs=[];`); the digest covers the body with the empty list, marker `data-blocks-engine-capture-choice-runtime` (value `true`), in `<head>`. |

Blocks Engine recognizes one body per runtime: the producer's current one.
When the producer changes a body, Blocks Engine checks that its native
replacement does what the new body does, then publishes the new body and
digest in place of the old ones. An earlier body is no longer recognized: a
page that carries it keeps its captured runtime wherever recognition is
required (below).

What the native replacement reproduces:

- **Disclosure interpreter.** Opening and closing a dialog (including Escape
  and the trigger's `aria-expanded`), and the ancestor bindings in
  `data-blocks-engine-capture-dialog-ancestor-state`. A binding row may change
  `class`, `style`, `hidden`, `aria-*` and `data-*` attributes: the set the
  capture records and the interpreter replays. A `class` binding is replayed
  as tokens: tokens of the other state that the target state lacks are
  removed, the target state's tokens are added, and every other class on the
  ancestor is kept; an emptied `class` whose recorded value is null is
  removed. `hidden`, `aria-*` and `data-*` are set to the recorded value, or
  removed when it is null, on open, on close and, as the interpreter does on
  load, to their closed values when the page starts. The native dialog finds
  the ancestor by its tag and closed class tokens, preferring the one whose
  other recorded attributes hold their closed values.
  `tests/unit/capture-runtime-ancestor-parity-browser.mjs` (class rows) and
  `tests/unit/capture-runtime-attribute-parity-browser.mjs` (attribute-only
  rows) compare the two in Chromium.

  The replayable attributes are the shared allowlist
  `CapturedDialogBlockGenerator::ANCESTOR_ATTRIBUTE_PATTERN`, applied by the
  projector, the block converter and the view script alike. It never admits an
  event handler (`on*`) or a URL-bearing attribute (`href`, `src`, ...): the
  capture does not record them and the interpreter never replays them. It also
  excludes Blocks Engine's own `data-blocks-engine-*` namespace, which holds
  the native dialog's state and capture evidence rather than source state. A
  row naming any other attribute is dropped.

  A projected dialog stays a native dialog block even when its trigger has a
  text label and its panel has the shape of a source aria disclosure.
  `core/details` cannot close on Escape, keep `aria-expanded` or replay
  ancestor bindings, so it is chosen only for disclosures that no capture
  runtime drives, where it loses nothing.
  Existing dropdown panels with a resting `html` snapshot retain both observed
  content states as editable blocks. The native wrapper shows opened content
  while open and restores closed content, class and style on close. Reused
  non-dropdown inline panels still retain the capture runtime as noted above.
- **Gallery helper.** Frame galleries become native carousels. A src-swap
  gallery (`data-blocks-engine-capture-gallery-sequence` with
  `data-blocks-engine-capture-gallery-slot` images) becomes a native src-swap
  carousel. It starts at index 0, wraps in both directions, sets each slot to
  the sequence image at the current index plus the slot's offset, and removes
  the slot's `srcset`.

**How Blocks Engine verifies them.** A script is a recognized runtime when
it carries a marker attribute and `sha256(trim(textContent))` equals the
published digest for that marker, where `trim` strips spaces, tabs, CR, LF,
NUL and vertical tab at both ends
(`CapturedDialogProjector::isRecognizedRuntimeScript()`). Every retirement
path uses that check: native dialogs projected from captured interactions,
wired dropdowns in a document scope, navigation submenus (with or without an
interaction report) and native galleries.

1. A page that carries a marked script whose body is not recognized keeps
   that runtime and the wiring it drives: Blocks Engine projects no native
   dialog, gallery or submenu replacement on it and retires nothing.
2. Otherwise only recognized scripts are retired, each with a recorded
   replacement proof (`native_runtime_replacements`, carrying the body
   digest).
3. Wired dropdowns in a declared document scope additionally require the
   recognized interpreter itself, and no other executable script (classic or
   module) apart from the native-control helper
   (`data-blocks-engine-capture-native-control-runtime`).

With a recognized interpreter and every trigger/panel binding proven local and
bounded, the disclosures become native dialog blocks and the interpreter is
retired with a recorded replacement. Any mismatch leaves the capture's script
and markup in place. That page then keeps working through the capture's own
runtime and loses no content; it just gets no native replacement.

To check a body, run `php -r 'echo hash("sha256", trim(file_get_contents($argv[1])));' <file>`.
`tests/contract/capture-evidence-vocabulary.php` holds the published
constants to these fixture digests.

### Interaction component contract

`interaction-states.json` has `schema` and `pages`. Each page retains `sourceUrl`,
`viewport`, `capturedAt` and optional route metadata, but carries **only** a
`components` inventory, never a flat interaction inventory. A component has:

- `selector`: source owner selector;
- `kind`: `tabs`, `disclosure`, `dialog`, `listbox`, `carousel`, `menu`, `filter`, `choice-group` or `unsupported`;
- `initial`: the initial observed state id;
- `states`: observed `{ id, html, evidence? }` nodes; probe replay facts belong to `evidence`;
- `transitions`: `{ from, to, trigger: { selector, action: "click" } }` edges;
- `status`: `captured`, `partial` or `unsupported`, with optional `reason`.

Projectors select component kinds and consume state-owned replay facts. They do
not read another trigger inventory. Semantic tabs use observed panel HTML; lazy
panels are sanitized before native tab projection. Semantic carousels use only
observed active slides. Native details retain their authored `open` state.
Unsupported components never materialize hidden content, even if they carry probe
evidence. Partial nodes grant no unobserved states or complete-coverage claim.

This is a breaking, lockstep replacement of the retired flat interaction reader.
Refs Automattic/blocks-engine#3709. This change must ship together with
Automattic/data-liberation-agent#1039.

### Published for downstream importers

Blocks Engine does not read these names. Downstream importers do.

| Name | Kind | Value | Meaning |
| ---- | ---- | ----- | ------- |
| `data-blocks-engine-capture-component-disclosure` | attribute | config index | Non-native disclosure replay config binding; native details retain authored open state. |
| `data-blocks-engine-capture-component-disclosure-runtime` | attribute on `<script>` | ignored | Portable non-native disclosure helper. |
| `data-blocks-engine-capture-device-selection` | attribute (flag) on `<script>` | ignored | The capture's device-selection bootstrap in `<head>`. |
| `data-blocks-engine-capture-device-body` | attribute (flag) on `<script>` | ignored | The capture's device-document overlay script at the start of `<body>`. |
| `data-blocks-engine-capture-selected-document` | attribute on `<html>` | profile id | The device profile the selection runtime chose. Written at runtime, so author selectors on it stay live. |
| `data-blocks-engine-capture-selected-viewport` | attribute (flag) on `<meta name="viewport">` | ignored | The viewport meta the selection runtime fills. |
| `data-blocks-engine-capture-document-unavailable` | attribute (flag) on `<html>` | ignored | Set at runtime when no captured document applies to the device. |
| `data-blocks-engine-capture-member-login` | attribute | provider `[a-z0-9_-]+` | The link leads to a member login with that provider. |
| `data-blocks-engine-capture-member-login-` | class prefix | `<prefix><provider>`, provider `[a-z0-9_-]+` | Class form of the member-login marker. It survives block conversion, which keeps classes. |

### Collection component nodes

| Attribute | Meaning |
| --- | --- |
| `data-blocks-engine-capture-unsupported` | Unsupported component function. |

The v2 `kind: collection` node carries `collection.members` (stable key, text,
optional URL and source member HTML), observed `states` (id, optional selector /
action / value trigger, ordered member keys), `controls`, `coverage`, `limits`,
and the producer's `replay` capability. The producer's `replay: unsupported`
does not prohibit consumer materialization from complete membership evidence.
`data-blocks-engine-capture-unsupported="collection"` remains an honest loss
marker unless the owning complete node can be projected.

Only captured, complete nodes are admitted. Partial/unsupported evidence and
bounded inventories retain their original tree and a loss finding. Source
member HTML becomes one editable native block tree in first-observed order.
Observed filter values select their exact recorded sets; empty/reset values
restore the union. Search samples do not prove a server predicate: the shared
collection filter runtime instead performs local normalized text search over
editable captured content and supplies an explicit no-matching-captured-records
state. This is not a promise to search uncaptured records.

Pagination is removed in favor of the complete captured list. Page probes
prove membership, not stable page boundaries across filter/search states;
therefore no source pagination behavior is invented. The obsolete portable
annotation-only collection filter projector is retired; existing verified
legacy typed-search evidence continues through the canonical collection
projector and the same editable runtime.
