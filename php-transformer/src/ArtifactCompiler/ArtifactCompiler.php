<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\ReferenceAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\Contract\ConversionReportProjection;
use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use Automattic\BlocksEngine\PhpTransformer\FormatBridge\FormatBridge;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;
use Automattic\BlocksEngine\PhpTransformer\StaticSite\MaterializationPlanBuilder;
use DOMDocument;
use DOMElement;

final class ArtifactCompiler
{
    public const INPUT_SCHEMA = 'blocks-engine/php-transformer/site-artifact/v1';

    /**
     * @param array<string, mixed> $artifact
     */
    public function compile(array $artifact): TransformerResult
    {
        $startedAt = hrtime(true);
        $normalized = ( new ArtifactNormalizer() )->normalize($artifact);
        $entry = $this->entryFile($normalized['files'], $normalized['entrypoints']);
        $documents = $this->compileSourceDocuments($normalized);
        $diagnostics = array_merge($normalized['diagnostics'], $documents['diagnostics'], $this->svgAssetDiagnostics($normalized['files']));

        if ( null === $entry && array() === $documents['documents'] ) {
            $diagnostics[] = $this->diagnostic('missing_entry_html', 'error', 'No HTML entry file was available to compile.');
        }

        $entryPath = is_array($entry) ? (string) $entry['path'] : '';
        $html = is_array($entry) ? (string) $entry['content'] : '';
        $referenceReports = $this->referenceReports($normalized['files']);
        $components = $this->detectComponents($normalized['files'], $entryPath, $documents['components']);
        $blockTypes = $this->detectBlockTypes($normalized['files'], $diagnostics);
        $entryBlocks = $this->compileEntryBlocks($html, $entryPath, $normalized['files']);
        $assets = array_merge($this->assetManifest($normalized['files'], $entryPath, $referenceReports['asset_references']), $entryBlocks['assets']);
        $diagnostics = array_merge($diagnostics, $entryBlocks['diagnostics']);
        $serializedBlocks = $entryBlocks['serialized_blocks'];
        if ( '' === $serializedBlocks && ! empty($documents['documents'][0]['block_markup']) ) {
            $serializedBlocks = (string) $documents['documents'][0]['block_markup'];
        }
        $sourceReports = array(
            'artifact' => array(
                'schema'          => self::INPUT_SCHEMA,
                'original_schema' => is_string($artifact['schema'] ?? null) ? $artifact['schema'] : '',
                'entry_path'      => $entryPath,
                'entrypoints'     => $normalized['entrypoints'],
                'file_count'      => count($normalized['files']),
                'accepted_count'  => count($normalized['files']),
                'rejected_count'  => $normalized['rejected_count'],
                'bytes'           => $normalized['bytes'],
                'files_by_kind'   => $this->countBy($normalized['files'], 'kind'),
                'files_by_role'   => $this->countBy($normalized['files'], 'role'),
                'files_by_mime'   => $this->countBy($normalized['files'], 'mime_type'),
                'files_by_source' => $this->countBy($normalized['files'], 'source'),
                'files_by_intent' => $this->countBy($normalized['files'], 'intent'),
                'limits'          => array(
                    'max_files'       => ArtifactNormalizer::DEFAULT_MAX_FILES,
                    'max_file_bytes'  => ArtifactNormalizer::DEFAULT_MAX_FILE_BYTES,
                    'max_total_bytes' => ArtifactNormalizer::DEFAULT_MAX_TOTAL_BYTES,
                ),
                'source_hash'     => hash('sha256', $normalized['hash_payload']),
                'html'            => array(
                    'bytes'         => strlen($html),
                    'element_count' => preg_match_all('/<\s*[a-z][a-z0-9:-]*(?:\s|>|\/)/i', $html),
                ),
                'internal_links'    => $referenceReports['internal_links'],
                'asset_references'  => $referenceReports['asset_references'],
                'image_references'  => $referenceReports['image_references'],
            ),
        );
        $sourceReports['compiled_site'] = $this->compiledSiteReport($normalized, $entryPath, $documents['documents'], $assets, $blockTypes, $serializedBlocks);
        $sourceReports['materialization_plan'] = ( new MaterializationPlanBuilder() )->fromCompiledSite($sourceReports['compiled_site']);
        $sourceReports['runtime_dependency_parity'] = ( new RuntimeDependencyParityReport() )->fromArtifact($normalized['files'], $html, $serializedBlocks, $entryPath);
        $provenance = array(
            array(
                'source_format' => 'artifact',
                'input_keys'    => array_keys($artifact),
                'source_hash'   => hash('sha256', $normalized['hash_payload']),
            ),
        );
        $metrics = array(
            'input_bytes'           => $normalized['bytes'],
            'block_count'           => $this->countBlocks($entryBlocks['blocks']),
            'fallback_count'        => count($entryBlocks['fallbacks']),
            'diagnostic_count'      => count($diagnostics),
            'transform_duration_ms' => (hrtime(true) - $startedAt) / 1000000,
            'output_bytes'          => strlen($serializedBlocks),
        );
        $sourceReports['conversion_report'] = ConversionReportProjection::fromResultParts('artifact', $entryBlocks['blocks'], $entryBlocks['fallbacks'], $sourceReports, $assets, $provenance, $metrics);

        return new TransformerResult(
            status: $this->statusFromDiagnostics($diagnostics),
            components: $components,
            blockTypes: $blockTypes,
            sourceReports: $sourceReports,
            blocks: $entryBlocks['blocks'],
            serializedBlocks: $serializedBlocks,
            documents: $documents['documents'],
            assets: $assets,
            diagnostics: $diagnostics,
            fallbacks: $entryBlocks['fallbacks'],
            provenance: $provenance,
            metrics: $metrics
        );
    }

    /**
     * Compile a standalone source fragment through the canonical format bridge.
     *
     * @param array<string,mixed> $options Transformer context/provenance options.
     */
    public function compileFragment(string $content, string $source = 'fragment', string $format = 'html', array $options = array()): TransformerResult
    {
        $bridge = new FormatBridge();
        return $bridge->convertResult($content, $format, 'blocks', array_merge(array(
            'source'       => $source,
            'source_scope' => 'artifact-fragment',
        ), $options));
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array{blocks: array<int, array<string, mixed>>, serialized_blocks: string, diagnostics: array<int, array<string, mixed>>, fallbacks: array<int, array<string, mixed>>, assets: array<int, array<string, mixed>>}
     */
    private function compileEntryBlocks(string $html, string $entryPath, array $files): array
    {
        $result = $this->compileHtmlDocumentBlocks($html, $entryPath, $files, 'artifact-entry');

        return array(
            'blocks'            => $result['blocks'],
            'serialized_blocks' => $result['serialized_blocks'],
            'diagnostics'       => $this->entryTransformDiagnostics($result['diagnostics']),
            'fallbacks'         => $result['fallbacks'],
            'assets'            => $result['assets'],
        );
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array{blocks: array<int, array<string, mixed>>, serialized_blocks: string, diagnostics: array<int, array<string, mixed>>, fallbacks: array<int, array<string, mixed>>, assets: array<int, array<string, mixed>>}
     */
    private function compileHtmlDocumentBlocks(string $html, string $sourcePath, array $files, string $sourceScope): array
    {
        if ( $this->containsBlockMarkup($html) ) {
            return array(
                'blocks'            => array(),
                'serialized_blocks' => $html,
                'diagnostics'       => array(),
                'fallbacks'         => array(),
                'assets'            => array(),
            );
        }

        if ( '' === trim($html) ) {
            return array(
                'blocks'            => array(),
                'serialized_blocks' => '',
                'diagnostics'       => array(),
                'fallbacks'         => array(),
                'assets'            => array(),
            );
        }

        $result = ( new HtmlTransformer() )->transform($this->safeHtmlDocumentHtml($html, $sourcePath, $files), array(
            'source'       => $sourcePath,
            'source_scope' => $sourceScope,
            'static_css'                => $this->linkedStylesheetCss($html, $sourcePath, $files),
            'asset_metadata'            => $this->assetMetadataForSource($sourcePath, $files),
            'runtime_dom_selectors'     => $this->runtimeDomSelectors($html, $sourcePath, $files),
            'runtime_canvas_selectors' => $this->runtimeCanvasSelectors($html, $sourcePath, $files),
        ))->toArray();

        return array(
            'blocks'            => is_array($result['blocks'] ?? null) ? $result['blocks'] : array(),
            'serialized_blocks' => (string) ($result['serialized_blocks'] ?? ''),
            'diagnostics'       => is_array($result['diagnostics'] ?? null) ? $result['diagnostics'] : array(),
            'fallbacks'         => is_array($result['fallbacks'] ?? null) ? $result['fallbacks'] : array(),
            'assets'            => is_array($result['assets'] ?? null) ? $result['assets'] : array(),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $files
     */
    private function safeHtmlDocumentHtml(string $html, string $entryPath, array $files): string
    {
        $html = $this->withoutMaterializedScriptTags($html, $entryPath, $files);
        $html = preg_replace_callback('/<img\s+[^>]*src\s*=\s*(["\'])([^"\']+)\1[^>]*>/i', function (array $matches) use ($entryPath, $files): string {
            $asset = $this->findAssetByHtmlReference((string) $matches[2], $entryPath, $files);
            if ( is_array($asset) && 'image/svg+xml' === ($asset['mime_type'] ?? '') && ! $this->isSafeImageAsset($asset) ) {
                return '';
            }

            return (string) $matches[0];
        }, $html) ?? $html;

        return preg_replace('/<figure\b[^>]*>\s*<\/figure>/i', '', $html) ?? $html;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     */
    private function withoutMaterializedScriptTags(string $html, string $entryPath, array $files): string
    {
        return preg_replace_callback('/<script\b([^>]*)>\s*<\/script>/i', function (array $matches) use ($entryPath, $files): string {
            $src = $this->htmlAttribute((string) $matches[1], 'src');
            if ( '' === $src ) {
                return (string) $matches[0];
            }

            $asset = $this->findAssetByHtmlReference($src, $entryPath, $files);
            if ( ! is_array($asset) || ! $this->isMaterializedScriptAsset($asset) ) {
                return (string) $matches[0];
            }

            return '';
        }, $html) ?? $html;
    }

    /**
     * @param array<string, mixed> $asset
     */
    private function isMaterializedScriptAsset(array $asset): bool
    {
        return in_array($asset['kind'] ?? '', array('js', 'mjs'), true)
            || 'script' === ($asset['role'] ?? '')
            || in_array($asset['mime_type'] ?? '', array('application/javascript', 'text/javascript', 'application/ecmascript', 'text/ecmascript'), true);
    }

    /**
     * @param array<int, array<string, mixed>> $files
     */
    private function linkedStylesheetCss(string $html, string $sourcePath, array $files): string
    {
        if ( ! preg_match_all('/<link\b[^>]*>/i', $html, $matches) ) {
            return '';
        }

        $css = array();
        foreach ( $matches[0] as $tag ) {
            $rel = $this->htmlAttribute((string) $tag, 'rel');
            $href = $this->htmlAttribute((string) $tag, 'href');
            if ( '' === $href || ! preg_match('/(?:^|\s)stylesheet(?:\s|$)/i', $rel) ) {
                continue;
            }

            $path = ArtifactPath::resolveRelativePath($href, $sourcePath);
            if ( '' === $path ) {
                continue;
            }

            foreach ( $files as $file ) {
                if ( $path === (string) ($file['path'] ?? '') && 'css' === ($file['kind'] ?? '') && is_string($file['content'] ?? null) ) {
                    $css[] = (string) $file['content'];
                    break;
                }
            }
        }

        return trim(implode("\n", $css));
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, string>
     */
    private function runtimeDomSelectors(string $html, string $sourcePath, array $files): array
    {
        $selectors = array();
        foreach ( $this->documentScriptContents($html, $sourcePath, $files) as $script ) {
            foreach ( $this->scriptDomSelectors($script) as $selector ) {
                $selectors[$selector] = true;
            }
        }

        return array_keys($selectors);
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, string>
     */
    private function runtimeCanvasSelectors(string $html, string $sourcePath, array $files): array
    {
        $canvasSelectors = $this->canvasSelectors($html);
        if ( array() === $canvasSelectors ) {
            return array();
        }

        $selectors = array();
        foreach ( $this->documentScriptContents($html, $sourcePath, $files) as $script ) {
            foreach ( $this->scriptCanvasSelectors($script) as $selector ) {
                if ( isset($canvasSelectors[$selector]) ) {
                    $selectors[$selector] = true;
                }
            }
        }

        return array_keys($selectors);
    }

    /**
     * @return array<int, string>
     */
    private function scriptCanvasSelectors(string $script): array
    {
        $selectors = array();
        $getContextPattern = '\.\s*getContext\s*\(';

        if ( preg_match_all('/document\s*\.\s*getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\1\s*\)\s*' . $getContextPattern . '/', $script, $matches) ) {
            foreach ( $matches[2] as $id ) {
                $selectors['#' . (string) $id] = true;
            }
        }

        if ( preg_match_all('/document\s*\.\s*querySelector\s*\(\s*(["\'])([#.][A-Za-z][A-Za-z0-9_-]*)\1\s*\)\s*' . $getContextPattern . '/', $script, $matches) ) {
            foreach ( $matches[2] as $selector ) {
                $selectors[(string) $selector] = true;
            }
        }

        if ( preg_match_all('/(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*document\s*\.\s*getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\2\s*\)/', $script, $assignments, PREG_SET_ORDER) ) {
            foreach ( $assignments as $assignment ) {
                if ( preg_match('/\b' . preg_quote((string) $assignment[1], '/') . '\s*' . $getContextPattern . '/', $script) ) {
                    $selectors['#' . (string) $assignment[3]] = true;
                }
            }
        }

        if ( preg_match_all('/(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*document\s*\.\s*querySelector\s*\(\s*(["\'])([#.][A-Za-z][A-Za-z0-9_-]*)\2\s*\)/', $script, $assignments, PREG_SET_ORDER) ) {
            foreach ( $assignments as $assignment ) {
                if ( preg_match('/\b' . preg_quote((string) $assignment[1], '/') . '\s*' . $getContextPattern . '/', $script) ) {
                    $selectors[(string) $assignment[3]] = true;
                }
            }
        }

        return array_keys($selectors);
    }

    /**
     * @return array<string, bool>
     */
    private function canvasSelectors(string $html): array
    {
        $selectors = array();
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ( ! $loaded ) {
            return array();
        }

        foreach ( $document->getElementsByTagName('canvas') as $canvas ) {
            if ( ! $canvas instanceof DOMElement ) {
                continue;
            }
            $id = trim($canvas->hasAttribute('id') ? $canvas->getAttribute('id') : '');
            if ( '' !== $id ) {
                $selectors['#' . $id] = true;
            }
            foreach ( preg_split('/\s+/', trim($canvas->hasAttribute('class') ? $canvas->getAttribute('class') : '')) ?: array() as $class ) {
                if ( '' !== $class ) {
                    $selectors['.' . $class] = true;
                }
            }
        }

        return $selectors;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, string>
     */
    private function documentScriptContents(string $html, string $sourcePath, array $files): array
    {
        $scripts = array();
        if ( ! preg_match_all('/<script\b([^>]*)>(.*?)<\/script>/is', $html, $matches, PREG_SET_ORDER) ) {
            return array();
        }

        foreach ( $matches as $match ) {
            $src = $this->htmlAttribute((string) $match[1], 'src');
            if ( '' === $src ) {
                $scripts[] = (string) $match[2];
                continue;
            }

            $asset = $this->findAssetByHtmlReference($src, $sourcePath, $files);
            if ( is_array($asset) && $this->isMaterializedScriptAsset($asset) && is_string($asset['content'] ?? null) ) {
                $scripts[] = (string) $asset['content'];
            }
        }

        return $scripts;
    }

    /**
     * @return array<int, string>
     */
    private function scriptDomSelectors(string $script): array
    {
        $selectors = array();
        if ( preg_match_all('/document\s*\.\s*getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\1\s*\)/', $script, $matches) ) {
            foreach ( $matches[2] as $id ) {
                $selectors['#' . (string) $id] = true;
            }
        }
        if ( preg_match_all('/document\s*\.\s*querySelector(?:All)?\s*\(\s*(["\'])([#.][A-Za-z][A-Za-z0-9_-]*)\1\s*\)/', $script, $matches) ) {
            foreach ( $matches[2] as $selector ) {
                $selectors[(string) $selector] = true;
            }
        }
        if ( preg_match_all('/\b(?!document\b)[A-Za-z_$][A-Za-z0-9_$]*\s*\.\s*querySelector(?:All)?\s*\(\s*(["\'])([#.][A-Za-z][A-Za-z0-9_-]*)\1\s*\)/', $script, $matches) ) {
            foreach ( $matches[2] as $selector ) {
                $selectors[(string) $selector] = true;
            }
        }
        if ( preg_match_all('/\.\s*closest\s*\(\s*(["\'])([#.][A-Za-z][A-Za-z0-9_-]*)\1\s*\)/', $script, $matches) ) {
            foreach ( $matches[2] as $selector ) {
                $selectors[(string) $selector] = true;
            }
        }

        return array_keys($selectors);
    }

    private function htmlAttribute(string $tag, string $name): string
    {
        if ( preg_match('/\s' . preg_quote($name, '/') . '\s*=\s*(["\'])(.*?)\1/is', $tag, $match) ) {
            return html_entity_decode((string) $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return '';
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array{internal_links: array<int, array<string, mixed>>, asset_references: array<int, array<string, mixed>>, image_references: array<int, array<string, mixed>>}
     */
    private function referenceReports(array $files): array
    {
        return ( new ReferenceAnalyzer() )->referenceReports(
            $files,
            fn (array $file): bool => $this->isLinkableDocument($file),
            fn (array $asset): bool => $this->isSafeImageAsset($asset)
        );
    }

    /**
     * @param array<string, mixed> $file
     */
    private function isLinkableDocument(array $file): bool
    {
        return in_array($file['kind'] ?? '', array('html', 'blocks'), true) && ! $this->isTemplatePartFile($file);
    }

    /**
     * @param array{files: array<int, array<string, mixed>>, bytes: int, hash_payload: string} $artifact
     * @param array<int, array<string, mixed>> $documents
     * @param array<int, array<string, mixed>> $assets
     * @param array<int, array<string, mixed>> $blockTypes
     * @return array<string, mixed>
     */
    private function compiledSiteReport(array $artifact, string $entryPath, array $documents, array &$assets, array $blockTypes, string $serializedBlocks): array
    {
        $pages = array();
        foreach ( $artifact['files'] as $file ) {
            if ( 'html' !== ($file['kind'] ?? '') || $this->isTemplatePartFile($file) ) {
                continue;
            }

            $path = (string) ($file['path'] ?? '');
            $title = $this->titleFromHtml((string) ($file['content'] ?? ''), $path);
            $slug = $this->slugFromPath($path);
            $content = (string) ($file['content'] ?? '');
            $compiledBlocks = $path === $entryPath
                ? array('serialized_blocks' => $serializedBlocks, 'assets' => array())
                : $this->compileHtmlDocumentBlocks($content, $path, $artifact['files'], 'artifact-document');
            foreach ( $compiledBlocks['assets'] ?? array() as $generatedAsset ) {
                if ( is_array($generatedAsset) ) {
                    $assets[] = $generatedAsset;
                }
            }
            $blockMarkup = (string) ($compiledBlocks['serialized_blocks'] ?? '');
            if ( '' === $blockMarkup && '' !== trim($content) ) {
                $blockMarkup = $this->htmlDocumentBlockMarkup($content);
            }
            $bodyFormat = '' !== trim($blockMarkup) ? 'blocks' : 'html';
            $pages[] = array_filter(
                array(
                    'source_path'    => $path,
                    'kind'           => 'html',
                    'role'           => $file['role'] ?? 'document',
                    'entrypoint'     => $path === $entryPath || ! empty($file['entrypoint']),
                    'slug'           => $slug,
                    'title'          => $title,
                    'metadata'       => $this->documentMetadata($path, 'html', (string) ($file['role'] ?? 'document'), $slug, $title, $bodyFormat),
                    'html'           => $file['content'] ?? '',
                    'body_format'    => $bodyFormat,
                    'block_markup'   => $blockMarkup,
                    'bytes'          => $file['bytes'] ?? 0,
                    'mime_type'      => $file['mime_type'] ?? 'text/html',
                    'asset_references' => $this->assetReferencePaths($assets),
                    'provenance'     => $file['provenance'] ?? array(),
                ),
                static fn (mixed $value): bool => array() !== $value
            );
        }

        foreach ( $documents as $document ) {
            $pages[] = array_filter(
                array(
                    'source_path'  => $document['source_path'] ?? '',
                    'kind'         => $document['kind'] ?? 'document',
                    'role'         => 'document',
                    'entrypoint'   => false,
                    'slug'         => $document['slug'] ?? '',
                    'title'        => $document['title'] ?? '',
                    'metadata'     => $this->documentMetadata(
                        (string) ($document['source_path'] ?? ''),
                        (string) ($document['kind'] ?? 'document'),
                        'document',
                        (string) ($document['slug'] ?? ''),
                        (string) ($document['title'] ?? ''),
                        (string) ($document['body_format'] ?? ''),
                        $document
                    ),
                    'body_format'  => $document['body_format'] ?? '',
                    'block_markup' => $document['block_markup'] ?? '',
                    'provenance'   => $document['provenance'] ?? array(),
                ),
                static fn (mixed $value): bool => array() !== $value
            );
        }

        return array(
            'schema'      => 'blocks-engine/php-transformer/compiled-site/v1',
            'source_hash' => hash('sha256', $artifact['hash_payload']),
            'entry_path'  => $entryPath,
            'pages'       => $pages,
            'assets'      => $this->compiledSiteAssets($assets),
            'template_parts' => $this->compiledSiteTemplateParts($artifact['files']),
            'visual_repair' => $this->compiledSiteVisualRepair($assets),
            'theme'       => array(
                'stylesheets' => $this->assetPathsByIntentOrRole($assets, 'style', 'stylesheet'),
                'scripts'     => $this->assetPathsByIntentOrRole($assets, 'behavior', 'script'),
                'fonts'       => $this->assetPathsByRole($assets, 'font'),
                'images'      => $this->assetPathsByRole($assets, 'image'),
                'template_parts' => array_values(array_map(
                    static fn (array $part): string => (string) ($part['source_path'] ?? ''),
                    $this->compiledSiteTemplateParts($artifact['files'])
                )),
                'block_types' => array_values(array_map(
                    static fn (array $blockType): string => (string) ($blockType['name'] ?? ''),
                    $blockTypes
                )),
            ),
            'totals'      => array(
                'pages'       => count($pages),
                'assets'      => count($assets),
                'input_bytes' => $artifact['bytes'],
            ),
        );
    }

    private function htmlDocumentBlockMarkup(string $html): string
    {
        if ( '' === trim($html) ) {
            return '';
        }
        if ( $this->containsBlockMarkup($html) ) {
            return $html;
        }

        $result = ( new HtmlTransformer() )->transform($html, array(
            'source'       => 'html-document',
            'source_scope' => 'artifact-document',
        ))->toArray();

        return isset($result['serialized_blocks']) && is_scalar($result['serialized_blocks']) ? trim((string) $result['serialized_blocks']) : '';
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<string, array<string, mixed>>
     */
    private function assetMetadataForSource(string $sourcePath, array $files): array
    {
        $metadata = array();
        foreach ( $files as $file ) {
            if ( $this->isMaterializedHtmlDocument($file) ) {
                continue;
            }

            $path = (string) ($file['path'] ?? '');
            $mimeType = (string) ($file['mime_type'] ?? '');
            if ( ! str_starts_with($mimeType, 'image/') ) {
                continue;
            }
            if ( '' === $path ) {
                continue;
            }

            $asset = array(
                'url'       => $path,
                'path'      => $path,
                'mime_type' => $mimeType,
            );

            foreach ( $this->assetLookupKeysForSource($path, $sourcePath) as $key ) {
                $metadata[$key] = $asset;
            }
        }

        return $metadata;
    }

    /**
     * @return array<int, string>
     */
    private function assetLookupKeysForSource(string $assetPath, string $sourcePath): array
    {
        $keys = array($assetPath, '/' . $assetPath);
        $relativePath = $this->relativePathFromSource($assetPath, $sourcePath);
        if ( '' !== $relativePath ) {
            $keys[] = $relativePath;
            if ( ! str_starts_with($relativePath, '../') ) {
                $keys[] = './' . $relativePath;
            }
        }

        return array_values(array_unique(array_filter($keys, static fn (string $key): bool => '' !== $key)));
    }

    private function relativePathFromSource(string $assetPath, string $sourcePath): string
    {
        $sourceDir = '' === $sourcePath || ! str_contains($sourcePath, '/') ? '' : dirname($sourcePath);
        if ( '' === $sourceDir ) {
            return $assetPath;
        }

        $sourceParts = explode('/', $sourceDir);
        $assetParts = explode('/', $assetPath);
        while ( array() !== $sourceParts && array() !== $assetParts && $sourceParts[0] === $assetParts[0] ) {
            array_shift($sourceParts);
            array_shift($assetParts);
        }

        return implode('/', array_merge(array_fill(0, count($sourceParts), '..'), $assetParts));
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function documentMetadata(string $sourcePath, string $kind, string $role, string $slug, string $title, string $bodyFormat, array $document = array()): array
    {
        return array_filter(
            array(
                'source_path' => $sourcePath,
                'kind'        => $kind,
                'role'        => $role,
                'post_type'   => $document['post_type'] ?? ('document' === $role ? 'page' : ''),
                'slug'        => $slug,
                'title'       => $title,
                'excerpt'     => $document['excerpt'] ?? '',
                'date'        => $document['date'] ?? '',
                'template'    => $document['template'] ?? '',
                'taxonomies'  => $document['taxonomies'] ?? array(),
                'body_format' => $bodyFormat,
            ),
            static fn (mixed $value): bool => '' !== $value && array() !== $value
        );
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function compiledSiteTemplateParts(array $files): array
    {
        $parts = array();
        foreach ( $files as $file ) {
            $path = (string) ($file['path'] ?? '');
            if ( ! $this->isTemplatePartFile($file) ) {
                continue;
            }

            $slug = $this->slugFromPath($path);
            $parts[] = array_filter(
                array(
                    'source_path'  => $path,
                    'slug'         => $slug,
                    'title'        => $this->titleFromPath($path),
                    'area'         => $this->templatePartArea($path, (string) ($file['role'] ?? '')),
                    'body_format'  => (string) ($file['kind'] ?? ''),
                    'block_markup' => $this->htmlDocumentBlockMarkup((string) ($file['content'] ?? '')),
                    'bytes'        => $file['bytes'] ?? 0,
                    'provenance'   => $file['provenance'] ?? array(),
                ),
                static fn (mixed $value): bool => '' !== $value && array() !== $value
            );
        }

        return $parts;
    }

    /**
     * @param array<string, mixed> $file
     */
    private function isTemplatePartFile(array $file): bool
    {
        $path = (string) ($file['path'] ?? '');
        $role = (string) ($file['role'] ?? '');
        return 'html' === ($file['kind'] ?? '') && ('template-part' === $role || preg_match('#(^|/)(parts|template-parts)/[^/]+\.html?$#i', $path));
    }

    private function templatePartArea(string $path, string $role): string
    {
        if ( preg_match('/\b(header|footer|sidebar|navigation)\b/i', $path . ' ' . $role, $match) ) {
            return strtolower($match[1]);
        }

        return 'uncategorized';
    }

    /**
     * @param array<int, array<string, mixed>> $assets
     * @return array<string, mixed>
     */
    private function compiledSiteVisualRepair(array $assets): array
    {
        $stylesheets = array_values(array_filter($assets, fn (array $asset): bool => $this->isVisualRepairStylesheet($asset)));
        $css = '';
        foreach ( $stylesheets as $asset ) {
            if ( isset($asset['content']) && is_string($asset['content']) ) {
                $css .= ('' === $css ? '' : "\n") . $asset['content'];
            }
        }

        return array_filter(
            array(
                'stylesheets' => array_values(array_map(
                    static fn (array $asset): array => array_filter(
                        array(
                            'path'      => $asset['path'] ?? '',
                            'role'      => $asset['role'] ?? '',
                            'intent'    => $asset['intent'] ?? '',
                            'mime_type' => $asset['mime_type'] ?? '',
                            'bytes'     => $asset['bytes'] ?? 0,
                        ),
                        static fn (mixed $value): bool => '' !== $value
                    ),
                    $stylesheets
                )),
                'css'         => $css,
            ),
            static fn (mixed $value): bool => '' !== $value && array() !== $value
        );
    }

    /**
     * @param array<string, mixed> $asset
     */
    private function isVisualRepairStylesheet(array $asset): bool
    {
        $path = (string) ($asset['path'] ?? '');
        $role = (string) ($asset['role'] ?? '');
        $intent = (string) ($asset['intent'] ?? '');
        return 'css' === ($asset['kind'] ?? '') && ('visual-repair' === $role || 'visual-repair' === $intent || preg_match('/(?:^|[-_\/])visual[-_]repair(?:[-_\/]|\.)/i', $path));
    }

    private function titleFromHtml(string $html, string $path): string
    {
        if ( preg_match('/<h1\b[^>]*>(.*?)<\/h1>/is', $html, $match) || preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $html, $match) ) {
            $titleHtml = preg_replace('/<\s*(?:br|\/\s*(?:div|h[1-6]|p))\b[^>]*>/i', ' ', $match[1]) ?? $match[1];
            $title = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($titleHtml), ENT_QUOTES | ENT_HTML5)) ?? '');
            if ( '' !== $title ) {
                return $title;
            }
        }

        return $this->titleFromPath($path);
    }

    /**
     * @param array<int, array<string, mixed>> $assets
     * @return array<int, array<string, mixed>>
     */
    private function compiledSiteAssets(array $assets): array
    {
        return array_values(array_map(
            static fn (array $asset): array => array_filter(
                array(
                    'source'           => $asset['source'] ?? '',
                    'path'             => $asset['path'] ?? '',
                    'target_path'      => $asset['target_path'] ?? $asset['path'] ?? '',
                    'kind'             => $asset['kind'] ?? '',
                    'role'             => $asset['role'] ?? '',
                    'intent'           => $asset['intent'] ?? '',
                    'media_type'       => $asset['media_type'] ?? $asset['mime_type'] ?? '',
                    'mime_type'        => $asset['mime_type'] ?? '',
                    'bytes'            => $asset['bytes'] ?? 0,
                    'binary'           => $asset['binary'] ?? false,
                    'content_encoding' => $asset['content_encoding'] ?? $asset['encoding'] ?? '',
                    'content'          => $asset['content'] ?? null,
                    'content_base64'   => $asset['content_base64'] ?? null,
                    'hash'             => $asset['hash'] ?? $asset['provenance']['hash'] ?? '',
                    'references'       => $asset['references'] ?? array(),
                ),
                static fn (mixed $value): bool => null !== $value && '' !== $value
            ),
            $assets
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $assets
     * @return array<int, string>
     */
    private function assetReferencePaths(array $assets): array
    {
        return array_values(array_map(static fn (array $asset): string => (string) ($asset['path'] ?? ''), $assets));
    }

    /**
     * @param array<int, array<string, mixed>> $assets
     * @return array<int, string>
     */
    private function assetPathsByIntentOrRole(array $assets, string $intent, string $role): array
    {
        return array_values(array_map(
            static fn (array $asset): string => (string) ($asset['path'] ?? ''),
            array_filter($assets, static fn (array $asset): bool => $intent === ($asset['intent'] ?? '') || $role === ($asset['role'] ?? ''))
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $assets
     * @return array<int, string>
     */
    private function assetPathsByRole(array $assets, string $role): array
    {
        return array_values(array_map(
            static fn (array $asset): string => (string) ($asset['path'] ?? ''),
            array_filter($assets, static fn (array $asset): bool => $role === ($asset['role'] ?? ''))
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, array<string, mixed>>
     */
    private function entryTransformDiagnostics(array $diagnostics): array
    {
        return array_values(array_filter(
            $diagnostics,
            static fn (array $diagnostic): bool => 'html_to_blocks_core_slice' !== ($diagnostic['code'] ?? '')
        ));
    }

    /**
     * @param array{files: array<int, array<string, mixed>>} $artifact
     * @return array{documents: array<int, array<string, mixed>>, components: array<int, array<string, mixed>>, diagnostics: array<int, array<string, mixed>>}
     */
    private function compileSourceDocuments(array $artifact): array
    {
        $documents = array();
        $components = array();
        $diagnostics = array();

        foreach ( $artifact['files'] as $file ) {
            if ( ! in_array($file['kind'], array('markdown', 'mdx'), true) || ! empty($file['binary']) ) {
                continue;
            }

            $parsed = $this->parseFrontmatter((string) $file['content']);
            $body = $parsed['body'];
            $frontmatter = $parsed['frontmatter'];
            $documentDiagnostics = array();

            if ( 'mdx' === $file['kind'] ) {
                $mdx = $this->extractMdxSemantics($body, $file, $artifact);
                $body = $mdx['markdown_body'];
                $components = array_merge($components, $mdx['components']);
                $documentDiagnostics = array_merge($documentDiagnostics, $mdx['diagnostics']);
            }

            $conversion = $this->convertMarkdownToBlocks($body);
            $documentDiagnostics = array_merge($documentDiagnostics, $conversion['diagnostics']);
            $diagnostics = array_merge($diagnostics, $documentDiagnostics);

            $documents[] = array(
                'source_path'  => $file['path'],
                'kind'         => $file['kind'],
                'post_type'    => $this->frontmatterString($frontmatter, array('post_type', 'type'), 'page'),
                'slug'         => $this->frontmatterString($frontmatter, array('slug'), $this->slugFromPath((string) $file['path'])),
                'title'        => $this->frontmatterString($frontmatter, array('title'), $this->titleFromPath((string) $file['path'])),
                'excerpt'      => $this->frontmatterString($frontmatter, array('excerpt', 'description'), ''),
                'date'         => $this->frontmatterString($frontmatter, array('date', 'published', 'published_at'), ''),
                'template'     => $this->frontmatterString($frontmatter, array('template', 'layout'), ''),
                'taxonomies'   => $this->frontmatterTaxonomies($frontmatter),
                'frontmatter'  => $frontmatter,
                'body'         => $body,
                'body_format'  => 'mdx' === $file['kind'] ? 'mdx' : 'markdown',
                'block_markup' => $conversion['serialized_blocks'],
                'diagnostics'  => $documentDiagnostics,
                'provenance'   => $file['provenance'],
            );
        }

        return array(
            'documents'   => $documents,
            'components'  => $components,
            'diagnostics' => $this->dedupeDiagnostics($diagnostics),
        );
    }

    /**
     * @return array{serialized_blocks: string, diagnostics: array<int, array<string, mixed>>}
     */
    private function convertMarkdownToBlocks(string $markdown): array
    {
        $result = ( new FormatBridge() )->convertResult(
            $markdown,
            'markdown',
            'blocks',
            array(
                'source'  => 'artifact_compiler',
                'context' => array(
                    'source_format' => 'markdown',
                    'target_format' => 'blocks',
                ),
            )
        )->toArray();

        if ( 'failed' !== (string) ( $result['status'] ?? '' ) ) {
            return array(
                'serialized_blocks' => (string) ( $result['serialized_blocks'] ?? '' ),
                'diagnostics'       => array_values(array_filter(
                    is_array($result['diagnostics'] ?? null) ? $result['diagnostics'] : array(),
                    static fn (array $diagnostic): bool => 'format_bridge_conversion_completed' !== (string) ($diagnostic['code'] ?? '')
                )),
            );
        }

        $diagnostics = is_array($result['diagnostics'] ?? null) ? $result['diagnostics'] : array();
        $diagnostics[] = $this->diagnostic('markdown_adapter_unavailable', 'warning', 'A Markdown adapter is unavailable; preserved source Markdown as a core/html fallback.');

        return array(
            'serialized_blocks' => '<!-- wp:html -->' . "\n" . $markdown . "\n" . '<!-- /wp:html -->',
            'diagnostics'       => $diagnostics,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @param array<int, string> $entrypoints
     * @return array<string, mixed>|null
     */
    private function entryFile(array $files, array $entrypoints): ?array
    {
        foreach ( $entrypoints as $entrypoint ) {
            foreach ( $files as $file ) {
                if ( $entrypoint === $file['path'] && $this->isEntryFile($file) ) {
                    return $file;
                }
            }
        }
        foreach ( array('index.html', 'index.htm', 'static-site/index.html', 'public/index.html') as $preferred ) {
            foreach ( $files as $file ) {
                if ( $preferred === strtolower((string) $file['path']) && $this->isEntryFile($file) ) {
                    return $file;
                }
            }
        }
        foreach ( $files as $file ) {
            if ( $this->isEntryFile($file) ) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $file
     */
    private function isEntryFile(array $file): bool
    {
        if ( ! empty($file['binary']) ) {
            return false;
        }

        return 'html' === ($file['kind'] ?? '') || 'blocks' === ($file['kind'] ?? '') || $this->containsBlockMarkup((string) ($file['content'] ?? ''));
    }

    private function containsBlockMarkup(string $content): bool
    {
        return str_contains($content, '<!-- wp:');
    }

    /**
     * @param array<int, array<string, mixed>> $files
     */
    private function fileHashPayload(array $files): string
    {
        $payload = '';
        foreach ( $files as $file ) {
            $content = isset($file['content_base64']) ? (string) $file['content_base64'] : (string) $file['content'];
            $payload .= $file['path'] . "\0" . $file['kind'] . "\0" . ($file['mime_type'] ?? '') . "\0" . $content . "\0";
        }

        return $payload;
    }

    private function sanitizeKey(string $key): string
    {
        return preg_replace('/[^a-z0-9_-]+/', '-', strtolower(trim($key))) ?? '';
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function assetManifest(array $files, string $entryPath, array $assetReferences = array()): array
    {
        $assets = array();
        foreach ( $files as $file ) {
            if ( $entryPath === $file['path'] || $this->isMaterializedHtmlDocument($file) ) {
                continue;
            }
            $asset = array(
                'source'           => $file['source'] ?? 'artifact',
                'path'             => $file['path'],
                'target_path'      => $file['path'],
                'kind'             => $file['kind'],
                'bytes'            => $file['bytes'],
                'media_type'       => $file['mime_type'],
                'mime_type'        => $file['mime_type'],
                'role'             => $file['role'],
                'encoding'         => $file['encoding'],
                'content_encoding' => $file['encoding'],
                'binary'           => $file['binary'],
                'hash'             => $file['provenance']['hash'] ?? '',
                'provenance'       => $file['provenance'],
            );
            if ( ! empty($file['content_base64']) ) {
                $asset['content_base64'] = $file['content_base64'];
            }
            if ( empty($file['binary']) && ! $this->isUnsafeSvgAsset($file) ) {
                $asset['content'] = $file['content'];
            }
            if ( ! empty($file['intent']) ) {
                $asset['intent'] = $file['intent'];
            }
            $references = $this->referencesForAsset((string) $file['path'], $assetReferences);
            if ( array() !== $references ) {
                $asset['references'] = $references;
            }
            $assets[] = $asset;
        }

        return $assets;
    }

    /**
     * @param array<string, mixed> $file
     */
    private function isMaterializedHtmlDocument(array $file): bool
    {
        return 'html' === ($file['kind'] ?? '') && ($this->isLinkableDocument($file) || $this->isTemplatePartFile($file));
    }

    /**
     * @param array<int, array<string, mixed>> $assetReferences
     * @return array<int, array<string, mixed>>
     */
    private function referencesForAsset(string $path, array $assetReferences): array
    {
        $references = array();
        foreach ( $assetReferences as $reference ) {
            if ( $path !== ($reference['asset_path'] ?? '') ) {
                continue;
            }

            $references[] = array_filter(
                array(
                    'source_path' => $reference['source_path'] ?? '',
                    'selector'    => $reference['selector'] ?? '',
                    'element'     => $reference['element'] ?? '',
                    'attribute'   => $reference['attribute'] ?? '',
                    'value'       => $reference['value'] ?? '',
                    'url'         => $reference['url'] ?? '',
                    'context'     => $reference['context'] ?? '',
                ),
                static fn (mixed $value): bool => '' !== $value
            );
        }

        return $references;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function svgAssetDiagnostics(array $files): array
    {
        $diagnostics = array();
        foreach ( $files as $file ) {
            if ( 'image/svg+xml' !== ($file['mime_type'] ?? '') || empty($file['content']) || $this->isSafeSvgContent((string) $file['content']) ) {
                continue;
            }

            $diagnostics[] = $this->diagnostic('unsafe_svg_asset', 'warning', 'An SVG image asset contains scriptable markup and its inline content was not exposed.', array('path' => $file['path']));
        }

        return $diagnostics;
    }

    /**
     * @param array<string, mixed> $asset
     */
    private function isSafeImageAsset(array $asset): bool
    {
        if ( 'image/svg+xml' !== ($asset['mime_type'] ?? '') ) {
            return true;
        }

        return ! empty($asset['content']) && $this->isSafeSvgContent((string) $asset['content']);
    }

    /**
     * @param array<string, mixed> $file
     */
    private function isUnsafeSvgAsset(array $file): bool
    {
        return 'image/svg+xml' === ($file['mime_type'] ?? '') && ! $this->isSafeSvgContent((string) ($file['content'] ?? ''));
    }

    private function isSafeSvgContent(string $content): bool
    {
        if ( '' === trim($content) ) {
            return false;
        }

        if ( ! preg_match('/<svg(?:\s|>)/i', $content) ) {
            return false;
        }

        return ! preg_match('/<\s*script\b|\son[a-z]+\s*=|javascript\s*:/i', $content);
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<string, mixed>|null
     */
    private function findAssetByHtmlReference(string $reference, string $entryPath, array $files): ?array
    {
        if ( '' === trim($reference) || preg_match('#^[a-z][a-z0-9+.-]*:#i', $reference) ) {
            return null;
        }

        $path = $this->resolveHtmlReferencePath($reference, $entryPath);
        if ( '' === $path ) {
            return null;
        }

        foreach ( $files as $file ) {
            if ( $path === ($file['path'] ?? '') ) {
                return $file;
            }
        }

        return null;
    }

    private function resolveHtmlReferencePath(string $reference, string $entryPath): string
    {
        return ArtifactPath::resolveRelativePath($reference, $entryPath);
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    private function countBlocks(array $blocks): int
    {
        $count = 0;
        foreach ( $blocks as $block ) {
            ++$count;
            if ( ! empty($block['innerBlocks']) && is_array($block['innerBlocks']) ) {
                $count += $this->countBlocks($block['innerBlocks']);
            }
        }

        return $count;
    }

    /**
     * @param array<int, array<string, mixed>> $sourceDocumentComponents
     * @return array<int, array<string, mixed>>
     */
    private function detectComponents(array $files, string $entryPath, array $sourceDocumentComponents = array()): array
    {
        $components = array();
        $classes = array();
        foreach ( $sourceDocumentComponents as $component ) {
            $key = 'mdx:' . (string) ($component['source'] ?? '') . ':' . (string) ($component['name'] ?? '');
            $components[$key] = $component;
        }

        foreach ( $files as $file ) {
            if ( in_array($file['kind'], array('jsx', 'tsx'), true) && empty($file['binary']) ) {
                foreach ( $this->detectJsxFileComponents($file) as $component ) {
                    $components['jsx-file:' . (string) $component['source'] . ':' . (string) $component['name']] = $component;
                }
            }

            if ( 'html' !== $file['kind'] || ! empty($file['binary']) ) {
                continue;
            }

            $content = (string) $file['content'];
            if ( preg_match_all('/data-component\s*=\s*(["\'])([^"\']+)\1/i', $content, $matches) ) {
                foreach ( $matches[2] as $name ) {
                    $key = $this->sanitizeKey($name);
                    if ( '' === $key ) {
                        continue;
                    }
                    $components['explicit:' . $key] = array(
                        'name'        => $key,
                        'source'      => $file['path'],
                        'signal'      => 'data-component',
                        'occurrences' => ($components['explicit:' . $key]['occurrences'] ?? 0) + 1,
                        'provenance'  => array('source_path' => $file['path']),
                    );
                }
            }

            if ( preg_match_all('/class\s*=\s*(["\'])([^"\']+)\1/i', $content, $matches) ) {
                foreach ( $matches[2] as $classList ) {
                    $classTokens = preg_split('/\s+/', trim($classList));
                    foreach ( false === $classTokens ? array() : $classTokens as $class ) {
                        $class = $this->sanitizeKey($class);
                        if ( '' === $class || strlen($class) < 3 ) {
                            continue;
                        }
                        $classes[$class] = ($classes[$class] ?? 0) + 1;
                    }
                }
            }
        }

        foreach ( $classes as $class => $count ) {
            if ( $count < 2 && ! preg_match('/(?:card|grid|hero|nav|header|footer|feature|testimonial|pricing|product|gallery|section)/', $class) ) {
                continue;
            }

            $components['class:' . $class] = array(
                'name'        => $class,
                'source'      => $entryPath,
                'signal'      => 'class-token',
                'occurrences' => $count,
                'provenance'  => array('source_path' => $entryPath),
            );
        }

        usort(
            $components,
            static function (array $left, array $right): int {
                $occurrenceComparison = ($right['occurrences'] ?? 1) <=> ($left['occurrences'] ?? 1);
                return 0 !== $occurrenceComparison ? $occurrenceComparison : strcmp((string) $left['name'], (string) $right['name']);
            }
        );

        return array_slice($components, 0, 25);
    }

    /**
     * @param array<string, mixed> $file
     * @return array<int, array<string, mixed>>
     */
    private function detectJsxFileComponents(array $file): array
    {
        $components = array();
        $content = (string) ($file['content'] ?? '');

        if ( preg_match_all('/(?:export\s+default\s+)?function\s+([A-Z][A-Za-z0-9_]*)\s*\(/', $content, $matches) ) {
            foreach ( $matches[1] as $name ) {
                $components[$name] = true;
            }
        }

        if ( preg_match_all('/(?:export\s+)?(?:const|let|var)\s+([A-Z][A-Za-z0-9_]*)\s*=\s*(?:\([^)]*\)|[A-Za-z0-9_]+)\s*=>/', $content, $matches) ) {
            foreach ( $matches[1] as $name ) {
                $components[$name] = true;
            }
        }

        return array_map(
            fn (string $name): array => array(
                'name'        => $name,
                'source'      => (string) ($file['path'] ?? ''),
                'signal'      => 'jsx-component-file',
                'occurrences' => 1,
                'provenance'  => array('source_path' => (string) ($file['path'] ?? '')),
            ),
            array_keys($components)
        );
    }

    /**
     * @return array{frontmatter: array<string, mixed>, body: string}
     */
    private function parseFrontmatter(string $content): array
    {
        if ( ! preg_match('/\A---\s*\R(.*?)\R---\s*\R?/s', $content, $matches) ) {
            return array(
                'frontmatter' => array(),
                'body'        => $content,
            );
        }

        $frontmatter = array();
        $lines = preg_split('/\R/', trim($matches[1]));
        foreach ( false === $lines ? array() : $lines as $line ) {
            if ( ! preg_match('/^([A-Za-z0-9_-]+)\s*:\s*(.*)$/', $line, $pair) ) {
                continue;
            }

            $value = trim($pair[2], " \t\n\r\0\x0B\"'");
            if ( preg_match('/^\[(.*)\]$/', $value, $list) ) {
                $value = array_values(array_filter(array_map(static fn (string $item): string => trim($item, " \t\n\r\0\x0B\"'"), explode(',', $list[1])), static fn (string $item): bool => '' !== $item));
            }

            $frontmatter[$this->sanitizeKey($pair[1])] = $value;
        }

        return array(
            'frontmatter' => $frontmatter,
            'body'        => substr($content, strlen($matches[0])),
        );
    }

    /**
     * @param array<string, mixed> $file
     * @param array{files: array<int, array<string, mixed>>} $artifact
     * @return array{markdown_body: string, components: array<int, array<string, mixed>>, diagnostics: array<int, array<string, mixed>>}
     */
    private function extractMdxSemantics(string $body, array $file, array $artifact): array
    {
        $imports = $this->extractMdxImports($body);
        $components = array();
        $diagnostics = array();
        $sourcePath = (string) $file['path'];

        if ( preg_match_all('/<([A-Z][A-Za-z0-9._-]*)(?:\s[^>]*)?\s*(?:>|\/>)/', $body, $matches) ) {
            foreach ( $matches[1] as $name ) {
                $import = $imports[$name] ?? null;
                $resolved = is_array($import) ? $this->resolveComponentImport((string) $import['path'], $sourcePath, $artifact) : '';
                $component = array(
                    'name'        => $name,
                    'source'      => $sourcePath,
                    'signal'      => 'mdx-jsx',
                    'occurrences' => ($components[$name]['occurrences'] ?? 0) + 1,
                    'provenance'  => array('source_path' => $sourcePath),
                );

                if ( is_array($import) ) {
                    $component['import_path'] = $import['path'];
                }
                if ( '' !== $resolved ) {
                    $component['resolved_path'] = $resolved;
                }

                $components[$name] = $component;

                if ( ! is_array($import) ) {
                    $diagnostics[] = $this->diagnostic('mdx_component_unresolved', 'warning', 'MDX component reference has no matching import.', array('path' => $sourcePath, 'component' => $name));
                } elseif ( '' === $resolved && str_starts_with((string) $import['path'], '.') ) {
                    $diagnostics[] = $this->diagnostic('mdx_import_unresolved', 'warning', 'MDX component import could not be linked to a generated source file.', array('path' => $sourcePath, 'component' => $name, 'import_path' => $import['path']));
                }
            }
        }

        $markdownBody = preg_replace('/^\s*import\s+[^;\r\n]+;?\s*$/m', '', $body) ?? $body;
        $markdownBody = preg_replace('/^\s*export\s+[^\r\n]+\s*$/m', '', $markdownBody) ?? $markdownBody;
        $markdownBody = preg_replace('/<([A-Z][A-Za-z0-9._-]*)(?:\s[^>]*)?\s*\/>/', '', $markdownBody) ?? $markdownBody;
        $markdownBody = preg_replace('/<\/?[A-Z][A-Za-z0-9._-]*(?:\s[^>]*)?>/', '', $markdownBody) ?? $markdownBody;

        return array(
            'markdown_body' => trim($markdownBody),
            'components'    => array_values($components),
            'diagnostics'   => $this->dedupeDiagnostics($diagnostics),
        );
    }

    /**
     * @return array<string, array{path: string}>
     */
    private function extractMdxImports(string $body): array
    {
        $imports = array();
        if ( ! preg_match_all('/^\s*import\s+(.+?)\s+from\s+["\']([^"\']+)["\'];?\s*$/m', $body, $matches, PREG_SET_ORDER) ) {
            return $imports;
        }

        foreach ( $matches as $match ) {
            $clause = trim($match[1]);
            $path = $match[2];
            if ( preg_match('/^([A-Z][A-Za-z0-9_]*)/', $clause, $default) ) {
                $imports[$default[1]] = array('path' => $path);
            }
            if ( preg_match('/\{([^}]+)\}/', $clause, $named) ) {
                foreach ( explode(',', $named[1]) as $name ) {
                    $parts = preg_split('/\s+as\s+/i', trim($name));
                    $alias = trim((string) end($parts));
                    if ( preg_match('/^[A-Z][A-Za-z0-9_]*$/', $alias) ) {
                        $imports[$alias] = array('path' => $path);
                    }
                }
            }
        }

        return $imports;
    }

    /**
     * @param array{files: array<int, array<string, mixed>>} $artifact
     */
    private function resolveComponentImport(string $importPath, string $sourcePath, array $artifact): string
    {
        if ( ! str_starts_with($importPath, '.') ) {
            return '';
        }

        $path = ArtifactPath::resolveRelativePath($importPath, $sourcePath, true);
        if ( '' === $path ) {
            return '';
        }

        $candidates = array($path);
        foreach ( array('js', 'jsx', 'ts', 'tsx', 'mdx') as $extension ) {
            $candidates[] = $path . '.' . $extension;
            $candidates[] = $path . '/index.' . $extension;
        }

        foreach ( $artifact['files'] as $file ) {
            if ( in_array($file['path'], $candidates, true) ) {
                return (string) $file['path'];
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $frontmatter
     * @param array<int, string> $keys
     */
    private function frontmatterString(array $frontmatter, array $keys, string $fallback): string
    {
        foreach ( $keys as $key ) {
            if ( isset($frontmatter[$key]) && is_scalar($frontmatter[$key]) && '' !== trim((string) $frontmatter[$key]) ) {
                return (string) $frontmatter[$key];
            }
        }

        return $fallback;
    }

    /**
     * @param array<string, mixed> $frontmatter
     * @return array<string, mixed>
     */
    private function frontmatterTaxonomies(array $frontmatter): array
    {
        $taxonomies = array();
        foreach ( array('category', 'categories', 'tag', 'tags') as $key ) {
            if ( isset($frontmatter[$key]) ) {
                $taxonomies[$key] = $frontmatter[$key];
            }
        }

        return $taxonomies;
    }

    private function slugFromPath(string $path): string
    {
        $base = preg_replace('/\.[A-Za-z0-9]+$/', '', basename($path));
        $base = '' === $base || null === $base ? 'document' : $base;
        return $this->sanitizeKey(str_replace(array('_', '.'), '-', $base));
    }

    private function titleFromPath(string $path): string
    {
        return ucwords(str_replace('-', ' ', $this->slugFromPath($path)));
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function detectBlockTypes(array $files, array &$diagnostics): array
    {
        $blockTypes = array();
        $blockRoots = array();

        foreach ( $files as $file ) {
            if ( 'block.json' !== basename((string) $file['path']) ) {
                continue;
            }
            $directory = dirname((string) $file['path']);
            $directory = '.' === $directory ? '' : $directory;
            $blockRoots[$directory] = $file;
        }

        foreach ( $blockRoots as $directory => $blockJsonFile ) {
            $blockJson = json_decode((string) $blockJsonFile['content'], true);
            if ( ! is_array($blockJson) ) {
                $blockJson = array();
                $diagnostics[] = $this->diagnostic('invalid_block_json', 'warning', 'A generated block.json file could not be decoded.', array('path' => $blockJsonFile['path']));
            }

            $name = isset($blockJson['name']) && is_string($blockJson['name']) ? trim($blockJson['name']) : '';
            if ( '' === $name ) {
                $name = 'generated/' . ('' === $directory ? 'block' : $this->sanitizeKey(basename($directory)));
                $diagnostics[] = $this->diagnostic('block_json_missing_name', 'warning', 'A generated block.json file did not declare a name; a stable generated name was assigned.', array('path' => $blockJsonFile['path'], 'name' => $name));
            }

            $blockFiles = $this->filesUnderDirectory($files, $directory);
            $blockTypes[] = array(
                'schema'          => 'chubes4/wordpress-block-type-artifact/v1',
                'name'            => $name,
                'slug'            => $this->sanitizeKey(basename($name)),
                'directory'       => $directory,
                'block_json_path' => $blockJsonFile['path'],
                'block_json'      => $blockJson,
                'metadata'        => $this->blockMetadataContract($blockJson),
                'assets'          => $this->blockAssetContract($blockJson, $blockFiles),
                'dependencies'    => $this->blockDependencyContract($blockJson, $blockFiles),
                'provenance'      => array(
                    'source'      => $blockJsonFile['source'] ?? 'artifact',
                    'source_hash' => hash('sha256', $this->fileHashPayload($blockFiles)),
                    'files'       => array_values(array_map(static fn (array $file): string => (string) $file['path'], $blockFiles)),
                ),
                'files'           => array_values(
                    array_map(
                        static fn (array $file): array => array(
                            'path'  => $file['path'],
                            'kind'  => $file['kind'],
                            'bytes' => $file['bytes'],
                        ),
                        $blockFiles
                    )
                ),
            );
        }

        usort(
            $blockTypes,
            static fn (array $left, array $right): int => strcmp((string) $left['name'], (string) $right['name'])
        );

        return $blockTypes;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function filesUnderDirectory(array $files, string $directory): array
    {
        $matched = array();
        $prefix = '' === $directory ? '' : $directory . '/';
        foreach ( $files as $file ) {
            if ( '' === $prefix || str_starts_with((string) $file['path'], $prefix) ) {
                $matched[] = $file;
            }
        }

        return $matched;
    }

    /**
     * @param array<string, mixed> $blockJson
     * @return array<string, mixed>
     */
    private function blockMetadataContract(array $blockJson): array
    {
        $metadata = array();
        foreach ( array('apiVersion', 'title', 'category', 'description', 'keywords', 'attributes', 'supports', 'usesContext', 'providesContext', 'textdomain', 'example', 'variations', 'parent', 'ancestor', 'allowedBlocks') as $key ) {
            if ( array_key_exists($key, $blockJson) ) {
                $metadata[$key] = $blockJson[$key];
            }
        }

        return $metadata;
    }

    /**
     * @param array<string, mixed> $blockJson
     * @param array<int, array<string, mixed>> $files
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function blockAssetContract(array $blockJson, array $files): array
    {
        $assets = array(
            'render'        => array(),
            'editor_script' => array(),
            'script'        => array(),
            'view_script'   => array(),
            'editor_style'  => array(),
            'style'         => array(),
            'view_style'    => array(),
        );

        foreach ( array(
            'render'       => 'render',
            'editorScript' => 'editor_script',
            'script'       => 'script',
            'viewScript'   => 'view_script',
            'editorStyle'  => 'editor_style',
            'style'        => 'style',
            'viewStyle'    => 'view_style',
        ) as $sourceField => $targetField ) {
            foreach ( $this->normalizeAssetReferences($blockJson[$sourceField] ?? null, $files, $sourceField) as $reference ) {
                $assets[$targetField][] = $reference;
            }
        }

        return $assets;
    }

    /**
     * @param mixed $value
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function normalizeAssetReferences(mixed $value, array $files, string $sourceField): array
    {
        $references = array();
        $values = is_array($value) ? array_values($value) : array($value);
        foreach ( $values as $item ) {
            if ( ! is_string($item) || '' === trim($item) ) {
                continue;
            }

            $item = trim($item);
            $isFileRef = str_starts_with($item, 'file:');
            $file = $isFileRef ? $this->findBlockFileByRelativePath($files, substr($item, 5)) : null;

            $reference = array(
                'reference'    => $item,
                'source_field' => $sourceField,
                'type'         => $isFileRef ? 'file' : 'handle',
            );
            if ( is_array($file) ) {
                $reference['path'] = $file['path'];
                $reference['kind'] = $file['kind'];
                $reference['bytes'] = $file['bytes'];
            }

            $references[] = $reference;
        }

        return $references;
    }

    /**
     * @param array<string, mixed> $blockJson
     * @param array<int, array<string, mixed>> $files
     * @return array<string, mixed>
     */
    private function blockDependencyContract(array $blockJson, array $files): array
    {
        $declared = array();
        foreach ( array('editorScript', 'script', 'viewScript', 'editorStyle', 'style', 'viewStyle') as $field ) {
            if ( array_key_exists($field, $blockJson) ) {
                $declared[$field] = $blockJson[$field];
            }
        }

        $assetFiles = array();
        foreach ( $files as $file ) {
            if ( ! str_ends_with((string) $file['path'], '.asset.php') ) {
                continue;
            }

            $assetFile = array(
                'path'  => $file['path'],
                'kind'  => $file['kind'],
                'bytes' => $file['bytes'],
            );
            $parsed = $this->parseAssetPhpManifest((string) ($file['content'] ?? ''));
            if ( array() !== $parsed ) {
                $assetFile['manifest'] = $parsed;
            }
            $assetFiles[] = $assetFile;
        }

        return array(
            'declared'    => $declared,
            'asset_files' => $assetFiles,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function parseAssetPhpManifest(string $content): array
    {
        $manifest = array();
        if ( preg_match('/["\']version["\']\s*=>\s*["\']([^"\']+)["\']/', $content, $version) ) {
            $manifest['version'] = $version[1];
        }
        if ( preg_match('/["\']dependencies["\']\s*=>\s*array\s*\((.*?)\)/s', $content, $dependencies) && preg_match_all('/["\']([^"\']+)["\']/', $dependencies[1], $matches) ) {
            $manifest['dependencies'] = array_values($matches[1]);
        }

        return $manifest;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<string, mixed>|null
     */
    private function findBlockFileByRelativePath(array $files, string $relativePath): ?array
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), './');
        foreach ( $files as $file ) {
            if ( basename((string) $file['path']) === $relativePath || str_ends_with((string) $file['path'], '/' . $relativePath) ) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<string, int>
     */
    private function countBy(array $files, string $field): array
    {
        $counts = array();
        foreach ( $files as $file ) {
            $value = (string) ($file[$field] ?? '');
            if ( '' === $value ) {
                continue;
            }
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, array<string, mixed>>
     */
    private function dedupeDiagnostics(array $diagnostics): array
    {
        $seen = array();
        $deduped = array();
        foreach ( $diagnostics as $diagnostic ) {
            $key = json_encode($diagnostic, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: serialize($diagnostic);
            if ( isset($seen[$key]) ) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $diagnostic;
        }

        return $deduped;
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     */
    private function statusFromDiagnostics(array $diagnostics): string
    {
        foreach ( $diagnostics as $diagnostic ) {
            if ( 'error' === ($diagnostic['severity'] ?? '') ) {
                return 'failed';
            }
        }
        return array() === $diagnostics ? 'success' : 'success_with_warnings';
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function diagnostic(string $code, string $severity, string $message, array $context = array()): array
    {
        return array_filter(
            array(
                'code'     => $code,
                'severity' => $severity,
                'message'  => $message,
                'source'   => self::class,
                'context'  => $context,
            ),
            static fn (mixed $value): bool => array() !== $value
        );
    }
}
