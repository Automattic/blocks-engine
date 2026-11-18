<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics;

use Automattic\BlocksEngine\PhpTransformer\Contract\ConversionFindingContract;
use Automattic\BlocksEngine\PhpTransformer\Contract\HtmlValidationOutcome;
use Automattic\BlocksEngine\PhpTransformer\Contract\ValidationEvidencePolicy;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\StaticSite\FontMaterialization\CssFontAnalysisCache;
use Automattic\BlocksEngine\PhpTransformer\StaticSite\FontMaterialization\FontMaterializationPlanBuilder;
use Automattic\BlocksEngine\PhpTransformer\Support\StepTimer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use Closure;
use DOMElement;

/**
 * Validates one compilation's output against its source: block validity,
 * semantic parity, content round trip, media retention and dead projected
 * selectors, folded into the validation outcome and conversion diagnostics.
 */
final class CompilationValidation
{
    private readonly DiagnosticsCollector $diagnosticsCollector;
    private readonly SemanticParityReporter $semanticParity;
    private readonly ContentRoundTripReporter $contentRoundTrip;
    private readonly SourceMediaRetentionReporter $sourceMediaRetention;
    private readonly DeadProjectedSelectorReporter $deadProjectedSelectors;
    private readonly SourceContentConservationReporter $sourceContentConservation;

    /**
     * @param (Closure(DOMElement): bool)|null $isDisplayNoneAtEveryViewport Stylesheet
     *        cascade evidence that an element never renders at any viewport.
     */
    public function __construct(
        private readonly Runtime $runtime,
        private readonly HtmlTransformerSession $session,
        CssFontAnalysisCache $cssFontAnalysis,
        private readonly ?Closure $isDisplayNoneAtEveryViewport = null
    ) {
        $this->diagnosticsCollector = new DiagnosticsCollector();
        $this->semanticParity = new SemanticParityReporter(
            $this->runtime,
            new TypographyParityAnalyzer(new FontMaterializationPlanBuilder($cssFontAnalysis))
        );
        $this->contentRoundTrip = new ContentRoundTripReporter();
        $this->sourceMediaRetention = new SourceMediaRetentionReporter();
        $this->deadProjectedSelectors = new DeadProjectedSelectorReporter();
        $this->sourceContentConservation = new SourceContentConservationReporter();
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $sourceProvenance
     * @param array<int, array<string, mixed>> $shellArtifacts
     * @param array<int, array<string, mixed>> $fallbacks
     * @param list<array{authored: string, projected: string, stylesheet?: string, scope?: string}> $projectedSelectorBindings
     */
    public function validate(StepTimer $timer, DOMElement $body, array $blocks, string $serializedBlocks, array $sourceProvenance, array $shellArtifacts, array $fallbacks, string $html, string $staticCss, ValidationEvidencePolicy $validationEvidence, array $projectedSelectorBindings, string $sourcePath): CompilationValidationResult
    {
        $timer->start('transform.validation.block_validity');
        $blockValidityEvaluation = $this->runtime->evaluateBlockSerialization($blocks);
        $blockValidityReport = $blockValidityEvaluation->report();
        $timer->start('transform.validation.semantic_parity');
        $semanticParityEvaluation = $this->semanticParity->evaluate($body, $blocks, $sourceProvenance, $html, $staticCss);
        $semanticParityReport = $semanticParityEvaluation->report($validationEvidence);
        $timer->start('transform.validation.content_round_trip');
        $contentRoundTripEvaluation = $this->contentRoundTrip->evaluate($serializedBlocks, $html, $this->session->transformationEvidenceState()->formControlEchoTexts());
        $contentRoundTripReport = $contentRoundTripEvaluation->report();
        // The round-trip check runs output ⊆ source to catch invented copy. This
        // runs the other way for media, which nothing did: an image demoted to
        // its own `alt` text is not absent, so every loss counter stayed at zero
        // while the page lost its pictures.
        $timer->start('transform.validation.media_retention');
        $sourceMediaRetentionReport = $this->sourceMediaRetention->evaluate(
            $html,
            $serializedBlocks,
            array_map(
                static fn (array $asset): string => (string) ( $asset['content'] ?? '' ),
                array_filter(
                    $this->session->assetMaterializationState()->assets(),
                    static fn (mixed $asset): bool => is_array($asset) && 'css' === ( $asset['kind'] ?? '' )
                )
            )
        )->report();
        $timer->start('transform.validation.dead_selectors');
        $deadProjectedSelectorHaystack = $serializedBlocks;
        foreach ( $shellArtifacts as $shellArtifact ) {
            $deadProjectedSelectorHaystack .= "\n" . (string) ( $shellArtifact['block_markup'] ?? '' );
        }
        $deadProjectedSelectorEvaluation = $this->deadProjectedSelectors->evaluate(
            $projectedSelectorBindings,
            array($sourcePath => $deadProjectedSelectorHaystack),
            array($sourcePath => $html)
        );
        $deadProjectedSelectorsReport = $deadProjectedSelectorEvaluation->report();
        // Output ⊆ source catches invented copy; this runs source → blocks so a
        // converter that silently drops a subtree is reported as content loss.
        $timer->start('transform.validation.content_conservation');
        $droppedContentFindings = $this->sourceContentConservation->evaluate(
            $body,
            array_merge(array($serializedBlocks), array_map(static fn (array $artifact): string => (string) ( $artifact['block_markup'] ?? '' ), $shellArtifacts)),
            $sourceProvenance,
            $fallbacks,
            $sourcePath,
            fn (DOMElement $element): bool => $this->session->navigationProjectionState()->isSuppressed($element)
                || $this->session->authorSelectorProjectionState()->isSupersededControlPath((string) $element->getNodePath())
                || $this->session->transformationEvidenceState()->isOmittedSkipLink((string) $element->getNodePath())
                || ( null !== $this->isDisplayNoneAtEveryViewport && ($this->isDisplayNoneAtEveryViewport)($element) )
        );
        $timer->start('transform.diagnostics');
        $validationOutcome = HtmlValidationOutcome::fromValidationFacts(
            $blockValidityEvaluation->status,
            $blockValidityEvaluation->findings,
            $semanticParityEvaluation->status(),
            $semanticParityEvaluation->findings,
            $contentRoundTripEvaluation->status(),
            $contentRoundTripEvaluation->findings
        );
        $diagnostics = $this->diagnosticsCollector->collect(
            HtmlTransformer::class,
            $this->session->runtimeBehaviorState()->scriptMetadata(),
            $fallbacks,
            $this->session->runtimeDomState()->islands(),
            $this->session->runtimeDomState()->preservations(),
            $this->session->runtimeDomState()->fallbacks(),
            $validationOutcome
        );
        foreach ( $deadProjectedSelectorEvaluation->findings as $finding ) {
            $diagnostics[] = ConversionFindingContract::withClassification(array_merge($finding, array(
                'source'  => HtmlTransformer::class,
                'message' => (string) ($finding['summary'] ?? ''),
            )));
        }

        array_push($diagnostics, ...$droppedContentFindings);

        return new CompilationValidationResult(
            $blockValidityReport,
            $semanticParityReport,
            $contentRoundTripReport,
            $sourceMediaRetentionReport,
            $deadProjectedSelectorsReport,
            $validationOutcome,
            $diagnostics
        );
    }
}
