<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Serialize identifiers for CSS selectors (CSSOM serialize-an-identifier, ASCII). */
final class CssIdent
{
    public static function escape(string $ident): string
    {
        $escaped = '';
        $length = strlen($ident);
        for ( $offset = 0; $offset < $length; $offset++ ) {
            $ord = ord($ident[ $offset ]);
            $leadingDigit = ( 0 === $offset && $ord >= 0x30 && $ord <= 0x39 )
                || ( 1 === $offset && $ord >= 0x30 && $ord <= 0x39 && '-' === ( $ident[0] ?? '' ) );
            if ( 0 === $ord ) {
                $escaped .= "\u{FFFD}";
                continue;
            }
            if ( $leadingDigit || ( $ord >= 0x01 && $ord <= 0x1f ) || 0x7f === $ord ) {
                $escaped .= '\\' . dechex($ord) . ' ';
                continue;
            }
            if ( 1 === preg_match('/[A-Za-z0-9_-]/', $ident[ $offset ]) ) {
                $escaped .= $ident[ $offset ];
                continue;
            }
            $escaped .= '\\' . $ident[ $offset ];
        }

        return $escaped;
    }

    /** @param list<string> $classNames */
    public static function compoundClassSelector(array $classNames): string
    {
        $selector = '';
        foreach ( $classNames as $className ) {
            if ( '' !== $className ) {
                $selector .= '.' . self::escape($className);
            }
        }

        return $selector;
    }

    public static function classSelectorRegex(string $className, string $delimiter = '/'): string
    {
        $unescaped = preg_quote($className, $delimiter);
        $escaped = preg_quote(self::escape($className), $delimiter);
        if ( $unescaped === $escaped ) {
            return '\.' . $unescaped;
        }

        return '\.(?:' . $unescaped . '|' . $escaped . ')';
    }
}
