<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Support;

use Automattic\BlocksEngine\PhpTransformer\Css\CssColor;

/**
 * Reads the legacy `<body bgcolor>` presentational hint as CSS declarations.
 *
 * The transformer re-wraps each document in a bare `<body>`, so attributes on
 * the source body are gone before style resolution. HTML maps `bgcolor` to
 * `background-color` beneath every author rule.
 */
final class BodyPresentationalHints
{
    public static function declarations(string $html): string
    {
        if ( 1 !== preg_match('/<body\b([^>]*)>/i', $html, $match) ) {
            return '';
        }
        $color = CssColor::legacy((string) ( HtmlTagScanner::attributes($match[1])['bgcolor'] ?? '' ));

        return null === $color ? '' : 'background-color:' . $color;
    }
}
