<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

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
 *   text is entirely one sized `<font>` run, the size is lifted onto the block
 *   (for a cell, onto a wrapper of its content): the block's strut then matches
 *   the run, and the lines keep their source height.
 * - A table does not inherit `text-align`: an aligned wrapper places the table
 *   box, while the text inside starts at the start edge.
 */
final class QuirksModeSource
{
    private const SIZES = array( 1 => '10px', 2 => '13px', 3 => '16px', 4 => '18px', 5 => '24px', 6 => '32px', 7 => '48px' );

    private const BLOCKS = array( 'p', 'td', 'th', 'div', 'center', 'li', 'dd', 'dt', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' );

    public static function isQuirksMode(string $html): bool
    {
        return 1 === preg_match('/<html\b[^>]*\s' . preg_quote(CaptureEvidence::COMPAT_MODE, '/') . '\s*=\s*(["\']?)BackCompat\1/i', $html);
    }

    public static function normalize(DOMElement $root): void
    {
        foreach ( $root->getElementsByTagName('table') as $table ) {
            $style = trim($table->getAttribute('style'));
            if ( 1 !== preg_match('/(?:^|;)\s*text-align\s*:/i', $style) ) {
                $table->setAttribute('style', 'text-align:start' . ('' === $style ? '' : ';' . $style));
            }
        }
        self::liftFontRuns($root);
    }

    private static function liftFontRuns(DOMElement $root): void
    {
        $fonts = array();
        foreach ( $root->getElementsByTagName('font') as $font ) {
            $fonts[] = $font;
        }
        foreach ( $fonts as $font ) {
            $block = $font->parentNode;
            $size = self::size($font->getAttribute('size'));
            if ( '' === $size || ! $block instanceof DOMElement || ! in_array(strtolower($block->tagName), self::BLOCKS, true) || ! self::isSoleRun($font, $block) ) {
                continue;
            }
            $style = trim($block->getAttribute('style'));
            if ( 1 === preg_match('/(?:^|;)\s*font(?:-size)?\s*:/i', $style) ) {
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
            }
            if ( ! $font->hasAttributes() ) {
                while ( null !== $font->firstChild ) {
                    $block->insertBefore($font->firstChild, $font);
                }
                $block->removeChild($font);
            }
        }
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

    /** Whether the font run holds all of the block's text; whitespace beside it shrinks with it. */
    private static function isSoleRun(DOMElement $font, DOMElement $block): bool
    {
        foreach ( $block->childNodes as $child ) {
            if ( $child === $font || XML_COMMENT_NODE === $child->nodeType ) {
                continue;
            }
            if ( ! $child instanceof DOMText || 1 !== preg_match('/^[\s\x{00A0}\x{3000}]*$/uD', $child->data) ) {
                return false;
            }
        }
        return 1 === preg_match('/[^\s\x{00A0}\x{3000}]/u', $font->textContent);
    }
}
