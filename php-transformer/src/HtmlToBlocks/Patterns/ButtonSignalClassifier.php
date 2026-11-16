<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceDeclarations;
use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;
use DOMElement;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;

final class ButtonSignalClassifier
{
    private const PADDING_PROPERTIES = array( 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'padding-block', 'padding-inline' );

    private const RADIUS_PROPERTIES = array(
        'border-radius',
        'border-top-left-radius', 'border-top-right-radius', 'border-bottom-right-radius', 'border-bottom-left-radius',
        'border-start-start-radius', 'border-start-end-radius', 'border-end-start-radius', 'border-end-end-radius',
    );

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
        if ( '' === $style ) {
            return false;
        }

        $hasBoxPadding = false;
        foreach ( CssRuleAnalyzer::declarations($style, self::PADDING_PROPERTIES) as [ 'value' => $paddingDeclaration ] ) {
            $padding = CssValueInspector::withoutImportant($paddingDeclaration);
            if ( '' === $padding || preg_match('/^(?:inherit|initial|revert(?:-layer)?|unset)$/', $padding) ) {
                continue;
            }

            $components = preg_split('/\s+/', $padding) ?: array();
            $allZero    = true;
            foreach ( $components as $component ) {
                if ( ! preg_match('/^[+-]?(?:0+(?:\.0*)?|\.0+)(?:[a-z%]+)?$/', $component) ) {
                    $allZero = false;
                    break;
                }
            }

            if ( ! $allZero ) {
                $hasBoxPadding = true;
                break;
            }
        }

        $hasExplicitControlBox = $this->hasClassSignal($element) && (
            ($this->hasPositiveDimension($style, 'width') && $this->hasPositiveDimension($style, 'height'))
            || $this->fillsPositionedContainer($style)
        );
        if ( ! $hasBoxPadding && ! $hasExplicitControlBox ) {
            return false;
        }

        if ( array() !== CssRuleAnalyzer::declarations($style, self::RADIUS_PROPERTIES) ) {
            return true;
        }

        // A side-specific border with matching padding is commonly an underline.
        // Only the box-wide shorthand establishes an outlined control surface.
        // A border reset (`border:0`, `border:none`) is neutral: it neither
        // outlines the control nor cancels the fill that may paint it.
        $border = (new ButtonStyleResolver())->paintedBorder($style);
        if ( isset($border['style']) ) {
            return true;
        }

        foreach ( CssRuleAnalyzer::declarations($style, array( 'background', 'background-color' )) as [ 'value' => $background ] ) {
            if ( CssValueInspector::backgroundPaints($background) ) {
                return true;
            }
        }
        return false;
    }

    private function hasPositiveDimension(string $style, string $property): bool
    {
        $declaration = CssRuleAnalyzer::declarations($style, array( $property ))[0] ?? null;
        if ( null === $declaration ) {
            return false;
        }

        $value = CssValueInspector::withoutImportant($declaration['value']);
        return ! preg_match('/^(?:auto|none|inherit|initial|revert(?:-layer)?|unset|[+-]?(?:0+(?:\.0*)?|\.0+)(?:[a-z%]+)?)$/', $value);
    }

    private function fillsPositionedContainer(string $style): bool
    {
        $declarations = SourceDeclarations::parse($style);

        return 'absolute' === ($declarations['position'] ?? '')
            && 1 === preg_match('/^100(?:\.0+)?%$/D', $declarations['min-width'] ?? '');
    }
}
