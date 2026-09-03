<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

/** Splits browser-loaded CSS before selector records approach engine limits. */
final class CssStylesheetChunker
{
    // Blink RuleData positions use 18 bits. Leave headroom for non-selector records.
    public const MAX_SELECTOR_RECORDS = 200000;

    /** @return list<string> */
    public function chunk(string $stylesheet, int $maxSelectorRecords = self::MAX_SELECTOR_RECORDS): array
    {
        if ($maxSelectorRecords < 1 || '' === $stylesheet) {
            return array($stylesheet);
        }

        $chunks = array();
        $content = '';
        $records = 0;
        foreach ($this->units($stylesheet, $maxSelectorRecords) as $unit) {
            if ('' !== $content && $records + $unit['records'] > $maxSelectorRecords) {
                $chunks[] = $content;
                $content = '';
                $records = 0;
            }
            $content .= $unit['css'];
            $records += $unit['records'];
        }
        if ('' !== $content || array() === $chunks) {
            $chunks[] = $content;
        }
        return $chunks;
    }

    /**
     * Return directives that each continuation stylesheet needs before its
     * first style rule. Imports deliberately stay with the first chunk: they
     * are only legal before namespace declarations and style rules.
     */
    public function continuationPreamble(string $stylesheet): string
    {
        $preamble = '';
        $offset = 0;
        while (null !== ($boundary = $this->nextBoundary($stylesheet, $offset)) && ';' === $stylesheet[$boundary]) {
            $statement = substr($stylesheet, $offset, $boundary - $offset + 1);
            if (1 === preg_match('/^\s*@(?:charset|namespace)\b/i', $statement)) {
                $preamble .= $statement;
            } elseif (!preg_match('/^\s*@import\b/i', $statement)) {
                break;
            }
            $offset = $boundary + 1;
        }
        return $preamble;
    }

    /** @return list<array{css:string,records:int}> */
    private function units(string $css, int $maxSelectorRecords): array
    {
        $units = array();
        $offset = 0;
        $length = strlen($css);
        while ($offset < $length) {
            $boundary = $this->nextBoundary($css, $offset);
            if (null === $boundary) {
                $units[] = array('css' => substr($css, $offset), 'records' => 0);
                break;
            }
            if (';' === $css[$boundary]) {
                $units[] = array('css' => substr($css, $offset, $boundary - $offset + 1), 'records' => 0);
                $offset = $boundary + 1;
                continue;
            }
            $end = $this->matchingBrace($css, $boundary);
            if (null === $end) {
                return array(array('css' => $css, 'records' => 0));
            }
            $prelude = substr($css, $offset, $boundary - $offset);
            $body = substr($css, $boundary + 1, $end - $boundary - 1);
            if ('@' === $this->firstSignificantCharacter($prelude) && $this->walksNestedRules($prelude)) {
                foreach ($this->units($body, $maxSelectorRecords) as $unit) {
                    $units[] = array('css' => $prelude . '{' . $unit['css'] . '}', 'records' => $unit['records']);
                }
            } elseif ('@' === $this->firstSignificantCharacter($prelude)) {
                $units[] = array('css' => substr($css, $offset, $end - $offset + 1), 'records' => 0);
            } else {
                $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
                if (null === $selectors) {
                    return array(array('css' => $css, 'records' => 0));
                }
                foreach (array_chunk($selectors, $maxSelectorRecords) as $selectorChunk) {
                    $units[] = array('css' => implode(',', $selectorChunk) . '{' . $body . '}', 'records' => count($selectorChunk));
                }
            }
            $offset = $end + 1;
        }
        return $units;
    }

    private function nextBoundary(string $css, int $offset): ?int
    {
        $state = CssSyntaxScanner::state();
        for ($index = $offset, $length = strlen($css); $index < $length; ) {
            $topLevel = CssSyntaxScanner::isTopLevel($state);
            $next = CssSyntaxScanner::consume($css, $index, $state);
            if (null === $next) {
                return null;
            }
            if ($topLevel && $next === $index + 1 && ('{' === $css[$index] || ';' === $css[$index])) {
                return $index;
            }
            $index = $next;
        }
        return null;
    }

    private function matchingBrace(string $css, int $openingBrace): ?int
    {
        $state = CssSyntaxScanner::state();
        $depth = 0;
        for ($index = $openingBrace, $length = strlen($css); $index < $length; ) {
            $topLevel = CssSyntaxScanner::isTopLevel($state);
            $next = CssSyntaxScanner::consume($css, $index, $state);
            if (null === $next) {
                return null;
            }
            if ($topLevel && $next === $index + 1 && '{' === $css[$index]) {
                ++$depth;
            } elseif ($topLevel && $next === $index + 1 && '}' === $css[$index] && 0 === --$depth) {
                return $index;
            }
            $index = $next;
        }
        return null;
    }

    private function firstSignificantCharacter(string $value): string
    {
        $state = CssSyntaxScanner::state();
        for ($offset = 0, $length = strlen($value); $offset < $length; ) {
            $next = CssSyntaxScanner::consume($value, $offset, $state);
            if (null === $next) {
                return '';
            }
            if ($next === $offset + 1 && CssSyntaxScanner::isTopLevel($state) && ! CssSyntaxScanner::isCssWhitespace($value[$offset])) {
                return $value[$offset];
            }
            $offset = $next;
        }
        return '';
    }

    private function walksNestedRules(string $prelude): bool
    {
        return 1 === preg_match('/^\s*@(container|layer|media|scope|starting-style|supports)\b/i', $prelude);
    }
}
