<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorTokenizer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use DOMElement;
use DOMXPath;

/**
 * Keep authored child combinators live across an adopted dialog's wrapper.
 *
 * A source panel adopted in place with its resting presentation is wrapped
 * whole in a box-less (`display:contents`) dialog element. The wrapper adds no
 * box, but it does add a tree level: a rule such as `.menus > .panel` that
 * rested the panel closed no longer reaches it, and the closed panel renders
 * as page content. Each child combinator whose parent side names a wrapper's
 * parent gains an alternative that steps through the wrapper. `:where()` keeps
 * the authored specificity; the original arm stays for unwrapped subjects.
 * Wrapping the panel does not break descendant combinators, which still reach it.
 */
final class CapturedDialogWrapperSelectorProjection
{
    private const WRAPPER = '[data-blocks-engine-resting]';

    public static function stylesheet(string $css, DOMElement $sourceBody): string
    {
        if ( ! str_contains($css, '>') || null === $sourceBody->ownerDocument ) return $css;
        $parents = array();
        foreach ( (new DOMXPath($sourceBody->ownerDocument))->query('.//div[@data-blocks-engine-resting][@data-blocks-engine-captured-dialog="true"]', $sourceBody) ?: array() as $wrapper ) {
            if ( $wrapper->parentNode instanceof DOMElement ) $parents[spl_object_id($wrapper->parentNode)] = $wrapper->parentNode;
        }
        if ( array() === $parents ) return $css;

        return (new CssStylesheetTransformer())->transformStyleRules($css, static function (string $prelude, string $body) use ($parents): string {
            $arms = CssStylesheetTransformer::splitSelectorList($prelude);
            if ( null === $arms ) return $prelude . '{' . $body . '}';
            $relaxed = array();
            foreach ( $arms as $arm ) {
                if ( ! str_contains($arm, '>') || str_contains($arm, '&') ) continue;
                $tokens = CssSelectorTokenizer::tokenize($arm);
                if ( ! $tokens['supported'] ) continue;
                foreach ( $tokens['combinators'] as $index => $combinator ) {
                    if ( '>' !== $combinator ) continue;
                    // The panel is the wrapper's only child, so sibling-indexed
                    // pseudo-classes on the stepped-into compound would read
                    // different siblings than in the source.
                    if ( 1 === preg_match('/:(?:first|last|only|nth)-/i', (string) $tokens['compounds'][$index + 1]) ) continue;
                    $prefix = substr($arm, 0, (int) $tokens['compound_spans'][$index]['end']);
                    $parsed = CssSelectorMatcher::parse(trim($prefix));
                    foreach ( $parents as $parent ) {
                        if ( ! CssSelectorMatcher::matches($parent, $parsed, true)['matches'] ) continue;
                        $relaxed[] = $prefix . '>:where(' . self::WRAPPER . ')>' . substr($arm, (int) $tokens['compound_spans'][$index + 1]['start']);
                        break;
                    }
                }
            }
            return array() === $relaxed ? $prelude . '{' . $body . '}' : implode(',', array_merge($arms, $relaxed)) . '{' . $body . '}';
        });
    }
}
