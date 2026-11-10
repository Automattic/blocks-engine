<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextMaterializer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorStylesheetProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMElement;

/**
 * Lowers a bounded phrasing tree of SVG-drawn binary button controls into
 * the layout shell plus editable text carriers.
 */
final class InlineCheckableControlLowering implements ElementConverter
{
    public function __construct(
        private readonly SourceBlockCreator $blocks,
        private readonly Runtime $runtime,
        private readonly HtmlTransformerSession $session,
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly RichTextMaterializer $richTextMaterializer,
        private readonly AuthoredStateButtonLowering $authoredStateButtons,
        private readonly RuntimeIslandAnalyzer $runtimeIslands,
        private readonly LayoutShellLowering $layoutShells
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
    {
        $block = $this->inlineCheckableControlBlock($element);

        return null === $block ? ConversionOutcome::unhandled() : ConversionOutcome::handled($block);
    }

    /** A bounded phrasing tree with named binary SVG controls is layout plus
     * editable text, rather than one invalid RichText run. Keep source wrappers
     * with the existing layout shell and transparent text carriers.
     */
    private function inlineCheckableControlBlock(DOMElement $element): ?array
    {
        if (!in_array(strtolower($element->tagName), array('div', 'span', 'button'), true)) return null;
        $buttons = 'button' === strtolower($element->tagName) ? array($element) : iterator_to_array($element->getElementsByTagName('button'));
        if (array() === $buttons || 16 < count($buttons) || 64 < $element->getElementsByTagName('*')->length) return null;
        foreach (SourceDom::subtreeElements($element) as $node) {
            if ($this->runtimeIslands->isRuntimeDomTarget($node) || array() !== SourceDom::eventMetadata($node) || $node->hasAttribute('data-action') || $node->hasAttribute('jsaction')) return null;
            foreach ($node->attributes as $attribute) if (str_starts_with(strtolower($attribute->name), 'data-wp-')) return null;
        }
        // Prove the complete tree before installing any retained selector paths.
        $supports = function (DOMElement $node, int $depth) use (&$supports): bool {
            if ($depth > 16) return false;
            if ('button' === strtolower($node->tagName)) return true;
            if (!in_array(strtolower($node->tagName), array('div', 'span'), true)) return false;
            foreach (SourceDom::elementElementChildren($node) as $child) {
                if ('button' === strtolower($child->tagName) || 0 < $child->getElementsByTagName('button')->length) {
                    if (!$supports($child, $depth + 1)) return false;
                } elseif (!$this->sourceElementClassifier->isInlineContentElement(strtolower($child->tagName)) || $this->richTextMaterializer->requiresHtmlFallback(SourceDom::outerHtml($child))) {
                    return false;
                }
            }
            return true;
        };
        if (!$supports($element, 0)) return null;
        $controls = array();
        foreach ($buttons as $button) {
            if ('checkbox' !== SourceDom::role($button) || '' === trim(SourceDom::attr($button, 'aria-label') . SourceDom::attr($button, 'title')) || $this->runtimeIslands->isRuntimeDomTarget($button)) return null;
            $block = $this->authoredStateButtons->fromElement($button);
            if (null === $block) return null;
            $controls[$button->getNodePath()] = $block;
        }
        $build = function (DOMElement $node) use (&$build, $controls): array {
            if (isset($controls[$node->getNodePath()])) return $controls[$node->getNodePath()];
            $children = array(); $text = '';
            $flush = function () use (&$children, &$text): void {
                if ('' !== trim($text)) $children[] = $this->blocks->createBlock('core/paragraph', array('content' => $text, 'className' => AuthorStylesheetProjector::INLINE_LAYOUT_CARRIER_CLASS));
                $text = '';
            };
            foreach ($node->childNodes as $child) {
                if ($child instanceof \DOMText) { $text .= $this->runtime->escapeHtml($child->textContent); continue; }
                if (!$child instanceof DOMElement) continue;
                if ('button' === strtolower($child->tagName) || 0 < $child->getElementsByTagName('button')->length) {
                    $flush();
                    $children[] = $build($child);
                } else {
                    $content = $this->richTextMaterializer->content($child);
                    $flush();
                    $this->session->authorSelectorProjectionState()->markRetainedSourcePath($child->getNodePath());
                    $children[] = $this->layoutShells->blockForElements(array($child), array($this->blocks->createBlock('core/paragraph', array('content' => $content, 'className' => AuthorStylesheetProjector::INLINE_LAYOUT_CARRIER_CLASS))), $child);
                }
            }
            $flush();
            $this->session->authorSelectorProjectionState()->markRetainedSourcePath($node->getNodePath());
            return $this->layoutShells->blockForElements(array($node), $children, $node);
        };
        return $build($element);
    }
}
