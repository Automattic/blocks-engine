<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\ElementPresentationResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternTreeConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use DOMElement;

/** Preserves one controls-only subtree as a provider binding slot. */
final class FormCompositionPlanner
{
    /**
     */
    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly PatternTreeConverter $tree,
        private readonly ElementPresentationResolver $presentation,
        private readonly SourceBlockCreator $createBlock
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array{block: array<string, mixed>, slot: array<string, mixed>}|null
     */
    public function compose(DOMElement $form, array &$fallbacks): ?array
    {
        $slot = $this->controlSlotElement($form);
        if ( null === $slot ) {
            return null;
        }

        $path = $slot->getNodePath();
        $provenance = $this->session->transformationProvenanceState();
        $token = $provenance->reserveFormControlSlot($path);
        try {
            $children = $this->tree->children($form, $fallbacks, true);
        } finally {
            $provenance->releaseFormControlSlot($path);
        }
        $slotBlock = $this->blockForBindingToken($children, $token);
        if ( array() === $children || null === $slotBlock ) {
            return null;
        }

        return array(
            'block' => $this->createBlock->createBlock('core/group', $this->presentation->presentationAttributes($form), $children, $form),
            'slot'  => $slotBlock,
        );
    }

    /** @param array<int, array<string, mixed>> $blocks @return array<string, mixed>|null */
    private function blockForBindingToken(array $blocks, string $token): ?array
    {
        foreach ( $blocks as $block ) {
            if ( ! is_array($block) ) {
                continue;
            }
            if ( $token === ($block['_binding_token'] ?? null) ) {
                return $block;
            }
            $nested = $this->blockForBindingToken(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array(), $token);
            if ( null !== $nested ) {
                return $nested;
            }
        }

        return null;
    }

    private function controlSlotElement(DOMElement $form): ?DOMElement
    {
        foreach ( $form->getElementsByTagName('*') as $element ) {
            if ( $element instanceof DOMElement && 'true' === strtolower($element->getAttribute('data-blocks-engine-choice-group')) ) {
                return $element;
            }
        }
        $controls = array_values(array_filter(
            FormControlClassifier::controlElements($form),
            static fn (DOMElement $control): bool => 'hidden' !== FormControlClassifier::controlType($control)
        ));
        if ( array() === $controls ) {
            return null;
        }

        $formPath = $form->getNodePath();
        $slot = null;
        for ( $candidate = $controls[0]->parentNode; $candidate instanceof DOMElement && $candidate->getNodePath() !== $formPath; $candidate = $candidate->parentNode ) {
            if ( array_filter($controls, fn (DOMElement $control): bool => ! $this->elementContains($candidate, $control)) ) {
                continue;
            }
            foreach ( $candidate->childNodes as $child ) {
                if ( XML_TEXT_NODE === $child->nodeType && '' !== trim($child->textContent ?? '') ) {
                    continue 2;
                }
                if ( ! $child instanceof DOMElement ) {
                    continue;
                }
                if ( ! array_filter($controls, fn (DOMElement $control): bool => $this->elementContains($child, $control)) ) {
                    continue 2;
                }
            }
            $slot = $candidate;
        }

        return $slot;
    }

    /** Node paths compare across the source and projected-clone documents. */
    private function elementContains(DOMElement $ancestor, DOMElement $element): bool
    {
        $ancestorPath = $ancestor->getNodePath();
        for ( $node = $element; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( $node->getNodePath() === $ancestorPath ) {
                return true;
            }
        }

        return false;
    }
}
