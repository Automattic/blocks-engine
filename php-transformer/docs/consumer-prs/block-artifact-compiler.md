# Consumer PR Plan: block-artifact-compiler

## Phase-1 PR Goal

Keep `chubes4/block-artifact-compiler` as the public compatibility package for website artifact compilation while delegating canonical compilation to `Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler`.

## Composer Change

During review, add the transformer path repository and requirement:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "/Users/chubes/Developer/blocks-engine@cook-php-transformer-consumer-prep/php-transformer",
      "options": {
        "symlink": true
      }
    }
  ],
  "require": {
    "php": ">=8.1",
    "automattic/blocks-engine-php-transformer": "dev-cook/php-transformer-consumer-prep"
  }
}
```

Before merge, replace the path-only development constraint with the first tagged transformer release.

## Public Function Mapping

| Current public surface | Transformer target | Phase-1 wrapper behavior |
| --- | --- | --- |
| `bac_compile_website_artifact( array $artifact, array $options = array() )` | `ArtifactCompiler::compile( array $artifact ): TransformerResult` | Return the existing BAC array shape by mapping `TransformerResult::toArray()` into current report fields. Keep BAC options local until transformer compiler options are public. |
| `bac_compile_fragment( string $content, string $source = 'fragment', string $format = 'html', array $options = array() )` | `ArtifactCompiler::compileFragment()` or `FormatBridge` plus result envelope | Preserve the current array shape for fragment compiler callers. |
| `bac_summarize_result( array $compiled )` | Transformer summary helper or BAC-owned mapper | Keep summary keys stable for Static Site Importer import reports and CLI output. |
| `Block_Artifact_Compiler::compile()` | `ArtifactCompiler::compile()` | Legacy class method delegates to the transformer and maps the result. |
| `Block_Artifact_Compiler::compile_fragment()` | `ArtifactCompiler` fragment path | Legacy method delegates and maps result shape. |
| `Block_Artifact_Compiler::summarize_result()` | Transformer summary helper or local mapper | Keep as a compatibility method until Static Site Importer no longer calls BAC summary helpers. |
| `bac_sanitize_key()` | WordPress runtime adapter or local compatibility helper | Keep local helper unless transformer exposes a package-safe equivalent. |
| `bac_json_encode()` | PHP native `json_encode()` wrapper or transformer helper | Keep local helper for report compatibility and JSON flags. |

## PR Steps

1. Add the transformer dependency and autoload it before `library.php` exposes BAC functions.
2. Replace `Block_Artifact_Compiler::compile()` internals with transformer delegation plus a BAC report mapper.
3. Keep private normalization/report helpers only where they adapt existing public BAC report shapes.
4. Port `tests/contract-smoke.php` expectations to compare legacy output fields against transformer-backed fields.
5. Keep `bac_compile_fragment()` working for current BFB and Static Site Importer callers.
6. Update README examples to describe BAC as a compatibility facade over the transformer.

## Acceptance Criteria

- `composer test` passes in `block-artifact-compiler`.
- `bac_compile_website_artifact()` returns the existing keys consumed by Static Site Importer, including documents, diagnostics, input/source reports, block reports, and summary-compatible fields.
- `bac_summarize_result()` output remains stable for current import-report summaries.
- Empty artifact, schema-less artifact, markdown, MDX, nested source, and generated block fixture cases stay covered.
- Any missing transformer report field is tracked upstream before the BAC wrapper paper-cuts around it.

## Blockers To Resolve Upstream First

- Missing `ArtifactCompiler::compileFragment()` equivalent for `bac_compile_fragment()`.
- Missing public compiler options on `ArtifactCompiler::compile()` for BAC option forwarding.
- Missing document artifact fields required by `Static_Site_Importer_Source_Page::from_wordpress_document_artifact()`.
- Missing diagnostics/report keys required by Static Site Importer quality gates.
