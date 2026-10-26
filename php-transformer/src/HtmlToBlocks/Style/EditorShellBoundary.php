<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssSyntaxScanner;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Support\EditorInnerBlocksLayer;

/**
 * Editor-canvas restatement across a proven third-party inner-blocks layer.
 *
 * Layout shells add no layer. Jetpack contact forms do; the caller supplies
 * emitted markup so only child steps whose parent matches an emitted form
 * are relaxed. The additional arm is also scoped to that block type at
 * runtime, and the layer itself cannot match a shell wrapper.
 *
 * Restating every placement as its own selector multiplies a selector by
 * 2^k for k boundaries. A nested rule states each boundary once instead:
 *
 *     :where(:root .editor-styles-wrapper) a{&>b,&:where(<form>)>:where(<layer>)>b{…}}
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
     * The layer as a weightless compound, `:where(<layers>)`, optionally
     * extended by further layer selectors. Every translation-side consumer
     * that restates a `parent>child` step through the layer builds it here.
     */
    public static function layer(string ...$extra): string
    {
        return EditorInnerBlocksLayer::compound(...$extra);
    }

    /**
     * The editor rule for `$selector`, or null when no child combinator of
     * it can cross a proven third-party layer.
     *
     * @return array{prelude: string, body: string}|null
     */
    public static function rule(string $selector, string $body, string $markup = ''): ?array
    {
        if ( ! str_contains($markup, '<!-- wp:jetpack/contact-form') ) return null;
        $segments = self::segments(trim($selector), $markup);
        if ( null === $segments ) {
            return null;
        }
        $head = array_shift($segments);
        $prelude = self::EDITOR_SCOPE . ' ' . $head;
        $rule = $body;
        foreach ( array_reverse($segments) as $segment ) {
            $rule = '&>' . $segment . ',&:where(.wp-block-jetpack-contact-form)>' . self::layer() . '>' . $segment . '{' . $rule . '}';
        }

        return array( 'prelude' => $prelude, 'body' => $rule );
    }

    /** @param array{prelude: string, body: string}|null $rule */
    public static function css(?array $rule): string
    {
        return null === $rule ? '' : $rule['prelude'] . '{' . $rule['body'] . '}';
    }

    /**
     * The selector split at every top-level `>` that could cross a
     * third-party layer, or null when there is none or the selector is malformed.
     * Relationships below a core/navigation root are native internals, and a
     * template part hosts its blocks directly; neither ever has the layer
     * between them.
     *
     * @return list<string>|null
     */
    private static function segments(string $selector, string $markup): ?array
    {
        if ( '' === $selector || str_contains($selector, '&') ) {
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
            if ( '' === $left || str_ends_with($left, ':where(.wp-block-template-part)') || 1 === preg_match('/\.wp-block-navigation(?![\w-])/', substr($selector, 0, $offset)) ) {
                continue;
            }
            if ( ! self::matchesLayerParent(substr($selector, 0, $offset), $markup) ) {
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

    private static function matchesLayerParent(string $selector, string $markup): bool
    {
        $parsed = CssSelectorMatcher::parse(trim($selector));
        foreach ( self::layerHosts($markup) as $element ) {
            $match = CssSelectorMatcher::matches($element, $parsed);
            if ( $match['supported'] && $match['matches'] ) return true;
        }
        return false;
    }

    /**
     * Emitted elements that host a third-party inner-blocks layer, parsed
     * once per markup string.
     *
     * @return list<\DOMElement>
     */
    private static function layerHosts(string $markup): array
    {
        static $cache = array();
        $key = md5($markup);
        if ( ! isset($cache[ $key ]) ) {
            $document = new \DOMDocument();
            @$document->loadHTML($markup);
            $hosts = array();
            foreach ( ( new \DOMXPath($document) )->query('//*[contains(concat(" ", normalize-space(@class), " "), " wp-block-jetpack-contact-form ")]') ?: array() as $element ) {
                if ( $element instanceof \DOMElement ) $hosts[] = $element;
            }
            $cache = array( $key => $hosts );
        }
        return $cache[ $key ];
    }

}
