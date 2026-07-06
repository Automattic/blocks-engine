<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

final class ButtonSignalClassifier
{
    public function hasTransformSignal(DOMElement $element): bool
    {
        if ( 'button' === strtolower($element->hasAttribute('role') ? $element->getAttribute('role') : '') ) {
            return true;
        }

        return $this->hasClassSignal($element)
            || $this->hasAnyToken($element, array( 'cta', 'action' ))
            || $this->hasPhrase($element, array( 'call-to-action', 'primary-action', 'secondary-action' ))
            || $this->hasActionText($element)
            || $this->hasStyleSignal($element);
    }

    /**
     * Detect button-like class/id tokens generically.
     *
     * Keys off the generic "btn"/"button" substring rather than any one specific
     * class string, so framework variants are recognized: btn, btn-primary,
     * hero-btn, link-btn, btnPrimary, actionButton, icon-button, roundedbtn, etc.
     */
    public function hasClassSignal(DOMElement $element): bool
    {
        foreach ( array( 'class', 'id' ) as $attribute ) {
            $value = strtolower($element->hasAttribute($attribute) ? $element->getAttribute($attribute) : '');
            if ( str_contains($value, 'btn') || str_contains($value, 'button') ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect button-like inline styling.
     *
     * Treats an element as a button when it carries padding plus a button shape
     * signal (a filled, non-transparent background or a border radius). This lets
     * styled anchors with no recognizable class still be promoted to buttons,
     * while plain text links (no padding/fill) stay links.
     */
    public function hasStyleSignal(DOMElement $element): bool
    {
        $style = strtolower($element->hasAttribute('style') ? $element->getAttribute('style') : '');
        if ( '' === $style || ! preg_match('/(?:^|;)\s*padding(?:-[a-z]+)?\s*:\s*[^;]+/', $style) ) {
            return false;
        }

        if ( preg_match('/(?:^|;)\s*border[a-z-]*radius\s*:\s*[^;]+/', $style) ) {
            return true;
        }

        return preg_match('/(?:^|;)\s*background(?:-color)?\s*:\s*[^;]+/', $style) === 1
            && preg_match('/(?:^|;)\s*background(?:-color)?\s*:\s*(?:transparent|none|inherit|initial|rgba\(\s*0\s*,\s*0\s*,\s*0\s*,\s*0\s*\))\s*(?:;|$)/', $style) !== 1;
    }

    /**
     * @param array<int, string> $tokens
     */
    private function hasAnyToken(DOMElement $element, array $tokens): bool
    {
        foreach ( array( 'class', 'id' ) as $attribute ) {
            $value = $element->hasAttribute($attribute) ? $element->getAttribute($attribute) : '';
            foreach ( preg_split('/[^a-z0-9]+/', strtolower($value)) ?: array() as $token ) {
                if ( in_array($token, $tokens, true) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $phrases
     */
    private function hasPhrase(DOMElement $element, array $phrases): bool
    {
        foreach ( array( 'class', 'id' ) as $attribute ) {
            $value = strtolower($element->hasAttribute($attribute) ? $element->getAttribute($attribute) : '');
            foreach ( $phrases as $phrase ) {
                if ( str_contains($value, $phrase) ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasActionText(DOMElement $element): bool
    {
        $text = strtolower(trim(preg_replace('/\s+/', ' ', $element->textContent ?? '') ?? ''));
        if ( '' === $text ) {
            return false;
        }

        return in_array($text, array(
            'add to cart',
            'buy now',
            'checkout',
            'shop now',
            'get started',
            'sign up',
            'subscribe',
            'donate',
            'register',
            'book now',
        ), true);
    }
}
