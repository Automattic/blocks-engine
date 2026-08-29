<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Closure;
use DOMElement;

/** Identifies bounded non-form containers that behave as forms. */
final class PseudoFormAnalyzer
{
    /** @param Closure(DOMElement): string $elementSelector */
    public function __construct(
        private readonly FormControlMetadataBuilder $formControlMetadataBuilder,
        private readonly Closure $elementSelector
    ) {
    }

    public function isPseudoForm(DOMElement $element): bool
    {
        if ( 'form' === strtolower($element->tagName) ) {
            return false;
        }

        // A real form owns its entire subtree and must emit the finding once.
        if ( FormControlClassifier::hasFormAncestor($element) || 0 < $element->getElementsByTagName('form')->length ) {
            return false;
        }

        if ( $this->containsUnrelatedLandmark($element) || ! $this->pairsDataEntryWithSubmit($element) ) {
            return false;
        }

        // Select the tightest coherent container rather than a surrounding shell.
        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement
                && ! FormControlClassifier::isControlElement($descendant)
                && $this->pairsDataEntryWithSubmit($descendant) ) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function boundaryMetadata(DOMElement $element): array
    {
        $rejectedAncestors = array();
        for ( $ancestor = $element->parentNode; $ancestor instanceof DOMElement && count($rejectedAncestors) < 4; $ancestor = $ancestor->parentNode ) {
            if ( ! $this->containsUnrelatedLandmark($ancestor) && ! $this->pairsDataEntryWithSubmit($ancestor) ) {
                continue;
            }
            $rejectedAncestors[] = array(
                'selector' => ($this->elementSelector)($ancestor),
                'reason'   => $this->containsUnrelatedLandmark($ancestor) ? 'contains_unrelated_landmark' : 'contains_nested_coherent_form',
            );
        }

        return array(
            'schema'             => 'generic/form-boundary/v1',
            'selector'           => ($this->elementSelector)($element),
            'selection_basis'    => array( 'local_controls', 'associated_label', 'submit_semantics' ),
            'rejected_ancestors' => $rejectedAncestors,
        );
    }

    public function hasStandaloneSearchSignal(DOMElement $element, DOMElement $input): bool
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

    private function pairsDataEntryWithSubmit(DOMElement $element): bool
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

    private function containsUnrelatedLandmark(DOMElement $element): bool
    {
        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if ( ! $descendant instanceof DOMElement ) {
                continue;
            }

            $tagName = strtolower($descendant->tagName);
            $role = strtolower($this->attr($descendant, 'role'));
            if ( in_array($tagName, array( 'article', 'nav', 'header', 'footer', 'main' ), true)
                || in_array($role, array( 'article', 'navigation', 'banner', 'contentinfo', 'main' ), true) ) {
                return true;
            }
        }

        return false;
    }

    private function attr(DOMElement $element, string $name): string
    {
        return $element->hasAttribute($name) ? $element->getAttribute($name) : '';
    }
}
