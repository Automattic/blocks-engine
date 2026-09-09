<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics;

use Automattic\BlocksEngine\PhpTransformer\Contract\ConversionFindingContract;

/**
 * The single semantic-parity evaluation, projected as a detailed report or
 * consumed directly by required validation diagnostics.
 */
final class SemanticParityEvaluation
{
    /**
     * @param array<string, int> $sourceLandmarks
     * @param array<string, int> $blockLandmarks
     * @param array<int, array<string, mixed>> $sourceMenus
     * @param array<int, array<string, mixed>> $blockMenus
     * @param array<int, array<string, mixed>> $findings
     */
    public function __construct(
        public readonly array $sourceLandmarks,
        public readonly array $blockLandmarks,
        public readonly array $sourceMenus,
        public readonly array $blockMenus,
        public readonly array $findings
    ) {
    }

    public function status(): string
    {
        return array() === $this->findings ? 'pass' : 'warning';
    }

    /** @return array<string, mixed> */
    public function report(string $validationEvidence = 'full'): array
    {
        $report = array(
            'schema' => 'blocks-engine/php-transformer/semantic-parity/v1',
            'finding_schema' => ConversionFindingContract::SCHEMA,
            'status' => $this->status(),
            'landmarks' => array(
                'source' => $this->sourceLandmarks,
                'blocks' => $this->blockLandmarks,
            ),
            'navigation_menus' => array(
                'source' => $this->sourceMenus,
                'blocks' => $this->blockMenus,
            ),
            'findings' => $this->findings,
        );
        if ('compact' === $validationEvidence) {
            // Findings and status remain authoritative; inventories are optional detail.
            unset($report['landmarks'], $report['navigation_menus']);
            $report = array_slice($report, 0, 3, true) + array(
                'evidence' => array(
                    'detail' => 'compact',
                    'omitted' => array('landmarks', 'navigation_menus'),
                ),
            ) + array_slice($report, 3, null, true);
        }

        return $report;
    }
}
