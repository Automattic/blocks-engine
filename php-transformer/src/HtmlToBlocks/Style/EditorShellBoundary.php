<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssSyntaxScanner;

/**
 * Editor-canvas restatement of a selector across layout-shell layers.
 *
 * The editor renders a layout shell's inner blocks inside one Gutenberg-owned
 * layer (EngineSupportCss::LAYOUT_SHELL_EDITOR_INNER_BLOCKS_CLASS) that saved
 * markup does not have. An authored child combinator whose sides land on
 * opposite sides of that layer matches on the front end and not in the
 * editor. Every such combinator may or may not cross a layer, so the editor
 * rule makes each one optional on its own.
 *
 * Restating every placement as its own selector multiplies a selector by
 * 2^k for k boundaries. A nested rule states each boundary once instead:
 *
 *     :where(:root .editor-styles-wrapper) a{&>b,&>:where(.layer)>b{…}}
 *
 * `&` is the parent rule's selector list, so each level adds one tolerant
 * step and the rule grows linearly with k. `&` weighs what its parent
 * selector weighs and both arms of a step weigh alike, so the rule ties with
 * the authored selector; the editor scope adds nothing. The scope never
 * matches the front end, so the rule is inert outside the editor canvas.
 */
final class EditorShellBoundary
{
    public const EDITOR_SCOPE = ':where(:root .editor-styles-wrapper)';

    /**
     * The editor rule for `$selector`, or null when no child combinator of
     * it can cross a layout-shell layer.
     *
     * With `$weightless`, every compound is wrapped in `:where()` and the
     * whole rule is weighed by `$weight` (a native scope plus specificity
     * shims), which the subject compound carries before `$tail`.
     *
     * @return array{prelude: string, body: string}|null
     */
    public static function rule(string $selector, string $body, bool $weightless = false, string $weight = '', string $tail = ''): ?array
    {
        $segments = self::segments(trim($selector));
        if ( null === $segments ) {
            return null;
        }
        $head = array_shift($segments);
        $prelude = $weightless ? $weight . ':where(' . self::EDITOR_SCOPE . ' ' . $head . ')' : self::EDITOR_SCOPE . ' ' . $head;
        $last = count($segments) - 1;
        $rule = $body;
        foreach ( array_reverse($segments, true) as $index => $segment ) {
            $segment = ( $weightless ? self::weightless($segment) : $segment ) . ( $index === $last ? $tail : '' );
            $rule = '&>' . $segment . ',&>:where(.' . EngineSupportCss::LAYOUT_SHELL_EDITOR_INNER_BLOCKS_CLASS . ')>' . $segment . '{' . $rule . '}';
        }

        return array( 'prelude' => $prelude, 'body' => $rule );
    }

    /** @param array{prelude: string, body: string}|null $rule */
    public static function css(?array $rule): string
    {
        return null === $rule ? '' : $rule['prelude'] . '{' . $rule['body'] . '}';
    }

    /**
     * The selector split at every top-level `>` that could cross a layout
     * shell layer, or null when there is none or the selector is malformed.
     * Combinators whose child side addresses native block internals
     * (`:where(.wp-block-…)`, or anything below a core/navigation root) are
     * relationships inside one block, and a
     * template part hosts its blocks directly; neither ever has the layer
     * between them.
     *
     * @return list<string>|null
     */
    private static function segments(string $selector): ?array
    {
        if ( '' === $selector || str_contains($selector, EngineSupportCss::LAYOUT_SHELL_EDITOR_INNER_BLOCKS_CLASS) || str_contains($selector, '&') ) {
            return null;
        }
        $offsets = array();
        $state = CssSyntaxScanner::state();
        $length = strlen($selector);
        for ( $index = 0; $index < $length; ++$index ) {
            $next = CssSyntaxScanner::consume($selector, $index, $state);
            if ( null === $next ) {
                return null;
            }
            if ( CssSyntaxScanner::isTopLevel($state) && $next === $index + 1 && '>' === $selector[ $index ] ) {
                $offsets[] = $index;
            }
            $index = $next - 1;
        }
        if ( ! CssSyntaxScanner::isComplete($state) ) {
            return null;
        }
        $segments = array();
        $cursor = 0;
        foreach ( $offsets as $offset ) {
            $left = trim(substr($selector, $cursor, $offset - $cursor));
            $right = ltrim(substr($selector, $offset + 1));
            if ( '' === $left || str_ends_with($left, ':where(.wp-block-template-part)') || str_starts_with($right, ':where(.wp-block-') || 1 === preg_match('/\.wp-block-navigation(?![\w-])/', substr($selector, 0, $offset)) ) {
                continue;
            }
            $segments[] = $left;
            $cursor = $offset + 1;
        }
        if ( array() === $segments ) {
            return null;
        }
        $segments[] = trim(substr($selector, $cursor));

        return $segments;
    }

    /**
     * The segment with every compound wrapped in `:where()`, its combinators
     * kept in place.
     */
    private static function weightless(string $segment): string
    {
        $state = CssSyntaxScanner::state();
        $length = strlen($segment);
        $output = '';
        $compound = '';
        for ( $index = 0; $index < $length; ++$index ) {
            $topLevel = CssSyntaxScanner::isTopLevel($state);
            $next = CssSyntaxScanner::consume($segment, $index, $state) ?? $length;
            $character = $segment[ $index ];
            if ( $topLevel && $next === $index + 1 && ( CssSyntaxScanner::isCssWhitespace($character) || in_array($character, array( '>', '+', '~' ), true) ) ) {
                $output .= ( '' === $compound ? '' : ':where(' . $compound . ')' ) . $character;
                $compound = '';
            } else {
                $compound .= substr($segment, $index, $next - $index);
            }
            $index = $next - 1;
        }

        return $output . ( '' === $compound ? '' : ':where(' . $compound . ')' );
    }
}
