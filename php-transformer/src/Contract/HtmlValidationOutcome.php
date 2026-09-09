<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Contract;

/**
 * Required HTML validation facts used for diagnostics and acceptance.
 *
 * Detailed validator reports remain source_reports projections. This contract
 * retains only each validator's status and the finding fields operational
 * consumers need, so they do not recover required facts from report maps.
 */
final class HtmlValidationOutcome
{
    /**
     * @param array<int, array<string, mixed>> $blockValidityFindings
     * @param array<int, array<string, mixed>> $semanticParityFindings
     * @param array<int, array<string, mixed>> $contentRoundTripFindings
     */
    public function __construct(
        public readonly string $blockValidityStatus = 'not_evaluated',
        public readonly array $blockValidityFindings = array(),
        public readonly string $semanticParityStatus = 'not_evaluated',
        public readonly array $semanticParityFindings = array(),
        public readonly string $contentRoundTripStatus = 'not_evaluated',
        public readonly array $contentRoundTripFindings = array()
    ) {
    }

    /**
     * Validators supply facts directly; detailed reports remain projections.
     *
     * @param array<int, array<string, mixed>> $blockValidityFindings
     * @param array<int, array<string, mixed>> $semanticParityFindings
     * @param array<string, mixed> $contentRoundTripReport
     */
    public static function fromValidationFactsAndContentRoundTripReport(
        string $blockValidityStatus,
        array $blockValidityFindings,
        string $semanticParityStatus,
        array $semanticParityFindings,
        array $contentRoundTripReport
    ): self {
        return new self(
            blockValidityStatus: $blockValidityStatus,
            blockValidityFindings: self::filteredFindings($blockValidityFindings, array('block_name', 'path')),
            semanticParityStatus: $semanticParityStatus,
            semanticParityFindings: self::filteredFindings($semanticParityFindings, array('selector')),
            contentRoundTripStatus: self::status($contentRoundTripReport),
            contentRoundTripFindings: self::findings($contentRoundTripReport, array('text'))
        );
    }

    /** @param array<string, mixed> $report */
    private static function status(array $report): string
    {
        return is_string($report['status'] ?? null) ? $report['status'] : 'not_evaluated';
    }

    /** @param array<string, mixed> $report @param array<int, string> $fields @return array<int, array<string, mixed>> */
    private static function findings(array $report, array $fields): array
    {
        return self::filteredFindings(is_array($report['findings'] ?? null) ? $report['findings'] : array(), $fields);
    }

    /** @param array<int, mixed> $sourceFindings @param array<int, string> $fields @return array<int, array<string, mixed>> */
    private static function filteredFindings(array $sourceFindings, array $fields): array
    {
        $findings = array();
        foreach ($sourceFindings as $finding) {
            if (!is_array($finding)) {
                continue;
            }
            // Keep only fields needed by diagnostics, without taking ownership
            // of their established defaults or coercing their original values.
            $findings[] = array_intersect_key($finding, array_flip(array_merge(array('code', 'summary', 'severity'), $fields)));
        }
        return $findings;
    }
}
