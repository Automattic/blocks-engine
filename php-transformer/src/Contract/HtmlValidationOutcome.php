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
     * @param array<int, array{code: string, summary: string, severity: string, block_name: string|null, path: string|null}> $blockValidityFindings
     * @param array<int, array{code: string, summary: string, severity: string, selector: string|null}> $semanticParityFindings
     * @param array<int, array{code: string, summary: string, severity: string, text: string|null}> $contentRoundTripFindings
     */
    public function __construct(
        public readonly string $blockValidityStatus = 'pass',
        public readonly array $blockValidityFindings = array(),
        public readonly string $semanticParityStatus = 'pass',
        public readonly array $semanticParityFindings = array(),
        public readonly string $contentRoundTripStatus = 'pass',
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
        return is_string($report['status'] ?? null) ? $report['status'] : 'pass';
    }

    /** @param array<string, mixed> $report @param array<int, string> $optionalFields @return array<int, array<string, string|null>> */
    private static function findings(array $report, array $optionalFields): array
    {
        $findings = array();
        foreach ($report['findings'] ?? array() as $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $outcome = array(
                'code' => (string) ($finding['code'] ?? 'warning'),
                'summary' => (string) ($finding['summary'] ?? ''),
                'severity' => (string) ($finding['severity'] ?? 'warning'),
            );
            foreach ($optionalFields as $field) {
                $outcome[$field] = is_string($finding[$field] ?? null) ? $finding[$field] : null;
            }
            $findings[] = $outcome;
        }
        return $findings;
    }
}
