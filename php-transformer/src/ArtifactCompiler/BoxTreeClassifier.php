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
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;
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

    /** Report-safe classification: dispositions, criteria, fold rules, counts and projection. */
    public static function report(string $html, array $tree): array
    {
        $result = self::classify($html, $tree);
        unset($result['folds'], $result['observations']);
        foreach ($result['nodes'] as &$node) unset($node['identity']);
        unset($node);
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
            // A bare `#` targets the document top, not an element; indexing the
            // empty id would mark every id-less element as referenced.
            if (str_starts_with($href, '#') && '' !== ($target = rawurldecode(substr($href, 1)))) $references[$target] = true;
            foreach (array('aria-controls', 'aria-labelledby', 'aria-describedby', 'aria-owns', 'for') as $attribute) {
                foreach (preg_split('/\s+/', trim($element->getAttribute($attribute)), -1, PREG_SPLIT_NO_EMPTY) as $target) $references[$target] = true;
            }
        }
        foreach ($dom->getElementsByTagName('style') as $style) {
            $references += CssRuleAnalyzer::referencedIds((string) $style->textContent);
        }
        // An element without an id is never referenced.
        unset($references['']);
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
            $blockParent = $effective['nodes'][$blockParents[$id]] ?? null;
            $stateCompounds ??= self::stateCompounds($dom);
            $handoff = self::identityHandoff($element, $node, $effective, $elements, $references, $stateCompounds);
            $identity = is_array($handoff) ? $handoff : array();
            $observed = self::observedViewports($element, $node, $effective);
            $criterion = $observed ? self::criterion($element, $node, $effective, $blockParent, isset($containing[$id]), $referenced, $stateTargeted, isset($identity['entrance']) ? $identity : array()) : 'unobserved-viewport';
            $disposition = 'equal-child-geometry' === $criterion ? 'neutral' : 'load-bearing';
            $nodes[$id] = array('disposition' => $disposition, 'criterion' => $criterion);
            if ('semantics/id/anchor' === $criterion && isset($identity['link'])
                && 'equal-child-geometry' === self::criterion($element, $node, $effective, $blockParent, isset($containing[$id]), $referenced, false, $identity)) {
                $nodes[$id]['link_carrier'] = true;
                $nodes[$id]['identity'] = $identity;
            }
            $semantic = isset($identity['tagName']);
            if ($observed && 'load-bearing' === $disposition && (in_array($criterion, self::FOLDABLE, true) || $semantic)) {
                // A state rule kept on the route can hang on any tag, not only custom elements.
                if (!$stateTargeted) {
                    $stateCompounds ??= self::stateCompounds($dom);
                    $stateTargeted = self::targetedByState($element, self::entranceKeepsIdRules($element, $identity, $stateCompounds));
                }
                $fold = $stateTargeted ? 'state-targeted' : self::fold($element, $node, $effective, $effective['nodes'][$blockParents[$id]] ?? null, isset($containing[$id]), $referenced, isset($identity['link']) ? array() : $identity);
                if (is_array($fold) && (!$semantic || $fold['equal_box'])) {
                    $fold['identity'] = $identity;
                    $child = $node['children'][0];
                    foreach ($effective['nodes'][$child]['viewports'] as $viewport => &$observation) {
                        if (!isset($node['viewports'][$viewport])) continue;
                        foreach ($fold['moves'] as $to => $from) $observation['used_styles'][$to] = $node['viewports'][$viewport]['used_styles'][$from];
                        foreach ($fold['add_padding'] as $to => $from) {
                            $observation['used_styles'][$to] = (self::pixels($observation['used_styles'][$to]) + self::pixels($node['viewports'][$viewport]['used_styles'][$from])) . 'px';
                        }
                        if ($fold['equal_box']) $observation['border_box'] = $node['viewports'][$viewport]['border_box'];
                        if (isset($fold['moves']['position']) && !isset($fold['moves']['align-self']) && 'absolute' === $observation['used_styles']['position']) {
                            $observation['used_styles']['align-self'] = $observation['used_styles']['justify-self'] = 'auto';
                        }
                    }
                    unset($observation);
                    // The child's border box is the box's, so it now contains what the box contained.
                    if (isset($containing[$id])) $containing[$child] = true;
                    if (array() !== $fold['moves'] || array() !== $fold['add_padding']) $received[$child] = true;
                    $folds[$id] = array('child' => $child) + $fold;
                    $disposition = 'folded';
                    $nodes[$id] = array('disposition' => $disposition, 'criterion' => $criterion, 'fold' => $fold['rules']);
                    foreach ($fold['rules'] as $rule) ++$foldCounts[$rule];
                }
            }
            if ('load-bearing' === $disposition && 1 === count($node['children'])) {
                $nodes[$id]['fold_refusal'] = !$observed ? 'unobserved-viewport' : (is_string($handoff) && 'ineligible-identity' !== $handoff && 'missing-handoff-target' !== $handoff ? $handoff
                    : (isset($fold) ? (is_string($fold) ? $fold : 'unequal-box') : $criterion));
            }
            unset($fold);
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

    /** Equality at one width cannot authorize removing a box served at another. */
    private static function observedViewports(DOMElement $element, array $node, array $tree): bool
    {
        $required = array_unique(array_merge(array('desktop', 'mobile'), array_keys($tree['viewports'] ?? array())));
        for ($ancestor = $element; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode) {
            $profile = EngineMarker::deviceScopeValue($ancestor);
            if ('' === $profile) {
                foreach (preg_split('/\s+/', trim($ancestor->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) as $class) {
                    $profile = CaptureEvidence::deviceDocumentProfile($class) ?? '';
                    if ('' !== $profile) break;
                }
            }
            if ('' !== $profile) {
                $required = array($profile);
                break;
            }
        }
        foreach ($required as $viewport) {
            if (!isset($tree['viewports'][$viewport], $node['viewports'][$viewport])) return false;
            foreach ($node['children'] as $child) {
                if (!isset($tree['nodes'][$child]['viewports'][$viewport])) return false;
            }
        }
        return true;
    }

    private static function criterion(DOMElement $element, array $node, array $tree, ?array $blockParent, bool $containing, bool $referenced, bool $stateTargeted = false, array $identity = array()): string
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
        foreach ($node['viewports'] as $observation) {
            // Even an identity transform owns a stacking context and a
            // containing block; folding must carry it, not erase it as neutral.
            if ('none' !== $observation['used_styles']['transform']) return 'containing-block';
        }
        $behavior = self::behavior($element, $node, $tree, $referenced, $stateTargeted, $identity);
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
        if (self::reparentingShrinks($node, $childNode, $blockParent, $tree)) return 'own-sizing';
        return 'equal-child-geometry';
    }

    /**
     * Equal captured boxes do not prove that the child will keep filling the
     * wrapper's space when it becomes a flex item itself.
     *
     * @param array<string, mixed>|null $parent
     */
    private static function reparentingShrinks(array $node, array $child, ?array $parent, array $tree): bool
    {
        if (null === $parent) return false;
        foreach ($node['viewports'] as $viewport => $observation) {
            $b = $observation['used_styles'];
            $c = $child['viewports'][$viewport]['used_styles'] ?? null;
            $p = $parent['viewports'][$viewport]['used_styles'] ?? null;
            if (!is_array($c) || !is_array($p) || !in_array($p['display'] ?? '', array('flex', 'inline-flex'), true)) continue;
            if (!in_array($b['display'] ?? '', array('block', 'flow-root'), true) || in_array($b['position'] ?? 'static', array('absolute', 'fixed'), true)) continue;
            if (str_starts_with($c['display'] ?? '', 'inline') || in_array($c['position'] ?? 'static', array('absolute', 'fixed'), true)) continue;
            if (str_starts_with($p['flex-direction'] ?? 'row', 'row')) {
                // A sole in-flow item that fills the line without growing is
                // sized by its own declaration (`width:100%`), which the
                // collapse would drop; with siblings, or short of the line,
                // its content may well be that wide.
                if (0.0 !== (float) ($b['flex-grow'] ?? '0') || !self::fillsSoleFlexLine($node, $parent, (string) $viewport, $tree)) continue;
                return true;
            }
            $align = 'auto' === ($b['align-self'] ?? 'auto') ? ($p['align-items'] ?? 'normal') : $b['align-self'];
            // Across a column, an item aligned anywhere but stretch fits its content.
            if (in_array(preg_replace('/^(?:safe|unsafe)\s+/', '', (string) $align), array('start', 'end', 'center', 'flex-start', 'flex-end', 'self-start', 'self-end', 'baseline', 'first baseline', 'last baseline', 'anchor-center'), true)) return true;
        }
        return false;
    }

    /** @param array<string, mixed> $parent */
    private static function fillsSoleFlexLine(array $node, array $parent, string $viewport, array $tree): bool
    {
        $inFlow = 0;
        foreach ((array) ($parent['children'] ?? array()) as $id) {
            $styles = $tree['nodes'][$id]['viewports'][$viewport]['used_styles'] ?? null;
            if (!is_array($styles) || 'none' === ($styles['display'] ?? '') || in_array($styles['position'] ?? 'static', array('absolute', 'fixed'), true)) continue;
            $inFlow++;
        }
        if (1 !== $inFlow) return false;
        $p = $parent['viewports'][$viewport] ?? null;
        $box = $node['viewports'][$viewport]['border_box']['width'] ?? null;
        if (!is_array($p) || !is_numeric($box)) return false;
        $line = (float) $p['border_box']['width'];
        foreach (array('padding-left', 'padding-right') as $edge) $line -= (float) (self::pixels((string) ($p['used_styles'][$edge] ?? '0px')) ?? 0.0);
        foreach (array('border-left', 'border-right') as $edge) $line -= (float) (self::borderWidth((string) ($p['used_styles'][$edge] ?? '')) ?? 0.0);
        $margins = (float) (self::pixels((string) ($node['viewports'][$viewport]['used_styles']['margin-left'] ?? '0px')) ?? 0.0) + (float) (self::pixels((string) ($node['viewports'][$viewport]['used_styles']['margin-right'] ?? '0px')) ?? 0.0);
        return abs((float) $box + $margins - $line) <= 0.5;
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
    private static function behavior(DOMElement $element, array $node, array $tree, bool $referenced, bool $stateTargeted, array $identity = array()): ?string
    {
        // Autonomous custom elements have no implicit role: they are generic
        // containers like div/span and fall through to the remaining criteria.
        $generic = in_array(strtolower($element->tagName), array('div', 'span'), true) || self::isAutonomousCustomElement($element) || isset($identity['link']);
        // An entrance fold transfers the id as well as the runtime handle,
        // preserving CSS and anchor references. Target eligibility below still
        // keeps the wrapper when that transfer cannot be made.
        $entranceId = self::carriesOnlyViewportEntrance($element);
        if ((!isset($identity['tagName']) && (!$generic || ($referenced && !$entranceId))) || $element->hasAttribute('role') || $element->hasAttribute('name')) return 'semantics/id/anchor';
        foreach ($element->attributes as $attribute) {
            if (str_starts_with($attribute->name, 'on') || str_starts_with($attribute->name, 'aria-') || in_array($attribute->name, isset($identity['tagName']) ? array('contenteditable', 'draggable', 'hidden', 'inert') : array('tabindex', 'contenteditable', 'draggable', 'hidden', 'inert'), true)) return 'interaction/state';
        }
        if (!empty($node['interaction_states']) || $stateTargeted) return 'interaction/state';
        // A viewport entrance is a runtime handle, not geometry: it can move
        // to a sole text child. Any other
        // capture marker keeps the box.
        $entrance = self::carriesCaptureMarker($element, isset($identity['link']));
        if ($entrance && !isset($identity['entrance'])) return 'capture-marker';
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
    private static function entranceKeepsIdRules(DOMElement $element, array $identity, array $compounds): array
    {
        $id = $element->getAttribute('id');
        if ('' === $id || !isset($identity['entrance'])) return $compounds;
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

    /** Decide the identity conversion may carry, or a report-safe refusal. */
    private static function identityHandoff(DOMElement $element, array $node, array $tree, array $elements, array $references, array $compounds): array|string
    {
        $tag = strtolower($element->tagName);
        if ('a' === $tag && '' !== trim($element->getAttribute('href')) && !isset($references[$element->getAttribute('id')])
            && !$element->hasAttribute('id') && !$element->hasAttribute('role') && !$element->hasAttribute('name')
            && '' === trim($element->textContent) && !self::targetedByOwnState($element, $compounds)) {
            return array('link' => true);
        }
        if (1 !== count($node['children'])) return 'missing-handoff-target';
        $childId = $node['children'][0];
        $child = $elements[$childId] ?? null;
        if (!$child instanceof DOMElement || $child->parentNode !== $element) return 'missing-handoff-target';
        if (self::carriesOnlyViewportEntrance($element)) {
            if (!\Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlCompilation::rendersFoldEntrance($child)) return 'entrance-target-unrendered';
            if ($child->hasAttribute('id')) return 'entrance-target-id';
            if (self::carriesCaptureMarker($child)) return 'entrance-target-marker';
            foreach (CssRuleAnalyzer::declarationList($element->getAttribute('style')) as $declaration) {
                if (!preg_match('/^--motion-/i', $declaration['name'])) return 'entrance-own-style';
            }
            foreach (CssRuleAnalyzer::declarationList($child->getAttribute('style')) as $declaration) {
                if (preg_match('/^--motion-/i', $declaration['name'])) return 'entrance-child-motion';
            }
            $attributes = array(CaptureEvidence::VIEWPORT_ENTRANCE => $element->getAttribute(CaptureEvidence::VIEWPORT_ENTRANCE));
            foreach ($element->attributes as $attribute) {
                if (\Automattic\BlocksEngine\PhpTransformer\Support\SourceAttribute::isFoldRuntimeAttribute($attribute->name)) $attributes[$attribute->name] = $attribute->value;
            }
            if ($element->hasAttribute('id')) $attributes['id'] = $element->getAttribute('id');
            if ('' !== trim($element->getAttribute('style'))) $attributes['style'] = rtrim($child->getAttribute('style'), "; \t\n\r\0\x0B") . ';' . $element->getAttribute('style');
            return array('entrance' => true, 'attributes' => $attributes);
        }
        if (!in_array($tag, array('div', 'section', 'header', 'footer', 'main', 'article', 'aside'), true)
            || ('div' === $tag && !$element->hasAttribute('id')) || 'div' !== strtolower($child->tagName)
            || count($tree['nodes'][$childId]['children'] ?? array()) < 2) return 'ineligible-identity';
        if (isset($references[$child->getAttribute('id')])) return 'referenced-id';
        foreach (array($element, $child) as $candidate) {
            if (self::carriesCaptureMarker($candidate)) return 'capture-marker';
            if (self::targetedByState($candidate, $compounds)) return 'state-targeted';
            foreach ($candidate->attributes as $attribute) {
                if (str_starts_with($attribute->name, 'on') || str_starts_with($attribute->name, 'aria-')
                    || in_array($attribute->name, array('role', 'name', 'contenteditable', 'draggable', 'hidden', 'inert'), true)) return 'interaction/state';
            }
        }
        $attributes = array();
        // Conflicting runtime hooks cannot share a single root.
        foreach ($element->attributes as $attribute) {
            if (\Automattic\BlocksEngine\PhpTransformer\Support\SourceAttribute::isFoldRuntimeAttribute($attribute->name)
                && $child->hasAttribute($attribute->name)
                && $child->getAttribute($attribute->name) !== $attribute->value) return 'hook-conflict:' . $attribute->name;
            if (\Automattic\BlocksEngine\PhpTransformer\Support\SourceAttribute::isFoldRuntimeAttribute($attribute->name)) $attributes[$attribute->name] = $attribute->value;
        }
        return array('tagName' => $tag, 'anchor' => $element->hasAttribute('id') ? $element->getAttribute('id') : null, 'runtime' => $attributes);
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
        ...BoxTree::PAINT_PROPERTIES,
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
     * A positioned box can also be replaced by its equal-box absolute child:
     * the child's fill sizing and placement give way to the box's own.
     * A clip is redundant when that child repeats it without exterior paint,
     * or moves to a non-clipping child that preserves descendant clipping.
     *
     * Never folded: pseudo-elements, non-identity transforms, filters, opacity, blending,
     * isolation, unmatched z-index, other non-flow boxes, and every
     * criterion {@see self::behavior()} reports.
     *
     * @param array<string, mixed>|null $parent The node the child would sit in.
     * @return array{rules: list<string>, moves: array<string, string>, add_padding: array<string, string>, equal_box: bool}|string Stable report-safe refusal code.
     */
    private static function fold(DOMElement $element, array $node, array $tree, ?array $parent, bool $containing, bool $referenced, array $identity = array()): array|string
    {
        if (1 !== count($node['children']) || null === $parent) return 'missing-fold-context';
        $behavior = self::behavior($element, $node, $tree, $referenced, false, $identity);
        if (null !== $behavior && 'viewport-entrance' !== $behavior) return $behavior;
        foreach ($element->childNodes as $text) {
            if ($text instanceof \DOMText && '' !== trim($text->textContent)) return 'mixed-text';
        }
        $child = $tree['nodes'][$node['children'][0]] ?? null;
        if (null === $child || self::reparentingShrinks($node, $child, $parent, $tree)) return 'reparenting-shrinks';
        $sides = array('top' => array('y', 'height', 1), 'right' => array('x', 'width', -1), 'bottom' => array('y', 'height', -1), 'left' => array('x', 'width', 1));
        $source = array_fill_keys(array_keys($sides), null);
        $moves = $rules = $addPadding = array();
        $equalBox = true;
        $hasPadding = false;
        foreach ($node['viewports'] as $observation) {
            foreach (array_keys($sides) as $side) {
                $hasPadding = $hasPadding || 0.0 !== (self::pixels($observation['used_styles']['padding-' . $side]) ?? 0.0);
            }
        }
        $boxless = true;
        // A border-box child measures a moved constraint on its border box.
        $childBorderBox = true;
        $constrained = false;
        $paddingConstraint = true;
        $paints = false;
        $relative = false;
        $absolute = null;
        $absoluteChild = null;
        $clipped = false;
        // Inherited property => [the box owns a value its parent lacks, the child repeats the box's value at every viewport].
        $inherited = array();
        foreach ($node['viewports'] as $viewport => $observation) {
            $b = $observation['used_styles'];
            $c = $child['viewports'][$viewport]['used_styles'] ?? null;
            $p = $parent['viewports'][$viewport]['used_styles'] ?? null;
            if (!is_array($c) || !is_array($p)) return 'missing-observation';
            if (!empty($observation['pseudo'])) return 'pseudo-element';
            $fill = 'absolute' === $b['position'];
            if (null !== $absolute && $absolute !== $fill) return 'inconsistent-position';
            $absolute = $fill;
            $replaces = 'absolute' === $c['position'] && in_array($b['position'], array('relative', 'absolute'), true);
            if (null !== $absoluteChild && $absoluteChild !== $replaces) return 'inconsistent-child-position';
            $absoluteChild = $replaces;
            if ($replaces) {
                foreach (array('top', 'right', 'bottom', 'left') as $offset) {
                    if (!in_array($c[$offset] ?? 'auto', array('auto', '0px'), true)) return 'child-offset:' . $offset;
                }
                if ('auto' !== ($c['z-index'] ?? 'auto') && $c['z-index'] !== ($b['z-index'] ?? 'auto')) return 'child-z-index';
                // With its sole child out of flow, a grid box's explicit
                // tracks alone can size it (`minmax(45px, auto)` rows). The
                // replacing child lays its own children on its own tracks,
                // so the size those tracks produced would be lost.
                if (in_array($b['display'], array('grid', 'inline-grid'), true) && (self::sizedTracks($b['grid-template-rows'] ?? 'none') || self::sizedTracks($b['grid-template-columns'] ?? 'none'))) return 'sized-grid-tracks';
            }
            foreach (self::NEVER_FOLD as $property => $inert) {
                if ('transform' === $property && self::identityTransform($b[$property] ?? 'none')) {
                    if ('none' !== ($b[$property] ?? 'none')) {
                        // Retain the stacking context and fixed-position containing block.
                        if ('none' !== $c[$property] && $b[$property] !== $c[$property]) return 'child-transform';
                        $moves[$property] = $property;
                        $containing = true;
                    }
                    continue;
                }
                if ('z-index' === $property && ($fill || $replaces) && $b[$property] === ($c[$property] ?? null)) continue;
                if (isset($b[$property]) && !in_array(strtolower(trim($b[$property])), $inert, true)) return 'nontransferable:' . $property;
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
                    || ($b['border-radius'] !== $c['border-radius'] && !$roundedClip)) return 'child-clip-conflict';
                $transferClip = $transferClip || $b[$overflow] !== $c[$overflow];
                if ($roundedClip) $moves['border-radius'] = 'border-radius';
                $clipped = true;
            }
            if ($transferClip) {
                // Moving both axes must not erase an independent child clip.
                foreach (array('overflow-x', 'overflow-y') as $overflow) {
                    if ($b[$overflow] !== $c[$overflow] && 'visible' !== $c[$overflow]) return 'child-clip-conflict';
                    $moves[$overflow] = $overflow;
                }
                $moves['border-radius'] = 'border-radius';
                // A static child need not be the containing block of a
                // positioned descendant. Refuse rather than moving its clip
                // below the ancestor that positions that descendant.
                if ('static' === $c['position'] && self::hasPositionedDescendant($child, $tree, $viewport)) return 'positioned-descendant';
            }
            $display = $b['display'];
            $childDisplay = $c['display'];
            if (!in_array($display, array('block', 'flow-root', 'flex', 'grid'), true)) return 'unsupported-display';
            if (str_starts_with($childDisplay, 'inline') || in_array($childDisplay, array('contents', 'none'), true)) return 'unsupported-child-display';
            $parentLays = in_array($p['display'], self::FLEX_OR_GRID, true);
            // A box that establishes its own formatting context keeps the
            // child's margins from collapsing with the child's content; in a
            // block parent the child must establish one itself.
            if (!$fill && !$replaces && 'block' !== $display && !$parentLays && !in_array($childDisplay, array('flex', 'grid', 'flow-root', 'table'), true)) return 'formatting-context';
            if (!in_array($b['position'], array('static', 'relative', 'absolute'), true) || (!$replaces && !in_array($c['position'], array('static', 'relative'), true))) return 'unsupported-position';
            if ($fill && !$replaces) {
                foreach (array('top', 'right', 'bottom', 'left') as $offset) {
                    if ('0px' !== ($b[$offset] ?? null) || !in_array($c[$offset] ?? 'auto', array('auto', '0px'), true)) return 'offset-conflict:' . $offset;
                }
            }
            if ('relative' === $b['position']) {
                $offsets = false;
                foreach (array('top', 'right', 'bottom', 'left') as $offset) $offsets = $offsets || !in_array($b[$offset] ?? 'auto', array('auto', '0px'), true);
                // Two relative offsets would add up on one box.
                if ($offsets && 'relative' === $c['position']) return 'relative-offset-conflict';
                if ('static' === $c['position'] || $replaces) $relative = true;
            }
            $box = $observation['border_box'];
            $inner = $child['viewports'][$viewport]['border_box'];
            if ($hasPadding) {
                foreach (array_keys($sides) as $childSide) {
                    if (0.0 !== self::borderWidth($c['border-' . $childSide])) return 'border';
                    if (null === self::pixels($c['padding-' . $childSide])) return 'unresolved-edge:' . $childSide;
                }
                if (self::boxPaints($c)) return 'child-paint';
                if ('visible' !== $c['overflow-x'] || 'visible' !== $c['overflow-y']) return 'child-border-clip';
                if ('content-box' !== ($c['box-sizing'] ?? null)) {
                    foreach (self::CONSTRAINT_INITIAL + array('width' => array('auto'), 'height' => array('auto')) as $property => $initial) {
                        if (!in_array($c[$property], $initial, true)) return 'constraint-box';
                    }
                }
            }
            foreach ($sides as $side => [$axis, $size, $direction]) {
                $border = self::borderWidth($b['border-' . $side]);
                $padding = self::pixels($b['padding-' . $side]);
                $margin = self::pixels($b['margin-' . $side]);
                $childMargin = self::pixels($c['margin-' . $side]);
                if (null === $padding || null === $margin || null === $childMargin) return 'unresolved-edge:' . $side;
                if ($replaces && 0.0 !== $padding) return 'replacement-padding';
                if (null === $border || 0.0 !== $border) {
                    // A bordered box paints around its child: only a non-painting border of zero width folds.
                    return 'border';
                }
                $edge = 1 === $direction ? $box[$axis] : $box[$axis] + $box[$size];
                $childEdge = 1 === $direction ? $inner[$axis] : $inner[$axis] + $inner[$size];
                if (abs($edge + $direction * $padding - $childEdge) > 0.01) return 'unequal-box';
                $childBorder = self::borderWidth($c['border-' . $side]);
                // Overflow clips at the padding edge, not outside a child's border.
                if ($clipped && 0.0 !== $childBorder) return 'child-border-clip';
                if (0.0 !== $padding || null === $childBorder || 0.0 !== $childBorder || 0.0 !== (float) (self::pixels($c['padding-' . $side]) ?? 1.0)) $boxless = false;
                if (0.0 !== $padding) {
                    if (0.0 !== $childMargin) return 'edge-conflict:' . $side;
                    $addPadding['padding-' . $side] = 'padding-' . $side;
                }
                if ($margin !== $childMargin && !(0.0 === $margin && 'y' === $axis && 'block' === $display && !$parentLays)) {
                    if (0.0 !== $childMargin) return 'edge-conflict:' . $side;
                    $source[$side] = 'margin';
                }
            }
            foreach (self::PLACEMENT_INITIAL as $property => $initial) {
                // Four zero insets determine an absolute fill's placement;
                // the child's former self-alignment is no longer relevant.
                if ($replaces) {
                    $moves[$property] = $property;
                    continue;
                }
                if ($fill && in_array($property, array('align-self', 'justify-self'), true)) continue;
                if ($b[$property] === $c[$property]) continue;
                if ($initial !== $c[$property] && !self::fillsSoleGridCell($b, $property, $c[$property])) return 'child-placement:' . $property;
                $moves[$property] = $property;
            }
            $paddingConstraint = $paddingConstraint && 'content-box' === ($b['box-sizing'] ?? null) && 'content-box' === ($c['box-sizing'] ?? null);
            foreach (array_keys($sides) as $side) {
                $paddingConstraint = $paddingConstraint && 0.0 === self::pixels($c['padding-' . $side]);
            }
            $childBorderBox = $childBorderBox && 'border-box' === ($c['box-sizing'] ?? null);
            foreach (self::CONSTRAINT_INITIAL as $property => $initial) {
                if (in_array($b[$property], $initial, true)) continue;
                if (!$replaces && !in_array($c[$property], $initial, true) && $c[$property] !== $b[$property]) return 'child-own-' . $property;
                $constrained = true;
            }
            if (self::boxPaints($b)) {
                if (self::boxPaints($c) || !in_array($c['border-radius'], array('0px', 'none'), true)) return 'child-paint';
                // A rounded child that clips would start clipping its own content.
                if (!$roundedClip && !in_array($b['border-radius'], array('0px', 'none'), true) && (in_array($c['overflow-x'], array('hidden', 'clip', 'auto', 'scroll'), true) || in_array($c['overflow-y'], array('hidden', 'clip', 'auto', 'scroll'), true))) return 'rounded-child-clip';
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
            if (in_array($property, self::INHERITANCE_BASIS, true)) return 'inheritance-basis:' . $property;
            // Elsewhere a child value that differs at a viewport is its own
            // there; inheriting the box's at some viewports and not others
            // cannot be restated as one move.
            foreach ($node['viewports'] as $viewport => $observation) {
                $b = $observation['used_styles'];
                $c = $child['viewports'][$viewport]['used_styles'];
                $p = $parent['viewports'][$viewport]['used_styles'];
                if ($b[$property] !== ($p[$property] ?? null) && $b[$property] === ($c[$property] ?? null)) return 'inconsistent-inheritance:' . $property;
            }
        }
        // Positioned descendants resolve against the padding box: it must not change.
        if (($containing || $relative || $absolute || $clipped) && !$equalBox) return 'unequal-box';
        if ($paints && !$equalBox) return 'unequal-box';
        // Constraints need matching sizing edges: a shared border box, or
        // content-box sizing with wrapper padding added to an unpadded child.
        if ($constrained && !($equalBox && ($boxless || $childBorderBox || ($hasPadding && $paddingConstraint)))) return 'constraint-box';
        foreach ($source as $side => $from) {
            if (null !== $from) $moves['margin-' . $side] = $from . '-' . $side;
        }
        if (array() !== $addPadding || $constrained) $rules[] = 'own-sizing';
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
        if ($absoluteChild) {
            // The child's absolute declarations described filling the old box,
            // not placement or sizing in its new parent.
            foreach (array('width', 'height', 'min-width', 'min-height', 'max-width', 'max-height', 'z-index') as $property) $moves[$property] = $property;
        }
        if ($absolute) {
            foreach (array('position', 'top', 'right', 'bottom', 'left') as $property) $moves[$property] = $property;
        }
        if ($clipped) $rules[] = 'clip';
        if ($absolute || array_intersect_key($moves, self::PLACEMENT_INITIAL) || in_array('margin', $source, true)) $rules[] = 'own-placement';
        if ('viewport-entrance' === $behavior) {
            // The replayed entrance animates the element that carries it; the
            // child plays it identically only on the box's own border box.
            if (!$equalBox) return 'unequal-box';
            $rules[] = 'viewport-entrance';
        }
        if (array() === $rules) return 'no-fold-rules';
        $moves += $inheritedMoves;
        return array('rules' => $rules, 'moves' => $moves, 'add_padding' => $addPadding, 'equal_box' => $equalBox);
    }

    /** A used track list that occupies space: any track resolves to a positive length. */
    private static function sizedTracks(string $tracks): bool
    {
        preg_match_all('/(?<![\w.-])(\d*\.?\d+)px\b/', $tracks, $lengths);
        foreach ($lengths[1] as $length) {
            if ((float) $length > 0.0) return true;
        }
        return false;
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
     * Selector prefixes of document style rules that apply only in a non-resting
     * state, with state pseudo-classes removed for the resting capture. Each
     * compound keeps its preceding context: subjects and ancestors remain rule
     * handles without targeting unrelated boxes of the same type.
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
                    $resting = '';
                    foreach ($tokens['supported'] ? $tokens['compounds'] : array($selector) as $i => $compound) {
                        $resting .= (0 === $i ? '' : ' ' . $tokens['combinators'][$i - 1] . ' ') . self::restingCompound($compound);
                    }
                    $parsed = CssSelectorMatcher::parse($resting);
                    if ($parsed['supported']) {
                        $hasSubject = false;
                        foreach ($dom->getElementsByTagName('*') as $element) {
                            if (CssSelectorMatcher::matches($element, $parsed)['matches']) {
                                $hasSubject = true;
                                break;
                            }
                        }
                        if (!$hasSubject) continue;
                    }
                    $prefix = '';
                    foreach ($tokens['supported'] ? $tokens['compounds'] : array($selector) as $i => $compound) {
                        $compound = self::restingCompound($compound);
                        // Only negations left (`:not(.x):focus`) names no box:
                        // like `*`, it is whichever element enters the state.
                        $positive = trim((string) preg_replace(self::NEGATION, '', $compound));
                        $compound = '' === $positive || '*' === $positive ? '*' : $compound;
                        $prefix .= (0 === $i ? '' : ' ' . $tokens['combinators'][$i - 1] . ' ') . $compound;
                        $compounds[$prefix] = true;
                    }
                }
            }
        }
        return array_keys($compounds);
    }

    /** Remove transient states; a negated state alone holds at rest. */
    private static function restingCompound(string $compound): string
    {
        $compound = (string) preg_replace(self::STATE_PSEUDO_CLASS, '', $compound);
        $compound = trim((string) preg_replace('/:not\(\s*\)/i', '', $compound));
        return '' === $compound ? '*' : $compound;
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
