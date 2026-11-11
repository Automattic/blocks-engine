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

    /**
     * Marks a native text block lowered from an empty source `p` or heading.
     * The historical class name is shared by both block types. Saved, it is
     * an empty element; in the editor, RichText fills it with a padding
     * character and a placeholder, so EngineSupportCss restores its
     * empty-element geometry there.
     */
    public const EMPTY_SOURCE_PARAGRAPH_CLASS = 'blocks-engine-empty-source-paragraph';

    public function __construct(
        private readonly \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator $createBlock,
        private readonly \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\ElementPresentationResolver $presentationResolver,
        private readonly \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextMaterialization $richTextMaterializer,
        private readonly \Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime $runtime,
        private readonly RichTextElementContext $context)
    {
    }

    public function handles(string $tagName): bool
    {
        return 'p' === $tagName || 1 === preg_match(self::HEADING_PATTERN, $tagName);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
    {
        if ( ! $this->handles($tagName) ) {
            return ConversionOutcome::unhandled();
        }

        $decorated = $this->convertPositionedDecoration($element, $tagName, $fallbacks);
        if (null !== $decorated) {
            return ConversionOutcome::handled($decorated);
        }

        if ( preg_match(self::HEADING_PATTERN, $tagName, $matches) ) {
            $button = SourceDom::onlyChildElement($element, 'button');
            $capturedChoice = null !== $button && $button->hasAttribute('data-blocks-engine-collection-choice');
            for ($owner = $element->parentNode; !$capturedChoice && $owner instanceof DOMElement; $owner = $owner->parentNode) {
                $capturedChoice = 'true' === $owner->getAttribute('data-blocks-engine-choice-group');
            }
            if (null !== $button && $capturedChoice) {
                $children = $this->context->convertChildren($element, $fallbacks);
                return ConversionOutcome::handled($this->createBlock->createBlock('core/group', array_merge($this->presentationResolver->presentationAttributes($element), array('tagName' => $tagName)), $children, $element));
            }
            // A heading with no text whose only content is one media element
            // is the media block it visually is, not a heading. Anything with
            // text keeps the rich-text route below (#3496).
            $fallbackCount = count($fallbacks);
            $media = $this->context->mediaBlockFromTextlessElement($element, $fallbacks);
            if ( null !== $media || count($fallbacks) > $fallbackCount ) {
                return ConversionOutcome::handled($media);
            }
            return ConversionOutcome::handled($this->convertHeading($element, (int) $matches[1]));
        }

        if ( 'p' === $tagName ) {
            return ConversionOutcome::handled($this->convertParagraph($element, $fallbacks));
        }

        return ConversionOutcome::unhandled();
    }

    /**
     * Keep overflow-visible, page-styled artwork in the document rather than
     * externalizing it into a clipped image. The text remains ordinary RichText.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function convertPositionedDecoration(DOMElement $element, string $tagName, array &$fallbacks): ?array
    {
        $decorations = array();
        $removed = array();
        foreach (iterator_to_array($element->getElementsByTagName('svg')) as $child) {
            if (!$child instanceof DOMElement || 'svg' !== strtolower($child->tagName)) {
                continue;
            }
            $block = $this->context->decorativeSvgBlock($child);
            if (null !== $block) {
                $decorations[] = $block;
                $removed[] = array($child, $child->parentNode, $child->nextSibling);
            }
        }
        if (array() === $decorations) {
            return null;
        }
        foreach ($removed as [$child, $parent]) {
            $parent?->removeChild($child);
        }
        try {
            $text = preg_match(self::HEADING_PATTERN, $tagName, $matches)
                ? $this->convertHeading($element, (int) $matches[1])
                : $this->convertParagraph($element, $fallbacks);
        } finally {
            foreach (array_reverse($removed) as [$child, $parent, $next]) {
                $parent?->insertBefore($child, $next);
            }
        }
        return $this->createBlock->createBlock('core/group', array(
            'className' => 'blocks-engine-decorated-text',
            'layout' => array('type' => 'default'),
        ), array_merge(null === $text ? array() : array($text), $decorations));
    }

    /** @return array<string, mixed>|null */
    private function convertHeading(DOMElement $element, int $level): ?array
    {
        $content        = $this->richTextMaterializer->content($element);
        $withLowered    = $this->richTextMaterializer->contentWithInlineSafeButtonsLowered($element, $content);
        if ( null !== $withLowered ) {
            $content = $withLowered;
        }
        // An inline icon beside heading text is RichText content once it is a
        // materialized image object, exactly as in a paragraph. Without this
        // step the raw `<svg>` trips the fallback gate and the whole heading
        // becomes a core/html island.
        $withInlineSvg  = $this->richTextMaterializer->contentWithMaterializedSvgImages($element, $content);
        if ( null !== $withInlineSvg ) {
            $content = $withInlineSvg;
        }

        // Let createBlock's structural RichText lowering convert flow children
        // before the HTML fallback gate rejects media inside their wrappers.
        $hasStructuralHtml = $this->richTextMaterializer->hasStructuralHtml($content);
        if ( ! $hasStructuralHtml && $this->richTextMaterializer->requiresHtmlFallbackForElement($element, $content) ) {
            return $this->createBlock->htmlPreservationBlock($element);
        }

        if ( ! $hasStructuralHtml && ! $this->runtime->hasVisibleText($content)
            && ! SourceDom::carriesOnlyRichTextInlineImages($element)
            && ! $this->richTextMaterializer->containsNativeSvgImageObject($content) ) {
            return $this->emptySourceTextBlock($element, 'core/heading', array('level' => $level));
        }

        return $this->createBlock->createBlock(
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

    /** Empty native text shares the existing footprint and source-identity path. */
    private function emptySourceTextBlock(DOMElement $element, string $name, array $extra = array()): ?array
    {
        // Paragraphs retain their established source-box contract (#2802).
        // Headings previously dropped unconditionally; retain only proven boxes.
        if ('core/paragraph' !== $name && !$this->context->shouldPreserveEmptySourceText($element)) {
            return null;
        }
        $attributes = $this->presentationAttributesWithBakedFontSize($element);
        $attributes['className'] = SourceDom::mergeClassNames((string) ($attributes['className'] ?? ''), self::EMPTY_SOURCE_PARAGRAPH_CLASS);
        return $this->createBlock->createBlock($name, array_merge($attributes, $extra, array('content' => '')), array(), $element);
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
        $attrs    = $this->presentationResolver->presentationAttributes($element);
        $responsiveClass = $this->presentationResolver->responsiveTypographyClassName($element);
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

        $fontSize = $this->presentationResolver->bakedTypographyFontSize($element);
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

        return $found ? $this->createBlock->createBlock('core/group', $this->presentationResolver->presentationAttributes($element), $children, $element) : null;
    }

    /**
     * Lowers a paragraph whose whole content is one `<button>` through the
     * container path before RichText can turn it into an inline mark, which
     * already converts that button natively with its runtime attributes, as
     * it does when the same button sits in a `<div>`. The paragraph keeps its
     * box as a group. Inline `<span>` wrappers between the paragraph and the
     * button are crossed only when they carry no class, style or id, so no
     * presentation is dropped. Declined when any block would still need
     * core/html.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function soleButtonParagraph(DOMElement $element, array &$fallbacks): ?array
    {
        $host = $element;
        while ( null !== ($wrapper = SourceDom::onlyChildElement($host, 'span')) ) {
            foreach ( array( 'class', 'style', 'id' ) as $attribute ) {
                if ( $wrapper->hasAttribute($attribute) ) {
                    return null;
                }
            }
            $host = $wrapper;
        }
        if ( null === SourceDom::onlyChildElement($host, 'button') ) {
            return null;
        }

        $local    = array();
        $children = $this->context->convertChildren($host, $local);
        if ( array() === $children || $this->containsHtmlBlock($children) ) {
            return null;
        }
        $fallbacks = array_merge($fallbacks, $local);

        return $this->createBlock->createBlock('core/group', $this->presentationResolver->presentationAttributes($element), $children, $element);
    }

    /** @param array<int, array<string, mixed>> $blocks */
    private function containsHtmlBlock(array $blocks): bool
    {
        foreach ( $blocks as $block ) {
            if ( 'core/html' === ($block['blockName'] ?? '') || $this->containsHtmlBlock((array) ($block['innerBlocks'] ?? array())) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lowers a paragraph holding a `<picture>` beside inline text (an avatar
     * next to an attribution line) to a row group. RichText cannot keep the
     * picture's `<source>` set, but the container path already converts the
     * picture through the shared media primitives and the text run to an
     * editable paragraph. The row keeps the two side by side as in the source
     * inline flow. Declined when any child would still need core/html.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function pictureWithTextRow(DOMElement $element, array &$fallbacks): ?array
    {
        $hasPicture = false;
        foreach ( $element->childNodes as $node ) {
            if ( $node instanceof DOMElement && 'picture' === strtolower($node->tagName) ) {
                $hasPicture = true;
                break;
            }
        }
        if ( ! $hasPicture || '' === trim($element->textContent ?? '') ) {
            return null;
        }

        $local    = array();
        $children = $this->context->convertChildren($element, $local);
        if ( count($children) < 2 ) {
            return null;
        }
        foreach ( $children as $child ) {
            if ( 'core/html' === ($child['blockName'] ?? '') ) {
                return null;
            }
        }
        $fallbacks = array_merge($fallbacks, $local);

        $attrs = $this->presentationResolver->presentationAttributes($element);
        $attrs['layout'] ??= array( 'type' => 'flex', 'flexWrap' => 'nowrap' );

        return $this->createBlock->createBlock('core/group', $attrs, $children, $element);
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

        // Classic editors wrap embed codes in a paragraph. A paragraph with no
        // text whose only content is one media element is that media block,
        // exactly like a text-less heading (#4303).
        $fallbackCount = count($fallbacks);
        $media = $this->context->mediaBlockFromTextlessElement($element, $fallbacks);
        if ( null !== $media || count($fallbacks) > $fallbackCount ) {
            return $media;
        }

        $editableIconRow = $this->context->compactLinkedIconTextRowFromParagraph($element);
        if ( null !== $editableIconRow ) {
            return $editableIconRow;
        }

        $soleControl = $this->soleButtonParagraph($element, $fallbacks);
        if ( null !== $soleControl ) {
            return $soleControl;
        }

        $content         = $this->richTextMaterializer->content($element);
        $withLowered     = $this->richTextMaterializer->contentWithInlineSafeButtonsLowered($element, $content);
        if ( null !== $withLowered ) {
            $content = $withLowered;
        }
        $withInlineSvg   = $this->richTextMaterializer->contentWithMaterializedSvgImages($element, $content);
        if ( null !== $withInlineSvg ) {
            $content = $withInlineSvg;
        }

        $emptyLayout = $this->context->emptyInlineGeometryBlock($element, $fallbacks);
        if ( null !== $emptyLayout ) {
            return $emptyLayout;
        }

        if ( $this->richTextMaterializer->requiresHtmlFallbackForElement($element, $content) ) {
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

            $pictureRow = $this->pictureWithTextRow($element, $fallbacks);
            if ( null !== $pictureRow ) {
                return $pictureRow;
            }

            return $this->createBlock->htmlPreservationBlock($element);
        }

        // A paragraph carrying box chrome around an empty inline child is a
        // styled container, not text. Lower it to a group so the chrome
        // survives as layout instead of an empty paragraph.
        if ( $this->context->hasEmptyVisualInlineChild($element) && $this->context->hasBoxChromeWrapperStyling($element) ) {
            $children = $this->context->convertChildren($element, $fallbacks, true);
            if ( array() !== $children ) {
                return $this->createBlock->createBlock('core/group', $this->presentationResolver->presentationAttributes($element), $children, $element);
            }
        }

        if ( ! $this->runtime->hasVisibleText($content)
            && ! SourceDom::carriesOnlyRichTextInlineImages($element)
            && ! $this->richTextMaterializer->containsNativeSvgImageObject($content) ) {
            // An empty paragraph that scripts address by selector must keep a
            // block at that position, otherwise the runtime target disappears.
            $fragmentId = SourceDom::namedFragmentTargetId($element);
            $fragmentTarget = '' !== $fragmentId
                && SourceDom::documentReferencesFragmentId($element, $fragmentId)
                && ! SourceDom::documentHasOtherFragmentTarget($element, $fragmentId);
            if ( $this->context->isRuntimeDomTarget($element) || $fragmentTarget ) {
                $attributes = $this->presentationResolver->presentationAttributes($element);
                if ( $fragmentTarget ) {
                    $attributes['anchor'] = $fragmentId;
                }
                return $this->createBlock->createBlock('core/group', $attributes, array(), $element);
            }

            $text = trim($element->textContent ?? '');
            if ( '' !== $text ) {
                $textBlocks = $this->context->convertText($text);

                return $textBlocks[0] ?? null;
            }
            return $this->emptySourceTextBlock($element, 'core/paragraph');
        }

        return $this->createBlock->createBlock(
            'core/paragraph',
            array_merge($this->presentationAttributesWithBakedFontSize($element), array( 'content' => $content )),
            array(),
            $element
        );
    }
}
