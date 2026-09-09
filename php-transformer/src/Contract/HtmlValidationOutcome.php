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
     * @param array<int, array<string, mixed>> $contentRoundTripFindings
     */
    public static function fromValidationFacts(
        string $blockValidityStatus,
        array $blockValidityFindings,
        string $semanticParityStatus,
        array $semanticParityFindings,
        string $contentRoundTripStatus,
        array $contentRoundTripFindings
    ): self {
        return new self(
            blockValidityStatus: $blockValidityStatus,
            blockValidityFindings: self::filteredFindings($blockValidityFindings, array('block_name', 'path')),
            semanticParityStatus: $semanticParityStatus,
            semanticParityFindings: self::filteredFindings($semanticParityFindings, array('selector')),
            contentRoundTripStatus: $contentRoundTripStatus,
            contentRoundTripFindings: self::filteredFindings($contentRoundTripFindings, array('text'))
        );
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
