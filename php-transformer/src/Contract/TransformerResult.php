<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Contract;

final class TransformerResult
{
    public const SCHEMA = 'blocks-engine/php-transformer/result/v1';

    /**
     * @param array<int, array<string, mixed>> $components
     * @param array<int, array<string, mixed>> $blockTypes
     * @param array<string, mixed> $sourceReports
     * @param array<string, mixed> $legacyMapping
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $documents
     * @param array<int, array<string, mixed>> $assets
     * @param array<int, array<string, mixed>> $diagnostics
     * @param array<int, array<string, mixed>> $fallbacks
     * @param array<int, array<string, mixed>> $provenance
     * @param array<int, array<string, mixed>> $coverage
     * @param array<string, mixed> $context
     * @param array<string, int|float> $metrics
     */
    public function __construct(
        public readonly string $status = 'success',
        public readonly array $components = array(),
        public readonly array $blockTypes = array(),
        public readonly array $sourceReports = array(),
        public readonly array $legacyMapping = array(),
        public readonly array $blocks = array(),
        public readonly string $serializedBlocks = '',
        public readonly array $documents = array(),
        public readonly array $assets = array(),
        public readonly array $diagnostics = array(),
        public readonly array $fallbacks = array(),
        public readonly array $provenance = array(),
        public readonly array $coverage = array(),
        public readonly array $context = array(),
        public readonly array $metrics = array()
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array(
            'schema'            => self::SCHEMA,
            'status'            => $this->status,
            'components'        => $this->components,
            'block_types'       => $this->blockTypes,
            'source_reports'    => $this->sourceReports,
            'legacy_mapping'    => $this->legacyMapping,
            'blocks'            => $this->blocks,
            'serialized_blocks' => $this->serializedBlocks,
            'documents'         => $this->documents,
            'assets'            => $this->assets,
            'diagnostics'       => $this->diagnostics,
            'fallbacks'         => $this->fallbacks,
            'provenance'        => $this->provenance,
            'coverage'          => $this->coverage,
            'context'           => $this->context,
            'metrics'           => $this->metrics,
        );
    }
}
