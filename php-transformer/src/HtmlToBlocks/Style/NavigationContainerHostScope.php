<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/**
 * Keeps a navigation host's box rules off Core's duplicated container list.
 *
 * Core renders a core/navigation `className` on both the `<nav>` root and its
 * inner `ul.wp-block-navigation__container` (which also carries
 * `wp-block-navigation`). When the source put the class on a menu wrapper
 * (never on a list), author box rules for it would size the list a second
 * time inside the root: a `width:50%` host makes the list half the host and
 * the items wrap. Those box declarations are moved to a sibling rule whose
 * wrapper-class subjects exclude the container. The exclusion sits in
 * `:not(:where())`, so specificity and cascade position are unchanged.
 */
final class NavigationContainerHostScope
{
    private const CONTAINER_CLASS = 'wp-block-navigation__container';

    private const EXCLUSION = ':not(:where(.' . self::CONTAINER_CLASS . '))';

    /** @var array<string, list<list<string>>> Wrapper class sets per emitted markup. */
    private array $wrapperClassSets = array();

    /**
     * @param callable(string): list<DOMElement> $sourceElements Source elements matching a class compound.
     */
    public function scope(string $css, string $sourcePrelude, string $markup, callable $sourceElements): string
    {
        if ( '' === $css || ! str_contains($markup, '<!-- wp:navigation ') || ! str_contains($sourcePrelude, '.') ) {
            return $css;
        }
        $hosts = $this->wrapperHostClassSets($sourcePrelude, $markup, $sourceElements);
        if ( array() === $hosts ) {
            return $css;
        }

        return ( new CssStylesheetTransformer() )->transformStyleRules(
            $css,
            function (string $prelude, string $body) use ($hosts): string {
                $rule = $prelude . '{' . $body . '}';
                $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
                if ( null === $selectors ) {
                    return $rule;
                }
                $scoped = array();
                $changed = false;
                foreach ( $selectors as $selector ) {
                    $exclusive = $this->excludeContainer(trim($selector), $hosts);
                    $changed = $changed || null !== $exclusive;
                    $scoped[] = $exclusive ?? trim($selector);
                }
                if ( ! $changed ) {
                    return $rule;
                }
                $box = $other = array();
                foreach ( CssValueSplitter::splitTopLevel($body, array( ';' )) as $declaration ) {
                    $name = strtolower(trim(explode(':', $declaration, 2)[0]));
                    if ( '' === trim($declaration) ) {
                        continue;
                    }
                    if ( self::isBoxProperty($name) ) {
                        $box[] = trim($declaration);
                    } else {
                        $other[] = trim($declaration);
                    }
                }
                if ( array() === $box ) {
                    return $rule;
                }
                return (array() === $other ? '' : $prelude . '{' . implode(';', $other) . '}')
                    . implode(',', $scoped) . '{' . implode(';', $box) . '}';
            }
        );
    }

    /**
     * Class compounds of the source prelude's subjects that name a navigation
     * root and, in the source, a non-list element only.
     *
     * @param callable(string): list<DOMElement> $sourceElements
     * @return list<list<string>>
     */
    private function wrapperHostClassSets(string $sourcePrelude, string $markup, callable $sourceElements): array
    {
        $roots = $this->navigationRootClassLists($markup);
        $sets = array();
        foreach ( CssStylesheetTransformer::splitSelectorList($sourcePrelude) ?? array() as $selector ) {
            $classes = self::subjectClasses(trim($selector));
            if ( array() === $classes || in_array($classes, $sets, true) ) {
                continue;
            }
            $onRoot = false;
            foreach ( $roots as $tokens ) {
                $onRoot = $onRoot || array() === array_diff($classes, $tokens);
            }
            if ( ! $onRoot ) {
                continue;
            }
            $compound = '.' . implode('.', array_map(static fn (string $class): string => addcslashes($class, '\\.:#[]()'), $classes));
            $elements = $sourceElements($compound);
            $lists = array_filter($elements, static fn (DOMElement $element): bool => in_array(strtolower($element->tagName), array( 'ul', 'ol', 'menu' ), true));
            if ( array() !== $elements && array() === $lists ) {
                $sets[] = $classes;
            }
        }
        return $sets;
    }

    /** @return list<list<string>> */
    private function navigationRootClassLists(string $markup): array
    {
        $key = md5($markup);
        if ( isset($this->wrapperClassSets[$key]) ) {
            return $this->wrapperClassSets[$key];
        }
        $lists = array();
        if ( preg_match_all('/<!-- wp:navigation (\{.*?\}) \/?-->/', $markup, $matches) ) {
            foreach ( $matches[1] as $json ) {
                $attributes = json_decode($json, true);
                $tokens = SourceDom::tokens(is_array($attributes) ? (string) ($attributes['className'] ?? '') : '');
                if ( array() !== $tokens ) {
                    $lists[] = $tokens;
                }
            }
        }
        $this->wrapperClassSets = array( $key => $lists );
        return $lists;
    }

    /**
     * The selector with the container excluded from its subject, when the
     * subject's top-level classes are a wrapper host class set (optionally
     * with Core's shared `wp-block-navigation` class).
     *
     * @param list<list<string>> $hosts
     */
    private function excludeContainer(string $selector, array $hosts): ?string
    {
        if ( str_contains($selector, self::CONTAINER_CLASS) ) {
            return null;
        }
        $start = self::subjectStart($selector);
        $subject = substr($selector, $start);
        $classes = array_values(array_diff(self::topLevelClasses($subject), array( 'wp-block-navigation' )));
        sort($classes);
        $matches = false;
        foreach ( $hosts as $host ) {
            $sorted = $host;
            sort($sorted);
            $matches = $matches || $sorted === $classes;
        }
        if ( ! $matches ) {
            return null;
        }
        // Pseudo-elements close a compound; the exclusion goes before them.
        if ( preg_match('/::?(?:before|after|first-line|first-letter|marker|placeholder|selection|backdrop)\b|::/i', $subject, $pseudo, PREG_OFFSET_CAPTURE) ) {
            $at = $start + (int) $pseudo[0][1];
            return substr($selector, 0, $at) . self::EXCLUSION . substr($selector, $at);
        }
        return $selector . self::EXCLUSION;
    }

    /**
     * Classes of a source selector's subject when it is a class compound,
     * optionally followed by pseudo-classes.
     *
     * @return list<string>
     */
    private static function subjectClasses(string $selector): array
    {
        $subject = substr($selector, self::subjectStart($selector));
        if ( ! preg_match('/^((?:\.(?:\\\\.|[A-Za-z0-9_-])+)+)(?::[^\s]*)?$/', $subject, $match) ) {
            return array();
        }
        $classes = self::topLevelClasses($match[1]);
        sort($classes);
        return $classes;
    }

    /** Offset of the selector's last compound (after the last top-level combinator). */
    private static function subjectStart(string $selector): int
    {
        $depth = 0;
        $start = 0;
        $length = strlen($selector);
        for ( $i = 0; $i < $length; ++$i ) {
            $char = $selector[$i];
            if ( '\\' === $char ) {
                ++$i;
                continue;
            }
            if ( '(' === $char || '[' === $char ) {
                ++$depth;
            } elseif ( ')' === $char || ']' === $char ) {
                $depth = max(0, $depth - 1);
            } elseif ( 0 === $depth && ( ctype_space($char) || '>' === $char || '+' === $char || '~' === $char ) ) {
                $start = $i + 1;
            }
        }
        return $start;
    }

    /** @return list<string> Class names outside any functional pseudo or attribute selector. */
    private static function topLevelClasses(string $compound): array
    {
        $depth = 0;
        $flat = '';
        $length = strlen($compound);
        for ( $i = 0; $i < $length; ++$i ) {
            $char = $compound[$i];
            if ( '\\' === $char && $i + 1 < $length ) {
                if ( 0 === $depth ) {
                    $flat .= $char . $compound[$i + 1];
                }
                ++$i;
                continue;
            }
            if ( '(' === $char || '[' === $char ) {
                ++$depth;
                continue;
            }
            if ( ')' === $char || ']' === $char ) {
                $depth = max(0, $depth - 1);
                continue;
            }
            if ( 0 === $depth ) {
                $flat .= $char;
            }
        }
        preg_match_all('/\.((?:\\\\.|[A-Za-z0-9_-])+)/', $flat, $matches);
        return array_values(array_unique(array_map('stripslashes', $matches[1])));
    }

    /** Properties that size, space or paint the host's own box. */
    private static function isBoxProperty(string $name): bool
    {
        return 'display' === $name
            || 'box-shadow' === $name
            || 1 === preg_match('/^(?:(?:min-|max-)?(?:width|height|inline-size|block-size)|margin(?:-.+)?|padding(?:-.+)?|border(?:-(?!collapse$|spacing$).+)?|background(?:-.+)?)$/', $name);
    }
}
