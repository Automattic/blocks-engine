<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlCompilation;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/**
 * Converts source tags whose block mapping depends only on the element's own
 * text/markup content and presentation attributes.
 *
 * It receives a {@see TextLeafElementContext} and can be exercised without
 * constructing a transformer.
 *
 * Ordering note: the element dispatch chain is order-sensitive, and
 * {@see handles()} decides which tags reach this converter at its position
 * in that chain.
 */
final class TextLeafElementConverter implements ElementConverter
{
    /**
     * Tags this converter owns, in the transformer's dispatch order.
     *
     * @var array<int, string>
     */
    private const TAGS = array('address', 'noscript', 'marquee', 'blink', 'pre', 'plaintext', 'hr', 'br');

    /** Source identities that must survive lowering to paragraph/group/pre. */
    public const LOWERED_TAGS = array('address', 'noscript', 'marquee', 'blink', 'plaintext');

    public function __construct(
        private readonly \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextMaterialization $richTextMaterializer,
        private readonly \Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime $runtime,
        private readonly \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator $createBlock,
        private readonly \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\ElementPresentationResolver $presentationResolver,
        private readonly \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier $sourceElementClassifier,
        private readonly TextLeafElementContext $context)
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
     *
     * @param array<int, array<string, mixed>> $fallbacks
     */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
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
        $content = $this->richTextMaterializer->content($element);
        if ( ! $this->runtime->hasVisibleText($content) ) {
            return null;
        }

        // <address> has no user-agent block margins; the emitted <p> would
        // gain the paragraph's 1em on any side the author left unset.
        $attrs = $this->presentationResolver->presentationAttributes($element);
        $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), HtmlCompilation::LOWERED_PARAGRAPH_CLASS);

        return $this->createBlock->createBlock('core/paragraph', array_merge($attrs, array( 'content' => $content )), array(), $element);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function convertNoscript(DOMElement $element, array &$fallbacks): ?array
    {
        $children = $this->context->convertChildren($element, $fallbacks, true);
        if ( array() === $children ) {
            $content = SourceDom::innerHtml($element);
            if ( ! $this->runtime->hasVisibleText($content) ) {
                return null;
            }

            return $this->createBlock->createBlock(
                'core/paragraph',
                array_merge($this->presentationResolver->presentationAttributes($element), array( 'content' => $content )),
                array(),
                $element
            );
        }

        if ( 1 === count($children) && array() === $this->presentationResolver->presentationAttributes($element) ) {
            return $children[0];
        }

        return $this->createBlock->createBlock('core/group', $this->presentationResolver->presentationAttributes($element), $children, $element);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function convertMarquee(DOMElement $element, array &$fallbacks): ?array
    {
        if ( $this->sourceElementClassifier->hasBlockContentChildren($element) ) {
            $children = $this->context->convertChildren($element, $fallbacks, true);
            if ( array() === $children ) {
                return null;
            }

            if ( 1 === count($children) && array() === $this->presentationResolver->presentationAttributes($element) ) {
                return $children[0];
            }

            return $this->createBlock->createBlock('core/group', $this->presentationResolver->presentationAttributes($element), $children, $element);
        }

        $content = SourceDom::innerHtml($element);
        if ( ! $this->runtime->hasVisibleText($content) ) {
            return null;
        }

        return $this->createBlock->createBlock(
            'core/paragraph',
            array_merge($this->presentationResolver->presentationAttributes($element), array( 'content' => $content )),
            array(),
            $element
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function convertPre(DOMElement $element): ?array
    {
        $code = SourceDom::firstChildElement($element, 'code');
        if ( $code instanceof DOMElement ) {
            return $this->createBlock->createBlock(
                'core/code',
                array_merge($this->context->codePresentationAttributes($element, $code), array( 'content' => $this->context->codeContent($code) )),
                array(),
                $element
            );
        }

        return $this->createBlock->createBlock(
            'core/preformatted',
            array_merge($this->presentationResolver->presentationAttributes($element), array( 'content' => SourceDom::innerHtmlPreservingWhitespace($element) )),
            array(),
            $element
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function convertPlaintext(DOMElement $element): ?array
    {
        $content = $this->runtime->escapeHtml($element->textContent ?? '');
        if ( '' === trim($content) ) {
            return null;
        }

        return $this->createBlock->createBlock(
            'core/preformatted',
            array_merge($this->presentationResolver->presentationAttributes($element), array( 'content' => $content )),
            array(),
            $element
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function convertSeparator(DOMElement $element): ?array
    {
        return $this->createBlock->createBlock(
            'core/separator',
            $this->presentationResolver->presentationAttributes($element, array(), array( 'margin-left', 'margin-right' )),
            array(),
            $element
        );
    }
}
