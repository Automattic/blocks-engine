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
 * - it is an inherited property whose value differs from the observed parent,
 *   or the emitted element has a user-agent, WordPress or theme.json default
 *   for it (a heading's size and weight, a link's color), so without the
 *   carrier it would not inherit the observed value;
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
     * set by user-agent or WordPress base styles on too many blocks (except
     * the zero box sides of {@see self::ZERO_DEFAULT_BOX_SIDES}).
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
     * Wrapper declarations constrained by a block's generated structure.
     * Cover's absolute image and overlay need a containing block and clipping,
     * and its content position aligns the inner container only in Core's flex
     * layout (the source children's percentage sizes resolve against that
     * container, not the cover). Source values outside these sets must yield
     * to Core, not undo it.
     */
    private const BLOCK_STRUCTURAL_VALUES = array(
        'core/cover' => array(
            'display' => array('flex', 'none'),
            'position' => array('relative', 'absolute', 'fixed', 'sticky'),
            'overflow' => array('hidden', 'clip', 'auto', 'scroll'),
            'overflow-x' => array('hidden', 'clip', 'auto', 'scroll'),
            'overflow-y' => array('hidden', 'clip', 'auto', 'scroll'),
        ),
    );

    /**
     * Paint already represented by the block's own image and gradient layers.
     * Cover owns these layers, so replaying them on its wrapper paints twice
     * and can request the unrewritten source image URL.
     */
    private const BLOCK_CONSUMED_PROPERTIES = array(
        'core/cover' => array(
            'background-image', 'background-size', 'background-position',
            'background-position-x', 'background-position-y', 'background-repeat',
            'background-clip', 'background-origin', 'background-attachment', 'background-blend-mode',
        ),
    );

    /**
     * Box sides whose default is zero on these blocks' emitted elements in
     * every context a box-tree carrier lands in (the author stylesheet is not
     * shipped there). The user agent gives the elements no padding and no
     * inline margins, and WordPress pads them only with `has-background`.
     * Flow, flex and grid layouts give children no inline margins, and a
     * constrained layout centres them with `!important`, which no block
     * attribute or carrier rule overrides. Vertical margins stay (the user
     * agent's `1em`), and so does a list's inline padding: one side is its
     * marker indent, and the evidence does not record the writing direction
     * that says which. Keys are block names, then the emitted tags they
     * apply to.
     */
    private const ZERO_DEFAULT_BOX_SIDES = array(
        'core/paragraph' => array('tags' => array('p'), 'sides' => array('padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'margin-left', 'margin-right')),
        'core/heading' => array('tags' => array('h1', 'h2', 'h3', 'h4', 'h5', 'h6'), 'sides' => array('padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'margin-left', 'margin-right')),
        'core/list' => array('tags' => array('ul', 'ol'), 'sides' => array('padding-top', 'padding-bottom', 'margin-left', 'margin-right')),
        'core/list-item' => array('tags' => array('li'), 'sides' => array('padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'margin-left', 'margin-right')),
    );

    /** Inherited longhands theme.json `elements` (heading, link, button, caption) and core control styles may set. */
    private const ELEMENT_TYPOGRAPHY = array('color', 'font-family', 'font-size', 'font-weight', 'font-style', 'line-height', 'letter-spacing', 'text-transform');
    private const CONTROL_TYPOGRAPHY = array('color', 'font-family', 'font-size', 'font-weight', 'font-style', 'line-height', 'letter-spacing', 'word-spacing', 'text-transform', 'text-indent', 'text-shadow', 'text-align', 'cursor');
    private const MONOSPACE = array('font-family', 'font-size');
    private const LIST_MARKER = array('list-style-type', 'list-style-position');

    /**
     * Inherited longhands the user agent sets on an emitted element (and, for
     * headings, links, buttons and captions, theme.json `elements`), so the
     * element does not inherit its parent's value for them.
     */
    private const TAG_INHERITED_DEFAULTS = array(
        'h1' => self::ELEMENT_TYPOGRAPHY, 'h2' => self::ELEMENT_TYPOGRAPHY, 'h3' => self::ELEMENT_TYPOGRAPHY,
        'h4' => self::ELEMENT_TYPOGRAPHY, 'h5' => self::ELEMENT_TYPOGRAPHY, 'h6' => self::ELEMENT_TYPOGRAPHY,
        'a' => array('color', 'cursor', 'font-family', 'font-size', 'font-weight', 'font-style', 'line-height', 'letter-spacing', 'text-transform'),
        'button' => self::CONTROL_TYPOGRAPHY, 'input' => self::CONTROL_TYPOGRAPHY, 'select' => self::CONTROL_TYPOGRAPHY,
        'textarea' => array('color', 'font-family', 'font-size', 'font-weight', 'font-style', 'line-height', 'letter-spacing', 'word-spacing', 'text-transform', 'text-indent', 'text-shadow', 'text-align', 'cursor', 'white-space', 'overflow-wrap', 'word-break'),
        'figcaption' => self::ELEMENT_TYPOGRAPHY, 'caption' => array('text-align'),
        'b' => array('font-weight'), 'strong' => array('font-weight'), 'th' => array('font-weight', 'text-align'),
        'small' => array('font-size'), 'big' => array('font-size'), 'sub' => array('font-size'), 'sup' => array('font-size'),
        'code' => self::MONOSPACE, 'kbd' => self::MONOSPACE, 'samp' => self::MONOSPACE, 'tt' => self::MONOSPACE,
        'pre' => array('font-family', 'font-size', 'white-space'),
        'em' => array('font-style'), 'i' => array('font-style'), 'cite' => array('font-style'), 'var' => array('font-style'), 'dfn' => array('font-style'), 'address' => array('font-style'),
        'mark' => array('color'), 'center' => array('text-align'), 'summary' => array('cursor', 'list-style-type', 'list-style-position'),
        'ul' => self::LIST_MARKER, 'ol' => self::LIST_MARKER, 'menu' => self::LIST_MARKER,
    );

    /**
     * Inherited longhands WordPress core block styles or theme.json elements
     * set on (or through) a block's emitted wrapper, beyond its element tag.
     */
    private const BLOCK_INHERITED_DEFAULTS = array(
        'core/heading' => self::ELEMENT_TYPOGRAPHY,
        'core/post-title' => self::ELEMENT_TYPOGRAPHY,
        'core/query-title' => self::ELEMENT_TYPOGRAPHY,
        'core/site-title' => self::ELEMENT_TYPOGRAPHY,
        'core/accordion-heading' => self::ELEMENT_TYPOGRAPHY,
        'core/button' => self::CONTROL_TYPOGRAPHY,
        'core/search' => self::CONTROL_TYPOGRAPHY,
        'core/file' => self::CONTROL_TYPOGRAPHY,
        'core/navigation-link' => self::ELEMENT_TYPOGRAPHY,
        'core/navigation-submenu' => self::ELEMENT_TYPOGRAPHY,
        'core/home-link' => self::ELEMENT_TYPOGRAPHY,
        'core/read-more' => self::ELEMENT_TYPOGRAPHY,
        'core/code' => array('font-family', 'font-size', 'white-space', 'overflow-wrap', 'word-break'),
        'core/preformatted' => array('font-family', 'font-size', 'white-space', 'overflow-wrap', 'word-break'),
        'core/verse' => array('font-family', 'font-size', 'white-space', 'overflow-wrap', 'word-break'),
        'core/pullquote' => array('font-size', 'line-height', 'text-align'),
        'core/list' => self::LIST_MARKER,
        'core/table' => array('font-size', 'font-weight', 'text-align'),
    );

    /**
     * Initial values of box-model and sizing longhands, as authors spell them.
     * An observed used value never reads `auto` or `0%`, so these are only
     * consulted for a lifted author value ({@see self::classifyLifted()}),
     * where the author's winner restates the property's default.
     */
    private const LIFTED_INITIAL_VALUES = array(
        'width' => array('auto'),
        'height' => array('auto'),
        'max-width' => array('none'),
        'margin-right' => array('0', '0px', '0%'),
        'margin-left' => array('0', '0px', '0%'),
    );

    /** A one-track grid template, which only a grid box can use. */
    private const LIFTED_ONE_TRACK = array('1fr', 'minmax(0px,1fr)', 'minmax(0,1fr)');

    /** Elements whose user-agent styles or presentational hints size them or give them margins. */
    private const LIFTED_TAG_DEFAULTS = array(
        'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'dl', 'dd', 'menu', 'blockquote', 'figure', 'pre', 'hr', 'fieldset', 'body',
        'img', 'picture', 'svg', 'video', 'audio', 'iframe', 'embed', 'object', 'canvas', 'input', 'textarea', 'select', 'button', 'table',
    );

    /** Blocks whose WordPress core styles size their wrapper or emit an element with default margins. */
    private const LIFTED_BLOCK_DEFAULTS = array(
        'core/image', 'core/gallery', 'core/table', 'core/embed', 'core/video', 'core/audio', 'core/pullquote', 'core/quote', 'core/separator',
        'core/site-logo', 'core/avatar', 'core/post-featured-image', 'core/cover', 'core/spacer', 'core/column', 'core/button', 'core/search',
        'core/paragraph', 'core/heading', 'core/list', 'core/code', 'core/preformatted', 'core/verse', 'core/html',
    );

    /**
     * Classify lifted author base values (BoxTreeFluidIntent) in the box's
     * context, as {@see self::classify()} does observed ones. Beyond the
     * observed initial values, an author `auto`, `none` or zero sizing or
     * inline margin is initial where neither the element nor the block's
     * base styles set the property, and a one-track grid template does not
     * apply outside a grid box.
     *
     * @param array<string, string> $styles The box's used styles with the lifted values in place.
     * @param list<string> $properties The lifted properties to classify.
     * @return array<string, string> Property => one of the class constants.
     */
    public function classifyLifted(array $styles, ?array $parent, array $properties, string $blockName = '', string $tagName = ''): array
    {
        $status = array_intersect_key($this->classify($styles, $parent, $blockName, $tagName), array_flip($properties));
        $tagName = strtolower($tagName);
        $known = '' !== $blockName || '' !== $tagName;
        $defaults = in_array($tagName, self::LIFTED_TAG_DEFAULTS, true) || in_array($blockName, self::LIFTED_BLOCK_DEFAULTS, true);
        $protected = array_fill_keys(self::BLOCK_BASE_PROPERTIES[$blockName] ?? array(), true);
        foreach ($status as $property => $reason) {
            $value = strtolower(preg_replace('/\s+/', '', (string) $styles[$property]) ?? '');
            // A CSS-wide keyword is an author instruction (inherit over a
            // user-agent default), not an observed value to compare.
            if (in_array($value, array('inherit', 'initial', 'unset', 'revert', 'revert-layer'), true)) {
                $status[$property] = self::KEPT;
                continue;
            }
            if (self::KEPT !== $reason || isset($protected[$property])) continue;
            if (in_array($property, array('grid-template-columns', 'grid-template-rows'), true)) {
                if (in_array($value, self::LIFTED_ONE_TRACK, true) && !self::isGrid(strtolower($styles['display'] ?? ''))) $status[$property] = self::NOT_APPLICABLE;
                continue;
            }
            if ($known && !$defaults && in_array($value, self::LIFTED_INITIAL_VALUES[$property] ?? array(), true)) $status[$property] = self::INITIAL;
        }
        return $status;
    }

    /** A non-inherited property's canonical initial value, when known. */
    public static function cascadeInitial(string $property): ?string
    {
        if (in_array($property, BoxTree::INHERITED_PROPERTIES, true) || in_array($property, self::EXTRA_INHERITED, true)) return null;
        return self::INITIAL_VALUES[$property][0] ?? null;
    }

    /** Whether the user agent gives this element its own value for an inherited property ({@see self::TAG_INHERITED_DEFAULTS}). */
    public static function ownsDefault(string $tagName, string $property): bool
    {
        return in_array($property, self::TAG_INHERITED_DEFAULTS[strtolower($tagName)] ?? array(), true);
    }

    /**
     * @param array<string, string> $styles Observed used styles of one box.
     * @param array<string, string>|null $parent Observed used styles of the box it inherits from, if captured.
     * @param string $tagName The element the carrier lands on, when known.
     * @return array<string, string> Property => one of the class constants.
     */
    public function classify(array $styles, ?array $parent, string $blockName = '', string $tagName = ''): array
    {
        $display = strtolower($styles['display'] ?? '');
        $parentDisplay = null === $parent ? null : strtolower($parent['display'] ?? '');
        $protected = array_fill_keys(self::BLOCK_BASE_PROPERTIES[$blockName] ?? array(), true);
        $ownDefaults = array_fill_keys(array_merge(self::TAG_INHERITED_DEFAULTS[strtolower($tagName)] ?? array(), self::BLOCK_INHERITED_DEFAULTS[$blockName] ?? array()), true);
        $zeroDefault = self::zeroDefaultSides($styles, $blockName, $tagName);
        $result = array();
        $structural = self::BLOCK_STRUCTURAL_VALUES[$blockName] ?? array();
        $consumed = array_fill_keys(self::BLOCK_CONSUMED_PROPERTIES[$blockName] ?? array(), true);
        foreach ($styles as $property => $value) {
            if (isset($consumed[$property]) || (isset($structural[$property]) && !in_array(strtolower(trim((string) $value)), $structural[$property], true))) {
                $result[$property] = self::NOT_APPLICABLE;
                continue;
            }
            $result[$property] = $this->classifyOne($property, (string) $value, $styles, $parent, $display, $parentDisplay, isset($protected[$property]), isset($ownDefaults[$property]));
            if (self::KEPT === $result[$property] && isset($zeroDefault[$property]) && in_array(trim((string) $value), array('0', '0px'), true)) $result[$property] = self::INITIAL;
        }
        return $result;
    }

    /**
     * {@see self::ZERO_DEFAULT_BOX_SIDES} for this box.
     *
     * @return array<string, true>
     */
    private static function zeroDefaultSides(array $styles, string $blockName, string $tagName): array
    {
        $entry = self::ZERO_DEFAULT_BOX_SIDES[$blockName] ?? null;
        if (null === $entry || !in_array(strtolower($tagName), $entry['tags'], true)) return array();
        // A background opts the block into WordPress's padded box.
        $painted = !in_array($styles['background-color'] ?? 'rgba(0, 0, 0, 0)', self::INITIAL_VALUES['background-color'], true)
            || 'none' !== ($styles['background-image'] ?? 'none');
        $sides = array();
        foreach ($entry['sides'] as $side) {
            if ($painted && str_starts_with($side, 'padding-')) continue;
            $sides[$side] = true;
        }
        return $sides;
    }

    private function classifyOne(string $property, string $value, array $styles, ?array $parent, string $display, ?string $parentDisplay, bool $protected, bool $ownDefault): string
    {
        if ('display' === $property) return self::KEPT;
        if ('' === $value || 'initial' === $value || 'inherit' === $value) return self::NOT_APPLICABLE;
        if (in_array($property, BoxTree::INHERITED_PROPERTIES, true) || in_array($property, self::EXTRA_INHERITED, true)) {
            // The element would compute its own default, not the parent's value.
            if ($ownDefault) return self::KEPT;
            if ('pointer-events' === $property && self::passThroughLayer($value, $styles)) return self::KEPT;
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

    /**
     * An out-of-flow box that lets pointers through to the content it covers
     * (a pinned header layer, an overlay) states that itself. The block editor
     * gives every block wrapper `pointer-events: auto`, so the inherited
     * `none` never reaches the block there, and the layer swallows clicks
     * meant for the blocks beneath it.
     */
    private static function passThroughLayer(string $value, array $styles): bool
    {
        return 'none' === $value && in_array($styles['position'] ?? '', array('absolute', 'fixed'), true);
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
