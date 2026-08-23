<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\AssetAnalysis;

/**
 * Public srcset parsing contract for transformer consumers.
 *
 * Candidate order and descriptors are preserved. Parsing is deliberately
 * lossless rather than validating URL schemes or descriptor grammar; callers
 * own those policies.
 *
 * @api
 */
final class SrcsetParser
{
    /** @return array<int,array{url:string,descriptor:string}> */
    public static function parse(string $srcset): array
    {
        $candidates = array();
        $length = strlen($srcset);
        $offset = 0;

        while ($offset < $length) {
            while ($offset < $length && (ctype_space($srcset[$offset]) || ',' === $srcset[$offset])) ++$offset;
            if ($offset >= $length) break;

            $urlStart = $offset;
            while ($offset < $length && !ctype_space($srcset[$offset])) ++$offset;
            $url = substr($srcset, $urlStart, $offset - $urlStart);
            $trailingCommas = strlen($url) - strlen(rtrim($url, ','));
            if ($trailingCommas > 0) {
                $url = substr($url, 0, -$trailingCommas);
                if ('' !== $url) $candidates[] = array('url' => $url, 'descriptor' => '');
                continue;
            }

            while ($offset < $length && ctype_space($srcset[$offset])) ++$offset;
            $descriptorStart = $offset;
            $parentheses = 0;
            while ($offset < $length) {
                if ('(' === $srcset[$offset]) ++$parentheses;
                elseif (')' === $srcset[$offset] && $parentheses > 0) --$parentheses;
                elseif (',' === $srcset[$offset] && 0 === $parentheses) break;
                ++$offset;
            }
            $descriptor = trim(substr($srcset, $descriptorStart, $offset - $descriptorStart));
            if ($offset < $length) ++$offset;
            if ('' !== $url) $candidates[] = array('url' => $url, 'descriptor' => $descriptor);
        }

        return $candidates;
    }

    /** @return array<int,string> */
    public static function urls(string $srcset): array
    {
        return array_column(self::parse($srcset), 'url');
    }

    /** @param callable(string):string $replace */
    public static function rewrite(string $srcset, callable $replace): string
    {
        return implode(', ', array_map(
            static fn(array $candidate): string => $replace($candidate['url']) . ('' !== $candidate['descriptor'] ? ' ' . $candidate['descriptor'] : ''),
            self::parse($srcset)
        ));
    }
}
