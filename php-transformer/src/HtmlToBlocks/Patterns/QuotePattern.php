<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

use const XML_TEXT_NODE;

final class QuotePattern implements PatternRecognizerInterface
{
    use PatternDomHelpersTrait;

    public function recognize(DOMElement $element, PatternContext $context): ?PatternRecognitionResult
    {
        return $this->matchBlockquote($element, $context);
    }

    public function matchBlockquote(DOMElement $element, PatternContext $context): ?PatternRecognitionResult
    {
        $quotes = $context->quoteContext();
        $converter = $context->recursiveConverter();
        if ( null === $quotes || null === $converter ) {
            return null;
        }

        $citation = $quotes->citationFromElement($element);
        $value = $quotes->innerHtmlWithoutTags($element, array( 'cite', 'footer' ));
        if ( '' === trim($quotes->stripAllTags($value)) ) {
            return null;
        }

        $createBlock = $context->createBlock(...);
        if ( $this->hasClass($element, 'wp-block-pullquote') ) {
            return new PatternRecognitionResult($createBlock('core/pullquote', array_filter(array_merge($context->presentationAttributes($element), array(
                'value'    => $value,
                'citation' => $citation,
            )), static fn ($value): bool => '' !== $value), array(), $element));
        }

        $fallbacks = array();
        $innerBlocks = $this->phrasingQuoteChildren($element, $value, $context, $quotes);
        if ( array() === $innerBlocks ) {
            $innerBlocks = $converter->childrenWithoutTags($element, $fallbacks, array( 'cite', 'footer' ));
        }
        if ( array() === $innerBlocks ) {
            $innerBlocks[] = $createBlock('core/paragraph', array( 'content' => $value ));
        }

        return new PatternRecognitionResult(
            $createBlock('core/quote', array_filter(array_merge($context->presentationAttributes($element), array( 'citation' => $citation )), static fn ($value): bool => '' !== $value), $innerBlocks, $element),
            $fallbacks
        );
    }

    public function matchFigureBlockquote(DOMElement $figure, DOMElement $blockquote, PatternContext $context): ?PatternRecognitionResult
    {
        $quotes = $context->quoteContext();
        $converter = $context->recursiveConverter();
        if ( null === $quotes || null === $converter ) {
            return null;
        }

        $citation = $quotes->citationFromElement($blockquote);
        $caption = $this->firstChildElement($figure, 'figcaption');
        if ( '' === $citation && $caption instanceof DOMElement ) {
            $citation = $context->innerHtml($caption);
            $captionClass = trim($this->attr($caption, 'class'));
            if ( '' !== $captionClass ) {
                $citation = '<span class="' . htmlspecialchars($captionClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . $citation . '</span>';
            }
        }

        $value = $quotes->innerHtmlWithoutTags($blockquote, array( 'cite', 'footer' ));
        if ( '' === trim($quotes->stripAllTags($value)) ) {
            return null;
        }

        $createBlock = $context->createBlock(...);
        $attrs = array_filter(array_merge($context->presentationAttributes($figure), array( 'citation' => $citation )), static fn ($value): bool => is_array($value) ? array() !== $value : '' !== $value);

        if ( $this->hasClass($figure, 'wp-block-pullquote') || $this->hasClass($blockquote, 'wp-block-pullquote') ) {
            return new PatternRecognitionResult($createBlock('core/pullquote', array_merge($attrs, array( 'value' => $value )), array(), $figure));
        }

        $fallbacks = array();
        $innerBlocks = array();
        foreach ( $figure->childNodes as $child ) {
            if ( ! $child instanceof DOMElement || $child->isSameNode($blockquote) || $child->isSameNode($caption) ) {
                continue;
            }
            $content = $context->innerHtml($child);
            if ( 'true' !== strtolower(trim($this->attr($child, 'aria-hidden'))) || '' === trim($quotes->stripAllTags($content)) ) {
                continue;
            }
            $innerBlocks[] = $createBlock('core/paragraph', array_merge($context->presentationAttributes($child), array( 'content' => $content )), array(), $child);
        }
        $innerBlocks = array_merge($innerBlocks, $converter->childrenWithoutTags($blockquote, $fallbacks, array( 'cite', 'footer' )));
        if ( array() === $innerBlocks ) {
            $innerBlocks[] = $createBlock('core/paragraph', array( 'content' => $value ));
        }

        return new PatternRecognitionResult($createBlock('core/quote', $attrs, $innerBlocks, $figure), $fallbacks);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function phrasingQuoteChildren(DOMElement $element, string $value, PatternContext $context, QuotePatternContext $quotes): array
    {
        // A blockquote may be wrapped in phrasing elements while still carrying
        // structural descendants. Send that shape through child lowering so the
        // structural nodes become inner blocks rather than paragraph RichText.
        if ( preg_match('/<(?:address|article|aside|blockquote|details|div|dl|figure|h[1-6]|hr|main|menu|nav|ol|p|pre|section|table|ul)\b/i', $value) ) {
            return array();
        }

        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                continue;
            }
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $tagName = strtolower($child->tagName);
            if ( in_array($tagName, array( 'cite', 'footer' ), true) ) {
                continue;
            }
            if ( 'br' === $tagName || $quotes->isInlineContentElement($tagName) ) {
                continue;
            }

            return array();
        }

        return array( $context->createBlock('core/paragraph', array_filter(array(
            'content'   => $value,
            'className' => 'blocks-engine-synthetic-paragraph',
        ), static fn (string $value): bool => '' !== $value)) );
    }

}
