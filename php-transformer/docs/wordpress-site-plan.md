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

Meta rows preserve `charset`, `name`, `property`, `http_equiv`, and `content`. Link rows preserve `rel`, `type`, `media`, `integrity`, `crossorigin`, `referrerpolicy`, `as`, `fetchpriority`, and `sizes`. Script rows preserve `type`, `integrity`, `crossorigin`, `referrerpolicy`, and `fetchpriority`, plus independent booleans for `async`, `defer`, `module`, and `nomodule`. `effective_loading` records browser loading semantics: `async` wins over `defer`; non-async module scripts are `defer`; other scripts are `blocking`. Inline scripts carry `source_kind: inline` and `body_hash`, not their source body.

URL-bearing link and external-script declarations contain either an explicit absolute or protocol-relative `url`, or an `asset_reference` token. Local artifact URLs must use `asset_reference`; undeclared local URLs are invalid. Resolver output adds `resolved_url` for each `asset_reference`. That URL is exactly the URL of a declared resolved theme-asset write. Explicit external URLs remain unchanged.

Document metadata is reporting-only. It preserves source declaration facts for consumers that need reports or manifests; it does not alter generated theme bootstrap behavior or claim runtime execution parity.

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
