<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssIdent;
use DOMElement;

/**
 * Which selectors can name an element's id or classes, decided by the `.name`
 * and `#name` runs a selector spells.
 *
 * `#id(?![\w-])` and `CssIdent::classSelectorRegex($class) . '(?![\w-])'`
 * match a selector exactly where the name is a whole `[\w-]+` run after `.`
 * or `#`, when the id is made of `[\w-]` alone and the class is also spelled
 * unescaped. A selector naming none of an element's runs cannot name it; any
 * other name is left to the exact regexes.
 */
final class SelectorNameRuns
{
    /** @return list<string> The `.name` and `#name` runs the selector spells. */
    public static function spelled(string $selector): array
    {
        preg_match_all('/[.#][\w-]+/', $selector, $matches);
        return $matches[0];
    }

    /**
     * The runs that name the element (`#id`, `.class`), or null when a name
     * cannot be decided by runs and every selector must be checked.
     *
     * @return list<string>|null
     */
    public static function naming(DOMElement $element): ?array
    {
        $runs = array();
        $id = trim($element->getAttribute('id'));
        if ( '' !== $id ) {
            if ( 1 !== preg_match('/^[\w-]+$/D', $id) ) {
                return null;
            }
            $runs[] = '#' . $id;
        }
        foreach ( preg_split('/\s+/', trim($element->getAttribute('class'))) ?: array() as $className ) {
            if ( '' === $className ) {
                continue;
            }
            if ( 1 !== preg_match('/^[\w-]+$/D', $className) || CssIdent::escape($className) !== $className ) {
                return null;
            }
            $runs[] = '.' . $className;
        }
        return $runs;
    }
}
