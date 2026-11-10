<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\Css\CssLength;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use DOMElement;

final class StandaloneBreakLowering
{
    public function __construct(
        private readonly SourceBlockCreator $blocks,
        private readonly StyleResolver $styleResolver
    ) {
    }

    public function blocksFromBreak(DOMElement $break): ?array
    {
        $parent = $break->parentNode;
        if ( ! $parent instanceof DOMElement ) return null;
        $display = strtolower($this->styleResolver->resolvedPresentationDeclarations($parent)['display'] ?? 'block');
        if ( in_array($display, array( 'flex', 'inline-flex', 'grid', 'inline-grid' ), true) ) return null;

        $first = $break;
        $count = 0;
        $bounds = array();
        foreach ( array( 'previousSibling', 'nextSibling' ) as $direction ) {
            $node = $break->$direction;
            while ( null !== $node ) {
                if ( XML_COMMENT_NODE === $node->nodeType || (XML_TEXT_NODE === $node->nodeType && '' === trim($node->textContent ?? '')) ) {
                    $node = $node->$direction;
                    continue;
                }
                if ( $node instanceof DOMElement && 'br' === strtolower($node->tagName) ) {
                    if ( 'previousSibling' === $direction ) $first = $node;
                    else ++$count;
                    $node = $node->$direction;
                    continue;
                }
                break;
            }
            $bounds[] = $node;
        }
        foreach ( $bounds as $bound ) {
            if ( ! $bound instanceof DOMElement ) return null;
            $boundDisplay = strtolower($this->styleResolver->resolvedPresentationDeclarations($bound)['display'] ?? '');
            if ( '' === $boundDisplay ) {
                if ( ! in_array(strtolower($bound->tagName), array( 'address', 'article', 'aside', 'blockquote', 'div', 'dl', 'fieldset', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'table', 'ul' ), true) ) return null;
            } elseif ( ! in_array($boundDisplay, array( 'block', 'flow-root', 'flex', 'grid', 'table', 'list-item' ), true) ) return null;
        }
        if ( $first !== $break ) return array();
        ++$count;

        $leading = $this->styleResolver->authoredInheritedPropertyWinner($parent, 'line-height');
        $font = $this->styleResolver->authoredInheritedPropertyWinner($parent, 'font-size');
        $root = $this->styleResolver->documentRootFontSize($parent) ?? 16.0;
        $fontPx = '' === $font ? 16.0 : CssLength::toPx($font, $root);
        $height = CssLength::toPx($leading, $root, $fontPx);
        if ( null !== $fontPx && is_numeric($leading) ) $height = (float) $leading * $fontPx;
        if ( null !== $fontPx && 1 === preg_match('/^([\d.]+)%$/', $leading, $match) ) $height = (float) $match[1] * $fontPx / 100;
        if ( null !== $height && $height >= 0 ) {
            return array($this->blocks->createBlock('core/spacer', array('height' => CssLength::format($height * $count))));
        }
        $blocks = array();
        for ( $i = 0; $i < $count; ++$i ) $blocks[] = $this->blocks->createBlock('core/paragraph', array('content' => ''));
        return $blocks;
    }
}
