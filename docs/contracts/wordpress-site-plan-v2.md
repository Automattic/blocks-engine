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
- Pages, templates, and template parts carry `canonical_block_markup`. It is
  materialization-ready but destination-independent: every local asset reference
  is a declared `{{wordpress-site-plan:asset:...}}` token. It is not browser-ready
  markup. Resolution adds `resolved_block_markup` without changing canonical data.
- `reference_tokens` is the only allowed token-to-artifact mapping. Each token maps
  to exactly one declared asset target. Validation rejects unsafe paths, missing
  scaffold writes, duplicate targets, missing asset writes, undeclared tokens, and
  template or part writes that do not match their declarations.
- `operations` is an ordered generic desired-state list. An entry page produces one
  `site_reading` operation that assigns the front page by its source path and
  reconciliation identity. Consumers apply resolved operations verbatim rather than
  inferring front-page behavior.
- Targets, slugs, and tokens use a case-insensitive collision policy. Producers
  retain their declared spelling, while plans reject two values that differ only by
  case so they materialize consistently on case-insensitive filesystems.
- Static browser references in markup and CSS (`src`, stylesheet `href`, `srcset`,
  `poster`, applicable `action`, `url()`, and `@import`) must be declared asset
  tokens or absolute/root-relative URLs. The canonical `functions.php` registers
  each supported document script once, uses `get_theme_file_uri()` for local writes,
  preserves query/fragment suffixes and supported external HTTP(S) URLs, and
  enqueues it only in its canonical scope: entry pages use `is_front_page()`, other
  pages use their declared page slug, and bound template-part declarations are
  global shell declarations. Equivalent declarations shared by routes share one
  registration while each matching route enqueues it. Repeated declarations in one
  source document remain distinct execution declarations.
- Script source order is preserved by deterministic scope-local enqueue-hook priorities,
  not WordPress dependency edges. This avoids WordPress downgrading `async` or `defer` strategies.
  The scaffold uses native strategy support where available and a handle-scoped
  `script_loader_tag` filter for exact `type`, `nomodule`, integrity, CORS,
  referrer-policy, fetch-priority, and combined loading attributes.
- `dynamic_script_references` and `dynamic_client_assets.status` are both `proven`
  only when every declared script is materialized and local script writes contain no
  dynamic import, script injection, or runtime URL-construction signal. They are
  both `not_proven` with `materializer_may_reject: true` for inline scripts,
  unsupported URLs, contradictory module/nomodule declarations, unbound part
  declarations, or dynamic-reference signals. The diagnostic code identifies the
  reason. Consumers can therefore require proof without rejecting supported static
  script plans.

## Runtime Resolution

The compiler cannot infer a deployed theme URL. Pass it explicitly:

```php
$resolved = (new WordPressSitePlanResolver())->resolve($plan, array(
    'theme_uri' => 'https://example.test/wp-content/themes/generated-site',
));
```

`theme_uri` must be an absolute HTTP(S) URL with authority and an optional
unambiguous path. Credentials, query strings, fragments, control characters,
backslashes, dot segments, and encoded path separators are rejected. The resolver
normalizes only scheme, host, optional port, and trailing path slash. It replaces
only declared tokens in markup and UTF-8 write payloads. Materializers write
`resolved['writes']` and apply `resolved['operations']` verbatim; they never rewrite
HTML, CSS, scripts, or URLs.
Consumers requiring proven dynamic client assets pass
`require_proven_dynamic_client_assets => true` and receive a deterministic rejection.

## Migration From v1

v1 was additive but incomplete: it emitted no block-theme scaffold or templates and
left source-relative asset URLs for consumers to interpret. It is not compatible
with v2 and consumers must switch to `canonical_block_markup`, resolve with explicit
destination context, and materialize the resolved writes. This is a breaking public
contract correction from the pre-1.0 `0.3.0` release and warrants the next minor
package version, `0.4.0`.
