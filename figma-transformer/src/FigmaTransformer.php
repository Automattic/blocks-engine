<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer;

use Automattic\BlocksEngine\FigmaTransformer\Contract\FigmaTransformResult;
use Automattic\BlocksEngine\FigmaTransformer\Diagnostics\LayoutMismatchReportBuilder;
use Automattic\BlocksEngine\FigmaTransformer\Diagnostics\RenderStyleMismatchReportBuilder;
use Automattic\BlocksEngine\FigmaTransformer\FigFile\FigArchiveReader;
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
            $sourceLossEvidence = $this->sourceLossEvidenceForChunk($archive, (int) $scenegraphCandidate['report']['chunk_index']);
            if ( ! empty($sourceLossEvidence) ) {
                $options['source_loss_evidence'] = $sourceLossEvidence;
            }
            $options['archive_asset_content_resolver'] = fn (array $asset): ?array => $this->archiveReader->hydrateAssetContent($path, $asset, $options);
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
     * @param array<string, mixed> $archive
     * @return array<string, mixed>
     */
    private function sourceLossEvidenceForChunk(array $archive, int $chunkIndex): array
    {
        $chunk = $archive['archive']['canvas']['chunks'][$chunkIndex] ?? null;
        $inventory = is_array($chunk) && is_array($chunk['payload']['kiwi_skipped_field_inventory'] ?? null)
            ? $chunk['payload']['kiwi_skipped_field_inventory']
            : null;

        return null === $inventory ? array() : array('skipped_field_inventory' => $inventory);
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
        return $this->finalizeArtifactResult(
            $artifact,
            $artifact['status'],
            $normalized['diagnostics'] ?? array(),
            array(
                'scenegraph' => $normalized['source_report'],
            ),
            $options,
            $startedAt,
            true,
            array(
                'node_count'             => $normalized['source_report']['node_count'] ?? ($artifact['metrics']['node_count'] ?? 0),
                'text_node_count'        => count($normalized['text_inventory'] ?? array()),
                'asset_reference_count'  => count($normalized['asset_references'] ?? array()),
                'asset_count'            => $artifact['metrics']['asset_count'] ?? 0,
                'file_count'             => count($artifact['files']),
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
        $pagePath = isset($options['static_site_page_path']) && is_scalar($options['static_site_page_path']) && '' !== (string) $options['static_site_page_path']
            ? (string) $options['static_site_page_path']
            : 'index.html';

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
                    'path'       => $pagePath,
                    'entrypoint' => true,
                    'page_type'  => isset($options['static_site_template_type']) && is_scalar($options['static_site_template_type']) ? (string) $options['static_site_template_type'] : '',
                    'slug'       => isset($options['static_site_template_slug']) && is_scalar($options['static_site_template_slug']) ? (string) $options['static_site_template_slug'] : '',
                    'responsive' => true,
                    'variants'   => $variants,
                ),
            ),
        );

        $emitOptions = $options;
        unset($emitOptions['responsive_variants'], $emitOptions['frame_id'], $emitOptions['page_name']);

        $artifact    = $this->htmlEmitter->emitSite($normalized, $pagePlan, $emitOptions);
        return $this->finalizeArtifactResult(
            $artifact,
            $artifact['status'],
            $normalized['diagnostics'] ?? array(),
            array(
                'scenegraph' => $normalized['source_report'],
            ),
            $options,
            $startedAt,
            true,
            array(
                'node_count'             => $artifact['metrics']['node_count'] ?? 0,
                'text_node_count'        => count($normalized['text_inventory'] ?? array()),
                'asset_reference_count'  => count($normalized['asset_references'] ?? array()),
                'asset_count'            => $artifact['metrics']['asset_count'] ?? 0,
                'file_count'             => count($artifact['files']),
                'breakpoint_count'       => count($variants),
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
        $normalized = empty($pages) ? null : $this->normalizeScenegraphForPagePlan($scenegraph, $pagePlan, $options);
        if ( null === $normalized ) {
            $artifact = array(
                'status' => empty($diagnostics) ? 'success' : 'success_with_warnings',
                'diagnostics' => array(),
                'files' => array(),
                'assets' => array(),
                'source_report' => array('pages' => array(), 'page_plan' => $pagePlan),
                'metrics' => array('node_count' => 0, 'asset_count' => 0, 'page_count' => 0),
            );
        } else {
            $emitOptions = $options;
            $emitOptions['implicit_route_page_plan'] = $pagePlan;
            $emitOptions['inline_css'] = false;
            $emitOptions['link_target_paths'] = $this->linkTargetPathsFromPages($pages, $scenegraph);
            unset($emitOptions['responsive_variants'], $emitOptions['page_name']);
            $artifact = $this->htmlEmitter->emitSite($normalized, $pagePlan, $emitOptions);
            $artifact = $this->withMultiPageSourceReports($artifact, $normalized, $pagePlan, $options);
        }

        $renderedNodes = null === $normalized ? array() : $this->renderedNodesFromArtifact($artifact, $normalized, array());

        return $this->finalizeArtifactResult(
            $artifact,
            empty($diagnostics) ? 'success' : 'success_with_warnings',
            array_merge($diagnostics, is_array($normalized['diagnostics'] ?? null) ? $normalized['diagnostics'] : array()),
            array(
                'pages' => $pagePlan,
            ),
            $options,
            $startedAt,
            false,
            array(
                'node_count'             => $this->countNormalizedNodes($renderedNodes),
                'text_node_count'        => $this->countNormalizedTextNodes($renderedNodes),
                'asset_reference_count'  => count($this->assetReferencesForNodes($renderedNodes)),
                'asset_count'            => count(is_array($artifact['assets'] ?? null) ? $artifact['assets'] : array()),
                'file_count'             => count(is_array($artifact['files'] ?? null) ? $artifact['files'] : array()),
                'page_count'             => count(is_array($artifact['source_report']['pages'] ?? null) ? $artifact['source_report']['pages'] : array()),
            )
        );
    }

    /**
     * Add transformer-owned page provenance and mismatch reports to a site emission.
     *
     * @param array<string, mixed> $artifact
     * @param array<string, mixed> $normalized
     * @param array<string, mixed> $pagePlan
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function withMultiPageSourceReports(array $artifact, array $normalized, array $pagePlan, array $options): array
    {
        $sourceReport = is_array($artifact['source_report'] ?? null) ? $artifact['source_report'] : array();
        $emittedPages = is_array($sourceReport['pages'] ?? null) ? array_values($sourceReport['pages']) : array();
        $plannedPages = is_array($pagePlan['pages'] ?? null) ? array_values($pagePlan['pages']) : array();
        $aggregateDiagnostics = is_array($sourceReport['transform_diagnostics'] ?? null) ? $sourceReport['transform_diagnostics'] : array();
        $aggregateVisualNodeMap = is_array($sourceReport['visual_node_map'] ?? null) ? array_values($sourceReport['visual_node_map']) : array();
        $nodeMap = is_array($normalized['node_map'] ?? null) ? $normalized['node_map'] : array();
        $pageReports = array();
        $tracedVisualNodeMap = array();

        foreach ( $emittedPages as $pageIndex => $emittedPage ) {
            if ( ! is_array($emittedPage) ) {
                continue;
            }

            $frameId = isset($emittedPage['frame_id']) && is_scalar($emittedPage['frame_id']) ? (string) $emittedPage['frame_id'] : '';
            $path = isset($emittedPage['path']) && is_scalar($emittedPage['path']) ? (string) $emittedPage['path'] : '';
            $plannedPage = is_array($plannedPages[$pageIndex] ?? null) ? $plannedPages[$pageIndex] : array();
            if ( $frameId !== (string) ($plannedPage['frame_id'] ?? '') ) {
                foreach ( $plannedPages as $candidate ) {
                    if ( is_array($candidate) && $frameId === (string) ($candidate['frame_id'] ?? '') ) {
                        $plannedPage = $candidate;
                        break;
                    }
                }
            }

            $pageVisualNodeMap = array_values(array_filter(
                $aggregateVisualNodeMap,
                static fn (mixed $node): bool => is_array($node) && $path === (string) ($node['page_path'] ?? '')
            ));
            $pageVisualNodeMap = $this->visualNodeMapWithPageTrace($pageVisualNodeMap, $pageIndex, $frameId, $path);
            array_push($tracedVisualNodeMap, ...$pageVisualNodeMap);

            $pageSourceReport = $sourceReport;
            $pageSourceReport['visual_node_count'] = count($pageVisualNodeMap);
            $pageSourceReport['visual_node_map'] = $pageVisualNodeMap;
            $pageSourceReport['transform_diagnostics'] = is_array($emittedPage['transform_diagnostics'] ?? null) ? $emittedPage['transform_diagnostics'] : array();
            unset($pageSourceReport['pages'], $pageSourceReport['page_plan']);

            $pageOptions = $options;
            $pageOptions['layout_mismatch_options'] = is_array($pageOptions['layout_mismatch_options'] ?? null) ? $pageOptions['layout_mismatch_options'] : array();
            $pageOptions['layout_mismatch_options']['page_path'] = $path;
            $pageOptions['layout_mismatch_options']['source_frame_id'] = $frameId;
            $pageOptions['layout_mismatch_options']['source_frame_width'] = $plannedPage['width'] ?? null;
            $pageOptions['render_style_mismatch_options'] = is_array($pageOptions['render_style_mismatch_options'] ?? null) ? $pageOptions['render_style_mismatch_options'] : array();
            $pageOptions['render_style_mismatch_options']['page_path'] = $path;
            $pageArtifact = array('source_report' => $pageSourceReport);
            $pageArtifact = $this->withLayoutMismatchReport($pageArtifact, $pageOptions);
            $pageArtifact = $this->withRenderStyleMismatchReport($pageArtifact, $pageOptions);
            $pageTransformDiagnostics = is_array($pageArtifact['source_report']['transform_diagnostics'] ?? null)
                ? $pageArtifact['source_report']['transform_diagnostics']
                : $aggregateDiagnostics;
            $renderedNodes = isset($nodeMap[$frameId]) && is_array($nodeMap[$frameId]) ? array($nodeMap[$frameId]) : array();
            $pageType = isset($plannedPage['page_type']) && is_scalar($plannedPage['page_type']) ? (string) $plannedPage['page_type'] : (string) ($emittedPage['page_type'] ?? '');
            $sourceFrameIdentity = is_array($plannedPage['source_frame_identity'] ?? null) ? $plannedPage['source_frame_identity'] : array();

            $pageReports[] = array_merge($emittedPage, array(
                'slug' => (string) ($plannedPage['slug'] ?? $emittedPage['slug'] ?? ''),
                'page_type' => $pageType,
                'source_frame_identity' => $sourceFrameIdentity,
                'node_count' => $this->countNormalizedNodes($renderedNodes),
                'text_node_count' => $this->countNormalizedTextNodes($renderedNodes),
                'asset_reference_count' => count($this->assetReferencesForNodes($renderedNodes)),
                'font_families' => is_array($emittedPage['font_families'] ?? null) ? $emittedPage['font_families'] : array(),
                'font_usage' => is_array($emittedPage['font_usage'] ?? null) ? $emittedPage['font_usage'] : array(),
                'font_css_supplied' => true === ($emittedPage['font_css_supplied'] ?? false),
                'visual_node_count' => count($pageVisualNodeMap),
                'visual_node_map' => $pageVisualNodeMap,
                'transform_diagnostics' => $pageTransformDiagnostics,
                'diagnostic_codes' => is_array($emittedPage['diagnostic_codes'] ?? null) ? $emittedPage['diagnostic_codes'] : array(),
            ));

            $this->enrichMultiPageFiles($artifact, $path, $pageType, (string) ($plannedPage['slug'] ?? ''), $sourceFrameIdentity, $frameId);
        }

        $sourceReport['pages'] = $pageReports;
        $sourceReport['page_plan'] = $pagePlan;
        $sourceReport['visual_node_count'] = count($tracedVisualNodeMap);
        $sourceReport['visual_node_map'] = $tracedVisualNodeMap;
        $aggregateDiagnostics = $this->withVisualNodePageContexts($aggregateDiagnostics, $tracedVisualNodeMap);
        $aggregateDiagnostics['scope'] = 'multi_page';
        $aggregateDiagnostics['selection'] = $this->multiPageSelectionDiagnostics($pageReports);
        $aggregateDiagnostics['visual_node_map_summary'] = $this->visualNodeMapSummary($tracedVisualNodeMap);
        $aggregateDiagnostics['pages'] = array_map(
            static fn (array $page): array => array(
                'frame_id' => (string) ($page['frame_id'] ?? ''),
                'page_path' => (string) ($page['path'] ?? ''),
                'page_name' => (string) ($page['name'] ?? ''),
                'transform_diagnostics' => is_array($page['transform_diagnostics'] ?? null) ? $page['transform_diagnostics'] : array(),
            ),
            $pageReports
        );

        $mergedLayout = $this->aggregatePageMismatchLayout($pageReports);
        $aggregateLayout = is_array($aggregateDiagnostics['layout'] ?? null) ? $aggregateDiagnostics['layout'] : array();
        foreach ( array('layout_mismatch_count', 'layout_mismatch_status', 'layout_mismatches', 'layout_mismatch_clusters', 'render_style_mismatch_count', 'render_style_mismatch_status', 'render_style') as $key ) {
            if ( array_key_exists($key, $mergedLayout) ) {
                $aggregateLayout[$key] = $mergedLayout[$key];
            }
        }
        $aggregateDiagnostics['layout'] = $aggregateLayout;
        $quality = is_array($aggregateDiagnostics['artifact_quality'] ?? null) ? $aggregateDiagnostics['artifact_quality'] : array();
        foreach ( $pageReports as $pageReport ) {
            $pageLayout = is_array($pageReport['transform_diagnostics']['layout'] ?? null) ? $pageReport['transform_diagnostics']['layout'] : array();
            if ( is_array($pageLayout['layout_mismatch'] ?? null) ) {
                $quality = $this->withLayoutMismatchArtifactQuality($quality, $pageLayout['layout_mismatch']);
            }
            if ( is_array($pageLayout['render_style'] ?? null) ) {
                $quality = $this->withRenderStyleMismatchArtifactQuality($quality, $pageLayout['render_style']);
            }
        }
        $aggregateDiagnostics['artifact_quality'] = $quality;
        $sourceReport['transform_diagnostics'] = $aggregateDiagnostics;
        $artifact['source_report'] = $sourceReport;

        return $artifact;
    }

    /**
     * Attach page provenance to diagnostic samples that identify an emitted node.
     *
     * @param array<string, mixed>             $diagnostics
     * @param array<int, array<string, mixed>> $visualNodeMap
     * @return array<string, mixed>
     */
    private function withVisualNodePageContexts(array $diagnostics, array $visualNodeMap): array
    {
        $contexts = array();
        foreach ( $visualNodeMap as $visualNode ) {
            if ( ! is_array($visualNode) || ! isset($visualNode['id']) || ! is_scalar($visualNode['id']) ) {
                continue;
            }
            $contexts[(string) $visualNode['id']] = array_filter(array(
                'frame_id' => isset($visualNode['source_page_frame_id']) && is_scalar($visualNode['source_page_frame_id']) ? (string) $visualNode['source_page_frame_id'] : '',
                'page_path' => isset($visualNode['page_path']) && is_scalar($visualNode['page_path']) ? (string) $visualNode['page_path'] : '',
                'source_page_index' => isset($visualNode['source_page_index']) && is_numeric($visualNode['source_page_index']) ? (int) $visualNode['source_page_index'] : null,
            ), static fn (mixed $value): bool => null !== $value && '' !== $value);
        }

        return $this->withDiagnosticSampleContexts($diagnostics, $contexts);
    }

    /**
     * @param array<mixed>                     $value
     * @param array<string, array<string, mixed>> $contexts
     * @return array<mixed>
     */
    private function withDiagnosticSampleContexts(array $value, array $contexts): array
    {
        $nodeId = isset($value['node_id']) && is_scalar($value['node_id']) ? (string) $value['node_id'] : '';
        if ( '' !== $nodeId && isset($contexts[$nodeId]) ) {
            $value = array_merge($contexts[$nodeId], $value);
        }
        foreach ( $value as $key => $item ) {
            if ( is_array($item) ) {
                $value[$key] = $this->withDiagnosticSampleContexts($item, $contexts);
            }
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $artifact
     * @param array<string, mixed> $sourceFrameIdentity
     */
    private function enrichMultiPageFiles(array &$artifact, string $path, string $pageType, string $pageSlug, array $sourceFrameIdentity, string $frameId): void
    {
        $canonicalTemplatePath = $this->canonicalTemplatePath($pageType);
        foreach ( is_array($artifact['files'] ?? null) ? $artifact['files'] : array() as $fileIndex => $file ) {
            if ( ! is_array($file) || ! isset($file['path']) || ! is_scalar($file['path']) ) {
                continue;
            }
            $filePath = (string) $file['path'];
            if ( $path !== $filePath && $canonicalTemplatePath !== $filePath ) {
                continue;
            }

            $isAlias = $canonicalTemplatePath === $filePath && $path !== $filePath;
            $artifact['files'][$fileIndex]['page_type'] = $pageType;
            $artifact['files'][$fileIndex]['template_slug'] = $this->canonicalTemplateSlug($pageType) ?: $pageSlug;
            $artifact['files'][$fileIndex]['canonical_template_path'] = '' !== $canonicalTemplatePath ? $canonicalTemplatePath : null;
            $artifact['files'][$fileIndex]['source_frame_identity'] = $isAlias
                ? array_merge($sourceFrameIdentity, array('path' => $filePath, 'alias_for_path' => $path))
                : $sourceFrameIdentity;
            if ( $isAlias || ! in_array($pageType, array('single', 'archive', '404'), true) ) {
                continue;
            }
            $templateSlug = $this->canonicalTemplateSlug($pageType);
            $artifact['files'][$fileIndex]['metadata'] = array(
                'template_surface' => array(
                    'schema' => 'blocks-engine/template-surface/v1',
                    'role' => $pageType,
                    'slug' => $templateSlug,
                    'logical_surface_id' => $pageType . ':' . $templateSlug,
                    'responsive_variant_id' => (string) ($sourceFrameIdentity[$isAlias ? 'primary_frame_id' : 'id'] ?? $frameId),
                    'declaration_provenance' => array('schema' => 'blocks-engine/template-surface-provenance/v1', 'kind' => 'artifact_metadata', 'source_path' => $filePath),
                ),
            );
        }
    }

    /**
     * Normalize the selected page roots once for a multi-page transform.
     *
     * @param array<string, mixed> $scenegraph
     * @param array<string, mixed> $pagePlan
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function normalizeScenegraphForPagePlan(array $scenegraph, array $pagePlan, array $options): array
    {
        $normalizeOptions = $options;
        unset(
            $normalizeOptions['multi_page'],
            $normalizeOptions['include_all_pages'],
            $normalizeOptions['frame_ids'],
            $normalizeOptions['entry_frame_id'],
            $normalizeOptions['frame_slug_map'],
            $normalizeOptions['responsive_variants'],
            $normalizeOptions['page_name']
        );

        $normalizeOptions['render_document'] = true;
        $normalizeOptions['document_frame_ids'] = $this->pagePlanFrameIds($pagePlan);

        return $this->scenegraphNormalizer->normalize($scenegraph, $normalizeOptions);
    }

    /**
     * @param array<string, mixed>             $artifact
     * @param array<int, array<string, mixed>> $diagnostics
     * @param array<string, mixed>             $figmaSourceReport
     * @param array<string, mixed>             $options
     * @param bool                             $attachMismatchReports
     * @param array<string, mixed>             $metrics
     */
    private function finalizeArtifactResult(
        array $artifact,
        string $status,
        array $diagnostics,
        array $figmaSourceReport,
        array $options,
        float $startedAt,
        bool $attachMismatchReports,
        array $metrics
    ): FigmaTransformResult {
        if ( $attachMismatchReports ) {
            $artifact = $this->withLayoutMismatchReport($artifact, $options);
            $artifact = $this->withRenderStyleMismatchReport($artifact, $options);
            $status = $artifact['status'];
        }
        $transformDiagnostics = is_array($artifact['source_report']['transform_diagnostics'] ?? null)
            ? $artifact['source_report']['transform_diagnostics']
            : array();

        return FigmaTransformResult::create(
            $status,
            array_merge($diagnostics, is_array($artifact['diagnostics'] ?? null) ? $artifact['diagnostics'] : array()),
            $artifact['files'],
            $artifact['assets'],
            array(
                'figma' => array_merge($figmaSourceReport, array('html' => $artifact['source_report'])),
                'compiled_site' => $this->compiledSiteSourceReport($artifact),
            ),
            $this->parityReportBuilder->build($options['parity'] ?? array()),
            array_merge(
                $metrics,
                array(
                    'transform_duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'vector_placeholder_count' => (int) ($transformDiagnostics['vectors']['placeholders'] ?? 0),
                )
            )
        );
    }

    /**
     * @param array<string, mixed> $pagePlan
     * @return array<int, string>
     */
    private function pagePlanFrameIds(array $pagePlan): array
    {
        $ids = array();
        foreach ( is_array($pagePlan['pages'] ?? null) ? $pagePlan['pages'] : array() as $page ) {
            if ( ! is_array($page) ) {
                continue;
            }
            if ( isset($page['frame_id']) && is_scalar($page['frame_id']) && '' !== (string) $page['frame_id'] ) {
                $ids[(string) $page['frame_id']] = (string) $page['frame_id'];
            }
            foreach ( is_array($page['variants'] ?? null) ? $page['variants'] : array() as $variant ) {
                if ( is_array($variant) && isset($variant['frame_id']) && is_scalar($variant['frame_id']) && '' !== (string) $variant['frame_id'] ) {
                    $ids[(string) $variant['frame_id']] = (string) $variant['frame_id'];
                }
            }
        }

        return array_values($ids);
    }

    /**
     * @param array<string, mixed> $artifact
     * @param array<string, mixed> $normalized
     * @param array<string, mixed> $page
     * @return array<int, array<string, mixed>>
     */
    private function renderedNodesFromArtifact(array $artifact, array $normalized, array $page): array
    {
        $nodeMap = is_array($normalized['node_map'] ?? null) ? $normalized['node_map'] : array();
        $nodes = array();
        $htmlPages = is_array($artifact['source_report']['pages'] ?? null) ? $artifact['source_report']['pages'] : array();
        if ( empty($htmlPages) ) {
            $htmlPages = array($page);
        }
        foreach ( $htmlPages as $htmlPage ) {
            if ( ! is_array($htmlPage) || ! isset($htmlPage['frame_id']) || ! is_scalar($htmlPage['frame_id']) ) {
                continue;
            }
            $frameId = (string) $htmlPage['frame_id'];
            if ( isset($nodeMap[$frameId]) && is_array($nodeMap[$frameId]) ) {
                $nodes[] = $nodeMap[$frameId];
            }
        }

        return $nodes;
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     */
    private function countNormalizedNodes(array $nodes): int
    {
        $count = 0;
        foreach ( $nodes as $node ) {
            if ( ! is_array($node) ) {
                continue;
            }
            ++$count;
            $count += $this->countNormalizedNodes($this->normalizedChildNodes($node));
        }

        return $count;
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     */
    private function countNormalizedTextNodes(array $nodes): int
    {
        $count = 0;
        foreach ( $nodes as $node ) {
            if ( ! is_array($node) ) {
                continue;
            }
            if ( 'TEXT' === strtoupper((string) ($node['type'] ?? '')) ) {
                ++$count;
            }
            $count += $this->countNormalizedTextNodes($this->normalizedChildNodes($node));
        }

        return $count;
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @return array<string, string>
     */
    private function assetReferencesForNodes(array $nodes): array
    {
        $references = array();
        foreach ( $nodes as $node ) {
            if ( ! is_array($node) ) {
                continue;
            }
            foreach ( array('fills', 'strokes', 'backgrounds') as $paintKey ) {
                foreach ( is_array($node[$paintKey] ?? null) ? $node[$paintKey] : array() as $paint ) {
                    if ( is_array($paint) && 'IMAGE' === strtoupper((string) ($paint['type'] ?? '')) ) {
                        $ref = (string) ($paint['ref'] ?? $paint['imageHash'] ?? $paint['assetRef']['guid'] ?? '');
                        if ( '' !== $ref ) {
                            $references[$ref] = $ref;
                        }
                    }
                }
            }
            foreach ( $this->assetReferencesForNodes($this->normalizedChildNodes($node)) as $ref ) {
                $references[$ref] = $ref;
            }
        }

        return $references;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, array<string, mixed>>
     */
    private function normalizedChildNodes(array $node): array
    {
        $children = is_array($node['nodes'] ?? null) ? $node['nodes'] : (is_array($node['children'] ?? null) ? $node['children'] : array());

        return array_values(array_filter($children, 'is_array'));
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
            $visualNode['page_path'] = $path;
            $traced[] = $visualNode;
        }

        return $traced;
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
        if ( 'not_comparable' === ($layoutMismatch['status'] ?? null) ) {
            $signals[] = array('severity' => 'warning', 'code' => 'layout_mismatch_not_comparable');
        }
        $artifactQuality['signals'] = $signals;
        $summary = is_array($artifactQuality['summary'] ?? null) ? $artifactQuality['summary'] : array();
        $summary['layout_mismatch_count'] = $count;
        $summary['layout_mismatch_status'] = (string) ($layoutMismatch['status'] ?? 'not_run');
        $summary['misplaced_element_count'] = (int) ($layoutMismatch['summary']['code_counts']['misplaced_element'] ?? 0);
        $summary['element_size_mismatch_count'] = (int) ($layoutMismatch['summary']['code_counts']['element_size_mismatch'] ?? 0);
        $summary['element_outside_parent_bounds_count'] = (int) ($layoutMismatch['summary']['code_counts']['element_outside_parent_bounds'] ?? 0);
        $artifactQuality['summary'] = $summary;
        if ( $count > 0 || 'not_comparable' === ($layoutMismatch['status'] ?? null) ) {
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
        $nodes = is_array($index['nodes'] ?? null) ? $index['nodes'] : array();
        $childrenIndex = is_array($index['children_index'] ?? null) ? $index['children_index'] : array();
        foreach ( $descendantRootPaths as $rootId => $path ) {
            $this->mapDescendantLinkTargets((string) $rootId, (string) $path, $childrenIndex, $nodes, $map);
        }

        return $map;
    }

    /**
     * Map every node below a planned page frame to that page's generated path.
     * Figma prototype links often target a section/text/control inside a frame
     * instead of the page frame itself; static HTML can only navigate to the
     * generated page path, so descendants inherit their containing page target.
     *
     * @param array<string, array<int, string>>  $childrenIndex
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, string>              $map
     */
    private function mapDescendantLinkTargets(string $rootId, string $path, array $childrenIndex, array $nodes, array &$map): void
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
            $map[$nodeId] = $this->descendantLinkTargetPath($path, is_array($nodes[$nodeId] ?? null) ? $nodes[$nodeId] : array());

            foreach ( is_array($childrenIndex[$nodeId] ?? null) ? $childrenIndex[$nodeId] : array() as $childId ) {
                if ( is_string($childId) && ! isset($visited[$childId]) ) {
                    $stack[] = $childId;
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private function descendantLinkTargetPath(string $path, array $node): string
    {
        if ( 'TEXT' !== strtoupper((string) ($node['type'] ?? '')) ) {
            return $path;
        }

        $name = strtolower((string) ($node['name'] ?? ''));
        $fontSize = isset($node['fontSize']) && is_numeric($node['fontSize']) ? (float) $node['fontSize'] : null;
        if ( null !== $fontSize && $fontSize < 24.0 ) {
            return $path;
        }
        if ( null === $fontSize && ! str_contains($name, 'heading') && ! str_contains($name, 'title') ) {
            return $path;
        }

        $text = trim((string) ($node['characters'] ?? $node['text'] ?? $node['name'] ?? ''));
        if ( '' === $text ) {
            return $path;
        }

        return $this->linkHrefWithHash($path, $this->slug($text));
    }

    private function linkHrefWithHash(string $href, string $hash): string
    {
        if ( '' === $hash || str_contains($href, '#') ) {
            return $href;
        }

        return $href . '#' . $hash;
    }

    private function slug(string $value): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $value) ?? '');
        $slug = trim($slug, '-');

        return '' === $slug ? 'node' : $slug;
    }

    /**
     * @param array<int, array<string, mixed>> $pageReports
     * @return array<string, mixed>
     */
    private function aggregatePageMismatchLayout(array $pageReports): array
    {
        $layout = array(
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

        foreach ( $pageReports as $page ) {
            $pageContext = array(
                'frame_id' => (string) ($page['frame_id'] ?? ''),
                'page_path' => (string) ($page['path'] ?? ''),
                'page_name' => (string) ($page['name'] ?? ''),
            );
            $pageLayout = is_array($page['transform_diagnostics']['layout'] ?? null) ? $page['transform_diagnostics']['layout'] : array();
            $layoutMismatch = is_array($pageLayout['layout_mismatch'] ?? null) ? $pageLayout['layout_mismatch'] : array();
            $layoutMismatchDiagnostics = is_array($layoutMismatch['diagnostics'] ?? null) ? $layoutMismatch['diagnostics'] : array();
            $layout['layout_mismatch_count'] += (int) ($layoutMismatch['summary']['diagnostic_count'] ?? count($layoutMismatchDiagnostics));
            if ( ! empty($layoutMismatch) ) {
                $status = (string) ($layoutMismatch['status'] ?? 'not_run');
                if ( 'fail' === $status || ( 'not_comparable' === $status && 'fail' !== $layout['layout_mismatch_status'] ) || 'not_evaluated' === $layout['layout_mismatch_status'] ) {
                    $layout['layout_mismatch_status'] = $status;
                }
            }
            foreach ( $layoutMismatchDiagnostics as $diagnostic ) {
                if ( is_array($diagnostic) ) {
                    $layout['layout_mismatches'][] = array_merge($pageContext, $diagnostic);
                }
            }
            foreach ( is_array($layoutMismatch['summary']['clusters'] ?? null) ? $layoutMismatch['summary']['clusters'] : array() as $clusterType => $clusters ) {
                $layout['layout_mismatch_clusters'][(string) $clusterType] ??= array();
                foreach ( is_array($clusters) ? $clusters : array() as $cluster ) {
                    if ( is_array($cluster) ) {
                        $layout['layout_mismatch_clusters'][(string) $clusterType][] = array_merge($pageContext, $cluster);
                    }
                }
            }

            $renderStyle = is_array($pageLayout['render_style'] ?? null) ? $pageLayout['render_style'] : array();
            if ( empty($renderStyle) ) {
                continue;
            }
            $pageSummary = is_array($renderStyle['summary'] ?? null) ? $renderStyle['summary'] : array();
            $summary = $layout['render_style']['summary'];
            foreach ( array('source_node_count', 'render_node_count', 'matched_node_count', 'unmatched_source_node_count', 'diagnostic_count', 'reported_diagnostic_count', 'font_mismatch_count', 'color_mismatch_count', 'background_mismatch_count', 'border_mismatch_count', 'opacity_mismatch_count', 'asset_mismatch_count', 'text_metric_mismatch_count') as $key ) {
                $summary[$key] = (int) ($summary[$key] ?? 0) + (int) ($pageSummary[$key] ?? 0);
            }
            $summary['truncated'] = ! empty($summary['truncated']) || ! empty($pageSummary['truncated']);
            $summary['match_ratio'] = $summary['source_node_count'] > 0 ? round($summary['matched_node_count'] / $summary['source_node_count'], 4) : 0.0;
            foreach ( is_array($pageSummary['category_counts'] ?? null) ? $pageSummary['category_counts'] : array() as $category => $count ) {
                $summary['category_counts'][(string) $category] = (int) ($summary['category_counts'][(string) $category] ?? 0) + (int) $count;
            }
            ksort($summary['category_counts']);
            $layout['render_style']['summary'] = $summary;
            $layout['render_style_mismatch_count'] = (int) $summary['diagnostic_count'];
            $status = (string) ($renderStyle['status'] ?? 'not_run');
            if ( 'fail' === $status ) {
                $layout['render_style']['status'] = 'fail';
                $layout['render_style_mismatch_status'] = 'fail';
            } elseif ( 'not_evaluated' === $layout['render_style_mismatch_status'] ) {
                $layout['render_style']['status'] = $status;
                $layout['render_style_mismatch_status'] = $status;
            }
            foreach ( is_array($renderStyle['diagnostics'] ?? null) ? $renderStyle['diagnostics'] : array() as $diagnostic ) {
                if ( is_array($diagnostic) ) {
                    $layout['render_style']['diagnostics'][] = array_merge($pageContext, $diagnostic);
                }
            }
        }

        if ( 'not_evaluated' === $layout['render_style_mismatch_status'] ) {
            $layout['render_style']['status'] = 'not_run';
            $layout['render_style_mismatch_status'] = 'not_run';
        }

        return $layout;
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

    private function canonicalTemplateSlug(string $templateType): string
    {
        return match ( $templateType ) {
            'single' => 'single',
            'archive' => 'archive',
            '404' => '404',
            default => '',
        };
    }

    private function canonicalTemplatePath(string $templateType): string
    {
        $slug = $this->canonicalTemplateSlug($templateType);

        return '' === $slug ? '' : $slug . '.html';
    }

    private function sanitizeAttribute(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
