<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Route state: what a router paints on the links of the route being served.
 *
 * Route state is recognised against the document, not a link's siblings.
 * Among the current links (`aria-current`, or `data-state~=selected`), the
 * class tokens present on every current link and on no non-current link are
 * route state. A router that marks every same-page link current therefore
 * still yields the shared chrome of a route with nothing marked. The rule
 * needs a baseline: it applies only when the document also holds at least
 * one non-current link.
 *
 * Route state is stripped together with `aria-current` and
 * `data-status="active"`. Source styling keyed on a state class of a menu item
 * is kept by the aria-current replay in WordPressCompatCss.
 */
final class RouteState
{
    /**
     * Class tokens that are route state, in first-current-link order.
     *
     * @param list<list<string>> $currentClasses class tokens of each current link
     * @param list<list<string>> $restingClasses class tokens of each non-current link
     * @return list<string>
     */
    public static function tokens(array $currentClasses, array $restingClasses): array
    {
        if (array() === $currentClasses || array() === $restingClasses) return array();
        $shared = null;
        foreach ($currentClasses as $classes) {
            $classes = array_values(array_unique(array_filter($classes, static fn(string $class): bool => '' !== $class)));
            $shared = null === $shared ? $classes : array_values(array_intersect($shared, $classes));
            if (array() === $shared) return array();
        }
        $resting = array();
        foreach ($restingClasses as $classes) foreach ($classes as $class) $resting[$class] = true;
        return array_values(array_filter($shared ?? array(), static fn(string $class): bool => !isset($resting[$class])));
    }

    /** @return list<string> */
    public static function classTokens(string $classAttribute): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim($classAttribute)) ?: array(), static fn(string $class): bool => '' !== $class));
    }

    public static function isCurrent(DOMElement $element): bool
    {
        if ($element->hasAttribute('aria-current') && 'false' !== strtolower(trim($element->getAttribute('aria-current')))) return true;
        return in_array('selected', self::classTokens($element->getAttribute('data-state')), true);
    }

    /**
     * Removes route state from a parsed document in place.
     */
    public static function stripFromDocument(DOMXPath $xpath): void
    {
        $analysis = self::analyse($xpath);
        foreach ($analysis['carriers'] as $carrier) {
            $classes = array_values(array_diff(self::classTokens($carrier['element']->getAttribute('class')), $carrier['tokens']));
            if (array() === $classes) $carrier['element']->removeAttribute('class');
            else $carrier['element']->setAttribute('class', implode(' ', $classes));
        }
        foreach ($analysis['current'] as $chain) {
            foreach ($chain as $element) {
                if ($element->hasAttribute('data-state')) $element->setAttribute('data-state', preg_replace('/\bselected\b/', 'false', $element->getAttribute('data-state')) ?? $element->getAttribute('data-state'));
                if ('active' === strtolower(trim($element->getAttribute('data-status')))) $element->removeAttribute('data-status');
            }
            $chain[0]->removeAttribute('aria-current');
        }
    }

    /**
     * Route state of a source HTML document, for markup compiled from it.
     *
     * Each carrier lists the full class tokens of a current link (or of a
     * wrapper that holds only it) and the route-state tokens among them; a
     * compiled block or element carrying all of those classes came from that
     * carrier.
     *
     * @return array{applies:bool,carriers:list<array{classes:list<string>,tokens:list<string>}>}
     */
    public static function ofHtml(string $html): array
    {
        $none = array('applies' => false, 'carriers' => array());
        if (!preg_match('/aria-current|\bselected\b/i', $html)) return $none;
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors(); libxml_use_internal_errors($previous);
        if (!$loaded) return $none;
        $analysis = self::analyse(new DOMXPath($dom));
        $carriers = array();
        foreach ($analysis['carriers'] as $carrier) {
            $classes = self::classTokens($carrier['element']->getAttribute('class'));
            $key = implode(' ', $classes) . "\0" . implode(' ', $carrier['tokens']);
            $carriers[$key] = array('classes' => $classes, 'tokens' => $carrier['tokens']);
        }
        return array('applies' => $analysis['applies'], 'carriers' => array_values($carriers));
    }

    /**
     * Removes the route-state tokens of $routeState from compiled block markup:
     * from every block className and element class list that holds all the
     * classes of a current-link carrier.
     *
     * @param array{applies?:bool,carriers?:list<array{classes:list<string>,tokens:list<string>}>} $routeState
     */
    public static function stripFromBlockMarkup(string $markup, array $routeState): string
    {
        $carriers = $routeState['carriers'] ?? array();
        if (array() === $carriers) return $markup;
        // A carrier whose classes are all route state has nothing stable to be
        // recognised by, so it matches only an element still marked current.
        $strip = static function (string $classAttribute, bool $markedCurrent) use ($carriers): ?string {
            $classes = self::classTokens($classAttribute);
            $remove = array();
            foreach ($carriers as $carrier) {
                if (!$markedCurrent && array() === array_diff($carrier['classes'], $carrier['tokens'])) continue;
                if (array() === array_diff($carrier['classes'], $classes)) foreach ($carrier['tokens'] as $token) $remove[$token] = true;
            }
            if (array() === $remove) return null;
            return implode(' ', array_values(array_filter($classes, static fn(string $class): bool => !isset($remove[$class]))));
        };
        $markup = preg_replace_callback('/"className":"([^"\\\\]*)"/', static function (array $match) use ($strip): string {
            $classes = $strip($match[1], false);
            return null === $classes ? $match[0] : '"className":"' . $classes . '"';
        }, $markup) ?? $markup;
        return preg_replace_callback('/<[a-z][a-z0-9-]*\\b[^>]*>/i', static function (array $tag) use ($strip): string {
            $markedCurrent = 1 === preg_match('/\\saria-current=(["\'])(?!false\\1)[^"\']*\\1/i', $tag[0]);
            return preg_replace_callback('/(\\sclass=)(["\'])([^"\']*)\\2/i', static function (array $match) use ($strip, $markedCurrent): string {
                $classes = $strip($match[3], $markedCurrent);
                return null === $classes ? $match[0] : $match[1] . $match[2] . $classes . $match[2];
            }, $tag[0]) ?? $tag[0];
        }, $markup) ?? $markup;
    }

    /**
     * A current link carries its state on itself and on the wrappers that
     * hold only it (an `li` around the anchor, up to the enclosing
     * navigation). Each such carrier is compared with the carriers at the same
     * depth of the non-current links.
     *
     * @return array{applies:bool,current:list<list<DOMElement>>,carriers:list<array{element:DOMElement,tokens:list<string>}>}
     */
    private static function analyse(DOMXPath $xpath): array
    {
        $current = array(); $resting = array();
        foreach ($xpath->query('//*[@aria-current] | //*[contains(concat(" ", normalize-space(@data-state), " "), " selected ")] | //a') ?: array() as $node) {
            if (!$node instanceof DOMElement) continue;
            if (self::isCurrent($node)) $current[] = self::chain($node);
            elseif ('a' === strtolower($node->tagName) && !self::insideCurrent($node)) $resting[] = self::chain($node);
        }
        $carriers = array();
        if (array() !== $current && array() !== $resting) {
            $depth = max(array_map('count', $current));
            for ($level = 0; $level < $depth; ++$level) {
                $currentAtLevel = array_values(array_filter(array_map(static fn(array $chain): ?DOMElement => $chain[$level] ?? null, $current)));
                $restingAtLevel = array_values(array_filter(array_map(static fn(array $chain): ?DOMElement => $chain[$level] ?? null, $resting)));
                $tokens = self::tokens(
                    array_map(static fn(DOMElement $element): array => self::classTokens($element->getAttribute('class')), $currentAtLevel),
                    array_map(static fn(DOMElement $element): array => self::classTokens($element->getAttribute('class')), $restingAtLevel)
                );
                if (array() === $tokens) continue;
                foreach ($currentAtLevel as $element) $carriers[] = array('element' => $element, 'tokens' => $tokens);
            }
        }
        return array('applies' => array() !== $current && array() !== $resting, 'current' => $current, 'carriers' => $carriers);
    }

    /**
     * The link and the wrappers that hold only it, innermost first, stopping
     * at the enclosing navigation.
     *
     * @return list<DOMElement>
     */
    private static function chain(DOMElement $link): array
    {
        $chain = array($link);
        for ($cursor = $link->parentNode; $cursor instanceof DOMElement; $cursor = $cursor->parentNode) {
            if (in_array(strtolower($cursor->tagName), array('nav', 'body', 'html'), true) || 'navigation' === strtolower($cursor->getAttribute('role'))) break;
            if (1 !== self::linkCount($cursor)) break;
            $chain[] = $cursor;
        }
        return $chain;
    }

    private static function insideCurrent(DOMElement $link): bool
    {
        for ($cursor = $link->parentNode; $cursor instanceof DOMElement; $cursor = $cursor->parentNode) if (self::isCurrent($cursor)) return true;
        return false;
    }

    private static function linkCount(DOMElement $element): int
    {
        $count = 0;
        foreach ($element->getElementsByTagName('*') as $descendant) {
            if ('a' === strtolower($descendant->tagName) || self::isCurrent($descendant)) ++$count;
            if ($count > 1) break;
        }
        return $count;
    }
}
