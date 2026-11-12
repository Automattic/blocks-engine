<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormControlTopologyBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormPresentationGraphBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormStepTrackDetector;
use DOMElement;

/** Builds the provider-materializable diagnostic for a form-like element. */
final class FormFallbackFindingBuilder
{
    /** @var list<string> Canonical selectors for the accepted source groups. */
    private array $choiceGroupSelectors = array();

    public function __construct(
        private readonly \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession $session,
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
        $controls = $this->metadataBuilder->controls($element, array(), null, $controlElements);
        $layoutGraph ??= (new FormLayoutGraphBuilder())->build($element, $this->session->authorStyleAnalysis()->stylesheetAssets(), $this->session->sourceStyleResolutionState()->formLayoutCss());
        $presentationBuilder = new FormPresentationGraphBuilder(
            fn (DOMElement $control, string $value): string => $this->context->resolvePresentationValue($control, $value),
            fn (DOMElement $element): string => $this->context->sanitizeInlineSvgMarkup($element),
            fn (DOMElement $control): ?DOMElement => $this->metadataBuilder->requiredMarker($control)
        );
        $formMetadata = $this->metadataBuilder->form($element);
        $containerPresentation = $presentationBuilder->buildContainer($element, $this->session->authorStyleAnalysis()->stylesheetAssets(), $this->session->sourceStyleResolutionState()->formLayoutCss());
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
            'form_intent'      => FormRuntimeRequirementAnalyzer::formIntent($element),
            'success_panel'    => $this->successPanelMetadataBuilder->build($element),
            'context'          => $this->context->sourceElementDescription->sourceContext($element),
            'classification'   => $this->session->fallbackEmitter()->classifyFallbackSubtree($element),
            'events'           => SourceDom::eventMetadata($element),
            // Top-level block annotations (never attrs): form_control_index
            // addresses controls[], form_choice_group_index addresses
            // choice_groups[]. Page provenance resolution removes both before
            // serialization; providers can replace blocks without order guesses.
            'readable_blocks'  => null !== $readableFormBlock ? $this->identifyChoiceGroups(array( $readableFormBlock ), $choiceGroups) : array(),
            'binding'          => $binding,
            'controls'         => $controls,
            'control_topology' => array(),
            'sibling_relations' => array(),
            'layout_graph'     => $layoutGraph,
            'presentation_graph' => array(),
            'control_count'    => count($controls),
            'text_length'      => strlen(trim($element->textContent ?? '')),
            'child_count'      => SourceDom::childElementCount($element),
            'html'             => $boundedHtml['html'],
            'html_bytes'       => $boundedHtml['bytes'],
            'html_truncated'   => $boundedHtml['truncated'],
        );
        if ( 'client_side_tool' === $finding['form_intent'] ) {
            $finding['runtime_requirement'] = 'client_script_execution';
            $finding['materialization_path'] = 'preserved_runtime_island';
            $finding['required_scripts'] = $this->session->fallbackEmitter()->requiredScriptsForElement($element);
            $finding['message'] = 'Client-side tool controls remain editable; preserve the source script runtime and live result region.';
            $finding['suggested_repair_class'] = 'preserve_runtime_island';
            $finding['actionability'] = 'preserve_source_script_with_readable_controls';
            $finding['materialization_target'] = array();
        }
        if ( array() !== $choiceGroups ) {
            $finding['choice_groups'] = $choiceGroups;
        }
        $steps = true === ($layoutGraph['truncated'] ?? true) ? null : (new FormStepTrackDetector())->detect($element, $layoutGraph, $this->session->authorStyleAnalysis()->stylesheetAssets(), $this->session->sourceStyleResolutionState()->formLayoutCss());
        if ( null !== $steps ) {
            $finding['steps'] = $steps;
        }
        // Provider fields and their indexed graphs span the workflow.
        // Hidden sibling panels need not have any particular step attribute.
        // Only expand within the recognized partitions, not unrelated decoys.
        if ( 'form' === strtolower($element->tagName) ) {
            $intentControls = FormRuntimeRequirementAnalyzer::intentControls($element);
            if ( null === $steps ) {
                $panelControls = (new FormWorkflowPanelDetector())->controls($element, $intentControls);
                $visibleSelectors = array_column($controls, 'selector');
                $intentControls = array_values(array_filter($intentControls, static fn (DOMElement $control): bool => in_array($control, $panelControls, true) || in_array(SourceDom::elementSelector($control), $visibleSelectors, true)));
            }
            $selectors = array_column($controls, 'selector');
            $missing = array_filter($intentControls, static fn (DOMElement $control): bool => ! in_array(SourceDom::elementSelector($control), $selectors, true));
            if ( array() !== $missing ) {
                $finding['controls'] = $this->metadataBuilder->controls($element, array(), $intentControls, $controlElements);
                $finding['control_count'] = count($finding['controls']);
            }
        }
        // Metadata can omit elements. Build every indexed graph from the exact
        // surviving inventory, not from a fresh classifier enumeration.
        $topologyBuilder = new FormControlTopologyBuilder();
        $finding['control_topology'] = $topologyBuilder->build($element, $controlElements);
        $finding['sibling_relations'] = $topologyBuilder->directLabelControlPairs($element, $controlElements);
        $finding['layout_graph'] = (new FormLayoutGraphBuilder($controlElements))->build($element, $this->session->authorStyleAnalysis()->stylesheetAssets(), $this->session->sourceStyleResolutionState()->formLayoutCss());
        $finding['presentation_graph'] = (new FormPresentationGraphBuilder(
            fn (DOMElement $control, string $value): string => $this->context->resolvePresentationValue($control, $value),
            fn (DOMElement $node): string => $this->context->sanitizeInlineSvgMarkup($node),
            fn (DOMElement $control): ?DOMElement => $this->metadataBuilder->requiredMarker($control),
            controlElements: $controlElements
        ))->build($element, $this->session->authorStyleAnalysis()->stylesheetAssets(), $this->session->sourceStyleResolutionState()->formLayoutCss());
        if ( 'form' !== strtolower($element->tagName) ) {
            $finding['form_boundary'] = $this->pseudoFormAnalyzer->boundaryMetadata($element);
        }

        $diagnostic = $this->context->buildFallbackDiagnostic($finding);
        if ( 'client_side_tool' === $finding['form_intent'] ) {
            $diagnostic['type'] = 'unsupported_html_fallback';
            $diagnostic['id'] = $diagnostic['fallback_identity'];
            $diagnostic['source_path'] = $diagnostic['source'] ?? '';
            $diagnostic['source_html_preview'] = $boundedHtml['html'];
            $diagnostic['acceptability'] = 'acceptable_preservation';
            $diagnostic['recoverability'] = 'recoverable_with_script_enqueue_or_component_runtime';
            $diagnostic['materialization_hint'] = 'preserve_source_script_with_readable_controls_and_live_results';
        }

        return $diagnostic;
    }

    /**
     * Match captured groups by source identity, not their tree position.
     * @param list<array<string, mixed>> $blocks
     * @param list<array<string, mixed>> $groups
     * @return list<array<string, mixed>>
     */
    private function identifyChoiceGroups(array $blocks, array $groups): array
    {
        foreach ($blocks as &$block) {
            // The field's box encloses the control: a substituted field keeps
            // it. The control's own frames enclose just the control, inside
            // the field box.
            $fieldShell = $block['_form_field_box'] ?? null;
            $controlShell = $block['_form_control_box'] ?? null;
            if (null !== $fieldShell || null !== $controlShell) {
                unset($block['_form_field_box'], $block['_form_control_box']);
                if (null !== $controlShell) {
                    // Marked so a provider can present the control's own
                    // frames on its control instead of around its caption.
                    $controlShell['form_control_box'] = $block['form_control_index'];
                    $block = $this->encloseInBox($block, $controlShell);
                }
                if (null !== $fieldShell) {
                    $block = $this->encloseInBox($block, $fieldShell);
                }
                continue;
            }
            foreach ($groups as $index => $group) {
                if (isset($block['_source_anchor_selector']) && $block['_source_anchor_selector'] === ($this->choiceGroupSelectors[$index] ?? null)) {
                    $block['form_choice_group_index'] = $index;
                    break;
                }
            }
            if (!empty($block['innerBlocks'])) {
                $block['innerBlocks'] = $this->identifyChoiceGroups($block['innerBlocks'], $groups);
            }
            if ('group' === ($block['form_field']['kind'] ?? null)) {
                // The legend is stated once, as the group's label.
                foreach ($block['innerBlocks'] ?? array() as $child) {
                    if (!empty($child['_form_legend'])) {
                        $block['form_field']['label_attributes'] = array_diff_key($child['attrs'] ?? array(), array('content' => true));
                    }
                }
                // Drop the legend's child slot along with the block, keeping innerContent aligned.
                $children = array();
                $content = array();
                $slot = 0;
                foreach ($block['innerContent'] ?? array() as $part) {
                    if (null !== $part) {
                        $content[] = $part;
                        continue;
                    }
                    $child = $block['innerBlocks'][$slot++] ?? null;
                    if (is_array($child) && empty($child['_form_legend'])) {
                        $children[] = $child;
                        $content[] = null;
                    }
                }
                $block['innerBlocks'] = $children;
                $block['innerContent'] = $content;
                // A fieldset framing one captured choice group is that group's node.
                $indexed = array_keys(array_filter($block['innerBlocks'], static fn (array $child): bool => isset($child['form_choice_group_index'])));
                if (1 === count($indexed) && ! isset($block['form_choice_group_index'])) {
                    $block['form_choice_group_index'] = $block['innerBlocks'][$indexed[0]]['form_choice_group_index'];
                    unset($block['innerBlocks'][$indexed[0]]['form_choice_group_index']);
                }
            }
        }
        unset($block);
        return $blocks;
    }

    /**
     * Nest the block inside an empty box shell, keeping one innerContent slot
     * for it.
     *
     * @param array<string, mixed> $block
     * @param array<string, mixed> $shell
     * @return array<string, mixed>
     */
    private function encloseInBox(array $block, array $shell): array
    {
        $shell['innerBlocks'] = array($block);
        $shell['innerContent'] = array($shell['innerContent'][0] ?? '', null, $shell['innerContent'][1] ?? '');
        return $shell;
    }

    /** @return array<int, array<string, mixed>> */
    private function choiceGroups(DOMElement $form): array
    {
        $groups = array();
        $this->choiceGroupSelectors = array();
        foreach ( $form->getElementsByTagName('*') as $element ) {
            if ( ! $element instanceof DOMElement || 'true' !== strtolower($element->getAttribute('data-blocks-engine-choice-group')) ) {
                continue;
            }
            $config = json_decode($element->getAttribute('data-blocks-engine-choice-config'), true);
            if ( ! is_array($config) || ! is_array($config['group'] ?? null) || ! is_array($config['choices'] ?? null) || ! is_array($config['states'] ?? null) ) {
                continue;
            }
            $choices = array();
            foreach ( $config['choices'] as $choice ) {
                if ( ! is_array($choice) || ! is_int($choice['index'] ?? null) ) continue;
                $choices[] = array_filter(array(
                    'index' => $choice['index'],
                    'selector' => ArrayField::string($choice, 'selector'),
                    'tag' => ArrayField::string($choice, 'tag'),
                    'id' => ArrayField::string($choice, 'id'),
                    'role' => ArrayField::string($choice, 'role'),
                    'label' => ArrayField::string($choice, 'label'),
                    'observed_choice_key' => ArrayField::string($choice, 'observed_choice_key'),
                    'source_value' => is_scalar($choice['source_value'] ?? null) ? $choice['source_value'] : null,
                ), static fn (mixed $value): bool => null !== $value && '' !== $value);
                if ( ! array_key_exists('source_value', $choices[array_key_last($choices)]) ) $choices[array_key_last($choices)]['source_value'] = null;
            }
            if ( count($choices) < 2 ) continue;
            $this->choiceGroupSelectors[] = SourceDom::elementSelector($element);
            $groups[] = array(
                // The group is a flat record of named source facts (see
                // `CapturedChoiceGroupProjector::boundedGroup()`). Nested values
                // are not part of it, and source markup must not inject them
                // into the form's fallback and runtime entity.
                'group' => array_filter($config['group'], static fn (mixed $value, int|string $key): bool => is_string($key) && is_string($value), ARRAY_FILTER_USE_BOTH),
                'choices' => $choices,
                'observed_transition' => $this->sourceSelection($element, $choices),
            );
        }

        return $groups;
    }

    /**
     * The selection the source document shows, read from each choice's own
     * ARIA state. A choice that states none stays unknown (`null`).
     *
     * @param array<int, array<string, mixed>> $choices
     * @return array{selected_index: ?int, selected: array<int, ?bool>}
     */
    private function sourceSelection(DOMElement $group, array $choices): array
    {
        $selected = array();
        foreach ( $choices as $position => $choice ) {
            // The capture binds the nth choice to the nth control of its tag and role.
            $nodes = array();
            foreach ( '' === ($choice['tag'] ?? '') ? array() : $group->getElementsByTagName((string) $choice['tag']) as $node ) {
                if ( $node instanceof DOMElement && strtolower($node->getAttribute('role')) === strtolower((string) ($choice['role'] ?? '')) ) {
                    $nodes[] = $node;
                }
            }
            $node = $nodes[$choice['index']] ?? null;
            $selected[$position] = null;
            foreach ( array('aria-pressed', 'aria-checked', 'aria-selected') as $attribute ) {
                if ( $node instanceof DOMElement && $node->hasAttribute($attribute) ) {
                    $selected[$position] = 'true' === strtolower(trim($node->getAttribute($attribute)));
                    break;
                }
            }
        }
        $chosen = array_keys($selected, true, true);

        return array(
            'selected_index' => 1 === count($chosen) ? $chosen[0] : null,
            'selected' => $selected,
        );
    }
}
