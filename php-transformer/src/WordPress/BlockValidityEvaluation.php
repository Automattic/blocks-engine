<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPress;

/**
 * The single block-validity evaluation. Its report is a projection for public
 * callers; compiler consumers use the authoritative status and findings here.
 */
final class BlockValidityEvaluation
{
    /**
     * @param array<string, mixed> $summary
     * @param array<int, array<string, mixed>> $findings
     */
    private function __construct(
        public readonly string $status,
        public readonly array $summary,
        public readonly array $findings
    ) {
    }

    /** @param array<int, array<string, mixed>> $blocks */
    public static function fromBlocks(array $blocks): self
    {
        $structuralReport = ( new BlockValidityValidator() )->validateBlocks($blocks);
        $findings = is_array($structuralReport['findings'] ?? null) ? $structuralReport['findings'] : array();
        $findings = array_merge($findings, ( new CanonicalSaveShapeValidator() )->findings($blocks));

        return new self(
            status: array() === $findings ? 'pass' : 'warning',
            summary: array(
                'block_count'         => $structuralReport['summary']['block_count'] ?? 0,
                'finding_count'       => count($findings),
                'checked_block_types' => $structuralReport['summary']['checked_block_types'] ?? array(),
            ),
            findings: $findings
        );
    }

    public function withParseFailure(): self
    {
        $findings = $this->findings;
        $findings[] = array(
            'code'     => 'serialized_blocks_parse_failed',
            'severity' => 'warning',
            'category' => 'wp_block_validity',
            'path'     => 'serialized_blocks',
            'summary'  => 'Serialized block comments were present but could not be parsed into a balanced block tree.',
        );
        $summary = $this->summary;
        $summary['finding_count'] = count($findings);

        return new self('warning', $summary, $findings);
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        return array(
            'schema'   => BlockValidityValidator::SCHEMA,
            'status'   => $this->status,
            'summary'  => $this->summary,
            'findings' => $this->findings,
        );
    }
}
