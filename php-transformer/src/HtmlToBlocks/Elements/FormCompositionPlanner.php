<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\TransformationProvenanceState;
use Closure;
use DOMElement;

/** Preserves one controls-only subtree as a provider binding slot. */
final class FormCompositionPlanner
{
    /**
     * @param Closure(): TransformationProvenanceState                                                     $transformationProvenance
     * @param Closure(DOMElement, array<int, array<string, mixed>>&, bool): array<int, array<string, mixed>> $convertChildren
     * @param Closure(DOMElement): array<string, mixed>                                                     $presentationAttributes
     * @param Closure(string, array<string, mixed>, array<int, array<string, mixed>>, ?DOMElement): array<string, mixed> $createBlock
     * @param Closure(DOMElement, DOMElement): bool                                                         $elementContains
     */
    public function __construct(
        private readonly Closure $transformationProvenance,
        private readonly Closure $convertChildren,
        private readonly Closure $presentationAttributes,
        private readonly Closure $createBlock,
        private readonly Closure $elementContains
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
        $provenance = ($this->transformationProvenance)();
        $token = $provenance->reserveFormControlSlot($path);
        try {
            $children = ($this->convertChildren)($form, $fallbacks, true);
        } finally {
            $provenance->releaseFormControlSlot($path);
        }
        $slotBlock = $this->blockForBindingToken($children, $token);
        if ( array() === $children || null === $slotBlock ) {
            return null;
        }

        return array(
            'block' => ($this->createBlock)('core/group', ($this->presentationAttributes)($form), $children, $form),
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
            if ( array_filter($controls, fn (DOMElement $control): bool => ! ($this->elementContains)($candidate, $control)) ) {
                continue;
            }
            foreach ( $candidate->childNodes as $child ) {
                if ( XML_TEXT_NODE === $child->nodeType && '' !== trim($child->textContent ?? '') ) {
                    continue 2;
                }
                if ( ! $child instanceof DOMElement ) {
                    continue;
                }
                if ( ! array_filter($controls, fn (DOMElement $control): bool => ($this->elementContains)($child, $control)) ) {
                    continue 2;
                }
            }
            $slot = $candidate;
        }

        return $slot;
    }
}
