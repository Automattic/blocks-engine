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

    /** @param array<string, mixed> $blockValidityReport @param array<string, mixed> $semanticParityReport @param array<string, mixed> $contentRoundTripReport */
    public static function fromReports(array $blockValidityReport, array $semanticParityReport, array $contentRoundTripReport): self
    {
        return new self(
            blockValidityStatus: self::status($blockValidityReport),
            blockValidityFindings: self::findings($blockValidityReport, array('block_name', 'path')),
            semanticParityStatus: self::status($semanticParityReport),
            semanticParityFindings: self::findings($semanticParityReport, array('selector')),
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
        $findings = array();
        foreach ($report['findings'] ?? array() as $finding) {
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
