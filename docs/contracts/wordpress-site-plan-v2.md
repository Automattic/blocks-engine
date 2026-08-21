# WordPress Site Plan v2

`blocks-engine/wordpress-site-plan/v2` is the complete, destination-independent
block-theme materialization contract emitted at
`TransformerResult.source_reports.wordpress_site_plan` for artifact compilation.
The compiler emits `wordpress_site_plan_diagnostics` instead when an artifact retains
an undeclared local browser reference and therefore cannot honestly produce this
self-contained contract.

The public API is `WordPressSitePlan::fromResult()`, `WordPressSitePlan::assertValid()`,
and `WordPressSitePlanResolver::resolve()`.

## Canonical Plan

- `writes` declares every theme file, with unique normalized relative targets:
  `style.css`, `theme.json`, `functions.php` when asset enqueues are required,
  fallback `templates/index.html`, `templates/page.html` when pages exist,
  `templates/front-page.html` when an entry page exists, and each template part
  under `parts/`. Every materializable asset has exactly one write under `assets/`.
- `style.css` includes a valid theme header and `theme.json` is a valid minimal
   block-theme configuration. The generated bootstrap uses WordPress runtime APIs
   to enqueue CSS and declared document scripts.
- Reconciliation identities are stable SHA-256 values derived only from the
  canonical source identity and destination identity. Pages use source path plus
  canonical route; writes use source path plus target path. Assets, templates, and
  template parts follow the same source-plus-target rule. Mutable markup and file
  payloads never affect reconciliation identity; their deterministic
  `content_hash` or `payload_hash` provides change detection instead.
  For declarations, `kind`, `type` or `capability`, and `source_path` are immutable
  identity fields; provenance, requirements, and payload are mutable content.
- `quality.pass` is the canonical boolean quality predicate. It is true exactly
  when `quality.status` is `success` or `success_with_warnings`; metrics and
  fallbacks remain available for detailed policy.
- `runtime_declarations` is an optional, explicit, generic declaration collection.
  It is empty when absent from the artifact and is never inferred from markup or
  product semantics. Each declaration has a generic `kind`, exactly one `type` or
  `capability`, a safe `source_path`, source-derived `reconciliation_identity`, and
  optional schema-typed bounded `payload`. Entity collections use a payload with
  `schema` and `entities`; `required_for` links resolve to declared `kind:name`
  keys. Duplicate identities, unsafe paths, unresolved requirements, contradictory
  kinds, and non-serializable or overlarge payloads fail validation before plan
   emission. Declarations are carried unchanged after canonical normalization by
   compilation, resolution, reports, and package serialization.
- Provider entity block bindings use `generic/block-binding/v1`. In addition to
  materializer-compatible `search_block_markup` and `occurrence`, each binding
  carries a `blocks-engine/runtime-binding-position/v1` emitted-block identity:
  its pre-order `block_index` and exact canonical byte `offset` and `length`.
   This identity is derived from the emitted block sequence, never from text or
   class similarity. Repeated identical block markup remains distinct by position.
   Shared-shell extraction retains a shell in page content when it contains a
   binding anchor, so the materializer's page-owned binding contract is unchanged.
   Validation rejects a binding whose declared markup, occurrence, or position is
   detached from its owning page.
- `asset_publication` is the explicit declaration kind for materializing a declared
  artifact asset at an arbitrary destination. It has `type: asset`, a stable
  source-path reconciliation identity, explicit `destination.capability` and
  `destination.required`, source provenance and role, MIME, source and final
  content hashes, a sanitization proof bound to the source hash, and bounded
  `reference_targets`, each naming an existing write path and reconciliation
  identity plus an exact canonical asset-token occurrence in the supported
  `css_url` context. Every published SVG, transformed or plain, rejects external
  and dynamic references before plan emission. The
  resolver retains that canonical token provenance and supplies its current
  resolved URL; the canonical plan never invents a destination URL.
  `source_hash` is the immutable normalized-artifact source payload hash carried
  into the plan as `assets[].hash`; transformed final bytes are independently
  bound by `expected_content_hash` and the canonical write payload hash. Public
  validation re-binds both facts to the asset, write, and allowlisted provenance.
- An `asset_publication.transformation` can declare `svg_font_enrichment`.
  Its deterministic input hash covers declared local CSS/font source paths; the
  engine parses the narrowly allowed local `@font-face` declarations and its
  expected content hash covers the final SVG. Raw font-face payloads, remote
  inputs, unsafe SVG, undeclared inputs or writes, mismatched hashes,
  duplicate identities, and over-limit payloads are rejected. The engine fetches
  nothing: destination adapters declare supported generic capabilities and
  decide how to publish declared bytes. Unsupported required capabilities reject;
  unsupported optional capabilities are reported in the resolution projection.
- Pages, templates, and template parts carry `canonical_block_markup`. It is
  materialization-ready but destination-independent: every local asset reference
  is a declared `{{wordpress-site-plan:asset:...}}` token. It is not browser-ready
  markup. Resolution adds `resolved_block_markup` without changing canonical page
  data, and projects provider binding anchors to the same resolved page markup.
- `reference_tokens` is the only allowed token-to-artifact mapping. Each token maps
  to exactly one declared asset target. Validation rejects unsafe paths, missing
  scaffold writes, duplicate targets, missing asset writes, undeclared tokens, and
   template or part writes that do not match their declarations.
- `source.source_documents` is additive source evidence. Each row records a
  compiled document path, payload hash, and producer provenance. Template-surface
  variants must exactly match a catalog row. This evidence supports structural
  validation; it is not an authentication mechanism for a wholly forged plan.
- Every page has a canonical `route`: root `index.*` is `/`, `about.*` is `/about`,
  `nested/index.*` is `/nested`, and nested documents retain every directory segment.
  A declared lowercase `metadata.route_path` with the same safe shape is preserved as
  the explicit canonical route.
  This map is computed before document projection and is the sole input for exported
  `routes`, page hierarchy operations, document link and metadata-href rewriting,
  resolver/report projections, and page-scoped script conditions. Relative and
  root-relative document links retain query and fragment suffixes; asset references
  remain on the separate declared-asset token path.
  The plan rejects colliding, traversal, encoded-separator, and unsafe route identities.
  Missing directory parents are explicit synthetic pages with stable source and
  reconciliation identities; a physical directory index takes precedence over a
  synthetic parent.
- `operations` is an ordered generic desired-state list. A topologically ordered
  `create_page` operation declares each real or synthetic page's slug, route, parent
  source reference, and reconciliation identity. A following `site_reading` operation
  assigns the front page by its source path and reconciliation identity. Consumers apply
  resolved operations verbatim rather than inferring hierarchy or front-page behavior.
- Targets, slugs, and tokens use a case-insensitive collision policy. Producers
  retain their declared spelling, while plans reject two values that differ only by
  case so they materialize consistently on case-insensitive filesystems.
- Static browser references in markup and CSS (`src`, stylesheet `href`, `srcset`,
  `poster`, applicable `action`, `url()`, and `@import`) must be declared asset
  tokens or absolute/root-relative URLs. The canonical `functions.php` registers
  each supported document script once, uses `get_theme_file_uri()` for local writes,
  preserves query/fragment suffixes and supported external HTTP(S) URLs, and
  enqueues it only in its canonical scope: entry pages use `is_front_page()`, other
  pages compare the queried WordPress page URI with their normalized source route
  path, and bound template-part declarations are
  global shell declarations. Equivalent declarations shared by routes share one
  registration while each matching route enqueues it. Repeated declarations in one
  source document remain distinct execution declarations.
- Script source order is preserved by deterministic scope-local enqueue-hook priorities,
  not WordPress dependency edges. This avoids WordPress downgrading `async` or `defer` strategies.
  The scaffold uses native strategy support where available and a handle-scoped
  `script_loader_tag` filter for exact `type`, `nomodule`, integrity, CORS,
  referrer-policy, fetch-priority, and combined loading attributes.
- `dynamic_script_references` and `dynamic_client_assets.status` are both `proven`
  only when every declared script is materialized as a local artifact and local script writes contain no
  dynamic import, script injection, or runtime URL-construction signal. They are
  both `not_proven` with `materializer_may_reject: true` for inline scripts,
  external URLs, unsupported URLs, contradictory module/nomodule declarations, unbound part
  declarations, or dynamic-reference signals. The diagnostic code identifies the
  reason. Supported external URLs are still emitted for consumers that accept this
  risk, but cannot satisfy the proof gate. Consumers can therefore require proof without rejecting supported static
  script plans.

## Runtime Resolution

The compiler cannot infer a deployed theme URL. Pass it explicitly:

```php
$resolved = (new WordPressSitePlanResolver())->resolve($plan, array(
    'theme_uri' => 'https://example.test/wp-content/themes/generated-site',
    'approved_plan_hash' => WordPressSitePlan::canonicalHash($plan),
));
```

`theme_uri` must be an absolute HTTP(S) URL with authority and an optional
unambiguous path. Credentials, query strings, fragments, control characters,
backslashes, dot segments, and encoded path separators are rejected. The resolver
normalizes only scheme, host, optional port, and trailing path slash. It replaces
only declared tokens in markup and UTF-8 write payloads. Materializers write
`resolved['writes']` and apply `resolved['operations']` verbatim; they never rewrite
HTML, CSS, scripts, or URLs.
Resolution returns a schema-tagged `resolution` projection with the normalized
`theme_uri`. Every UTF-8 resolved write retains its canonical payload and hash; the
resolved payload and hash must equal the canonical token replacement for that context.
Resolved page, template, and part markup follows the same rule. A plan without this
projection retains canonical tokenized writes, and an arbitrary `resolution` field
cannot alter those invariants.
Approval and materialization systems retain `approved_plan_hash` outside the plan
and pass it at resolution. The resolver rejects a canonical plan that differs from
that approved identity. This hash is an external integrity comparison, not a
signature or standalone authentication proof.
Consumers requiring proven dynamic client assets pass
`require_proven_dynamic_client_assets => true` and receive a deterministic rejection.

## Migration From v1

v1 was additive but incomplete: it emitted no block-theme scaffold or templates and
left source-relative asset URLs for consumers to interpret. It is not compatible
with v2 and consumers must switch to `canonical_block_markup`, resolve with explicit
destination context, and materialize the resolved writes. This is a breaking public
contract correction from the pre-1.0 `0.3.0` release and warrants the next minor
package version, `0.4.0`.

## Lifecycle Migration

Earlier v2 previews derived page reconciliation identity from block markup. Consumers
migrating from that mutable identity must reconcile existing records through explicit
legacy metadata where they retain it, then persist the v2 source-and-route identity
as the primary key. A content hash change with the same reconciliation identity is an
update, not a new page or write. New runtime requirements must be supplied as explicit
artifact `runtime_declarations`; the engine intentionally does not infer them.
Existing consumers can continue using declarations other than `asset_publication`.
Consumers adding publication intent provide explicit source and sanitization hashes
rather than relying on file extension inference.
