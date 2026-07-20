# WordPress Site Plan v1

`blocks-engine/wordpress-site-plan/v1` is the additive, self-contained materialization handoff emitted at `TransformerResult.source_reports.wordpress_site_plan` for artifact compilation.

The public API is `WordPressSitePlan::fromResult()` and `WordPressSitePlan::assertValid()`.

- `pages`, `templates`, and `template_parts` carry final serialized block markup, post/parent metadata, template-part area, and deterministic reconciliation identities.
- `assets` preserves canonical source/target identities for all compiled assets. `writes` carries materializable theme-asset payloads with a relative target path, `utf8` or `base64` payload encoding, media/hash metadata, and load metadata.
- `routes`, navigation, menus, rewrite candidates, theme, and visual-repair data retain the materialization semantics required without consulting `compiled_site`.
- `source`, `diagnostics`, and `quality` preserve source identity/provenance and compiler outcome metadata.

The v1 projection emits an empty `templates` list because ArtifactCompiler does not currently infer reusable full templates. It does not generate base theme files. Projection fails when a compiled page or template part lacks final block markup or a safe source identity. Validation rejects unsupported schemas, unsafe POSIX/Windows/UNC paths, invalid encodings, and malformed nested rows.
