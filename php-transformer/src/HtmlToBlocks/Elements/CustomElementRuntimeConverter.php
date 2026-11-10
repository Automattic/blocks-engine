<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\FallbackDiagnostic;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\SourceElementDescription;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/** Disposes of custom elements whose rendering depends on a defining script. */
final class CustomElementRuntimeConverter implements ElementConverter
{
    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly StyleResolver $styleResolver,
        private readonly SourceElementDescription $sourceElementDescription,
        private readonly RuntimeIslandAnalyzer $runtimeIslands,
        private readonly GeneratedComponentLowering $generatedComponents,
        private readonly SourceBlockCreator $createBlock
    ) {
    }

    /**
     * Preserve a textless custom element only when captured script content proves
     * it defines that tag. A configuration-bearing element without that proof
     * stays an explicit missing-functional-content finding rather than a silent
     * drop or a generic unsupported-element silence.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
    {
        $decision = ( new CustomElementRuntimeDependency() )->classify(
            $element,
            $this->session->runtimeBehaviorState()->runtimeScriptMetadata(),
            $this->session->runtimeBehaviorState()->runtimeProjectionScriptAssets()
        );
        if ( 'preserve' !== $decision['disposition'] && 'unproven' !== $decision['disposition'] ) {
            return ConversionOutcome::unhandled();
        }
        if ( 'unproven' === $decision['disposition'] ) {
            // A host the source cascade hides at every viewport renders
            // nothing there, so omitting it loses no content or behavior.
            if ( $this->styleResolver->isDisplayNoneAtEveryViewport($element) ) {
                return ConversionOutcome::handled(null);
            }
            $fallbacks[] = FallbackDiagnostic::build(array(
                'type'            => 'missing_functional_content',
                'reason'          => CustomElementRuntimeDependency::UNPROVEN_REASON,
                'diagnostic_code' => CustomElementRuntimeDependency::UNPROVEN_CODE,
                'message'         => 'A functional custom element was not preserved because no captured script proves it defines that element.',
                'source_format'   => 'html',
                'tag'             => $tagName,
                'selector'        => SourceDom::elementSelector($element),
                'attributes'      => SourceDom::htmlAttributes($element),
                'context'         => $this->sourceElementDescription->sourceContext($element),
                'html'            => SourceDom::safeFallbackHtml($element, $this->session->authorSelectorProjectionState()->tagMarkers()),
            ), $this->session->transformationProvenanceState()->fallback());

            return ConversionOutcome::handled(null);
        }

        $this->runtimeIslands->recordRuntimeIsland(
            $element,
            'custom_element',
            'custom_element_requires_defining_script',
            'client_script_execution',
            array(
                'required_scripts'      => $decision['scripts'],
                'preservation_strategy' => 'sanitized_embed_markup',
            )
        );
        $generated = $this->session->fallbackEmitter()->maybeGenerateCustomBlock($element, $this->session->requireGeneratedBlockRegistry(), true, true);
        $block = null !== $generated
            ? $this->generatedComponents->generatedBlock($generated, $element)
            : $this->createBlock->htmlPreservationBlock($element);
        $block['_editability_runtime_owned'] = true;

        return ConversionOutcome::handled($block);
    }
}
