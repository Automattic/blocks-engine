<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\Support\BlockAttributes;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;
use Automattic\BlocksEngine\PhpTransformer\WordPress\SourceClassIdentity;

/**
 * Navigation markup as it renders when no page is current.
 *
 * A captured page marks its own menu item as current, opens the disclosure
 * that holds it and may tint the item. The same header captured on another
 * route marks a different item. Removing that route state, and giving every
 * item the resting presentation its peers share, lets one shared template
 * part serve every route while WordPress marks the current item at render.
 *
 * Stateless: every method is a pure function of its inputs.
 */
final class RestingNavigation
{
    /** Normalize route-specific current state inside captured dialog snapshot HTML. */
    private static function normalizeExistingPanelSnapshots(string $markup): string
    {
        $normalizeSnapshot = static function (string $snapshot): string {
            $data = json_decode($snapshot, true);
            if (!is_array($data)) return $snapshot;
            foreach (array('resting', 'opened') as $state) {
                if (!is_string($data[$state]['html'] ?? null)) continue;
                $html = $data[$state]['html'];
                $dom = HtmlSerialization::parseFragment('<div id="be-snapshot-root">' . $html . '</div>');
                $xpath = new \DOMXPath($dom);
                foreach ($xpath->query('//*[@aria-current="page"]') as $current) {
                    $siblings = array();
                    foreach ($current->parentNode->childNodes as $sibling) if ($sibling instanceof \DOMElement && $sibling->tagName === $current->tagName) $siblings[] = $sibling;
                    $shared = array();
                    foreach ($siblings as $sibling) if ($sibling !== $current) foreach (preg_split('/\\s+/', trim($sibling->getAttribute('class'))) ?: array() as $class) $shared[$class] = true;
                    $classes = array_filter(preg_split('/\\s+/', trim($current->getAttribute('class'))) ?: array(), static fn(string $class): bool => isset($shared[$class]));
                    if ($classes) $current->setAttribute('class', implode(' ', $classes)); else $current->removeAttribute('class');
                    $current->removeAttribute('aria-current');
                }
                $root = $dom->getElementById('be-snapshot-root');
                $out = ''; if ($root) foreach ($root->childNodes as $child) $out .= $dom->saveHTML($child);
                $data[$state]['html'] = $out;
            }
            return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $snapshot;
        };
        $markup = preg_replace_callback('/<!--\\s*wp:[^ ]+\\s+(\\{.*?\\})\\s*-->/s', static function (array $m) use ($normalizeSnapshot): string {
            $attrs = json_decode($m[1], true);
            if (!is_array($attrs)) return $m[0];
            $walk = static function (&$value) use (&$walk, $normalizeSnapshot): void {
                if (!is_array($value)) return;
                foreach ($value as $key => &$item) { if (CaptureEvidence::EXISTING_PANEL === $key && is_string($item)) $item = $normalizeSnapshot($item); else $walk($item); }
            };
            $walk($attrs);
            return '<!-- wp:' . preg_replace('/^<!--\\s*wp:([^ ]+).*/s', '$1', $m[0]) . ' ' . BlockAttributes::encode($attrs) . ' -->';
        }, $markup) ?? $markup;
        return preg_replace_callback('/(' . preg_quote(CaptureEvidence::EXISTING_PANEL, '/') . '=")([^"]*)(")/', static fn(array $m): string => $m[1] . htmlspecialchars($normalizeSnapshot(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')), ENT_QUOTES | ENT_HTML5, 'UTF-8') . $m[3], $markup) ?? $markup;
    }

    public static function markup(string $markup, bool $semanticIdentity = false): string
    {
        $markup = self::normalizeExistingPanelSnapshots($markup);
        $stateCarrierCounts = array();
        $linkColorCounts = array();
        $linkCount = 0;
        $restingColorCountsBySignature = array();
        preg_match_all('/<!--\s*wp:navigation(?:-link|-submenu)?\s+(\{.*?\})\s*(?:\/)?-->/s', $markup, $navigationMatches);
        foreach ($navigationMatches[0] as $index => $opening) {
            $attributes = $navigationMatches[1][$index];
            $attrs = json_decode($attributes, true);
            if (!is_array($attrs)) continue;
            $isLink = !preg_match('/<!--\s*wp:navigation\s/', $opening);
            if ($isLink) ++$linkCount;
            $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array();
            $current = in_array('blocks-engine-current-navigation-item', $classes, true);
            $signature = self::navigationClassSignature($classes);
            foreach ($classes as $class) {
                if (preg_match('/^blocks-engine-navigation-link-color-states-\d+$/', $class)) $stateCarrierCounts[$class] = ($stateCarrierCounts[$class] ?? 0) + 1;
                if ($isLink && preg_match('/^blocks-engine-navigation-link-color-[a-f0-9]{64}$/', $class)) {
                    $linkColorCounts[$class] = ($linkColorCounts[$class] ?? 0) + 1;
                    if (!$current) $restingColorCountsBySignature[$signature][$class] = ($restingColorCountsBySignature[$signature][$class] ?? 0) + 1;
                }
            }
        }
        $restingPeers = self::restingNavigationPeers($markup);
        $sharedLinkColors = array_keys(array_filter($linkColorCounts, static fn(int $count): bool => 1 < $linkCount && $linkCount - 1 === $count));
        $restingColorBySignature = array();
        foreach ($restingColorCountsBySignature as $signature => $counts) {
            arsort($counts, SORT_NUMERIC);
            $restingColorBySignature[$signature] = (string) array_key_first($counts);
        }
        $routeState = self::navigationRouteState($markup);
        $navigationIndex = -1; $submenuDepth = 0;
        $blockIndex = -1;
        $markup = preg_replace_callback('/<!--\s*(?:\/wp:navigation-submenu|wp:(navigation(?:-link|-submenu)?)\s+(\{.*?\})\s*(\/)?)\s*-->/s', static function (array $match) use ($semanticIdentity, $stateCarrierCounts, $sharedLinkColors, $restingColorBySignature, $restingPeers, $routeState, &$navigationIndex, &$blockIndex, &$submenuDepth): string {
            if (!isset($match[1])) { $submenuDepth = max(0, $submenuDepth - 1); return $match[0]; }
            ++$blockIndex;
            if ('navigation' === $match[1]) { ++$navigationIndex; $submenuDepth = 0; }
            $depth = $submenuDepth;
            if ('navigation-submenu' === $match[1] && empty($match[3])) ++$submenuDepth;
            $attrs = json_decode($match[2], true);
            if (!is_array($attrs)) return $match[0];
            $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array();
            $selfClosing = '' !== ($match[3] ?? '');
            if ('navigation' === $match[1] && isset($routeState['current_navigations'][$navigationIndex])) {
                // A menu that holds the served route is disclosed for that route only.
                $resting = self::withoutDisclosureClasses($classes);
                if ($resting !== $classes) {
                    $classes = $resting;
                    if (array() === array_filter($classes)) {
                        unset($attrs['className']);
                    } else {
                        $attrs['className'] = implode(' ', $classes);
                    }
                    $match[0] = self::navigationBlockOpening($match[1], $attrs, $selfClosing);
                }
            }
            $subject = $routeState['current_link_subjects'][$blockIndex] ?? null;
            if (is_array($subject) && !isset($attrs['metadata']['blocksEngineNavigationAnchor'])) {
                $classes = self::adoptedAnchorSubjectClasses($classes, $subject, $semanticIdentity);
                $attrs['className'] = implode(' ', $classes);
                $attrs['metadata']['blocksEngineNavigationAnchor'] = $subject;
                $match[0] = self::navigationBlockOpening($match[1], $attrs, $selfClosing);
            }
            $current = in_array('blocks-engine-current-navigation-item', $classes, true);
            $peer = $restingPeers[$navigationIndex . ':' . $depth] ?? null;
            $peerClasses = is_array($peer) ? (preg_split('/\\s+/', trim((string) ($peer['className'] ?? ''))) ?: array()) : array();
            if (is_array($peer) && 'navigation' !== $match[1] && ($semanticIdentity || $current || isset($routeState['current_submenus'][$blockIndex]))) {
                // A site builder paints the served route's item, and the submenu
                // holding it, with its disclosure classes. The chrome reads as it
                // rests: disclosure absent from the resting peers is route state,
                // not authored presentation, so it never reaches the shared part.
                $resting = self::withoutDisclosureClasses($classes, $peerClasses);
                if ($resting !== $classes) {
                    $classes = $resting;
                    if (array() === array_filter($classes)) unset($attrs['className']);
                    else $attrs['className'] = implode(' ', $classes);
                    $match[0] = self::navigationBlockOpening($match[1], $attrs, $selfClosing);
                }
            }
            if ($current && 'navigation-link' === $match[1] && is_array($peer)) {
                // A client router can paint the current item through its own
                // utility classes instead of an aria-current hook. The shared part
                // must not freeze one page's selection, so the item takes its
                // resting peers' presentation. The current-page state is restored
                // at render time; its color stays on the navigation root marker.
                // Rebuild in the peer's key order so identical presentation
                // serializes identically regardless of which page was current.
                // Stable item classes stay; a homepage marker is not current-page state.
                $own = array_diff_key($attrs, array_flip(array('className', 'style', 'color', 'typography', 'anchor', 'anchorClassName')));
                $attrs = array_merge($peer, $own);
                $peerClasses = preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array();
                // A current item may swap arbitrary author utilities rather
                // than name its state. Match the producer's structural proof:
                // shared presentation plus missing resting classes identifies
                // the item's peer-unique replacement classes as route state.
                $replaces = array() !== array_intersect($classes, $peerClasses) && array() !== array_diff($peerClasses, $classes);
                $stable = array_values(array_filter($classes, static fn(string $class): bool => '' !== $class && (!$replaces || str_starts_with($class, 'blocks-engine-') || str_starts_with($class, 'be-')) && !self::isCurrentPageClass($class, $peerClasses) && !preg_match('/^blocks-engine-navigation-(?:current|link)-color-[a-f0-9]{64}$/', $class) && !preg_match('/^blocks-engine-navigation-link-color-states-\d+$/', $class) && !preg_match('/^be-inline-geometry-[a-f0-9]{16}(?:-[a-f0-9]{16})?$/', $class) && !in_array($class, $peerClasses, true)));
                // The part keeps the item's own link-state carrier, which the
                // navigation-root current-color rule resolves its state against.
                $stateCarriers = $semanticIdentity ? array() : array_values(array_filter($classes, static fn(string $class): bool => 1 === preg_match('/^blocks-engine-navigation-link-color-states-\d+$/', $class)));
                $merged = array_values(array_unique(array_filter(array_merge($peerClasses, $stable, $stateCarriers), static fn(string $class): bool => '' !== $class)));
                if (array() === $merged) unset($attrs['className']); else $attrs['className'] = implode(' ', $merged);
                return '<!-- wp:' . $match[1] . ' ' . BlockAttributes::encode($attrs) . ' ' . (($match[3] ?? '') ? '/' : '') . '-->';
            }
            if (!$current && !$semanticIdentity) return $match[0];
            $isLink = 'navigation' !== $match[1];
            $peerClasses = is_array($peer) ? (preg_split('/\s+/', trim((string) ($peer['className'] ?? ''))) ?: array()) : array();
            // A link colour on a current item is the colour it rests at on every
            // route (an inferred current item keeps its base colour, an authored
            // one carries its resting colour while its current presentation is
            // lifted onto the runtime current item), and the item's state carrier
            // belongs to that colour. A carrier unique to a current item without
            // one paints only the served route.
            $restingColor = array() !== preg_grep('/^blocks-engine-navigation-link-color-[a-f0-9]{64}$/', $classes);
            $classes = array_values(array_filter($classes, static function (string $class) use ($current, $semanticIdentity, $stateCarrierCounts, $peerClasses, $restingColor): bool {
                if ($current && self::isCurrentPageClass($class, $peerClasses)) return false;
                if (($current || $semanticIdentity) && preg_match('/^blocks-engine-navigation-current-color-[a-f0-9]{64}$/', $class)) return false;
                if ($semanticIdentity && $current && !$restingColor && 1 === ($stateCarrierCounts[$class] ?? 0)) return false;
                if ($semanticIdentity && $current && preg_match('/^be-inline-geometry-[a-f0-9]{16}(?:-[a-f0-9]{16})?$/', $class)) return false;
                return true;
            }));
            if ($semanticIdentity && $current && $isLink) {
                $resting = $restingColorBySignature[self::navigationClassSignature($classes)] ?? null;
                $classes = array_values(array_unique(array_merge($classes, $sharedLinkColors, is_string($resting) ? array($resting) : array())));
            }
            $attrs['className'] = implode(' ', $classes);
            if ('' === $attrs['className']) unset($attrs['className']);
            // A link with no resting peer beside it (a lone top-level link) cannot
            // take its presentation from them on the page where it is current,
            // and the current item's own colour and type are not kept. Identity
            // ignores them for such a link on every page, so one header is not
            // split by which page happens to be current.
            // A linked submenu (a featured card in a mega menu) is the served
            // route on its own page, where its current presentation is dropped
            // with the resting type it shares with every other route. Identity
            // ignores its own presentation on every page for the same reason.
            $linkedSubmenu = 'navigation-submenu' === $match[1] && '' !== (string) ($attrs['url'] ?? '');
            if ($current || ($semanticIdentity && $isLink && (false === $peer || $linkedSubmenu))) unset($attrs['color'], $attrs['style'], $attrs['typography']);
            if ($current || ($semanticIdentity && $isLink)) unset($attrs['anchor'], $attrs['anchorClassName']);
            return '<!-- wp:' . $match[1] . ' ' . BlockAttributes::encode($attrs) . ' ' . (($match[3] ?? '') ? '/' : '') . '-->';
        }, $markup) ?? $markup;
        // A menu authored as plain links (list items, rich text) marks the
        // served route with aria-current="page" in saved content. One shared
        // part serves every route, so that page-scoped state is neither part of
        // the chrome's identity nor frozen into the part.
        $markup = preg_replace('/(<a\b[^>]*?)\s+aria-current\s*=\s*(["\'])page\2/i', '$1', $markup) ?? $markup;
        // Rich mega-menu panels can retain a rendered navigation root rather
        // than navigation-link blocks. Its lifted current colour is still
        // page state, just like the marker on a core navigation comment.
        if ($semanticIdentity) {
            $markup = preg_replace('/\s*blocks-engine-navigation-current-color-[a-f0-9]{64}\b/', '', $markup) ?? $markup;
        }
        $markup = self::withoutListItemDisclosureState($markup);
        return self::withoutMenuSelection($markup, true);
    }

    /**
     * A menu authored as list items converts to li groups, list items or
     * navigation links. The source paints the item holding the served route (and, in a submenu,
     * its parent item) with a disclosure class its sibling items do not
     * carry. That class is route state, so it is neither part of the
     * chrome's identity nor frozen into the shared part.
     */
    private static function withoutListItemDisclosureState(string $markup): string
    {
        // Block attributes never hold `-->`: serialization escapes `--`.
        if (!preg_match_all('/<!-- (\/)?wp:([a-z0-9-]+(?:\/[a-z0-9-]+)?)(?: (\{.*?\}))? (\/)?-->(?:<li class="([^"]*)")?/s', $markup, $tokens, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return $markup;
        // Li items keyed by the block that holds them: siblings of one list.
        $stack = array(); $lists = array();
        foreach ($tokens as $index => $token) {
            if ('' !== ($token[1][0] ?? '')) { array_pop($stack); continue; }
            $name = $token[2][0];
            $attrs = json_decode((string) ($token[3][0] ?? ''), true);
            $attrs = is_array($attrs) ? $attrs : array();
            if ('' !== ($token[5][0] ?? '') && ('list-item' === $name || ('group' === $name && 'li' === ($attrs['tagName'] ?? null)))) {
                $lists[(string) end($stack)][] = array('token' => $index, 'name' => $name, 'attrs' => $attrs, 'classes' => preg_split('/\s+/', trim($token[5][0])) ?: array(), 'li' => true);
            } elseif (in_array($name, array('navigation-link', 'navigation-submenu'), true)) {
                $lists[(string) end($stack)][] = array('token' => $index, 'name' => $name, 'attrs' => $attrs, 'classes' => preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array(), 'li' => false, 'self_closing' => '' !== ($token[4][0] ?? ''));
            }
            if ('' === ($token[4][0] ?? '')) $stack[] = $index;
        }
        $replacements = array();
        foreach ($lists as $items) {
            if (2 > count($items)) continue;
            $counts = array();
            foreach ($items as $item) foreach (array_intersect($item['classes'], SourceClassIdentity::DISCLOSURE_CLASSES) as $class) $counts[$class] = ($counts[$class] ?? 0) + 1;
            $routeState = array_keys(array_filter($counts, static fn(int $count): bool => 1 === $count));
            if (array() === $routeState) continue;
            foreach ($items as $item) {
                if (array() === array_intersect($item['classes'], $routeState)) continue;
                $attrs = $item['attrs'];
                $kept = array_values(array_diff(preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array(), $routeState));
                if (array() === $kept) unset($attrs['className']); else $attrs['className'] = implode(' ', $kept);
                $token = $tokens[$item['token']];
                $comment = '<!-- wp:' . $item['name'] . (array() === $attrs ? '' : ' ' . BlockAttributes::encode($attrs)) . ($item['li'] || empty($item['self_closing']) ? ' -->' : ' /-->');
                $replacements[] = array($token[0][1], strlen($token[0][0]), $comment . ($item['li'] ? '<li class="' . implode(' ', array_values(array_diff($item['classes'], $routeState))) . '"' : ('' === ($token[5][0] ?? '') ? '' : '<li class="' . $token[5][0] . '"')));
            }
        }
        usort($replacements, static fn(array $left, array $right): int => $right[0] <=> $left[0]);
        foreach ($replacements as [$offset, $length, $replacement]) $markup = substr_replace($markup, $replacement, $offset, $length);
        return $markup;
    }

    /**
     * Route state of the navigation blocks, indexed in the order
     * withoutCurrentNavigationState() visits them:
     *
     * - current_navigations: navigation blocks (counted like its
     *   $navigationIndex) that contain the current item.
     * - current_submenus: submenu blocks that contain the current item.
     * - current_link_subjects: for each current link (counted like its
     *   $blockIndex), the anchor subject its resting siblings in the same
     *   navigation or submenu share.
     *
     * @return array{current_navigations:array<int,true>,current_submenus:array<int,true>,current_link_subjects:array<int,array<string,mixed>>}
     */
    private static function navigationRouteState(string $markup): array
    {
        $currentNavigations = array();
        $currentSubmenus = array();
        $currentLinkContainers = array();
        $subjectCounts = array();
        // Open navigation and submenu blocks, innermost last.
        $containers = array();
        $navigationIndex = -1;
        $blockIndex = -1;
        preg_match_all('/<!--\s*(?:wp:(navigation(?:-link|-submenu)?)(?:\s+(\{.*?\}))?\s*(\/)?|(\/)wp:navigation(?:-submenu)?\s*)-->/s', $markup, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if ('' !== ($match[4] ?? '')) {
                array_pop($containers);
                continue;
            }
            $name = $match[1];
            $attributes = $match[2] ?? '';
            $selfClosing = '' !== ($match[3] ?? '');
            // Only blocks with attributes are visited by withoutCurrentNavigationState().
            if ('' !== $attributes) {
                ++$blockIndex;
            }
            if ('navigation' === $name) {
                $navigation = '' !== $attributes ? ++$navigationIndex : null;
                if (!$selfClosing) {
                    $containers[] = array('navigation' => $navigation, 'key' => 'navigation-' . $blockIndex);
                }
                continue;
            }
            $container = end($containers);
            if ('navigation-submenu' === $name && !$selfClosing) {
                $containers[] = array('navigation' => null, 'key' => 'submenu-' . $blockIndex);
            }
            $attrs = '' !== $attributes ? json_decode($attributes, true) : null;
            if (!is_array($container) || !is_array($attrs)) {
                continue;
            }
            if (str_contains((string) ($attrs['className'] ?? ''), 'blocks-engine-current-navigation-item')) {
                foreach ($containers as $ancestor) {
                    if (null !== $ancestor['navigation']) {
                        $currentNavigations[$ancestor['navigation']] = true;
                    } elseif (str_starts_with($ancestor['key'], 'submenu-')) {
                        $currentSubmenus[(int) substr($ancestor['key'], 8)] = true;
                    }
                }
                if ('navigation-link' === $name) {
                    $currentLinkContainers[$blockIndex] = $container['key'];
                }
                continue;
            }
            $subject = 'navigation-link' === $name ? self::sharedAnchorSubject($attrs) : null;
            if (null === $subject) {
                continue;
            }
            $subjectKey = (string) json_encode($subject);
            $subjectCounts[$container['key']][$subjectKey]['count'] = ($subjectCounts[$container['key']][$subjectKey]['count'] ?? 0) + 1;
            $subjectCounts[$container['key']][$subjectKey]['subject'] = $subject;
        }
        $currentLinkSubjects = array();
        foreach ($currentLinkContainers as $link => $containerKey) {
            $subject = self::dominantAnchorSubject($subjectCounts[$containerKey] ?? array());
            if (null !== $subject) {
                $currentLinkSubjects[$link] = $subject;
            }
        }
        return array('current_navigations' => $currentNavigations, 'current_submenus' => $currentSubmenus, 'current_link_subjects' => $currentLinkSubjects);
    }

    /**
     * The anchor subject a resting link renders through, without the parts
     * that belong to that link alone: its id and inline geometry markers.
     *
     * @param array<string,mixed> $attrs
     * @return array<string,mixed>|null
     */
    private static function sharedAnchorSubject(array $attrs): ?array
    {
        $subject = $attrs['metadata']['blocksEngineNavigationAnchor'] ?? null;
        if (!is_array($subject)) {
            return null;
        }
        unset($subject['id']);
        if (is_string($subject['className'] ?? null)) {
            $classes = preg_split('/\s+/', trim($subject['className'])) ?: array();
            $subject['className'] = implode(' ', array_filter($classes, static fn(string $class): bool => 1 !== preg_match('/^be-inline-geometry-[a-f0-9]{16}(?:-[a-f0-9]{16})?$/', $class)));
        }
        return $subject;
    }

    /**
     * The most common anchor subject, when at least two links share it.
     *
     * @param array<string,array{count:int,subject:array<string,mixed>}> $counts
     * @return array<string,mixed>|null
     */
    private static function dominantAnchorSubject(array $counts): ?array
    {
        uasort($counts, static fn(array $left, array $right): int => $right['count'] <=> $left['count']);
        $top = reset($counts);
        return is_array($top) && 2 <= $top['count'] ? $top['subject'] : null;
    }

    /**
     * Classes without disclosure state not shared by the resting peers.
     * With no peer classes, this preserves the navigation-root behavior.
     *
     * @param array<int,string> $classes
     * @param array<int,string> $peerClasses
     * @return array<int,string>
     */
    private static function withoutDisclosureClasses(array $classes, array $peerClasses = array()): array
    {
        return array_values(array_diff($classes, array_diff(SourceClassIdentity::DISCLOSURE_CLASSES, $peerClasses)));
    }

    /**
     * Classes of an authored current item that adopts its resting siblings'
     * anchor subject. The item rendered its anchor classes on itself; they
     * move back to the subject. Its link-state carrier and the anchor
     * line-height/box markers that stood in for the subject only paint the
     * served route, so they are not part of the chrome's identity.
     *
     * @param array<int,string> $classes
     * @param array<string,mixed> $subject
     * @return array<int,string>
     */
    private static function adoptedAnchorSubjectClasses(array $classes, array $subject, bool $semanticIdentity): array
    {
        $subjectClasses = preg_split('/\s+/', trim((string) ($subject['className'] ?? ''))) ?: array();
        $classes = array_diff($classes, $subjectClasses);
        if ($semanticIdentity) {
            $classes = array_filter($classes, static fn(string $class): bool => 1 !== preg_match('/^blocks-engine-navigation-(?:link-color-states-\d+|anchor-line-height-[a-f0-9]{64}|link-box-[a-f0-9]{64})$/', $class));
        }
        return array_values($classes);
    }

    /** @param array<string,mixed> $attrs */
    private static function navigationBlockOpening(string $name, array $attrs, bool $selfClosing): string
    {
        return '<!-- wp:' . $name . ' ' . BlockAttributes::encode($attrs) . ' ' . ($selfClosing ? '/' : '') . '-->';
    }

    public static function withoutMenuSelection(string $markup, bool $resting = false): string
    {
        $replacement = $resting ? 'menu false link' : 'menu link';
        $markup = preg_replace('/(data-state=)(\\\\u0022|"|&quot;)menu (?:selected|false)\s+link\2/', '$1$2' . $replacement . '$2', $markup) ?? $markup;
        return preg_replace('/\s*(aria-current=)(\\\\u0022|"|&quot;)page\2/', '', $markup) ?? $markup;
    }

    /**
     * Per navigation block (document order) and submenu depth, keyed
     * `index:depth`, the presentation shared by at least two of its non-current
     * links: className plus color/style attrs.
     *
     * @return array<string,array<string,mixed>|false|null> false marks a level with a single link
     */
    private static function restingNavigationPeers(string $markup): array
    {
        $peers = array(); $index = -1; $groups = array(); $currentSignatures = array(); $depth = 0; $linkCounts = array();
        preg_match_all('/<!--\s*(\/)?wp:(navigation(?:-link|-submenu)?)(?:\s+(\{.*?\}))?\s*(\/)?-->/s', $markup, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if ('navigation-submenu' === $match[2]) {
                if ('' !== $match[1]) { $depth = max(0, $depth - 1); continue; }
                if (empty($match[4])) ++$depth;
                continue;
            }
            if ('' !== $match[1]) continue;
            if ('navigation' === $match[2]) { ++$index; $depth = 0; continue; }
            if ($index < 0) continue;
            // A link is only a peer of the links beside it. The items of a
            // submenu style differently from the top-level items.
            $level = $index . ':' . $depth;
            $linkCounts[$level] = ($linkCounts[$level] ?? 0) + 1;
            $attrs = json_decode($match[3] ?? '', true);
            if (!is_array($attrs)) continue;
            $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array();
            if (in_array('blocks-engine-current-navigation-item', $classes, true)) {
                $currentSignatures[$level][] = self::navigationClassSignature($classes);
                continue;
            }
            $presentation = array_intersect_key($attrs, array_flip(array('className', 'style', 'color', 'typography')));
            if (is_string($presentation['className'] ?? null)) {
                $classes = array_values(array_filter($classes, static fn(string $class): bool => !preg_match('/^blocks-engine-attribute-[a-f0-9]{6,}(?:-\d+)?$/', $class)));
                sort($classes, SORT_STRING);
                $presentation['className'] = implode(' ', $classes);
            }
            $key = json_encode($presentation);
            $groups[$level][$key] = array('count' => ($groups[$level][$key]['count'] ?? 0) + 1, 'presentation' => $presentation);
        }
        foreach ($groups as $navigation => $candidates) {
            uasort($candidates, static fn(array $left, array $right): int => $right['count'] <=> $left['count']);
            $top = reset($candidates);
            $singlePeerMatches = is_array($top) && 1 === count($candidates)
                && array() !== ($currentSignatures[$navigation] ?? array())
                && array(self::navigationClassSignature(preg_split('/\s+/', trim((string) ($top['presentation']['className'] ?? ''))) ?: array())) === array_values(array_unique($currentSignatures[$navigation]));
            $peers[$navigation] = is_array($top) && (2 <= $top['count'] || $singlePeerMatches) ? $top['presentation'] : null;
        }
        // A level holding one link has no peers at all.
        foreach ($linkCounts as $level => $count) if (1 === $count && !is_array($peers[$level] ?? null)) $peers[$level] = false;
        return $peers;
    }

    /** @param array<int,string> $peerClasses */
    private static function isCurrentPageClass(string $class, array $peerClasses = array()): bool
    {
        if (in_array($class, array('blocks-engine-current-navigation-item', 'blocks-engine-current-navigation-underline'), true) || SourceClassIdentity::isCurrentState($class)) return true;
        if (1 !== preg_match('/(?:^|[-_])(?:is-)?(?:current|active|selected|on)$/', $class)) return false;
        return !in_array($class, $peerClasses, true);
    }

    /** @param array<int,string> $classes */
    private static function navigationClassSignature(array $classes): string
    {
        $classes = array_values(array_filter($classes, static function (string $class): bool {
            return !in_array($class, array('blocks-engine-current-navigation-item', 'blocks-engine-current-navigation-underline'), true) && !SourceClassIdentity::isCurrentState($class)
                && !preg_match('/^blocks-engine-navigation-(?:current|link)-color-[a-f0-9]{64}$/', $class)
                && !preg_match('/^be-inline-geometry-[a-f0-9]{16}(?:-[a-f0-9]{16})?$/', $class);
        }));
        sort($classes, SORT_STRING);
        return implode(' ', $classes);
    }
}
