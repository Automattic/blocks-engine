<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\WordPress\SourceClassIdentity;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorTokenizer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSyntaxScanner;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\MenuVocabulary;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorSelectorProjectionState;
use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;
use Automattic\BlocksEngine\PhpTransformer\Support\RenderEquivalentMarkup;
use Automattic\BlocksEngine\PhpTransformer\Support\StyleTagScanner;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\PageRoute;
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
 * a resting link, each current link and each of its single-link wrappers
 * (such as its `li`) loses `data-status` and the current-state class tokens
 * (`active`, `nav-item--current`) that no resting link or wrapper at that
 * depth carries, with the disclosure classes (`expanded`) a builder adds to
 * the served item. A menu item holding a submenu wraps one link, its label, so
 * a current parent item rests too. Other tokens are the link's own
 * identity, even when only the current link has them.
 *
 * `aria-current` stays where WordPress restores it: on a menu link to the
 * page, whose state navigation re-derives on the served route. A link to a
 * fragment names a section, not the page, and a current link outside a menu
 * (a brand link) has nothing to restore it on other routes, so both lose it.
 * WordPressCompatCss replays author state selectors against the served
 * route's `aria-current`.
 *
 * Route chrome (header and footer landmarks) can carry two other kinds of
 * route state. A capture-resolved fragment target placed inside the chrome
 * is the route's own scroll target, so it moves out to just before the
 * landmark and stays on the route. A class token that the same chrome
 * element (same tag and id) carries on some routes but not on others is
 * route state when no author rule renders it at rest: every rule naming it
 * either sets only transition timing or also requires a class the element
 * does not have. A token no rule names may be a script hook or an authored
 * variant, so it stays. A capture's responsive projection hook whose
 * every rule restates the element's own inline declarations records only a
 * reserialized style, not a width-scoped presentation, so it goes too.
 */
final class RouteStateProjector
{
    /**
     * @param array<int, array<string, mixed>> $files
     * @return array{files:array<int, array<string, mixed>>}
     */
    public function project(array $files, string $entryPath = ''): array
    {
        // Normalized artifacts carry their entrypoint on the files themselves,
        // including when projection is invoked by the staged transport.
        if ('' === $entryPath) {
            foreach ($files as $file) {
                if (!empty($file['entrypoint'])) {
                    $entryPath = (string) $file['path'];
                    break;
                }
            }
        }
        $files = self::withoutUnstyledViewportClassAliases($files);
        // Route bindings record class names, so they read the aligned ones.
        $files = self::withRouteAlignedChromeClasses($files);
        $files = self::bindNavigationRuns(self::bindRetainedLabels($files, $entryPath));
        $files = self::bindCurrentNavigationSources($files);
        $files = self::withRestingChrome($files);
        foreach ($files as &$file) {
            if ('html' !== ($file['kind'] ?? null) || ! is_string($file['content'] ?? null)) {
                continue;
            }
            $file['content'] = self::normalize($file['content'], true);
            // Lowering can move inline paint to generated CSS classes. Carry
            // sibling order explicitly so removing generated class hashes from
            // shell identity cannot hide a genuine stacking reorder.
            $identity = \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ChromeStackingIdentity::normalize($file['content']);
            preg_match_all('/<[^>]+style="[^"]*\bz-index\s*:\s*([+-]?\d+)[^"]*"[^>]*>/i', $identity, $stacking);
            $position = 0;
            $file['content'] = preg_replace_callback('/<[^>]+style="[^"]*\bz-index\s*:\s*([+-]?\d+)[^"]*"[^>]*>/i', static function (array $match) use ($stacking, &$position): string {
                $rank = $stacking[1][$position++] ?? $match[1];
                $class = 'be-stacking-order-' . str_replace('-', 'negative-', $rank);
                if (preg_match('/\bclass="/', $match[0])) return preg_replace('/\bclass="/', 'class="' . $class . ' ', $match[0], 1) ?? $match[0];
                return substr_replace($match[0], ' class="' . $class . '"', -1, 0);
            }, $file['content']) ?? $file['content'];
        }
        unset($file);

        return array('files' => $files);
    }

    /**
     * Discover the site's current-page runs once, before per-route lowering.
     * The annotation carries only navigation ownership, never a selected item.
     */
    private static function bindNavigationRuns(array $files): array
    {
        $documents = array();
        $runs = array();
        $qualified = array();
        foreach ($files as $index => $file) {
            if ('html' !== ($file['kind'] ?? null) || !is_string($file['content'] ?? null)) continue;
            $document = HtmlSerialization::parseFragment($file['content']);
            $documents[$index] = $document;
            foreach ((new DOMXPath($document))->query('//*[a[@href]]') ?: array() as $element) {
                if (!$element instanceof DOMElement) continue;
                $signature = MenuVocabulary::linkRunSignature($element, (string) ($file['path'] ?? ''));
                if (null === $signature) continue;
                $runs[$index][] = array($element, $signature);
                if (MenuVocabulary::isCurrentPageLinkRun($element)) $qualified[$signature] = true;
            }
        }
        foreach ($runs as $index => $rows) {
            $changed = false;
            foreach ($rows as [$element, $signature]) {
                if (!isset($qualified[$signature])) continue;
                $element->setAttribute('data-blocks-engine-navigation-run', 'true');
                // Recheck placement: a matching run in article content is not chrome.
                if (!MenuVocabulary::isCurrentPageLinkRun($element)) {
                    $element->removeAttribute('data-blocks-engine-navigation-run');
                    continue;
                }
                $changed = true;
            }
            if ($changed) $files[$index]['content'] = HtmlSerialization::documentHtml($documents[$index]);
        }
        return $files;
    }

    /**
     * Compare the same single-link item across captures. Only a demonstrated
     * current/resting pair can own a binding; unrelated label differences remain
     * authored content. Retained labels bind attributes; ordinary navigation
     * labels bind descendant inline paint, leaving anchor projection unchanged.
     * The binding travels with labelMarkup or navigation-link label HTML.
     */
    private static function bindRetainedLabels(array $files, string $entryPath): array
    {
        $documents = array();
        $groups = array();
        $menus = array();
        $root = PageRoute::entryRoot(array(array('entrypoint' => true, 'source_path' => $entryPath)));
        foreach ($files as $index => $file) {
            if ('html' !== ($file['kind'] ?? null) || !is_string($file['content'] ?? null) || !preg_match('/<nav\b|\brole=["\']?navigation\b/i', $file['content'])) continue;
            $document = HtmlSerialization::parseFragment($file['content']);
            $documents[$index] = $document;
            foreach ((new DOMXPath($document))->query('//*[self::nav or @role="navigation"]//a[@href]') ?: array() as $link) {
                if (!$link instanceof DOMElement) continue;
                $label = $link;
                while ($label->parentNode instanceof DOMElement && !self::isNavigationLandmark($label->parentNode) && 'label' !== strtolower($label->tagName) && 1 === $label->parentNode->getElementsByTagName('a')->length) $label = $label->parentNode;
                $nodes = array(); $shape = array(); $current = false;
                foreach (SourceDom::subtreeElements($label) as $node) {
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
                $target = ArtifactPath::resolveRelativePath(ltrim($href, '/'), str_starts_with($href, '/') ? $entryPath : (string) ($file['path'] ?? ''));
                $navigation = $link->parentNode;
                while ($navigation instanceof DOMElement && !self::isNavigationLandmark($navigation)) $navigation = $navigation->parentNode;
                $menu = (string) $navigation->getNodePath();
                // A dialog's role="navigation" menu keeps its own markup rather
                // than lowering as navigation links (a <nav> still lowers as
                // core/navigation), so its links carry their route state like a label.
                $dialog = false;
                if ('nav' !== strtolower($navigation->tagName)) {
                    for ($cursor = $navigation; $cursor instanceof DOMElement; $cursor = $cursor->parentNode) $dialog = $dialog || 'dialog' === strtolower($cursor->tagName) || 'dialog' === SourceDom::role($cursor);
                }
                $self = self::labelRoute($target) === self::labelRoute((string) ($file['path'] ?? ''));
                $menus[$menu][$index]['self'] = ($menus[$menu][$index]['self'] ?? 0) + (int) $self;
                $menus[$menu][$index]['explicit'] = ($menus[$menu][$index]['explicit'] ?? false) || $current;
                $key = $menu . ':' . $target . ':' . implode('/', $shape) . ':' . trim($label->textContent);
                $groups[$key][] = array('index' => $index, 'nodes' => $nodes, 'current' => $current, 'self' => $self, 'menu' => $menu, 'retained' => $dialog || 'label' === strtolower($label->tagName));
            }
        }
        $changed = array();
        foreach ($groups as $rows) {
            // Explicit state owns the menu. Href inference is only a candidate:
            // every capture must have at most one self-targeting peer, and the
            // same item's descendant paint must demonstrate a stable pair.
            $menuRows = $menus[$rows[0]['menu']];
            if (!array_filter($menuRows, static fn(array $menu): bool => $menu['explicit'] || $menu['self'] > 1)
                && self::hasSelfTargetPaintPair($rows)) {
                foreach ($rows as &$row) $row['current'] = $row['self'];
                unset($row);
            }
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
                    // Ordinary links already project anchor classes and colours.
                    // Only descendant inline paint needs this cross-route binding.
                    if (!$rows[0]['retained'] && ('style' !== $name || 'a' === strtolower($rows[0]['nodes'][$position]->tagName))) continue;
                    $values = static function (array $peers) use ($position, $name): array {
                        return array_values(array_unique(array_map(static fn(array $row): ?string => $row['nodes'][$position]->hasAttribute($name) ? $row['nodes'][$position]->getAttribute($name) : null, $peers), SORT_REGULAR));
                    };
                    if ('style' === $name) {
                        $styles = array_map(static fn(array $row): array => self::inlineDeclarations($row['nodes'][$position]->getAttribute('style')), $rows);
                        $properties = array_unique(array_merge(...array_map('array_keys', $styles)));
                        $routeProperties = array();
                        foreach ($properties as $property) {
                            $restValues = array(); $activeValues = array();
                            foreach ($rows as $i => $row) {
                                if ($row['current']) $activeValues[] = $styles[$i][$property] ?? null;
                                else $restValues[] = $styles[$i][$property] ?? null;
                            }
                            $restValues = array_values(array_unique($restValues, SORT_REGULAR));
                            $activeValues = array_values(array_unique($activeValues, SORT_REGULAR));
                            // Stacking order follows the moving current item even
                            // on resting siblings; other paint needs a stable
                            // current/resting pair at declaration granularity.
                            if ((1 === count($restValues) && 1 === count($activeValues) && $restValues !== $activeValues)
                                || ('z-index' === $property && count(array_unique(array_column($styles, $property))) > 1)) {
                                $routeProperties[] = $property;
                            }
                        }
                        if (array() === $routeProperties) continue;
                        $bases = $styles;
                        foreach ($bases as &$base) {
                            foreach ($routeProperties as $property) unset($base[$property]);
                            ksort($base, SORT_STRING);
                        }
                        unset($base);
                        // Never hide a varying authored declaration behind a
                        // route binding for a different property on this node.
                        if (1 !== count(array_unique(array_map('serialize', $bases)))) continue;
                        $canonical = $bases[0];
                        $serialize = static fn(array $declarations): ?string => array() === $declarations ? null : implode(';', array_map(static fn(string $key, string $value): string => $key . ':' . $value, array_keys($declarations), $declarations));
                        $binding['rest']['style'] = $serialize($canonical);
                        $binding['current']['style'] = $serialize($styles[array_search(true, array_column($rows, 'current'), true)]);
                        foreach ($rows as $i => $row) {
                            $path = (string) ($files[$row['index']]['path'] ?? '');
                            $route = self::labelRoute(PageRoute::withoutEntryRoot($path, $root));
                            $binding['routes'][$route]['style'] = $serialize($styles[$i]);
                        }
                        ksort($binding['routes'], SORT_STRING);
                        continue;
                    }
                    $rest = $values($resting); $active = $values($selected);
                    // Builders number data handles per route (`Link.Active.5678`):
                    // the same handle at rest differs only in its digit runs, so
                    // each capture replays its own value on its own route.
                    $numbered = static fn(array $values): array => array_values(array_unique(array_map(static fn(?string $value): ?string => null === $value || !str_starts_with($name, 'data-') ? $value : preg_replace('/[0-9]+/', '#', $value), $values), SORT_REGULAR));
                    // A class token every current copy has and no resting copy
                    // has (or the reverse) is route state, even when another
                    // route's copy differs in its other tokens.
                    $tokens = static fn(array $values): array => array_map(static fn(?string $value): array => SourceDom::tokens((string) $value), $values);
                    $marks = static fn(array $own, array $other): array => array_diff(array_intersect(...array_merge($own, array(array_merge(...$own)))), array_merge(...$other));
                    $paired = 'class' === $name
                        ? array() !== $marks($tokens($active), $tokens($rest)) || array() !== $marks($tokens($rest), $tokens($active))
                        : 1 === count($numbered($rest)) && 1 === count($numbered($active)) && $numbered($rest) !== $numbered($active);
                    if (!$paired) continue;
                    $binding['rest'][$name] = $rest[0]; $binding['current'][$name] = $active[0];
                    if (1 === count($rest) && 1 === count($active)) continue;
                    foreach ($rows as $row) {
                        $node = $row['nodes'][$position];
                        $binding['routes'][self::labelRoute(PageRoute::withoutEntryRoot((string) ($files[$row['index']]['path'] ?? ''), $root))][$name] = $node->hasAttribute($name) ? $node->getAttribute($name) : null;
                    }
                    ksort($binding['routes'], SORT_STRING);
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

    private static function isNavigationLandmark(DOMElement $element): bool
    {
        return 'nav' === strtolower($element->tagName) || 'navigation' === SourceDom::role($element);
    }

    /** Use the same route identity for self-target evidence and replay bindings. */
    private static function labelRoute(string $path): string
    {
        return '/' . trim((string) preg_replace('~(?:^|/)index(?:\\.html?)?$|\\.html?$~i', '', ArtifactPath::stripQueryAndFragment($path)), '/');
    }

    /** Href alone (or capture-varying stacking order alone) is not state evidence. */
    private static function hasSelfTargetPaintPair(array $rows): bool
    {
        $selected = array_filter($rows, static fn(array $row): bool => $row['self']);
        $resting = array_filter($rows, static fn(array $row): bool => !$row['self']);
        if (array() === $selected || array() === $resting) return false;
        foreach ($rows[0]['nodes'] as $position => $node) {
            if (0 === $position || 'a' === strtolower($node->tagName)) continue;
            $styles = array_map(static fn(array $row): array => self::inlineDeclarations($row['nodes'][$position]->getAttribute('style')), $rows);
            foreach (array_unique(array_merge(...array_map('array_keys', $styles))) as $property) {
                if ('z-index' === $property) continue;
                $active = array(); $rest = array();
                foreach ($rows as $i => $row) {
                    if ($row['self']) $active[] = $styles[$i][$property] ?? null;
                    else $rest[] = $styles[$i][$property] ?? null;
                }
                $active = array_values(array_unique($active, SORT_REGULAR));
                $rest = array_values(array_unique($rest, SORT_REGULAR));
                if (1 === count($active) && 1 === count($rest) && $active !== $rest) return true;
            }
        }
        return false;
    }

    /** Split only at CSS declaration boundaries, never inside strings or functions. */
    private static function inlineDeclarations(string $style): array
    {
        $state = CssSyntaxScanner::state();
        $declarations = array(); $start = 0;
        for ($offset = 0, $length = strlen($style); $offset <= $length;) {
            if ($offset === $length || (';' === $style[$offset] && '' === $state['quote'] && !$state['comment'] && 0 === $state['parens'] && 0 === $state['brackets'])) {
                $declaration = trim(substr($style, $start, $offset - $start));
                $colon = strpos($declaration, ':');
                if (false !== $colon) {
                    $property = trim(substr($declaration, 0, $colon));
                    if (!str_starts_with($property, '--')) $property = strtolower($property);
                    $declarations[$property] = trim(substr($declaration, $colon + 1));
                }
                $start = ++$offset;
                continue;
            }
            $next = CssSyntaxScanner::consume($style, $offset, $state);
            if (null === $next) return array();
            $offset = $next;
        }
        return $declarations;
    }

    public const CURRENT_SOURCES_ATTRIBUTE = 'data-blocks-engine-current-sources';

    public const CURRENT_SECTION_ATTRIBUTE = 'data-blocks-engine-current-section';

    private static function sortedClasses(string $classes): string
    {
        $list = SourceDom::tokens($classes);
        sort($list, SORT_STRING);
        return implode(' ', array_unique($list));
    }

    private const NAVIGATION_ROOT = "self::nav or @role='navigation' or ((self::ul or self::ol) and (@role='menu' or @role='menubar'))";

    /**
     * A navigation item the source marks current (a current-state class token
     * or aria-current on the item or its own link) on some captures and at rest
     * on others is route state. When its own link does not name exactly the
     * captures that mark it (a URL-less dropdown toggle, a section parent that
     * stays current on its child pages), the served URL cannot re-derive it, so
     * every capture's copy of the item records the captures that marked it.
     * An item is the same item across captures at the same position in the
     * same-ordered navigation, with the same label. A navigation is a `<nav>`,
     * a `role="navigation"` landmark, or a WAI-ARIA `menu`/`menubar` list
     * outside one: builders mark a header menu up as either.
     *
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private static function bindCurrentNavigationSources(array $files): array
    {
        $documents = array();
        $items = array();
        foreach ($files as $index => $file) {
            if ('html' !== ($file['kind'] ?? null) || !is_string($file['content'] ?? null) || 1 !== preg_match('/<nav\b|\brole=["\']?(?:navigation|menu|menubar)\b/i', $file['content'])) continue;
            $document = HtmlSerialization::parseFragment($file['content']);
            $documents[$index] = $document;
            $xpath = new DOMXPath($document);
            foreach ($xpath->query('//*[' . self::NAVIGATION_ROOT . '][not(ancestor::*[' . self::NAVIGATION_ROOT . '])]') ?: array() as $position => $navigation) {
                if (!$navigation instanceof DOMElement) continue;
                $root = (string) $navigation->getNodePath();
                foreach ($xpath->query('.//li | .//a[not(ancestor::li)]', $navigation) ?: array() as $item) {
                    if (!$item instanceof DOMElement) continue;
                    $link = 'a' === strtolower($item->tagName) ? $item : null;
                    $current = self::marksCurrent($item);
                    foreach ($item->childNodes as $child) {
                        if (!$child instanceof DOMElement || !in_array(strtolower($child->tagName), array('a', 'button'), true)) continue;
                        $link ??= $child;
                        $current = $current || self::marksCurrent($child);
                    }
                    $boxed = false;
                    if (null === $link && 'li' === strtolower($item->tagName)) {
                        // A boxed submenu toggle is evidence only of its section.
                        foreach ($xpath->query('.//a | .//button', $item) ?: array() as $candidate) {
                            for ($cursor = $candidate->parentNode; $cursor instanceof DOMElement && !$cursor->isSameNode($item); $cursor = $cursor->parentNode) {
                                if (in_array(strtolower($cursor->tagName), array('ul', 'ol'), true) || 'menu' === $cursor->getAttribute('role')) continue 2;
                            }
                            $link = $candidate instanceof DOMElement ? $candidate : null;
                            $boxed = null !== $link;
                            break;
                        }
                    }
                    if (null === $link) continue;
                    $key = $position . substr((string) $item->getNodePath(), strlen($root)) . ':' . trim((string) preg_replace('/\s+/', ' ', $link->textContent));
                    $items[$key][$index] = array('node' => $item, 'current' => $current, 'href' => trim($link->getAttribute('href')), 'classes' => $link->getAttribute('class'), 'section' => $item !== $link && null !== $xpath->query('.//ul | .//ol | .//*[@role="menu"]', $item)?->item(0));
                    if ($boxed) $items[$key][$index]['current'] = false;
                }
            }
        }
        $route = static fn(string $path): string => trim((string) preg_replace('~(?:^|/)index(?:\.html?)?$|\.html?$~i', '', ArtifactPath::stripQueryAndFragment($path)), '/');
        $changed = array();
        foreach ($items as $rows) {
            // A URL-less submenu parent whose own link reads differently by
            // route is painted as the section holding the served item.
            $first = reset($rows);
            if (count($rows) > 1 && $first['section'] && ('' === $first['href'] || '#' === $first['href'][0])
                && count(array_unique(array_map(static fn(array $row): string => self::sortedClasses($row['classes']), $rows))) > 1) {
                foreach ($rows as $index => $row) {
                    $row['node']->setAttribute(self::CURRENT_SECTION_ATTRIBUTE, 'true');
                    $changed[$index] = true;
                }
            }
            $current = array_keys(array_filter($rows, static fn(array $row): bool => $row['current']));
            if (array() === $current || count($current) === count($rows)) continue;
            $named = array();
            foreach ($rows as $index => $row) {
                $path = (string) ($files[$index]['path'] ?? '');
                $href = $row['href'];
                if ('' === $href || ArtifactPath::namesSection($href) || str_starts_with($href, '//') || preg_match('~^[a-z][a-z0-9+.-]*:~i', $href)) continue;
                $target = str_starts_with($href, '/') ? ltrim($href, '/') : ArtifactPath::resolveRelativePath($href, $path);
                if ($route($target) === $route($path)) $named[] = $index;
            }
            if ($named === $current) continue;
            $sources = (string) json_encode(array_values(array_map(static fn(int $index): string => (string) ($files[$index]['path'] ?? ''), $current)), JSON_UNESCAPED_SLASHES);
            foreach ($rows as $index => $row) {
                $row['node']->setAttribute(self::CURRENT_SOURCES_ATTRIBUTE, $sources);
                $changed[$index] = true;
            }
        }
        foreach (array_keys($changed) as $index) {
            $html = HtmlSerialization::documentHtml($documents[$index]);
            if (is_string($html)) $files[$index]['content'] = $html;
        }
        return $files;
    }

    private static function marksCurrent(DOMElement $element): bool
    {
        return MenuVocabulary::containsCurrentStateToken($element->getAttribute('class'))
            || ($element->hasAttribute('aria-current') && !in_array(strtolower(trim($element->getAttribute('aria-current'))), array('', 'false'), true));
    }

    private const CHROME_LANDMARKS = '//header | //footer | //*[@role="banner"] | //*[@role="contentinfo"]';

    /**
     * Only a site with several routes has chrome to share; a single document
     * keeps its authored bytes.
     *
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private static function withRestingChrome(array $files): array
    {
        $documents = array();
        foreach ($files as $index => $file) {
            if ('html' !== ($file['kind'] ?? null) || !is_string($file['content'] ?? null) || !preg_match('/<(?:header|footer)\b|\srole\s*=\s*["\']?(?:banner|contentinfo)/i', $file['content'])) continue;
            $documents[$index] = null;
        }
        if (count($documents) < 2) return $files;
        $changed = array();
        $elements = array();
        $stylesheets = null;
        foreach (array_keys($documents) as $index) {
            $document = HtmlSerialization::parseFragment($files[$index]['content']);
            $documents[$index] = $document;
            $xpath = new DOMXPath($document);
            $landmarks = array();
            foreach ($xpath->query(self::CHROME_LANDMARKS) ?: array() as $landmark) {
                if (!$landmark instanceof DOMElement) continue;
                $outer = $landmark;
                for ($cursor = $landmark->parentNode; $cursor instanceof DOMElement; $cursor = $cursor->parentNode) if (self::isChromeLandmark($cursor)) $outer = $cursor;
                if ($outer === $landmark) $landmarks[] = $landmark;
            }
            foreach ($landmarks as $landmark) {
                // Each target keeps its document order ahead of the landmark.
                foreach (iterator_to_array($xpath->query('.//*[@' . CaptureEvidence::ANCHOR_TARGET . ']', $landmark) ?: array()) as $target) {
                    if (!$target instanceof DOMElement || null !== $target->firstElementChild || '' !== trim($target->textContent) || !$landmark->parentNode instanceof \DOMNode) continue;
                    $landmark->parentNode->insertBefore($target, $landmark);
                    $changed[$index] = true;
                }
                foreach ($xpath->query('descendant-or-self::*[contains(@class, "' . CaptureEvidence::RESPONSIVE_PROJECTION_CLASS_PREFIX . '")]', $landmark) ?: array() as $hooked) {
                    if (!$hooked instanceof DOMElement) continue;
                    $classes = self::classes($hooked);
                    $kept = array_values(array_filter($classes, static function (string $class) use ($hooked, $files, &$stylesheets): bool {
                        if (!preg_match('/^' . preg_quote(CaptureEvidence::RESPONSIVE_PROJECTION_CLASS_PREFIX, '/') . '[a-f0-9]{12}$/', $class)) return true;
                        $stylesheets ??= self::stylesheets($files);
                        return !self::restatesInlineStyle($class, $hooked->getAttribute('style'), $stylesheets);
                    }));
                    if ($kept === $classes) continue;
                    if (array() === $kept) $hooked->removeAttribute('class'); else $hooked->setAttribute('class', implode(' ', $kept));
                    $changed[$index] = true;
                }
                $nodes = array_merge(array($landmark), iterator_to_array($xpath->query('.//*[@id]', $landmark) ?: array()));
                foreach ($nodes as $node) {
                    if (!$node instanceof DOMElement || '' === trim($node->getAttribute('id'))) continue;
                    $elements[strtolower($node->tagName) . '#' . $node->getAttribute('id')][$index][] = $node;
                }
            }
        }
        foreach ($elements as $occurrences) {
            if (count($occurrences) < 2) continue;
            $nodes = array();
            foreach ($occurrences as $index => $rows) {
                if (1 !== count($rows)) continue 2;
                $nodes[$index] = $rows[0];
            }
            $sets = array_map(static fn(DOMElement $node): array => self::classes($node), $nodes);
            $varying = array_values(array_diff(array_unique(array_merge(...array_values($sets))), array_intersect(...array_values($sets))));
            if (array() === $varying) continue;
            $stylesheets ??= self::stylesheets($files);
            foreach ($varying as $token) {
                foreach ($nodes as $index => $node) {
                    if (in_array($token, $sets[$index], true) && !self::restsWithoutClass($token, $sets[$index], $stylesheets)) continue 2;
                }
                foreach ($nodes as $index => $node) {
                    if (!in_array($token, $sets[$index], true)) continue;
                    $sets[$index] = array_values(array_diff($sets[$index], array($token)));
                    if (array() === $sets[$index]) $node->removeAttribute('class');
                    else $node->setAttribute('class', implode(' ', $sets[$index]));
                    $changed[$index] = true;
                }
            }
        }
        foreach (array_keys($changed) as $index) {
            $html = HtmlSerialization::documentHtml($documents[$index]);
            if (is_string($html)) $files[$index]['content'] = $html;
        }
        return $files;
    }

    /**
     * A capture carries a class token that a source script toggles with the
     * viewport as a width-scoped alias, and copies every rule naming the
     * token for the alias. When no rule names the token, the alias has no
     * rules either: it renders nothing, yet its name encodes the breakpoint
     * measured on its own route, so the same chrome carries a different name
     * on each route and stops comparing as one design. An alias that no
     * stylesheet names is dropped; an alias with rules keeps its name.
     *
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private static function withoutUnstyledViewportClassAliases(array $files): array
    {
        $prefix = CaptureEvidence::VIEWPORT_CLASS_PREFIX;
        $aliasPattern = '/(?<![A-Za-z0-9_-])' . preg_quote($prefix, '/') . '[a-f0-9]{12}(?![A-Za-z0-9_-])/';
        $carriers = array_filter($files, static fn(array $file): bool => 'html' === ($file['kind'] ?? null) && is_string($file['content'] ?? null) && str_contains($file['content'], $prefix));
        if (array() === $carriers) return $files;
        $stylesheets = null;
        $styled = array();
        $isStyled = static function (string $alias) use ($files, &$stylesheets, &$styled): bool {
            if (isset($styled[$alias])) return $styled[$alias];
            $stylesheets ??= self::stylesheets($files);
            $pattern = '/\.' . preg_quote($alias, '/') . '(?![A-Za-z0-9_-])/';
            foreach ($stylesheets as $css) if (str_contains($css, $alias) && preg_match($pattern, $css)) return $styled[$alias] = true;
            return $styled[$alias] = false;
        };
        foreach ($carriers as $index => $file) {
            $content = preg_replace_callback('/(\sclass\s*=\s*)(["\'])(.*?)\2/is', static function (array $match) use ($prefix, $aliasPattern, $isStyled): string {
                if (!str_contains($match[3], $prefix)) return $match[0];
                $classes = SourceDom::tokens($match[3]);
                $kept = array_values(array_filter($classes, static fn(string $class): bool => !preg_match($aliasPattern, $class) || $isStyled($class)));
                return $kept === $classes ? $match[0] : $match[1] . $match[2] . implode(' ', $kept) . $match[2];
            }, $file['content']);
            if (is_string($content)) $files[$index]['content'] = $content;
        }
        return $files;
    }

    /**
     * Atomic CSS builders name a document's utility classes in the order that
     * document first uses them, so the same chrome control can carry
     * `c2-19` on one route and `c2-1c` on another for the same declaration,
     * while `c2-19` means something else on the second route. A class is read
     * by its meaning: every rule of the document's own style elements that
     * names it, with the class itself left out of the selector and with the
     * classes chrome does not wear (a route-numbered page scope such as
     * `.framer-7gg9x` around `.framer-1499lr3`) read as an ancestor slot. On chrome
     * (navigation and landmark subtrees), a meaning whose class name differs
     * across routes, or whose name means something else on another route, is
     * given one content-addressed name on every route: the element swaps the
     * class and its document gains a copy of each rule right after the
     * original, so the cascade is unchanged. Classes that also appear in a
     * stylesheet file, or only in compound or functional selectors, keep
     * their names.
     *
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private static function withRouteAlignedChromeClasses(array $files): array
    {
        $documents = array(); $sheets = array(); $global = '';
        foreach ($files as $index => $file) {
            if (!is_string($file['content'] ?? null)) continue;
            if ('inline-style' === ($file['source'] ?? null)) {
                foreach ($file['inline_references'] ?? array($file) as $reference) $sheets[(string) ($reference['source_path'] ?? '')][(int) ($reference['stylesheet_index'] ?? 0)] = $index;
            } elseif ('css' === ($file['kind'] ?? null)) {
                $global .= "\n" . $file['content'];
            } elseif ('html' === ($file['kind'] ?? null) && preg_match('/<(?:nav|header|footer)\b|\srole\s*=\s*["\']?(?:navigation|banner|contentinfo)/i', $file['content'])) {
                $documents[$index] = null;
            }
        }
        $documents = array_filter($documents, static fn($unused, int $index): bool => isset($sheets[(string) ($files[$index]['path'] ?? '')]), ARRAY_FILTER_USE_BOTH);
        if (count($documents) < 2) return $files;
        $transformer = new CssStylesheetTransformer();
        $classPattern = '/\.(-?[A-Za-z_][A-Za-z0-9_-]*)/';
        $named = static fn(string $class): string => '/\.' . preg_quote($class, '/') . '(?![A-Za-z0-9_-])/';
        $scoped = array(); $meanings = array(); $worn = array();
        foreach (array_keys($documents) as $index) {
            $document = HtmlSerialization::parseFragment($files[$index]['content']);
            $documents[$index] = $document;
            foreach ((new DOMXPath($document))->query('//*[self::nav or self::header or self::footer or @role="navigation" or @role="banner" or @role="contentinfo"]/descendant-or-self::*[@class]') ?: array() as $element) {
                if (!$element instanceof DOMElement) continue;
                $scoped[$index][] = $element;
                foreach (self::classes($element) as $class) $worn[$class] = true;
            }
        }
        foreach (array_keys($documents) as $index) {
            // Each class's rules in this document's own style elements, in order.
            $rules = array(); $unaliasable = array();
            $own = $sheets[(string) $files[$index]['path']];
            ksort($own);
            foreach ($own as $sheet) {
                $transformer->visitStyleRules(CssSyntaxScanner::withoutComments((string) $files[$sheet]['content']), static function (string $prelude, string $body, array $ancestors) use (&$rules, &$unaliasable, $classPattern, $named, $worn): void {
                    foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array($prelude) as $selector) {
                        if (!preg_match_all($classPattern, $selector, $classes)) continue;
                        foreach (array_unique($classes[1]) as $class) {
                            $tokenized = CssSelectorTokenizer::tokenize(trim($selector));
                            $alone = $tokenized['supported'];
                            foreach ($tokenized['compounds'] ?? array() as $compound) {
                                if (preg_match($named($class), $compound) && (str_contains($compound, '(') || preg_match_all($classPattern, $compound) > 1)) $alone = false;
                            }
                            if (!$alone) { $unaliasable[$class] = true; continue; }
                            // Copies keep each route's own scope, so the slot only lets equal rules meet.
                            $read = preg_replace_callback($classPattern, static fn(array $other): string => isset($worn[$other[1]]) ? $other[0] : ".\x02", (string) preg_replace($named($class), ".\x01", trim($selector)));
                            $rules[$class][] = implode("\0", $ancestors) . "\0" . $read . "\0" . RenderEquivalentMarkup::canonicalStyle($body);
                        }
                    }
                });
            }
            foreach ($rules as $class => $meaning) {
                if (isset($unaliasable[$class]) || preg_match($named($class), $global)) continue;
                $meanings[$index][$class] = hash('sha256', implode("\n", $meaning));
            }
        }
        $names = array(); $readings = array();
        foreach ($meanings as $index => $classes) {
            foreach ($classes as $class => $meaning) {
                $readings[$class][$meaning] = true;
                $names[$meaning][$index][] = $class;
            }
        }
        // A meaning is route-numbered when routes name it differently, or when
        // one of its names reads differently on another route.
        $unstable = array();
        foreach ($names as $meaning => $routes) {
            if (1 < count(array_unique(array_map(static fn(array $classes): string => implode(' ', $classes), $routes)))) { $unstable[$meaning] = true; continue; }
            foreach (reset($routes) as $class) if (count($readings[$class]) > 1) $unstable[$meaning] = true;
        }
        if (array() === $unstable) return $files;
        $sheetAliases = array();
        foreach ($meanings as $index => $classes) {
            $aliases = array();
            foreach ($classes as $class => $meaning) if (isset($unstable[$meaning])) $aliases[$class] = 'route-class-' . substr($meaning, 0, 12);
            if (array() === $aliases) continue;
            $used = array();
            foreach ($scoped[$index] ?? array() as $element) {
                $classList = self::classes($element);
                foreach ($classList as $class) if (isset($aliases[$class])) $used[$class] = $aliases[$class];
                $aliased = array_values(array_unique(array_map(static fn(string $class): string => $aliases[$class] ?? $class, $classList)));
                if ($aliased !== $classList) $element->setAttribute('class', implode(' ', $aliased));
            }
            // Only aliases chrome now wears need their rules copied.
            if (array() === $used) continue;
            foreach ($sheets[(string) $files[$index]['path']] as $sheet) $sheetAliases[$sheet] = ($sheetAliases[$sheet] ?? array()) + $used;
            $html = HtmlSerialization::documentHtml($documents[$index]);
            if (is_string($html)) $files[$index]['content'] = $html;
        }
        // Each rule naming an aliased class gains a copy for its alias right
        // after it, so both names keep their place in the cascade.
        $withCopies = static fn(string $css, array $aliases): string => $transformer->transform($css, static function (string $prelude, string $body) use ($aliases, $classPattern, $named): string|array {
            $copies = array();
            foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
                preg_match_all($classPattern, $selector, $classes);
                foreach (array_unique($classes[1]) as $class) if (isset($aliases[$class])) $copies[] = preg_replace($named($class), '.' . $aliases[$class], trim($selector));
            }
            return array() === $copies ? $prelude : array(array('prelude' => $prelude, 'body' => $body), array('prelude' => implode(',', array_unique($copies)), 'body' => $body));
        });
        $restyled = array();
        foreach ($sheetAliases as $sheet => $aliases) {
            $content = $withCopies((string) $files[$sheet]['content'], $aliases);
            if ($content === $files[$sheet]['content']) continue;
            $files[$sheet]['content'] = $content;
            // An inline sheet is addressed by its bytes, as normalization addresses it.
            if (str_starts_with((string) ($files[$sheet]['path'] ?? ''), 'inline/')) {
                $files[$sheet]['path'] = 'inline/' . hash('sha256', ($files[$sheet]['intent'] ?? '') . "\0" . ($files[$sheet]['type'] ?? '') . "\0" . $content) . '.' . ($files[$sheet]['kind'] ?? 'css');
            }
            foreach ($files[$sheet]['inline_references'] ?? array($files[$sheet]) as $reference) {
                $restyled[(string) ($reference['source_path'] ?? '')][(int) ($reference['stylesheet_index'] ?? 0)] = $aliases;
            }
        }
        // Inline sheets are re-read from their document's style elements when
        // a staged compile normalizes one page, so those elements carry the
        // same copies: an aliased class never outlives its rules.
        foreach ($files as $index => $file) {
            if ('html' !== ($file['kind'] ?? null) || !is_string($file['content'] ?? null) || !isset($restyled[(string) ($file['path'] ?? '')])) continue;
            $html = $file['content'];
            $position = 0;
            $splices = array();
            foreach (StyleTagScanner::scan($html) as $style) {
                if ('' === trim($style['content']) || !StyleTagScanner::isCssType(StyleTagScanner::attribute($style['attributes'], 'type'))) continue;
                $aliases = $restyled[(string) $file['path']][++$position] ?? null;
                if (null === $aliases) continue;
                // The raw text ends where the closing tag opens.
                $start = $style['offset'] + (int) strrpos(substr($html, $style['offset'], $style['end_offset'] - $style['offset']), '</') - strlen($style['content']);
                $splices[] = array($start, strlen($style['content']), $withCopies($style['content'], $aliases));
            }
            foreach (array_reverse($splices) as [$start, $length, $css]) $html = substr_replace($html, $css, $start, $length);
            $files[$index]['content'] = $html;
        }
        return $files;
    }

    private static function isChromeLandmark(DOMElement $element): bool
    {
        return in_array(strtolower($element->tagName), array('header', 'footer'), true) || in_array(SourceDom::role($element), array('banner', 'contentinfo'), true);
    }

    /**
     * Author CSS of the artifact: stylesheet files and document style elements.
     *
     * @param array<int, array<string, mixed>> $files
     * @return list<string>
     */
    private static function stylesheets(array $files): array
    {
        $sheets = array();
        foreach ($files as $file) {
            if (!is_string($file['content'] ?? null)) continue;
            if ('css' === ($file['kind'] ?? null) || preg_match('/\.css$/i', (string) ($file['path'] ?? ''))) $sheets[] = $file['content'];
            elseif ('html' === ($file['kind'] ?? null) && preg_match_all('/<style\b[^>]*>(.*?)<\/style\s*>/is', $file['content'], $matches)) array_push($sheets, ...$matches[1]);
        }
        return array_values(array_map(static fn(string $css): string => CssSyntaxScanner::withoutComments($css), $sheets));
    }

    /**
     * Whether an element with `$classes` renders the same at rest without
     * `$token`. At least one rule must name the token, and each of its
     * selectors must either require a class the element lacks or apply only
     * transition timing.
     *
     * @param list<string> $classes
     * @param list<string> $stylesheets
     */
    private static function restsWithoutClass(string $token, array $classes, array $stylesheets): bool
    {
        $pattern = '/\.' . preg_quote($token, '/') . '(?![A-Za-z0-9_\x{80}-\x{10FFFF}-])/u';
        $named = false;
        foreach ($stylesheets as $css) {
            if (!str_contains($css, $token)) continue;
            foreach (self::styleRules($css) as $rule) {
                if (!preg_match($pattern, $rule['prelude'])) continue;
                $named = true;
                if (null === $rule['body']) return false;
                $selectors = CssStylesheetTransformer::splitSelectorList($rule['prelude']);
                if (null === $selectors) return false;
                $timingOnly = null;
                foreach ($selectors as $selector) {
                    if (!preg_match($pattern, $selector)) continue;
                    $tokens = CssSelectorTokenizer::tokenize(trim($selector));
                    if (!$tokens['supported']) return false;
                    $unmatched = false;
                    foreach ($tokens['compounds'] as $compound) {
                        if (!preg_match($pattern, $compound)) continue;
                        // A functional pseudo-class can match without the classes it names.
                        if (str_contains($compound, '(')) return false;
                        preg_match_all('/\.(-?[A-Za-z_\x{80}-\x{10FFFF}][A-Za-z0-9_\x{80}-\x{10FFFF}-]*)/u', $compound, $required);
                        if (array() !== array_diff($required[1], $classes, array($token))) $unmatched = true;
                    }
                    if ($unmatched) continue;
                    $timingOnly ??= self::setsOnlyTransitionTiming($rule['body']);
                    if (!$timingOnly) return false;
                }
            }
        }
        return $named;
    }

    /**
     * Whether every rule naming the hook selects it alone and declares, without
     * `!important`, exactly the element's inline declarations.
     *
     * @param list<string> $stylesheets
     */
    private static function restatesInlineStyle(string $hook, string $style, array $stylesheets): bool
    {
        $inline = RenderEquivalentMarkup::canonicalStyle($style);
        if ('' === $inline) return false;
        $named = false;
        foreach ($stylesheets as $css) {
            if (!str_contains($css, $hook)) continue;
            foreach (self::styleRules($css) as $rule) {
                if (!str_contains($rule['prelude'], $hook)) continue;
                if ('.' . $hook !== $rule['prelude'] || null === $rule['body']) return false;
                $declarations = preg_replace('/\s*!\s*important\s*(?=;|$)/i', '', $rule['body']) ?? $rule['body'];
                if (RenderEquivalentMarkup::canonicalStyle($declarations) !== $inline) return false;
                $named = true;
            }
        }
        return $named;
    }

    private static function setsOnlyTransitionTiming(string $body): bool
    {
        $names = 0;
        foreach (explode(';', $body) as $declaration) {
            if ('' === trim($declaration)) continue;
            $colon = strpos($declaration, ':');
            if (false === $colon || !preg_match('/^transition(?:-[a-z-]+)?$/', strtolower(trim(substr($declaration, 0, $colon))))) return false;
            ++$names;
        }
        return $names > 0;
    }

    /**
     * Style rules of a stylesheet, through conditional group rules. A rule
     * holding nested blocks has a null body: its effect is not read here.
     *
     * @return list<array{prelude: string, body: ?string}>
     */
    private static function styleRules(string $css): array
    {
        $rules = array();
        (new CssStylesheetTransformer())->visitStyleRules($css, static function (string $prelude, string $body) use (&$rules): void {
            $rules[] = array('prelude' => trim($prelude), 'body' => str_contains($body, '{') ? null : $body);
        });
        return $rules;
    }

    /**
     * The compiler retains page-item state until navigation can lift its paint
     * to runtime selectors. Standalone normalization still removes router
     * tokens; fragment links never retain them because they name no page.
     */
    public static function normalize(string $html, bool $preserveNavigationPresentation = false): string
    {
        if (! preg_match('/\saria-current\s*=|\sdata-status\s*=\s*["\']?active|\sstyle\s*=\s*(?:"[\s;]*"|\'[\s;]*\')/i', $html)) {
            return $html;
        }
        $document = HtmlSerialization::parseFragment($html);

        $changed = false;
        $xpath = new DOMXPath($document);
        // An inline style with no declarations has no resting presentation.
        // Remove it on the source DOM, before labels or identities serialize it.
        foreach ($xpath->query('//*[@style]') ?: array() as $element) {
            if ('' === trim($element->getAttribute('style'), "; \t\n\r\0\x0B")) {
                $element->removeAttribute('style');
                $changed = true;
            }
        }
        $current = array();
        $resting = array();
        foreach ($xpath->query('//a[@href]') ?: array() as $link) {
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
            return $changed ? HtmlSerialization::documentHtml($document) : $html;
        }

        if (array() !== $resting) {
            $restingClasses = array();
            foreach ($resting as $chain) {
                foreach ($chain as $level => $element) {
                    foreach (self::classes($element) as $class) {
                        $restingClasses[$level][$class] = true;
                    }
                }
            }
            foreach ($current as $chain) {
                foreach ($chain as $level => $element) {
                    $state = array_values(array_filter(self::classes($element), static fn(string $class): bool => ! isset($restingClasses[$level][$class]) && (MenuVocabulary::containsCurrentStateToken($class) || in_array($class, SourceClassIdentity::DISCLOSURE_CLASSES, true) || 'page_item' === $class || 1 === preg_match('/^page-item-\d+$/', $class))));
                    if (array() === $state) {
                        continue;
                    }
                    // Keep page-item evidence for navigation's current-paint
                    // projection, but not router state on fragment links (Core
                    // cannot restore those as the current page).
                    $link = 'a' === strtolower($element->tagName) ? $element : $element->getElementsByTagName('a')->item(0);
                    if ($preserveNavigationPresentation && self::inMenu($element) && $link instanceof DOMElement && !ArtifactPath::namesSection($link->getAttribute('href'))) continue;
                    $elementState = array_filter($state, static fn(string $class): bool => ('page_item' !== $class && 1 !== preg_match('/^page-item-\d+$/', $class)) || AuthorSelectorProjectionState::hasWordPressCurrentSignal($element));
                    if (array() === $elementState) {
                        continue;
                    }
                    $changed = true;
                    $classes = array_values(array_diff(self::classes($element), $elementState));
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
            if ('nav' === strtolower($cursor->tagName) || 'navigation' === strtolower($cursor->getAttribute('role')) || MenuVocabulary::isCurrentPageLinkRun($cursor)) {
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
            if (1 !== self::ownLinkCount($cursor)) {
                break;
            }
            $chain[] = $cursor;
        }

        return $chain;
    }

    /**
     * Links an element holds. A menu item holding a submenu owns one link,
     * its label; the submenu's links belong to the submenu's own items.
     */
    private static function ownLinkCount(DOMElement $element): int
    {
        if ('li' !== strtolower($element->tagName)) {
            return $element->getElementsByTagName('a')->length;
        }
        $count = 0;
        foreach ($element->getElementsByTagName('a') as $link) {
            for ($cursor = $link->parentNode; $cursor instanceof DOMElement && $cursor !== $element; $cursor = $cursor->parentNode) {
                if (in_array(strtolower($cursor->tagName), array('ul', 'ol'), true)) {
                    continue 2;
                }
            }
            ++$count;
        }

        return $count;
    }

    /** @return list<string> */
    private static function classes(DOMElement $element): array
    {
        return array_values(array_unique(array_filter(SourceDom::tokens($element->getAttribute('class')), static fn(string $class): bool => '' !== $class)));
    }
}
