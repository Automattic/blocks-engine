<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\FallbackDiagnostic;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormControlTopologyBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder;
use DOMDocument;
use DOMElement;

trait FormDispatchTrait
{
    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function convertFormDispatchElement(DOMElement $element, array &$fallbacks): ?array
    {
        $searchBlock = $this->searchBlockConverter->searchBlockFromForm($element);
        if ( null !== $searchBlock ) {
            return $searchBlock;
        }

        if ( FormControlClassifier::hasDataEntryControls($element) ) {
            $composition = $this->formCompositionPlanner->compose($element, $fallbacks);
            if ( null !== $composition ) {
                $fallbacks[] = $this->formFallbackFinding($element, $composition['block'], $composition['slot']);
                $this->formRuntimeIslandRecorder->recordForm($element, $composition['block']);
                return $composition['block'];
            }
        }

        $readableFormBlock = $this->readableFormBlockBuilder->build($element);
        if ( null !== $readableFormBlock && ! $this->formRuntimeRequirementAnalyzer->requiresPreservation($element) ) {
            if ( FormControlClassifier::hasDataEntryControls($element) ) {
                $fallbacks[] = $this->formFallbackFinding($element, $readableFormBlock);
            }

            return $readableFormBlock;
        }

        if ( FormControlClassifier::hasDataEntryControls($element) ) {
            $preservationBlock = $this->htmlPreservationBlock($element);
            $fallbacks[] = $this->formFallbackFinding($element, $readableFormBlock, $preservationBlock);
            $this->formRuntimeIslandRecorder->recordForm($element, $readableFormBlock);

            return $preservationBlock;
        }

        $readableFormBlock = $this->readableFormBlockBuilder->build($element, true);
        $this->formRuntimeIslandRecorder->recordForm($element, $readableFormBlock);

        // Surface a form fallback finding so a downstream consumer can map the
        // preserved control structure onto a working form provider.
        if ( null === $readableFormBlock || FormControlClassifier::hasDataEntryControls($element) ) {
            $fallbacks[] = $this->formFallbackFinding($element, $readableFormBlock);
        }

        return $readableFormBlock;
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     */
    private function captureDivBasedPseudoFormFallback(DOMElement $element, array &$fallbacks): void
    {
        // Some signup/contact widgets pair data-entry controls with a submit-like
        // control inside a plain container. Emit the same finding as a real form.
        if ( $this->pseudoFormAnalyzer->isPseudoForm($element) ) {
            $fallbacks[] = $this->formFallbackFinding($element, $this->readableFormBlockBuilder->build($element, true));
        }
    }

    /**
     * Build the shared html_form_fallback finding (issue #315) for an element that
     * behaves as a form. Both the real <form> path and the div-based pseudo-form
     * path emit through here so the downstream materializer receives an identical
     * shape (controls, form metadata, classification, bounded HTML) regardless of
     * whether the source markup used a <form> element.
     *
     * @param array<string, mixed>|null $readableFormBlock
     * @return array<string, mixed>
     */
    private function formFallbackFinding(DOMElement $element, ?array $readableFormBlock, ?array $bindingBlock = null): array
    {
        $controls = $this->formControlMetadataBuilder->controls($element);
        $controlTopology = (new FormControlTopologyBuilder())->build($element);
        $layoutGraph = (new FormLayoutGraphBuilder())->build($element, $this->authorStyles()->stylesheetAssets(), $this->sourceStyles()->formLayoutCss());
        $boundedHtml = $this->boundedFallbackHtml($this->safeFallbackHtml($element));
        $replacesRuntimeIsland = null !== $bindingBlock;
        $bindingBlock ??= $readableFormBlock;
        $supersededRuntimeSelectors = $this->runtimeIslands->runtimeDomSelectorsForElement($element);
        if ( $replacesRuntimeIsland ) $supersededRuntimeSelectors[] = $this->runtimeIslandSelector($element);

        $finding = array(
            'type'            => 'html',
            'reason'          => 'form_requires_runtime',
            'diagnostic_code' => 'html_form_fallback',
            'message'         => 'Form intent and controls were extracted as provider-materializable metadata; the source form markup is preserved until a form provider materializes it.',
            'source_format'   => 'html',
            'tag'             => strtolower($element->tagName),
            'selector'        => $this->elementSelector($element),
            'attributes'      => $this->htmlAttributes($element),
            'form'            => $this->formControlMetadataBuilder->form($element),
            'success_panel'   => $this->formSuccessPanelMetadataBuilder->build($element),
            'context'         => $this->sourceContext($element),
            'classification'  => $this->fallbackEmitter()->classifyFallbackSubtree($element),
            'events'          => $this->eventMetadata($element),
            'readable_blocks' => null !== $readableFormBlock ? array( $readableFormBlock ) : array(),
            'binding'         => null !== $bindingBlock ? $this->blockBinding($bindingBlock, 'form', $supersededRuntimeSelectors) : array(),
            'controls'        => $controls,
            'control_topology' => $controlTopology,
            'layout_graph'     => $layoutGraph,
            'control_count'   => count($controls),
            'text_length'     => strlen(trim($element->textContent ?? '')),
            'child_count'     => $this->childElementCount($element),
            'html'            => $boundedHtml['html'],
            'html_bytes'      => $boundedHtml['bytes'],
            'html_truncated'  => $boundedHtml['truncated'],
        );
        if ( 'form' !== strtolower($element->tagName) ) {
            $finding['form_boundary'] = $this->pseudoFormAnalyzer->boundaryMetadata($element);
        }

        return FallbackDiagnostic::build($finding, $this->transformationProvenance()->fallback());
    }

    /**
     * Record text the transformer synthesizes from a form control (label plus
     * value/placeholder/options/required state) so the content round-trip
     * reporter does not flag it as invented copy — it is intentionally absent
     * from the source's visible content. Harmless if a recorded string never
     * reaches the output: the reporter only ever uses it to suppress an exact
     * match.
     */
    private function registerFormControlEcho(string $text): void
    {
        $this->transformationEvidence()->recordFormControlEcho($text);
    }

    private function hasSearchFormSignal(DOMElement $form, DOMElement $input): bool
    {
        if ( 'search' === FormControlClassifier::controlType($input) || 'search' === strtolower(trim($this->attr($form, 'role'))) ) {
            return true;
        }

        $queryName = strtolower(trim($this->attr($input, 'name')));
        if ( in_array($queryName, array( 's', 'q', 'query', 'search' ), true) ) {
            return true;
        }

        $haystack = strtolower(implode(' ', array(
            $this->attr($form, 'action'),
            $this->attr($form, 'aria-label'),
            $this->attr($form, 'id'),
            $this->attr($form, 'class'),
        )));

        return str_contains($haystack, 'search');
    }

}
