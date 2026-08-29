<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\AccordionPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\NavigationPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\SocialLinksPattern;
use DOMElement;

/** Converts ordered and unordered lists through their ordered strategies. */
final class ListElementConverter
{
    public function __construct(private readonly ListElementContext $context)
    {
    }

    public function handles(string $tagName): bool
    {
        return 'ul' === $tagName || 'ol' === $tagName;
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks): ConversionOutcome
    {
        if ( ! $this->handles($tagName) ) {
            return ConversionOutcome::unhandled();
        }

        $navigation = $this->context->recognizePatterns($element, $fallbacks, array( AccordionPattern::class, SocialLinksPattern::class, NavigationPattern::class ));
        if ( null !== $navigation ) {
            return ConversionOutcome::handled($this->context->rememberAccordionDisclosureRoot($navigation, $element));
        }

        if ( $this->context->isStructuredCardList($element) ) {
            $decomposed = $this->context->decomposeStructuredCardList($element, $fallbacks);
            if ( null !== $decomposed ) {
                return ConversionOutcome::handled($decomposed);
            }
        }

        if ( $this->context->containsStructuralItemContent($element) ) {
            return ConversionOutcome::handled($this->context->decomposeStructuralList($element, $fallbacks));
        }

        $items = $this->context->listItems($element, $fallbacks);
        if ( array() === $items ) {
            return ConversionOutcome::handled(null);
        }

        $attributes = $this->context->isCssOwnedGridElement($element)
            ? $this->context->cssOwnedGridAttributes($element)
            : $this->context->presentationAttributes($element);

        return ConversionOutcome::handled($this->context->createBlock(
            'core/list',
            array_merge($attributes, 'ol' === $tagName ? array( 'ordered' => true ) : array()),
            $items,
            $element
        ));
    }
}
