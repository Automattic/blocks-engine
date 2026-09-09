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
        return ( new BlockValidityValidator() )
            ->evaluateBlocks($blocks)
            ->withAdditionalFindings(( new CanonicalSaveShapeValidator() )->findings($blocks));
    }

    /**
     * @param array<int, string> $checkedBlockTypes
     * @param array<int, array<string, mixed>> $findings
     */
    public static function fromStructuralFacts(int $blockCount, array $checkedBlockTypes, array $findings): self
    {
        return new self(
            status: array() === $findings ? 'pass' : 'warning',
            summary: array(
                'block_count'         => $blockCount,
                'finding_count'       => count($findings),
                'checked_block_types' => $checkedBlockTypes,
            ),
            findings: $findings
        );
    }

    /** @param array<int, array<string, mixed>> $findings */
    public function withAdditionalFindings(array $findings): self
    {
        if ( array() === $findings ) {
            return $this;
        }

        $findings = array_merge($this->findings, $findings);
        $summary = $this->summary;
        $summary['finding_count'] = count($findings);

        return new self('warning', $summary, $findings);
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
