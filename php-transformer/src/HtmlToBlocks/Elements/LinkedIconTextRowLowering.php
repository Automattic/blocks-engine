<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextMaterializer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\LinkUrlSanitizer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SvgMaterializer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMElement;

final class LinkedIconTextRowLowering
{
    public function __construct(
        private readonly SourceBlockCreator $blocks,
        private readonly StyleResolver $styleResolver,
        private readonly ImageLowering $imageLowering,
        private readonly LinkWrapperPropagator $linkWrapperPropagator,
        private readonly SvgMaterializer $svgMaterializer,
        private readonly RichTextMaterializer $richTextMaterializer,
        private readonly Runtime $runtime
    ) {
    }

    /**
     * Split a linked, compact SVG/text lockup into native image and RichText
     * blocks before RichTextMaterializer turns its SVG into an inline object.
     * The source span remains the layout host, while link ownership is carried
     * by the native image href and the text's editable RichText link.
     *
     * @return array<string, mixed>|null
     */
    public function fromParagraph(DOMElement $paragraph): ?array
    {
        $anchor = null;
        foreach ( $paragraph->childNodes as $node ) {
            if ( $node instanceof DOMElement ) {
                if ( $anchor instanceof DOMElement || 'a' !== strtolower($node->tagName) ) {
                    return null;
                }
                $anchor = $node;
            } elseif ( '' !== trim($node->textContent ?? '') ) {
                return null;
            }
        }
        if ( ! $anchor instanceof DOMElement ) {
            return null;
        }

        $span = null;
        foreach ( $anchor->childNodes as $node ) {
            if ( $node instanceof DOMElement ) {
                if ( $span instanceof DOMElement || 'span' !== strtolower($node->tagName) ) {
                    return null;
                }
                $span = $node;
            } elseif ( '' !== trim($node->textContent ?? '') ) {
                return null;
            }
        }
        if ( ! $span instanceof DOMElement ) {
            return null;
        }

        $icon = null;
        foreach ( $span->childNodes as $node ) {
            if ( $node instanceof DOMElement ) {
                if ( $icon instanceof DOMElement || ! in_array(strtolower($node->tagName), array( 'svg', 'img' ), true) ) {
                    return null;
                }
                $icon = $node;
            }
        }
        if ( ! $icon instanceof DOMElement
            || ( 'svg' === strtolower($icon->tagName)
                && 'true' !== strtolower(trim($icon->getAttribute('aria-hidden')))
                && '' === trim(SourceDom::attr($icon, 'aria-label'))
                && '' === trim(SourceDom::attr($icon, 'title')) )
            || '' === trim($span->textContent ?? '')
            || '' === LinkUrlSanitizer::sanitize(SourceDom::attr($anchor, 'href'))
        ) {
            return null;
        }

        $textSpan = $span->cloneNode(true);
        if ( ! $textSpan instanceof DOMElement ) {
            return null;
        }
        foreach ( iterator_to_array($textSpan->childNodes) as $child ) {
            if ( $child instanceof DOMElement && in_array(strtolower($child->tagName), array( 'svg', 'img' ), true) ) {
                $textSpan->removeChild($child);
            }
        }
        $text = $this->richTextMaterializer->content($textSpan);
        if ( ! $this->runtime->hasVisibleText($text) ) {
            return null;
        }

        $image = 'svg' === strtolower($icon->tagName)
            ? $this->svgMaterializer->inlineSvgBlockFromElement($icon)
            : $this->imageLowering->convertImage($icon);
        if ( ! is_array($image) || 'core/image' !== ($image['blockName'] ?? null) ) {
            return null;
        }

        $href = LinkUrlSanitizer::sanitize(SourceDom::attr($anchor, 'href'));
        $link = '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        foreach ( array( 'target' => 'target', 'rel' => 'rel' ) as $sourceName => $outputName ) {
            $value = SourceDom::attr($anchor, $sourceName);
            if ( '' !== $value ) {
                $link .= ' ' . $outputName . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            }
        }
        $link .= '>' . $text . '</a>';
        $textBlock = $this->blocks->createBlock('core/paragraph', array(
            'content' => $link,
            'style' => array( 'spacing' => array( 'margin' => array( 'top' => '0', 'bottom' => '0' ) ) ),
        ));

        $children = array( $image, $textBlock );
        $linkAttrs = $this->linkWrapperPropagator->linkPropagationAttributes($anchor);
        $this->linkWrapperPropagator->propagateLinkWrapper($children, $linkAttrs);

        $attrs = array_replace_recursive(
            $this->styleResolver->presentationAttributes($paragraph),
            $this->styleResolver->presentationAttributes($anchor),
            $this->styleResolver->presentationAttributes($span)
        );
        $classes = array();
        foreach ( array( $paragraph, $anchor, $span ) as $sourceElement ) {
            foreach ( preg_split('/\s+/', trim(SourceDom::attr($sourceElement, 'class'))) ?: array() as $class ) {
                if ( '' !== $class && ! in_array($class, $classes, true) ) {
                    $classes[] = $class;
                }
            }
        }
        if ( array() !== $classes ) {
            $attrs['className'] = implode(' ', $classes);
        }

        return $this->blocks->createBlock('core/group', $attrs, $children, $anchor);
    }
}
