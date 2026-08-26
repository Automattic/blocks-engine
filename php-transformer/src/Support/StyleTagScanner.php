<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Support;

final class StyleTagScanner
{
    /**
     * @return list<array{attributes:string,content:string,offset:int,end_offset:int}>
     */
    public static function scan(string $html): array
    {
        $styles = array();
        $offset = 0;
        $length = strlen($html);

        while ($offset < $length) {
            $open = stripos($html, '<style', $offset);
            if (false === $open) {
                break;
            }
            $boundary = $html[$open + 6] ?? '';
            if ('' !== $boundary && '>' !== $boundary && '/' !== $boundary && !ctype_space($boundary)) {
                $offset = $open + 6;
                continue;
            }
            $openEnd = self::tagEnd($html, $open + 6);
            if (null === $openEnd) {
                break;
            }
            $close = self::closingTag($html, $openEnd + 1);
            if (null === $close) {
                break;
            }

            $styles[] = array(
                'attributes' => substr($html, $open + 6, $openEnd - $open - 6),
                'content' => substr($html, $openEnd + 1, $close['offset'] - $openEnd - 1),
                'offset' => $open,
                'end_offset' => $close['end_offset'],
            );
            $offset = $close['end_offset'];
        }

        return $styles;
    }

    private static function tagEnd(string $html, int $offset): ?int
    {
        $quote = '';
        for ($index = $offset, $length = strlen($html); $index < $length; ++$index) {
            $character = $html[$index];
            if ('' !== $quote) {
                if ($character === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ('"' === $character || "'" === $character) {
                $quote = $character;
                continue;
            }
            if ('>' === $character) {
                return $index;
            }
        }

        return null;
    }

    /** @return array{offset:int,end_offset:int}|null */
    private static function closingTag(string $html, int $offset): ?array
    {
        $length = strlen($html);
        while ($offset < $length) {
            $close = stripos($html, '</style', $offset);
            if (false === $close) {
                return null;
            }
            $boundary = $html[$close + 7] ?? '';
            if ('' !== $boundary && '>' !== $boundary && !ctype_space($boundary)) {
                $offset = $close + 7;
                continue;
            }
            $closeEnd = self::tagEnd($html, $close + 7);
            if (null === $closeEnd) {
                return null;
            }

            return array('offset' => $close, 'end_offset' => $closeEnd + 1);
        }

        return null;
    }
}
