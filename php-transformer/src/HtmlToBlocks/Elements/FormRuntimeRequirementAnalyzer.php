<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
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
        $action = trim(SourceDom::attr($form, 'action'));
        if ( '' !== $action && '#' !== $action ) {
            return true;
        }

        if ( '' === $action && '' !== trim(SourceDom::attr($form, 'method')) ) {
            return true;
        }

        foreach ( array( 'enctype', 'target' ) as $attribute ) {
            if ( '' !== trim(SourceDom::attr($form, $attribute)) ) {
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
                SourceDom::attr($control, 'value'),
                SourceDom::attr($control, 'class'),
                SourceDom::attr($control, 'id'),
                SourceDom::attr($control, 'name'),
                SourceDom::attr($control, 'aria-label'),
                SourceDom::attr($control, 'title'),
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
        foreach ( preg_split('/\s+/', trim(SourceDom::attr($element, 'class'))) ?: array() as $class ) {
            if ( preg_match('/^js-[A-Za-z0-9_-]+$/', $class) ) {
                return true;
            }
        }

        return false;
    }
}
