# WordPress Site Plan v2

`blocks-engine/wordpress-site-plan/v2` is a destination-independent materialization contract. A consumer resolves it with `WordPressSitePlanResolver` and combines the resolved plan with its own materialization receipt. Destination IDs, paths outside declared writes, and product report formats remain consumer-owned.

## Document Metadata

Every page and template part has `document_metadata`. It is normalized compiler output, not source HTML:

```php
array(
    'source_context' => array('source_path' => 'nested/about.html', 'kind' => 'html'),
    'title' => 'About',
    'title_declaration' => array('order' => 0, 'placement' => 'head'),
    'meta' => array(),
    'links' => array(),
    'scripts' => array(),
)
```

`meta`, `links`, and `scripts` are ordered source rows. Their zero-based `order` equals their array index. `placement` is `head` or `body`; `title_declaration` always has `order: 0` and `placement: head`. `source_context` identifies the compiler document that supplied the declarations.

## Page Routes

Each page has `route.path`, `route.parent_path`, and `route.slug`. The route is derived
from the normalized source path: root `index.*` is `/`, `nested/index.*` is `/nested`,
and `nested/about.*` is `/nested/about`. `operations` first provides topologically
ordered `create_page` rows, including parent source references and reconciliation
identities, then the `site_reading` front-page operation. Missing directory parents are
declared synthetic pages, so materializers create the same hierarchy without inferring
or manually reparenting pages. A physical directory index replaces the corresponding
synthetic parent; route collisions fail closed. A safe lowercase `metadata.route_path`
is preserved when the source contract declares an explicit canonical route.
The canonical route map is computed before page, link, metadata, operation, resolver,
report, and script-scope projection. It rewrites relative and root-relative document
links while preserving query and fragment suffixes; declared asset references continue
to use asset tokens rather than page routes.

Meta rows preserve `charset`, `name`, `property`, `http_equiv`, and `content`. Link rows preserve `rel`, `type`, `media`, `integrity`, `crossorigin`, `referrerpolicy`, `as`, `fetchpriority`, and `sizes`. Script rows preserve `type`, `integrity`, `crossorigin`, `referrerpolicy`, and `fetchpriority`, plus independent booleans for `async`, `defer`, `module`, and `nomodule`. Explicitly present empty values are retained as `''` so consumers can distinguish them from absent attributes, except `crossorigin`: its empty or boolean HTML state is normalized to `anonymous`, matching browser CORS semantics. `effective_loading` records browser loading semantics: `async` wins over `defer`; non-async module scripts are `defer`; other scripts are `blocking`. Inline scripts carry `source_kind: inline` and `body_hash`, not their source body.

URL-bearing link and external-script declarations contain either an explicit absolute or protocol-relative `url`, or an `asset_reference` token. Local artifact URLs must use `asset_reference`; undeclared local URLs are invalid. Resolver output adds `resolved_url` for each `asset_reference`. That URL is exactly the URL of a declared resolved theme-asset write. Explicit external URLs remain unchanged.

Document metadata is canonical runtime input as well as reporting data. The generated
theme scaffold registers supported local-write and external HTTP(S) script declarations
once, preserves their normalized loading and tag attributes, and enqueues them only for
their source scope. Entry-page declarations run on `is_front_page()`, other page
declarations compare the queried page URI with their normalized source route path, and bound template-part declarations run
as global shell scripts. The scaffold preserves declaration order through scope-local
enqueue-hook priorities, without dependencies that would change WordPress loading strategy.

`reference_semantics.dynamic_script_references` and
`dynamic_client_assets.status` are both `proven` for fully materialized static script
declarations. They are both `not_proven` with `materializer_may_reject: true` when
inline source, an external or unsupported URL or contradictory declaration, an unbound template
part, dynamic import, script injection, or runtime URL construction prevents complete
proof. The accompanying diagnostics are the deterministic explanation. Consumers that
set `require_proven_dynamic_client_assets` are rejected only for that unproven state.
Supported external URLs remain in the generated scaffold for callers that accept the
runtime-reference risk, but cannot pass that proof gate.

## Reporting

`reporting` is a compiler-output summary:

```php
array(
    'source_documents' => array(
        array(
            'source_path' => 'index.html',
            'kind' => 'html',
            'body_format' => 'blocks',
            'block_document' => true,
            'provenance' => array(),
        ),
    ),
    'metrics' => array(
        'source_document_count' => 1,
        'block_document_count' => 1,
        'native_block_count' => 0,
        'fallback_count' => 0,
    ),
    'diagnostic_codes' => array(),
)
```

It provides generic source-document identity, native/block and fallback metrics, provenance, and diagnostic linkage. It intentionally excludes destination IDs, filesystem locations, and consumer report paths. A consumer can project its own stable report from the resolved plan and a receipt that confirms every declared write and page reconciliation identity.
