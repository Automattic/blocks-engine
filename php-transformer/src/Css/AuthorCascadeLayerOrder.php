<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

use Automattic\BlocksEngine\PhpTransformer\Css\CssSyntaxScanner;

/**
 * Reads the cascade-layer order an author stylesheet establishes for itself.
 *
 * A layer's precedence comes from where its name is first registered, not from
 * where its rules appear. An author stylesheet that registers `base` before
 * `utilities` is stating that utilities win; that ordering only survives while
 * the author stylesheet is the first thing to name those layers.
 *
 * Engine support CSS is free to join an author layer, but doing so registers
 * the name. Whenever the support stylesheet is parsed first — the block editor
 * injects author CSS through `block_editor_settings_all` after the enqueued
 * editor styles — that registration reverses the author's intended order and
 * the author's own reset layer starts beating its utilities.
 *
 * A wholly named order can be pinned with a leading statement. Anonymous block
 * or import layers own unnameable positions: a partial named bootstrap would
 * reorder those positions, so their original authored stream establishes order.
 */
final class AuthorCascadeLayerOrder
{
    /** Bounds the emitted statement against pathological input. */
    private const MAX_LAYERS = 64;

    /** A complete named order, or '' when anonymous positions cannot be named. */
    public function statement(string $stylesheet): string
    {
        $order = $this->layerOrder($stylesheet);
        return $order['anonymous'] || array() === $order['names'] ? '' : '@layer ' . implode(',', $order['names']) . ';';
    }

    /**
     * Top-level layer names in registration order.
     *
     * Only top-level at-rules register a top-level name: `@layer b` nested
     * inside `@layer a` registers `a.b`, which inherits `a`'s position and
     * cannot reorder anything on its own.
     *
     * @return list<string>
     */
    public function names(string $stylesheet): array
    {
        return $this->layerOrder($stylesheet)['names'];
    }

    /** @return array{names:list<string>, anonymous:bool} */
    private function layerOrder(string $stylesheet): array
    {
        $css = preg_replace('#/\*.*?\*/#s', '', $stylesheet) ?? $stylesheet;
        $length = strlen($css);
        // Block nesting is tracked through the shared scanner so quoted strings,
        // parentheses, brackets and CSS escapes cannot be mistaken for structure.
        // A selector may legally escape the very characters that delimit a block
        // — Tailwind arbitrary-value utilities do it constantly
        // (`.w-\[calc\(100\%\)\]`, `.a\{b`) — and counting raw braces desynchronised
        // the depth, so a single escaped brace anywhere above the `@layer`
        // statement hid it completely. names() then returned nothing, statement()
        // emitted nothing, and the layer-order pin this class exists to produce
        // silently stopped applying to exactly the stylesheets that need it.
        $state = CssSyntaxScanner::state();
        $depth = 0;
        $names = array();
        $anonymous = false;
        $cursor = 0;

        while ($cursor < $length) {
            $character = $css[$cursor];

            if (CssSyntaxScanner::isTopLevel($state)) {
                if ('{' === $character) {
                    ++$depth;
                    ++$cursor;
                    continue;
                }
                if ('}' === $character) {
                    $depth = max(0, $depth - 1);
                    ++$cursor;
                    continue;
                }
                if (0 === $depth && '@' === $character && 0 === substr_compare($css, '@layer', $cursor, 6, true)) {
                    $span = strcspn($css, '{;', $cursor);
                    $prelude = substr($css, $cursor + 6, $span - 6);
                    $anonymous = $anonymous || '' === trim($prelude);
                    foreach ($this->preludeNames($prelude) as $name) {
                        if (count($names) < self::MAX_LAYERS && ! in_array($name, $names, true)) {
                            $names[] = $name;
                        }
                    }
                    // Land on the terminator so `{` still opens a block for the depth counter.
                    $cursor += $span;
                    continue;
                }
                if (0 === $depth && '@' === $character && 0 === substr_compare($css, '@import', $cursor, 7, true)) {
                    $end = $cursor;
                    $importState = CssSyntaxScanner::state();
                    while ($end < $length && !(CssSyntaxScanner::isTopLevel($importState) && ';' === $css[$end])) {
                        $end = CssSyntaxScanner::consume($css, $end, $importState) ?? ($end + 1);
                    }
                    // The URL is one lexical token; only subsequent import
                    // qualifiers can register a layer, never a quoted URL.
                    $qualifiers = array_slice(CssValueSplitter::splitTopLevelWhitespace(substr($css, $cursor + 7, $end - $cursor - 7)), 1);
                    foreach ($qualifiers as $qualifier) {
                        if ('layer' === strtolower($qualifier)) $anonymous = true;
                        if (preg_match('/^layer\((.*)\)$/i', $qualifier, $match)) {
                            foreach ($this->preludeNames($match[1]) as $name) {
                                if (count($names) < self::MAX_LAYERS && !in_array($name, $names, true)) $names[] = $name;
                            }
                        }
                    }
                    $cursor = $end;
                    continue;
                }
            }

            $cursor = CssSyntaxScanner::consume($css, $cursor, $state) ?? ($cursor + 1);
        }

        return array('names' => $names, 'anonymous' => $anonymous);
    }

    /**
     * Top-level names declared by one `@layer` prelude.
     *
     * An empty prelude is an anonymous layer: it registers a position no later
     * rule can name, so it cannot participate in an ordering statement. A
     * prelude that is not a well-formed name list is left alone rather than
     * guessed at.
     *
     * @return list<string>
     */
    private function preludeNames(string $prelude): array
    {
        $prelude = trim($prelude);
        if ('' === $prelude) {
            return array();
        }

        $names = array();
        foreach (explode(',', $prelude) as $candidate) {
            $candidate = strtolower(trim($candidate));
            if (1 !== preg_match('/^[a-z0-9_-]+(?:\.[a-z0-9_-]+)*$/', $candidate)) {
                return array();
            }
            $top = strstr($candidate, '.', true);
            $names[] = false === $top ? $candidate : $top;
        }

        return $names;
    }
}
