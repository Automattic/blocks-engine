# Native generated-theme lifecycle

A generated WordPress theme supports content and routes created after capture,
as well as its captured pages. Canonical plans now supply single, archive, and
404 defaults alongside index/search/page/front-page templates.

- **Single:** a page-only import gains a native title/content surface for future
  posts. Captured post article chrome and captured body rendering remain
  authoritative where present.
- **Archive:** contextual archive title, inherited query, pagination, and
  no-results blocks. It does not substitute an unscoped post feed.
- **404:** a genuine missing-page heading and native search recovery, without
  an archive query loop.

`NATIVE_TEMPLATE_SLUGS` and `NATIVE_QUERY_TEMPLATE_SLUGS` provide shared-shell
placement families. Source-proven shared chrome reaches the new native routes;
captured page/entry-specific chrome retains its source-scoped rules.

Native defaults record `source_relation: generated_native_lifecycle`. They are
generated destination behavior, not evidence of a captured source 404/archive.
Explicit source template surfaces replace defaults at the same slug; ambiguous
duplicate explicit declarations still fail through the existing source-surface
validation. All defaults use core blocks and introduce no core/html fallbacks.

Verification: `tests/contract/native-template-lifecycle.php`, the canonical
site-plan/shared-shell/template-surface contracts, and the SSI disposable
WordPress oracle with this compiler mounted as a dependency overlay. SSI owns
the corresponding managed classic PHP scaffold; it consumes the block plan
without independently reconstructing these templates.
