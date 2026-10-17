<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\MenuVocabulary;
use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Removes the state a client router painted on a captured route's links, so
 * each source document compiles its chrome as it rests on every route.
 *
 * A current link carries `aria-current` or `data-status="active"`. Route
 * state is judged against the document's resting links, not a link's
 * siblings, so a router that marks every link of a menu current is still
 * recognised by the links it left resting elsewhere. When the document holds
 * a resting link, each current link loses `data-status` and the current-state
 * class tokens (`active`, `nav-item--current`) that every current link (or
 * every current link's single-link wrapper at the same depth, such as its
 * `li`) carries and no resting link at that depth does. Other tokens are the
 * link's own identity, even when only the current link has them.
 *
 * `aria-current` stays where WordPress restores it: on a menu link to the
 * page, whose state navigation re-derives on the served route. A link to a
 * fragment names a section, not the page, and a current link outside a menu
 * (a brand link) has nothing to restore it on other routes, so both lose it.
 * WordPressCompatCss replays author state selectors against the served
 * route's `aria-current`.
 */
final class RouteStateProjector
{
    /**
     * @param array<int, array<string, mixed>> $files
     * @return array{files:array<int, array<string, mixed>>}
     */
    public function project(array $files): array
    {
        foreach ($files as &$file) {
            if ('html' !== ($file['kind'] ?? null) || ! is_string($file['content'] ?? null)) {
                continue;
            }
            $file['content'] = self::normalize($file['content']);
        }
        unset($file);

        return array('files' => $files);
    }

    public static function normalize(string $html): string
    {
        if (! preg_match('/\saria-current\s*=|\sdata-status\s*=\s*["\']?active/i', $html)) {
            return $html;
        }
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return $html;
        }

        $current = array();
        $resting = array();
        foreach ((new DOMXPath($document))->query('//a[@href]') ?: array() as $link) {
            if (! $link instanceof DOMElement) {
                continue;
            }
            if (self::isCurrent($link)) {
                $current[] = self::chain($link);
            } else {
                $resting[] = self::chain($link);
            }
        }
        if (array() === $current) {
            return $html;
        }

        $changed = false;
        if (array() !== $resting) {
            $depth = max(array_map('count', $current));
            for ($level = 0; $level < $depth; ++$level) {
                $atLevel = array_values(array_filter(array_map(static fn(array $chain): ?DOMElement => $chain[$level] ?? null, $current)));
                if (count($atLevel) !== count($current)) {
                    continue;
                }
                $state = null;
                foreach ($atLevel as $element) {
                    $classes = self::classes($element);
                    $state = null === $state ? $classes : array_values(array_intersect($state, $classes));
                }
                $restingClasses = array();
                foreach ($resting as $chain) {
                    if (isset($chain[$level])) {
                        foreach (self::classes($chain[$level]) as $class) {
                            $restingClasses[$class] = true;
                        }
                    }
                }
                $state = array_values(array_filter($state ?? array(), static fn(string $class): bool => ! isset($restingClasses[$class]) && MenuVocabulary::containsCurrentStateToken($class)));
                if (array() === $state) {
                    continue;
                }
                $changed = true;
                foreach ($atLevel as $element) {
                    $classes = array_values(array_diff(self::classes($element), $state));
                    if (array() === $classes) {
                        $element->removeAttribute('class');
                    } else {
                        $element->setAttribute('class', implode(' ', $classes));
                    }
                }
            }
        }

        foreach ($current as $chain) {
            $link = $chain[0];
            if (array() !== $resting && $link->hasAttribute('data-status')) {
                $link->removeAttribute('data-status');
                $changed = true;
            }
            if ($link->hasAttribute('aria-current') && (ArtifactPath::namesSection($link->getAttribute('href')) || (array() !== $resting && ! self::inMenu($link)))) {
                $link->removeAttribute('aria-current');
                $changed = true;
            }
        }
        // An unchanged document keeps its authored bytes, so it compiles
        // exactly as its peers that carry no route state.
        if (! $changed) {
            return $html;
        }

        $output = HtmlSerialization::documentHtml($document);
        return is_string($output) ? $output : $html;
    }

    private static function isCurrent(DOMElement $link): bool
    {
        $ariaCurrent = strtolower(trim($link->getAttribute('aria-current')));
        return ($link->hasAttribute('aria-current') && 'false' !== $ariaCurrent)
            || 'active' === strtolower(trim($link->getAttribute('data-status')));
    }

    private static function inMenu(DOMElement $link): bool
    {
        for ($cursor = $link->parentNode; $cursor instanceof DOMElement; $cursor = $cursor->parentNode) {
            if ('nav' === strtolower($cursor->tagName) || 'navigation' === strtolower($cursor->getAttribute('role'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The link and the wrappers that hold only it, innermost first, up to
     * the enclosing navigation.
     *
     * @return list<DOMElement>
     */
    private static function chain(DOMElement $link): array
    {
        $chain = array($link);
        for ($cursor = $link->parentNode; $cursor instanceof DOMElement; $cursor = $cursor->parentNode) {
            $tag = strtolower($cursor->tagName);
            if (in_array($tag, array('nav', 'body', 'html', 'header', 'footer', 'main'), true) || 'navigation' === strtolower($cursor->getAttribute('role'))) {
                break;
            }
            if (1 !== $cursor->getElementsByTagName('a')->length) {
                break;
            }
            $chain[] = $cursor;
        }

        return $chain;
    }

    /** @return list<string> */
    private static function classes(DOMElement $element): array
    {
        return array_values(array_unique(array_filter(preg_split('/\s+/', trim($element->getAttribute('class'))) ?: array(), static fn(string $class): bool => '' !== $class)));
    }
}
