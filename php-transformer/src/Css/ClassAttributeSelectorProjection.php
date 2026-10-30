<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

use Automattic\BlocksEngine\PhpTransformer\WordPress\SourceClassIdentity;

/**
 * Keep authored class attribute selectors (`*=`, `^=`, `$=`, `|=`) on the
 * source elements they matched.
 *
 * Those operators test the whole `class` attribute text. A projected block
 * adds engine tokens (`blocks-engine-subject-tag-span`,
 * `blocks-engine-navigation-item-…`) to that text, so `[class*="span"]` would
 * start matching a subtitle span that never matched in the source. Each such
 * predicate becomes an equivalent of the same specificity with two arms:
 *
 * - the authored predicate on elements without engine tokens, which keeps it
 *   live for runtime-toggled classes and carried markup, and
 * - the source class tokens that satisfy it, which address the source
 *   elements whatever tokens projection added or however it reordered them.
 *
 * Engine tokens can then never create a match. The token vocabulary comes
 * from the source documents' class attributes, so no class list is assumed.
 */
final class ClassAttributeSelectorProjection
{
    private const ENGINE_TOKEN_PREFIX = 'blocks-engine-';
    private const ENGINE_FREE = ':where(:not([class*="' . self::ENGINE_TOKEN_PREFIX . '"]))';
    private const ATTRIBUTE = '/\[\s*class\s*([*^$|])=\s*(?:"([^"\\\\]*)"|\'([^\'\\\\]*)\'|([A-Za-z0-9_-]+))\s*([iIsS])?\s*\]/';

    /** @var array<string, true> */
    private array $classAttributes = array();
    /** @var array<string, string> */
    private array $replacements = array();

    /** @param iterable<string> $classAttributes Source `class` attribute values. */
    public function __construct(iterable $classAttributes)
    {
        foreach ( $classAttributes as $value ) {
            $value = trim((string) preg_replace('/[\x09\x0A\x0C\x0D\x20]+/', ' ', $value));
            if ( '' !== $value ) {
                $this->classAttributes[$value] = true;
            }
        }
    }

    /** Class attribute values of every element in HTML documents. @param iterable<string> $documents */
    public static function fromHtmlDocuments(iterable $documents): self
    {
        $values = array();
        foreach ( $documents as $html ) {
            if ( ! str_contains($html, 'class') ) {
                continue;
            }
            if ( preg_match_all('/<[A-Za-z][^\s\/>]*[^>]*?\sclass\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))/i', $html, $matches, PREG_SET_ORDER) ) {
                foreach ( $matches as $match ) {
                    $values[] = html_entity_decode(($match[1] ?? '') . ($match[2] ?? '') . ($match[3] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
        }
        return new self($values);
    }

    /** Rewrite every class attribute predicate in each style rule's selectors. */
    public function stylesheet(string $css): string
    {
        if ( ! preg_match('/\[\s*class\s*[*^$|]=/i', $css) ) {
            return $css;
        }
        return (new CssStylesheetTransformer())->transformStyleRules($css, fn (string $prelude, string $body): string => $this->selector($prelude) . '{' . $body . '}');
    }

    public function selector(string $selector): string
    {
        if ( ! preg_match('/\[\s*class\s*[*^$|]=/i', $selector) ) {
            return $selector;
        }
        $result = '';
        $length = strlen($selector);
        for ( $offset = 0; $offset < $length; ) {
            $character = $selector[$offset];
            if ( '\\' === $character ) {
                $result .= substr($selector, $offset, 2);
                $offset += 2;
                continue;
            }
            if ( '"' === $character || "'" === $character ) {
                $end = $offset + 1;
                while ( $end < $length && $selector[$end] !== $character ) {
                    $end += '\\' === $selector[$end] ? 2 : 1;
                }
                $result .= substr($selector, $offset, $end + 1 - $offset);
                $offset = $end + 1;
                continue;
            }
            if ( '[' === $character && 1 === preg_match(self::ATTRIBUTE, $selector, $match, PREG_OFFSET_CAPTURE, $offset) && $offset === $match[0][1] ) {
                $attribute = $match[0][0];
                $next = $offset + strlen($attribute);
                $value = ($match[2][0] ?? '') . ($match[3][0] ?? '') . ($match[4][0] ?? '');
                // A predicate this pass already produced, an engine one, or one that
                // spans tokens (whitespace in the value) stays as authored.
                if ( str_starts_with(substr($selector, $next), self::ENGINE_FREE) || str_starts_with(strtolower($value), self::ENGINE_TOKEN_PREFIX) || 1 === preg_match('/^$|[\x09\x0A\x0C\x0D\x20]/', $value) ) {
                    $result .= $attribute;
                    $offset = $next;
                    continue;
                }
                $result .= $this->replacement($attribute, $match[1][0], $value, 'i' === strtolower($match[5][0] ?? ''));
                $offset = $next;
                continue;
            }
            $result .= $character;
            ++$offset;
        }
        return $result;
    }

    private function replacement(string $attribute, string $operator, string $value, bool $insensitive): string
    {
        $key = $operator . "\0" . $value . "\0" . ($insensitive ? 'i' : '');
        if ( isset($this->replacements[$key]) ) {
            return $this->replacements[$key];
        }
        $arms = array( $attribute . self::ENGINE_FREE );
        foreach ( $this->sourceTokens($operator, $value, $insensitive) as $token ) {
            $marker = SourceClassIdentity::marker($token);
            $arms[] = '.' . CssIdent::escape('' === $marker ? $token : $marker);
        }
        // :is() takes its most specific arm: one attribute or one class, the
        // authored weight.
        return $this->replacements[$key] = ':is(' . implode(',', array_values(array_unique($arms))) . ')';
    }

    /**
     * Tokens that satisfy the predicate on source elements whose class
     * attribute satisfied it, excluding tokens also carried by a source element
     * it did not match, so no other source element joins.
     *
     * @return list<string>
     */
    private function sourceTokens(string $operator, string $value, bool $insensitive): array
    {
        $needle = $insensitive ? strtolower($value) : $value;
        $tokens = array();
        $excluded = array();
        foreach ( array_keys($this->classAttributes) as $attribute ) {
            $text = $insensitive ? strtolower((string) $attribute) : (string) $attribute;
            $matches = self::test($operator, $text, $needle);
            foreach ( explode(' ', (string) $attribute) as $token ) {
                if ( ! $matches ) {
                    $excluded[$token] = true;
                } elseif ( self::test($operator, $insensitive ? strtolower($token) : $token, $needle) ) {
                    $tokens[$token] = true;
                }
            }
        }
        $tokens = array_keys(array_diff_key($tokens, $excluded));
        $tokens = array_values(array_filter(array_map('strval', $tokens), static fn (string $token): bool => ! str_starts_with($token, self::ENGINE_TOKEN_PREFIX)));
        sort($tokens, SORT_STRING);
        return $tokens;
    }

    private static function test(string $operator, string $text, string $value): bool
    {
        return match ( $operator ) {
            '*' => str_contains($text, $value),
            '^' => str_starts_with($text, $value),
            '$' => str_ends_with($text, $value),
            default => $text === $value || str_starts_with($text, $value . '-'),
        };
    }
}
