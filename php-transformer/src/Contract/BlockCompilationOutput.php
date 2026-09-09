<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Contract;

/**
 * In-memory facts required when an HTML result is assembled into an artifact.
 *
 * Omitted from the canonical result envelope: source_reports remains the
 * compatible projection for existing serialized-result consumers.
 */
final class BlockCompilationOutput
{
    /** @var array<int, string> */
    public readonly array $runtimeBlockPaths;

    /** @var array<int, string> */
    public readonly array $visualBlockPaths;

    /**
     * @param array<int, array<string, mixed>> $sourceProvenance
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
        public readonly array $sourceProvenance = array(),
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
        public readonly array $coreHtmlFallbackEvidence = array(),
        public readonly HtmlValidationOutcome $validationOutcome = new HtmlValidationOutcome()
    ) {
        $runtimePaths = array();
        $visualPaths = array();
        foreach ($sourceProvenance as $entry) {
            if (!is_array($entry) || !is_string($entry['block_path'] ?? null)) continue;
            if (!empty($entry['editability_runtime_owned'])) $runtimePaths[] = $entry['block_path'];
            if (!empty($entry['editability_visual_owned'])) $visualPaths[] = $entry['block_path'];
        }
        $this->runtimeBlockPaths = $runtimePaths;
        $this->visualBlockPaths = $visualPaths;
    }

    public static function empty(): self
    {
        return new self(
            coreHtmlFallbackEvidence: CoreHtmlFallbackEvidence::fromBlocks(array(), array(), array()),
            validationOutcome: new HtmlValidationOutcome()
        );
    }
}
