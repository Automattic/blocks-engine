<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer;

use Automattic\BlocksEngine\FigmaTransformer\Contract\FigmaTransformResult;
use Automattic\BlocksEngine\FigmaTransformer\FigFile\FigArchiveReader;
use Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter;
use Automattic\BlocksEngine\FigmaTransformer\Parity\ParityReportBuilder;
use Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ScenegraphNormalizer;

/**
 * Public Figma transformation entrypoint.
 */
final class FigmaTransformer
{
    public function __construct(
        private readonly FigArchiveReader $archiveReader = new FigArchiveReader(),
        private readonly StaticHtmlEmitter $htmlEmitter = new StaticHtmlEmitter(),
        private readonly ParityReportBuilder $parityReportBuilder = new ParityReportBuilder(),
        private readonly ScenegraphNormalizer $scenegraphNormalizer = new ScenegraphNormalizer()
    ) {
    }

    /**
     * Transform a .fig file or .fig wrapper archive into the canonical result envelope.
     *
     * @param array<string, mixed> $options Transformation options.
     */
    public function transformFile(string $path, array $options = array()): FigmaTransformResult
    {
        $startedAt = microtime(true);
        $archive   = $this->archiveReader->read($path);

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

            $scenegraphSourceReports = $scenegraphResult['source_reports']['figma'] ?? array();
            return FigmaTransformResult::create(
                $scenegraphStatus,
                array_merge($diagnostics, $scenegraphResult['diagnostics'] ?? array()),
                $scenegraphResult['files'] ?? array(),
                $scenegraphResult['assets'] ?? array(),
                array(
                    'figma' => array_merge(
                        array_merge($sourceReports['figma'], array('decoded_scenegraph' => $scenegraphCandidate['report'])),
                        is_array($scenegraphSourceReports) ? $scenegraphSourceReports : array()
                    ),
                ),
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
            'theme'      => array_filter(array(
                'font_usage' => $fontUsage,
            ), static fn (mixed $value): bool => array() !== $value && '' !== $value),
        ), static fn (mixed $value): bool => array() !== $value && '' !== $value);
    }
}
