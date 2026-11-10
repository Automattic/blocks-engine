<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredButtonBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternTreeConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\LinkUrlSanitizer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMElement;

/**
 * Lowers elements that carry a link without being a plain anchor: a
 * link-bearing custom element, or an anchor wrapping one runtime control.
 */
final class LinkCarrierLowering implements ElementConverter
{
    public function __construct(
        private readonly SourceBlockCreator $blocks,
        private readonly PatternTreeConverter $tree,
        private readonly Runtime $runtime,
        private readonly StyleResolver $styleResolver,
        private readonly RuntimeIslandAnalyzer $runtimeIslands,
        private readonly LayoutShellLowering $layoutShells
    ) {
    }

    /** Lowers a link-bearing unknown element; see {@see self::linkBearingElementBlock()}. */
    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
    {
        $block = $this->linkBearingElementBlock($element);

        return null === $block ? ConversionOutcome::unhandled() : ConversionOutcome::handled($block);
    }

    /**
     * Lower an otherwise-unsupported static element with a usable destination
     * to an editable native button link. This deliberately runs only after the
     * specialized converters: it is a capability-based escape hatch for unknown
     * element names, including namespaced elements normalized by DOMDocument.
     *
     * Runtime-owned elements and elements without a safe destination continue
     * through the explicit preservation or unsupported-diagnostic paths.
     *
     * @return array<string, mixed>|null
     */
    public function linkBearingElementBlock(DOMElement $element): ?array
    {
        if ( ! $this->runtimeIslands->isSafeTransparentCustomElement($element) ) {
            return null;
        }

        $href = LinkUrlSanitizer::sanitize(SourceDom::attr($element, 'href'));
        if ( '' === $href ) {
            return null;
        }

        $label = trim($this->runtime->stripAllTags($this->styleResolver->innerHtml($element)));
        if ( '' === $label ) {
            $label = trim(SourceDom::attr($element, 'aria-label'));
        }
        if ( '' === $label ) {
            $label = trim(SourceDom::attr($element, 'title'));
        }
        if ( '' === $label ) {
            $label = 'Open link';
        }

        $attrs = array_merge($this->styleResolver->presentationAttributes($element), array_filter(array(
            'url'        => $href,
            'text'       => $label,
            'linkTarget' => SourceDom::attr($element, 'target'),
            'rel'        => SourceDom::attr($element, 'rel'),
        ), static fn (string $value): bool => '' !== trim($value)));
        return $this->blocks->createBlock(
            'core/buttons',
            array(),
            array( $this->blocks->createBlock('core/button', $attrs, array(), $element) ),
            $element
        );
    }

    /**
     * Lowers a link wrapping one captured runtime control (a dialog-trigger
     * button, say) to the source anchor saved as a layout-shell wrapper around
     * that control's native block.
     *
     * The invalid interactive nesting real sources ship would otherwise take the
     * wrapped-button preservation exit as one core/html island, although the
     * control itself converts natively — the runtime authored-button the same
     * element takes without a link. The shell keeps the anchor's exact
     * attributes (the link target among them) around the editable control, and
     * the control keeps its dialog binding attributes. Declines — keeping the
     * wrapped-button preservation — when the control has no native conversion
     * or any resulting block would still be core/html.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     */
    public function linkedRuntimeControlBlockFromAnchor(DOMElement $anchor, array &$fallbacks): ?array
    {
        $control = null;
        foreach ( $anchor->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }
            if ( ! $child instanceof DOMElement || 'button' !== strtolower($child->tagName) || null !== $control ) {
                return null;
            }
            $control = $child;
        }
        if ( null === $control
            || ! $this->runtimeIslands->isRuntimeDomTarget($control)
            || array() === AuthoredButtonBlockGenerator::sourceSafeAttributes($control) ) {
            return null;
        }

        $local    = array();
        $children = $this->tree->children($anchor, $local, true);
        if ( array() === $children ) {
            return null;
        }
        foreach ( $children as $child ) {
            if ( 'core/html' === ($child['blockName'] ?? '') ) {
                return null;
            }
        }
        $fallbacks = array_merge($fallbacks, $local);

        $href = LinkUrlSanitizer::sanitize(SourceDom::attr($anchor, 'href'));
        if ( '' === $href ) {
            $anchor->removeAttribute('href');
        } else {
            $anchor->setAttribute('href', $href);
        }

        return $this->layoutShells->blockForElements(array($anchor), $children, $anchor);
    }
}
