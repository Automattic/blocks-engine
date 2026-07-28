<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

final class ButtonSignalClassifier
{
    public function hasTransformSignal(DOMElement $element, string $resolvedStyle = ''): bool
    {
        if ( 'button' === strtolower($element->hasAttribute('role') ? $element->getAttribute('role') : '') ) {
            return true;
        }

        return $this->hasStyleSignal($element, $resolvedStyle);
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
     * Detect an explicit, visible button surface.
     *
     * Class names and action-oriented text are not enough: they commonly label
     * textual CTAs, navigation, and legal links. A control needs box padding plus
     * visible fill, border, or rounding in its resolved author styles.
     */
    public function hasStyleSignal(DOMElement $element, string $resolvedStyle = ''): bool
    {
        $style = strtolower('' !== trim($resolvedStyle) ? $resolvedStyle : ($element->hasAttribute('style') ? $element->getAttribute('style') : ''));
        if ( '' === $style || ! preg_match('/(?:^|;)\s*padding(?:-[a-z]+)?\s*:\s*[^;]+/', $style) ) {
            return false;
        }

        if ( preg_match('/(?:^|;)\s*border[a-z-]*radius\s*:\s*[^;]+/', $style) ) {
            return true;
        }

        if ( preg_match('/(?:^|;)\s*border(?:-[a-z]+)?\s*:\s*[^;]+/', $style) === 1 ) {
            return preg_match('/(?:^|;)\s*border(?:-[a-z]+)?\s*:\s*(?:0|none)\s*(?:;|$)/', $style) !== 1;
        }

        return preg_match('/(?:^|;)\s*background(?:-color)?\s*:\s*[^;]+/', $style) === 1
            && preg_match('/(?:^|;)\s*background(?:-color)?\s*:\s*(?:transparent|none|inherit|initial|rgba\(\s*0\s*,\s*0\s*,\s*0\s*,\s*0\s*\))\s*(?:;|$)/', $style) !== 1;
    }
}
