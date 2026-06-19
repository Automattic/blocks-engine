# block-artifact-compiler Wrapper Plan

`chubes4/block-artifact-compiler` should become a compatibility package around `Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler` after the artifact compiler slice lands.

## Public Surface Mapping

| Existing surface | Target transformer surface | Wrapper behavior |
| --- | --- | --- |
| `bac_compile_website_artifact( array $artifact, array $options = array() )` | `ArtifactCompiler::compile( array $artifact )` | Return the existing compiler result envelope until Static Site Importer accepts `TransformerResult`. |
| `bac_compile_fragment( string $content, string $source = 'fragment', string $format = 'html', array $options = array() )` | `ArtifactCompiler::compileFragment( ... )` or a normalized one-file artifact passed to `compile()` | Preserve virtual fragment path behavior and source labels. |
| `bac_summarize_result( array $compiled )` | Result summary helper on the transformer side | Keep the old summary shape for import reports and CLI output. |
| `Block_Artifact_Compiler::compile()` | `ArtifactCompiler::compile()` | Legacy class should delegate to the transformer. |
| `Block_Artifact_Compiler::summarize_result()` | Result summary helper | Legacy class should stay as thin delegation if consumers instantiate it directly. |

## Adapter Skeleton

See `examples/compatibility/block-artifact-compiler-wrapper.php` for a copyable wrapper sketch.

## Migration Notes

- Keep the old result schema stable while Static Site Importer still records `wordpress_artifacts`, `diagnostics`, `bfb_report`, and source-document summaries.
- Normalize artifact input in one place. If normalization moves into `php-transformer`, the compatibility package should stop duplicating it.
- Keep size limits, safe relative path checks, binary handling, and artifact provenance in the canonical compiler implementation.
- Migrate current `tests/contract-smoke.php` assertions into shared transformer fixtures before removing the old package's compiler logic.
