<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Closure;
use DOMElement;

/** Determines whether a form depends on behavior that native blocks cannot retain. */
final class FormRuntimeRequirementAnalyzer
{
    /**
     * @param Closure(DOMElement): array<string, mixed> $eventMetadata
     * @param Closure(DOMElement): bool                 $isRuntimeDomTarget
     */
    public function __construct(
        private readonly Closure $eventMetadata,
        private readonly Closure $isRuntimeDomTarget
    ) {
    }

    public function requiresPreservation(DOMElement $form): bool
    {
        return 0 < $form->getElementsByTagName('script')->length
            || array() !== ($this->eventMetadata)($form)
            || $this->hasSubmissionMetadata($form)
            || $this->hasCommerceSubmissionSignal($form)
            || $this->hasRuntimeDomTargets($form);
    }

    private function hasSubmissionMetadata(DOMElement $form): bool
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

    private function hasCommerceSubmissionSignal(DOMElement $form): bool
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

    private function hasRuntimeDomTargets(DOMElement $form): bool
    {
        if ( ($this->isRuntimeDomTarget)($form) || $this->hasRuntimeClassSignal($form) ) {
            return true;
        }

        foreach ( FormControlClassifier::controlElements($form) as $control ) {
            if ( ($this->isRuntimeDomTarget)($control) || $this->hasRuntimeClassSignal($control) ) {
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

    private function attr(DOMElement $element, string $name): string
    {
        return $element->hasAttribute($name) ? $element->getAttribute($name) : '';
    }
}
