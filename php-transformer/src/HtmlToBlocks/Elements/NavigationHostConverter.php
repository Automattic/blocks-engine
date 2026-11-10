<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\AccordionPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\NavigationPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternTreeConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\SocialLinksPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/**
 * A custom-element menu host is not a component. Lower the host of a
 * navigation landmark to that landmark's native navigation, keeping host
 * identity CSS can still address, before generated-component capture freezes
 * paragraph-wrapped labels into a companion content attribute.
 */
final class NavigationHostConverter implements ElementConverter
{
    public function __construct(
        private readonly PatternTreeConverter $tree,
        private readonly LayoutShellLowering $layoutShells,
        private readonly SourceBlockCreator $createBlock
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
    {
        $landmark = ( new NavigationPattern($this->createBlock) )->hostedNavigationLandmark($element);
        if ( ! $landmark instanceof DOMElement ) {
            return ConversionOutcome::unhandled();
        }

        $navigation = $this->tree->patterns($landmark, $fallbacks, array( AccordionPattern::class, SocialLinksPattern::class, NavigationPattern::class ));
        if ( null === $navigation ) {
            return ConversionOutcome::unhandled();
        }

        if ( '' === trim(SourceDom::attr($element, 'id') . SourceDom::attr($element, 'class') . SourceDom::attr($element, 'style')) ) {
            return ConversionOutcome::handled($navigation);
        }

        return ConversionOutcome::handled($this->layoutShells->blockForElements(array( $element ), array( $navigation ), $element));
    }
}
