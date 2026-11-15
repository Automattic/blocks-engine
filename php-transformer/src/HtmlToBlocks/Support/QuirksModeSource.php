<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use DOMElement;
use DOMText;

/**
 * Quirks-mode rendering of a source, restated for a standards-mode page.
 *
 * Capture records `data-blocks-engine-capture-compat-mode="BackCompat"` on the
 * root of a source that rendered in quirks mode. WordPress pages render in
 * standards mode, so the quirks that shaped the source are written out as
 * plain styles before conversion:
 *
 * - A line holding only a smaller `<font size>` run gets no strut from its
 *   block's larger font, so the lines are as tight as the run. When a block's
 *   text is one sized `<font>` run, the size is lifted onto the block (for a
 *   cell, onto a wrapper of the run): the block's strut then matches the run,
 *   and the lines keep their source height. Text beside the run (`　<font>`)
 *   does give its line a full strut, so it keeps the default size it had.
 * - Beyond that, a cell or `body` whose lines hold smaller `<font size>`
 *   runs among its own text keeps the run's line height there too (the
 *   "line height calculation quirk"): the container's strut is dropped
 *   (`line-height:0` on a carrier, as for a lifted run), and its own content
 *   keeps `line-height:normal`, its loose text in a span. A line with the
 *   container's own text still gets its full height, a run's line no more
 *   than the run's. Author line heights on the way are left alone.
 * - A table does not inherit `text-align`: an aligned wrapper places the table
 *   box, while the text inside starts at the start edge.
 * - The default margins of a block at the edge of `body` or a cell are dropped
 *   (HTML "margin-collapsing quirks"): the first one loses its top margin, and
 *   a cell's last paragraph its bottom margin. These are user-agent rules, so
 *   an author rule that sets the margin still wins.
 */
final class QuirksModeSource
{
    private const SIZES = array( 1 => '10px', 2 => '13px', 3 => '16px', 4 => '18px', 5 => '24px', 6 => '32px', 7 => '48px' );

    /** Elements with default margins, per the HTML rendering rules. */
    private const MARGINED = array( 'blockquote', 'dir', 'dl', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'listing', 'menu', 'ol', 'p', 'plaintext', 'pre', 'ul', 'xmp' );

    private const BLOCKS = array( 'p', 'td', 'th', 'div', 'center', 'li', 'dd', 'dt', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' );

    public static function isQuirksMode(string $html): bool
    {
        return 1 === preg_match('/<html\b[^>]*\s' . preg_quote(CaptureEvidence::COMPAT_MODE, '/') . '\s*=\s*(["\']?)BackCompat\1/i', $html);
    }

    /** Size of text that no author rule, inline style or `<font>` sizes. */
    private const DEFAULT_SIZE = '16px';

    /** @param callable(DOMElement, list<string>): bool $authorDeclares Whether an author rule sets any of the properties on the element. */
    public static function normalize(DOMElement $root, callable $authorDeclares): void
    {
        foreach ( $root->getElementsByTagName('table') as $table ) {
            $style = trim($table->getAttribute('style'));
            if ( ! self::declares($style, array( 'text-align' )) ) {
                $table->setAttribute('style', 'text-align:start' . ('' === $style ? '' : ';' . $style));
            }
        }
        self::liftFontRuns($root, $authorDeclares);
        self::dropStruts($root, $authorDeclares);
        self::dropEdgeMargins($root, $authorDeclares);
    }

    /** @param callable(DOMElement, list<string>): bool $authorDeclares */
    private static function dropEdgeMargins(DOMElement $root, callable $authorDeclares): void
    {
        $containers = array( $root );
        foreach ( array( 'td', 'th' ) as $tagName ) {
            foreach ( $root->getElementsByTagName($tagName) as $cell ) {
                $containers[] = $cell;
            }
        }
        foreach ( $containers as $container ) {
            $children = array_values(array_filter(iterator_to_array($container->childNodes), self::isSubstantial(...)));
            $first = $children[0] ?? null;
            $last = $children[count($children) - 1] ?? null;
            $isCell = $container !== $root;
            if ( $first instanceof DOMElement && in_array(strtolower($first->tagName), self::MARGINED, true) ) {
                self::zeroMargin($first, 'top', $authorDeclares);
            }
            if ( $isCell && $last instanceof DOMElement && 'p' === strtolower($last->tagName) ) {
                self::zeroMargin($last, 'bottom', $authorDeclares);
            }
        }
    }

    /** @param callable(DOMElement, list<string>): bool $authorDeclares */
    private static function zeroMargin(DOMElement $element, string $side, callable $authorDeclares): void
    {
        $style = trim($element->getAttribute('style'));
        $logical = 'top' === $side ? 'margin-block-start' : 'margin-block-end';
        if ( self::declares($style, array( 'margin', 'margin-' . $side )) || $authorDeclares($element, array( 'margin', 'margin-' . $side, 'margin-block', $logical )) ) {
            return;
        }
        $element->setAttribute('style', 'margin-' . $side . ':0' . ('' === $style ? '' : ';' . $style));
    }

    /** An element, or text that is not only whitespace. */
    private static function isSubstantial(\DOMNode $node): bool
    {
        return $node instanceof DOMElement || ( $node instanceof DOMText && 1 === preg_match('/[^\s]/u', $node->data) );
    }

    /** @param callable(DOMElement, list<string>): bool $authorDeclares */
    private static function liftFontRuns(DOMElement $root, callable $authorDeclares): void
    {
        $fonts = array();
        foreach ( $root->getElementsByTagName('font') as $font ) {
            $fonts[] = $font;
        }
        foreach ( $fonts as $font ) {
            $block = $font->parentNode;
            $size = self::size($font->getAttribute('size'));
            if ( '' === $size || ! $block instanceof DOMElement || ! in_array(strtolower($block->tagName), self::BLOCKS, true) ) {
                continue;
            }
            $beside = self::textBesideRun($font, $block);
            if ( null === $beside || ( array() !== $beside && ! self::hasDefaultSize($block, $authorDeclares) ) ) {
                continue;
            }
            $style = trim($block->getAttribute('style'));
            if ( self::declares($style, array( 'font', 'font-size' )) ) {
                continue;
            }
            $font->removeAttribute('size');
            if ( in_array(strtolower($block->tagName), array( 'td', 'th' ), true) ) {
                // A cell is a table part, not a block: its own text runs in an anonymous block, so the size lands there.
                $carrier = $block->ownerDocument->createElement('div');
                $carrier->setAttribute('style', 'font-size:' . $size);
                $block->insertBefore($carrier, $font);
                $carrier->appendChild($font);
                $block = $carrier;
            } else {
                $block->setAttribute('style', 'font-size:' . $size . ('' === $style ? '' : ';' . $style));
                foreach ( $beside as $text ) {
                    $carrier = $block->ownerDocument->createElement('span');
                    $carrier->setAttribute('style', 'font-size:' . self::DEFAULT_SIZE);
                    $block->replaceChild($carrier, $text);
                    $carrier->appendChild($text);
                }
            }
            if ( ! $font->hasAttributes() ) {
                while ( null !== $font->firstChild ) {
                    $block->insertBefore($font->firstChild, $font);
                }
                $block->removeChild($font);
            }
        }
    }

    /** @param callable(DOMElement, list<string>): bool $authorDeclares */
    private static function dropStruts(DOMElement $root, callable $authorDeclares): void
    {
        $containers = array( $root );
        foreach ( array( 'td', 'th' ) as $tagName ) {
            foreach ( $root->getElementsByTagName($tagName) as $cell ) {
                $containers[] = $cell;
            }
        }
        foreach ( $containers as $container ) {
            if ( ! self::hasSmallerRun($container) || ! self::hasDefaultSize($container, $authorDeclares) || self::setsLineHeight($container, $authorDeclares) ) {
                continue;
            }
            $document = $container->ownerDocument;
            $carrier  = $document->createElement('div');
            $carrier->setAttribute('style', 'line-height:0');
            foreach ( iterator_to_array($container->childNodes) as $child ) {
                if ( $child instanceof DOMText && 1 === preg_match('/[^ \t\n\r\f]/', $child->data) ) {
                    $span = $document->createElement('span');
                    $span->setAttribute('style', 'line-height:normal');
                    $span->appendChild($child);
                    $child = $span;
                } elseif ( $child instanceof DOMElement && ! self::setsLineHeight($child, $authorDeclares, false) ) {
                    $style = trim($child->getAttribute('style'));
                    $child->setAttribute('style', 'line-height:normal' . ('' === $style ? '' : ';' . $style));
                }
                $carrier->appendChild($child);
            }
            $container->appendChild($carrier);
        }
    }

    /** Whether a `<font size>` run among the container's own content is smaller than the default size. */
    private static function hasSmallerRun(DOMElement $container): bool
    {
        foreach ( $container->childNodes as $child ) {
            $size = $child instanceof DOMElement && 'font' === strtolower($child->tagName) ? self::size($child->getAttribute('size')) : '';
            if ( '' !== $size && (int) $size < (int) self::DEFAULT_SIZE ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether an inline style or author rule sets the line height on the
     * element or, with `$inherited`, on an ancestor it inherits from.
     *
     * @param callable(DOMElement, list<string>): bool $authorDeclares
     */
    private static function setsLineHeight(DOMElement $element, callable $authorDeclares, bool $inherited = true): bool
    {
        for ( $node = $element; $node instanceof DOMElement; $node = $inherited ? $node->parentNode : null ) {
            if ( self::declares($node->getAttribute('style'), array( 'line-height', 'font' )) || $authorDeclares($node, array( 'line-height', 'font' )) ) {
                return true;
            }
        }
        return false;
    }

    /** The px size of a legacy `<font size>` value, relative values counting from the default 3. */
    private static function size(string $value): string
    {
        $value = trim($value);
        if ( 1 === preg_match('/^([+-]?)(\d)$/D', $value, $match) ) {
            $level = '' === $match[1] ? (int) $match[2] : 3 + (int) ($match[1] . $match[2]);
            return self::SIZES[min(7, max(1, $level))];
        }
        return '';
    }

    /**
     * Blank text beside the font run that still renders (`　`, `&nbsp;`), or
     * null when the run is blank or anything else sits beside it: a block with
     * its own words keeps the run as an inline mark.
     *
     * @return list<DOMText>|null
     */
    private static function textBesideRun(DOMElement $font, DOMElement $block): ?array
    {
        if ( 1 !== preg_match('/[^ \t\n\r\f]/', $font->textContent) ) {
            return null;
        }
        $beside = array();
        foreach ( $block->childNodes as $child ) {
            if ( $child === $font || XML_COMMENT_NODE === $child->nodeType ) {
                continue;
            }
            if ( ! $child instanceof DOMText || 1 !== preg_match('/^[\s\x{00A0}\x{3000}]*$/uD', $child->data) ) {
                return null;
            }
            if ( 1 === preg_match('/[^ \t\n\r\f]/', $child->data) ) {
                $beside[] = $child;
            }
        }
        return $beside;
    }

    /**
     * Whether the block's own text has the default size: no `<font size>`,
     * inline or author `font-size` on it or an ancestor up to the nearest table,
     * which in quirks mode restarts at the initial size.
     *
     * @param callable(DOMElement, list<string>): bool $authorDeclares
     */
    private static function hasDefaultSize(DOMElement $block, callable $authorDeclares): bool
    {
        for ( $node = $block; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( 'font' === strtolower($node->tagName) && '' !== trim($node->getAttribute('size')) ) {
                return false;
            }
            if ( self::declares($node->getAttribute('style'), array( 'font', 'font-size' )) || $authorDeclares($node, array( 'font', 'font-size' )) ) {
                return false;
            }
            if ( 'table' === strtolower($node->tagName) ) {
                return true;
            }
        }
        return true;
    }

    /** @param list<string> $properties */
    private static function declares(string $style, array $properties): bool
    {
        return array() !== CssRuleAnalyzer::declarations($style, $properties);
    }
}
