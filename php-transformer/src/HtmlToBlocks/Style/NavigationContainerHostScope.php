<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/**
 * Neutralizes a navigation host's box sizing on Core's duplicated container list.
 *
 * Core renders a core/navigation `className` on both the `<nav>` root and its
 * inner `ul.wp-block-navigation__container`. When the source put the class on
 * a menu wrapper (never on a list), author rules that size the host also size
 * the list a second time inside the root: a `width:50%` host makes the list
 * half the host and the items wrap. Every author rule is kept as projected;
 * one additive rule per host resets only the sizing properties the host sets
 * (width, min-/max-width, inline-axis margin and padding) on the duplicated
 * container.
 * Display, alignment and visibility are left to the author rules.
 */
final class NavigationContainerHostScope
{
    private const CONTAINER_CLASS = 'wp-block-navigation__container';

    /**
     * Container initial value of each neutralized property. Block-axis and
     * shorthand margin and padding belong to the navigation frame reset
     * (NavigationPatternContext::recordNavigationContainerReset), so only the
     * inline-axis longhands it leaves are listed here.
     */
    private const RESETS = array(
        'width' => 'auto',
        'min-width' => 'auto',
        'max-width' => 'none',
        'margin-left' => '0',
        'margin-right' => '0',
        'margin-inline' => '0',
        'margin-inline-start' => '0',
        'margin-inline-end' => '0',
        'padding-left' => '0',
        'padding-right' => '0',
        'padding-inline' => '0',
        'padding-inline-start' => '0',
        'padding-inline-end' => '0',
    );

    /**
     * Wrapper host class sets named by a source rule's subjects.
     *
     * @param list<list<string>> $roots Navigation root class lists for this projection.
     * @param callable(string): list<DOMElement> $sourceElements Source elements matching a class compound.
     * @return list<list<string>>
     */
    public function hostClassSets(string $sourcePrelude, array $roots, callable $sourceElements): array
    {
        if ( array() === $roots || ! str_contains($sourcePrelude, '.') ) {
            return array();
        }
        return $this->wrapperHostClassSets($sourcePrelude, $roots, $sourceElements);
    }

    /**
     * One additive rule per wrapper host resetting, on the duplicated
     * container, the sizing properties the projected stylesheet sets on it.
     *
     * @param list<list<string>> $hosts
     */
    public function neutralizingRules(string $css, array $hosts): string
    {
        if ( '' === $css || array() === $hosts ) {
            return '';
        }
        $set = array();
        ( new CssStylesheetTransformer() )->visitStyleRules(
            $css,
            function (string $prelude, string $body) use ($hosts, &$set): void {
                $properties = self::sizingProperties($body);
                if ( array() === $properties ) {
                    return;
                }
                foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector ) {
                    $index = $this->hostIndex(trim($selector), $hosts);
                    if ( null !== $index ) {
                        $set[ $index ] = array_merge($set[ $index ] ?? array(), $properties);
                    }
                }
            }
        );

        $rules = '';
        foreach ( $set as $index => $properties ) {
            $compound = implode('', array_map(static fn (string $class): string => '.' . self::escapeClass($class), $hosts[ $index ]));
            $declarations = array();
            foreach ( self::RESETS as $property => $value ) {
                if ( in_array($property, $properties, true) ) {
                    $declarations[] = $property . ':' . $value;
                }
            }
            $rules .= ':root .wp-block-navigation' . $compound . ' .' . self::CONTAINER_CLASS . $compound . '{' . implode(';', $declarations) . '}';
        }
        return $rules;
    }

    /**
     * Neutralized properties a rule body sets to something other than the
     * container's initial value.
     *
     * @return list<string>
     */
    private static function sizingProperties(string $body): array
    {
        $properties = array();
        foreach ( CssValueSplitter::splitTopLevel($body, array( ';' )) as $declaration ) {
            if ( ! str_contains($declaration, ':') ) {
                continue;
            }
            [$name, $value] = array_map('trim', explode(':', $declaration, 2));
            $name = strtolower($name);
            $value = strtolower(trim((string) preg_replace('/!\s*important$/i', '', $value)));
            if ( ! isset(self::RESETS[ $name ]) || in_array($value, array( self::RESETS[ $name ], '0', '0px' ), true) ) {
                continue;
            }
            $properties[] = $name;
        }
        return $properties;
    }

    private static function escapeClass(string $class): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_-]/', '\\\\$0', $class);
    }

    /**
     * Class compounds of the source prelude's subjects that name a navigation
     * root and, in the source, a non-list element only.
     *
     * @param list<list<string>> $roots
     * @param callable(string): list<DOMElement> $sourceElements
     * @return list<list<string>>
     */
    private function wrapperHostClassSets(string $sourcePrelude, array $roots, callable $sourceElements): array
    {
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
    public function navigationRootClassLists(string $markup): array
    {
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
        return $lists;
    }

    /**
     * Index of the wrapper host whose class set is the selector subject's
     * top-level classes (optionally with Core's shared `wp-block-navigation`).
     *
     * @param list<list<string>> $hosts
     */
    private function hostIndex(string $selector, array $hosts): ?int
    {
        if ( str_contains($selector, self::CONTAINER_CLASS) ) {
            return null;
        }
        $subject = substr($selector, self::subjectStart($selector));
        if ( 1 === preg_match('/::|:(?:before|after|first-line|first-letter|marker|placeholder|selection|backdrop)\b/i', $subject) ) {
            return null;
        }
        $classes = array_values(array_diff(self::topLevelClasses($subject), array( 'wp-block-navigation' )));
        sort($classes);
        foreach ( $hosts as $index => $host ) {
            $sorted = $host;
            sort($sorted);
            if ( $sorted === $classes ) {
                return $index;
            }
        }
        return null;
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
}
