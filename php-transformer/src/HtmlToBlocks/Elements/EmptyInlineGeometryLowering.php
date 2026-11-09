<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\EmptyElementFootprint;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/**
 * Lowers an empty inline tree whose source CSS still generates a line box or
 * flex track: layout, not text and not a raw HTML island, kept in the
 * existing layout shell.
 */
final class EmptyInlineGeometryLowering
{
    public function __construct(
        private readonly SourceBlockCreator $blocks,
        private readonly HtmlTransformerSession $session,
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly EmptyElementFootprint $emptyElementFootprint,
        private readonly AuthoredStateButtonLowering $authoredStateButtons,
        private readonly LayoutShellLowering $layoutShells,
        private readonly RuntimeIslandAnalyzer $runtimeIslands
    ) {
    }

    /** @return array<string, mixed> */
    private function preservedSourceControlBlock(DOMElement $element): array
    {
        return $this->authoredStateButtons->fromElement($element) ?? $this->blocks->htmlPreservationBlock($element);
    }

    /**
     * An empty inline tree whose source CSS still generates a line box or flex
     * track is layout, not text and not a raw HTML island. core/group cannot
     * keep the source phrasing tags, so the existing layout shell does.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    public function emptyInlineGeometryBlock(DOMElement $element, array &$fallbacks): ?array
    {
        if ( $this->hasPaintedSourceText($element) || $this->emptyElementFootprint->subtreeHasPaintedPseudo($element, $this->session->sourceStyleResolutionState()->pseudoElementRules()) || $this->runtimeIslands->isRuntimeDomTarget($element) ) {
            return null;
        }
        $inner = array();
        $layout = false;
        foreach ( SourceDom::elementElementChildren($element) as $child ) {
            if ( FormControlClassifier::isControlElement($child) ) {
                $inner[] = $this->preservedSourceControlBlock($child);
                continue;
            }
            if ( ! $this->isEmptyInlineGeometryNode($child) || $this->containsExcludedMedia($child) ) {
                return null;
            }
            $layout = $layout || $this->emptyElementFootprint->declaresEmptyInlineLayoutBox($child);
            $inner[] = $this->emptyInlineGeometryShell($child);
        }
        if ( ! $layout || array() === $inner ) {
            return null;
        }

        return $this->layoutShells->blockForElements(array( $element ), $inner, $element);
    }

    private function emptyInlineGeometryShell(DOMElement $element): array
    {
        $wrappers = array( $element );
        $branch = $element;
        while ( true ) {
            $children = SourceDom::elementElementChildren($branch);
            if ( 1 !== count($children) || ! $this->isEmptyInlineGeometryNode($children[0]) ) {
                break;
            }
            $child = $children[0];
            $wrappers[] = $child;
            $branch = $child;
            if ( count(SourceDom::elementElementChildren($child)) > 1 ) {
                break;
            }
        }
        $inner = array();
        foreach ( SourceDom::elementElementChildren($branch) as $child ) {
            if ( $child === (array() === $wrappers ? null : $wrappers[array_key_last($wrappers)]) || ! $this->isEmptyInlineGeometryNode($child) ) {
                continue;
            }
            $inner[] = count(SourceDom::elementElementChildren($child)) > 1
                ? $this->emptyInlineGeometryShell($child)
                : $this->layoutShells->blockForElements($this->emptyInlineWrapperChain($child), array(), $child);
        }

        return $this->layoutShells->blockForElements($wrappers, $inner, $element);
    }

    /**
     * @return list<DOMElement>
     */
    private function emptyInlineWrapperChain(DOMElement $element): array
    {
        $chain = array( $element );
        $cursor = $element;
        while ( true ) {
            $children = SourceDom::elementElementChildren($cursor);
            if ( 1 !== count($children) || ! $this->isEmptyInlineGeometryNode($children[0]) ) {
                break;
            }
            $child = $children[0];
            if ( '' === trim(SourceDom::attr($child, 'class')) && '' === trim(SourceDom::attr($child, 'style')) && 0 === $child->childElementCount ) {
                break;
            }
            $chain[] = $child;
            $cursor = $child;
        }

        return $chain;
    }

    private function isEmptyInlineGeometryNode(DOMElement $element): bool
    {
        return $this->sourceElementClassifier->isInlineContentElement(strtolower($element->tagName))
            && ! $this->hasPaintedSourceText($element)
            && ! $this->containsExcludedMedia($element)
            && ! $this->runtimeIslands->isRuntimeDomTarget($element);
    }

    private function hasPaintedSourceText(DOMElement $element): bool
    {
        if ( '' !== trim($element->textContent ?? '') ) {
            return true;
        }
        foreach ( $element->getElementsByTagName('*') as $node ) {
            if ( $node instanceof DOMElement && '' !== trim(SourceDom::attr($node, 'data-text')) ) {
                return true;
            }
        }

        return false;
    }

    private function containsExcludedMedia(DOMElement $element): bool
    {
        foreach ( array_merge(array( $element ), SourceDom::descendantElements($element)) as $node ) {
            $tag = strtolower($node->tagName);
            if ( in_array($tag, array( 'audio', 'canvas', 'iframe', 'img', 'object', 'picture', 'svg', 'video' ), true) ) {
                return true;
            }
        }

        return false;
    }
}
