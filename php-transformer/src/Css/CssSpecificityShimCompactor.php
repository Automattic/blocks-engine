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
 * - a class `:where()` restates beside the same class outside it is dropped
 *   from the `:where()`;
 * - a `:where()` of classes (or a type) that shims already pay for is
 *   unwrapped: `:where(.m):not(.be-c-0)` is `.m`, `:where(figure):not(be-t-0)`
 *   is `figure`;
 * - inside `:where()`, which weighs nothing, shims are dropped.
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

        $hasType = array() !== array_filter($pieces, static fn (array $piece): bool => 'type' === $piece['kind']);
        $kept = array();
        $element = false;
        foreach ($pieces as $piece) {
            if ('element' === $piece['kind']) $element = true;
            if ($element || 'pseudo' !== $piece['kind'] || 'where' !== $piece['name'] || 1 !== preg_match('/^([A-Za-z][\w-]*)?((?:\.' . self::IDENT . ')*)$/D', $piece['args'], $match)) {
                $kept[] = $piece;
                continue;
            }
            $type = $match[1] ?? '';
            preg_match_all('/\.' . self::IDENT . '/', $match[2], $named);
            $inner = array_values(array_filter(array_unique($named[0]), static fn (string $class): bool => !isset($classes[$class])));
            if ('' !== $type && !$weightless && !$hasType && array() !== $shims['type']) {
                array_shift($shims['type']);
                array_unshift($kept, array('kind' => 'type', 'text' => $type));
                $hasType = true;
                $type = '';
            }
            if (!$weightless) {
                while (array() !== $inner && array() !== $shims['class']) {
                    array_shift($shims['class']);
                    $class = array_shift($inner);
                    $classes[$class] = true;
                    $kept[] = array('kind' => 'class', 'text' => $class);
                }
            }
            if ('' === $type && array() === $inner) continue;
            $kept[] = array('kind' => 'pseudo', 'name' => 'where', 'args' => $type . implode('', $inner), 'text' => ':where(' . $type . implode('', $inner) . ')');
        }
        $pieces = $kept;
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
