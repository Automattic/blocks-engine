# WordPress Site Plan v1 (Superseded)

`blocks-engine/wordpress-site-plan/v1` is the additive, self-contained materialization handoff emitted at `TransformerResult.source_reports.wordpress_site_plan` for artifact compilation.

This contract was superseded by [WordPress Site Plan v2](wordpress-site-plan-v2.md).

- `pages`, `templates`, and `template_parts` carry final serialized block markup, post/parent metadata, template-part area, and deterministic reconciliation identities.
- `assets` preserves canonical source/target identities for all compiled assets. `writes` carries materializable theme-asset payloads with a relative target path, `utf8` or `base64` payload encoding, media/hash metadata, and load metadata.
- `routes`, navigation, menus, rewrite candidates, theme, and visual-repair data retain the materialization semantics required without consulting `compiled_site`.
- `source`, `diagnostics`, and `quality` preserve source identity/provenance and compiler outcome metadata.

v1 emitted no block-theme scaffold or templates and left local asset URLs unresolved.
It is retained here only as migration documentation; new consumers must use v2.
