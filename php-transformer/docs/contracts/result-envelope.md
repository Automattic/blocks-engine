# Result Envelope Contract

Transformer APIs should return a stable result envelope instead of product-specific arrays.

Required top-level keys for successful transformations:

```json
{
  "schema": "blocks-engine/php-transformer/result/v1",
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

Callers may ignore keys they do not need. The package should keep these names stable so Static Site Importer, Studio, WordPress.com, and wp-site-generator can share contract fixtures.
