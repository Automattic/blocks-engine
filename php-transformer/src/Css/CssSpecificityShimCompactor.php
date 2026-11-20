<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;

/**
 * Writes the specificity a projected arm carries at its smallest spelling.
 *
 * Projection raises an arm's weight one simple selector at a time: each extra
 * class is a `:not(.be-c-0)` (or a repeated real class), each extra type a
 * `:not(be-t-0)`, each id a `:not(#be-i-0)`, and an engine carrier it must not
 * weigh is wrapped in `:where()`. Every arm restates them, so a projected
 * framework sheet ships the same padding thousands of times
 * (`:not(.be-c-0):not(.be-c-0):not(.be-c-0)`).
 *
 * Shims are collision-allocated names no element carries, so a negation of
 * any compound of them matches every element, and `:not()` weighs as its
 * argument. Within one compound:
 *
 * - all shim negations fold into one, `:not(be-t-0#be-i-0.be-c-0.be-c-0)`;
 * - a class the compound names twice is named once, its second weight folded
 *   in as a class shim;
 * - inside `:where()`, which weighs nothing, shims are dropped;
 * - a shim beside a one-compound `:where()` is paid by a simple selector the
 *   `:where()` already requires, named outside it instead:
 *   `:where(.role.subject.x):not(.be-c-0)` is `.x:where(.role.subject)`.
 *   The compound requires the same simple selectors, so it matches the same
 *   elements, and the lifted selector weighs what the shim did.
 *
 * Arguments of `:is()`, `:not()` and `:where()` are compacted the same way:
 * each argument keeps its matches and weight, so the list keeps both. Every
 * arm therefore matches the same elements at the same specificity. A compound
 * this reading does not fully understand (escapes, strings, namespaces,
 * nesting) is kept byte for byte, and a rewrite is only taken when shorter.
 */
final class CssSpecificityShimCompactor
{
    private const ELEMENT_PSEUDOS = array('before', 'after', 'first-line', 'first-letter');
    private const IDENT = '(?:-?[A-Za-z_]|--)[\w-]*';

    public static function stylesheet(string $css): string
    {
        if (1 !== preg_match('/:not\([.#]?' . EngineMarker::specificityPatternBody() . '\)|:where\(/', $css)) return $css;
        return (new CssStylesheetTransformer())->transform($css, static fn (string $prelude): string => self::selectorList($prelude));
    }

    public static function selectorList(string $prelude, bool $weightless = false): string
    {
        if (str_contains($prelude, '/*') || str_contains($prelude, '\\') || str_contains($prelude, '&')) return $prelude;
        $arms = CssStylesheetTransformer::splitSelectorList($prelude);
        if (null === $arms) return $prelude;
        $compacted = array();
        foreach ($arms as $arm) {
            $arm = self::complex($arm, $weightless) ?? $arm;
            // Two arms the rewrite spells alike are one arm.
            if (!in_array($arm, $compacted, true)) $compacted[] = $arm;
        }
        $result = implode(',', $compacted);
        return strlen($result) < strlen($prelude) ? $result : $prelude;
    }

    /** One complex selector, or null when it is not read whole. */
    private static function complex(string $arm, bool $weightless): ?string
    {
        $output = '';
        $compound = '';
        $length = strlen($arm);
        for ($offset = 0; $offset < $length;) {
            $character = $arm[$offset];
            if ('(' === $character || '[' === $character) {
                $close = CssSyntaxScanner::matchingClose($arm, $offset);
                if (null === $close) return null;
                $compound .= substr($arm, $offset, $close - $offset + 1);
                $offset = $close + 1;
                continue;
            }
            if (CssSyntaxScanner::isCssWhitespace($character) || '>' === $character || '+' === $character || '~' === $character) {
                $combinator = '';
                while ($offset < $length && (CssSyntaxScanner::isCssWhitespace($arm[$offset]) || in_array($arm[$offset], array('>', '+', '~'), true))) $combinator .= $arm[$offset++];
                if ('' !== $compound) {
                    $compacted = self::compound($compound, $weightless);
                    if (null === $compacted) return null;
                    $output .= $compacted;
                    $compound = '';
                }
                $output .= $combinator;
                continue;
            }
            if ('"' === $character || "'" === $character || '|' === $character) return null;
            $compound .= $character;
            ++$offset;
        }
        if ('' !== $compound) {
            $compacted = self::compound($compound, $weightless);
            if (null === $compacted) return null;
            $output .= $compacted;
        }
        return $output;
    }

    /** One compound selector, or null when it is not read whole. */
    private static function compound(string $compound, bool $weightless): ?string
    {
        $pieces = self::pieces($compound);
        if (null === $pieces) return null;
        $shim = '/^([.#]?)(' . EngineMarker::specificityPatternBody() . ')$/D';
        $shims = array('type' => array(), 'id' => array(), 'class' => array());
        $kept = array();
        $element = false;
        foreach ($pieces as $piece) {
            if ('element' === $piece['kind']) $element = true;
            if (!$element && 'pseudo' === $piece['kind'] && in_array($piece['name'], array('is', 'not', 'where'), true)) {
                if ('not' === $piece['name'] && 1 === preg_match($shim, $piece['args'], $match)) {
                    $shims['#' === $match[1] ? 'id' : ('.' === $match[1] ? 'class' : 'type')][] = $match[2];
                    continue;
                }
                $piece['args'] = self::selectorList($piece['args'], $weightless || 'where' === $piece['name']);
                $piece['text'] = ':' . $piece['name'] . '(' . $piece['args'] . ')';
            }
            $kept[] = $piece;
        }
        $pieces = $kept;
        if ($weightless) {
            $shims = array('type' => array(), 'id' => array(), 'class' => array());
            if (array() === $pieces) return $compound;
        }

        // Classes the compound requires outside any argument.
        $classes = array();
        $kept = array();
        $element = false;
        foreach ($pieces as $piece) {
            if ('element' === $piece['kind']) $element = true;
            if (!$element && 'class' === $piece['kind']) {
                if (isset($classes[$piece['text']])) {
                    // A repeated class weighs one more class; a shim names it shorter.
                    $name = $shims['class'][0] ?? null;
                    if ($weightless) continue;
                    if (null !== $name && strlen('.' . $name) < strlen($piece['text'])) { $shims['class'][] = $name; continue; }
                }
                $classes[$piece['text']] = true;
            }
            $kept[] = $piece;
        }
        $pieces = $kept;
        if (!$weightless) $pieces = self::withLiftedWeight($pieces, $shims, $classes);

        if (array() === $pieces && array() === $shims['type'] && array() === $shims['id'] && array() === $shims['class']) return $compound;

        $head = $tail = '';
        $element = false;
        foreach ($pieces as $piece) {
            if ('element' === $piece['kind']) $element = true;
            if ('type' === $piece['kind']) $head = $piece['text'] . $head;
            elseif ($element) $tail .= $piece['text'];
            else $head .= $piece['text'];
        }
        $folded = array_shift($shims['type']) ?? '';
        foreach ($shims['id'] as $id) $folded .= '#' . $id;
        foreach ($shims['class'] as $class) $folded .= '.' . $class;
        if ('' !== $folded) $head .= ':not(' . $folded . ')';
        foreach ($shims['type'] as $type) $head .= ':not(' . $type . ')';
        $result = $head . $tail;
        return strlen($result) <= strlen($compound) ? $result : $compound;
    }

    /**
     * Pays class and type shims with the classes and type a one-compound
     * `:where()` of the same compound requires: `:where(.a.b):not(.be-c-0)`
     * becomes `.b:where(.a)`, `:where(figure):not(be-t-0)` becomes `figure`.
     * A class the compound already names outside is not lifted (it would
     * weigh twice), nor a type beside another one.
     *
     * @param list<array{kind: string, text: string, name?: string, args?: string}> $pieces
     * @param array{type: list<string>, id: list<string>, class: list<string>} $shims
     * @param array<string,true> $classes
     * @return list<array{kind: string, text: string, name?: string, args?: string}>
     */
    private static function withLiftedWeight(array $pieces, array &$shims, array $classes): array
    {
        if (array() === $shims['class'] && array() === $shims['type']) return $pieces;
        $typed = isset($pieces[0]) && 'type' === $pieces[0]['kind'];
        $result = array();
        $element = false;
        foreach ($pieces as $piece) {
            if ('element' === $piece['kind']) $element = true;
            if ($element || 'pseudo' !== $piece['kind'] || 'where' !== $piece['name'] || (array() === $shims['class'] && (array() === $shims['type'] || $typed))) {
                $result[] = $piece;
                continue;
            }
            $inner = self::pieces(trim((string) $piece['args']));
            if (null === $inner || array() === $inner || in_array('element', array_column($inner, 'kind'), true)) {
                $result[] = $piece;
                continue;
            }
            // The last classes are lifted first: a carrier's own subject
            // class follows the role classes it is scoped by.
            $lift = array();
            foreach (array_reverse($inner, true) as $index => $simple) {
                if ('class' === $simple['kind'] && array() !== $shims['class'] && !isset($classes[$simple['text']])) {
                    array_pop($shims['class']);
                    $classes[$simple['text']] = true;
                    $lift[$index] = true;
                } elseif ('type' === $simple['kind'] && '*' !== $simple['text'] && !$typed && array() !== $shims['type']) {
                    array_pop($shims['type']);
                    $typed = true;
                    $lift[$index] = true;
                }
            }
            $lifted = array();
            $rest = array();
            foreach ($inner as $index => $simple) {
                if (isset($lift[$index])) $lifted[] = $simple;
                elseif ('type' !== $simple['kind'] || '*' !== $simple['text'] || array() === $lift) $rest[] = $simple;
            }
            if (array() === $lifted) {
                $result[] = $piece;
                continue;
            }
            foreach ($lifted as $simple) $result[] = $simple;
            if (array() !== $rest) {
                $args = implode('', array_column($rest, 'text'));
                $result[] = array('kind' => 'pseudo', 'text' => ':where(' . $args . ')', 'name' => 'where', 'args' => $args);
            }
        }
        return $result;
    }

    /**
     * Simple selectors of a compound, in order, or null when one is not read.
     *
     * @return list<array{kind: string, text: string, name?: string, args?: string}>|null
     */
    private static function pieces(string $compound): ?array
    {
        $pieces = array();
        $length = strlen($compound);
        $offset = 0;
        if (1 === preg_match('/\G(?:[A-Za-z][\w-]*|\*)/', $compound, $match, 0, $offset)) {
            $pieces[] = array('kind' => 'type', 'text' => $match[0]);
            $offset += strlen($match[0]);
        }
        while ($offset < $length) {
            $character = $compound[$offset];
            if ('.' === $character || '#' === $character) {
                if (1 !== preg_match('/\G[.#]' . self::IDENT . '/', $compound, $match, 0, $offset)) return null;
                $pieces[] = array('kind' => '.' === $character ? 'class' : 'id', 'text' => $match[0]);
                $offset += strlen($match[0]);
                continue;
            }
            if ('[' === $character) {
                $close = CssSyntaxScanner::matchingClose($compound, $offset);
                if (null === $close) return null;
                $pieces[] = array('kind' => 'attribute', 'text' => substr($compound, $offset, $close - $offset + 1));
                $offset = $close + 1;
                continue;
            }
            if (':' !== $character || 1 !== preg_match('/\G(::?)(' . self::IDENT . ')/', $compound, $match, 0, $offset)) return null;
            $name = strtolower($match[2]);
            $text = $match[0];
            $offset += strlen($match[0]);
            $args = null;
            if ($offset < $length && '(' === $compound[$offset]) {
                $close = CssSyntaxScanner::matchingClose($compound, $offset);
                if (null === $close) return null;
                $args = substr($compound, $offset + 1, $close - $offset - 1);
                $text .= '(' . $args . ')';
                $offset = $close + 1;
            }
            $element = '::' === $match[1] || (null === $args && in_array($name, self::ELEMENT_PSEUDOS, true));
            $piece = array('kind' => $element ? 'element' : 'pseudo', 'text' => $text, 'name' => $name);
            if (null !== $args) $piece['args'] = $args;
            $pieces[] = $piece;
        }
        return $pieces;
    }
}
