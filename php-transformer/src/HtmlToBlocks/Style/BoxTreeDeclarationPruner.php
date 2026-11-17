<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;

/**
 * Reduce one observed used-style map to the declarations that change
 * rendering relative to the cascade an engine carrier lands in.
 *
 * Evidence reports every captured longhand, including CSS initial values and
 * properties that cannot apply to the box. Re-declaring them grows the engine
 * sheet without changing rendering, so a longhand is kept only when:
 *
 * - it is an inherited property whose value differs from the observed parent;
 * - it applies to the box (container longhands need a flex or grid box, item
 *   longhands a flex or grid parent, offsets a positioned box, and so on);
 * - its value is not the CSS initial value, or WordPress or user-agent base
 *   styles may set the property otherwise on the block that carries it.
 *
 * A `display:none` box keeps its declarations: interaction (disclosures,
 * dialogs, dropdowns) can reveal it, and the capture is then its only style.
 */
final class BoxTreeDeclarationPruner
{
    public const KEPT = 'kept';
    /** Pruned because the box inherits the same value from its parent. */
    public const INHERITED = 'inherited';
    /** Pruned because the value equals the initial value no base style overrides. */
    public const INITIAL = 'initial';
    /** Pruned because the property does not affect this box. */
    public const NOT_APPLICABLE = 'not-applicable';

    /**
     * CSS initial values, as computed-style serializations, for longhands that
     * WordPress core and user-agent base styles do not set on block wrappers
     * (or set only in the contexts protected below). Properties missing here
     * are always kept: display, box model, sizing and inherited typography are
     * set by user-agent or WordPress base styles on too many blocks.
     */
    private const INITIAL_VALUES = array(
        'position' => array('static'),
        'box-sizing' => array('content-box'),
        'overflow-x' => array('visible'),
        'overflow-y' => array('visible'),
        'row-gap' => array('normal'),
        'column-gap' => array('normal'),
        'flex-direction' => array('row'),
        'flex-wrap' => array('nowrap'),
        'justify-content' => array('normal'),
        'align-items' => array('normal'),
        'align-content' => array('normal'),
        'flex-grow' => array('0'),
        'flex-shrink' => array('1'),
        'flex-basis' => array('auto'),
        'align-self' => array('auto'),
        'order' => array('0'),
        'grid-template-columns' => array('none'),
        'grid-template-rows' => array('none'),
        'grid-auto-columns' => array('auto'),
        'grid-auto-rows' => array('auto'),
        'grid-auto-flow' => array('row'),
        'grid-column-start' => array('auto'),
        'grid-column-end' => array('auto'),
        'grid-row-start' => array('auto'),
        'grid-row-end' => array('auto'),
        'justify-items' => array('normal', 'legacy'),
        'justify-self' => array('auto'),
        'background-color' => array('rgba(0, 0, 0, 0)', 'transparent'),
        'background-image' => array('none'),
        'border-radius' => array('0px'),
        'box-shadow' => array('none'),
        'mask-image' => array('none'),
        'mask-mode' => array('match-source'),
        'filter' => array('none'),
        'opacity' => array('1'),
        'mix-blend-mode' => array('normal'),
        'transform' => array('none'),
        'z-index' => array('auto'),
        'max-height' => array('none'),
        'text-decoration-line' => array('none'),
        'text-decoration-style' => array('solid'),
        'text-overflow' => array('clip'),
        'vertical-align' => array('baseline'),
        'top' => array('auto'),
        'right' => array('auto'),
        'bottom' => array('auto'),
        'left' => array('auto'),
        'float' => array('none'),
        'clear' => array('none'),
        'aspect-ratio' => array('auto'),
        'object-fit' => array('fill'),
        'object-position' => array('50% 50%'),
        'clip-path' => array('none'),
        'isolation' => array('auto'),
        'backdrop-filter' => array('none'),
        'background-position-x' => array('0%'),
        'background-position-y' => array('0%'),
        'background-size' => array('auto'),
        'background-repeat' => array('repeat'),
        'background-clip' => array('border-box'),
        'background-origin' => array('padding-box'),
        'background-attachment' => array('scroll'),
        'background-blend-mode' => array('normal'),
        'animation-name' => array('none'),
        'transition-property' => array('all'),
        'transition-duration' => array('0s'),
    );

    /** Inherited in CSS but outside the producer's inherited typography list. */
    private const EXTRA_INHERITED = array('pointer-events');

    private const FLEX_CONTAINER = array('flex-direction', 'flex-wrap');
    private const GRID_CONTAINER = array('grid-template-columns', 'grid-template-rows', 'grid-auto-columns', 'grid-auto-rows', 'grid-auto-flow', 'justify-items');
    private const BOX_ALIGNMENT_CONTAINER = array('row-gap', 'column-gap', 'justify-content', 'align-items', 'align-content');
    private const FLEX_ITEM = array('flex-grow', 'flex-shrink', 'flex-basis');
    private const LAYOUT_ITEM = array('order', 'align-self', 'grid-column-start', 'grid-column-end', 'grid-row-start', 'grid-row-end', 'justify-self');
    private const MIN_SIZE = array('min-width', 'min-height');
    private const OFFSETS = array('top', 'right', 'bottom', 'left');
    private const BACKGROUND_LAYER = array('background-position-x', 'background-position-y', 'background-size', 'background-repeat', 'background-origin', 'background-attachment', 'background-blend-mode');
    private const BORDERS = array('border-top', 'border-right', 'border-bottom', 'border-left');

    /**
     * Container longhands core layout styles (is-layout-flex, is-layout-grid,
     * buttons, navigation, social links) set on a flex or grid block.
     */
    private const FLEX_BASE_PROPERTIES = array('flex-direction', 'flex-wrap', 'justify-content', 'align-items', 'row-gap', 'column-gap');
    private const GRID_BASE_PROPERTIES = array('grid-template-columns', 'row-gap', 'column-gap');

    /**
     * Longhands WordPress core or user-agent styles set otherwise on these
     * blocks' wrappers, so an observed initial value must still be declared.
     */
    private const BLOCK_BASE_PROPERTIES = array(
        'core/separator' => array('border-top', 'border-right', 'border-bottom', 'border-left', 'opacity', 'background-color'),
        'core/pullquote' => array('border-top', 'border-bottom'),
        'core/quote' => array('border-left', 'border-right'),
        'core/code' => array('border-top', 'border-right', 'border-bottom', 'border-left', 'border-radius', 'overflow-x'),
        'core/cover' => array('min-height', 'position', 'overflow-x', 'overflow-y'),
        'core/column' => array('flex-grow', 'flex-shrink', 'flex-basis', 'min-width'),
        'core/media-text' => array('grid-template-columns', 'grid-template-rows'),
        'core/button' => array('border-radius', 'flex-basis'),
        'core/image' => array('vertical-align'),
    );

    /**
     * @param array<string, string> $styles Observed used styles of one box.
     * @param array<string, string>|null $parent Observed used styles of the box it inherits from, if captured.
     * @return array<string, string> Property => one of the class constants.
     */
    public function classify(array $styles, ?array $parent, string $blockName = ''): array
    {
        $display = strtolower($styles['display'] ?? '');
        $parentDisplay = null === $parent ? null : strtolower($parent['display'] ?? '');
        $protected = array_fill_keys(self::BLOCK_BASE_PROPERTIES[$blockName] ?? array(), true);
        $result = array();
        foreach ($styles as $property => $value) {
            $result[$property] = $this->classifyOne($property, (string) $value, $styles, $parent, $display, $parentDisplay, isset($protected[$property]));
        }
        return $result;
    }

    private function classifyOne(string $property, string $value, array $styles, ?array $parent, string $display, ?string $parentDisplay, bool $protected): string
    {
        if ('display' === $property) return self::KEPT;
        if ('' === $value || 'initial' === $value || 'inherit' === $value) return self::NOT_APPLICABLE;
        if (in_array($property, BoxTree::INHERITED_PROPERTIES, true) || in_array($property, self::EXTRA_INHERITED, true)) {
            return null !== $parent && isset($parent[$property]) && self::inheritedValue($property, (string) $parent[$property]) === self::inheritedValue($property, $value) ? self::INHERITED : self::KEPT;
        }
        if ($protected) return self::KEPT;
        $initial = $this->isInitial($property, $value, $styles, $parentDisplay);
        if ($this->notApplicable($property, $initial, $styles, $display, $parentDisplay)) return self::NOT_APPLICABLE;
        if ($this->protectedInContext($property, $display)) return self::KEPT;
        return $initial ? self::INITIAL : self::KEPT;
    }

    /** Whether the property cannot affect this box, so even its value is irrelevant. */
    private function notApplicable(string $property, bool $initial, array $styles, string $display, ?string $parentDisplay): bool
    {
        $flex = self::isFlex($display);
        $grid = self::isGrid($display);
        // Unknown parents keep non-initial item longhands.
        $parentFlex = null === $parentDisplay || self::isFlex($parentDisplay);
        $parentGrid = null === $parentDisplay || self::isGrid($parentDisplay);
        // Container and item longhands with a non-initial value stay: they can
        // still reach absolutely positioned or multi-column descendants.
        if (in_array($property, self::FLEX_CONTAINER, true)) return ! $flex && $initial;
        if (in_array($property, self::GRID_CONTAINER, true)) return ! $grid && $initial;
        if (in_array($property, self::BOX_ALIGNMENT_CONTAINER, true)) return ! $flex && ! $grid && $initial;
        if (in_array($property, self::FLEX_ITEM, true)) return ! $parentFlex;
        if (in_array($property, self::LAYOUT_ITEM, true)) return ! $parentFlex && ! $parentGrid && $initial;
        if (in_array($property, self::OFFSETS, true)) return 'static' === ($styles['position'] ?? 'static');
        if ('transform-origin' === $property) return 'none' === ($styles['transform'] ?? 'none');
        if ('text-decoration-color' === $property || 'text-decoration-style' === $property) return 'none' === ($styles['text-decoration-line'] ?? 'none');
        if ('mask-mode' === $property) return 'none' === ($styles['mask-image'] ?? 'none');
        if (in_array($property, self::BACKGROUND_LAYER, true)) return 'none' === ($styles['background-image'] ?? 'none');
        if ('background-clip' === $property) {
            return 'none' === ($styles['background-image'] ?? 'none') && in_array($styles['background-color'] ?? '', self::INITIAL_VALUES['background-color'], true);
        }
        return false;
    }

    /** Context where WordPress base styles commonly set a non-initial value. */
    private function protectedInContext(string $property, string $display): bool
    {
        if (self::isFlex($display) && in_array($property, self::FLEX_BASE_PROPERTIES, true)) return true;
        if (self::isGrid($display) && in_array($property, self::GRID_BASE_PROPERTIES, true)) return true;
        // Background layers are only observed with an image; keep them whole.
        return in_array($property, self::BACKGROUND_LAYER, true);
    }

    private function isInitial(string $property, string $value, array $styles, ?string $parentDisplay): bool
    {
        if (in_array($property, self::BORDERS, true) || 'outline' === $property) {
            // A side whose style is none, or whose width is zero, paints nothing.
            $tokens = preg_split('/\s+/', strtolower(trim($value))) ?: array();
            return in_array('none', $tokens, true) || in_array('0px', $tokens, true);
        }
        if ('text-decoration-color' === $property) return $value === ($styles['color'] ?? null);
        // A relatively positioned box with zero offsets does not move.
        if (in_array($property, self::OFFSETS, true) && 'relative' === ($styles['position'] ?? null)) return 'auto' === $value || '0px' === $value;
        // Outside a flex or grid parent `min-*: auto` resolves to zero.
        if (in_array($property, self::MIN_SIZE, true)) {
            return 'auto' === $value || ('0px' === $value && null !== $parentDisplay && ! self::isFlex($parentDisplay) && ! self::isGrid($parentDisplay));
        }
        return in_array($value, self::INITIAL_VALUES[$property] ?? array(), true);
    }

    /** Inherited values compared by rendering: a fully transparent shadow paints nothing. */
    private static function inheritedValue(string $property, string $value): string
    {
        if ('text-shadow' !== $property || 'none' === $value) return $value;
        preg_match_all('/rgba?\([^)]*\)/', $value, $colors);
        foreach ($colors[0] as $color) {
            if (1 !== preg_match('/^rgba\(.*,\s*0\)$/', $color)) return $value;
        }
        $rest = preg_replace('/rgba?\([^)]*\)/', '', $value) ?? $value;
        return array() !== $colors[0] && 1 === preg_match('/^[\s\d.,a-z%-]*$/', $rest) && 0 === preg_match('/[a-z]{3,}/', $rest) ? 'none' : $value;
    }

    private static function isFlex(string $display): bool
    {
        return in_array($display, array('flex', 'inline-flex'), true);
    }

    private static function isGrid(string $display): bool
    {
        return in_array($display, array('grid', 'inline-grid'), true);
    }
}
