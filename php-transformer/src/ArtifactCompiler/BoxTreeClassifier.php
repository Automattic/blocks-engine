<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorTokenizer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSyntaxScanner;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;
use DOMElement;

/**
 * ADR 0005 box classification. A `neutral` or `folded` disposition is the sole
 * authority for collapsing a source box during conversion; every other
 * disposition keeps the box. A folded box moves the declarations that made it
 * load-bearing onto its sole child ({@see self::fold()}). The projection
 * estimates the resulting block tree for reporting.
 */
final class BoxTreeClassifier
{
    /** Fold rules, in report order. */
    public const FOLD_RULES = array('own-sizing', 'paint', 'containing-block', 'own-placement', 'viewport-entrance', 'clip');

    /** Criteria a fold can discharge; every other criterion keeps the box. */
    private const FOLDABLE = array('paints', 'clips', 'containing-block', 'own-sizing', 'own-placement', 'viewport-entrance');

    /**
     * Text blocks a viewport entrance can move to: each always emits its own
     * block, and that block's root renders both an anchor and runtime
     * attributes, so the entrance and the id its state rules key on land on
     * the element the box's border box became.
     */
    private const ENTRANCE_TARGETS = array('p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6');

    /** Report-safe classification: dispositions, criteria, fold rules, counts and projection. */
    public static function report(string $html, array $tree): array
    {
        $result = self::classify($html, $tree);
        unset($result['folds'], $result['observations']);
        return $result;
    }

    /**
     * The classification plus what conversion needs to apply folds:
     * `folds` maps each folded box id to `child` (the sole child id that takes
     * its place), `moves` (child property => box property whose value and
     * author bands the child now carries) and `equal_box` (whether the child's
     * border box equals the box's at every viewport). `observations` maps each
     * child that received declarations to its viewport observations with
     * those declarations applied.
     */
    public static function classify(string $html, array $tree): array
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
                $styles = $tree['nodes'][$id]['viewports'][$viewport]['used_styles'] ?? null;
                // Shared chrome may carry fewer viewports than its route.
                if (!is_array($styles)) { $absolute[$id] = $a; $fixed[$id] = $f; continue; }
                if ('absolute' === $styles['position'] && null !== $a) $containing[$a] = true;
                if ('fixed' === $styles['position'] && null !== $f) $containing[$f] = true;
                $establishes = 'none' !== $styles['transform'] || 'none' !== $styles['filter'];
                $absolute[$id] = $establishes || 'static' !== $styles['position'] ? $id : $a;
                $fixed[$id] = $establishes ? $id : $f;
            }
        }
        $stateCompounds = null;
        $nodes = $folds = $blockParents = $received = array();
        $counts = array('load-bearing' => 0, 'neutral' => 0, 'folded' => 0);
        $foldCounts = array_fill_keys(self::FOLD_RULES, 0);
        // Document order visits a box before its child, so a fold's moved
        // declarations are on the child's effective observation before the
        // child is classified in turn (a chain of folds composes).
        $effective = $tree;
        foreach ($elements as $id => $element) {
            $node = $effective['nodes'][$id];
            $parent = $parents[$id];
            $blockParents[$id] = isset($nodes[$parent]) && 'load-bearing' !== $nodes[$parent]['disposition'] ? $blockParents[$parent] : $parent;
            $stateTargeted = false;
            if (self::isAutonomousCustomElement($element)) {
                $stateCompounds ??= self::stateCompounds($dom);
                $stateTargeted = self::targetedByState($element, $stateCompounds);
            }
            $referenced = isset($references[$element->getAttribute('id')]);
            $criterion = self::criterion($element, $node, $effective, isset($containing[$id]), $referenced, $stateTargeted);
            $disposition = 'equal-child-geometry' === $criterion ? 'neutral' : 'load-bearing';
            $nodes[$id] = array('disposition' => $disposition, 'criterion' => $criterion);
            if ('semantics/id/anchor' === $criterion && self::isLinkCarrier($element, $referenced)) {
                $stateCompounds ??= self::stateCompounds($dom);
                if (!self::targetedByOwnState($element, $stateCompounds)
                    && 'equal-child-geometry' === self::criterion($element, $node, $effective, isset($containing[$id]), $referenced, false, true)) $nodes[$id]['link_carrier'] = true;
            }
            if ('load-bearing' === $disposition && in_array($criterion, self::FOLDABLE, true)) {
                // A state rule kept on the route can hang on any tag, not only custom elements.
                if (!$stateTargeted) {
                    $stateCompounds ??= self::stateCompounds($dom);
                    $stateTargeted = self::targetedByState($element, self::entranceKeepsIdRules($element, $node, $stateCompounds));
                }
                $fold = $stateTargeted ? null : self::fold($element, $node, $effective, $effective['nodes'][$blockParents[$id]] ?? null, isset($containing[$id]), $referenced);
                if (null !== $fold) {
                    $child = $node['children'][0];
                    foreach ($effective['nodes'][$child]['viewports'] as $viewport => &$observation) {
                        if (!isset($node['viewports'][$viewport])) continue;
                        foreach ($fold['moves'] as $to => $from) $observation['used_styles'][$to] = $node['viewports'][$viewport]['used_styles'][$from];
                        if (isset($fold['moves']['position']) && 'absolute' === $observation['used_styles']['position']) {
                            $observation['used_styles']['align-self'] = $observation['used_styles']['justify-self'] = 'auto';
                        }
                    }
                    unset($observation);
                    // The child's border box is the box's, so it now contains what the box contained.
                    if (isset($containing[$id])) $containing[$child] = true;
                    if (array() !== $fold['moves']) $received[$child] = true;
                    $folds[$id] = array('child' => $child) + $fold;
                    $disposition = 'folded';
                    $nodes[$id] = array('disposition' => $disposition, 'criterion' => $criterion, 'fold' => $fold['rules']);
                    foreach ($fold['rules'] as $rule) ++$foldCounts[$rule];
                }
            }
            ++$counts[$disposition];
        }
        $observations = array();
        foreach ($received as $id => $_) $observations[$id] = $effective['nodes'][$id]['viewports'];
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
        return array('counts' => $counts, 'fold_counts' => $foldCounts, 'nodes' => $nodes, 'folds' => $folds, 'observations' => $observations, 'projection' => $tree ? array(
            'basis' => 'estimated-block-producing-boxes', 'depth' => $depth, 'groups' => $groups,
            'leaves' => $leaves, 'groups_per_leaf' => $leaves ? $groups / $leaves : 0.0,
        ) : null);
    }

    private static function criterion(DOMElement $element, array $node, array $tree, bool $containing, bool $referenced, bool $stateTargeted = false, bool $linkCarried = false): string
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
        $behavior = self::behavior($element, $node, $tree, $referenced, $stateTargeted, $linkCarried);
        if (null !== $behavior) return $behavior;
        if (1 !== count($node['children'])) return 'own-sizing';
        $childNode = $tree['nodes'][$node['children'][0]] ?? array();
        // display:contents has no principal box. Its child's placement belongs
        // to the outer layout already; comparing it to the wrapper's unused
        // placement values invents a Group boundary.
        if (self::boxlessContents($element, $node, $childNode)) return 'equal-child-geometry';
        $placement = self::placement($node, $childNode);
        if (null !== $placement) return $placement;
        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMText && '' !== trim($child->textContent)) return 'own-sizing';
        }
        foreach ($node['viewports'] as $viewport => $observation) {
            $child = $tree['nodes'][$node['children'][0]]['viewports'][$viewport]['border_box'] ?? null;
            // A child observed at fewer viewports cannot prove equal geometry.
            if (!is_array($child)) return 'own-sizing';
            foreach ($observation['border_box'] as $axis => $value) {
                if (abs($value - $child[$axis]) > 0.01) return 'own-sizing';
            }
        }
        return 'equal-child-geometry';
    }

    /** A single-child, non-interactive contents wrapper contributes no box. */
    private static function boxlessContents(DOMElement $element, array $node, array $child): bool
    {
        // Custom hosts have a separate transparent-hook lowering. Their DOM
        // boundary still supplies selector and runtime context to descendants;
        // treating them as ordinary neutral boxes changes that lowering.
        if (str_contains($element->tagName, '-')) return false;
        foreach ($element->childNodes as $text) {
            if ($text instanceof \DOMText && '' !== trim($text->textContent)) return false;
        }
        foreach ($node['viewports'] as $viewport => $observation) {
            $styles = $observation['used_styles'];
            $own = $child['viewports'][$viewport]['used_styles'] ?? null;
            if ('contents' !== ($styles['display'] ?? null) || !is_array($own)) return false;
            // Do not erase a live ancestor state rule, or rebase an inherited
            // value the child derives from this wrapper.
            foreach (self::INHERITED as $property) {
                if (($styles[$property] ?? null) !== ($own[$property] ?? null)) return false;
            }
            // A contents box cannot itself be hit. A sole child restoring
            // pointer-events:auto already overrides its disabled ancestor;
            // removing that ancestor changes neither it nor its descendants.
            // Keep other values conservative (including missing evidence).
            if (($styles['pointer-events'] ?? null) !== ($own['pointer-events'] ?? null)
                && !('none' === ($styles['pointer-events'] ?? null) && 'auto' === ($own['pointer-events'] ?? null))) return false;
        }
        return !self::targetedByState($element, self::stateCompounds($element->ownerDocument));
    }

    /**
     * Load-bearing reasons no declaration move can discharge: semantics and
     * references, interaction and state, capture markers, motion, and
     * unobserved children.
     */
    private static function behavior(DOMElement $element, array $node, array $tree, bool $referenced, bool $stateTargeted, bool $linkCarried = false): ?string
    {
        // Autonomous custom elements have no implicit role: they are generic
        // containers like div/span and fall through to the remaining criteria.
        // A link carrier's anchor semantics are its link, which conversion
        // moves onto the blocks it wraps ({@see self::isLinkCarrier()}).
        $generic = in_array(strtolower($element->tagName), array('div', 'span'), true) || self::isAutonomousCustomElement($element) || $linkCarried;
        if (!$generic || $referenced || $element->hasAttribute('role') || $element->hasAttribute('name')) return 'semantics/id/anchor';
        foreach ($element->attributes as $attribute) {
            if (str_starts_with($attribute->name, 'on') || str_starts_with($attribute->name, 'aria-') || in_array($attribute->name, array('tabindex', 'contenteditable', 'draggable', 'hidden', 'inert'), true)) return 'interaction/state';
        }
        if (!empty($node['interaction_states']) || $stateTargeted) return 'interaction/state';
        // A viewport entrance is a runtime handle, not geometry: it can move
        // to a sole text child ({@see self::entranceTarget()}). Any other
        // capture marker keeps the box.
        $entrance = self::carriesCaptureMarker($element, $linkCarried);
        if ($entrance && (!self::carriesOnlyViewportEntrance($element) || null === self::entranceTarget($element, $node))) return 'capture-marker';
        foreach ($node['viewports'] as $observation) {
            $s = $observation['used_styles'];
            if (isset($s['animation-name']) && 'none' !== $s['animation-name']) return 'motion';
            if (isset($s['transition-duration']) && preg_match('/[1-9]/', $s['transition-duration']) && 'none' !== ($s['transition-property'] ?? 'all')) return 'motion';
        }
        // Unknown boxes between observations cannot prove wrapper neutrality.
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement && !isset($tree['nodes'][$child->getAttribute(BoxTree::NODE_ATTRIBUTE)])) return 'unobserved-child';
        }
        return $entrance ? 'viewport-entrance' : null;
    }

    /**
     * State compounds a fold must not orphan. A box whose viewport entrance
     * moves to its child takes its id there too, so a compound naming that id
     * still finds the element that replays the entrance.
     *
     * @param list<string> $compounds
     * @return list<string>
     */
    private static function entranceKeepsIdRules(DOMElement $element, array $node, array $compounds): array
    {
        $id = $element->getAttribute('id');
        if ('' === $id || !self::carriesOnlyViewportEntrance($element) || null === self::entranceTarget($element, $node)) return $compounds;
        $pattern = '/#' . preg_quote($id, '/') . '(?![\w-])/';
        return array_values(array_filter($compounds, static fn (string $compound): bool => !preg_match($pattern, $compound)));
    }

    /** Whether the only capture marker a box carries is its viewport entrance. */
    private static function carriesOnlyViewportEntrance(DOMElement $element): bool
    {
        foreach ($element->attributes as $attribute) {
            if (str_starts_with($attribute->name, CaptureEvidence::ATTRIBUTE_PREFIX) && CaptureEvidence::VIEWPORT_ENTRANCE !== $attribute->name) return false;
        }
        foreach (preg_split('/\s+/', $element->getAttribute('class'), -1, PREG_SPLIT_NO_EMPTY) as $class) {
            if (str_starts_with($class, CaptureEvidence::ATTRIBUTE_PREFIX)) return false;
        }
        return $element->hasAttribute(CaptureEvidence::VIEWPORT_ENTRANCE);
    }

    /**
     * The sole child a box's viewport entrance and id can move to: a text
     * block with no id, capture marker or inline motion properties of its own,
     * under a box with only transferable --motion-* inline declarations, so its state rules
     * (keyed on the box's id) and the replayed entrance stay on one element.
     */
    public static function entranceTarget(DOMElement $element, array $node): ?DOMElement
    {
        if (1 !== count($node['children'])) return null;
        foreach (CssRuleAnalyzer::declarationList($element->getAttribute('style')) as $declaration) {
            if (!preg_match('/^--motion-/i', $declaration['name'])) return null;
        }
        foreach ($element->childNodes as $child) {
            if (!$child instanceof DOMElement || $node['children'][0] !== $child->getAttribute(BoxTree::NODE_ATTRIBUTE)) continue;
            if (!in_array(strtolower($child->tagName), self::ENTRANCE_TARGETS, true) || $child->hasAttribute('id') || self::carriesCaptureMarker($child)) return null;
            foreach (CssRuleAnalyzer::declarationList($child->getAttribute('style')) as $declaration) {
                if (preg_match('/^--motion-/i', $declaration['name'])) return null;
            }
            return $child;
        }
        return null;
    }

    /** Paint a box can hand to a child that occupies its border box. */
    private const PAINT = array(
        'background-color', 'background-image', 'background-position-x', 'background-position-y', 'background-size',
        'background-repeat', 'background-clip', 'background-origin', 'background-attachment', 'background-blend-mode',
        'border-radius', 'box-shadow', 'outline',
    );

    /** Placement longhands other than margins, with the CSS initial values the child must hold to receive them. */
    private const PLACEMENT_INITIAL = array(
        'order' => '0', 'align-self' => 'auto', 'justify-self' => 'auto', 'flex-grow' => '0', 'flex-shrink' => '1', 'flex-basis' => 'auto',
        'grid-column-start' => 'auto', 'grid-column-end' => 'auto', 'grid-row-start' => 'auto', 'grid-row-end' => 'auto',
        'float' => 'none', 'clear' => 'none',
    );

    /** Sizing constraints, with the values that constrain nothing. */
    private const CONSTRAINT_INITIAL = array(
        'min-width' => array('auto', '0px', 'none'), 'min-height' => array('auto', '0px', 'none'),
        'max-width' => array('none'), 'max-height' => array('none'),
    );

    /** Compositing a fold would change: each creates a stacking context or clips. */
    private const NEVER_FOLD = array(
        'transform' => array('none'), 'filter' => array('none'), 'backdrop-filter' => array('none'), 'opacity' => array('1'),
        'mix-blend-mode' => array('normal', 'none'), 'isolation' => array('auto'), 'clip-path' => array('none'),
        'mask-image' => array('none'), 'z-index' => array('auto'),
    );

    private const FLEX_OR_GRID = array('flex', 'inline-flex', 'grid', 'inline-grid');

    /** Inherited properties a box can own for its subtree; removing the box re-bases them on its parent. */
    private const INHERITED = array(
        'color', 'font-family', 'font-size', 'font-weight', 'font-style', 'font-variant', 'font-stretch', 'line-height',
        'letter-spacing', 'word-spacing', 'text-align', 'text-transform', 'text-indent', 'text-shadow', 'white-space',
        'word-break', 'overflow-wrap', 'hyphens', 'direction', 'list-style-type', 'list-style-position', 'visibility', 'cursor',
    );

    /**
     * Inherited properties whose child value can be relative to the box's
     * (an `em`/`%` font size, a unitless or `em` line height, a `%` indent):
     * a child that does not repeat the box's value may still be derived from it.
     */
    private const INHERITANCE_BASIS = array('font-size', 'line-height', 'text-indent');

    /**
     * ADR 0005 slice 5: a single-child box that is load-bearing only through
     * its own declarations hands them to its child, proved by geometry at
     * every captured viewport, and then emits no block.
     *
     * - `own-sizing`: padding becomes the child's margin on that side when the
     *   child has none there and its border box sits exactly inside the box's
     *   padding edge. A vertical side needs a flex or grid parent, where the
     *   new margin cannot collapse. Sizing constraints move only when the
     *   child's border box is the box's and neither has padding or borders.
     * - `paint`: background, radius, shadow and outline move when the child's
     *   border box is the box's and the child paints none of them. A border
     *   that paints has a width, so its child never fills the border box.
     * - `containing-block`: `position: relative` (and its offsets) moves to a
     *   static child whose border box is the box's.
     * - `own-placement`: placement in the parent (grid lines, self-alignment,
     *   order, flex item factors, margins, float/clear) moves to a child whose
     *   own values are initial (or, in a one-cell grid box, grid lines naming
     *   that cell), where the child's margin box then equals the box's margin
     *   box.
     *
     * Absolute inset-zero boxes can hand position to an equal-box flow child.
     * A clip is redundant when that child repeats it without exterior paint,
     * or moves to a non-clipping child that preserves descendant clipping.
     *
     * Never folded: pseudo-elements, transforms, filters, opacity, blending,
     * isolation, unmatched z-index, other non-flow boxes, and every
     * criterion {@see self::behavior()} reports.
     *
     * @param array<string, mixed>|null $parent The node the child would sit in.
     * @return array{rules: list<string>, moves: array<string, string>, equal_box: bool}|null
     */
    private static function fold(DOMElement $element, array $node, array $tree, ?array $parent, bool $containing, bool $referenced): ?array
    {
        if (1 !== count($node['children']) || null === $parent) return null;
        $behavior = self::behavior($element, $node, $tree, $referenced, false);
        if (null !== $behavior && 'viewport-entrance' !== $behavior) return null;
        foreach ($element->childNodes as $text) {
            if ($text instanceof \DOMText && '' !== trim($text->textContent)) return null;
        }
        $child = $tree['nodes'][$node['children'][0]] ?? null;
        if (null === $child) return null;
        $sides = array('top' => array('y', 'height', 1), 'right' => array('x', 'width', -1), 'bottom' => array('y', 'height', -1), 'left' => array('x', 'width', 1));
        $source = array_fill_keys(array_keys($sides), null);
        $moves = $rules = array();
        $equalBox = true;
        $boxless = true;
        // A border-box child measures a moved constraint on its border box.
        $childBorderBox = true;
        $constrained = false;
        $paints = false;
        $relative = false;
        $absolute = null;
        $clipped = false;
        // Inherited property => [the box owns a value its parent lacks, the child repeats the box's value at every viewport].
        $inherited = array();
        foreach ($node['viewports'] as $viewport => $observation) {
            $b = $observation['used_styles'];
            $c = $child['viewports'][$viewport]['used_styles'] ?? null;
            $p = $parent['viewports'][$viewport]['used_styles'] ?? null;
            if (!is_array($c) || !is_array($p) || !empty($observation['pseudo'])) return null;
            $fill = 'absolute' === $b['position'];
            if (null !== $absolute && $absolute !== $fill) return null;
            $absolute = $fill;
            foreach (self::NEVER_FOLD as $property => $inert) {
                if ('z-index' === $property && $fill && $b[$property] === ($c[$property] ?? null)) continue;
                if (isset($b[$property]) && !in_array(strtolower(trim($b[$property])), $inert, true)) return null;
            }
            // A square child can take the box's rounded clip. The geometry
            // and border checks below still require equal, borderless boxes.
            $roundedClip = !in_array($b['border-radius'], array('0px', 'none'), true)
                && '0px' === $c['border-radius'];
            $transferClip = false;
            foreach (array('overflow-x', 'overflow-y') as $overflow) {
                if (!in_array($b[$overflow], array('hidden', 'clip', 'auto', 'scroll'), true)) continue;
                if (($b[$overflow] !== $c[$overflow] && 'visible' !== $c[$overflow]) || 'none' !== $c['box-shadow']
                    || self::paintsLine($c['outline']) || !self::identityTransform($c['transform'])
                    || ($b['border-radius'] !== $c['border-radius'] && !$roundedClip)) return null;
                $transferClip = $transferClip || $b[$overflow] !== $c[$overflow];
                if ($roundedClip) $moves['border-radius'] = 'border-radius';
                $clipped = true;
            }
            if ($transferClip) {
                // Moving both axes must not erase an independent child clip.
                foreach (array('overflow-x', 'overflow-y') as $overflow) {
                    if ($b[$overflow] !== $c[$overflow] && 'visible' !== $c[$overflow]) return null;
                    $moves[$overflow] = $overflow;
                }
                $moves['border-radius'] = 'border-radius';
                // A static child need not be the containing block of a
                // positioned descendant. Refuse rather than moving its clip
                // below the ancestor that positions that descendant.
                if ('static' === $c['position'] && self::hasPositionedDescendant($child, $tree, $viewport)) return null;
            }
            $display = $b['display'];
            $childDisplay = $c['display'];
            if (!in_array($display, array('block', 'flow-root', 'flex', 'grid'), true)) return null;
            if (str_starts_with($childDisplay, 'inline') || in_array($childDisplay, array('contents', 'none'), true)) return null;
            $parentLays = in_array($p['display'], self::FLEX_OR_GRID, true);
            // A box that establishes its own formatting context keeps the
            // child's margins from collapsing with the child's content; in a
            // block parent the child must establish one itself.
            if (!$fill && 'block' !== $display && !$parentLays && !in_array($childDisplay, array('flex', 'grid', 'flow-root', 'table'), true)) return null;
            if (!in_array($b['position'], array('static', 'relative', 'absolute'), true) || !in_array($c['position'], array('static', 'relative'), true)) return null;
            if ($fill) {
                foreach (array('top', 'right', 'bottom', 'left') as $offset) {
                    if ('0px' !== ($b[$offset] ?? null) || !in_array($c[$offset] ?? 'auto', array('auto', '0px'), true)) return null;
                }
            }
            if ('relative' === $b['position']) {
                $offsets = false;
                foreach (array('top', 'right', 'bottom', 'left') as $offset) $offsets = $offsets || !in_array($b[$offset] ?? 'auto', array('auto', '0px'), true);
                // Two relative offsets would add up on one box.
                if ($offsets && 'relative' === $c['position']) return null;
                if ('static' === $c['position']) $relative = true;
            }
            $box = $observation['border_box'];
            $inner = $child['viewports'][$viewport]['border_box'];
            foreach ($sides as $side => [$axis, $size, $direction]) {
                $border = self::borderWidth($b['border-' . $side]);
                $padding = self::pixels($b['padding-' . $side]);
                $margin = self::pixels($b['margin-' . $side]);
                $childMargin = self::pixels($c['margin-' . $side]);
                if (null === $padding || null === $margin || null === $childMargin) return null;
                if (null === $border || 0.0 !== $border) {
                    // A bordered box paints around its child: only a non-painting border of zero width folds.
                    return null;
                }
                $edge = 1 === $direction ? $box[$axis] : $box[$axis] + $box[$size];
                $childEdge = 1 === $direction ? $inner[$axis] : $inner[$axis] + $inner[$size];
                if (abs($edge + $direction * $padding - $childEdge) > 0.01) return null;
                $childBorder = self::borderWidth($c['border-' . $side]);
                // Overflow clips at the padding edge, not outside a child's border.
                if ($clipped && 0.0 !== $childBorder) return null;
                if (0.0 !== $padding || null === $childBorder || 0.0 !== $childBorder || 0.0 !== (float) (self::pixels($c['padding-' . $side]) ?? 1.0)) $boxless = false;
                if (0.0 !== $padding) {
                    $equalBox = false;
                    if (0.0 !== $childMargin || 'margin' === $source[$side]) return null;
                    if ('y' === $axis && !$parentLays) return null;
                    $source[$side] = 'padding';
                } elseif ($margin !== $childMargin && !(0.0 === $margin && 'y' === $axis && 'block' === $display && !$parentLays)) {
                    // (A child's vertical margin already collapses through a
                    // flow box with no padding or border into a block parent.)
                    if (0.0 !== $childMargin || 'padding' === $source[$side]) return null;
                    $source[$side] = 'margin';
                }
            }
            foreach (self::PLACEMENT_INITIAL as $property => $initial) {
                // Four zero insets determine an absolute fill's placement;
                // the child's former self-alignment is no longer relevant.
                if ($fill && in_array($property, array('align-self', 'justify-self'), true)) continue;
                if ($b[$property] === $c[$property]) continue;
                if ($initial !== $c[$property] && !self::fillsSoleGridCell($b, $property, $c[$property])) return null;
                $moves[$property] = $property;
            }
            $childBorderBox = $childBorderBox && 'border-box' === ($c['box-sizing'] ?? null);
            foreach (self::CONSTRAINT_INITIAL as $property => $initial) {
                if (in_array($b[$property], $initial, true)) continue;
                if (!in_array($c[$property], $initial, true)) return null;
                $constrained = true;
            }
            if (self::boxPaints($b)) {
                if (self::boxPaints($c) || !in_array($c['border-radius'], array('0px', 'none'), true)) return null;
                // A rounded child that clips would start clipping its own content.
                if (!$roundedClip && !in_array($b['border-radius'], array('0px', 'none'), true) && (in_array($c['overflow-x'], array('hidden', 'clip', 'auto', 'scroll'), true) || in_array($c['overflow-y'], array('hidden', 'clip', 'auto', 'scroll'), true))) return null;
                $paints = true;
            }
            foreach (self::INHERITED as $property) {
                if (!isset($b[$property])) continue;
                $state = $inherited[$property] ?? array(false, true);
                $inherited[$property] = array(
                    $state[0] || $b[$property] !== ($p[$property] ?? null),
                    $state[1] && $b[$property] === ($c[$property] ?? null),
                );
            }
        }
        // A box that owns an inherited value (the wrapper of a rich-text run
        // owning its font size) folds only when that value moves with it.
        $inheritedMoves = array();
        foreach ($inherited as $property => [$owned, $repeated]) {
            if (!$owned) continue;
            if ($repeated) {
                // The child inherits it: restate it on the child, with the box's author bands.
                $inheritedMoves[$property] = $property;
                continue;
            }
            // The child's own value may be derived from the box's (an `em`
            // font size): without the box it would re-base on the new parent.
            if (in_array($property, self::INHERITANCE_BASIS, true)) return null;
            // Elsewhere a child value that differs at a viewport is its own
            // there; inheriting the box's at some viewports and not others
            // cannot be restated as one move.
            foreach ($node['viewports'] as $viewport => $observation) {
                $b = $observation['used_styles'];
                $c = $child['viewports'][$viewport]['used_styles'];
                $p = $parent['viewports'][$viewport]['used_styles'];
                if ($b[$property] !== ($p[$property] ?? null) && $b[$property] === ($c[$property] ?? null)) return null;
            }
        }
        // Positioned descendants resolve against the padding box: it must not change.
        if (($containing || $relative || $absolute || $clipped) && !$equalBox) return null;
        if ($paints && !$equalBox) return null;
        // With an equal box the box has no padding or border, so its
        // constraint bounds the border box the child shares: the child holds
        // it as is when it has no box either, or sizes its border box.
        if ($constrained && !($equalBox && ($boxless || $childBorderBox))) return null;
        foreach ($source as $side => $from) {
            if (null !== $from) $moves['margin-' . $side] = $from . '-' . $side;
        }
        if (in_array('padding', $source, true) || $constrained) $rules[] = 'own-sizing';
        if ($constrained) {
            foreach (self::CONSTRAINT_INITIAL as $property => $_) $moves[$property] = $property;
        }
        if ($paints) {
            $rules[] = 'paint';
            foreach (self::PAINT as $property) $moves[$property] = $property;
        }
        if ($relative || $containing) {
            $rules[] = 'containing-block';
            if ($relative) {
                foreach (array('position', 'top', 'right', 'bottom', 'left') as $property) $moves[$property] = $property;
            }
        }
        if ($absolute) {
            foreach (array('position', 'top', 'right', 'bottom', 'left') as $property) $moves[$property] = $property;
        }
        if ($clipped) $rules[] = 'clip';
        if ($absolute || array_intersect_key($moves, self::PLACEMENT_INITIAL) || in_array('margin', $source, true)) $rules[] = 'own-placement';
        if ('viewport-entrance' === $behavior) {
            // The replayed entrance animates the element that carries it; the
            // child plays it identically only on the box's own border box.
            if (!$equalBox) return null;
            $rules[] = 'viewport-entrance';
        }
        if (array() === $rules) return null;
        $moves += $inheritedMoves;
        return array('rules' => $rules, 'moves' => $moves, 'equal_box' => $equalBox);
    }

    /** Missing descendant evidence cannot prove that moving a clip is safe. */
    private static function hasPositionedDescendant(array $node, array $tree, string|int $viewport): bool
    {
        $pending = $node['children'];
        while ($pending) {
            $descendant = $tree['nodes'][array_pop($pending)] ?? null;
            $position = $descendant['viewports'][$viewport]['used_styles']['position'] ?? null;
            if (null === $position || in_array($position, array('absolute', 'fixed'), true)) return true;
            foreach ($descendant['children'] as $id) $pending[] = $id;
        }
        return false;
    }

    /**
     * A child's grid line inside a one-cell grid box names that cell, which is
     * the box itself: it places the child nowhere once the box's own placement
     * replaces it.
     */
    private static function fillsSoleGridCell(array $box, string $property, string $value): bool
    {
        if (!str_starts_with($property, 'grid-') || 'grid' !== $box['display']) return false;
        foreach (array('grid-template-columns', 'grid-template-rows') as $template) {
            if (!preg_match('/^(?:\d*\.)?\d+px$/', trim((string) ($box[$template] ?? '')))) return false;
        }
        return in_array(trim($value), str_ends_with($property, '-start') ? array('1') : array('2', 'span 1'), true);
    }

    /** Whether a box paints a background, border, shadow or outline of its own. */
    private static function boxPaints(array $s): bool
    {
        if (!self::transparent($s['background-color'])) return true;
        if (!preg_match('/^none(?:\s*,\s*none)*$/i', trim($s['background-image'])) || 'none' !== $s['box-shadow']) return true;
        foreach (array('border-top', 'border-right', 'border-bottom', 'border-left', 'outline') as $property) {
            if (self::paintsLine($s[$property])) return true;
        }
        return false;
    }

    private static function paintsLine(string $value): bool
    {
        return !preg_match('/\b(?:none|hidden)\b|(?:^|\s)0(?:px)?(?:\s|$)/', $value) && !str_contains($value, 'transparent') && !preg_match('/rgba\([^)]*,\s*0\s*\)/', $value);
    }

    /** The width of an observed border side (`1px solid red`, `none`), or null when unreadable. */
    private static function borderWidth(string $value): ?float
    {
        $tokens = preg_split('/\s+/', strtolower(trim($value))) ?: array();
        if (in_array('none', $tokens, true) || in_array('hidden', $tokens, true)) return 0.0;
        foreach ($tokens as $token) {
            if (preg_match('/^(?:\d*\.)?\d+px$/', $token)) return (float) $token;
            if ('0' === $token) return 0.0;
        }
        return null;
    }

    private static function pixels(string $value): ?float
    {
        return preg_match('/^-?(?:\d*\.)?\d+px$/', trim($value)) ? (float) trim($value) : null;
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

    /** Names the HTML spec reserves despite the hyphen; they are not autonomous custom elements. */
    private const RESERVED_HYPHENATED_NAMES = array(
        'annotation-xml', 'color-profile', 'font-face', 'font-face-src', 'font-face-uri', 'font-face-format', 'font-face-name', 'missing-glyph',
    );

    /** Non-resting states: interaction pseudo-classes and runtime state attributes the capture replays. */
    private const STATE_PSEUDO_CLASS = '/(?<!:):(?:hover|focus-visible|focus-within|focus|active|checked|target)(?![\w(-])/i';
    private const STATE_ATTRIBUTE = '/\[\s*data-interact-/i';
    /** A `:not()` filter, with up to two levels of nested parentheses. */
    private const NEGATION = '/:not\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)/i';

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
                        // Only negations left (`:not(.x):focus`) names no box:
                        // like `*`, it is whichever element enters the state.
                        $positive = trim((string) preg_replace(self::NEGATION, '', $compound));
                        $compounds['' === $positive || '*' === $positive ? '*' : $compound] = true;
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

    /** Capture annotation of the in-page fragment an anchor's href resolved to. */
    private const LINK_FRAGMENT_MARKER = CaptureEvidence::ATTRIBUTE_PREFIX . 'anchor-fragment';

    /**
     * A whole-element link wrapper: a text-less `<a href>` with no identity of
     * its own. Conversion carries its link onto the blocks it wraps
     * (`LinkWrapperPropagator`), so once that link has moved the anchor's box
     * is neutral exactly when a generic box of the same geometry would be.
     * The classifier only marks it (`link_carrier`); conversion collapses it
     * only after the link has actually moved. Text is excluded: it would
     * re-base the anchor's inherited paint (link colour) on its parent.
     */
    private static function isLinkCarrier(DOMElement $element, bool $referenced): bool
    {
        return 'a' === strtolower($element->tagName)
            && '' !== trim($element->getAttribute('href'))
            && !$referenced
            && !$element->hasAttribute('id')
            && !$element->hasAttribute('role')
            && !$element->hasAttribute('name')
            && '' === trim($element->textContent);
    }

    /**
     * Whether a state rule hangs on this element's own identity. A compound a
     * bare `<a href>` also matches (`a:hover`, a universal focus ring) styles
     * every link, so it still reaches the anchors the carried link becomes.
     *
     * @param list<string> $compounds
     */
    private static function targetedByOwnState(DOMElement $element, array $compounds): bool
    {
        $bare = $element->ownerDocument?->createElement('a');
        if (null === $bare) return true;
        $bare->setAttribute('href', $element->getAttribute('href'));
        foreach ($compounds as $compound) {
            if ('*' === $compound) continue;
            if (self::targetedByState($element, array($compound)) && !self::targetedByState($bare, array($compound))) return true;
        }
        return false;
    }

    /** Capture markers gate device visibility or interaction; the box carrying one is the engine's handle. */
    private static function carriesCaptureMarker(DOMElement $element, bool $linkCarried = false): bool
    {
        foreach ($element->attributes as $attribute) {
            // The fragment a carried link resolved to travels with its href.
            if ($linkCarried && self::LINK_FRAGMENT_MARKER === $attribute->name) continue;
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
                $child = $descendant['viewports'][$viewport]['border_box'] ?? null;
                // An unobserved descendant cannot prove it stays inside the clip.
                if (!is_array($child)) return true;
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
            if (self::paintsLine($s[$property])) return true;
        }
        return false;
    }
}
