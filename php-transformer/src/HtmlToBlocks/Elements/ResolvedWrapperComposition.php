<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatchCache;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceDeclarations;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;
use DOMNode;

/**
 * Proves, for one wrapper chain around its sole source leaf, that folding
 * the chain into the leaf keeps every resolved author declaration.
 *
 * The fold transfers the wrappers' classes (and a single id) onto the leaf.
 * Every author rule whose match the fold changes, for the merged node or any
 * of its descendants, is compared against the source cascade:
 *
 * - a wrapper declaration that is an initial value, or inert on a block-level
 *   wrapper in block flow, drops out;
 * - inherited text properties and custom properties move onto the merged node
 *   when the leaf does not declare them itself;
 * - wrapper padding and paint move onto the leaf when the leaf has no box of
 *   its own (padding, border, margin, sizing or paint);
 * - a lost match is pinned under its original conditions on an engine-owned
 *   carrier for the node it applied to, in the source precedence order;
 * - a gained match must restate values the node already had.
 *
 * The scope is local: only rules whose subjects can match the chain, the leaf
 * or its descendants are considered, before and after the fold. Anything the
 * proof cannot reproduce exactly returns a named failure and the wrapper stays.
 */
final class ResolvedWrapperComposition
{
    public function __construct(private readonly HtmlTransformerSession $session)
    {
    }

    /** @var array<int, list<string>>|null */
    private ?array $conditionsByOrder = null;

    /**
     * Whether box-tree evidence proved every wrapper geometry-neutral at
     * every captured viewport, and the leaf resolves a moved size to the
     * border box it already has.
     */
    private bool $neutralEvidence = false;
    private bool $sizeTransfers = false;

    /**
     * @param list<DOMElement> $wrappers The single-child chain above `$leaf`, outermost first.
     * @param string $wrapperClasses The wrapper classes the merged node carries.
     * @param bool|null $sizeTransfers Null without box-tree evidence. Otherwise every wrapper is proven
     *        neutral, and whether the leaf resolves a moved size declaration to its own border box.
     * @return array{failure: string}|array{pins: list<array{node: DOMElement, rules: list<array{conditions: list<string>, declarations: array<string, string>}>}>}
     */
    public function prove(array $wrappers, DOMElement $leaf, string $transferredId, string $wrapperClasses, ?bool $sizeTransfers = null): array
    {
        $this->neutralEvidence = null !== $sizeTransfers;
        $this->sizeTransfers = true === $sizeTransfers;
        $outer = $wrappers[0] ?? null;
        if (! $outer instanceof DOMElement || ! $outer->parentNode instanceof DOMElement) return self::failure('no_wrapper_parent');
        for ($node = $leaf->parentNode, $index = count($wrappers) - 1; $index >= 0; --$index, $node = $node?->parentNode) {
            if ($node !== $wrappers[$index]) return self::failure('not_a_wrapper_chain');
        }

        // Read source matches independently of caches populated during lowering.
        $mainCache = new CssSelectorMatchCache();
        $nodes = array_merge(array($leaf), SourceDom::descendantElements($leaf));
        $wrapperRules = array();
        foreach ($wrappers as $index => $wrapper) {
            $wrapperRules[$index] = $this->matchedRules($wrapper, $mainCache);
        }
        $before = array();
        foreach ($nodes as $index => $node) {
            $before[$index] = $this->matchedRules($node, $mainCache);
        }
        $after = $this->matchedRulesAfterFold($wrappers, $leaf, $nodes, $transferredId, $wrapperClasses);
        if (null === $after) return self::failure('fold_not_simulated');

        $pins = array();
        $leafFailure = $this->composeLeaf($wrappers, $wrapperRules, $leaf, $before[0], $after[0], $pins);
        if (null !== $leafFailure) return self::failure($leafFailure);
        foreach ($nodes as $index => $node) {
            if (0 === $index) continue;
            $failure = $this->composeDescendant($node, $before[$index], $after[$index], $pins);
            if (null !== $failure) return self::failure($failure);
        }

        $result = array();
        foreach ($pins as $entry) {
            if (array() !== $entry['rules']) $result[] = $entry;
        }
        return array('pins' => $result);
    }

    /** @return array{failure: string} */
    private static function failure(string $reason): array
    {
        return array('failure' => $reason);
    }

    /**
     * @param list<DOMElement> $wrappers
     * @param array<int, array<string, array<string, mixed>>> $wrapperRules
     * @param array<string, array<string, mixed>> $before
     * @param array<string, array<string, mixed>> $after
     * @param array<string, array{node: DOMElement, rules: list<array{conditions: list<string>, declarations: array<string, string>}>}> $pins
     */
    private function composeLeaf(array $wrappers, array $wrapperRules, DOMElement $leaf, array $before, array $after, array &$pins): ?string
    {
        $leafFamilies = $this->leafFamilies($leaf, $before);
        $leafDisplay = '';
        foreach ($before as $rule) {
            if (isset($rule['declarations']['display'])) $leafDisplay = CssValueInspector::comparable((string) $rule['declarations']['display']);
        }
        $leafInline = SourceDeclarations::inline($leaf);
        if (isset($leafInline['display'])) $leafDisplay = CssValueInspector::comparable($leafInline['display']);
        $leafFlexContainer = in_array($leafDisplay, array('flex', 'inline-flex', 'grid', 'inline-grid'), true);
        $leafDefines = array();
        foreach ($before as $rule) {
            foreach (array_keys($rule['declarations']) as $property) {
                if (str_starts_with((string) $property, '--')) $leafDefines[(string) $property] = true;
            }
        }

        // Classify every wrapper declaration that reaches the merged node,
        // through a kept match, a lost match or the wrapper's inline style.
        $wrapperPins = array();
        $familyOwners = array();
        $movesPadding = false;
        $movesPaint = false;
        $fullWidth = false;
        foreach ($wrappers as $index => $wrapper) {
            $entries = $wrapperRules[$index];
            $inline = SourceDeclarations::inline($wrapper);
            if (array() !== $inline) {
                $entries['inline:' . $index] = array('declarations' => $inline, 'conditions' => array(), 'state' => false, 'pseudo' => false, 'precedence' => array(0, PHP_INT_MAX, PHP_INT_MAX), 'inline' => true);
            }
            foreach ($entries as $key => $rule) {
                // A rule that already applied to the leaf keeps applying.
                if (isset($before[$key])) continue;
                // Its generated box would move inside the merged node.
                if ($rule['pseudo'] && array() !== $rule['declarations']) return 'wrapper_pseudo_element';
                $kept = isset($after[$key]);
                $retained = array();
                foreach ($rule['declarations'] as $property => $value) {
                    $property = (string) $property;
                    $kind = $this->wrapperDeclarationKind($property, (string) $value, $leafFlexContainer);
                    $family = self::family($property);
                    // Evidence proves the wrapper's used value inert; the
                    // authored one must not land on the leaf.
                    if ('drop-if-lost' === $kind) $kind = $kept ? 'refuse' : 'drop';
                    if ('refuse' === $kind) return 'wrapper_declaration_not_composable:' . $property;
                    if ('drop' === $kind) {
                        // An initial value still competes once it lands on the leaf.
                        if ($kept && isset($leafFamilies[$family])) return 'wrapper_reset_overrides_leaf:' . $property;
                        // Auto inline margins centre a leaf that has a size of its own.
                        if ($kept && 'margin' === $family && str_contains(CssValueInspector::comparable((string) $value), 'auto') && isset($leafFamilies['size'])) return 'wrapper_auto_margin_centres_leaf';
                        continue;
                    }
                    if ('full-width' === $kind) {
                        if ($kept && isset($leafFamilies[$family])) return 'wrapper_reset_overrides_leaf:' . $property;
                        $fullWidth = true;
                        continue;
                    }
                    if (isset($leafFamilies[$family])) return 'leaf_declares_wrapper_property:' . $property;
                    if (preg_match_all('/var\(\s*(--[A-Za-z0-9_-]+)/', (string) $value, $matches)) {
                        foreach ($matches[1] as $name) {
                            if (isset($leafDefines[$name])) return 'wrapper_variable_rebinds_on_leaf:' . $property;
                        }
                    }
                    if (isset($familyOwners[$family]) && $familyOwners[$family] !== $index) return 'nested_wrappers_share_property:' . $property;
                    $familyOwners[$family] = $index;
                    if ('padding' === $kind) $movesPadding = true;
                    if ('paint' === $kind) $movesPaint = true;
                    if ('padding' === $kind && preg_match('/[0-9.](?:em|ex|ch|lh)\b/i', (string) $value) && isset($leafFamilies['font'])) return 'wrapper_padding_depends_on_leaf_font';
                    $retained[$property] = (string) $value;
                }
                if ($kept || array() === $retained) continue;
                // Pinning resting declarations cannot reproduce a state.
                if ($rule['state']) return 'lost_state_rule';
                if ($rule['deviceScoped'] ?? false) return 'lost_device_scoped_wrapper_rule';
                $wrapperPins[] = array('rule' => $rule, 'declarations' => $retained, 'wrapper' => $index);
            }
        }
        if ($fullWidth && $movesPadding) return 'full_width_wrapper_with_padding';
        if ($fullWidth && isset($leafFamilies['size'])) return 'full_width_wrapper_around_sized_leaf';
        if ($movesPadding) {
            foreach (array('padding', 'border', 'margin', 'size', 'position', 'background') as $family) {
                if (isset($leafFamilies[$family])) return 'leaf_box_blocks_padding_transfer:' . $family;
            }
        }
        if ($movesPaint && (isset($leafFamilies['margin']) || isset($leafFamilies['border']))) return 'leaf_box_blocks_paint_transfer';
        // The leaf's percentage heights resolved against an auto-height wrapper.
        foreach (array_merge($before, array(array('declarations' => $leafInline))) as $rule) {
            foreach ($rule['declarations'] as $property => $value) {
                if (in_array($property, array('height', 'min-height', 'max-height'), true) && str_contains((string) $value, '%')) return 'leaf_percentage_height';
            }
        }

        // A wrapper pin must outrank every kept declaration of its own box.
        foreach ($wrapperPins as $pin) {
            foreach (array_keys($pin['declarations']) as $property) {
                foreach ($wrapperRules[$pin['wrapper']] as $key => $other) {
                    if (! isset($after[$key]) || $other['order'] === ($pin['rule']['order'] ?? null)) continue;
                    if ($this->outranks($other, $pin['rule'], $property)) return 'lost_wrapper_rule_outranked:' . $property;
                }
            }
            $this->addPin($pins, $leaf, $pin['rule'], $pin['declarations']);
        }

        foreach ($after as $key => $rule) {
            if (isset($before[$key]) || $this->isWrapperRule($wrapperRules, $key)) continue;
            if (! $this->restatesExistingValues($leaf, $rule, $before)) return 'gained_author_match';
        }
        foreach ($before as $key => $rule) {
            if (isset($after[$key])) continue;
            $failure = $this->pinLost($leaf, $rule, $before, $after, $pins);
            if (null !== $failure) return $failure;
        }
        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $before
     * @param array<string, array<string, mixed>> $after
     * @param array<string, array{node: DOMElement, rules: list<array{conditions: list<string>, declarations: array<string, string>}>}> $pins
     */
    private function composeDescendant(DOMElement $node, array $before, array $after, array &$pins): ?string
    {
        foreach ($after as $key => $rule) {
            if (isset($before[$key])) continue;
            if (! $this->restatesExistingValues($node, $rule, $before)) return 'gained_descendant_author_match';
        }
        foreach ($before as $key => $rule) {
            if (isset($after[$key])) continue;
            $failure = $this->pinLost($node, $rule, $before, $after, $pins);
            if (null !== $failure) return $failure;
        }
        return null;
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, array<string, mixed>> $before
     * @param array<string, array<string, mixed>> $after
     * @param array<string, array{node: DOMElement, rules: list<array{conditions: list<string>, declarations: array<string, string>}>}> $pins
     */
    private function pinLost(DOMElement $node, array $rule, array $before, array $after, array &$pins): ?string
    {
        if (array() === $rule['declarations']) return null;
        if ($rule['state']) return 'lost_state_rule';
        if ($rule['deviceScoped'] ?? false) return 'lost_device_scoped_rule';
        $inline = SourceDeclarations::inline($node);
        foreach (array_keys($rule['declarations']) as $property) {
            $family = self::family((string) $property);
            foreach (array_keys($inline) as $inlineProperty) {
                if (self::family((string) $inlineProperty) === $family && ! self::important((string) $rule['declarations'][$property])) return 'lost_rule_under_inline_style:' . $property;
            }
            foreach ($after as $key => $other) {
                if (! isset($before[$key])) continue;
                if ($this->outranks($other, $rule, (string) $property)) return 'lost_rule_outranked:' . $property;
            }
        }
        $this->addPin($pins, $node, $rule, $rule['declarations']);
        return null;
    }

    /**
     * Whether `$other` sets `$property`'s family and wins over `$rule`'s
     * declaration of it, so a pinned copy would invert the cascade. A state
     * rule may win in its state, which a resting pin cannot reproduce.
     *
     * @param array<string, mixed> $other
     * @param array<string, mixed> $rule
     */
    private function outranks(array $other, array $rule, string $property): bool
    {
        $family = self::family($property);
        $important = self::important((string) ($rule['declarations'][$property] ?? ''));
        foreach ($other['declarations'] as $otherProperty => $value) {
            if (self::family((string) $otherProperty) !== $family) continue;
            $otherImportant = self::important((string) $value);
            if ($otherImportant !== $important) {
                if ($otherImportant) return true;
                continue;
            }
            if ($other['state'] || $other['precedence'] > $rule['precedence']) return true;
        }
        return false;
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, array<string, mixed>> $before
     */
    private function restatesExistingValues(DOMElement $node, array $rule, array $before): bool
    {
        if (array() === $rule['declarations']) return true;
        if ($rule['state'] || array() !== $rule['conditions']) return false;
        $resolved = SourceDeclarations::inline($node);
        $ordered = array_values($before);
        usort($ordered, static fn (array $a, array $b): int => $a['precedence'] <=> $b['precedence']);
        $values = array();
        foreach ($ordered as $existing) {
            if (array() !== $existing['conditions'] || $existing['state']) continue;
            foreach ($existing['declarations'] as $property => $value) $values[(string) $property] = CssValueInspector::comparable((string) $value);
        }
        foreach ($resolved as $property => $value) $values[(string) $property] = CssValueInspector::comparable($value);
        foreach ($rule['declarations'] as $property => $value) {
            if (($values[(string) $property] ?? null) !== CssValueInspector::comparable((string) $value)) return false;
        }
        return true;
    }

    /** @param array<int, array<string, array<string, mixed>>> $wrapperRules */
    private function isWrapperRule(array $wrapperRules, string $key): bool
    {
        foreach ($wrapperRules as $rules) {
            if (isset($rules[$key])) return true;
        }
        return false;
    }

    /**
     * @param array<string, array{node: DOMElement, rules: list<array{conditions: list<string>, declarations: array<string, string>, precedence?: array<int, int>}>}> $pins
     * @param array<string, mixed> $rule
     * @param array<string, string> $declarations
     */
    private function addPin(array &$pins, DOMElement $node, array $rule, array $declarations): void
    {
        $key = (string) $node->getNodePath();
        $pins[$key] ??= array('node' => $node, 'rules' => array());
        $pins[$key]['rules'][] = array('conditions' => $rule['conditions'], 'declarations' => $declarations, 'precedence' => $rule['precedence']);
        usort($pins[$key]['rules'], static fn (array $a, array $b): int => ($a['precedence'] ?? array()) <=> ($b['precedence'] ?? array()));
    }

    /**
     * Families the leaf already owns with a non-initial value: its own author
     * rules, inline style and the user-agent defaults of its tag. A family
     * whose every declaration is an initial value stays unowned.
     *
     * @param array<string, array<string, mixed>> $before
     * @return array<string, true>
     */
    private function leafFamilies(DOMElement $leaf, array $before): array
    {
        // The leaf's own box is read on authored values alone.
        $neutralEvidence = $this->neutralEvidence;
        $this->neutralEvidence = false;
        try {
            return $this->authoredLeafFamilies($leaf, $before);
        } finally {
            $this->neutralEvidence = $neutralEvidence;
        }
    }

    /**
     * @param array<string, array<string, mixed>> $before
     * @return array<string, true>
     */
    private function authoredLeafFamilies(DOMElement $leaf, array $before): array
    {
        $families = array();
        $zeroed = array();
        $declarations = array();
        foreach ($before as $rule) {
            foreach ($rule['declarations'] as $property => $value) $declarations[] = array((string) $property, (string) $value, array() === $rule['conditions'] && ! $rule['state']);
        }
        foreach (SourceDeclarations::inline($leaf) as $property => $value) $declarations[] = array((string) $property, (string) $value, true);
        foreach ($declarations as [$property, $value, $resting]) {
            $family = self::family($property);
            if ('drop' !== $this->wrapperDeclarationKind($property, $value, false) || self::isInheritedProperty($property) || str_starts_with($property, '--')) {
                $families[$family] = true;
                continue;
            }
            if ($resting && self::allZero(CssValueInspector::comparable($value))) {
                foreach (self::sides($property) as $side) $zeroed[$side] = true;
            }
        }
        // User-agent boxes count unless an unconditional reset zeroes them.
        $tag = strtolower($leaf->tagName);
        $uaSides = array();
        if (in_array($tag, array('p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'figure', 'blockquote'), true)) $uaSides = array('margin-top', 'margin-bottom');
        if (in_array($tag, array('figure', 'blockquote'), true)) $uaSides = array('margin-top', 'margin-right', 'margin-bottom', 'margin-left');
        if (in_array($tag, array('ul', 'ol'), true)) $uaSides[] = 'padding-left';
        foreach ($uaSides as $side) {
            if (! isset($zeroed[$side])) $families[self::family($side)] = true;
        }
        if (preg_match('/^h[1-6]$/', $tag)) $families['font'] = true;
        if (in_array($tag, array('img', 'svg', 'video', 'picture', 'iframe'), true)) $families['size'] = true;
        return $families;
    }

    /** @return list<string> */
    private static function sides(string $property): array
    {
        if (preg_match('/^(margin|padding)$/', $property, $match)) return array($match[1] . '-top', $match[1] . '-right', $match[1] . '-bottom', $match[1] . '-left');
        if (preg_match('/^(margin|padding)-block$/', $property, $match)) return array($match[1] . '-top', $match[1] . '-bottom');
        if (preg_match('/^(margin|padding)-inline$/', $property, $match)) return array($match[1] . '-left', $match[1] . '-right');
        if (preg_match('/^(margin|padding)-block-(start|end)$/', $property, $match)) return array($match[1] . '-' . ('start' === $match[2] ? 'top' : 'bottom'));
        if (preg_match('/^(margin|padding)-inline-(start|end)$/', $property, $match)) return array($match[1] . '-' . ('start' === $match[2] ? 'left' : 'right'));
        return array($property);
    }

    /**
     * What happens to one wrapper declaration once the wrapper is gone:
     * `drop` (initial or inert), `full-width` (equal to auto for an unpadded
     * block), `inherit`, `padding`, `paint`, or `refuse`.
     */
    private function wrapperDeclarationKind(string $property, string $value, bool $leafFlexContainer): string
    {
        $value = CssValueInspector::comparable($value);
        if (str_starts_with($property, '--')) return 'inherit';
        if (self::isInheritedProperty($property)) return 'inherit';
        if (in_array($value, array('initial', 'unset'), true)) return 'drop';
        if (preg_match('/^padding(?:-(?:top|right|bottom|left))?$/', $property)) {
            if (self::allZero($value)) return 'drop';
            return str_contains($value, 'var(') ? 'refuse' : 'padding';
        }
        if (preg_match('/^margin(?:-(?:top|right|bottom|left))?$/', $property) && $this->neutralEvidence) {
            if (self::allZero($value)) return 'drop';
            // A neutral wrapper's margins place the leaf exactly where they
            // placed the wrapper, auto inline margins included.
            return in_array($property, array('margin-top', 'margin-bottom'), true) && str_contains($value, 'auto') || str_contains($value, 'var(') ? 'refuse' : 'retain';
        }
        if (preg_match('/^margin(?:-(?:top|right|bottom|left))?$/', $property)) {
            // Auto inline margins of an auto-width block resolve to zero. The
            // wrapper has no width of its own when its sizes drop out.
            foreach (preg_split('/\s+/', $value) ?: array() as $part) {
                if ('auto' !== $part && ! self::allZero($part)) return 'refuse';
            }
            return in_array($property, array('margin-top', 'margin-bottom'), true) && str_contains($value, 'auto') ? 'refuse' : 'drop';
        }
        if (str_starts_with($property, 'background')) {
            return in_array($value, array('none', 'transparent', '0 0', 'none 0 0', 'rgba(0,0,0,0)', 'rgba(0, 0, 0, 0)'), true) ? 'drop' : 'paint';
        }
        if (preg_match('/^border(?:-(?:top|right|bottom|left))?(?:-(?:width|style))?$/', $property)) {
            return in_array($value, array('0', '0px', 'none', '0 none', 'none 0', 'medium none'), true) ? 'drop' : 'refuse';
        }
        if (preg_match('/^border(?:-(?:top|right|bottom|left))?-color$/', $property)) return 'drop';
        // A neutral wrapper's size moves onto a leaf that resolves it to the
        // border box both already share: the leaf takes the wrapper's place,
        // so the size resolves against the same containing block.
        $sized = $this->neutralEvidence && $this->sizeTransfers && ! str_contains($value, 'var(') ? 'retain' : 'refuse';
        if (in_array($property, array('width', 'inline-size'), true)) {
            if ('auto' === $value) return 'drop';
            return '100%' === $value ? 'full-width' : $sized;
        }
        if (in_array($property, array('max-width', 'max-height', 'max-inline-size', 'max-block-size'), true)) {
            if ('none' === $value) return 'drop';
            return preg_match('/^(\d+)px$/', $value, $match) && (int) $match[1] >= 10000 ? 'drop' : $sized;
        }
        if (in_array($property, array('min-width', 'min-height', 'min-inline-size', 'min-block-size'), true)) return in_array($value, array('0', '0px', 'auto'), true) ? 'drop' : $sized;
        if ('height' === $property || 'block-size' === $property) return 'auto' === $value ? 'drop' : $sized;
        // The wrapper's parent lays it out in block flow (structural layout
        // children never reach this proof), where item properties are inert.
        if (preg_match('/^(?:flex|flex-grow|flex-shrink|flex-basis|order|align-self|justify-self|grid-column|grid-row|grid-area|grid-column-start|grid-column-end|grid-row-start|grid-row-end|place-self)$/', $property)) return 'drop';
        // Container properties of a block wrapper are inert, but they land on
        // the merged node through its classes.
        if (preg_match('/^(?:flex-direction|flex-wrap|flex-flow|justify-content|justify-items|align-items|align-content|place-items|place-content|gap|row-gap|column-gap|grid-template-columns|grid-template-rows|grid-template-areas|grid-template|grid-auto-flow|grid-auto-rows|grid-auto-columns)$/', $property)) {
            return $leafFlexContainer ? 'refuse' : 'drop';
        }
        return match ($property) {
            'display' => 'block' === $value ? 'drop' : 'refuse',
            // Evidence proves a relative wrapper's insets auto and that no
            // out-of-flow descendant uses it as its containing block.
            'position' => 'static' === $value || ($this->neutralEvidence && 'relative' === $value) ? 'drop' : 'refuse',
            'float' => 'none' === $value ? 'drop' : ($this->neutralEvidence ? 'drop-if-lost' : 'refuse'),
            'clear' => 'none' === $value ? 'drop' : 'refuse',
            'vertical-align' => 'baseline' === $value ? 'drop' : 'refuse',
            'overflow', 'overflow-x', 'overflow-y' => 'visible' === $value ? 'drop' : 'refuse',
            'opacity' => in_array($value, array('1', '100%'), true) ? 'drop' : 'refuse',
            'transform', 'filter', 'backdrop-filter', 'box-shadow', 'outline', 'clip-path', 'mask' => in_array($value, array('none', '0'), true) ? 'drop' : 'refuse',
            // Offsets and stacking only act on a positioned wrapper, and any
            // non-static position already refuses.
            'z-index', 'top', 'right', 'bottom', 'left', 'inset' => 'drop',
            'box-sizing' => 'drop',
            'outline-offset', 'transition', 'transition-property', 'transition-duration', 'transition-timing-function', 'transition-delay' => 'drop',
            'border-radius' => self::allZero($value) ? 'drop' : 'refuse',
            'whitespace' => 'drop',
            default => 'refuse',
        };
    }

    private static function allZero(string $value): bool
    {
        foreach (preg_split('/\s+/', trim($value)) ?: array() as $part) {
            if (! preg_match('/^-?0(?:\.0+)?(?:px|em|rem|%|vh|vw)?$/', $part)) return false;
        }
        return '' !== trim($value);
    }

    private static function isInheritedProperty(string $property): bool
    {
        return str_starts_with($property, 'font-') || in_array($property, array('font', 'white-space', 'text-wrap', 'text-wrap-mode', 'text-wrap-style', 'white-space-collapse', 'color', 'line-height', 'letter-spacing', 'word-spacing', 'text-align', 'text-transform', 'text-indent', 'visibility', 'cursor', 'direction', 'hyphens', 'word-break', 'overflow-wrap', 'word-wrap', 'text-rendering', '-webkit-font-smoothing', '-moz-osx-font-smoothing', 'list-style', 'list-style-type', 'list-style-position'), true);
    }

    private static function important(string $value): bool
    {
        return (bool) preg_match('/!\s*important\s*$/i', $value);
    }

    /** The cascade family a property competes in, merging shorthands with their longhands. */
    private static function family(string $property): string
    {
        $property = strtolower($property);
        if (str_starts_with($property, '--')) return $property;
        foreach (array('padding', 'margin', 'border', 'background', 'font', 'grid', 'flex', 'overflow', 'inset', 'transition', 'animation', 'outline', 'text-decoration', 'list-style') as $prefix) {
            if ($property === $prefix || str_starts_with($property, $prefix . '-')) return $prefix;
        }
        if (in_array($property, array('top', 'right', 'bottom', 'left'), true)) return 'inset';
        if (in_array($property, array('width', 'height', 'min-width', 'min-height', 'max-width', 'max-height', 'inline-size', 'block-size', 'aspect-ratio'), true)) return 'size';
        if (in_array($property, array('white-space', 'white-space-collapse', 'text-wrap', 'text-wrap-mode', 'text-wrap-style'), true)) return 'white-space';
        if (in_array($property, array('gap', 'row-gap', 'column-gap'), true)) return 'gap';
        if (in_array($property, array('position', 'z-index'), true)) return $property;
        return $property;
    }

    /**
     * Author rules matching `$element`, keyed by rule order (and state for a
     * state-suffixed selector), each with its conditions and precedence.
     *
     * @return array<string, array<string, mixed>>
     */
    private function matchedRules(DOMElement $element, CssSelectorMatchCache $cache): array
    {
        $matched = array();
        foreach ($this->session->authorStyleRuleCandidates($element, $cache) as $selector) {
            if (! ($selector['parsed']['supported'] ?? false)) continue;
            if (! $cache->matches($element, $selector['selector'], $selector['parsed'], true)['matches']) continue;
            // A pseudo-element box is not the element's own box: like a state,
            // a resting composition cannot reproduce it.
            $pseudo = 1 === preg_match('/::|:(?:before|after|first-line|first-letter)\b/i', (string) $selector['selector']);
            $state = $pseudo || null !== ($selector['parsed']['pseudo_state_suffix_span'] ?? null);
            $key = 'rule:' . $selector['rule_order'] . ($state ? ':state:' . $selector['selector'] : '');
            $counts = CssSelectorMatcher::specificityCounts($selector['parsed']);
            $precedence = array(0, 1000000 * $counts['ids'] + 1000 * $counts['classes'] + $counts['types'], (int) $selector['rule_order']);
            if (isset($matched[$key]) && $matched[$key]['precedence'] >= $precedence) continue;
            $matched[$key] = array(
                'order' => (int) $selector['rule_order'],
                'declarations' => $selector['declarations'],
                'conditions' => $this->conditions((int) $selector['rule_order']),
                'state' => $state,
                'pseudo' => $pseudo,
                // Selector device gates cannot be replaced by a bare carrier.
                'deviceScoped' => str_contains((string) $selector['selector'], 'capture-device') || str_contains((string) $selector['selector'], 'data-be-d'),
                'precedence' => $precedence,
            );
        }
        return $matched;
    }

    /** @return list<string> */
    private function conditions(int $order): array
    {
        if (null === $this->conditionsByOrder) {
            $this->conditionsByOrder = array();
            foreach ($this->session->authorStyleAnalysis()->styleRules() as $rule) {
                $this->conditionsByOrder[(int) $rule['order']] = array_values(array_map('strval', $rule['conditions'] ?? array()));
            }
        }
        return $this->conditionsByOrder[$order] ?? array();
    }

    /**
     * Temporarily fold the chain in the source DOM, read the merged node's
     * and descendants' matches with a private selector cache, then restore.
     *
     * @param list<DOMElement> $wrappers
     * @param list<DOMElement> $nodes
     * @return array<int, array<string, array<string, mixed>>>|null
     */
    private function matchedRulesAfterFold(array $wrappers, DOMElement $leaf, array $nodes, string $transferredId, string $wrapperClasses): ?array
    {
        $outer = $wrappers[0];
        $parent = $outer->parentNode;
        $leafParent = $leaf->parentNode;
        if (! $parent instanceof DOMNode || ! $leafParent instanceof DOMNode) return null;
        $leafNext = $leaf->nextSibling;
        $hadClass = $leaf->hasAttribute('class');
        $leafClass = $leaf->getAttribute('class');
        $hadId = $leaf->hasAttribute('id');
        $merged = SourceDom::mergeClassNames($wrapperClasses, $leafClass);

        $parent->insertBefore($leaf, $outer);
        $parent->removeChild($outer);
        if ('' !== $merged) $leaf->setAttribute('class', $merged);
        if ('' !== $transferredId && ! $hadId) $leaf->setAttribute('id', $transferredId);

        $cache = new CssSelectorMatchCache();
        $after = array();
        try {
            foreach ($nodes as $index => $node) {
                $after[$index] = $this->matchedRules($node, $cache);
            }
        } finally {
            $parent->insertBefore($outer, $leaf);
            $leafParent->insertBefore($leaf, $leafNext);
            if ($hadClass) {
                $leaf->setAttribute('class', $leafClass);
            } else {
                $leaf->removeAttribute('class');
            }
            if (! $hadId) $leaf->removeAttribute('id');
        }
        return $after;
    }
}
