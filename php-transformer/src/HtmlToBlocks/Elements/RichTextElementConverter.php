<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;

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
final class RichTextElementConverter implements ElementConverter
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
        $content        = $this->context->headingRichTextContent($this->context->richTextContent($element));
        $withLowered    = $this->context->richTextWithInlineSafeButtonsLowered($element, $content);
        if ( null !== $withLowered ) {
            $content = $withLowered;
        }
        // An inline icon beside heading text is RichText content once it is a
        // materialized image object, exactly as in a paragraph. Without this
        // step the raw `<svg>` trips the fallback gate and the whole heading
        // becomes a core/html island.
        $withInlineSvg  = $this->context->richTextWithMaterializedSvgImages($element, $content);
        if ( null !== $withInlineSvg ) {
            $content = $withInlineSvg;
        }

        if ( $this->context->requiresHtmlFallback($content) ) {
            return $this->context->htmlPreservationBlock($element);
        }

        if ( '' === trim($this->context->stripAllTags($content)) && ! $this->context->containsNativeSvgImageObject($content) ) {
            return null;
        }

        return $this->context->createBlock(
            'core/heading',
            array_merge(
                $this->presentationAttributesWithBakedFontSize($element),
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
     * Presentation attributes with the authored cascade-winning `font-size`
     * baked into `style.typography.fontSize` when presentation resolution
     * could not serialize one.
     *
     * `classOwnedResponsiveDeclarations()` hands a responsive `font-size` to
     * author-stylesheet ownership so media queries keep winning the cascade.
     * Text blocks cannot rely on that ownership: the heading rules a projected
     * theme ships unlayered (`h1{font-size:inherit}`) outrank every `@layer`,
     * so the only authored value that renders is the inline one a typography
     * support serializes.
     *
     * @return array<string, mixed>
     */
    private function presentationAttributesWithBakedFontSize(DOMElement $element): array
    {
        $attrs    = $this->context->presentationAttributes($element);
        $responsiveClass = $this->context->responsiveTypographyClassName($element);
        if ( '' !== $responsiveClass ) {
            unset($attrs['style']['typography']['fontSize']);
            if ( array() === ($attrs['style']['typography'] ?? null) ) {
                unset($attrs['style']['typography']);
            }
            if ( array() === ($attrs['style'] ?? null) ) {
                unset($attrs['style']);
            }
            $attrs['className'] = trim((string) ($attrs['className'] ?? '') . ' ' . $responsiveClass);
            return $attrs;
        }

        $fontSize = $this->context->bakedTypographyFontSize($element);
        if ( '' !== $fontSize && ! isset($attrs['style']['typography']['fontSize']) ) {
            $attrs['style']['typography']['fontSize'] = $fontSize;
        }

        return $attrs;
    }

    /**
     * Lowers a paragraph made only of text and disclosure-widget spans to a
     * group of paragraphs and `core/details`; anything else is declined.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function textWithDisclosureChildren(DOMElement $element, array &$fallbacks): ?array
    {
        $children = array();
        $found = false;
        $text = '';
        $flush = function () use (&$children, &$text): void {
            if ( '' !== trim($text) ) {
                $children = array_merge($children, $this->context->convertText(trim($text)));
            }
            $text = '';
        };
        foreach ( $element->childNodes as $node ) {
            if ( $node instanceof DOMElement && 'span' === strtolower($node->tagName) ) {
                $local = array();
                $details = $this->context->nativeDisclosureBlock($node, $local);
                if ( null === $details ) {
                    return null;
                }
                $flush();
                $fallbacks = array_merge($fallbacks, $local);
                $children[] = $details;
                $found = true;
            } elseif ( $node instanceof \DOMComment ) {
                continue;
            } elseif ( $node instanceof \DOMText ) {
                $text .= $node->textContent;
            } else {
                return null;
            }
        }
        $flush();

        return $found ? $this->context->createBlock('core/group', $this->context->presentationAttributes($element), $children, $element) : null;
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

        // Paragraphs used only as attachment wrappers are common in WordPress
        // source. Route their image anchor before RichText rejects the <img>.
        $image = $this->context->imageBlockFromParagraph($element);
        if ( null !== $image ) {
            return $image;
        }

        $editableIconRow = $this->context->compactLinkedIconTextRowFromParagraph($element);
        if ( null !== $editableIconRow ) {
            return $editableIconRow;
        }

        $content         = $this->context->richTextContent($element);
        $withLowered     = $this->context->richTextWithInlineSafeButtonsLowered($element, $content);
        if ( null !== $withLowered ) {
            $content = $withLowered;
        }
        $withInlineSvg   = $this->context->richTextWithMaterializedSvgImages($element, $content);
        if ( null !== $withInlineSvg ) {
            $content = $withInlineSvg;
        }

        $emptyLayout = $this->context->emptyInlineGeometryBlock($element, $fallbacks);
        if ( null !== $emptyLayout ) {
            return $emptyLayout;
        }

        if ( $this->context->requiresHtmlFallback($content) ) {
            // A paragraph wrapping one anchor that mixes an image with text is
            // not RichText, but the container path already converts that anchor
            // shape natively. Lower it the same way instead of preserving a
            // core/html island for content the transformer can represent.
            $mixedMedia = $this->context->mixedMediaLinkGroupFromParagraph($element, $fallbacks);
            if ( null !== $mixedMedia ) {
                return $mixedMedia;
            }

            // A text run ending in a toggle + collapsed-region span is text plus a
            // native disclosure, not an opaque HTML island.
            $disclosure = $this->textWithDisclosureChildren($element, $fallbacks);
            if ( null !== $disclosure ) {
                return $disclosure;
            }

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
            $fragmentId = SourceDom::namedFragmentTargetId($element);
            $fragmentTarget = '' !== $fragmentId
                && SourceDom::documentReferencesFragmentId($element, $fragmentId)
                && ! SourceDom::documentHasOtherFragmentTarget($element, $fragmentId);
            if ( $this->context->isRuntimeDomTarget($element) || $fragmentTarget ) {
                $attributes = $this->context->presentationAttributes($element);
                if ( $fragmentTarget ) {
                    $attributes['anchor'] = $fragmentId;
                }
                return $this->context->createBlock('core/group', $attributes, array(), $element);
            }

            $textBlocks = $this->context->convertText(trim($element->textContent ?? ''));

            return $textBlocks[0] ?? null;
        }

        return $this->context->createBlock(
            'core/paragraph',
            array_merge($this->presentationAttributesWithBakedFontSize($element), array( 'content' => $content )),
            array(),
            $element
        );
    }
}
