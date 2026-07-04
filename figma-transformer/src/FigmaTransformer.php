<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer;

use Automattic\BlocksEngine\FigmaTransformer\Contract\FigmaTransformResult;
use Automattic\BlocksEngine\FigmaTransformer\Diagnostics\DiagnosticAggregation;
use Automattic\BlocksEngine\FigmaTransformer\Diagnostics\LayoutMismatchReportBuilder;
use Automattic\BlocksEngine\FigmaTransformer\Diagnostics\RenderStyleMismatchReportBuilder;
use Automattic\BlocksEngine\FigmaTransformer\FigFile\FigArchiveReader;
use Automattic\BlocksEngine\FigmaTransformer\Html\FontResolver;
use Automattic\BlocksEngine\FigmaTransformer\Html\SourceLossCoverageBuilder;
use Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter;
use Automattic\BlocksEngine\FigmaTransformer\Parity\ParityReportBuilder;
use Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ScenegraphFrameInspector;
use Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ScenegraphIndex;
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
        private readonly LayoutMismatchReportBuilder $layoutMismatchReportBuilder = new LayoutMismatchReportBuilder(),
        private readonly RenderStyleMismatchReportBuilder $renderStyleMismatchReportBuilder = new RenderStyleMismatchReportBuilder(),
        private readonly ScenegraphNormalizer $scenegraphNormalizer = new ScenegraphNormalizer(),
        private readonly ScenegraphFrameInspector $frameInspector = new ScenegraphFrameInspector(),
        private readonly ScenegraphPagePlanner $pagePlanner = new ScenegraphPagePlanner(),
        private readonly ScenegraphIndex $scenegraphIndex = new ScenegraphIndex()
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
     * Inspect minimal Kiwi node-gating metadata without materializing full nodes.
     *
     * @param array<string, mixed> $options Inspection options.
     * @return array<string, mixed>
     */
    public function inspectKiwiGateFile(string $path, array $options = array()): array
    {
        $options['inspect_kiwi_gate'] = true;
        $options['kiwi_gate_only'] = true;
        $archive = $this->archiveReader->read($path, $options);
        $chunks = is_array($archive['archive']['canvas']['chunks'] ?? null) ? $archive['archive']['canvas']['chunks'] : array();
        $reports = array();

        foreach ( $chunks as $chunk ) {
            if ( ! is_array($chunk) ) {
                continue;
            }

            $payload = $chunk['payload'] ?? array();
            if ( ! is_array($payload) || ! is_array($payload['kiwi_node_gate'] ?? null) ) {
                continue;
            }

            $reports[] = array(
                'chunk_index' => (int) ($chunk['index'] ?? count($reports)),
                'compressed_bytes' => (int) ($chunk['compressed_bytes'] ?? 0),
                'inflated_bytes' => (int) ($chunk['inflated_bytes'] ?? 0),
                'compression' => (string) ($chunk['compression'] ?? ''),
                'kiwi_node_gate' => $payload['kiwi_node_gate'],
            );
        }

        return array(
            'schema' => 'blocks-engine/figma-transformer/kiwi-gate-inspection/v1',
            'status' => empty($archive['diagnostics']) ? 'success' : 'success_with_warnings',
            'input' => $archive['input'] ?? array(),
            'report_count' => count($reports),
            'reports' => $reports,
            'diagnostics' => is_array($archive['diagnostics'] ?? null) ? $archive['diagnostics'] : array(),
        );
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
                'figma_transformer_nested_fig_preflight_failed',
                'figma_transformer_nested_fig_unreadable',
                'figma_transformer_tempfile_failed',
                'figma_transformer_missing_canvas',
                'figma_transformer_canvas_decode_preflight_failed',
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

        $responsiveVariants = $this->responsivePageVariants($options);
        if ( null !== $responsiveVariants ) {
            return $this->transformResponsivePage($scenegraph, $responsiveVariants, $options);
        }

        $startedAt = microtime(true);
        $normalized = $this->scenegraphNormalizer->normalize($scenegraph, $options);
        $artifact    = $this->htmlEmitter->emit($normalized, $options);
        $artifact    = $this->withLayoutMismatchReport($artifact, $options);
        $artifact    = $this->withRenderStyleMismatchReport($artifact, $options);
        $diagnostics = array_merge($normalized['diagnostics'] ?? array(), $artifact['diagnostics']);
        $parity      = $this->parityReportBuilder->build($options['parity'] ?? array());
        $transformDiagnostics = is_array($artifact['source_report']['transform_diagnostics'] ?? null) ? $artifact['source_report']['transform_diagnostics'] : array();

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
                'vector_placeholder_count' => (int) ($transformDiagnostics['vectors']['placeholders'] ?? 0),
            )
        );
    }

    /**
     * Extract a usable responsive variant list (two or more breakpoint frames)
     * from the per-page options the multi-page loop forwards. Returns null when
     * the page is a single-frame (non-responsive) page so the caller keeps the
     * existing one-frame {@see StaticHtmlEmitter::emit()} path untouched.
     *
     * @param array<string, mixed> $options
     * @return array<int, array<string, mixed>>|null
     */
    private function responsivePageVariants(array $options): ?array
    {
        if ( ! is_array($options['responsive_variants'] ?? null) ) {
            return null;
        }

        $variants = array_values(array_filter(
            $options['responsive_variants'],
            static fn (mixed $variant): bool => is_array($variant)
                && isset($variant['frame_id'])
                && is_scalar($variant['frame_id'])
                && '' !== (string) $variant['frame_id']
        ));

        return count($variants) > 1 ? $variants : null;
    }

    /**
     * Emit one responsive page (desktop + mobile/tablet breakpoint variants)
     * through {@see StaticHtmlEmitter::emitSite()} so the primary (widest)
     * variant renders as the base layout and narrower variants emit
     * `@media (max-width: …)` overrides. This is the live wiring point that
     * turns a planner-detected responsive variant-group into ONE `@media`-aware
     * page instead of one page per frame (#247).
     *
     * The full scenegraph is normalized (no `frame_id` limit) so every variant
     * frame stays in the emitter's node map; emitSite then walks only the page's
     * variant frames. The result envelope mirrors {@see emit()} so the
     * multi-page loop aggregates it identically to a single-frame page.
     *
     * @param array<string, mixed>             $scenegraph
     * @param array<int, array<string, mixed>> $variants Ordered breakpoint variants (widest first).
     * @param array<string, mixed>             $options
     */
    private function transformResponsivePage(array $scenegraph, array $variants, array $options): FigmaTransformResult
    {
        $startedAt = microtime(true);

        $primaryFrameId = (string) ($variants[0]['frame_id'] ?? '');
        $pageName = isset($options['page_name']) && is_scalar($options['page_name']) ? (string) $options['page_name'] : '';

        // Normalize the FULL scenegraph (drop the single-frame selection) so
        // every variant frame is present in the emitter node map. render_document
        // prevents the normalizer from auto-selecting a single frame when no
        // frame_id is passed — the full document render tree is needed so that
        // appendNodeMap can overwrite the flat node_map's stale embedded children
        // with refreshed, instance-resolved versions for all variant frames.
        $normalizeOptions = $options;
        unset($normalizeOptions['frame_id'], $normalizeOptions['responsive_variants'], $normalizeOptions['page_name']);
        $normalizeOptions['render_document'] = true;
        $normalizeOptions['document_frame_ids'] = array_values(array_filter(array_map(
            static fn (array $variant): string => isset($variant['frame_id']) && is_scalar($variant['frame_id']) ? (string) $variant['frame_id'] : '',
            $variants
        )));
        $normalized = $this->scenegraphNormalizer->normalize($scenegraph, $normalizeOptions);

        $pagePlan = array(
            'pages' => array(
                array(
                    'frame_id'   => $primaryFrameId,
                    'name'       => '' !== $pageName ? $pageName : ($normalized['name'] ?? $primaryFrameId),
                    'path'       => 'index.html',
                    'entrypoint' => true,
                    'responsive' => true,
                    'variants'   => $variants,
                ),
            ),
        );

        $emitOptions = $options;
        unset($emitOptions['responsive_variants'], $emitOptions['frame_id'], $emitOptions['page_name']);

        $artifact    = $this->htmlEmitter->emitSite($normalized, $pagePlan, $emitOptions);
        $artifact    = $this->withLayoutMismatchReport($artifact, $options);
        $artifact    = $this->withRenderStyleMismatchReport($artifact, $options);
        $diagnostics = array_merge($normalized['diagnostics'] ?? array(), $artifact['diagnostics']);
        $parity      = $this->parityReportBuilder->build($options['parity'] ?? array());

        $transformDiagnostics = is_array($artifact['source_report']['transform_diagnostics'] ?? null) ? $artifact['source_report']['transform_diagnostics'] : array();

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
                'node_count'             => $artifact['metrics']['node_count'] ?? 0,
                'text_node_count'        => count($normalized['text_inventory'] ?? array()),
                'asset_reference_count'  => count($normalized['asset_references'] ?? array()),
                'asset_count'            => $artifact['metrics']['asset_count'] ?? 0,
                'file_count'             => count($artifact['files']),
                'breakpoint_count'       => count($variants),
                'transform_duration_ms'  => (int) round((microtime(true) - $startedAt) * 1000),
                'vector_placeholder_count' => (int) ($transformDiagnostics['vectors']['placeholders'] ?? 0),
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
        $cssChunkIndexesByPath = array();
        $pageReports = array();
        $visualNodeMap = array();
        $fontFamilies = array();
        $fontUsage = array();
        $fontCssSupplied = false;
        $nodeCount = 0;
        $textNodeCount = 0;
        $assetReferenceCount = 0;
        $linkTargetPaths = $this->linkTargetPathsFromPages($pages, $scenegraph);

        foreach ( $pages as $page ) {
            if ( ! is_array($page) ) {
                continue;
            }

            $frameId = isset($page['frame_id']) && is_scalar($page['frame_id']) ? (string) $page['frame_id'] : '';
            if ( '' === $frameId ) {
                continue;
            }

            $path = isset($page['path']) && is_scalar($page['path']) && '' !== (string) $page['path'] ? (string) $page['path'] : ((true === ($page['entrypoint'] ?? false)) ? 'index.html' : (string) ($page['slug'] ?? $frameId) . '.html');
            $pageOptions = $options;
            $pageOptions['frame_id'] = $frameId;
            $pageOptions['layout_mismatch_options'] = is_array($pageOptions['layout_mismatch_options'] ?? null) ? $pageOptions['layout_mismatch_options'] : array();
            $pageOptions['layout_mismatch_options']['page_path'] = $path;
            $pageOptions['render_style_mismatch_options'] = is_array($pageOptions['render_style_mismatch_options'] ?? null) ? $pageOptions['render_style_mismatch_options'] : array();
            $pageOptions['render_style_mismatch_options']['page_path'] = $path;
            $pageOptions['static_site_page_path'] = $path;
            $pageOptions['implicit_route_page_plan'] = $pagePlan;
            $pageOptions['inline_css'] = false;
            unset($pageOptions['multi_page'], $pageOptions['include_all_pages'], $pageOptions['frame_ids'], $pageOptions['entry_frame_id'], $pageOptions['max_pages'], $pageOptions['frame_slug_map'], $pageOptions['responsive_variants'], $pageOptions['page_name']);
            $pageOptions['link_target_paths'] = $linkTargetPaths;

            // When the planner collapsed responsive sibling frames into this one
            // page (#251), forward the ordered breakpoint variants so the live
            // transform emits ONE `@media`-aware page (primary base layout +
            // narrower `max-width` overrides) instead of just the primary frame.
            // Single-variant pages carry no `responsive_variants`, so they keep
            // the existing one-frame emission path unchanged.
            $pageVariants = is_array($page['variants'] ?? null) ? array_values($page['variants']) : array();
            if ( true === ($page['responsive'] ?? false) && count($pageVariants) > 1 ) {
                $pageOptions['responsive_variants'] = $pageVariants;
                $pageOptions['page_name'] = (string) ($page['name'] ?? $frameId);
            }

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
            $pageIndex = count($pageReports);
            $pageVisualNodeMap = $this->visualNodeMapWithPageTrace(
                is_array($pageHtmlReport['visual_node_map'] ?? null) ? array_values($pageHtmlReport['visual_node_map']) : array(),
                $pageIndex,
                $frameId,
                $path
            );
            foreach ( $pageVisualNodeMap as $visualNode ) {
                if ( is_array($visualNode) ) {
                    $visualNodeMap[] = $visualNode;
                }
            }
            $fontFamilies = $this->mergeFontFamilies($fontFamilies, $pageFontFamilies);
            $fontUsage = $this->mergeFontUsage($fontUsage, $pageFontUsage);
            $fontCssSupplied = $fontCssSupplied || true === ($pageHtmlReport['font_css_supplied'] ?? false);

            $html = $this->fileContent($pageResult['files'] ?? array(), 'index.html');
            $css = $this->fileContent($pageResult['files'] ?? array(), 'style.css');
            if ( '' !== $css ) {
                $css = $this->scopeRootCustomPropertiesToPage($css, $html);
                $cssChunkIndexesByPath[$path] = count($cssChunks);
                $cssChunks[] = $css;
            }

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
                'visual_node_count' => count($pageVisualNodeMap),
                'visual_node_map' => $pageVisualNodeMap,
                'transform_diagnostics' => $pageTransformDiagnostics,
                'diagnostic_codes' => $this->diagnosticCodeCounts($pageDiagnostics),
            );
        }

        $mergedCss = $this->mergeCssChunks($cssChunks);
        $css = $mergedCss['css'];
        foreach ( $files as $fileIndex => $file ) {
            if ( 'text/html' !== ($file['mime_type'] ?? '') || ! isset($file['content'], $file['path']) || ! is_scalar($file['path']) ) {
                continue;
            }
            $chunkIndex = $cssChunkIndexesByPath[(string) $file['path']] ?? null;
            if ( null === $chunkIndex || empty($mergedCss['class_maps'][$chunkIndex]) ) {
                continue;
            }
            $files[$fileIndex]['content'] = $this->applyCssClassRenameMapToHtml((string) $file['content'], $mergedCss['class_maps'][$chunkIndex]);
        }
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
        $transformDiagnostics = $this->mergePageTransformDiagnostics($pageReports, $assetReport, $visualNodeMap);
        $parity = $this->parityReportBuilder->build($options['parity'] ?? array());
        $artifact = array(
            'files' => $files,
            'assets' => $assetReport,
            'source_report' => array(
                'pages' => $pageReports,
                'page_plan' => $pagePlan,
                'visual_node_count' => count($visualNodeMap),
                'visual_node_map' => $visualNodeMap,
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
                'vector_placeholder_count' => (int) ($transformDiagnostics['vectors']['placeholders'] ?? 0),
            )
        );
    }

    /**
     * @param array<int, mixed> $visualNodeMap
     * @return array<int, array<string, mixed>>
     */
    private function visualNodeMapWithPageTrace(array $visualNodeMap, int $pageIndex, string $frameId, string $path): array
    {
        $traced = array();
        foreach ( $visualNodeMap as $visualNode ) {
            if ( ! is_array($visualNode) ) {
                continue;
            }

            $visualNode['source_page_index'] = $pageIndex;
            $visualNode['source_page_frame_id'] = $frameId;
            $visualNode['page_path'] = isset($visualNode['page_path']) && is_scalar($visualNode['page_path']) && '' !== (string) $visualNode['page_path']
                ? (string) $visualNode['page_path']
                : $path;
            $traced[] = $visualNode;
        }

        return $traced;
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
     * @param array<string, mixed> $artifact
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function withLayoutMismatchReport(array $artifact, array $options): array
    {
        $evidence = $this->layoutMismatchEvidenceFromOptions($options);
        if ( empty($evidence) ) {
            return $artifact;
        }

        $sourceReport = is_array($artifact['source_report'] ?? null) ? $artifact['source_report'] : array();
        $reportOptions = is_array($options['layout_mismatch_options'] ?? null) ? $options['layout_mismatch_options'] : array();
        if ( isset($options['layout_mismatch_threshold']) ) {
            $reportOptions['threshold'] = $options['layout_mismatch_threshold'];
        }
        if ( isset($options['layout_mismatch_size_threshold']) ) {
            $reportOptions['size_threshold'] = $options['layout_mismatch_size_threshold'];
        }

        $layoutMismatch = $this->layoutMismatchReportBuilder->build($sourceReport, $evidence, $reportOptions);
        $transformDiagnostics = is_array($sourceReport['transform_diagnostics'] ?? null) ? $sourceReport['transform_diagnostics'] : array();
        $layout = is_array($transformDiagnostics['layout'] ?? null) ? $transformDiagnostics['layout'] : array();
        $layout['layout_mismatch'] = $layoutMismatch;
        $layout['layout_mismatch_count'] = (int) ($layoutMismatch['summary']['diagnostic_count'] ?? 0);
        $layout['layout_mismatch_status'] = (string) ($layoutMismatch['status'] ?? 'not_run');
        $transformDiagnostics['layout'] = $layout;
        $transformDiagnostics['artifact_quality'] = $this->withLayoutMismatchArtifactQuality(
            is_array($transformDiagnostics['artifact_quality'] ?? null) ? $transformDiagnostics['artifact_quality'] : array(),
            $layoutMismatch
        );
        $sourceReport['transform_diagnostics'] = $transformDiagnostics;
        $artifact['source_report'] = $sourceReport;

        return $artifact;
    }

    /**
     * @param array<string, mixed> $artifact
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function withRenderStyleMismatchReport(array $artifact, array $options): array
    {
        $evidence = $this->renderStyleMismatchEvidenceFromOptions($options);
        if ( empty($evidence) ) {
            return $artifact;
        }

        $sourceReport = is_array($artifact['source_report'] ?? null) ? $artifact['source_report'] : array();
        $reportOptions = is_array($options['render_style_mismatch_options'] ?? null) ? $options['render_style_mismatch_options'] : array();
        $renderStyleMismatch = $this->renderStyleMismatchReportBuilder->build($sourceReport, $evidence, $reportOptions);
        $transformDiagnostics = is_array($sourceReport['transform_diagnostics'] ?? null) ? $sourceReport['transform_diagnostics'] : array();
        $layout = is_array($transformDiagnostics['layout'] ?? null) ? $transformDiagnostics['layout'] : array();
        $layout['render_style'] = $renderStyleMismatch;
        $layout['render_style_mismatch_count'] = (int) ($renderStyleMismatch['summary']['diagnostic_count'] ?? 0);
        $layout['render_style_mismatch_status'] = (string) ($renderStyleMismatch['status'] ?? 'not_run');
        $transformDiagnostics['layout'] = $layout;
        $transformDiagnostics['artifact_quality'] = $this->withRenderStyleMismatchArtifactQuality(
            is_array($transformDiagnostics['artifact_quality'] ?? null) ? $transformDiagnostics['artifact_quality'] : array(),
            $renderStyleMismatch
        );
        $sourceReport['transform_diagnostics'] = $transformDiagnostics;
        $artifact['source_report'] = $sourceReport;

        return $artifact;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function layoutMismatchEvidenceFromOptions(array $options): array
    {
        foreach ( array('layout_mismatch', 'layout_mismatch_evidence', 'generated_dom_boxes', 'dom_boxes') as $key ) {
            if ( is_array($options[$key] ?? null) ) {
                return $options[$key];
            }
        }

        if ( is_array($options['metadata']['generated_dom_boxes'] ?? null) ) {
            return $options['metadata']['generated_dom_boxes'];
        }
        if ( is_array($options['metadata']['dom_boxes'] ?? null) ) {
            return $options['metadata']['dom_boxes'];
        }

        return array();
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function renderStyleMismatchEvidenceFromOptions(array $options): array
    {
        foreach ( array('render_style_mismatch', 'render_style_mismatch_evidence', 'generated_render_evidence', 'render_evidence') as $key ) {
            if ( is_array($options[$key] ?? null) ) {
                return $options[$key];
            }
        }

        if ( is_array($options['metadata']['generated_render_evidence'] ?? null) ) {
            return $options['metadata']['generated_render_evidence'];
        }
        if ( is_array($options['metadata']['render_evidence'] ?? null) ) {
            return $options['metadata']['render_evidence'];
        }

        return array();
    }

    /**
     * @param array<string, mixed> $artifactQuality
     * @param array<string, mixed> $layoutMismatch
     * @return array<string, mixed>
     */
    private function withLayoutMismatchArtifactQuality(array $artifactQuality, array $layoutMismatch): array
    {
        $count = (int) ($layoutMismatch['summary']['diagnostic_count'] ?? 0);
        $artifactQuality['schema'] = $artifactQuality['schema'] ?? 'blocks-engine/figma-transformer/artifact-quality/v1';
        $signals = is_array($artifactQuality['signals'] ?? null) ? $artifactQuality['signals'] : array();
        if ( $count > 0 ) {
            $signals[] = array('severity' => 'warning', 'code' => 'layout_mismatch', 'count' => $count);
        }
        $artifactQuality['signals'] = $signals;
        $summary = is_array($artifactQuality['summary'] ?? null) ? $artifactQuality['summary'] : array();
        $summary['layout_mismatch_count'] = $count;
        $summary['layout_mismatch_status'] = (string) ($layoutMismatch['status'] ?? 'not_run');
        $summary['misplaced_element_count'] = (int) ($layoutMismatch['summary']['code_counts']['misplaced_element'] ?? 0);
        $summary['element_size_mismatch_count'] = (int) ($layoutMismatch['summary']['code_counts']['element_size_mismatch'] ?? 0);
        $summary['element_outside_parent_bounds_count'] = (int) ($layoutMismatch['summary']['code_counts']['element_outside_parent_bounds'] ?? 0);
        $artifactQuality['summary'] = $summary;
        if ( $count > 0 ) {
            $artifactQuality['status'] = 'needs_review';
            $artifactQuality['quality_status'] = 'warn';
        }

        return $artifactQuality;
    }

    /**
     * @param array<string, mixed> $artifactQuality
     * @param array<string, mixed> $renderStyleMismatch
     * @return array<string, mixed>
     */
    private function withRenderStyleMismatchArtifactQuality(array $artifactQuality, array $renderStyleMismatch): array
    {
        $count = (int) ($renderStyleMismatch['summary']['diagnostic_count'] ?? 0);
        $artifactQuality['schema'] = $artifactQuality['schema'] ?? 'blocks-engine/figma-transformer/artifact-quality/v1';
        $signals = is_array($artifactQuality['signals'] ?? null) ? $artifactQuality['signals'] : array();
        if ( $count > 0 ) {
            $signals[] = array('severity' => 'warning', 'code' => 'render_style_mismatch', 'count' => $count);
        }
        $artifactQuality['signals'] = $signals;
        $summary = is_array($artifactQuality['summary'] ?? null) ? $artifactQuality['summary'] : array();
        $renderSummary = is_array($renderStyleMismatch['summary'] ?? null) ? $renderStyleMismatch['summary'] : array();
        foreach ( array('font_mismatch_count', 'color_mismatch_count', 'background_mismatch_count', 'border_mismatch_count', 'opacity_mismatch_count', 'asset_mismatch_count', 'text_metric_mismatch_count', 'matched_node_count', 'unmatched_source_node_count', 'match_ratio') as $key ) {
            $summary['render_style_' . $key] = $renderSummary[$key] ?? (str_ends_with($key, '_count') ? 0 : 0.0);
        }
        $summary['render_style_mismatch_count'] = $count;
        $summary['render_style_mismatch_status'] = (string) ($renderStyleMismatch['status'] ?? 'not_run');
        $artifactQuality['summary'] = $summary;
        if ( $count > 0 ) {
            $artifactQuality['status'] = 'needs_review';
            $artifactQuality['quality_status'] = 'warn';
        }

        return $artifactQuality;
    }

    /**
     * Map planned frame ids to their generated page paths so per-page emission can resolve NODE/prototype links to slugs.
     *
     * @param array<int, mixed> $pages
     * @return array<string, string>
     */
    private function linkTargetPathsFromPages(array $pages, array $scenegraph): array
    {
        $map = array();
        $descendantRootPaths = array();
        foreach ( $pages as $page ) {
            if ( ! is_array($page) ) {
                continue;
            }

            $frameId = isset($page['frame_id']) && is_scalar($page['frame_id']) ? (string) $page['frame_id'] : '';
            $path = isset($page['path']) && is_scalar($page['path']) && '' !== (string) $page['path']
                ? (string) $page['path']
                : (true === ($page['entrypoint'] ?? false) ? 'index.html' : (string) ($page['slug'] ?? $frameId) . '.html');
            if ( '' === $frameId || '' === $path ) {
                continue;
            }

            $map[$frameId] = $path;
            $descendantRootPaths[$frameId] = $path;

            foreach ( is_array($page['variants'] ?? null) ? $page['variants'] : array() as $variant ) {
                if ( ! is_array($variant) || ! isset($variant['frame_id']) || ! is_scalar($variant['frame_id']) ) {
                    continue;
                }

                $variantFrameId = (string) $variant['frame_id'];
                if ( '' === $variantFrameId ) {
                    continue;
                }

                $map[$variantFrameId] = $path;
                $descendantRootPaths[$variantFrameId] = $path;
            }
        }

        if ( empty($descendantRootPaths) ) {
            return $map;
        }

        $index = $this->scenegraphIndex->build($scenegraph);
        $childrenIndex = is_array($index['children_index'] ?? null) ? $index['children_index'] : array();
        foreach ( $descendantRootPaths as $rootId => $path ) {
            $this->mapDescendantLinkTargets((string) $rootId, (string) $path, $childrenIndex, $map);
        }

        return $map;
    }

    /**
     * Map every node below a planned page frame to that page's generated path.
     * Figma prototype links often target a section/text/control inside a frame
     * instead of the page frame itself; static HTML can only navigate to the
     * generated page path, so descendants inherit their containing page target.
     *
     * @param array<string, array<int, string>> $childrenIndex
     * @param array<string, string>             $map
     */
    private function mapDescendantLinkTargets(string $rootId, string $path, array $childrenIndex, array &$map): void
    {
        $stack = is_array($childrenIndex[$rootId] ?? null) ? $childrenIndex[$rootId] : array();
        $visited = array($rootId => true);
        $guard = 0;

        while ( array() !== $stack && $guard < 200000 ) {
            ++$guard;
            $nodeId = array_pop($stack);
            if ( ! is_string($nodeId) || isset($visited[$nodeId]) ) {
                continue;
            }

            $visited[$nodeId] = true;
            $map[$nodeId] = $path;

            foreach ( is_array($childrenIndex[$nodeId] ?? null) ? $childrenIndex[$nodeId] : array() as $childId ) {
                if ( is_string($childId) && ! isset($visited[$childId]) ) {
                    $stack[] = $childId;
                }
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $pageReports
     * @param array<int, array<string, mixed>> $assetReport
     * @param array<int, array<string, mixed>> $visualNodeMap
     * @return array<string, mixed>
     */
    private function mergePageTransformDiagnostics(array $pageReports, array $assetReport, array $visualNodeMap): array
    {
        $images = array('paint_refs' => 0, 'node_refs' => 0, 'resolved_assets' => 0, 'image_block_count' => 0, 'total_node_count' => 0, 'image_block_nodes' => array(), 'missing_assets' => array(), 'asset_nodes' => array());
        $vectors = array('nodes' => 0, 'rendered_paths' => 0, 'rendered_asset_fallbacks' => 0, 'vector_network_decoded' => 0, 'boolean_operations_composed' => 0, 'placeholders' => 0, 'placeholder_nodes' => array(), 'placeholder_reasons' => array());
        $layout = array(
            'large_negative_left_count' => 0,
            'large_css_offset_count' => 0,
            'large_css_offset_nodes' => array(),
            'off_canvas_visual_node_count' => 0,
            'off_canvas_visual_nodes' => array(),
            'large_absolute_offset_count' => 0,
            'large_absolute_offset_nodes' => array(),
            'empty_visible_container_count' => 0,
            'empty_visible_container_blocker_count' => 0,
            'empty_visible_container_categories' => array(),
            'empty_visible_containers' => array(),
            'decorative_underlays' => array('count' => 0, 'nodes' => array()),
            'sticky_ghosts' => array('count' => 0, 'candidates' => array()),
            'image_heavy_landmark_candidates' => array(),
            'layout_mismatch_count' => 0,
            'layout_mismatch_status' => 'not_evaluated',
            'layout_mismatches' => array(),
            'layout_mismatch_clusters' => array(),
            'render_style_mismatch_count' => 0,
            'render_style_mismatch_status' => 'not_evaluated',
            'render_style' => array(
                'schema' => RenderStyleMismatchReportBuilder::SCHEMA,
                'status' => 'not_evaluated',
                'summary' => array(
                    'source_node_count' => 0,
                    'render_node_count' => 0,
                    'matched_node_count' => 0,
                    'unmatched_source_node_count' => 0,
                    'match_ratio' => 0.0,
                    'diagnostic_count' => 0,
                    'reported_diagnostic_count' => 0,
                    'truncated' => false,
                    'font_mismatch_count' => 0,
                    'color_mismatch_count' => 0,
                    'background_mismatch_count' => 0,
                    'border_mismatch_count' => 0,
                    'opacity_mismatch_count' => 0,
                    'asset_mismatch_count' => 0,
                    'text_metric_mismatch_count' => 0,
                    'category_counts' => array(),
                ),
                'diagnostics' => array(),
            ),
        );
        $fontFamilies = array();
        $fontUsage = array();
        $fontCssSupplied = false;
        $fontMaterialized = false;
        $diagnosticCodes = array();
        $pages = array();
        $links = array(
            'schema'             => 'blocks-engine/figma-transformer/link-coverage/v1',
            'sources_found'      => 0,
            'anchors_emitted'    => 0,
            'url_links'          => 0,
            'node_links'         => 0,
            'toc_links'          => 0,
            'implicit_route_links' => 0,
            'implicit_route_self_suppressed' => 0,
            'route_targets'      => array(),
            'unresolved'         => 0,
            'unresolved_targets' => array(),
        );
        $css = array(
            'schema' => 'blocks-engine/figma-transformer/css-diagnostics/v1',
            'invalid_numeric_token_count' => 0,
            'invalid_numeric_tokens' => array(),
        );
        $decisionTraces = array(
            'schema' => 'blocks-engine/figma-transformer/decision-traces/v1',
            'trace_count' => 0,
            'reason_counts' => array(),
            'domain_counts' => array(),
            'samples' => array(),
        );
        $positionalParity = array(
            'schema' => 'blocks-engine/figma-transformer/positional-parity/v1',
            'full_bleed_viewport_width_count' => 0,
            'full_bleed_breakout_count' => 0,
            'mirrored_transform_count' => 0,
            'reflected_full_bleed_count' => 0,
            'fixed_over_root_width_underlay_count' => 0,
            'fixed_over_root_width_underlays' => array(),
            'chrome_overflow_count' => 0,
            'chrome_overflow_nodes' => array(),
            'root_stacking_trace_count' => 0,
            'root_stacking_reason_counts' => array(),
            'decision_trace_samples' => array(),
        );

        foreach ( $pageReports as $page ) {
            $diagnostics = is_array($page['transform_diagnostics'] ?? null) ? $page['transform_diagnostics'] : array();
            $pageContext = array(
                'frame_id' => (string) ($page['frame_id'] ?? ''),
                'page_path' => (string) ($page['path'] ?? ''),
                'page_name' => (string) ($page['name'] ?? ''),
            );
            $pages[] = array_merge($pageContext, array('transform_diagnostics' => $diagnostics));

            $pageImages = is_array($diagnostics['images'] ?? null) ? $diagnostics['images'] : array();
            DiagnosticAggregation::addIntegerCounts($images, $pageImages, array('paint_refs', 'node_refs', 'resolved_assets', 'image_block_count', 'total_node_count'));
            DiagnosticAggregation::appendContextSamples($images, 'image_block_nodes', $pageImages, 'image_block_nodes', $pageContext);
            DiagnosticAggregation::appendContextSamples($images, 'missing_assets', $pageImages, 'missing_assets', $pageContext);
            DiagnosticAggregation::appendContextSamples($images, 'asset_nodes', $pageImages, 'asset_nodes', $pageContext);

            $pageVectors = is_array($diagnostics['vectors'] ?? null) ? $diagnostics['vectors'] : array();
            DiagnosticAggregation::addIntegerCounts($vectors, $pageVectors, array('nodes', 'rendered_paths', 'rendered_asset_fallbacks', 'vector_network_decoded', 'boolean_operations_composed', 'placeholders'));
            DiagnosticAggregation::appendContextSamples($vectors, 'placeholder_nodes', $pageVectors, 'placeholder_nodes', $pageContext);
            DiagnosticAggregation::addCounterMap($vectors['placeholder_reasons'], is_array($pageVectors['placeholder_reasons'] ?? null) ? $pageVectors['placeholder_reasons'] : array());

            $pageFonts = is_array($diagnostics['fonts'] ?? null) ? $diagnostics['fonts'] : array();
            $fontFamilies = $this->mergeFontFamilies($fontFamilies, is_array($pageFonts['families'] ?? null) ? $pageFonts['families'] : array());
            $fontUsage = $this->mergeFontUsage($fontUsage, is_array($pageFonts['usage'] ?? null) ? $pageFonts['usage'] : array());
            $fontCssSupplied = $fontCssSupplied || true === ($pageFonts['css_supplied'] ?? false);
            $fontMaterialized = $fontMaterialized || true === ($pageFonts['materialized'] ?? false);

            $pageLinks = is_array($diagnostics['links'] ?? null) ? $diagnostics['links'] : array();
            DiagnosticAggregation::addIntegerCounts($links, $pageLinks, array('sources_found', 'anchors_emitted', 'url_links', 'node_links', 'toc_links', 'implicit_route_links', 'implicit_route_self_suppressed', 'unresolved'));
            DiagnosticAggregation::appendContextSamples($links, 'route_targets', $pageLinks, 'route_targets', $pageContext);
            DiagnosticAggregation::appendContextSamples($links, 'unresolved_targets', $pageLinks, 'unresolved_targets', $pageContext);

            $pageCss = is_array($diagnostics['css'] ?? null) ? $diagnostics['css'] : array();
            DiagnosticAggregation::addIntegerCounts($css, $pageCss, array('invalid_numeric_token_count'));
            DiagnosticAggregation::appendContextSamples($css, 'invalid_numeric_tokens', $pageCss, 'invalid_numeric_tokens', $pageContext);
            $this->mergeDecisionTraceDiagnostics($decisionTraces, is_array($diagnostics['decision_traces'] ?? null) ? $diagnostics['decision_traces'] : array(), $pageContext);

            $pageLayout = is_array($diagnostics['layout'] ?? null) ? $diagnostics['layout'] : array();
            DiagnosticAggregation::addIntegerCounts($layout, $pageLayout, array('large_negative_left_count', 'large_css_offset_count', 'off_canvas_visual_node_count', 'large_absolute_offset_count', 'empty_visible_container_count', 'empty_visible_container_blocker_count'));
            $this->mergePositionalParityDiagnostics($positionalParity, is_array($pageLayout['positional_parity'] ?? null) ? $pageLayout['positional_parity'] : array(), $pageContext);
            DiagnosticAggregation::appendContextSamples($layout, 'large_css_offset_nodes', $pageLayout, 'large_css_offset_nodes', $pageContext);
            DiagnosticAggregation::appendContextSamples($layout, 'off_canvas_visual_nodes', $pageLayout, 'off_canvas_visual_nodes', $pageContext);
            DiagnosticAggregation::appendContextSamples($layout, 'large_absolute_offset_nodes', $pageLayout, 'large_absolute_offset_nodes', $pageContext);
            DiagnosticAggregation::appendContextSamples($layout, 'empty_visible_containers', $pageLayout, 'empty_visible_containers', $pageContext);
            DiagnosticAggregation::addCounterMap($layout['empty_visible_container_categories'], is_array($pageLayout['empty_visible_container_categories'] ?? null) ? $pageLayout['empty_visible_container_categories'] : array());
            $underlays = is_array($pageLayout['decorative_underlays']['nodes'] ?? null) ? $pageLayout['decorative_underlays']['nodes'] : array();
            foreach ( $underlays as $item ) {
                if ( is_array($item) ) {
                    $layout['decorative_underlays']['nodes'][] = array_merge($pageContext, $item);
                }
            }
            foreach ( is_array($pageLayout['sticky_ghosts']['candidates'] ?? null) ? $pageLayout['sticky_ghosts']['candidates'] : array() as $item ) {
                if ( is_array($item) ) {
                    $layout['sticky_ghosts']['candidates'][] = array_merge($pageContext, $item);
                }
            }
            foreach ( is_array($pageLayout['image_heavy_landmark_candidates'] ?? null) ? $pageLayout['image_heavy_landmark_candidates'] : array() as $item ) {
                if ( is_array($item) ) {
                    $layout['image_heavy_landmark_candidates'][] = array_merge($pageContext, $item);
                }
            }
            $pageLayoutMismatch = is_array($pageLayout['layout_mismatch'] ?? null) ? $pageLayout['layout_mismatch'] : array();
            $pageLayoutMismatchDiagnostics = is_array($pageLayoutMismatch['diagnostics'] ?? null) ? $pageLayoutMismatch['diagnostics'] : array();
            $layout['layout_mismatch_count'] += (int) ($pageLayoutMismatch['summary']['diagnostic_count'] ?? count($pageLayoutMismatchDiagnostics));
            if ( ! empty($pageLayoutMismatch) ) {
                $layout['layout_mismatch_status'] = 'fail' === ($pageLayoutMismatch['status'] ?? null) ? 'fail' : ('not_evaluated' === $layout['layout_mismatch_status'] ? (string) ($pageLayoutMismatch['status'] ?? 'not_run') : $layout['layout_mismatch_status']);
            }
            foreach ( $pageLayoutMismatchDiagnostics as $item ) {
                if ( is_array($item) ) {
                    $layout['layout_mismatches'][] = array_merge($pageContext, $item);
                }
            }
            $pageLayoutMismatchClusters = is_array($pageLayoutMismatch['summary']['clusters'] ?? null) ? $pageLayoutMismatch['summary']['clusters'] : array();
            foreach ( $pageLayoutMismatchClusters as $clusterType => $clusters ) {
                if ( ! is_array($clusters) ) {
                    continue;
                }
                if ( ! isset($layout['layout_mismatch_clusters'][$clusterType]) ) {
                    $layout['layout_mismatch_clusters'][$clusterType] = array();
                }
                foreach ( $clusters as $cluster ) {
                    if ( is_array($cluster) ) {
                        $layout['layout_mismatch_clusters'][$clusterType][] = array_merge($pageContext, $cluster);
                    }
                }
            }

            $pageRenderStyle = is_array($pageLayout['render_style'] ?? null) ? $pageLayout['render_style'] : array();
            if ( ! empty($pageRenderStyle) ) {
                $pageRenderStyleSummary = is_array($pageRenderStyle['summary'] ?? null) ? $pageRenderStyle['summary'] : array();
                $renderStyleSummary = is_array($layout['render_style']['summary'] ?? null) ? $layout['render_style']['summary'] : array();
                foreach ( array('source_node_count', 'render_node_count', 'matched_node_count', 'unmatched_source_node_count', 'diagnostic_count', 'reported_diagnostic_count', 'font_mismatch_count', 'color_mismatch_count', 'background_mismatch_count', 'border_mismatch_count', 'opacity_mismatch_count', 'asset_mismatch_count', 'text_metric_mismatch_count') as $key ) {
                    $renderStyleSummary[$key] = (int) ($renderStyleSummary[$key] ?? 0) + (int) ($pageRenderStyleSummary[$key] ?? 0);
                }
                $renderStyleSummary['truncated'] = ! empty($renderStyleSummary['truncated']) || ! empty($pageRenderStyleSummary['truncated']);
                $renderStyleSummary['match_ratio'] = (int) ($renderStyleSummary['source_node_count'] ?? 0) > 0 ? round((int) ($renderStyleSummary['matched_node_count'] ?? 0) / (int) $renderStyleSummary['source_node_count'], 4) : 0.0;
                $categoryCounts = is_array($renderStyleSummary['category_counts'] ?? null) ? $renderStyleSummary['category_counts'] : array();
                foreach ( is_array($pageRenderStyleSummary['category_counts'] ?? null) ? $pageRenderStyleSummary['category_counts'] : array() as $category => $count ) {
                    $categoryCounts[(string) $category] = (int) ($categoryCounts[(string) $category] ?? 0) + (int) $count;
                }
                ksort($categoryCounts);
                $renderStyleSummary['category_counts'] = $categoryCounts;
                $layout['render_style']['summary'] = $renderStyleSummary;
                $layout['render_style_mismatch_count'] = (int) ($renderStyleSummary['diagnostic_count'] ?? 0);
                $pageRenderStyleStatus = (string) ($pageRenderStyle['status'] ?? 'not_run');
                if ( 'fail' === $pageRenderStyleStatus ) {
                    $layout['render_style']['status'] = 'fail';
                    $layout['render_style_mismatch_status'] = 'fail';
                } elseif ( 'not_evaluated' === $layout['render_style_mismatch_status'] ) {
                    $layout['render_style']['status'] = $pageRenderStyleStatus;
                    $layout['render_style_mismatch_status'] = $pageRenderStyleStatus;
                }
                foreach ( is_array($pageRenderStyle['diagnostics'] ?? null) ? $pageRenderStyle['diagnostics'] : array() as $item ) {
                    if ( is_array($item) ) {
                        $layout['render_style']['diagnostics'][] = array_merge($pageContext, $item);
                    }
                }
            }

            $pageDiagnosticCodes = is_array($page['diagnostic_codes'] ?? null) ? $page['diagnostic_codes'] : (is_array($diagnostics['diagnostic_codes'] ?? null) ? $diagnostics['diagnostic_codes'] : array());
            DiagnosticAggregation::addCounterMap($diagnosticCodes, $pageDiagnosticCodes);
        }

        $layout['decorative_underlays']['count'] = count($layout['decorative_underlays']['nodes']);
        $layout['sticky_ghosts']['candidates'] = array_values($layout['sticky_ghosts']['candidates']);
        $layout['sticky_ghosts']['count'] = count($layout['sticky_ghosts']['candidates']);
        $layout['large_css_offset_nodes'] = array_values($layout['large_css_offset_nodes']);
        $layout['off_canvas_visual_nodes'] = array_values($layout['off_canvas_visual_nodes']);
        $links['route_targets'] = array_values($links['route_targets']);
        $links['unresolved_targets'] = array_values($links['unresolved_targets']);
        $layout['empty_visible_containers'] = array_values($layout['empty_visible_containers']);
        ksort($layout['empty_visible_container_categories']);
        $layout['layout_mismatches'] = array_values($layout['layout_mismatches']);
        $layout['render_style']['diagnostics'] = array_values($layout['render_style']['diagnostics']);
        if ( 'not_evaluated' === $layout['render_style_mismatch_status'] ) {
            $layout['render_style']['status'] = 'not_run';
            $layout['render_style_mismatch_status'] = 'not_run';
        }
        ksort($decisionTraces['reason_counts']);
        ksort($decisionTraces['domain_counts']);
        ksort($positionalParity['root_stacking_reason_counts']);
        $positionalParity['fixed_over_root_width_underlays'] = array_slice(array_values($positionalParity['fixed_over_root_width_underlays']), 0, 25);
        $positionalParity['chrome_overflow_nodes'] = array_slice(array_values($positionalParity['chrome_overflow_nodes']), 0, 25);
        $positionalParity['decision_trace_samples'] = array_slice(array_values($positionalParity['decision_trace_samples']), 0, 100);
        $layout['positional_parity'] = $positionalParity;
        ksort($diagnosticCodes);
        $fontResolution = ( new FontResolver() )->resolve($fontUsage, $fontCssSupplied ? 'operator-supplied' : '');
        $fonts = array(
            'families' => $fontFamilies,
            'usage' => $fontUsage,
            'count' => count($fontFamilies),
            'css_supplied' => $fontCssSupplied,
            'materialized' => $fontMaterialized,
            'missing_css' => $fontResolution['unresolved_families'],
            'resolved_css' => $fontResolution['resolved_families'],
            'cdn_families' => $fontResolution['cdn_families'],
            'coverage' => $fontResolution['coverage'],
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
            'visual_node_map_summary' => $this->visualNodeMapSummary($visualNodeMap),
            'pages' => $pages,
            'images' => $images,
            'vectors' => $vectors,
            'fonts' => $fonts,
            'assets' => $assets,
            'generated_svg_assets' => $generatedSvgAssets,
            'layout' => $layout,
            'decision_traces' => $decisionTraces,
            'links' => $links,
            'css' => $css,
            'artifact_quality' => $this->artifactQualityDiagnostics($images, $vectors, $fonts, $assets, $generatedSvgAssets, $layout, $links, $css),
            'diagnostic_codes' => $diagnosticCodes,
        );
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $source
     * @param array<string, mixed> $pageContext
     */
    private function mergeDecisionTraceDiagnostics(array &$target, array $source, array $pageContext): void
    {
        $target['trace_count'] = (int) ($target['trace_count'] ?? 0) + (int) ($source['trace_count'] ?? 0);
        DiagnosticAggregation::addCounterMap($target['reason_counts'], is_array($source['reason_counts'] ?? null) ? $source['reason_counts'] : array());
        DiagnosticAggregation::addCounterMap($target['domain_counts'], is_array($source['domain_counts'] ?? null) ? $source['domain_counts'] : array());
        DiagnosticAggregation::appendContextSamples($target, 'samples', $source, 'samples', $pageContext);
        $target['samples'] = array_slice($target['samples'], 0, 100);
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $source
     * @param array<string, mixed> $pageContext
     */
    private function mergePositionalParityDiagnostics(array &$target, array $source, array $pageContext): void
    {
        DiagnosticAggregation::addIntegerCounts($target, $source, array(
            'full_bleed_viewport_width_count',
            'full_bleed_breakout_count',
            'mirrored_transform_count',
            'reflected_full_bleed_count',
            'fixed_over_root_width_underlay_count',
            'chrome_overflow_count',
            'root_stacking_trace_count',
        ));
        DiagnosticAggregation::addCounterMap($target['root_stacking_reason_counts'], is_array($source['root_stacking_reason_counts'] ?? null) ? $source['root_stacking_reason_counts'] : array());
        DiagnosticAggregation::appendContextSamples($target, 'fixed_over_root_width_underlays', $source, 'fixed_over_root_width_underlays', $pageContext);
        DiagnosticAggregation::appendContextSamples($target, 'chrome_overflow_nodes', $source, 'chrome_overflow_nodes', $pageContext);
        DiagnosticAggregation::appendContextSamples($target, 'decision_trace_samples', $source, 'decision_trace_samples', $pageContext);
    }

    /**
     * @param array<string, mixed> $images
     * @param array<string, mixed> $vectors
     * @param array<string, mixed> $fonts
     * @param array<string, mixed> $assets
     * @param array<string, mixed> $generatedSvgAssets
     * @param array<string, mixed> $layout
     * @param array<string, mixed> $links
     * @param array<string, mixed> $css
     * @return array<string, mixed>
     */
    private function artifactQualityDiagnostics(array $images, array $vectors, array $fonts, array $assets, array $generatedSvgAssets, array $layout, array $links = array(), array $css = array()): array
    {
        $signals = array();

        if ( ! empty($images['missing_assets']) ) {
            $signals[] = array('severity' => 'warning', 'code' => 'missing_render_assets', 'count' => count($images['missing_assets']));
        }
        if ( ! empty($vectors['placeholders']) ) {
            $signals[] = array('severity' => 'warning', 'code' => 'vector_placeholders', 'count' => (int) $vectors['placeholders']);
        }
        if ( ! empty($fonts['missing_css']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'font_css_missing',
                'count' => count($fonts['missing_css']),
                'font_usage' => $this->fontUsageForFamilies(is_array($fonts['usage'] ?? null) ? $fonts['usage'] : array(), $fonts['missing_css']),
            );
        }
        if ( ! empty($layout['large_negative_left_count']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'off_canvas_left_css',
                'count' => (int) $layout['large_negative_left_count'],
                'sample_nodes' => array_slice(is_array($layout['large_css_offset_nodes'] ?? null) ? $layout['large_css_offset_nodes'] : array(), 0, 10),
            );
        }
        if ( ! empty($layout['off_canvas_visual_node_count']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'off_canvas_visual_nodes',
                'count' => (int) $layout['off_canvas_visual_node_count'],
                'sample_nodes' => array_slice(is_array($layout['off_canvas_visual_nodes'] ?? null) ? $layout['off_canvas_visual_nodes'] : array(), 0, 10),
            );
        }
        if ( ! empty($layout['large_absolute_offset_count']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'large_absolute_offsets',
                'count' => (int) $layout['large_absolute_offset_count'],
                'sample_nodes' => array_slice(is_array($layout['large_absolute_offset_nodes'] ?? null) ? $layout['large_absolute_offset_nodes'] : array(), 0, 10),
            );
        }
        if ( ! empty($layout['image_heavy_landmark_candidates']) ) {
            $signals[] = array('severity' => 'warning', 'code' => 'image_heavy_landmark_candidate', 'count' => count($layout['image_heavy_landmark_candidates']));
        }
        if ( ! empty($layout['layout_mismatch_count']) ) {
            $signals[] = array('severity' => 'warning', 'code' => 'layout_mismatch', 'count' => (int) $layout['layout_mismatch_count']);
        }
        if ( ! empty($layout['render_style_mismatch_count']) ) {
            $signals[] = array('severity' => 'warning', 'code' => 'render_style_mismatch', 'count' => (int) $layout['render_style_mismatch_count']);
        }
        if ( ! empty($links['unresolved']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'link_target_unresolved',
                'count' => (int) $links['unresolved'],
                'sample_nodes' => array_slice(is_array($links['unresolved_targets'] ?? null) ? $links['unresolved_targets'] : array(), 0, 10),
            );
        }
        if ( ! empty($css['invalid_numeric_token_count']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'invalid_css_numeric_token',
                'count' => (int) $css['invalid_numeric_token_count'],
                'sample_tokens' => array_slice(is_array($css['invalid_numeric_tokens'] ?? null) ? $css['invalid_numeric_tokens'] : array(), 0, 10),
            );
        }
        $sourceLossCoverage = $this->sourceLossCoverage($images, $vectors);
        if ( ! empty($sourceLossCoverage['not_emitted_source_nodes']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'source_loss_coverage_gap',
                'count' => (int) $sourceLossCoverage['not_emitted_source_nodes'],
                'coverage_ratio' => (float) $sourceLossCoverage['coverage_ratio'],
                'domains' => $sourceLossCoverage['domains'],
            );
        }
        $imageBlockCount = (int) ($images['image_block_count'] ?? 0);
        $totalNodeCount = max(0, (int) ($images['total_node_count'] ?? 0));
        $imageNodeDensity = $totalNodeCount > 0 ? $imageBlockCount / $totalNodeCount : 0.0;
        if ( $imageBlockCount >= 12 && ($imageNodeDensity >= 0.35 || ! empty($layout['image_heavy_landmark_candidates'])) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'excessive_image_blocks',
                'count' => $imageBlockCount,
                'threshold' => 12,
                'image_node_density' => round($imageNodeDensity, 3),
                'sample_nodes' => array_slice(is_array($images['image_block_nodes'] ?? null) ? $images['image_block_nodes'] : array(), 0, 10),
            );
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

        $failCodes = array('missing_render_assets', 'vector_placeholders', 'invalid_css_numeric_token');
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
                'image_block_count' => $imageBlockCount,
                'image_node_density' => round($imageNodeDensity, 3),
                'total_node_count' => $totalNodeCount,
                'vector_image_fallbacks' => (int) ($vectors['rendered_asset_fallbacks'] ?? 0),
                'vector_nodes' => (int) ($vectors['nodes'] ?? 0),
                'vector_decoded_to_svg' => (int) ($vectors['rendered_paths'] ?? 0),
                'vector_network_decoded' => (int) ($vectors['vector_network_decoded'] ?? 0),
                'boolean_operations_composed' => (int) ($vectors['boolean_operations_composed'] ?? 0),
                'vector_decode_coverage_ratio' => (int) ($vectors['nodes'] ?? 0) > 0 ? round((int) ($vectors['rendered_paths'] ?? 0) / (int) $vectors['nodes'], 3) : 0.0,
                'generated_svg_count' => (int) ($vectors['rendered_paths'] ?? 0),
                'externalized_svg_asset_count' => (int) ($generatedSvgAssets['count'] ?? 0),
                'generated_svg_bytes' => (int) ($generatedSvgAssets['bytes'] ?? 0),
                'large_negative_left_count' => (int) ($layout['large_negative_left_count'] ?? 0),
                'large_css_offset_count' => (int) ($layout['large_css_offset_count'] ?? 0),
                'off_canvas_visual_node_count' => (int) ($layout['off_canvas_visual_node_count'] ?? 0),
                'render_style_mismatch_count' => (int) ($layout['render_style_mismatch_count'] ?? 0),
                'render_style_mismatch_status' => (string) ($layout['render_style_mismatch_status'] ?? 'not_run'),
                'link_sources_found' => (int) ($links['sources_found'] ?? 0),
                'anchors_emitted' => (int) ($links['anchors_emitted'] ?? 0),
                'link_targets_unresolved' => (int) ($links['unresolved'] ?? 0),
                'invalid_css_numeric_tokens' => (int) ($css['invalid_numeric_token_count'] ?? 0),
                'large_absolute_offset_count' => (int) ($layout['large_absolute_offset_count'] ?? 0),
                'empty_visible_container_count' => (int) ($layout['empty_visible_container_count'] ?? 0),
                'empty_visible_container_blocker_count' => (int) ($layout['empty_visible_container_blocker_count'] ?? 0),
                'image_heavy_landmark_candidates' => count($layout['image_heavy_landmark_candidates'] ?? array()),
                'layout_mismatch_count' => (int) ($layout['layout_mismatch_count'] ?? 0),
                'layout_mismatch_status' => (string) ($layout['layout_mismatch_status'] ?? 'not_evaluated'),
                'source_loss_coverage' => $sourceLossCoverage,
            ),
        );
    }

    /**
     * @param array<string, mixed> $images
     * @param array<string, mixed> $vectors
     * @return array<string, mixed>
     */
    private function sourceLossCoverage(array $images, array $vectors): array
    {
        $sourceLossCoverageBuilder = new SourceLossCoverageBuilder();
        $domains = array(
            'images' => $sourceLossCoverageBuilder->imageDomain($images),
            'vectors' => $sourceLossCoverageBuilder->vectorDomain($vectors),
        );

        return $sourceLossCoverageBuilder->aggregate($domains);
    }

    /**
     * @param array<int, array<string, mixed>> $visualNodeMap
     * @return array<string, mixed>
     */
    private function visualNodeMapSummary(array $visualNodeMap): array
    {
        $pagePathCounts = array();
        $sourcePageCounts = array();
        $emittedClassSamples = array();
        $withEmittedMetadata = 0;
        $withPagePath = 0;

        foreach ( $visualNodeMap as $visualNode ) {
            if ( ! is_array($visualNode) ) {
                continue;
            }

            $pagePath = isset($visualNode['page_path']) && is_scalar($visualNode['page_path']) ? (string) $visualNode['page_path'] : '';
            if ( '' !== $pagePath ) {
                ++$withPagePath;
                $pagePathCounts[$pagePath] = ($pagePathCounts[$pagePath] ?? 0) + 1;
            }

            if ( isset($visualNode['source_page_index']) && is_numeric($visualNode['source_page_index']) ) {
                $sourcePageIndex = (string) ((int) $visualNode['source_page_index']);
                $sourcePageCounts[$sourcePageIndex] = ($sourcePageCounts[$sourcePageIndex] ?? 0) + 1;
            }

            $emittedClass = isset($visualNode['emitted_class']) && is_scalar($visualNode['emitted_class']) ? (string) $visualNode['emitted_class'] : '';
            $emittedTag = isset($visualNode['emitted_tag']) && is_scalar($visualNode['emitted_tag']) ? (string) $visualNode['emitted_tag'] : '';
            if ( '' !== $emittedClass || '' !== $emittedTag ) {
                ++$withEmittedMetadata;
            }
            if ( '' !== $emittedClass && count($emittedClassSamples) < 10 ) {
                $emittedClassSamples[] = array(
                    'node_id' => isset($visualNode['id']) && is_scalar($visualNode['id']) ? (string) $visualNode['id'] : '',
                    'class' => $emittedClass,
                    'page_path' => '' !== $pagePath ? $pagePath : null,
                );
            }
        }

        ksort($pagePathCounts);
        ksort($sourcePageCounts);

        return array(
            'schema' => 'blocks-engine/figma-transformer/visual-node-map-summary/v1',
            'visual_node_count' => count($visualNodeMap),
            'nodes_with_emitted_metadata' => $withEmittedMetadata,
            'nodes_with_page_path' => $withPagePath,
            'page_path_counts' => $pagePathCounts,
            'source_page_index_counts' => $sourcePageCounts,
            'emitted_class_samples' => $emittedClassSamples,
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
            'gzip_bytes' => $this->sumNullableGeneratedSvgAssetMetric($assets, 'gzip_bytes'),
            'path_element_count' => array_sum(array_map(static fn (array $asset): int => (int) ($asset['path_element_count'] ?? 0), $assets)),
            'path_data_bytes' => array_sum(array_map(static fn (array $asset): int => (int) ($asset['path_data_bytes'] ?? 0), $assets)),
            'largest_path_data_bytes' => empty($assets) ? 0 : max(array_map(static fn (array $asset): int => (int) ($asset['largest_path_data_bytes'] ?? 0), $assets)),
            'unique_path_data_count' => $this->uniqueGeneratedSvgPathDataCount($assets),
            'duplicate_path_data_count' => $this->duplicateGeneratedSvgPathDataCount($assets),
            'paths' => array_values(array_map(static fn (array $asset): string => (string) ($asset['path'] ?? ''), $assets)),
            'largest_assets' => array_slice($assets, 0, 10),
            'assets' => $assets,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $assets
     */
    private function sumNullableGeneratedSvgAssetMetric(array $assets, string $key): ?int
    {
        $sum = 0;
        foreach ( $assets as $asset ) {
            if ( ! array_key_exists($key, $asset) || null === $asset[$key] ) {
                return null;
            }
            $sum += (int) $asset[$key];
        }

        return $sum;
    }

    /**
     * @param array<int, array<string, mixed>> $assets
     */
    private function uniqueGeneratedSvgPathDataCount(array $assets): int
    {
        $hashes = array();
        foreach ( $assets as $asset ) {
            foreach ( is_array($asset['path_data_hashes'] ?? null) ? $asset['path_data_hashes'] : array() as $hash ) {
                if ( is_scalar($hash) && '' !== (string) $hash ) {
                    $hashes[(string) $hash] = true;
                }
            }
        }

        return count($hashes);
    }

    /**
     * @param array<int, array<string, mixed>> $assets
     */
    private function duplicateGeneratedSvgPathDataCount(array $assets): int
    {
        $pathDataCount = array_sum(array_map(static fn (array $asset): int => (int) ($asset['path_data_count'] ?? 0), $assets));

        return max(0, $pathDataCount - $this->uniqueGeneratedSvgPathDataCount($assets));
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
     * Merge per-page CSS chunks into one deduplicated stylesheet.
     *
     * CSS is tokenized at TOP-LEVEL statement boundaries (tracking brace depth)
     * rather than per line, so block at-rules — most importantly the responsive
     * `@media (max-width: …)` blocks emitted for a collapsed responsive page —
     * stay ATOMIC and are not shattered into stray `{`/`}`/inner-rule lines that
     * line-level deduping would corrupt. `@import` (and the leading font-source
     * comment) float to the top to stay valid after concatenation; plain
     * top-level rules dedupe; `@media` (and other block at-rules) preserve their
     * widest-first emission order and follow the base rules so narrower
     * breakpoints still win the cascade at their own viewport width.
     *
     * @param array<int, string> $chunks
     * @return array{css: string, class_maps: array<int, array<string, string>>}
     */
    private function mergeCssChunks(array $chunks): array
    {
        $imports = array();
        $rules = array();
        $atBlocks = array();
        $readableRules = array();
        foreach ( $chunks as $chunkIndex => $chunk ) {
            foreach ( $this->splitCssStatements($chunk) as $statement ) {
                $statement = trim($statement);
                if ( '' === $statement ) {
                    continue;
                }
                // `@import` (and any leading font-source comment) must precede all
                // other rules to stay valid after chunks are concatenated.
                if ( str_starts_with($statement, '@import') || ( str_starts_with($statement, '/*') && str_contains($statement, 'web fonts') ) ) {
                    $imports[$statement] = true;
                    continue;
                }
                // Block at-rules (e.g. responsive `@media` breakpoints) must stay
                // intact and keep their emission order behind the base rules.
                if ( str_starts_with($statement, '@media') || ( str_starts_with($statement, '@') && str_contains($statement, '{') ) ) {
                    $atBlocks[$statement] = true;
                    continue;
                }
                $readableRule = $this->readableCssRule($statement);
                if ( null !== $readableRule ) {
                    $readableRules[$readableRule['class']][$readableRule['body']][] = $chunkIndex;
                }
                $rules[$statement] = true;
            }
        }

        $classMaps = array();
        $renamesByStatement = array();
        foreach ( $readableRules as $class => $bodies ) {
            if ( count($bodies) < 2 ) {
                continue;
            }
            foreach ( $bodies as $body => $chunkIndexes ) {
                $renamedClass = $class . '-' . substr(sha1($body), 0, 8);
                $renamesByStatement['.' . $class . '{' . $body . '}'] = '.' . $renamedClass . '{' . $body . '}';
                foreach ( $chunkIndexes as $chunkIndex ) {
                    $classMaps[$chunkIndex][$class] = $renamedClass;
                }
            }
        }

        if ( ! empty($renamesByStatement) ) {
            $renamedRules = array();
            foreach ( array_keys($rules) as $statement ) {
                $renamedRules[$renamesByStatement[$statement] ?? $statement] = true;
            }
            $rules = $renamedRules;
        }

        $ordered = array_merge(array_keys($imports), array_keys($rules), array_keys($atBlocks));

        return array(
            'css' => implode("\n", $ordered) . (empty($ordered) ? '' : "\n"),
            'class_maps' => $classMaps,
        );
    }

    /**
     * Multi-page output merges independently-emitted page stylesheets. Leaving
     * per-page design tokens on `:root` lets later pages override earlier page
     * typography/color variables, so scope custom properties to the emitted page
     * frame when a concrete root node class is available.
     */
    private function scopeRootCustomPropertiesToPage(string $css, string $html): string
    {
        if ( '' === $css || '' === $html || ! str_contains($css, ':root{') ) {
            return $css;
        }

        if ( 1 !== preg_match('/<main\b[^>]*data-figma-root="true"[^>]*>\s*<[^>]+class="([^"]*)"/s', $html, $matches) ) {
            return $css;
        }

        $rootClass = '';
        foreach ( preg_split('/\s+/', trim((string) $matches[1])) ?: array() as $class ) {
            if ( str_starts_with($class, 'figma-node-') ) {
                $rootClass = $class;
                break;
            }
        }
        if ( '' === $rootClass ) {
            return $css;
        }

        return (string) preg_replace('/(^|\n):root\{/m', '$1.' . $rootClass . '{', $css);
    }

    /**
     * @return array{class: string, body: string}|null
     */
    private function readableCssRule(string $statement): ?array
    {
        if ( 1 !== preg_match('/^\.([A-Za-z][A-Za-z0-9_-]*)\{(.*)\}$/s', $statement, $matches) ) {
            return null;
        }

        $class = $matches[1];
        if ( str_starts_with($class, 'figma-node-') || in_array($class, array('figma-root', 'figma-link', 'figma-text-glyphs', 'figma-vector-asset'), true) ) {
            return null;
        }

        return array('class' => $class, 'body' => $matches[2]);
    }

    /**
     * @param array<string, string> $classMap
     */
    private function applyCssClassRenameMapToHtml(string $html, array $classMap): string
    {
        if ( empty($classMap) ) {
            return $html;
        }

        return (string) preg_replace_callback('/class="([^"]*)"/', static function (array $matches) use ($classMap): string {
            $classes = preg_split('/\s+/', trim((string) $matches[1])) ?: array();
            $classes = array_map(static fn (string $class): string => $classMap[$class] ?? $class, $classes);
            return 'class="' . implode(' ', array_values(array_filter($classes, static fn (string $class): bool => '' !== $class))) . '"';
        }, $html);
    }

    /**
     * Split a CSS string into top-level statements, keeping each rule or block
     * at-rule (with its full brace-balanced body) as one element. Bare `@import`
     * statements and standalone comments are returned as their own elements.
     *
     * @return array<int, string>
     */
    private function splitCssStatements(string $css): array
    {
        $statements = array();
        $buffer = '';
        $depth = 0;
        $parenDepth = 0;
        $quote = null;
        $escaped = false;
        $inComment = false;
        $length = strlen($css);
        for ( $i = 0; $i < $length; $i++ ) {
            $char = $css[$i];
            $buffer .= $char;

            if ( $inComment ) {
                if ( '*' === $char && '/' === ($css[$i + 1] ?? '') ) {
                    $buffer .= '/';
                    ++$i;
                    $inComment = false;
                }
                continue;
            }

            if ( null !== $quote ) {
                if ( $escaped ) {
                    $escaped = false;
                    continue;
                }
                if ( '\\' === $char ) {
                    $escaped = true;
                    continue;
                }
                if ( $quote === $char ) {
                    $quote = null;
                }
                continue;
            }

            if ( '/' === $char && '*' === ($css[$i + 1] ?? '') ) {
                $buffer .= '*';
                ++$i;
                $inComment = true;
                continue;
            }
            if ( '"' === $char || "'" === $char ) {
                $quote = $char;
                continue;
            }
            if ( '(' === $char ) {
                ++$parenDepth;
                continue;
            }
            if ( ')' === $char ) {
                $parenDepth = max(0, $parenDepth - 1);
                continue;
            }
            if ( '{' === $char ) {
                ++$depth;
                continue;
            }
            if ( '}' === $char ) {
                $depth = max(0, $depth - 1);
                if ( 0 === $depth ) {
                    $statements[] = trim($buffer);
                    $buffer = '';
                }
                continue;
            }
            if ( ';' === $char && 0 === $depth && 0 === $parenDepth ) {
                // Top-level statement with no block body (e.g. `@import …;`).
                $statements[] = trim($buffer);
                $buffer = '';
            }
        }

        $trailing = trim($buffer);
        if ( '' !== $trailing ) {
            $statements[] = $trailing;
        }

        return array_values(array_filter($statements, static fn (string $statement): bool => '' !== $statement));
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
     * @return array<int, array<string, mixed>>
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

                $usage[$family] ??= array('weights' => array(), 'weight_counts' => array(), 'text_node_count' => 0, 'visible_text_area_px' => 0, 'sample_nodes' => array());

                $weights = is_array($item['weights'] ?? null) ? $item['weights'] : array($item['weight'] ?? 400);
                foreach ( $weights as $weight ) {
                    if ( is_numeric($weight) ) {
                        $usage[$family]['weights'][(int) $weight] = true;
                    }
                }
                foreach ( is_array($item['weight_counts'] ?? null) ? $item['weight_counts'] : array() as $weight => $count ) {
                    if ( is_numeric($weight) ) {
                        $usage[$family]['weight_counts'][(string) (int) $weight] = ($usage[$family]['weight_counts'][(string) (int) $weight] ?? 0) + (int) $count;
                    }
                }
                $usage[$family]['text_node_count'] += (int) ($item['text_node_count'] ?? 0);
                $usage[$family]['visible_text_area_px'] += (int) ($item['visible_text_area_px'] ?? 0);
                foreach ( is_array($item['sample_nodes'] ?? null) ? $item['sample_nodes'] : array() as $sampleNode ) {
                    if ( is_array($sampleNode) && count($usage[$family]['sample_nodes']) < 10 ) {
                        $usage[$family]['sample_nodes'][] = $sampleNode;
                    }
                }
            }
        }

        ksort($usage, SORT_NATURAL | SORT_FLAG_CASE);
        $merged = array();
        foreach ( $usage as $family => $data ) {
            $weightValues = array_keys($data['weights']);
            sort($weightValues, SORT_NUMERIC);
            ksort($data['weight_counts']);
            $merged[] = array(
                'family' => $family,
                'weights' => $weightValues,
                'weight_counts' => $data['weight_counts'],
                'text_node_count' => (int) $data['text_node_count'],
                'visible_text_area_px' => (int) $data['visible_text_area_px'],
                'sample_nodes' => $data['sample_nodes'],
            );
        }

        return $merged;
    }

    /**
     * @param array<int, array<string, mixed>> $fontUsage
     * @param array<int, string> $families
     * @return array<int, array<string, mixed>>
     */
    private function fontUsageForFamilies(array $fontUsage, array $families): array
    {
        $wanted = array_fill_keys(array_map('strtolower', $families), true);
        return array_values(array_filter(
            $fontUsage,
            static fn (array $usage): bool => isset($wanted[strtolower((string) ($usage['family'] ?? ''))])
        ));
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
            $asset = array(
                'id'        => (string) ($file['source_id'] ?? ''),
                'path'      => (string) ($file['path'] ?? ''),
                'mime_type' => (string) ($file['mime_type'] ?? 'application/octet-stream'),
                'bytes'     => strlen($content),
                'hash'      => hash('sha256', $content),
            );
            if ( 'image/svg+xml' === ($file['mime_type'] ?? null) && str_starts_with((string) ($file['source_id'] ?? ''), 'generated-vector-') ) {
                $asset += $this->svgAssetMetrics($content);
            }
            $assets[] = $asset;
        }

        return $assets;
    }

    /**
     * @return array<string, mixed>
     */
    private function svgAssetMetrics(string $content): array
    {
        $pathElementCount = preg_match_all('/<path\b[^>]*>/i', $content, $pathMatches);
        $pathDataValues = array();
        foreach ( $pathMatches[0] ?? array() as $pathElement ) {
            if ( preg_match('/\bd\s*=\s*(["\'])(.*?)\1/is', (string) $pathElement, $pathDataMatch) ) {
                $pathDataValues[] = html_entity_decode((string) $pathDataMatch[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        $pathDataBytes = array_map(static fn (string $pathData): int => strlen($pathData), $pathDataValues);
        $pathDataHashes = array_map(static fn (string $pathData): string => hash('sha256', $pathData), $pathDataValues);
        $uniquePathDataHashes = array_values(array_unique($pathDataHashes));

        return array(
            'gzip_bytes' => function_exists('gzencode') ? strlen((string) gzencode($content, 9)) : null,
            'path_element_count' => false === $pathElementCount ? 0 : $pathElementCount,
            'path_data_count' => count($pathDataValues),
            'path_data_bytes' => array_sum($pathDataBytes),
            'largest_path_data_bytes' => empty($pathDataBytes) ? 0 : max($pathDataBytes),
            'unique_path_data_count' => count($uniquePathDataHashes),
            'duplicate_path_data_count' => max(0, count($pathDataValues) - count($uniquePathDataHashes)),
            'path_data_hashes' => $uniquePathDataHashes,
        );
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
