# Transitional Repository Map

The first migration wave treats the existing open source repositories as source inputs and future consumers of `php-transformer`. This document is migration context only; the package API does not depend on these repository names.

| Repository | Current role | Target role |
| --- | --- | --- |
| `chubes4/html-to-blocks-converter` | Converts raw HTML to Gutenberg block arrays. | Compatibility package or plugin wrapper around `PhpTransformer\\HtmlToBlocks`. |
| `chubes4/block-format-bridge` | Routes conversion between HTML, Markdown, and blocks. | Compatibility package around `PhpTransformer\\FormatBridge`. |
| `chubes4/block-artifact-compiler` | Normalizes generated website artifacts into WordPress-native outputs. | Compatibility package around `PhpTransformer\\ArtifactCompiler`. |
| `chubes4/static-site-importer` | WordPress plugin product for importing static sites into pages/themes. | Product integration that consumes `php-transformer`; remains independently useful. |

## Migration Rule

Move canonical library behavior into `php-transformer`; keep product behavior and existing public integration surfaces outside the package until callers migrate deliberately.
