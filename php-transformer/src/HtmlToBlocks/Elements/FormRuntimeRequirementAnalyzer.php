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
            || self::hasCommerceSubmissionSignal($form)
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

    /** Classifies source semantics only; classes and vendor attributes are not intent. */
    public static function formIntent(DOMElement $form): string
    {
        $path = strtolower((string) parse_url(SourceDom::attr($form, 'action'), PHP_URL_PATH));
        $controls = FormControlClassifier::controlElements($form);
        // Hidden submission data is deliberately absent from the authored-control
        // inventory; inspect it separately without exposing it as an editable field.
        $hiddenItem = false;
        foreach ( $form->getElementsByTagName('input') as $input ) {
            if ( 'hidden' === strtolower(SourceDom::attr($input, 'type'))
                && in_array(strtolower(SourceDom::attr($input, 'name')), array( 'id', 'item_id', 'product_id', 'variant_id' ), true)
                && '' !== trim(SourceDom::attr($input, 'value')) ) {
                $hiddenItem = true;
            }
        }
        $quantity = false;
        $email = false;
        $message = false;
        $search = 'search' === strtolower(SourceDom::attr($form, 'role'));
        $select = false;
        $password = false;
        $subscribe = false;
        $freeText = false;
        $hasChoice = false;
        $onlyChoices = true;
        $hasSubmit = false;
        // Submission controls count even when they are not rendered/authored
        // fields in the provider inventory (for example a hidden submit button).
        foreach ( $form->getElementsByTagName('*') as $control ) {
            $tag = strtolower($control->tagName);
            $controlType = FormControlClassifier::controlType($control);
            if ( in_array($tag, array( 'button', 'input' ), true)
                && in_array($controlType, array( 'submit', 'image' ), true) ) {
                $hasSubmit = true;
            }
        }
        foreach ( $controls as $control ) {
            $type = strtolower(SourceDom::attr($control, 'type'));
            $name = strtolower(SourceDom::attr($control, 'name'));
            $tag = strtolower($control->tagName);
            $controlType = FormControlClassifier::controlType($control);
            if ( 'select' === $tag || ('input' === $tag && in_array($controlType, array( 'radio', 'checkbox' ), true)) ) {
                $hasChoice = true;
            } elseif ( ('button' === $tag && in_array(strtolower(SourceDom::attr($control, 'role')), array( 'radio', 'checkbox', 'switch' ), true))
                || ('button' !== $tag && ! in_array($controlType, array( 'submit', 'reset', 'button', 'image' ), true)) ) {
                // Custom stateful widgets are not plain button/reset controls;
                // retain their existing provider composition and presentation.
                $onlyChoices = false;
            }
            $quantity = $quantity || in_array($name, array( 'quantity', 'qty' ), true);
            $email = $email || 'email' === $type || 'email' === $name;
            $message = $message || 'textarea' === $tag;
            $search = $search || 'search' === $type || in_array($name, array( 'q', 's', 'search', 'query' ), true);
            $select = $select || 'select' === $tag;
            $password = $password || 'password' === $type;
            // Filters choose among values (select, checkbox, radio, range, number,
            // date); a form that asks for free text such as a name or phone
            // collects a message, never a catalog filter.
            $freeText = $freeText || 'textarea' === $tag || ('input' === $tag && in_array($type, array( '', 'text', 'tel', 'email', 'url' ), true) && ! in_array($name, array( 'q', 's', 'search', 'query' ), true));
            if ( FormControlClassifier::isSubmitLikeControl($control) ) {
                $subscribe = $subscribe || 1 === preg_match('/\b(?:subscribe|newsletter)\b/i', ($control->textContent ?? '') . ' ' . SourceDom::attr($control, 'value'));
            }
        }
        if ( preg_match('~(?:^|/)cart/add(?:[/.]|$)~', $path) || ($hiddenItem && $quantity) || self::hasCommerceSubmissionSignal($form, true) ) {
            return 'commerce_add_to_cart';
        }
        if ( $password ) return 'login';
        // Messaging intents first: a contact form may offer a subject <select>
        // and omit `method`, but a search or filter form never collects an email.
        if ( $email && ($subscribe || preg_match('~(?:^|/)(?:newsletter|subscribe)(?:/|$)~', $path)) ) return 'newsletter';
        if ( $email && $message ) return 'contact';
        $method = strtolower(trim(SourceDom::attr($form, 'method')));
        if ( '' === $method || 'get' === $method ) {
            if ( $search || preg_match('~(?:^|/)search(?:/|$)~', $path) ) return 'search';
            if ( $select && ! $freeText ) return 'filter_sort';
        }

        // Keep existing GET search/filter intents, but recognize bounded choice
        // state without a submit control independently of method or destination.
        if ( $hasChoice && $onlyChoices && ! $hasSubmit && ! $email ) return 'preference';

        return 'unknown';
    }

    private static function hasCommerceSubmissionSignal(DOMElement $form, bool $addToCartOnly = false): bool
    {
        foreach ( FormControlClassifier::controlElements($form) as $control ) {
            if ( ! FormControlClassifier::isSubmitLikeControl($control) ) {
                continue;
            }

            $haystack = strtolower(implode(' ', array(
                $control->textContent ?? '',
                SourceDom::attr($control, 'value'),
                $addToCartOnly ? '' : SourceDom::attr($control, 'class'),
                $addToCartOnly ? '' : SourceDom::attr($control, 'id'),
                SourceDom::attr($control, 'name'),
                SourceDom::attr($control, 'aria-label'),
                SourceDom::attr($control, 'title'),
            )));

            $semantics = $addToCartOnly ? 'add[ _-]*(?:to[ _-]*)?cart' : 'add to cart|cart|checkout|payment|purchase|buy|order|register|registration|ticket';
            if ( preg_match('/(?:^|[^a-z0-9])(?:' . $semantics . ')(?:[^a-z0-9]|$)/', $haystack) ) {
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
