<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextMaterializer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SvgMaterializer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMElement;

final class InlineSvgTextGroupLowering
{
    public function __construct(
        private readonly SourceBlockCreator $blocks,
        private readonly HtmlTransformerSession $session,
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly StyleResolver $styleResolver,
        private readonly SvgMaterializer $svgMaterializer,
        private readonly RichTextMaterializer $richTextMaterializer,
        private readonly Runtime $runtime
    ) {
    }

    /**
     * Convert an inline text token with a passive SVG into native sibling blocks.
     * RichText cannot retain SVG markup, but a materialized core/image can retain
     * the artwork while the wrapper class remains available to the stylesheet.
     *
     * @return array<string, mixed>|null
     */
    public function fromElement(DOMElement $element): ?array
    {
        $tagName = strtolower($element->tagName);
        if ( ! in_array($tagName, array( 'b', 'span', 'strong' ), true) || ( 'span' === $tagName && '' === trim(SourceDom::attr($element, 'class')) ) || 0 === $element->getElementsByTagName('svg')->length ) {
            return null;
        }

        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                continue;
            }

            if ( ! $child instanceof DOMElement ) {
                return null;
            }

            $tagName = strtolower($child->tagName);
            if ( 'svg' === $tagName ) {
                continue;
            }

            if ( 'a' !== $tagName && 'br' !== $tagName && ! $this->sourceElementClassifier->isInlineContentElement($tagName) ) {
                return null;
            }

            foreach ( $child->getElementsByTagName('*') as $descendant ) {
                if ( ! $descendant instanceof DOMElement ) {
                    continue;
                }

                $descendantTagName = strtolower($descendant->tagName);
                if ( 'a' !== $descendantTagName && 'br' !== $descendantTagName && ! $this->sourceElementClassifier->isInlineContentElement($descendantTagName) ) {
                    return null;
                }
            }
        }

        $textRun = '';
        $generatedAssets = $this->session->assetMaterializationState()->checkpoint();
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                $textRun .= htmlspecialchars($child->textContent ?? '', ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
                continue;
            }

            if ( ! $child instanceof DOMElement ) {
                $this->session->assetMaterializationState()->restore($generatedAssets);
                return null;
            }

            if ( 'svg' !== strtolower($child->tagName) ) {
                $textRun .= SourceDom::outerHtml($child);
                continue;
            }

            $image = $this->svgMaterializer->inlineSvgRichTextImageMarkup($child);
            if ( null === $image ) {
                $this->session->assetMaterializationState()->restore($generatedAssets);
                return null;
            }

            $textRun .= $image;
        }

        $content = trim($textRun);
        if ( in_array($tagName, array( 'b', 'strong' ), true) ) {
            $content = '<' . $tagName . '>' . $content . '</' . $tagName . '>';
        }
        if ( ! $this->runtime->hasVisibleText($content) || $this->richTextMaterializer->requiresHtmlFallbackWithoutNativeSvgImageObjects($content) ) {
            $this->session->assetMaterializationState()->restore($generatedAssets);
            return null;
        }

        $attrs = $this->styleResolver->presentationAttributes($element);
        $display = CssValueInspector::comparable((string) ($this->styleResolver->resolvedPresentationDeclarations($element)['display'] ?? ''));
        if ( in_array($display, array('flex', 'inline-flex', 'grid', 'inline-grid'), true) ) {
            // This paragraph replaces the source layout box, not just an inline
            // text run. Do not let synthetic RichText carrier resets neutralize
            // its display (including when the text contains a link).
            $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), SourceBlockAttributeProjector::SOURCE_BOX_PARAGRAPH_CLASS);
        }
        return $this->blocks->createBlock('core/paragraph', array_merge($attrs, array( 'content' => $content )), array(), $element);
    }
}
