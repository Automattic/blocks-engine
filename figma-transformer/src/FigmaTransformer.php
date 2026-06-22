<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer;

use Automattic\BlocksEngine\FigmaTransformer\Contract\FigmaTransformResult;
use Automattic\BlocksEngine\FigmaTransformer\FigFile\FigArchiveReader;
use Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter;
use Automattic\BlocksEngine\FigmaTransformer\Parity\ParityReportBuilder;

/**
 * Public Figma transformation entrypoint.
 */
final class FigmaTransformer
{
    public function __construct(
        private readonly FigArchiveReader $archiveReader = new FigArchiveReader(),
        private readonly StaticHtmlEmitter $htmlEmitter = new StaticHtmlEmitter(),
        private readonly ParityReportBuilder $parityReportBuilder = new ParityReportBuilder()
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

        return FigmaTransformResult::create(
            'success_with_warnings',
            $diagnostics,
            array(),
            $archive['assets'],
            $sourceReports,
            $parity,
            $metrics
        );
    }

    /**
     * Transform a normalized scenegraph into static HTML artifact files.
     *
     * @param array<string, mixed> $scenegraph Normalized Figma scenegraph.
     * @param array<string, mixed> $options Transformation options.
     */
    public function transformScenegraph(array $scenegraph, array $options = array()): FigmaTransformResult
    {
        $startedAt = microtime(true);
        $artifact  = $this->htmlEmitter->emit($scenegraph, $options);
        $parity    = $this->parityReportBuilder->build($options['parity'] ?? array());

        return FigmaTransformResult::create(
            $artifact['status'],
            $artifact['diagnostics'],
            $artifact['files'],
            $artifact['assets'],
            array(
                'figma' => array(
                    'scenegraph' => $artifact['source_report'],
                ),
            ),
            $parity,
            array(
                'node_count'             => $artifact['metrics']['node_count'] ?? 0,
                'file_count'             => count($artifact['files']),
                'transform_duration_ms'  => (int) round((microtime(true) - $startedAt) * 1000),
            )
        );
    }
}
