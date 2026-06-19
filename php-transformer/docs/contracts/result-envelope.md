# Result Envelope Contract

Transformer APIs should return a stable result envelope instead of product-specific arrays.

Required top-level keys for successful transformations:

```json
{
  "schema": "blocks-engine/php-transformer/result/v1",
  "status": "success",
  "components": [],
  "block_types": [],
  "source_reports": {},
  "legacy_mapping": {},
  "blocks": [],
  "serialized_blocks": "",
  "documents": [],
  "assets": [],
  "diagnostics": [],
  "fallbacks": [],
  "provenance": [],
  "coverage": []
}
```

`status` is one of `success`, `success_with_warnings`, or `failed`. Artifact compiler callers should use `source_reports.artifact` for normalized input metadata such as entry path, accepted/rejected file counts, MIME/kind/role counts, and source HTML metrics.

`components` contains inspectable component candidates discovered before materialization. `block_types` contains generated block type artifacts promoted from `block.json` roots. `legacy_mapping` documents how this shared envelope maps back to predecessor result shapes, including the Block Artifact Compiler `wordpress_artifacts` contract, while migration is in progress.

Callers may ignore keys they do not need. The package should keep these names stable so Static Site Importer, Studio, WordPress.com, and wp-site-generator can share contract fixtures.
