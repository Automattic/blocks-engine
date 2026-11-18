<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorTokenizer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSyntaxScanner;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;
use DOMElement;

/**
 * ADR 0005 box classification. A `neutral` disposition is the sole authority
 * for collapsing a source box during conversion; every other disposition keeps
 * the box. The projection estimates the resulting block tree for reporting.
 */
final class BoxTreeClassifier
{
    public static function report(string $html, array $tree): array
    {
        $dom = HtmlSerialization::parseDocument($html);
        $elements = $parents = array();
        foreach ($dom->getElementsByTagName('*') as $element) {
            $id = $element->getAttribute(BoxTree::NODE_ATTRIBUTE);
            // Missing evidence must not invent source identities.
            if (!isset($tree['nodes'][$id])) continue;
            $elements[$id] = $element;
            $parent = $element->parentNode;
            while ($parent instanceof DOMElement && !$parent->hasAttribute(BoxTree::NODE_ATTRIBUTE)) $parent = $parent->parentNode;
            $parents[$id] = $parent instanceof DOMElement ? $parent->getAttribute(BoxTree::NODE_ATTRIBUTE) : '';
        }
        $references = array();
        foreach ($dom->getElementsByTagName('*') as $element) {
            $href = $element->getAttribute('href');
            if (str_starts_with($href, '#')) $references[rawurldecode(substr($href, 1))] = true;
            foreach (array('aria-controls', 'aria-labelledby', 'aria-describedby', 'aria-owns', 'for') as $attribute) {
                foreach (preg_split('/\s+/', trim($element->getAttribute($attribute)), -1, PREG_SPLIT_NO_EMPTY) as $target) $references[$target] = true;
            }
        }
        $containing = array();
        foreach ($tree['viewports'] ?? array() as $viewport => $_) {
            $absolute = $fixed = array();
            foreach ($elements as $id => $element) {
                $parent = $parents[$id];
                $a = $absolute[$parent] ?? null;
                $f = $fixed[$parent] ?? null;
                $styles = $tree['nodes'][$id]['viewports'][$viewport]['used_styles'];
                if ('absolute' === $styles['position'] && null !== $a) $containing[$a] = true;
                if ('fixed' === $styles['position'] && null !== $f) $containing[$f] = true;
                $establishes = 'none' !== $styles['transform'] || 'none' !== $styles['filter'];
                $absolute[$id] = $establishes || 'static' !== $styles['position'] ? $id : $a;
                $fixed[$id] = $establishes ? $id : $f;
            }
        }
        $stateCompounds = null;
        $nodes = array();
        $counts = array('load-bearing' => 0, 'neutral' => 0);
        foreach ($elements as $id => $element) {
            $node = $tree['nodes'][$id];
            $stateTargeted = false;
            if (self::isAutonomousCustomElement($element)) {
                $stateCompounds ??= self::stateCompounds($dom);
                $stateTargeted = self::targetedByState($element, $stateCompounds);
            }
            $criterion = self::criterion($element, $node, $tree, isset($containing[$id]), isset($references[$element->getAttribute('id')]), $stateTargeted, $tree['nodes'][$parents[$id]] ?? null);
            $disposition = in_array($criterion, self::NEUTRAL_CRITERIA, true) ? 'neutral' : 'load-bearing';
            $nodes[$id] = array('disposition' => $disposition, 'criterion' => $criterion);
            ++$counts[$disposition];
        }
        // Estimate block-producing boxes; inline content and navigation internals
        // do not each become blocks. Actual emitted depth is measured in slice 3.
        $depths = $inBody = $inNavigation = array();
        $depth = $groups = $leaves = 0;
        foreach ($elements as $id => $element) {
            $inBody[$id] = 'body' === strtolower($element->tagName) || ($inBody[$parents[$id]] ?? false);
            if (!$inBody[$id] || 'body' === strtolower($element->tagName)) continue;
            $tag = strtolower($element->tagName);
            $insideNavigation = $inNavigation[$parents[$id]] ?? false;
            $inNavigation[$id] = 'nav' === $tag || $insideNavigation;
            $produces = !$insideNavigation && !in_array($tag, array('span', 'a', 'strong', 'em', 'b', 'i', 'br', 'script', 'style', 'link', 'meta'), true);
            $kept = $produces && 'load-bearing' === $nodes[$id]['disposition'];
            $depths[$id] = ($depths[$parents[$id]] ?? 0) + ($kept ? 1 : 0);
            $depth = max($depth, $depths[$id]);
            if ($produces && (!$tree['nodes'][$id]['children'] || 'nav' === $tag || in_array($tag, array('p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'), true))) ++$leaves;
            elseif ($kept) ++$groups;
        }
        return array('counts' => $counts, 'nodes' => $nodes, 'projection' => $tree ? array(
            'basis' => 'estimated-block-producing-boxes', 'depth' => $depth, 'groups' => $groups,
            'leaves' => $leaves, 'groups_per_leaf' => $leaves ? $groups / $leaves : 0.0,
        ) : null);
    }

    /** Criteria that prove a box renders nothing its sole child does not. */
    private const NEUTRAL_CRITERIA = array('equal-child-geometry', 'no-box');

    /** Inherited properties a boxless element can still pass to its child. */
    private const INHERITED = array(
        'color', 'cursor', 'font-family', 'font-size', 'font-style', 'font-weight', 'letter-spacing', 'line-height',
        'list-style-position', 'list-style-type', 'overflow-wrap', 'pointer-events', 'text-align', 'text-indent',
        'text-shadow', 'text-transform', 'visibility', 'white-space', 'word-break', 'word-spacing',
    );

    private static function criterion(DOMElement $element, array $node, array $tree, bool $containing, bool $referenced, bool $stateTargeted = false, ?array $parent = null): string
    {
        foreach ($node['viewports'] as $observation) {
            $s = $observation['used_styles'];
            if (!empty($observation['pseudo']) || self::paints($s)) return 'paints';
        }
        if (self::clips($node, $tree)) return 'clips';
        foreach ($node['viewports'] as $observation) {
            if (count($node['children']) > 1 && in_array($observation['used_styles']['display'], array('flex', 'inline-flex', 'grid', 'inline-grid'), true)) return 'multi-child-layout';
        }
        if ($containing) return 'containing-block';
        $boxless = self::boxless($node);
        // Autonomous custom elements have no implicit role: they are generic
        // containers like div/span and fall through to the remaining criteria.
        if ((!in_array(strtolower($element->tagName), array('div', 'span'), true) && !self::isAutonomousCustomElement($element)) || $referenced || $element->hasAttribute('role') || $element->hasAttribute('name')) return 'semantics/id/anchor';
        foreach ($element->attributes as $attribute) {
            if (str_starts_with($attribute->name, 'on') || str_starts_with($attribute->name, 'aria-') || in_array($attribute->name, array('tabindex', 'contenteditable', 'draggable', 'hidden', 'inert'), true)) return 'interaction/state';
        }
        if (!empty($node['interaction_states']) || $stateTargeted) return 'interaction/state';
        if (self::carriesCaptureMarker($element)) return 'capture-marker';
        foreach ($node['viewports'] as $observation) {
            $s = $observation['used_styles'];
            if (isset($s['animation-name']) && 'none' !== $s['animation-name']) return 'motion';
            if (isset($s['transition-duration']) && preg_match('/[1-9]/', $s['transition-duration']) && 'none' !== ($s['transition-property'] ?? 'all')) return 'motion';
        }
        // Unknown boxes between observations cannot prove wrapper neutrality.
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement && !isset($tree['nodes'][$child->getAttribute(BoxTree::NODE_ATTRIBUTE)])) return 'unobserved-child';
        }
        if (1 !== count($node['children'])) return 'own-sizing';
        // `display: contents` generates no box at any captured viewport: its
        // child already takes the parent's layout, so neither placement nor
        // geometry can differ without it. Only inherited values pass through.
        if ($boxless) {
            if (null === $parent) return 'inherits';
            foreach ($node['viewports'] as $viewport => $observation) {
                foreach (self::INHERITED as $property) {
                    if (($observation['used_styles'][$property] ?? null) !== ($parent['viewports'][$viewport]['used_styles'][$property] ?? null)) return 'inherits';
                }
            }
            return 'no-box';
        }
        $placement = self::placement($node, $tree['nodes'][$node['children'][0]] ?? array());
        if (null !== $placement) return $placement;
        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMText && '' !== trim($child->textContent)) return 'own-sizing';
        }
        foreach ($node['viewports'] as $viewport => $observation) {
            $child = $tree['nodes'][$node['children'][0]]['viewports'][$viewport]['border_box'];
            foreach ($observation['border_box'] as $axis => $value) {
                if (abs($value - $child[$axis]) > 0.01) return 'own-sizing';
            }
        }
        return 'equal-child-geometry';
    }

    /**
     * A box that hides its subtree or places it within its own parent's layout
     * renders something of its own: without it, the child would take the
     * parent's layout with its own placement values. Equal geometry at the
     * captured viewports does not prove that placement is redundant.
     */
    private static function placement(array $node, array $child): ?string
    {
        foreach ($node['viewports'] as $viewport => $observation) {
            $s = $observation['used_styles'];
            if ('none' === $s['display']) return 'hidden';
            $c = $child['viewports'][$viewport]['used_styles'] ?? array();
            // An inline-level child sits on this box's line box; its parent's
            // layout would blockify or re-flow it.
            if (str_starts_with($c['display'] ?? '', 'inline')) return 'line-box';
            foreach (self::PLACEMENT as $property) {
                if (($s[$property] ?? null) !== ($c[$property] ?? null)) return 'own-placement';
            }
            if ('static' !== $s['position']) {
                foreach (array('top', 'right', 'bottom', 'left') as $offset) {
                    if (!in_array($s[$offset] ?? 'auto', array('auto', '0px'), true)) return 'own-placement';
                }
            }
        }
        return null;
    }

    /** Whether the element generates no box at every captured viewport. */
    private static function boxless(array $node): bool
    {
        if (array() === ($node['viewports'] ?? array())) return false;
        foreach ($node['viewports'] as $observation) {
            if ('contents' !== ($observation['used_styles']['display'] ?? null)) return false;
        }
        return true;
    }

    /** Names the HTML spec reserves despite the hyphen; they are not autonomous custom elements. */
    private const RESERVED_HYPHENATED_NAMES = array(
        'annotation-xml', 'color-profile', 'font-face', 'font-face-src', 'font-face-uri', 'font-face-format', 'font-face-name', 'missing-glyph',
    );

    /** Non-resting states: interaction pseudo-classes and runtime state attributes the capture replays. */
    private const STATE_PSEUDO_CLASS = '/(?<!:):(?:hover|focus-visible|focus-within|focus|active|checked|target)(?![\w(-])/i';
    // data-interact-key is a stable selector identity, not a runtime state.
    // It can scope resting rules too; a pseudo-class or another interaction
    // attribute in the selector still protects the host as a state handle.
    private const STATE_ATTRIBUTE = '/\[\s*data-interact-(?!key\s*(?:[~|^$*]?=|\]))/i';

    private static function isAutonomousCustomElement(DOMElement $element): bool
    {
        $tag = strtolower($element->tagName);
        return 1 === preg_match('/^[a-z][a-z0-9._]*-[a-z0-9._-]*$/', $tag) && !in_array($tag, self::RESERVED_HYPHENATED_NAMES, true);
    }

    /**
     * Compounds of document style rules that apply only in a non-resting state,
     * with state pseudo-classes removed so the resting capture can be matched.
     * A box matched by any compound of such a rule (subject or ancestor) is the
     * rule's handle; collapsing it would orphan the state styling.
     *
     * @return list<string>
     */
    private static function stateCompounds(\DOMDocument $dom): array
    {
        $compounds = array();
        foreach ($dom->getElementsByTagName('style') as $style) {
            $css = CssSyntaxScanner::withoutComments((string) $style->textContent);
            if (!preg_match_all('/([^{};]+)\{/', $css, $matches)) continue;
            foreach ($matches[1] as $prelude) {
                $prelude = trim($prelude);
                if ('' === $prelude || str_starts_with($prelude, '@')) continue;
                foreach (CssValueSplitter::splitTopLevel($prelude, array(',')) as $selector) {
                    if (!preg_match(self::STATE_PSEUDO_CLASS, $selector) && !preg_match(self::STATE_ATTRIBUTE, $selector)) continue;
                    $tokens = CssSelectorTokenizer::tokenize($selector);
                    foreach ($tokens['supported'] ? $tokens['compounds'] : array($selector) as $compound) {
                        $compound = trim((string) preg_replace(self::STATE_PSEUDO_CLASS, '', $compound));
                        $compounds['' === $compound ? '*' : $compound] = true;
                    }
                }
            }
        }
        return array_keys($compounds);
    }

    /** @param list<string> $compounds */
    private static function targetedByState(DOMElement $element, array $compounds): bool
    {
        foreach ($compounds as $compound) {
            if ('*' === $compound) continue;
            $match = CssSelectorMatcher::matches($element, CssSelectorMatcher::parse($compound));
            if ($match['matches']) return true;
            if ($match['supported']) continue;
            // An unparseable compound cannot prove the box is untargeted when it
            // names this element's tag or one of its attributes.
            if (preg_match('/(?:^|[^\w-])' . preg_quote(strtolower($element->tagName), '/') . '(?![\w-])/i', $compound)) return true;
            foreach ($element->attributes as $attribute) {
                if (preg_match('/\[\s*' . preg_quote($attribute->name, '/') . '(?![\w-])/i', $compound)) return true;
            }
        }
        return false;
    }

    /** Capture markers gate device visibility or interaction; the box carrying one is the engine's handle. */
    private static function carriesCaptureMarker(DOMElement $element): bool
    {
        foreach ($element->attributes as $attribute) {
            if (str_starts_with($attribute->name, CaptureEvidence::ATTRIBUTE_PREFIX)) return true;
        }
        foreach (preg_split('/\s+/', $element->getAttribute('class'), -1, PREG_SPLIT_NO_EMPTY) as $class) {
            if (str_starts_with($class, CaptureEvidence::ATTRIBUTE_PREFIX)) return true;
        }
        return false;
    }

    /** Properties that place a box within its parent's flow, flex, or grid layout. */
    private const PLACEMENT = array(
        'order', 'align-self', 'justify-self', 'flex-grow', 'flex-shrink', 'flex-basis',
        'grid-column-start', 'grid-column-end', 'grid-row-start', 'grid-row-end',
        'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'float', 'clear',
    );

    /** Geometry is checked independently on each clipped axis at every captured viewport. */
    private static function clips(array $node, array $tree): bool
    {
        foreach ($node['viewports'] as $viewport => $observation) {
            $styles = $observation['used_styles'];
            $clipping = array('hidden', 'clip', 'auto', 'scroll');
            if (!in_array($styles['overflow-x'], $clipping, true) && !in_array($styles['overflow-y'], $clipping, true)) continue;
            $box = $observation['border_box'];
            $pending = $node['children'];
            while ($pending) {
                $descendant = $tree['nodes'][array_pop($pending)];
                $child = $descendant['viewports'][$viewport]['border_box'];
                foreach (array('x' => 'width', 'y' => 'height') as $axis => $size) {
                    $overflow = $observation['used_styles']['overflow-' . $axis];
                    if (in_array($overflow, $clipping, true)
                        && ($child[$axis] < $box[$axis] - 0.01 || $child[$axis] + $child[$size] > $box[$axis] + $box[$size] + 0.01)) return true;
                }
                foreach ($descendant['children'] as $id) $pending[] = $id;
            }
        }
        return false;
    }

    private static function identityTransform(string $transform): bool
    {
        if ('none' === $transform) return true;
        if (!preg_match('/^(matrix|matrix3d)\(([^)]+)\)$/', trim($transform), $match)) return false;
        $values = array_map('trim', explode(',', $match[2]));
        $identity = 'matrix' === $match[1] ? array(1, 0, 0, 1, 0, 0) : array(1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1);
        if (count($values) !== count($identity)) return false;
        foreach ($values as $i => $value) {
            if (!is_numeric($value) || (float) $value !== (float) $identity[$i]) return false;
        }
        return true;
    }

    private static function transparent(string $color): bool
    {
        $color = strtolower(trim($color));
        if ('transparent' === $color) return true;
        if (preg_match('/\/\s*([\d.e+-]+)%?\s*\)$/', $color, $match)) return (float) $match[1] === 0.0;
        return 1 === preg_match('/^rgba\([^,]+,[^,]+,[^,]+,\s*0(?:\.0*)?%?\s*\)$/', $color);
    }

    private static function paints(array $s): bool
    {
        if (!self::identityTransform($s['transform'])) return true;
        foreach (array('box-shadow', 'filter') as $property) {
            if ('none' !== $s[$property]) return true;
        }
        if ((float) $s['opacity'] !== 1.0 || !in_array($s['mix-blend-mode'], array('normal', 'none'), true)) return true;
        if (!self::transparent($s['background-color'])) return true;
        foreach (array('background-image', 'mask-image') as $property) {
            if (!preg_match('/^none(?:\s*,\s*none)*$/i', trim($s[$property]))) return true;
        }
        foreach (array('border-top', 'border-right', 'border-bottom', 'border-left', 'outline') as $property) {
            if (!preg_match('/\b(?:none|hidden)\b|(?:^|\s)0(?:px)?(?:\s|$)/', $s[$property]) && !str_contains($s[$property], 'transparent') && !preg_match('/rgba\([^)]*,\s*0\s*\)/', $s[$property])) return true;
        }
        return false;
    }
}
