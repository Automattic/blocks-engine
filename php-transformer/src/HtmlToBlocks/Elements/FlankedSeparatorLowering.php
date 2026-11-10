<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\SpacerPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

final class FlankedSeparatorLowering
{
    public function __construct(
        private readonly SourceBlockCreator $blocks,
        private readonly StyleResolver $styleResolver,
        private readonly SourceElementClassifier $sourceElementClassifier
    ) {
    }

    /** @return array<string, mixed>|null */
    public function blockFromElement(DOMElement $element): ?array
    {
        if ( array() !== SourceDom::htmlAttributes($element) || '' !== trim($element->textContent ?? '') ) {
            return null;
        }

        $children = array();
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                $children[] = $child;
            }
        }
        if ( 3 !== count($children) || 'hr' !== strtolower($children[1]->tagName) ) {
            return null;
        }

        $margins = array();
        foreach ( array( 'top' => $children[0], 'bottom' => $children[2] ) as $side => $flank ) {
            if ( 0 !== SourceDom::childElementCount($flank) || '' !== trim($flank->textContent ?? '') || array( 'style' ) !== array_keys(SourceDom::htmlAttributes($flank)) ) {
                return null;
            }

            $declarations = $this->styleResolver->cssDeclarations(SourceDom::attr($flank, 'style'));
            if ( array() !== array_diff(array_keys($declarations), array( 'height', 'overflow', 'width' ))
                || 'hidden' !== strtolower(trim((string) ($declarations['overflow'] ?? '')))
                || ! in_array(strtolower(trim((string) ($declarations['width'] ?? ''))), array( '', '100%' ), true)
            ) {
                return null;
            }

            $height = SpacerPattern::heightFromStyle(SourceDom::attr($flank, 'style'));
            if ( '' === $height || ! $this->sourceElementClassifier->isPositiveCssLength($this->styleResolver->resolveCssVariablesInValue($height, $flank)) ) {
                return null;
            }
            $margins[ $side ] = $height;
        }

        $separator = $children[1];
        $attrs = $this->styleResolver->presentationAttributes($separator, array(), array( 'margin-left', 'margin-right' ));
        $attrs['style']['spacing']['margin'] = array_merge($attrs['style']['spacing']['margin'] ?? array(), $margins);

        return $this->blocks->createBlock('core/separator', $attrs, array(), $separator);
    }
}
