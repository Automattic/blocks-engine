<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormControlTopologyBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder;
use DOMElement;

/** Builds the provider-materializable diagnostic for a form-like element. */
final class FormFallbackFindingBuilder
{
    public function __construct(
        private readonly FormFallbackFindingContext $context,
        private readonly FormControlMetadataBuilder $metadataBuilder,
        private readonly FormSuccessPanelMetadataBuilder $successPanelMetadataBuilder,
        private readonly PseudoFormAnalyzer $pseudoFormAnalyzer
    ) {
    }

    /**
     * @param array<string, mixed>|null $readableFormBlock
     * @param array<string, mixed>|null $bindingBlock
     * @return array<string, mixed>
     */
    public function build(DOMElement $element, ?array $readableFormBlock, ?array $bindingBlock = null): array
    {
        $controls = $this->metadataBuilder->controls($element);
        $controlTopology = (new FormControlTopologyBuilder())->build($element);
        $layoutGraph = (new FormLayoutGraphBuilder())->build($element, $this->context->stylesheetAssets(), $this->context->formLayoutCss());
        $boundedHtml = $this->context->boundedFallbackHtml($element);
        $replacesRuntimeIsland = null !== $bindingBlock;
        $bindingBlock ??= $readableFormBlock;
        $supersededRuntimeSelectors = $this->context->runtimeDomSelectors($element);
        if ( $replacesRuntimeIsland ) {
            $supersededRuntimeSelectors[] = $this->context->runtimeIslandSelector($element);
        }

        $finding = array(
            'type'             => 'html',
            'reason'           => 'form_requires_runtime',
            'diagnostic_code'  => 'html_form_fallback',
            'message'          => 'Form intent and controls were extracted as provider-materializable metadata; the source form markup is preserved until a form provider materializes it.',
            'source_format'    => 'html',
            'tag'              => strtolower($element->tagName),
            'selector'         => $this->context->elementSelector($element),
            'attributes'       => $this->context->htmlAttributes($element),
            'form'             => $this->metadataBuilder->form($element),
            'success_panel'    => $this->successPanelMetadataBuilder->build($element),
            'context'          => $this->context->sourceContext($element),
            'classification'   => $this->context->classifyFallbackSubtree($element),
            'events'           => $this->context->eventMetadata($element),
            'readable_blocks'  => null !== $readableFormBlock ? array( $readableFormBlock ) : array(),
            'binding'          => null !== $bindingBlock ? $this->context->blockBinding($bindingBlock, 'form', $supersededRuntimeSelectors) : array(),
            'controls'         => $controls,
            'control_topology' => $controlTopology,
            'layout_graph'     => $layoutGraph,
            'control_count'    => count($controls),
            'text_length'      => strlen(trim($element->textContent ?? '')),
            'child_count'      => $this->context->childElementCount($element),
            'html'             => $boundedHtml['html'],
            'html_bytes'       => $boundedHtml['bytes'],
            'html_truncated'   => $boundedHtml['truncated'],
        );
        if ( 'form' !== strtolower($element->tagName) ) {
            $finding['form_boundary'] = $this->pseudoFormAnalyzer->boundaryMetadata($element);
        }

        return $this->context->buildFallbackDiagnostic($finding);
    }
}
