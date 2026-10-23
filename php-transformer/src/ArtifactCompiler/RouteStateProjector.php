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
        $files = self::bindRetainedLabels($files);
        foreach ($files as &$file) {
            if ('html' !== ($file['kind'] ?? null) || ! is_string($file['content'] ?? null)) {
                continue;
            }
            $file['content'] = self::normalize($file['content']);
        }
        unset($file);

        return array('files' => $files);
    }

    /**
     * Compare the same single-link label across captures. Only a demonstrated
     * current/resting pair can own a binding; unrelated label differences remain
     * authored content. The binding travels with labelMarkup and its saved HTML.
     */
    private static function bindRetainedLabels(array $files): array
    {
        $documents = array();
        $groups = array();
        foreach ($files as $index => $file) {
            if ('html' !== ($file['kind'] ?? null) || !is_string($file['content'] ?? null) || !str_contains($file['content'], '<label')) continue;
            $document = new DOMDocument('1.0', 'UTF-8');
            $previous = libxml_use_internal_errors(true);
            $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $file['content'], LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
            libxml_clear_errors(); libxml_use_internal_errors($previous);
            if (!$loaded) continue;
            $documents[$index] = $document;
            foreach ((new DOMXPath($document))->query('//nav//label[.//input]') ?: array() as $label) {
                if (!$label instanceof DOMElement || 1 !== $label->getElementsByTagName('a')->length) continue;
                $link = $label->getElementsByTagName('a')->item(0);
                if (!$link instanceof DOMElement || !$link->hasAttribute('href')) continue;
                $nodes = array(); $shape = array(); $current = false;
                foreach ($label->getElementsByTagName('*') as $node) {
                    if ('input' === strtolower($node->tagName)) continue;
                    $nodes[] = $node; $shape[] = $node->tagName;
                    foreach ($node->attributes as $attribute) {
                        if (('class' === $attribute->name || str_starts_with($attribute->name, 'data-')) && !str_starts_with($attribute->name, 'data-blocks-engine-') && MenuVocabulary::containsCurrentStateToken($attribute->value)) $current = true;
                        if ('aria-current' === $attribute->name && !in_array(strtolower($attribute->value), array('', 'false'), true)) $current = true;
                    }
                }
                // Translation and authored content are not route state. Peers
                // must keep the same link text as well as structural ownership.
                $href = trim($link->getAttribute('href'));
                if ('' === $href || ArtifactPath::namesSection($href) || str_starts_with($href, '//') || preg_match('~^[a-z][a-z0-9+.-]*:~i', $href)) continue;
                $target = str_starts_with($href, '/') ? ltrim(ArtifactPath::stripQueryAndFragment($href), '/') : ArtifactPath::resolveRelativePath($href, (string) ($file['path'] ?? ''));
                $key = $target . ':' . implode('/', $shape) . ':' . trim($label->textContent);
                $groups[$key][] = array('index' => $index, 'nodes' => $nodes, 'current' => $current);
            }
        }
        $changed = array();
        foreach ($groups as $rows) {
            $resting = array_values(array_filter($rows, static fn(array $row): bool => !$row['current']));
            $selected = array_values(array_filter($rows, static fn(array $row): bool => $row['current']));
            if (array() === $resting || array() === $selected) continue;
            foreach ($rows[0]['nodes'] as $position => $unused) {
                $names = array();
                foreach ($rows as $row) foreach ($row['nodes'][$position]->attributes as $attr) $names[$attr->name] = true;
                $binding = array('rest' => array(), 'current' => array());
                foreach (array_keys($names) as $name) {
                    if (!in_array($name, array('class', 'style', 'aria-current'), true) && !str_starts_with($name, 'data-')) continue;
                    if (str_starts_with($name, 'data-blocks-engine-')) continue;
                    $values = static function (array $peers) use ($position, $name): array {
                        return array_values(array_unique(array_map(static fn(array $row): ?string => $row['nodes'][$position]->hasAttribute($name) ? $row['nodes'][$position]->getAttribute($name) : null, $peers), SORT_REGULAR));
                    };
                    $rest = $values($resting); $active = $values($selected);
                    if (1 !== count($rest) || 1 !== count($active) || $rest === $active) continue;
                    $binding['rest'][$name] = $rest[0]; $binding['current'][$name] = $active[0];
                }
                if (array() === $binding['rest']) continue;
                foreach ($rows as $row) {
                    $node = $row['nodes'][$position];
                    foreach ($binding['rest'] as $name => $value) {
                        if (null === $value) $node->removeAttribute($name); else $node->setAttribute($name, $value);
                    }
                    $node->setAttribute('data-blocks-engine-route-state', (string) json_encode($binding, JSON_UNESCAPED_SLASHES));
                    $changed[$row['index']] = true;
                }
            }
        }
        foreach (array_keys($changed) as $index) $files[$index]['content'] = HtmlSerialization::documentHtml($documents[$index]);
        return $files;
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
