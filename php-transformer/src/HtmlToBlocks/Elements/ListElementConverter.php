<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\AccordionPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\NavigationPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\SocialLinksPattern;
use DOMElement;

/** Converts ordered and unordered lists (including semantic menus) through their ordered strategies. */
final class ListElementConverter implements ElementConverter
{
    public function __construct(
        private readonly \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\ElementPresentationResolver $presentationResolver,
        private readonly \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator $createBlock,
        private readonly ListElementContext $context)
    {
    }

    public function handles(string $tagName): bool
    {
        return in_array($tagName, array( 'ul', 'ol', 'menu' ), true);
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
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

        $attributes = $this->context->authorLayoutOwnership->isCssOwnedGridElement($element)
            ? $this->context->authorLayoutOwnership->cssOwnedGridAttributes($element)
            : $this->presentationResolver->presentationAttributes($element, array(), array( 'text-align' ));

        return ConversionOutcome::handled($this->createBlock->createBlock(
            'core/list',
            array_merge($attributes, 'ol' === $tagName ? array( 'ordered' => true ) : array()),
            $items,
            $element
        ));
    }
}
