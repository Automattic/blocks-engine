<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use DOMElement;

/**
 * Converts `h1`-`h6` and `p` into their native rich-text blocks.
 *
 * These are the two highest-traffic branches of the transformer's dispatch
 * chain and they share one decision shape: materialize rich-text content, decide
 * whether that content still needs a core/html fallback, then either drop the
 * element, lower it to a group, or emit the native block.
 *
 * Extracted as a collaborator rather than a trait. A single-consumer trait keeps
 * every method in the transformer's `$this` scope, so it relocates code without
 * shrinking the object surface. This class receives a
 * {@see RichTextElementContext} and is exercised without a transformer.
 */
final class RichTextElementConverter
{
    private const HEADING_PATTERN = '/^h([1-6])$/';

    public function __construct(private readonly RichTextElementContext $context)
    {
    }

    public function handles(string $tagName): bool
    {
        return 'p' === $tagName || 1 === preg_match(self::HEADING_PATTERN, $tagName);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks): ConversionOutcome
    {
        if ( preg_match(self::HEADING_PATTERN, $tagName, $matches) ) {
            return ConversionOutcome::handled($this->convertHeading($element, (int) $matches[1]));
        }

        if ( 'p' === $tagName ) {
            return ConversionOutcome::handled($this->convertParagraph($element, $fallbacks));
        }

        return ConversionOutcome::unhandled();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function convertHeading(DOMElement $element, int $level): ?array
    {
        $content = $this->context->headingRichTextContent($this->context->richTextContent($element));

        if ( $this->context->requiresHtmlFallback($content) ) {
            return $this->context->htmlPreservationBlock($element);
        }

        if ( '' === trim($this->context->stripAllTags($content)) ) {
            return null;
        }

        return $this->context->createBlock(
            'core/heading',
            array_merge(
                $this->context->presentationAttributes($element),
                array(
                    'content' => $content,
                    'level'   => $level,
                )
            ),
            array(),
            $element
        );
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function convertParagraph(DOMElement $element, array &$fallbacks): ?array
    {
        $marquee = $this->context->authoredMarqueeBlock($element);
        if ( null !== $marquee ) {
            return $marquee;
        }

        $content         = $this->context->richTextContent($element);
        $withInlineSvg   = $this->context->richTextWithMaterializedSvgImages($element, $content);
        if ( null !== $withInlineSvg ) {
            $content = $withInlineSvg;
        }

        if ( $this->context->requiresHtmlFallback($content) ) {
            return $this->context->htmlPreservationBlock($element);
        }

        // A paragraph carrying box chrome around an empty inline child is a
        // styled container, not text. Lower it to a group so the chrome
        // survives as layout instead of an empty paragraph.
        if ( $this->context->hasEmptyVisualInlineChild($element) && $this->context->hasBoxChromeWrapperStyling($element) ) {
            $children = $this->context->convertChildren($element, $fallbacks, true);
            if ( array() !== $children ) {
                return $this->context->createBlock('core/group', $this->context->presentationAttributes($element), $children, $element);
            }
        }

        if ( '' === trim($this->context->stripAllTags($content)) && ! $this->context->containsNativeSvgImageObject($content) ) {
            // An empty paragraph that scripts address by selector must keep a
            // block at that position, otherwise the runtime target disappears.
            if ( $this->context->isRuntimeDomTarget($element) ) {
                return $this->context->createBlock('core/group', $this->context->presentationAttributes($element), array(), $element);
            }

            $textBlocks = $this->context->convertText(trim($element->textContent ?? ''));

            return $textBlocks[0] ?? null;
        }

        return $this->context->createBlock(
            'core/paragraph',
            array_merge($this->context->presentationAttributes($element), array( 'content' => $content )),
            array(),
            $element
        );
    }
}
