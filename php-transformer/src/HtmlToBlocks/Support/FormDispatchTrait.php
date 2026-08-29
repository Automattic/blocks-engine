<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredInputBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredSelectBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\FallbackDiagnostic;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormControlTopologyBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder;
use DOMDocument;
use DOMElement;
use DOMNode;

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

        $readableFormBlock = $this->readableFormBlockFromForm($element);
        if ( null !== $readableFormBlock && ! $this->formRequiresRuntimePreservation($element) ) {
            if ( FormControlClassifier::hasDataEntryControls($element) ) {
                $fallbacks[] = $this->formFallbackFinding($element, $readableFormBlock);
            }

            return $readableFormBlock;
        }

        if ( FormControlClassifier::hasDataEntryControls($element) ) {
            $composition = $this->compositionalFormBlock($element, $fallbacks);
            if ( null !== $composition ) {
                $fallbacks[] = $this->formFallbackFinding($element, $composition['block'], $composition['slot']);
                $this->recordFormRuntimeIsland($element, $composition['block']);
                return $composition['block'];
            }
            $preservationBlock = $this->htmlPreservationBlock($element);
            $fallbacks[] = $this->formFallbackFinding($element, $readableFormBlock, $preservationBlock);
            $this->recordFormRuntimeIsland($element, $readableFormBlock);

            return $preservationBlock;
        }

        $readableFormBlock = $this->readableFormBlockFromForm($element, true);
        $this->recordFormRuntimeIsland($element, $readableFormBlock);

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
        if ( $this->isDivBasedPseudoForm($element) ) {
            $fallbacks[] = $this->formFallbackFinding($element, $this->readableFormBlockFromForm($element, true));
        }
    }

    /**
     * @param array<string, mixed>|null $readableFormBlock
     */
    private function recordFormRuntimeIsland(DOMElement $element, ?array $readableFormBlock): void
    {
        $controls = $this->formControlMetadataBuilder->controls($element);
        $this->runtimeIslands->recordRuntimeIsland($element, 'form', 'form_requires_runtime', 'server_or_client_form_handler', array(
            'form'             => $this->formControlMetadataBuilder->form($element),
            'controls'         => $controls,
            'control_count'    => count($controls),
            'events'           => $this->eventMetadata($element),
            'readable_blocks'  => null !== $readableFormBlock ? array( $readableFormBlock ) : array(),
            'required_scripts' => $this->requiredScriptsForElement($element),
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readableFormBlockFromForm(DOMElement $form, bool $allowFormEvents = false): ?array
    {
        if ( 0 < $form->getElementsByTagName('script')->length || ( ! $allowFormEvents && array() !== $this->eventMetadata($form) ) ) {
            return null;
        }

        $contentBlocks = array();
        $buttonBlocks = array();
        foreach ( FormControlClassifier::controlElements($form) as $control ) {
            if ( array() !== $this->eventMetadata($control) || ! FormControlClassifier::isReadableControl($control) ) {
                return null;
            }

            if ( FormControlClassifier::isSubmitLikeControl($control) ) {
                $buttonBlocks[] = $this->createBlock('core/button', array_merge($this->styleResolver->presentationAttributes($control), array(
                    'text' => $this->runtime->escapeHtml($this->formControlMetadataBuilder->submitText($control, 'Submit')),
                )), array(), $control);
                continue;
            }

            if ( $this->runtimeIslands->isRuntimeDomTarget($control) ) {
                $this->runtimeIslands->recordRuntimeIsland($control, 'control', 'runtime_dom_target', 'client_script_execution', array(
                    'control'          => $this->formControlMetadataBuilder->control($control),
                    'events'           => $this->eventMetadata($control),
                    'required_scripts' => $this->requiredScriptsForElement($control),
                ));
            }

            $readableControlBlock = $this->readableFormControlBlockFromElement($control);
            if ( null === $readableControlBlock ) {
                continue;
            }

            $fieldBlocks = array();
            $associatedLabel = $this->formControlMetadataBuilder->associatedLabel($control);
            if ( $associatedLabel instanceof DOMElement && AuthoredInputBlockGenerator::NAME === ($readableControlBlock['blockName'] ?? '') ) {
                $labelBlock = $this->readableFormControlBlockFromElement($associatedLabel);
                if ( null !== $labelBlock ) {
                    $fieldBlocks[] = $labelBlock;
                }
            }
            $fieldBlocks[] = $readableControlBlock;
            $contentBlocks[] = ( 1 === count($fieldBlocks) && AuthoredInputBlockGenerator::NAME !== ($readableControlBlock['blockName'] ?? '') )
                ? $fieldBlocks[0]
                : $this->createBlock('core/group', array(), $fieldBlocks, $control);
        }

        if ( array() !== $buttonBlocks ) {
            $contentBlocks[] = $this->createBlock('core/buttons', array(), $buttonBlocks, $form);
        }

        if ( array() === $contentBlocks ) {
            return null;
        }

        return $this->createBlock('core/group', $this->styleResolver->presentationAttributes($form), $contentBlocks, $form);
    }

    /**
     * Preserve one unambiguous controls-only subtree as the provider binding
     * slot while converting the form's surrounding visual content normally.
     *
     * @param array<int,array<string,mixed>> $fallbacks
     * @return array{block:array<string,mixed>,slot:array<string,mixed>}|null
     */
    private function compositionalFormBlock(DOMElement $form, array &$fallbacks): ?array
    {
        $slot = $this->formControlSlotElement($form);
        if ( null === $slot ) return null;

        $path = $slot->getNodePath();
        $token = $this->transformationProvenance()->reserveFormControlSlot($path);
        try {
            $children = $this->convertChildren($form, $fallbacks, true);
        } finally {
            $this->transformationProvenance()->releaseFormControlSlot($path);
        }
        $slotBlock = $this->blockForBindingToken($children, $token);
        if ( array() === $children || null === $slotBlock ) return null;

        return array(
            'block' => $this->createBlock('core/group', $this->styleResolver->presentationAttributes($form), $children, $form),
            'slot'  => $slotBlock,
        );
    }

    /** @param array<int,array<string,mixed>> $blocks @return array<string,mixed>|null */
    private function blockForBindingToken(array $blocks, string $token): ?array
    {
        foreach ($blocks as $block) {
            if (!is_array($block)) continue;
            if ($token === ($block['_binding_token'] ?? null)) return $block;
            $nested = $this->blockForBindingToken(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array(), $token);
            if (null !== $nested) return $nested;
        }
        return null;
    }

    private function formControlSlotElement(DOMElement $form): ?DOMElement
    {
        $controls = FormControlClassifier::controlElements($form);
        if ( array() === $controls ) return null;

        $formPath = $form->getNodePath();
        for ( $candidate = $controls[0]->parentNode; $candidate instanceof DOMElement && $candidate->getNodePath() !== $formPath; $candidate = $candidate->parentNode ) {
            if ( array_filter($controls, fn(DOMElement $control): bool => !$this->elementContains($candidate, $control)) ) continue;
            foreach ( $candidate->childNodes as $child ) {
                if ( XML_TEXT_NODE === $child->nodeType && '' !== trim($child->textContent ?? '') ) continue 2;
                if ( !$child instanceof DOMElement ) continue;
                if ( !array_filter($controls, fn(DOMElement $control): bool => $this->elementContains($child, $control)) ) continue 2;
            }
            return $candidate;
        }
        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readableFormControlBlockFromElement(DOMElement $element): ?array
    {
        $tagName = strtolower($element->tagName);
        if ( 'label' === $tagName ) {
            $controls = FormControlClassifier::controlElements($element);
            if ( array() !== $controls ) {
                $blocks = array();
                foreach ( $controls as $control ) {
                    if ( ! FormControlClassifier::isReadableControl($control) || array() !== $this->eventMetadata($control) ) {
                        return null;
                    }

                    if ( $this->runtimeIslands->isRuntimeDomTarget($control) ) {
                        $this->recordRuntimeControlIsland($control);
                        return $this->htmlPreservationBlock($element);
                    }

                    $summary = $this->readableFormControlText($control);
                    if ( '' !== $summary ) {
                        $blocks[] = $this->createBlock('core/paragraph', array( 'content' => $summary ), array(), $control);
                    }
                }

                if ( 1 === count($blocks) ) {
                    return $blocks[0];
                }

                return array() !== $blocks ? $this->createBlock('core/group', $this->styleResolver->presentationAttributes($element), $blocks, $element) : null;
            }

            $label = $this->formControlMetadataBuilder->labelText($element);
            if ( '' === $label ) {
                $label = trim(preg_replace('/\s+/', ' ', $element->textContent ?? '') ?? '');
            }

            return '' !== $label ? $this->createBlock('core/paragraph', array( 'content' => $this->runtime->escapeHtml($label) ), array(), $element) : null;
        }

        if ( ! FormControlClassifier::isControlElement($element) || ! FormControlClassifier::isReadableControl($element) || array() !== $this->eventMetadata($element) ) {
            return null;
        }

        if ( 'input' === $tagName && 'search' === FormControlClassifier::controlType($element) ) {
            $label = $this->formControlMetadataBuilder->label($element);
            if ( '' === $label ) {
                $label = $this->attr($element, 'aria-label');
            }
            if ( '' === $label ) {
                $label = 'Search';
            }

            return $this->htmlPreservationBlock($element);
        }

        if ( $this->runtimeIslands->isRuntimeDomTarget($element) ) {
            $this->recordRuntimeControlIsland($element);
            return $this->htmlPreservationBlock($element);
        }

        if ( 'select' === $tagName ) {
            $selectBlock = $this->readableSelectBlockFromElement($element);
            if ( null !== $selectBlock ) {
                return $selectBlock;
            }
        }

        if ( 'input' === $tagName ) {
            $inputBlock = $this->readableInputBlockFromElement($element);
            if ( null !== $inputBlock ) {
                return $inputBlock;
            }
        }

        $summary = $this->readableFormControlText($element);
        if ( '' === $summary ) {
            return null;
        }

        return $this->createBlock('core/paragraph', array_merge($this->styleResolver->presentationAttributes($element), array( 'content' => $summary )), array(), $element);
    }

    private function recordRuntimeControlIsland(DOMElement $element): void
    {
        $this->runtimeIslands->recordRuntimeIsland($element, 'control', 'runtime_dom_target', 'client_script_execution', array(
            'control'          => $this->formControlMetadataBuilder->control($element),
            'events'           => $this->eventMetadata($element),
            'required_scripts' => $this->requiredScriptsForElement($element),
        ));
    }

    /**
     * Preserve a standalone form control that has no faithful native block or
     * readable static approximation as a bounded runtime island instead of an
     * unsupported-element loss.
     *
     * Reached only after the readable-control and search paths decline, so the
     * control is one whose behavior depends on a client runtime: file/hidden/
     * color/date-style inputs core blocks cannot represent, or any control
     * carrying inline event handlers. The source markup is carried in the
     * island snippet so the behavior can be re-attached, and no misleading
     * static text is emitted for controls (often hidden) that have no visual
     * representation. This yields a `preserved_runtime_island` outcome rather
     * than an `unsupported_element_loss`.
     */
    private function preserveStandaloneFormControlAsRuntimeIsland(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( ! in_array($tagName, array( 'input', 'select', 'textarea' ), true) ) {
            return false;
        }

        $this->runtimeIslands->recordRuntimeIsland($element, 'control', 'form_control_requires_runtime', 'client_form_control_runtime', array(
            'control'          => $this->formControlMetadataBuilder->control($element),
            'events'           => $this->eventMetadata($element),
            'required_scripts' => $this->requiredScriptsForElement($element),
        ));

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readableSelectBlockFromElement(DOMElement $select): ?array
    {
        $label = $this->formControlMetadataBuilder->readableLabel($select);
        $this->registerFormControlEcho($label);
        $options = $this->formControlMetadataBuilder->options($select);
        if ( array() === $options ) {
            return null;
        }
        // Form controls are below the general high-value style boundary, so use
        // the selector-resolved author cascade directly as the representation
        // gate. Class/id presence alone is never sufficient.
        if ( array() === $this->styleResolver->structuralPresentationDeclarations($select) ) {
            $optionBlocks = array();
            foreach ( $options as $option ) {
                $optionLabel = trim((string) ($option['label'] ?? ''));
                if ( '' === $optionLabel ) {
                    continue;
                }
                if ( true === ($option['selected'] ?? false) ) {
                    $optionLabel .= ' (selected)';
                }
                $this->registerFormControlEcho($optionLabel);
                $optionBlocks[] = $this->createBlock('core/list-item', array( 'content' => $this->runtime->escapeHtml($optionLabel) ));
            }

            return $this->createBlock('core/group', $this->styleResolver->presentationAttributes($select), array(
                $this->createBlock('core/paragraph', array( 'content' => $this->runtime->escapeHtml($label) ), array(), $select),
                $this->createBlock('core/list', array(), $optionBlocks, $select),
            ), $select);
        }
        $this->generatedBlocks()->register(AuthoredSelectBlockGenerator::class, ( new AuthoredSelectBlockGenerator() )->definition());
        $attrs = array_filter(array(
            'id' => $this->attr($select, 'id'),
            'name' => $this->attr($select, 'name'),
            'ariaLabel' => $this->attr($select, 'aria-label'),
            'placeholder' => $this->attr($select, 'placeholder'),
            'className' => $this->attr($select, 'class'),
            'style' => $this->attr($select, 'style'),
            'options' => $options,
            'selectedSummary' => $this->selectedOptionSummary($options),
        ), static fn (mixed $value): bool => is_array($value) ? array() !== $value : '' !== $value);
        $markup = ( new AuthoredSelectBlockGenerator() )->markup($attrs);
        $controlBlock = array(
            'blockName' => AuthoredSelectBlockGenerator::NAME,
            'attrs' => $attrs,
            'innerBlocks' => array(),
            'innerHTML' => $markup,
            'innerContent' => array( $markup ),
        );

        // Keep the long-standing group/anchor contract for callers that address
        // the converted field structurally. Source identity lives on the native
        // control, so authored select selectors never style this transparent shell.
        return $this->createBlock('core/group', array_filter(array(
            'anchor' => $this->safeAnchor($this->attr($select, 'id')),
            'className' => 'blocks-engine-authored-select-wrapper',
        )), array( $controlBlock ));
    }

    /**
     * Return a compact native input only when authored presentation is proven by
     * the resolved CSS cascade. The direct save shape preserves flex-child and
     * selector semantics that a readable paragraph cannot represent.
     *
     * @return array<string, mixed>|null
     */
    private function readableInputBlockFromElement(DOMElement $input): ?array
    {
        if ( array() === $this->styleResolver->structuralPresentationDeclarations($input) ) {
            return null;
        }
        $this->generatedBlocks()->register(AuthoredInputBlockGenerator::class, ( new AuthoredInputBlockGenerator() )->definition());
        $attrs = array_filter(array(
            'type' => FormControlClassifier::controlType($input),
            'id' => $this->attr($input, 'id'),
            'name' => $this->attr($input, 'name'),
            'value' => $this->attr($input, 'value'),
            'placeholder' => $this->attr($input, 'placeholder'),
            'ariaLabel' => $this->attr($input, 'aria-label'),
            'className' => $this->attr($input, 'class'),
            'style' => $this->attr($input, 'style'),
            'min' => $this->attr($input, 'min'),
            'max' => $this->attr($input, 'max'),
            'step' => $this->attr($input, 'step'),
            'required' => $input->hasAttribute('required'),
            'disabled' => $input->hasAttribute('disabled'),
            'readOnly' => $input->hasAttribute('readonly'),
            'checked' => $input->hasAttribute('checked'),
        ), static fn (mixed $value): bool => is_bool($value) ? $value : '' !== $value);
        $markup = ( new AuthoredInputBlockGenerator() )->markup($attrs);

        return array(
            'blockName' => AuthoredInputBlockGenerator::NAME,
            'attrs' => $attrs,
            'innerBlocks' => array(),
            'innerHTML' => $markup,
            'innerContent' => array( $markup ),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $options
     */
    private function selectedOptionSummary(array $options): string
    {
        $selected = array();
        foreach ( $options as $option ) {
            if ( ! empty($option['selected']) && '' !== trim((string) ($option['label'] ?? '')) ) {
                $selected[] = (string) $option['label'];
            }
        }

        return array() === $selected ? '' : implode(', ', $selected) . ' (selected)';
    }

    private function formRequiresRuntimePreservation(DOMElement $form): bool
    {
        return 0 < $form->getElementsByTagName('script')->length
            || array() !== $this->eventMetadata($form)
            || $this->formHasRuntimeSubmissionMetadata($form)
            || $this->formHasCommerceSubmissionSignal($form)
            || $this->formHasRuntimeDomTargets($form);
    }

    private function formHasRuntimeSubmissionMetadata(DOMElement $form): bool
    {
        $action = trim($this->attr($form, 'action'));
        if ( '' !== $action && '#' !== $action ) {
            return true;
        }

        if ( '' === $action && '' !== trim($this->attr($form, 'method')) ) {
            return true;
        }

        foreach ( array( 'enctype', 'target' ) as $attribute ) {
            if ( '' !== trim($this->attr($form, $attribute)) ) {
                return true;
            }
        }

        return false;
    }

    private function formHasCommerceSubmissionSignal(DOMElement $form): bool
    {
        foreach ( FormControlClassifier::controlElements($form) as $control ) {
            if ( ! FormControlClassifier::isSubmitLikeControl($control) ) {
                continue;
            }

            $haystack = strtolower(implode(' ', array(
                $control->textContent ?? '',
                $this->attr($control, 'value'),
                $this->attr($control, 'class'),
                $this->attr($control, 'id'),
                $this->attr($control, 'name'),
                $this->attr($control, 'aria-label'),
                $this->attr($control, 'title'),
            )));

            if ( preg_match('/(?:^|[^a-z0-9])(?:add to cart|cart|checkout|payment|purchase|buy|order|register|registration|ticket)(?:[^a-z0-9]|$)/', $haystack) ) {
                return true;
            }
        }

        return false;
    }

    private function formHasRuntimeDomTargets(DOMElement $form): bool
    {
        if ( $this->runtimeIslands->isRuntimeDomTarget($form) || $this->hasRuntimeClassSignal($form) ) {
            return true;
        }

        foreach ( FormControlClassifier::controlElements($form) as $control ) {
            if ( $this->runtimeIslands->isRuntimeDomTarget($control) || $this->hasRuntimeClassSignal($control) ) {
                return true;
            }
        }

        return false;
    }

    private function hasRuntimeClassSignal(DOMElement $element): bool
    {
        foreach ( preg_split('/\s+/', trim($this->attr($element, 'class'))) ?: array() as $class ) {
            if ( preg_match('/^js-[A-Za-z0-9_-]+$/', $class) ) {
                return true;
            }
        }

        return false;
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
            'success_panel'   => $this->formSuccessPanelMetadata($element),
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
            $finding['form_boundary'] = $this->pseudoFormBoundaryMetadata($element);
        }

        return FallbackDiagnostic::build($finding, $this->transformationProvenance()->fallback());
    }

    /**
     * @return array<string, mixed>
     */
    private function formSuccessPanelMetadata(DOMElement $form): array
    {
        if ( 'form' !== strtolower($form->tagName) ) {
            foreach ( $this->descendantElements($form) as $descendant ) {
                if ( $this->hasSuccessPanelSignal($descendant) ) {
                    return $this->successPanelMetadata($descendant);
                }
            }

            return array();
        }

        for ( $sibling = $form->nextSibling; $sibling instanceof DOMNode; $sibling = $sibling->nextSibling ) {
            if ( XML_TEXT_NODE === $sibling->nodeType && '' === trim($sibling->textContent ?? '') ) {
                continue;
            }

            if ( ! $sibling instanceof DOMElement ) {
                return array();
            }

            if ( ! $this->hasSuccessPanelSignal($sibling) ) {
                return array();
            }

            return $this->successPanelMetadata($sibling);
        }

        return array();
    }

    /** @return array<string, mixed> */
    private function successPanelMetadata(DOMElement $element): array
    {
        $boundedHtml = $this->boundedFallbackHtml($this->safeFallbackHtml($element));
        return array_filter(array(
            'selector'       => $this->elementSelector($element),
            'id'             => $this->attr($element, 'id'),
            'class'          => $this->attr($element, 'class'),
            'role'           => $this->attr($element, 'role'),
            'aria_live'      => $this->attr($element, 'aria-live'),
            'text'           => $this->normalizedSuccessPanelText($element),
            'html'           => $boundedHtml['html'],
            'html_bytes'     => $boundedHtml['bytes'],
            'html_truncated' => $boundedHtml['truncated'],
        ), static fn (mixed $value): bool => is_bool($value) || is_int($value) || '' !== trim((string) $value));
    }

    private function normalizedSuccessPanelText(DOMElement $element): string
    {
        $html = preg_replace('/<\/?[a-z][a-z0-9]*\b[^>]*>/i', ' ', $this->innerHtml($element)) ?? $element->textContent ?? '';
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    private function hasSuccessPanelSignal(DOMElement $element): bool
    {
        $role = strtolower($this->attr($element, 'role'));
        if ( in_array($role, array( 'status', 'alert' ), true) ) {
            return true;
        }

        $tokens = strtolower(trim($this->attr($element, 'id') . ' ' . $this->attr($element, 'class') . ' ' . $this->attr($element, 'aria-live')));
        return (bool) preg_match('/(?:^|[^a-z0-9])(?:success|sent|submitted|thank|thanks|confirmation|confirmed)(?:[^a-z0-9]|$)/', $tokens);
    }

    /**
     * Whether a non-<form> container behaves as a form: it is the tightest
     * container that pairs at least one data-entry control with a submit-like
     * control, and no real <form> owns the subtree.
     *
     * Structural only — the signal is "data-entry control + submit-like control in
     * one bounded container", never a fixture id/class/name. Conservative: a lone
     * search box or a stray input with no submit control never qualifies, and a
     * subtree owned by a real <form> (as ancestor or descendant) is left to the
     * <form> path so the finding is emitted exactly once.
     */
    private function isDivBasedPseudoForm(DOMElement $element): bool
    {
        if ( 'form' === strtolower($element->tagName) ) {
            return false;
        }

        // A real <form> ancestor or descendant owns the controls; let the <form>
        // path emit the finding so it is never double-counted.
        if ( FormControlClassifier::hasFormAncestor($element) ) {
            return false;
        }
        if ( 0 < $element->getElementsByTagName('form')->length ) {
            return false;
        }

        // A pseudo-form must be a local interaction region, never the page shell
        // that happens to contain navigation or editorial content plus controls.
        if ( $this->pseudoFormContainsUnrelatedLandmark($element) ) {
            return false;
        }

        if ( ! $this->containerPairsDataEntryWithSubmit($element) ) {
            return false;
        }

        // Bound the container to the tightest one: if a descendant container also
        // pairs the controls, defer to it so a wrapper does not swallow a nested
        // pseudo-form (and sibling pseudo-forms each emit their own finding).
        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement
                && ! FormControlClassifier::isControlElement($descendant)
                && $this->containerPairsDataEntryWithSubmit($descendant) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a container holds a local, labeled data-entry control and a submit
     * action. Unlike a real form, a div gives a plain button no submit ownership,
     * so its action must be explicit in type or semantics.
     */
    private function containerPairsDataEntryWithSubmit(DOMElement $element): bool
    {
        $hasDataEntry = false;
        $hasFieldLabel = false;
        $hasSubmit = false;
        $hasActionControl = false;
        $hasContainerAction = '' !== trim($this->attr($element, 'action')) || '' !== trim($this->attr($element, 'method')) || '' !== trim($this->attr($element, 'data-action'));

        foreach ( FormControlClassifier::controlElements($element) as $control ) {
            if ( FormControlClassifier::isPseudoFormDataEntryControl($control) && ! $this->hasStandaloneSearchSignal($element, $control) ) {
                $hasDataEntry = true;
                $hasFieldLabel = $hasFieldLabel || '' !== trim($this->formControlMetadataBuilder->label($control)) || '' !== trim($this->attr($control, 'aria-label')) || '' !== trim($this->attr($control, 'name'));
            } elseif ( 'button' === strtolower($control->tagName) || ( 'input' === strtolower($control->tagName) && ! in_array(FormControlClassifier::controlType($control), array( 'reset', 'button' ), true) ) ) {
                $hasActionControl = true;
                $hasSubmit = $hasSubmit || FormControlClassifier::isPseudoFormSubmitControl($control);
            }

            if ( $hasDataEntry && $hasFieldLabel && ( $hasSubmit || ( $hasContainerAction && $hasActionControl ) ) ) {
                return true;
            }
        }

        return false;
    }

    private function pseudoFormContainsUnrelatedLandmark(DOMElement $element): bool
    {
        foreach ( $this->descendantElements($element) as $descendant ) {
            $tagName = strtolower($descendant->tagName);
            $role = strtolower($this->attr($descendant, 'role'));
            if ( in_array($tagName, array( 'article', 'nav', 'header', 'footer', 'main' ), true)
                || in_array($role, array( 'article', 'navigation', 'banner', 'contentinfo', 'main' ), true) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function pseudoFormBoundaryMetadata(DOMElement $element): array
    {
        $rejectedAncestors = array();
        for ( $ancestor = $element->parentNode; $ancestor instanceof DOMElement && count($rejectedAncestors) < 4; $ancestor = $ancestor->parentNode ) {
            if ( ! $this->pseudoFormContainsUnrelatedLandmark($ancestor) && ! $this->containerPairsDataEntryWithSubmit($ancestor) ) {
                continue;
            }
            $rejectedAncestors[] = array(
                'selector' => $this->elementSelector($ancestor),
                'reason'   => $this->pseudoFormContainsUnrelatedLandmark($ancestor) ? 'contains_unrelated_landmark' : 'contains_nested_coherent_form',
            );
        }

        return array(
            'schema' => 'generic/form-boundary/v1',
            'selector' => $this->elementSelector($element),
            'selection_basis' => array( 'local_controls', 'associated_label', 'submit_semantics' ),
            'rejected_ancestors' => $rejectedAncestors,
        );
    }

    private function readableFormControlText(DOMElement $control): string
    {
        $label = $this->formControlMetadataBuilder->readableLabel($control);

        $type = FormControlClassifier::controlType($control);
        if ( '' === $label ) {
            $label = 'select' === $type ? 'Select option' : ucfirst($type);
        }

        $details = array();
        if ( 'select' === strtolower($control->tagName) ) {
            $options = array();
            $selected = array();
            foreach ( $this->formControlMetadataBuilder->options($control) as $option ) {
                $optionLabel = (string) ($option['label'] ?? '');
                if ( '' === $optionLabel ) {
                    continue;
                }
                $options[] = $optionLabel;
                if ( true === ($option['selected'] ?? false) ) {
                    $selected[] = $optionLabel;
                }
            }
            if ( array() !== $options ) {
                $details[] = implode(', ', $options);
            }
            if ( array() !== $selected ) {
                $details[] = 'selected: ' . implode(', ', $selected);
            }
        } elseif ( 'range' === $type ) {
            $value = trim($this->attr($control, 'value'));
            if ( '' !== $value ) {
                $details[] = $value;
            }

            $bounds = array();
            foreach ( array( 'min', 'max', 'step' ) as $attribute ) {
                $value = trim($this->attr($control, $attribute));
                if ( '' !== $value ) {
                    $bounds[] = $attribute . ' ' . $value;
                }
            }
            if ( array() !== $bounds ) {
                $details[] = implode(', ', $bounds);
            }
        } else {
            foreach ( array( 'value', 'placeholder' ) as $attribute ) {
                $value = trim($this->attr($control, $attribute));
                if ( '' !== $value ) {
                    $details[] = $value;
                    break;
                }
            }
        }

        $text = $label;
        if ( array() !== $details ) {
            $text .= ': ' . implode(' (', $details) . ( count($details) > 1 ? ')' : '' );
        }
        if ( $control->hasAttribute('required') ) {
            $text .= ' (required)';
        }

        $this->registerFormControlEcho($text);

        return $this->runtime->escapeHtml($text);
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

    private function hasStandaloneSearchSignal(DOMElement $element, DOMElement $input): bool
    {
        if ( 'search' === FormControlClassifier::controlType($input) || 'search' === strtolower(trim($this->attr($element, 'role'))) ) {
            return true;
        }

        $haystack = strtolower(implode(' ', array(
            $this->attr($element, 'aria-label'),
            $this->attr($element, 'id'),
            $this->attr($element, 'class'),
            $this->attr($input, 'aria-label'),
            $this->attr($input, 'id'),
            $this->attr($input, 'class'),
            $this->attr($input, 'name'),
            $this->attr($input, 'placeholder'),
        )));

        return str_contains($haystack, 'search');
    }

}
