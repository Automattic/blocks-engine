<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use DOMElement;

/**
 * Converts source tags whose block mapping depends only on the element's own
 * text/markup content and presentation attributes.
 *
 * These branches previously lived inline in `HtmlTransformer::convertElement()`.
 * They are extracted as a collaborator rather than a trait: a trait keeps every
 * method in the transformer's `$this` scope, so it moves code across a file
 * boundary without reducing the object surface. This class receives a
 * {@see TextLeafElementContext} and can be exercised without constructing a
 * transformer.
 *
 * Ordering note: the transformer's dispatch chain is order-sensitive. This
 * converter is consulted at exactly the position the extracted branches
 * occupied, and {@see handles()} matches the same tags those branches matched.
 */
final class TextLeafElementConverter
{
    /**
     * Tags this converter owns, in the transformer's dispatch order.
     *
     * @var array<int, string>
     */
    private const TAGS = array('address', 'noscript', 'marquee', 'blink', 'pre', 'plaintext', 'hr', 'br');

    public function __construct(private readonly TextLeafElementContext $context)
    {
    }

    public function handles(string $tagName): bool
    {
        return in_array($tagName, self::TAGS, true);
    }

    /**
     * Convert a text-leaf element.
     *
     * Returns a `ConversionOutcome` so a legitimate `null` block (the element
     * intentionally produces nothing, e.g. `br`) stays distinguishable from
     * "this converter does not own the tag".
     */
    /**
     * @param array<int, array<string, mixed>> $fallbacks
     */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks): ConversionOutcome
    {
        return match ($tagName) {
            'address'          => ConversionOutcome::handled($this->convertAddress($element)),
            'noscript'         => ConversionOutcome::handled($this->convertNoscript($element, $fallbacks)),
            'marquee', 'blink' => ConversionOutcome::handled($this->convertMarquee($element, $fallbacks)),
            'pre'              => ConversionOutcome::handled($this->convertPre($element)),
            'plaintext'        => ConversionOutcome::handled($this->convertPlaintext($element)),
            'hr'               => ConversionOutcome::handled($this->convertSeparator($element)),
            'br'               => ConversionOutcome::handled(null),
            default            => ConversionOutcome::unhandled(),
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function convertAddress(DOMElement $element): ?array
    {
        $content = $this->context->richTextContent($element);
        if ( '' === trim($this->context->stripAllTags($content)) ) {
            return null;
        }

        return $this->context->createBlock(
            'core/paragraph',
            array_merge($this->context->presentationAttributes($element), array( 'content' => $content )),
            array(),
            $element
        );
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function convertNoscript(DOMElement $element, array &$fallbacks): ?array
    {
        $children = $this->context->convertChildren($element, $fallbacks, true);
        if ( array() === $children ) {
            $content = $this->context->innerHtml($element);
            if ( '' === trim($this->context->stripAllTags($content)) ) {
                return null;
            }

            return $this->context->createBlock(
                'core/paragraph',
                array_merge($this->context->presentationAttributes($element), array( 'content' => $content )),
                array(),
                $element
            );
        }

        if ( 1 === count($children) && array() === $this->context->presentationAttributes($element) ) {
            return $children[0];
        }

        return $this->context->createBlock('core/group', $this->context->presentationAttributes($element), $children, $element);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function convertMarquee(DOMElement $element, array &$fallbacks): ?array
    {
        if ( $this->context->hasBlockContentChildren($element) ) {
            $children = $this->context->convertChildren($element, $fallbacks, true);
            if ( array() === $children ) {
                return null;
            }

            if ( 1 === count($children) && array() === $this->context->presentationAttributes($element) ) {
                return $children[0];
            }

            return $this->context->createBlock('core/group', $this->context->presentationAttributes($element), $children, $element);
        }

        $content = $this->context->innerHtml($element);
        if ( '' === trim($this->context->stripAllTags($content)) ) {
            return null;
        }

        return $this->context->createBlock(
            'core/paragraph',
            array_merge($this->context->presentationAttributes($element), array( 'content' => $content )),
            array(),
            $element
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function convertPre(DOMElement $element): ?array
    {
        $code = $this->context->firstChildElement($element, 'code');
        if ( $code instanceof DOMElement ) {
            return $this->context->createBlock(
                'core/code',
                array_merge($this->context->codePresentationAttributes($element, $code), array( 'content' => $this->context->codeContent($code) )),
                array(),
                $element
            );
        }

        return $this->context->createBlock(
            'core/preformatted',
            array_merge($this->context->presentationAttributes($element), array( 'content' => $this->context->innerHtmlPreservingWhitespace($element) )),
            array(),
            $element
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function convertPlaintext(DOMElement $element): ?array
    {
        $content = $this->context->escapeHtml($element->textContent ?? '');
        if ( '' === trim($content) ) {
            return null;
        }

        return $this->context->createBlock(
            'core/preformatted',
            array_merge($this->context->presentationAttributes($element), array( 'content' => $content )),
            array(),
            $element
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function convertSeparator(DOMElement $element): ?array
    {
        return $this->context->createBlock(
            'core/separator',
            $this->context->presentationAttributes($element, array(), array( 'margin-left', 'margin-right' )),
            array(),
            $element
        );
    }
}
