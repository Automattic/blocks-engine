<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer;

use Automattic\BlocksEngine\FigmaTransformer\Contract\FigmaTransformResult;
use Automattic\BlocksEngine\FigmaTransformer\FigFile\FigArchiveReader;
use Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter;
use Automattic\BlocksEngine\FigmaTransformer\Parity\ParityReportBuilder;
use Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ScenegraphFrameInspector;
use Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ScenegraphNormalizer;
use Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ScenegraphPagePlanner;

/**
 * Public Figma transformation entrypoint.
 */
final class FigmaTransformer
{
    public function __construct(
        private readonly FigArchiveReader $archiveReader = new FigArchiveReader(),
        private readonly StaticHtmlEmitter $htmlEmitter = new StaticHtmlEmitter(),
        private readonly ParityReportBuilder $parityReportBuilder = new ParityReportBuilder(),
        private readonly ScenegraphNormalizer $scenegraphNormalizer = new ScenegraphNormalizer(),
        private readonly ScenegraphFrameInspector $frameInspector = new ScenegraphFrameInspector(),
        private readonly ScenegraphPagePlanner $pagePlanner = new ScenegraphPagePlanner()
    ) {
    }

    /**
     * Inspect frame/page candidates in a .fig file or .fig wrapper archive.
     *
     * @param array<string, mixed> $options Inspection options.
     * @return array<string, mixed>
     */
    public function inspectFramesFile(string $path, array $options = array()): array
    {
        $archive = $this->archiveReader->read($path, $options);
        $scenegraphCandidate = $this->decodedScenegraphCandidate($archive);
        if ( null === $scenegraphCandidate ) {
            return array(
                'schema'      => 'blocks-engine/figma-transformer/frame-inspection/v1',
                'status'      => $this->fallbackStatus($archive),
                'input'       => $archive['input'] ?? array(),
                'node_count'  => 0,
                'candidate_count' => 0,
                'returned_count' => 0,
                'candidates'  => array(),
                'diagnostics' => array_merge(
                    is_array($archive['diagnostics'] ?? null) ? $archive['diagnostics'] : array(),
                    array(
                        array(
                            'severity' => 'warning',
                            'code'     => 'figma_transformer_decoded_scenegraph_missing',
                            'message'  => 'No decoded NODE_CHANGES, document, or nodes payload was available for frame inspection.',
                            'source'   => 'FigmaTransformer',
                        ),
                    )
                ),
            );
        }

        $report = $this->inspectFramesScenegraph($scenegraphCandidate['payload'], $options);
        $report['status'] = empty($archive['diagnostics']) ? 'success' : 'success_with_warnings';
        $report['input'] = $archive['input'] ?? array();
        $report['decoded_scenegraph'] = $scenegraphCandidate['report'];
        $report['diagnostics'] = array_merge(is_array($archive['diagnostics'] ?? null) ? $archive['diagnostics'] : array(), is_array($report['diagnostics'] ?? null) ? $report['diagnostics'] : array());
        return $report;
    }

    /**
     * @param array<string, mixed> $scenegraph Decoded scenegraph.
     * @param array<string, mixed> $options Inspection options.
     * @return array<string, mixed>
     */
    public function inspectFramesScenegraph(array $scenegraph, array $options = array()): array
    {
        return $this->frameInspector->inspect($scenegraph, $options);
    }

    /**
     * Transform a .fig file or .fig wrapper archive into the canonical result envelope.
     *
     * @param array<string, mixed> $options Transformation options.
     */
    public function transformFile(string $path, array $options = array()): FigmaTransformResult
    {
        $startedAt = microtime(true);
        $archive   = $this->archiveReader->read($path, $options);

        $diagnostics = $archive['diagnostics'];
        $sourceReports = array(
            'figma' => array(
                'input'   => $archive['input'],
                'archive' => $archive['archive'],
                'meta'    => $archive['meta'],
                'assets'  => $archive['assets'],
            ),
        );

        $metrics = array(
            'input_bytes'           => $archive['input']['bytes'] ?? 0,
            'embedded_asset_count'  => count($archive['assets']),
            'transform_duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        );

        $parity = $this->parityReportBuilder->build(array(), array(
            'status' => 'not_run',
            'reason' => 'parity_runner_not_invoked',
        ));

        $scenegraphCandidate = $this->decodedScenegraphCandidate($archive);
        if ( null !== $scenegraphCandidate ) {
            $scenegraph = $this->withArchiveAssets($scenegraphCandidate['payload'], $archive['assets']);
            $scenegraphResult = $this->transformScenegraph($scenegraph, $options)->toArray();
            $scenegraphStatus = (string) ($scenegraphResult['status'] ?? 'success_with_warnings');
            if ( 'success' === $scenegraphStatus && ! empty($diagnostics) ) {
                $scenegraphStatus = 'success_with_warnings';
            }

            $scenegraphSourceReports = is_array($scenegraphResult['source_reports'] ?? null) ? $scenegraphResult['source_reports'] : array();
            $scenegraphFigmaSourceReports = is_array($scenegraphSourceReports['figma'] ?? null) ? $scenegraphSourceReports['figma'] : array();
            unset($scenegraphSourceReports['figma']);
            $mergedSourceReports = array_merge(
                array(
                    'figma' => array_merge(
                        array_merge($sourceReports['figma'], array('decoded_scenegraph' => $scenegraphCandidate['report'])),
                        $scenegraphFigmaSourceReports
                    ),
                ),
                $scenegraphSourceReports
            );

            return FigmaTransformResult::create(
                $scenegraphStatus,
                array_merge($diagnostics, $scenegraphResult['diagnostics'] ?? array()),
                $scenegraphResult['files'] ?? array(),
                $scenegraphResult['assets'] ?? array(),
                $mergedSourceReports,
                $scenegraphResult['parity'] ?? $parity,
                array_merge(
                    $metrics,
                    array(
                        'decoded_payload_candidate_count' => $scenegraphCandidate['candidate_count'],
                        'selected_decoded_payload_index'  => $scenegraphCandidate['report']['chunk_index'],
                    ),
                    $scenegraphResult['metrics'] ?? array()
                )
            );
        }

        $diagnostics[] = array(
            'severity' => 'warning',
            'code'     => 'figma_transformer_decoded_scenegraph_missing',
            'message'  => 'No decoded NODE_CHANGES, document, or nodes payload was available in canvas.fig.',
            'source'   => 'FigmaTransformer',
            'context'  => array(
                'decoded_payload_candidate_count' => 0,
                'canvas_chunk_count'              => count($archive['archive']['canvas']['chunks'] ?? array()),
            ),
        );

        return FigmaTransformResult::create(
            $this->fallbackStatus($archive),
            $diagnostics,
            array(),
            $archive['assets'],
            $sourceReports,
            $parity,
            $metrics
        );
    }

    /**
     * @param array<string, mixed> $archive
     * @return array<string, mixed>|null
     */
    private function decodedScenegraphCandidate(array $archive): ?array
    {
        $chunks = $archive['archive']['canvas']['chunks'] ?? array();
        if ( ! is_array($chunks) ) {
            return null;
        }

        $candidates = array();
        foreach ( $chunks as $chunk ) {
            if ( ! is_array($chunk) ) {
                continue;
            }

            $payload = $chunk['payload'] ?? array();
            if ( ! is_array($payload) || 'json' !== ($payload['classification'] ?? null) || ! is_array($payload['json'] ?? null) ) {
                continue;
            }

            $json = $payload['json'];
            if ( $this->isScenegraphPayload($json) ) {
                $shape = $this->scenegraphShape($json);
                $candidates[] = array(
                    'payload' => $json,
                    'score'   => $this->scenegraphCandidateScore($json, $shape),
                    'report'  => array(
                        'chunk_index' => (int) ($chunk['index'] ?? count($candidates)),
                        'shape'       => $shape,
                    ),
                );
            }
        }

        foreach ( $chunks as $chunk ) {
            if ( ! is_array($chunk) ) {
                continue;
            }

            $payload = $chunk['payload'] ?? array();
            if ( is_array($payload) && 'kiwi_message' === ($payload['classification'] ?? null) && is_array($payload['kiwi_message'] ?? null) ) {
                $kiwiMessage = $payload['kiwi_message'];
                if ( $this->isScenegraphPayload($kiwiMessage) ) {
                    $shape = $this->scenegraphShape($kiwiMessage);
                    $candidates[] = array(
                        'payload' => $kiwiMessage,
                        'score'   => $this->scenegraphCandidateScore($kiwiMessage, $shape),
                        'report'  => array(
                            'chunk_index'    => (int) ($chunk['index'] ?? count($candidates)),
                            'shape'          => $shape,
                            'classification' => 'kiwi_message',
                        ),
                    );
                }
            }
        }

        if ( empty($candidates) ) {
            return null;
        }

        usort(
            $candidates,
            static function (array $a, array $b): int {
                $scoreCompare = $b['score'] <=> $a['score'];
                if ( 0 !== $scoreCompare ) {
                    return $scoreCompare;
                }

                return ((int) $a['report']['chunk_index']) <=> ((int) $b['report']['chunk_index']);
            }
        );

        $selected = $candidates[0];
        $selected['candidate_count'] = count($candidates);
        return $selected;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function isScenegraphPayload(array $payload): bool
    {
        foreach ( array('NODE_CHANGES', 'node_changes', 'nodeChanges', 'document', 'nodes') as $key ) {
            if ( array_key_exists($key, $payload) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function scenegraphShape(array $payload): string
    {
        foreach ( array('NODE_CHANGES', 'node_changes', 'nodeChanges', 'document', 'nodes') as $key ) {
            if ( array_key_exists($key, $payload) ) {
                return $key;
            }
        }

        return 'unknown';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function scenegraphCandidateScore(array $payload, string $shape): int
    {
        $shapeScore = match ( $shape ) {
            'NODE_CHANGES', 'node_changes', 'nodeChanges' => 300,
            'document' => 200,
            'nodes' => 100,
            default => 0,
        };

        return $shapeScore + min(99, $this->scenegraphCandidateNodeCount($payload, $shape));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function scenegraphCandidateNodeCount(array $payload, string $shape): int
    {
        $value = $payload[$shape] ?? null;
        if ( ! is_array($value) ) {
            return 0;
        }

        if ( 'document' === $shape ) {
            return $this->nestedNodeCount($value);
        }

        $count = 0;
        foreach ( $value as $item ) {
            if ( is_array($item) ) {
                $node = is_array($item['node'] ?? null) ? $item['node'] : $item;
                $count += $this->nestedNodeCount($node);
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nestedNodeCount(array $node): int
    {
        $count = 1;
        foreach ( $node['children'] ?? array() as $child ) {
            if ( is_array($child) ) {
                $count += $this->nestedNodeCount($child);
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed>             $scenegraph
     * @param array<int, array<string, mixed>> $archiveAssets
     * @return array<string, mixed>
     */
    private function withArchiveAssets(array $scenegraph, array $archiveAssets): array
    {
        if ( empty($archiveAssets) ) {
            return $scenegraph;
        }

        $assets = is_array($scenegraph['assets'] ?? null) ? $scenegraph['assets'] : array();
        foreach ( $archiveAssets as $asset ) {
            if ( ! is_array($asset) ) {
                continue;
            }

            $id = (string) ($asset['id'] ?? $asset['hash'] ?? $asset['path'] ?? count($assets));
            $assets[$id] = $asset;
        }

        $scenegraph['assets'] = $assets;
        return $scenegraph;
    }

    /**
     * @param array<string, mixed> $archive
     */
    private function fallbackStatus(array $archive): string
    {
        foreach ( $archive['diagnostics'] ?? array() as $diagnostic ) {
            $code = (string) ($diagnostic['code'] ?? '');
            if ( in_array($code, array(
                'figma_transformer_unreadable_file',
                'figma_transformer_invalid_zip',
                'figma_transformer_nested_fig_unreadable',
                'figma_transformer_tempfile_failed',
                'figma_transformer_missing_canvas',
                'figma_transformer_canvas_too_short',
                'figma_transformer_kiwi_truncated_chunk_table',
                'figma_transformer_kiwi_truncated_chunk',
                'figma_transformer_kiwi_zlib_inflate_failed',
            ), true) ) {
                return 'decode_failed';
            }
        }

        return 'unsupported_decoder_pending';
    }

    /**
     * Transform a decoded Figma scenegraph into static HTML artifact files.
     *
     * @param array<string, mixed> $scenegraph Decoded Figma scenegraph or NODE_CHANGES payload.
     * @param array<string, mixed> $options Transformation options.
     */
    public function transformScenegraph(array $scenegraph, array $options = array()): FigmaTransformResult
    {
        if ( $this->isMultiPageTransform($options) ) {
            return $this->transformScenegraphPages($scenegraph, $options);
        }

        $startedAt = microtime(true);
        $normalized = $this->scenegraphNormalizer->normalize($scenegraph, $options);
        $artifact    = $this->htmlEmitter->emit($normalized, $options);
        $diagnostics = array_merge($normalized['diagnostics'] ?? array(), $artifact['diagnostics']);
        $parity      = $this->parityReportBuilder->build($options['parity'] ?? array());

        return FigmaTransformResult::create(
            $artifact['status'],
            $diagnostics,
            $artifact['files'],
            $artifact['assets'],
            array(
                'figma' => array(
                    'scenegraph' => $normalized['source_report'],
                    'html'       => $artifact['source_report'],
                ),
                'compiled_site' => $this->compiledSiteSourceReport($artifact),
            ),
            $parity,
            array(
                'node_count'             => $normalized['source_report']['node_count'] ?? ($artifact['metrics']['node_count'] ?? 0),
                'text_node_count'        => count($normalized['text_inventory'] ?? array()),
                'asset_reference_count'  => count($normalized['asset_references'] ?? array()),
                'asset_count'            => $artifact['metrics']['asset_count'] ?? 0,
                'file_count'             => count($artifact['files']),
                'transform_duration_ms'  => (int) round((microtime(true) - $startedAt) * 1000),
            )
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function isMultiPageTransform(array $options): bool
    {
        return true === ($options['multi_page'] ?? false)
            || true === ($options['include_all_pages'] ?? false)
            || ! empty($options['frame_ids'])
            || ! empty($options['max_pages']);
    }

    /**
     * @param array<string, mixed> $scenegraph
     * @param array<string, mixed> $options
     */
    private function transformScenegraphPages(array $scenegraph, array $options = array()): FigmaTransformResult
    {
        $startedAt = microtime(true);
        $pagePlan = $this->pagePlanner->plan($scenegraph, $options);
        $diagnostics = is_array($pagePlan['diagnostics'] ?? null) ? $pagePlan['diagnostics'] : array();
        $pages = is_array($pagePlan['pages'] ?? null) ? $pagePlan['pages'] : array();
        $files = array();
        $assetsByPath = array();
        $cssChunks = array();
        $pageReports = array();
        $fontFamilies = array();
        $fontUsage = array();
        $fontCssSupplied = false;
        $nodeCount = 0;
        $textNodeCount = 0;
        $assetReferenceCount = 0;

        foreach ( $pages as $page ) {
            if ( ! is_array($page) ) {
                continue;
            }

            $frameId = isset($page['frame_id']) && is_scalar($page['frame_id']) ? (string) $page['frame_id'] : '';
            if ( '' === $frameId ) {
                continue;
            }

            $pageOptions = $options;
            $pageOptions['frame_id'] = $frameId;
            unset($pageOptions['multi_page'], $pageOptions['include_all_pages'], $pageOptions['frame_ids'], $pageOptions['entry_frame_id'], $pageOptions['max_pages'], $pageOptions['frame_slug_map']);
            $pageResult = $this->transformScenegraph($scenegraph, $pageOptions)->toArray();
            $pageDiagnostics = is_array($pageResult['diagnostics'] ?? null) ? $pageResult['diagnostics'] : array();
            $diagnostics = array_merge($diagnostics, $pageDiagnostics);
            $nodeCount += (int) ($pageResult['metrics']['node_count'] ?? 0);
            $textNodeCount += (int) ($pageResult['metrics']['text_node_count'] ?? 0);
            $assetReferenceCount += (int) ($pageResult['metrics']['asset_reference_count'] ?? 0);
            $pageHtmlReport = is_array($pageResult['source_reports']['figma']['html'] ?? null) ? $pageResult['source_reports']['figma']['html'] : array();
            $pageFontFamilies = is_array($pageHtmlReport['font_families'] ?? null) ? $pageHtmlReport['font_families'] : array();
            $pageFontUsage = is_array($pageHtmlReport['font_usage'] ?? null) ? $pageHtmlReport['font_usage'] : array();
            $pageTransformDiagnostics = is_array($pageHtmlReport['transform_diagnostics'] ?? null) ? $pageHtmlReport['transform_diagnostics'] : array();
            $fontFamilies = $this->mergeFontFamilies($fontFamilies, $pageFontFamilies);
            $fontUsage = $this->mergeFontUsage($fontUsage, $pageFontUsage);
            $fontCssSupplied = $fontCssSupplied || true === ($pageHtmlReport['font_css_supplied'] ?? false);

            $html = $this->fileContent($pageResult['files'] ?? array(), 'index.html');
            $css = $this->fileContent($pageResult['files'] ?? array(), 'style.css');
            if ( '' !== $css ) {
                $cssChunks[] = $css;
            }

            $path = isset($page['path']) && is_scalar($page['path']) && '' !== (string) $page['path'] ? (string) $page['path'] : ((true === ($page['entrypoint'] ?? false)) ? 'index.html' : (string) ($page['slug'] ?? $frameId) . '.html');
            if ( '' !== $html ) {
                $files[] = array(
                    'path'      => $path,
                    'role'      => true === ($page['entrypoint'] ?? false) ? 'entrypoint' : 'document',
                    'mime_type' => 'text/html',
                    'content'   => $html,
                );
            }

            foreach ( is_array($pageResult['files'] ?? null) ? $pageResult['files'] : array() as $file ) {
                if ( ! is_array($file) || ! isset($file['path']) || ! is_scalar($file['path']) ) {
                    continue;
                }
                $assetPath = (string) $file['path'];
                if ( ! str_starts_with($assetPath, 'assets/') ) {
                    continue;
                }
                $assetsByPath[$assetPath] = $file;
            }

            $pageReports[] = array(
                'frame_id'   => $frameId,
                'name'       => (string) ($page['name'] ?? $frameId),
                'slug'       => (string) ($page['slug'] ?? ''),
                'path'       => $path,
                'entrypoint' => true === ($page['entrypoint'] ?? false),
                'node_count' => (int) ($pageResult['metrics']['node_count'] ?? 0),
                'text_node_count' => (int) ($pageResult['metrics']['text_node_count'] ?? 0),
                'asset_reference_count' => (int) ($pageResult['metrics']['asset_reference_count'] ?? 0),
                'font_families' => $pageFontFamilies,
                'font_usage' => $pageFontUsage,
                'font_css_supplied' => true === ($pageHtmlReport['font_css_supplied'] ?? false),
                'transform_diagnostics' => $pageTransformDiagnostics,
                'diagnostic_codes' => $this->diagnosticCodeCounts($pageDiagnostics),
            );
        }

        $css = $this->mergeCssChunks($cssChunks);
        if ( '' !== $css ) {
            $files[] = array(
                'path'      => 'style.css',
                'role'      => 'stylesheet',
                'mime_type' => 'text/css',
                'content'   => $css,
            );
        }

        foreach ( $assetsByPath as $assetFile ) {
            $files[] = $assetFile;
        }

        $assetReport = $this->assetReportFromFiles(array_values($assetsByPath));
        $transformDiagnostics = $this->mergePageTransformDiagnostics($pageReports, $assetReport);
        $parity = $this->parityReportBuilder->build($options['parity'] ?? array());
        $artifact = array(
            'files' => $files,
            'assets' => $assetReport,
            'source_report' => array(
                'pages' => $pageReports,
                'page_plan' => $pagePlan,
                'font_families' => $fontFamilies,
                'font_usage' => $fontUsage,
                'font_css_supplied' => $fontCssSupplied,
                'transform_diagnostics' => $transformDiagnostics,
            ),
            'metrics' => array(
                'asset_count' => count($assetReport),
                'file_count' => count($files),
            ),
        );

        return FigmaTransformResult::create(
            empty($diagnostics) ? 'success' : 'success_with_warnings',
            $diagnostics,
            $files,
            $assetReport,
            array(
                'figma' => array(
                    'pages' => $pagePlan,
                    'html'  => $artifact['source_report'],
                ),
                'compiled_site' => $this->compiledSiteSourceReport($artifact),
            ),
            $parity,
            array(
                'node_count'             => $nodeCount,
                'text_node_count'        => $textNodeCount,
                'asset_reference_count'  => $assetReferenceCount,
                'asset_count'            => count($assetReport),
                'file_count'             => count($files),
                'page_count'             => count($pageReports),
                'transform_duration_ms'  => (int) round((microtime(true) - $startedAt) * 1000),
            )
        );
    }

    /**
     * @param mixed $files
     */
    private function fileContent(mixed $files, string $path): string
    {
        foreach ( is_array($files) ? $files : array() as $file ) {
            if ( is_array($file) && $path === ($file['path'] ?? null) ) {
                return isset($file['content']) && is_scalar($file['content']) ? (string) $file['content'] : '';
            }
        }

        return '';
    }

    /**
     * @param array<int, array<string, mixed>> $pageReports
     * @param array<int, array<string, mixed>> $assetReport
     * @return array<string, mixed>
     */
    private function mergePageTransformDiagnostics(array $pageReports, array $assetReport): array
    {
        $images = array('paint_refs' => 0, 'node_refs' => 0, 'resolved_assets' => 0, 'image_block_count' => 0, 'image_block_nodes' => array(), 'missing_assets' => array());
        $vectors = array('nodes' => 0, 'rendered_paths' => 0, 'rendered_asset_fallbacks' => 0, 'placeholders' => 0, 'placeholder_nodes' => array());
        $layout = array(
            'large_negative_left_count' => 0,
            'fixed_root_width_count' => 0,
            'fixed_root_width_nodes' => array(),
            'large_absolute_offset_count' => 0,
            'large_absolute_offset_nodes' => array(),
            'decorative_underlays' => array('count' => 0, 'nodes' => array()),
            'image_heavy_landmark_candidates' => array(),
        );
        $fontFamilies = array();
        $missingCss = array();
        $fontCssSupplied = false;
        $fontMaterialized = false;
        $diagnosticCodes = array();
        $pages = array();

        foreach ( $pageReports as $page ) {
            $diagnostics = is_array($page['transform_diagnostics'] ?? null) ? $page['transform_diagnostics'] : array();
            $pageContext = array(
                'frame_id' => (string) ($page['frame_id'] ?? ''),
                'page_path' => (string) ($page['path'] ?? ''),
                'page_name' => (string) ($page['name'] ?? ''),
            );
            $pages[] = array_merge($pageContext, array('transform_diagnostics' => $diagnostics));

            $pageImages = is_array($diagnostics['images'] ?? null) ? $diagnostics['images'] : array();
            foreach ( array('paint_refs', 'node_refs', 'resolved_assets', 'image_block_count') as $key ) {
                $images[$key] += (int) ($pageImages[$key] ?? 0);
            }
            foreach ( is_array($pageImages['image_block_nodes'] ?? null) ? $pageImages['image_block_nodes'] : array() as $item ) {
                if ( is_array($item) ) {
                    $images['image_block_nodes'][] = array_merge($pageContext, $item);
                }
            }
            foreach ( is_array($pageImages['missing_assets'] ?? null) ? $pageImages['missing_assets'] : array() as $item ) {
                if ( is_array($item) ) {
                    $images['missing_assets'][] = array_merge($pageContext, $item);
                }
            }

            $pageVectors = is_array($diagnostics['vectors'] ?? null) ? $diagnostics['vectors'] : array();
            foreach ( array('nodes', 'rendered_paths', 'rendered_asset_fallbacks', 'placeholders') as $key ) {
                $vectors[$key] += (int) ($pageVectors[$key] ?? 0);
            }
            foreach ( is_array($pageVectors['placeholder_nodes'] ?? null) ? $pageVectors['placeholder_nodes'] : array() as $item ) {
                if ( is_array($item) ) {
                    $vectors['placeholder_nodes'][] = array_merge($pageContext, $item);
                }
            }

            $pageFonts = is_array($diagnostics['fonts'] ?? null) ? $diagnostics['fonts'] : array();
            $fontFamilies = $this->mergeFontFamilies($fontFamilies, is_array($pageFonts['families'] ?? null) ? $pageFonts['families'] : array());
            $missingCss = $this->mergeFontFamilies($missingCss, is_array($pageFonts['missing_css'] ?? null) ? $pageFonts['missing_css'] : array());
            $fontCssSupplied = $fontCssSupplied || true === ($pageFonts['css_supplied'] ?? false);
            $fontMaterialized = $fontMaterialized || true === ($pageFonts['materialized'] ?? false);

            $pageLayout = is_array($diagnostics['layout'] ?? null) ? $diagnostics['layout'] : array();
            $layout['large_negative_left_count'] += (int) ($pageLayout['large_negative_left_count'] ?? 0);
            $layout['fixed_root_width_count'] += (int) ($pageLayout['fixed_root_width_count'] ?? 0);
            $layout['large_absolute_offset_count'] += (int) ($pageLayout['large_absolute_offset_count'] ?? 0);
            foreach ( is_array($pageLayout['fixed_root_width_nodes'] ?? null) ? $pageLayout['fixed_root_width_nodes'] : array() as $item ) {
                if ( is_array($item) ) {
                    $layout['fixed_root_width_nodes'][] = array_merge($pageContext, $item);
                }
            }
            foreach ( is_array($pageLayout['large_absolute_offset_nodes'] ?? null) ? $pageLayout['large_absolute_offset_nodes'] : array() as $item ) {
                if ( is_array($item) ) {
                    $layout['large_absolute_offset_nodes'][] = array_merge($pageContext, $item);
                }
            }
            $underlays = is_array($pageLayout['decorative_underlays']['nodes'] ?? null) ? $pageLayout['decorative_underlays']['nodes'] : array();
            foreach ( $underlays as $item ) {
                if ( is_array($item) ) {
                    $layout['decorative_underlays']['nodes'][] = array_merge($pageContext, $item);
                }
            }
            foreach ( is_array($pageLayout['image_heavy_landmark_candidates'] ?? null) ? $pageLayout['image_heavy_landmark_candidates'] : array() as $item ) {
                if ( is_array($item) ) {
                    $layout['image_heavy_landmark_candidates'][] = array_merge($pageContext, $item);
                }
            }

            $pageDiagnosticCodes = is_array($page['diagnostic_codes'] ?? null) ? $page['diagnostic_codes'] : (is_array($diagnostics['diagnostic_codes'] ?? null) ? $diagnostics['diagnostic_codes'] : array());
            foreach ( $pageDiagnosticCodes as $code => $count ) {
                $diagnosticCodes[(string) $code] = ($diagnosticCodes[(string) $code] ?? 0) + (int) $count;
            }
        }

        $layout['decorative_underlays']['count'] = count($layout['decorative_underlays']['nodes']);
        ksort($diagnosticCodes);
        $fonts = array(
            'families' => $fontFamilies,
            'count' => count($fontFamilies),
            'css_supplied' => $fontCssSupplied,
            'materialized' => $fontMaterialized,
            'missing_css' => $missingCss,
        );
        $assets = array(
            'emitted_files' => count($assetReport),
            'paths' => array_values(array_map(static fn (array $asset): string => (string) ($asset['path'] ?? ''), $assetReport)),
        );
        $generatedSvgAssets = $this->generatedSvgAssetsFromReport($assetReport);

        return array(
            'schema' => 'blocks-engine/figma-transformer/transform-diagnostics/v1',
            'scope' => 'multi_page',
            'selection' => $this->multiPageSelectionDiagnostics($pageReports),
            'pages' => $pages,
            'images' => $images,
            'vectors' => $vectors,
            'fonts' => $fonts,
            'assets' => $assets,
            'generated_svg_assets' => $generatedSvgAssets,
            'layout' => $layout,
            'artifact_quality' => $this->artifactQualityDiagnostics($images, $vectors, $fonts, $assets, $generatedSvgAssets, $layout),
            'diagnostic_codes' => $diagnosticCodes,
        );
    }

    /**
     * @param array<string, mixed> $images
     * @param array<string, mixed> $vectors
     * @param array<string, mixed> $fonts
     * @param array<string, mixed> $assets
     * @param array<string, mixed> $generatedSvgAssets
     * @param array<string, mixed> $layout
     * @return array<string, mixed>
     */
    private function artifactQualityDiagnostics(array $images, array $vectors, array $fonts, array $assets, array $generatedSvgAssets, array $layout): array
    {
        $signals = array();

        if ( ! empty($images['missing_assets']) ) {
            $signals[] = array('severity' => 'warning', 'code' => 'missing_render_assets', 'count' => count($images['missing_assets']));
        }
        if ( ! empty($vectors['placeholders']) ) {
            $signals[] = array('severity' => 'warning', 'code' => 'vector_placeholders', 'count' => (int) $vectors['placeholders']);
        }
        if ( ! empty($fonts['missing_css']) ) {
            $signals[] = array('severity' => 'info', 'code' => 'font_css_missing', 'count' => count($fonts['missing_css']));
        }
        if ( ! empty($layout['large_negative_left_count']) ) {
            $signals[] = array('severity' => 'warning', 'code' => 'off_canvas_left_css', 'count' => (int) $layout['large_negative_left_count']);
        }
        if ( ! empty($layout['fixed_root_width_count']) ) {
            $signals[] = array('severity' => 'warning', 'code' => 'fixed_root_width', 'count' => (int) $layout['fixed_root_width_count']);
        }
        if ( ! empty($layout['large_absolute_offset_count']) ) {
            $signals[] = array('severity' => 'warning', 'code' => 'large_absolute_offsets', 'count' => (int) $layout['large_absolute_offset_count']);
        }
        if ( ! empty($layout['image_heavy_landmark_candidates']) ) {
            $signals[] = array('severity' => 'warning', 'code' => 'image_heavy_landmark_candidate', 'count' => count($layout['image_heavy_landmark_candidates']));
        }
        if ( (int) ($images['image_block_count'] ?? 0) >= 12 ) {
            $signals[] = array('severity' => 'warning', 'code' => 'excessive_image_blocks', 'count' => (int) $images['image_block_count']);
        }
        if ( (int) ($vectors['rendered_asset_fallbacks'] ?? 0) >= 8 ) {
            $signals[] = array('severity' => 'warning', 'code' => 'excessive_vector_image_fallbacks', 'count' => (int) $vectors['rendered_asset_fallbacks']);
        }
        if ( (int) ($generatedSvgAssets['bytes'] ?? 0) > 1048576 ) {
            $signals[] = array(
                'severity' => 'info',
                'code' => 'large_generated_svg_assets',
                'count' => (int) ($generatedSvgAssets['count'] ?? 0),
                'bytes' => (int) ($generatedSvgAssets['bytes'] ?? 0),
            );
        }

        $failCodes = array('missing_render_assets', 'vector_placeholders');
        $failCount = count(array_filter($signals, static fn (array $signal): bool => in_array((string) ($signal['code'] ?? ''), $failCodes, true)));
        $warningCount = count(array_filter($signals, static fn (array $signal): bool => 'warning' === ($signal['severity'] ?? null)));
        $qualityStatus = $failCount > 0 ? 'fail' : (empty($signals) ? 'pass' : 'warn');

        return array(
            'schema' => 'blocks-engine/figma-transformer/artifact-quality/v1',
            'status' => $warningCount > 0 ? 'needs_review' : (empty($signals) ? 'clean' : 'info'),
            'quality_status' => $qualityStatus,
            'signals' => $signals,
            'summary' => array(
                'missing_asset_nodes' => count($images['missing_assets'] ?? array()),
                'vector_placeholders' => (int) ($vectors['placeholders'] ?? 0),
                'missing_font_css' => count($fonts['missing_css'] ?? array()),
                'emitted_asset_files' => (int) ($assets['emitted_files'] ?? 0),
                'image_block_count' => (int) ($images['image_block_count'] ?? 0),
                'vector_image_fallbacks' => (int) ($vectors['rendered_asset_fallbacks'] ?? 0),
                'generated_svg_count' => (int) ($generatedSvgAssets['count'] ?? 0),
                'generated_svg_bytes' => (int) ($generatedSvgAssets['bytes'] ?? 0),
                'large_negative_left_count' => (int) ($layout['large_negative_left_count'] ?? 0),
                'fixed_root_width_count' => (int) ($layout['fixed_root_width_count'] ?? 0),
                'large_absolute_offset_count' => (int) ($layout['large_absolute_offset_count'] ?? 0),
                'image_heavy_landmark_candidates' => count($layout['image_heavy_landmark_candidates'] ?? array()),
            ),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $pageReports
     * @return array<string, mixed>
     */
    private function multiPageSelectionDiagnostics(array $pageReports): array
    {
        $frames = array();
        foreach ( $pageReports as $page ) {
            if ( ! is_array($page) ) {
                continue;
            }

            $frames[] = array_filter(array(
                'frame_id' => (string) ($page['frame_id'] ?? ''),
                'name' => (string) ($page['name'] ?? ''),
                'path' => (string) ($page['path'] ?? ''),
                'entrypoint' => true === ($page['entrypoint'] ?? false),
                'node_count' => (int) ($page['node_count'] ?? 0),
                'text_node_count' => (int) ($page['text_node_count'] ?? 0),
                'asset_reference_count' => (int) ($page['asset_reference_count'] ?? 0),
            ), static fn (mixed $value): bool => null !== $value && '' !== $value);
        }

        return array(
            'schema' => 'blocks-engine/figma-transformer/selection/v1',
            'mode' => 'selected_frames',
            'page_count' => count($frames),
            'selected_frames' => $frames,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $assetReport
     * @return array<string, mixed>
     */
    private function generatedSvgAssetsFromReport(array $assetReport): array
    {
        $assets = array_values(array_filter(
            $assetReport,
            static fn (array $asset): bool => 'image/svg+xml' === ($asset['mime_type'] ?? null) && str_starts_with((string) ($asset['id'] ?? ''), 'generated-vector-')
        ));
        usort($assets, static fn (array $a, array $b): int => ((int) ($b['bytes'] ?? 0) <=> (int) ($a['bytes'] ?? 0)) ?: strcmp((string) ($a['path'] ?? ''), (string) ($b['path'] ?? '')));

        return array(
            'schema' => 'blocks-engine/figma-transformer/generated-svg-assets/v1',
            'count' => count($assets),
            'bytes' => array_sum(array_map(static fn (array $asset): int => (int) ($asset['bytes'] ?? 0), $assets)),
            'paths' => array_values(array_map(static fn (array $asset): string => (string) ($asset['path'] ?? ''), $assets)),
            'largest_assets' => array_slice($assets, 0, 10),
            'assets' => $assets,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, int>
     */
    private function diagnosticCodeCounts(array $diagnostics): array
    {
        $counts = array();
        foreach ( $diagnostics as $diagnostic ) {
            if ( ! is_array($diagnostic) || ! isset($diagnostic['code']) || ! is_scalar($diagnostic['code']) ) {
                continue;
            }

            $code = (string) $diagnostic['code'];
            $counts[$code] = ($counts[$code] ?? 0) + 1;
        }

        ksort($counts);
        return $counts;
    }

    /**
     * @param array<int, string> $chunks
     */
    private function mergeCssChunks(array $chunks): string
    {
        $rules = array();
        foreach ( $chunks as $chunk ) {
            foreach ( explode("\n", $chunk) as $line ) {
                $line = trim($line);
                if ( '' !== $line ) {
                    $rules[$line] = true;
                }
            }
        }

        return implode("\n", array_keys($rules)) . (empty($rules) ? '' : "\n");
    }

    /**
     * @param array<int, mixed> ...$familySets
     * @return array<int, string>
     */
    private function mergeFontFamilies(array ...$familySets): array
    {
        $families = array();
        foreach ( $familySets as $familySet ) {
            foreach ( $familySet as $family ) {
                if ( is_scalar($family) && '' !== (string) $family ) {
                    $families[(string) $family] = true;
                }
            }
        }

        $merged = array_keys($families);
        sort($merged, SORT_NATURAL | SORT_FLAG_CASE);
        return $merged;
    }

    /**
     * @param array<int, mixed> ...$usageSets
     * @return array<int, array{family: string, weights: array<int, int>}>
     */
    private function mergeFontUsage(array ...$usageSets): array
    {
        $usage = array();
        foreach ( $usageSets as $usageSet ) {
            foreach ( $usageSet as $item ) {
                if ( ! is_array($item) ) {
                    continue;
                }

                $family = isset($item['family']) && is_scalar($item['family']) ? (string) $item['family'] : '';
                if ( '' === $family ) {
                    continue;
                }

                if ( ! isset($usage[$family]) ) {
                    $usage[$family] = array();
                }

                $weights = is_array($item['weights'] ?? null) ? $item['weights'] : array($item['weight'] ?? 400);
                foreach ( $weights as $weight ) {
                    if ( is_numeric($weight) ) {
                        $usage[$family][(int) $weight] = true;
                    }
                }
            }
        }

        ksort($usage, SORT_NATURAL | SORT_FLAG_CASE);
        $merged = array();
        foreach ( $usage as $family => $weights ) {
            $weightValues = array_keys($weights);
            sort($weightValues, SORT_NUMERIC);
            $merged[] = array('family' => $family, 'weights' => $weightValues);
        }

        return $merged;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function assetReportFromFiles(array $files): array
    {
        $assets = array();
        foreach ( $files as $file ) {
            $content = isset($file['content']) && is_scalar($file['content']) ? (string) $file['content'] : '';
            $assets[] = array(
                'id'        => (string) ($file['source_id'] ?? ''),
                'path'      => (string) ($file['path'] ?? ''),
                'mime_type' => (string) ($file['mime_type'] ?? 'application/octet-stream'),
                'bytes'     => strlen($content),
                'hash'      => hash('sha256', $content),
            );
        }

        return $assets;
    }

    /**
     * Project Figma's static artifact into the generic compiled-site metadata contract.
     *
     * @param array<string, mixed> $artifact Static HTML emitter result.
     * @return array<string, mixed>
     */
    private function compiledSiteSourceReport(array $artifact): array
    {
        $htmlReport = is_array($artifact['source_report'] ?? null) ? $artifact['source_report'] : array();
        $fontUsage  = is_array($htmlReport['font_usage'] ?? null) ? $htmlReport['font_usage'] : array();

        return array_filter(array(
            'schema'     => 'blocks-engine/figma-transformer/compiled-site/v1',
            'entry_path' => 'index.html',
            'pages'      => is_array($htmlReport['pages'] ?? null) ? $htmlReport['pages'] : array(),
            'assets'     => is_array($artifact['assets'] ?? null) ? $artifact['assets'] : array(),
            'totals'     => array_filter(array(
                'page_count'  => is_array($htmlReport['pages'] ?? null) ? count($htmlReport['pages']) : 0,
                'asset_count' => is_array($artifact['assets'] ?? null) ? count($artifact['assets']) : 0,
                'file_count'  => is_array($artifact['files'] ?? null) ? count($artifact['files']) : 0,
            ), static fn (mixed $value): bool => 0 !== $value),
            'theme'      => array_filter(array(
                'font_usage' => $fontUsage,
            ), static fn (mixed $value): bool => array() !== $value && '' !== $value),
        ), static fn (mixed $value): bool => array() !== $value && '' !== $value);
    }
}
