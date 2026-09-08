<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Contract;

/**
 * In-memory facts required when an HTML result is assembled into an artifact.
 *
 * This is deliberately not serialized: source_reports remains the compatible
 * diagnostic projection for existing result-envelope consumers.
 */
final class BlockCompilationOutput
{
    /**
     * @param array<int, string> $runtimeBlockPaths
     * @param array<int, string> $visualBlockPaths
     * @param array<string, mixed>|null $editabilityReport
     * @param array<string, mixed> $responsiveCounterpartContracts
     * @param array<string, mixed> $layoutGeometryProof
     * @param array<int, array<string, mixed>> $reusableComponents
     * @param array<int, array<string, mixed>> $runtimeIslands
     * @param array<int, array<string, mixed>> $generatedBlocks
     * @param array<int, array<string, mixed>> $gutenbergGaps
     * @param array<int, array<string, mixed>> $interactionCandidates
     * @param array<int, string> $supersededSelectors
     * @param array<int, array<string, mixed>> $authorStylesheetProjections
     * @param array<int, array<string, mixed>> $runtimeScriptProjections
     * @param array<int, array<string, mixed>> $shellArtifacts
     * @param array<string, mixed> $coreHtmlFallbackEvidence
     */
    public function __construct(
        public readonly array $runtimeBlockPaths = array(),
        public readonly array $visualBlockPaths = array(),
        public readonly ?array $editabilityReport = null,
        public readonly array $responsiveCounterpartContracts = array(),
        public readonly array $layoutGeometryProof = array(),
        public readonly array $reusableComponents = array(),
        public readonly array $runtimeIslands = array(),
        public readonly array $generatedBlocks = array(),
        public readonly array $gutenbergGaps = array(),
        public readonly array $interactionCandidates = array(),
        public readonly array $supersededSelectors = array(),
        public readonly array $authorStylesheetProjections = array(),
        public readonly array $runtimeScriptProjections = array(),
        public readonly array $shellArtifacts = array(),
        public readonly array $coreHtmlFallbackEvidence = array()
    ) {
    }
}
