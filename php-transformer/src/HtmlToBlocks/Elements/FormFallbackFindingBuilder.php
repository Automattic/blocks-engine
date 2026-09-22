<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormControlTopologyBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormPresentationGraphBuilder;
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
     * @param array<string, mixed>|null $layoutGraph
     * @return array<string, mixed>
     */
    public function build(DOMElement $element, ?array $readableFormBlock, ?array $bindingBlock = null, ?array $layoutGraph = null): array
    {
        $controls = $this->metadataBuilder->controls($element);
        $controlTopology = (new FormControlTopologyBuilder())->build($element);
        $layoutGraph ??= (new FormLayoutGraphBuilder())->build($element, $this->context->stylesheetAssets(), $this->context->formLayoutCss());
        $presentationBuilder = new FormPresentationGraphBuilder(
            fn (DOMElement $control, string $value): string => $this->context->resolvePresentationValue($control, $value),
            fn (DOMElement $element): string => $this->context->sanitizeInlineSvgMarkup($element),
            fn (DOMElement $control): ?DOMElement => $this->metadataBuilder->requiredMarker($control)
        );
        $presentationGraph = $presentationBuilder->build($element, $this->context->stylesheetAssets(), $this->context->formLayoutCss());
        $formMetadata = $this->metadataBuilder->form($element);
        $containerPresentation = $presentationBuilder->buildContainer($element, $this->context->stylesheetAssets(), $this->context->formLayoutCss());
        if ($containerPresentation) $formMetadata['container_presentation'] = $containerPresentation;
        $choiceGroups = $this->choiceGroups($element);
        $boundedHtml = $this->context->boundedFallbackHtml($element);
        $replacesRuntimeIsland = null !== $bindingBlock;
        $bindingBlock ??= $readableFormBlock;
        $supersededRuntimeSelectors = $this->context->runtimeDomSelectors($element);
        if ( $replacesRuntimeIsland ) {
            $supersededRuntimeSelectors[] = SourceDom::runtimeIslandSelector($element);
        }
        // A real `<form>`, and any explicit replacement block, is the block the
        // page emits for the element, so it anchors on itself. A div pseudo-form
        // is not replaced by the readable block built for it: the page emits its
        // own converted subtree instead. Anchor that binding on the source
        // element so the form entity keeps an exact anchor the page still owns.
        $pageOwnedAnchor = ! $replacesRuntimeIsland && 'form' !== strtolower($element->tagName) ? $element : null;
        $binding = null !== $pageOwnedAnchor
            ? $this->context->blockBinding(array(), 'form', $supersededRuntimeSelectors, $pageOwnedAnchor)
            : ( null !== $bindingBlock ? $this->context->blockBinding($bindingBlock, 'form', $supersededRuntimeSelectors) : array() );
        if ( array() === $binding && array() !== $choiceGroups ) {
            $binding = $this->context->blockBinding(array(), 'form', $supersededRuntimeSelectors, $element);
        }

        $finding = array(
            'type'             => 'html',
            'reason'           => 'form_requires_runtime',
            'diagnostic_code'  => 'html_form_fallback',
            'message'          => 'Form intent and controls were extracted as provider-materializable metadata; the source form markup is preserved until a form provider materializes it.',
            'source_format'    => 'html',
            'tag'              => strtolower($element->tagName),
            'selector'         => SourceDom::elementSelector($element),
            'attributes'       => SourceDom::htmlAttributes($element),
            'form'             => $formMetadata,
            'success_panel'    => $this->successPanelMetadataBuilder->build($element),
            'context'          => $this->context->sourceContext($element),
            'classification'   => $this->context->classifyFallbackSubtree($element),
            'events'           => SourceDom::eventMetadata($element),
            'readable_blocks'  => null !== $readableFormBlock ? array( $readableFormBlock ) : array(),
            'binding'          => $binding,
            'controls'         => $controls,
            'control_topology' => $controlTopology,
            'sibling_relations' => (new FormControlTopologyBuilder())->directLabelControlPairs($element),
            'layout_graph'     => $layoutGraph,
            'presentation_graph' => $presentationGraph,
            'control_count'    => count($controls),
            'text_length'      => strlen(trim($element->textContent ?? '')),
            'child_count'      => SourceDom::childElementCount($element),
            'html'             => $boundedHtml['html'],
            'html_bytes'       => $boundedHtml['bytes'],
            'html_truncated'   => $boundedHtml['truncated'],
        );
        if ( array() !== $choiceGroups ) {
            $finding['choice_groups'] = $choiceGroups;
        }
        if ( 'form' !== strtolower($element->tagName) ) {
            $finding['form_boundary'] = $this->pseudoFormAnalyzer->boundaryMetadata($element);
        }

        return $this->context->buildFallbackDiagnostic($finding);
    }

    /** @return array<int, array<string, mixed>> */
    private function choiceGroups(DOMElement $form): array
    {
        $groups = array();
        foreach ( $form->getElementsByTagName('*') as $element ) {
            if ( ! $element instanceof DOMElement || 'true' !== strtolower($element->getAttribute('data-blocks-engine-choice-group')) ) {
                continue;
            }
            $config = json_decode($element->getAttribute('data-blocks-engine-choice-config'), true);
            if ( ! is_array($config) || ! is_array($config['group'] ?? null) || ! is_array($config['choices'] ?? null) || ! is_array($config['states'] ?? null) ) {
                continue;
            }
            $states = array_values(array_filter($config['states'], 'is_array'));
            $initial = $states[0] ?? array();
            $choices = array();
            foreach ( $config['choices'] as $choice ) {
                if ( ! is_array($choice) || ! is_int($choice['index'] ?? null) ) continue;
                $choices[] = array_filter(array(
                    'index' => $choice['index'],
                    'selector' => is_string($choice['selector'] ?? null) ? $choice['selector'] : '',
                    'tag' => is_string($choice['tag'] ?? null) ? $choice['tag'] : '',
                    'id' => is_string($choice['id'] ?? null) ? $choice['id'] : '',
                    'role' => is_string($choice['role'] ?? null) ? $choice['role'] : '',
                    'label' => is_string($choice['label'] ?? null) ? $choice['label'] : '',
                    'observed_choice_key' => is_string($choice['observed_choice_key'] ?? null) ? $choice['observed_choice_key'] : '',
                    'source_value' => array_key_exists('source_value', $choice) ? $choice['source_value'] : null,
                ), static fn (mixed $value): bool => null !== $value && '' !== $value);
                if ( ! array_key_exists('source_value', $choices[array_key_last($choices)]) ) $choices[array_key_last($choices)]['source_value'] = null;
            }
            if ( count($choices) < 2 ) continue;
            $groups[] = array(
                'group' => $config['group'],
                'choices' => $choices,
                'observed_transition' => array(
                    'selected_index' => is_int($initial['selectedIndex'] ?? null) ? $initial['selectedIndex'] : null,
                    'selected' => is_array($initial['selected'] ?? null) ? array_values(array_map(static fn (mixed $value): ?bool => is_bool($value) ? $value : null, $initial['selected'])) : array(),
                ),
            );
        }

        return $groups;
    }
}
