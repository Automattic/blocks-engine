# Declared document-head runtime

The optional `document_metadata.head` contract uses schema
`blocks-engine/document-head/v1`. A source-owned `id`, `class`, or `data-*`
attribute on a head `meta`, `link`, or `style` requests ordered head
materialization. Ordinary descriptive metadata retains its existing routing.

```json
{
  "schema": "blocks-engine/document-head/v1",
  "elements": [
    {
      "tag": "meta",
      "attributes": {
        "name": "viewport",
        "content": "width=device-width, initial-scale=1",
        "data-selected-viewport": ""
      }
    },
    {
      "tag": "script",
      "attributes": {},
      "inline": true,
      "asset_reference": "{{wordpress-site-plan:asset:asset-example}}"
    }
  ]
}
```

## Materialization and evidence

- `elements` retains the mixed source order of head metadata, linked stylesheets,
  inline styles, and scripts. Styles retain separate DOM identities even when
  adjacent stylesheet payloads would otherwise coalesce.
- Attribute maps preserve source-owned attributes with bounded names and values:
  at most 512 elements, 32 attributes per element, 128-byte names and 8192-byte
  values. Event-handler attributes and embedded URL attributes use the owning
  declaration/asset contract instead of opaque attribute strings.
- Executable inline scripts and styles bind declared canonical assets. The
  emitted bootstrap prints their bodies inline, preserving parser-time behavior.
  External scripts and links retain their source semantics and token-bound URLs.
  Inert inline script data retains its body and the existing document metadata
  body hash, bounded to one MiB. Head script declarations must match the existing
  script-loading contract; strict dynamic-client-asset resolution still applies.
- Linked stylesheet rows also carry their source `link:nth-of-type(N)` occurrence
  so enqueue aliases can hand ownership to the actual emitted head element.
  Non-subresource document relationships, such as canonical links, resolve through
  the existing route map; stylesheet and preload URLs remain asset references.
- The theme bootstrap selects the route by reconciliation identity (with existing
  front-page/page-route fallback), installs the head emitter after template
  selection and emits at `wp_head` priority `-1`. A declared unique viewport
  replaces Core's `_block_template_viewport_meta_tag` callback. Head-owned
  scripts/styles are not independently emitted by the theme enqueue path.
- `DocumentHeadContext::fromPlan()` accepts only a validated site plan, including
  its exact generated `functions.php` write. It returns the same head DOM used by
  the materializer. Source markup, body block markup, and metadata with a missing
  bootstrap cannot independently prove a head target.
- Runtime parity evaluates after site-plan production. A head target gets
  `generated_target_evidence: declared_document_head` only when that plan emits
  it. Body/native-companion proof stays separate. A selector shared by head and
  body requires both regions to survive.

## Device-document source contracts

Source-owned scope and authored-media attributes travel unchanged. In particular,
`data-dla-document-scope` and `data-dla-source-media` are ordinary attribute data
to the head materializer; it contains no destination-specific selector logic.
The source selector owns device activation. A stored `media="not all"` can be
an inactive device's activation state, while `data-dla-source-media` records its
author condition. These are distinct values; materialization preserves both and
executes the source selectors in their declared head positions.

Editor/body presentation projection remains a separate gate. The head contract
does not certify that a consumer interprets device scope or author conditions in
its editor stylesheet projection.

## Public API additions

- Optional `document_metadata.head` and resolved head-element asset URLs.
- `RuntimeDependencyParityReport::fromArtifact()` accepts an optional final
  `wordpressSitePlan` argument; this supplies validated generated-head evidence.
- `WordPressSitePlanComposer::compose()` accepts an optional final runtime-parity
  callback so validation runs against the produced plan.
- `DocumentHeadContext` owns scan, validation, rendering, bootstrap, and proof.
  The existing script-type and entry-root policies are exposed as internal
  `WordPressSitePlan` helpers for that owning primitive.

## Verification and paired artifacts

From `php-transformer/`:

```sh
php tests/contract/document-head-runtime.php
composer test
php tools/document-head-runtime/build.php --output=<fresh-neutral-evidence>
php tools/document-head-runtime/build.php --input=<portable-artifact.json> --output=<fresh-source-evidence>
php tools/document-head-runtime/build.php --input=<portable-artifact.json> --autoload=<baseline>/vendor/autoload.php --output=<fresh-baseline-evidence>
```

Each build saves `input.json`, `result.json`, `summary.json`, and source files. A
materializable build additionally saves canonical/resolved plans, the complete
theme write tree, emitted head HTML, and a parser-contract page. The builder does
not activate a theme or mutate a WordPress site.

From `php-transformer/tools/visual-parity/`, after installing its dependencies:

```sh
npm run test:head
node tests/document-head-paired.mjs <portable-artifact.json> <fresh-browser-evidence>
```

The neutral browser gate checks actual parser execution before the body exists,
unique viewport selection, mixed script/style ordering, and distinct media/scope
attributes. The paired browser gate compares source and emitted head state under
desktop, phone and tablet user agents. It records full observations in
`browser-head-proof.json`. These are head-contract gates; a consumer still runs
its real WordPress import, runtime/browser, editor, body/layout and complete-site
parity gates.

## Consumer ownership handoff

A consumer overlay that already emits viewport or descriptive head metadata must
honor `document_metadata.head` ownership. A second content-only viewport callback
can duplicate or override the proven target. The compiler validates its own
bootstrap; downstream bootstrap overlays must retain that contract and be checked
against actual WordPress output.
