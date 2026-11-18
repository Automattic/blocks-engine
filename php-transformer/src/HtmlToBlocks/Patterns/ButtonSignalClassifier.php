<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceDeclarations;
use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;
use DOMElement;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
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

        // Empty same-document links already keep their painted decoration in
        // a native link host. Do not reroute those placeholder icon controls.
        $href = trim(SourceDom::attr($element, 'href'));
        $hasEmptyDestinationBox = '' === trim($element->textContent) && '' !== $href && '#' !== $href;
        $hasExplicitControlBox = ($this->hasClassSignal($element) || $hasEmptyDestinationBox) && (
            ($this->hasPositiveDimension($style, 'width') && $this->hasPositiveDimension($style, 'height'))
            || $this->fillsPositionedContainer($style)
        );
        if ( ! $this->hasBoxPadding($style) && ! $hasExplicitControlBox ) {
            return false;
        }

        return $this->paintsControlBox($style);
    }

    /**
     * Detect a control surface whose box is split across an anchor and its sole
     * phrasing label: the anchor paints the fill, border or rounding while the
     * label carries the padding that sizes it. Together they draw one padded,
     * painted box, the same evidence {@see self::hasStyleSignal()} reads on a
     * single element. An anchor with its own width or height is sized by
     * that box, not by its label, so the label's padding does not draw it.
     */
    public function hasSplitSurfaceSignal(string $anchorStyle, string $labelStyle): bool
    {
        $anchorStyle = strtolower($anchorStyle);

        return $this->hasBoxPadding(strtolower($labelStyle))
            && ! $this->hasPositiveDimension($anchorStyle, 'width')
            && ! $this->hasPositiveDimension($anchorStyle, 'height')
            && $this->paintsControlBox($anchorStyle);
    }

    private function hasBoxPadding(string $style): bool
    {
        foreach ( CssRuleAnalyzer::declarations($style, self::PADDING_PROPERTIES) as [ 'value' => $paddingDeclaration ] ) {
            $padding = CssValueInspector::withoutImportant($paddingDeclaration);
            if ( '' === $padding || preg_match('/^(?:inherit|initial|revert(?:-layer)?|unset)$/', $padding) ) {
                continue;
            }

            foreach ( preg_split('/\s+/', $padding) ?: array() as $component ) {
                if ( ! preg_match('/^[+-]?(?:0+(?:\.0*)?|\.0+)(?:[a-z%]+)?$/', $component) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Visible rounding, a box-wide painted border, or a painting background. */
    private function paintsControlBox(string $style): bool
    {
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
