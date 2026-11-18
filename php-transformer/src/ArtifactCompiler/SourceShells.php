<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;
use Automattic\BlocksEngine\PhpTransformer\Support\ShellLandmarkPolicy;

/**
 * Source-shell comparison for shared inline shells: the identity a page's
 * landmark markup reduces to once capture evidence and current-item state are
 * neutralized, the landmark's ancestor context and the context every member
 * shares, and the evidence count that picks the richest member.
 */
final class SourceShells
{
    /**
     * The landmark's ancestors, outermost first: `html`, `body`, then each
     * wrapper down to its parent.
     *
     * @return list<array{tag:string,attributes:array<string,string>}>
     */
    public static function ancestorContext(\DOMElement $landmark): array
    {
        $context = array();
        for ($cursor = $landmark->parentNode; $cursor instanceof \DOMElement; $cursor = $cursor->parentNode) {
            $attributes = array();
            $attributes = SourceDom::htmlAttributes($cursor);
            array_unshift($context, array('tag' => strtolower($cursor->tagName), 'attributes' => $attributes));
        }
        return $context;
    }

    /**
     * Ancestor context every member shares: depth-wise while tags agree, with
     * only attribute values (and class tokens) common to all members.
     *
     * @param array<int|string,list<array{tag:string,attributes:array<string,string>}>> $contexts
     * @return list<array{tag:string,attributes:array<string,string>}>
     */
    public static function sharedAncestorContext(array $contexts): array
    {
        $contexts = array_values($contexts);
        $shared = array();
        if (array() === $contexts) return $shared;
        for ($depth = 0; ; ++$depth) {
            $level = array();
            foreach ($contexts as $context) {
                if (!isset($context[$depth]) || (array() !== $level && $context[$depth]['tag'] !== $level[0]['tag'])) break 2;
                $level[] = $context[$depth];
            }
            $attributes = $level[0]['attributes'];
            foreach (array_slice($level, 1) as $ancestor) {
                foreach ($attributes as $name => $value) {
                    if (!array_key_exists($name, $ancestor['attributes'])) { unset($attributes[$name]); continue; }
                    if ('class' === $name) {
                        $tokens = SourceDom::tokens($ancestor['attributes'][$name]);
                        $attributes[$name] = implode(' ', array_values(array_intersect(SourceDom::tokens($value), $tokens)));
                        if ('' === $attributes[$name]) unset($attributes[$name]);
                    } elseif ($ancestor['attributes'][$name] !== $value) {
                        unset($attributes[$name]);
                    }
                }
            }
            $shared[] = array('tag' => $level[0]['tag'], 'attributes' => $attributes);
        }
        return $shared;
    }

    /** @param array<int|string,string> $attributes */
    public static function attributes(array $attributes): string
    {
        $html = '';
        foreach ($attributes as $name => $value) {
            if (!is_string($name) || 1 !== preg_match('/^[a-z_:][a-z0-9_:.-]*$/', $name)) continue;
            $html .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        return $html;
    }

    /**
     * The current item's state can be marked by a class on a descendant, for
     * example a current-page class on the link inside the current `li`. Mirror
     * the ancestor sibling-class intersection inside the subtree: keep on each
     * descendant only the classes its counterparts at the same position and tag
     * in the sibling items also carry.
     *
     * @param list<\DOMElement> $counterparts Same-position, same-tag elements in the sibling items.
     */
    private static function neutralizeCurrentSubtreeShellClasses(\DOMElement $node, array $counterparts): void
    {
        $counterpartClasses = array();
        foreach ($counterparts as $counterpart) {
            foreach (SourceDom::tokens($counterpart->getAttribute('class')) as $class) if ('' !== $class) $counterpartClasses[$class] = true;
        }
        $classes = SourceDom::tokens($node->getAttribute('class'));
        if (array() !== $classes && array() !== $counterpartClasses) $node->setAttribute('class', implode(' ', array_values(array_filter($classes, static fn(string $class): bool => isset($counterpartClasses[$class])))));
        $children = array();
        foreach ($node->childNodes as $child) if ($child instanceof \DOMElement) $children[] = $child;
        $peerLists = array();
        foreach ($counterparts as $counterpart) {
            $peers = array();
            foreach ($counterpart->childNodes as $peer) if ($peer instanceof \DOMElement) $peers[] = $peer;
            $peerLists[] = $peers;
        }
        foreach ($children as $index => $child) {
            $childCounterparts = array();
            foreach ($peerLists as $peers) if (isset($peers[$index]) && $peers[$index]->tagName === $child->tagName) $childCounterparts[] = $peers[$index];
            if (array() === $childCounterparts) continue;
            self::neutralizeCurrentSubtreeShellClasses($child, $childCounterparts);
        }
    }

    public static function evidenceCount(string $markup): int
    {
        $dom = HtmlSerialization::parseDocument('<body>' . $markup . '</body>');
        $count = 0;
        foreach ($dom->getElementsByTagName('*') as $node) {
            foreach (array(CaptureEvidence::VIEWPORT_ENTRANCE, CaptureEvidence::GLYPH_COLOR) as $attribute) if ($node->hasAttribute($attribute)) ++$count;
            foreach (SourceDom::tokens($node->getAttribute('class')) as $class) if (str_starts_with($class, CaptureEvidence::RESPONSIVE_CLASS_PREFIX)) ++$count;
        }
        return $count;
    }

    public static function identity(string $markup, bool $ignoreViewportEntrance = true): string
    {
        $dom = HtmlSerialization::parseDocument('<body>' . $markup . '</body>');
        $xpath = new \DOMXPath($dom);
        foreach ($dom->getElementsByTagName('*') as $node) {
            foreach (iterator_to_array($node->attributes) as $attribute) {
                // Comparison ignores route-local observations; emission keeps
                // node IDs so the canonical route's accepted tree can restyle
                // the isolated shared shell rather than falling back to CSS.
                if ($ignoreViewportEntrance && \Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree::isEvidenceAttribute($attribute->name)) $node->removeAttributeNode($attribute);
            }
        }
        if ($ignoreViewportEntrance) {
            foreach ($dom->getElementsByTagName('*') as $node) {
                foreach (iterator_to_array($node->attributes) as $attribute) {
                    if (!str_starts_with($attribute->name, 'data-')) continue;
                    $value = json_decode($attribute->value, true);
                    if (is_array($value)) $node->setAttribute($attribute->name, (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                }
            }
            foreach ($xpath->query('//*[@' . CaptureEvidence::VIEWPORT_ENTRANCE . ']') ?: array() as $node) {
                if (!$node instanceof \DOMElement) continue;
                $evidence = json_decode($node->getAttribute(CaptureEvidence::VIEWPORT_ENTRANCE), true);
                foreach (ArrayField::array($evidence, 'attributes') as $name => $state) {
                    // Only recorded after states are incidental. An unrelated or
                    // subsequently changed attribute remains structural.
                    if (is_string($name) && is_array($state) && array_key_exists('after', $state) && is_string($state['after']) && $node->getAttribute($name) === $state['after']) $node->removeAttribute($name);
                }
                $node->removeAttribute(CaptureEvidence::VIEWPORT_ENTRANCE);
            }
        }
        if ($ignoreViewportEntrance) self::neutralizeDialogInstanceIds($xpath);
        if ($ignoreViewportEntrance) {
            foreach ($xpath->query('//*') ?: array() as $node) {
                if (!$node instanceof \DOMElement) continue;
                // The source still owns provenance here: do not infer sampled
                // paint from the SVG produced later by social-link lowering.
                $node->removeAttribute(CaptureEvidence::GLYPH_COLOR);
                // Measured padding is a route observation, not authored shell
                // identity. The canonical member still supplies it on emission.
                if ($node->hasAttribute(CaptureEvidence::fluidIgnorePaddingAttribute())) {
                    $style = preg_replace('/(?:^|;)\s*padding(?:-(?:top|right|bottom|left))?\s*:[^;]*/i', '', $node->getAttribute('style')) ?? '';
                    $node->setAttribute('style', ltrim($style, ';'));
                }
                // A selector-resolved dialog ancestor can sit at a different
                // depth on each route. Its state is shared; the distance from
                // the trigger is capture context, not landmark identity. Keep
                // depth-only targets structural and preserve emission evidence.
                if ($node->hasAttribute(CaptureEvidence::DIALOG_ANCESTOR_STATE)) {
                    $states = json_decode($node->getAttribute(CaptureEvidence::DIALOG_ANCESTOR_STATE), true);
                    if (is_array($states) && array_is_list($states)) {
                        foreach ($states as &$state) {
                            if (is_array($state) && is_string($state['selector'] ?? null) && '' !== trim($state['selector'])) unset($state['depth']);
                        }
                        unset($state);
                        $encoded = json_encode($states, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        if (false !== $encoded) $node->setAttribute(CaptureEvidence::DIALOG_ANCESTOR_STATE, $encoded);
                    }
                }
                $classes = array_values(array_filter(SourceDom::tokens($node->getAttribute('class')), static fn(string $class): bool => !str_starts_with($class, CaptureEvidence::RESPONSIVE_CLASS_PREFIX) && !str_starts_with($class, CaptureEvidence::RESPONSIVE_COUNTERPART_CLASS_PREFIX) && !CaptureEvidence::isDeviceIslandClass($class) && !str_starts_with($class, CaptureEvidence::RESPONSIVE_PROJECTION_CLASS_PREFIX)));
                if (array() === $classes) $node->removeAttribute('class');
                else $node->setAttribute('class', implode(' ', $classes));
            }
        }
        // Hydration boundary comments vary with capture timing, not shell identity.
        foreach ($xpath->query('//comment()') ?: array() as $comment) {
            if (preg_match('/^\/?\$(?:[!?])?$/', trim($comment->nodeValue ?? ''))) $comment->parentNode?->removeChild($comment);
        }
        // Some menus mark the link's wrapper rather than the anchor. Only
        // navigation wrappers containing links establish route-current state;
        // selected tabs, choices and other content remain structural.
        foreach ($xpath->query('//*[@data-selected="true"][ancestor::nav or ancestor::*[@role="navigation"]]') ?: array() as $node) {
            if (!$node instanceof \DOMElement || 0 === $node->getElementsByTagName('a')->length) continue;
            $peers = array();
            // A wrapper's counterparts may live inside sibling list items.
            $item = $node;
            while ($item->parentNode instanceof \DOMElement && 'li' !== strtolower($item->tagName) && 'nav' !== strtolower($item->parentNode->tagName)) $item = $item->parentNode;
            if ('li' !== strtolower($item->tagName)) continue;
            foreach ($item->parentNode?->childNodes ?? array() as $sibling) {
                if (!$sibling instanceof \DOMElement || $sibling === $item || $sibling->tagName !== $item->tagName) continue;
                foreach ($sibling->getElementsByTagName($node->tagName) as $peer) {
                    // Another current link cannot establish resting state.
                    if ('true' === $peer->getAttribute('data-selected')) continue;
                    if ($peer->getAttribute('data-part') === $node->getAttribute('data-part') && 0 !== $peer->getElementsByTagName('a')->length) $peers[] = $peer;
                }
            }
            if (array() === $peers) continue;
            self::neutralizeCurrentSubtreeShellClasses($node, $peers);
            foreach (array('data-selected', 'data-interactive') as $attribute) {
                $values = array_unique(array_map(static fn(\DOMElement $peer): ?string => $peer->hasAttribute($attribute) ? $peer->getAttribute($attribute) : null, $peers));
                if (1 !== count($values)) continue;
                $value = reset($values);
                if (null === $value) $node->removeAttribute($attribute);
                else $node->setAttribute($attribute, $value);
            }
        }
        $currentNodes = array();
        foreach ($xpath->query('//*[@aria-current] | //*[contains(concat(" ", normalize-space(@data-state), " "), " selected ")]') ?: array() as $node) if ($node instanceof \DOMElement) $currentNodes[] = $node;
        foreach ($xpath->query('//*[@class]') ?: array() as $node) {
            if (!$node instanceof \DOMElement || in_array($node, $currentNodes, true)) continue;
            if (array() !== array_filter(SourceDom::tokens($node->getAttribute('class')), \Automattic\BlocksEngine\PhpTransformer\WordPress\SourceClassIdentity::containsCurrentStateToken(...))) $currentNodes[] = $node;
        }
        foreach ($currentNodes as $node) {
            // Explicit current tokens are state, not evidence that an item's
            // other classes must match its structurally different siblings.
            $marked = false;
            for ($ancestor = $node; $ancestor instanceof \DOMElement; $ancestor = $ancestor->parentNode) {
                if (array() !== array_filter(SourceDom::tokens($ancestor->getAttribute('class')), \Automattic\BlocksEngine\PhpTransformer\WordPress\SourceClassIdentity::containsCurrentStateToken(...)) || str_contains($ancestor->getAttribute('data-blocks-engine-route-resting'), '"class"')) $marked = true;
                if ('nav' === strtolower($ancestor->tagName)) break;
            }
            // A navigation toggle may be an anchor without href, with no
            // same-tag sibling to establish its resting classes. Known current
            // tokens are route state independently of its computed link colour.
            if ('a' === strtolower($node->tagName) && 0 < ($xpath->query('ancestor::nav | ancestor::*[@role="navigation"]', $node)?->length ?? 0)) {
                $classes = array_values(array_filter(SourceDom::tokens($node->getAttribute('class')), static fn(string $class): bool => !\Automattic\BlocksEngine\PhpTransformer\WordPress\SourceClassIdentity::containsCurrentStateToken($class)));
                if (array() === $classes) $node->removeAttribute('class');
                else $node->setAttribute('class', implode(' ', $classes));
            }
            // Sibling class intersection describes route state only at the
            // menu-item level: the current node and its wrappers up to the
            // first level whose same-tag siblings are navigation peers. Layout
            // wrappers above it keep their classes on every route.
            $atItemLevel = true;
            for ($cursor = $node; $cursor instanceof \DOMElement; $cursor = $cursor->parentNode) {
                if ($cursor->hasAttribute('data-state')) $cursor->setAttribute('data-state', preg_replace('/\bselected\b/', 'false', $cursor->getAttribute('data-state')) ?? $cursor->getAttribute('data-state'));
                $classes = SourceDom::tokens($cursor->getAttribute('class'));
                if ($marked) {
                    $rest = array_values(array_filter($classes, static fn(string $class): bool => !\Automattic\BlocksEngine\PhpTransformer\WordPress\SourceClassIdentity::containsCurrentStateToken($class) && !in_array($class, \Automattic\BlocksEngine\PhpTransformer\WordPress\SourceClassIdentity::DISCLOSURE_CLASSES, true)));
                    if (array() === $rest) $cursor->removeAttribute('class');
                    else $cursor->setAttribute('class', implode(' ', $rest));
                } elseif ($atItemLevel && $cursor->parentNode instanceof \DOMElement) {
                    $siblingClasses = array();
                    $hasNavigationPeer = false;
                    foreach ($cursor->parentNode->childNodes as $sibling) {
                        if (!$sibling instanceof \DOMElement || $sibling === $cursor || $sibling->tagName !== $cursor->tagName) continue;
                        if ('a' === strtolower($sibling->tagName) || 0 !== $sibling->getElementsByTagName('a')->length) $hasNavigationPeer = true;
                        foreach (SourceDom::tokens($sibling->getAttribute('class')) as $class) $siblingClasses[$class] = true;
                    }
                    if (array() !== $classes && array() !== $siblingClasses) $cursor->setAttribute('class', implode(' ', array_values(array_filter($classes, static fn(string $class): bool => isset($siblingClasses[$class])))));
                    if ($hasNavigationPeer) $atItemLevel = false;
                }
                if ('nav' === strtolower($cursor->tagName) || 'navigation' === strtolower($cursor->getAttribute('role'))) break;
            }
            if ($node->hasAttribute('aria-current') && 'false' !== strtolower(trim($node->getAttribute('aria-current')))) {
                if ($node->hasAttribute('data-is-current')) $node->setAttribute('data-is-current', 'false');
                $siblings = array();
                foreach ($node->parentNode?->childNodes ?? array() as $sibling) {
                    if ($sibling instanceof \DOMElement && $sibling !== $node && $sibling->tagName === $node->tagName) $siblings[] = $sibling;
                }
                if (array() !== $siblings) self::neutralizeCurrentSubtreeShellClasses($node, $siblings);
            }
        }
        foreach ($xpath->query('//*[@aria-current]') ?: array() as $node) if ($node instanceof \DOMElement) $node->removeAttribute('aria-current');
        foreach ($xpath->query('//*[@style]') ?: array() as $node) {
            if (!$node instanceof \DOMElement) continue;
            $style = strtolower(preg_replace('/\s+/', '', $node->getAttribute('style')) ?? '');
            $style = rtrim($style, ';');
            if ('' === $style) $node->removeAttribute('style');
            else $node->setAttribute('style', $style);
        }
        $body = $dom->getElementsByTagName('body')->item(0);
        $root = $body instanceof \DOMElement ? $body->firstElementChild : null;
        $identity = $root instanceof \DOMElement ? ($dom->saveHTML($root) ?: $markup) : $markup;
        return ShellLandmarkPolicy::withoutResponsiveCorrespondenceMarkup($identity);
    }

    /**
     * A captured dialog panel is a snapshot of the opened state, and the
     * runtime renders it with instance ids it mints on each visit
     * (`comp-a1_r_menu` on one route, `comp-b2_r_menu` on another). An id
     * nothing in the landmark names (no `aria-controls`, `for`, `#fragment`)
     * is that instance name, not structure: it and the class tokens derived
     * from it read as their position in the panel. Referenced ids stay.
     */
    private static function neutralizeDialogInstanceIds(\DOMXPath $xpath): void
    {
        $referenced = array();
        foreach ($xpath->query('//@*') ?: array() as $attribute) {
            $name = strtolower($attribute->nodeName);
            if ('href' === $name && str_starts_with(trim($attribute->nodeValue ?? ''), '#')) $referenced[substr(trim($attribute->nodeValue ?? ''), 1)] = true;
            elseif (in_array($name, array('for', 'list', 'form', 'popovertarget', CaptureEvidence::DIALOG_TRIGGER, CaptureEvidence::DIALOG_PANEL), true) || str_starts_with($name, 'aria-')) {
                foreach (SourceDom::tokens((string) $attribute->nodeValue) as $token) $referenced[$token] = true;
            }
        }
        foreach ($xpath->query('//*[@' . CaptureEvidence::DIALOG_PANEL . ']') ?: array() as $panel) {
            if (!$panel instanceof \DOMElement) continue;
            $names = array();
            foreach ($xpath->query('.//*[@id]', $panel) ?: array() as $node) {
                $id = $node instanceof \DOMElement ? $node->getAttribute('id') : '';
                if ('' === $id || isset($referenced[$id]) || isset($names[$id])) continue;
                $names[$id] = 'dialog-instance-' . count($names);
            }
            if (array() === $names) continue;
            // Longest first, so an id that prefixes another does not claim it.
            uksort($names, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
            foreach ($xpath->query('descendant-or-self::*[@id or @class]', $panel) ?: array() as $node) {
                if (!$node instanceof \DOMElement) continue;
                if (isset($names[$node->getAttribute('id')])) $node->setAttribute('id', $names[$node->getAttribute('id')]);
                if (!$node->hasAttribute('class')) continue;
                $classes = array_map(static function (string $class) use ($names): string {
                    foreach ($names as $id => $name) if ($class === $id || str_starts_with($class, $id . '-')) return $name . substr($class, strlen($id));
                    return $class;
                }, SourceDom::tokens($node->getAttribute('class')));
                $node->setAttribute('class', implode(' ', $classes));
            }
        }
    }
}
