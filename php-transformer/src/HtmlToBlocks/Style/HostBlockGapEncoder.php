<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssLength;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\BlockFactory;
use DOMElement;

/**
 * Encodes the source's vertical child spacing on emitted groups for a host
 * theme the engine does not own.
 *
 * WordPress gives every child after the first in a flow or constrained group
 * `margin-block-start` equal to the group's `style.spacing.blockGap`, else the
 * theme's `styles.spacing.blockGap`, and zeroes `margin-block-end`; a child's
 * own `style.spacing.margin.top` wins. A group whose children are laid out by
 * an author flex or grid rule still has no native layout type, so its children
 * get those flow margins too. Flex and grid groups with a native layout type
 * space children with `gap` = blockGap, else the theme's.
 *
 * For each group whose source children are known, this writes the spacing the
 * source actually has between them: the most common value as the group's
 * blockGap, and `margin.top` on children that differ. Margins core zeroes but
 * the source keeps (a first child's start margin and a last child's end margin
 * inside a padded or bordered box) are written on the child. Flex and grid
 * item margins do not collapse, so each item keeps its own start and end
 * margins. Source margins are the static author cascade over the user-agent
 * block margins; adjoining block-flow margins collapse, including through a
 * wrapper with no padding or border.
 *
 * Values are px lengths or `0`, except that a margin which is one element's
 * own `em` margin stays an em length on that element's block when its font
 * size has no static px value (a fluid `clamp(..vw..)` preset). Collapsed
 * block-flow margins that are not one such margin stay px. A child whose
 * saved markup the block factory cannot reproduce from its attributes is
 * left unchanged.
 */
final class HostBlockGapEncoder
{
    /** User-agent block margins (top and bottom) in em of the element's own font size. */
    private const UA_BLOCK_MARGIN_EM = array(
        'p' => 1.0, 'h1' => 0.67, 'h2' => 0.83, 'h3' => 1.0, 'h4' => 1.33, 'h5' => 1.67, 'h6' => 2.33,
        'ul' => 1.0, 'ol' => 1.0, 'dl' => 1.0, 'menu' => 1.0, 'blockquote' => 1.0, 'figure' => 1.0,
        'pre' => 1.0, 'hr' => 0.5, 'listing' => 1.0, 'xmp' => 1.0,
    );

    /** User-agent heading and small-text sizes, in em of the parent. */
    private const UA_FONT_EM = array( 'h1' => 2.0, 'h2' => 1.5, 'h3' => 1.17, 'h4' => 1.0, 'h5' => 0.83, 'h6' => 0.67, 'small' => 0.833 );

    private const RESPONSIVE_MARGIN_TOP_CLASS_PREFIX = 'blocks-engine-responsive-margin-top-';

    /** @var \WeakMap<DOMElement, array{0: float, 1: bool}>|null Font size in px and whether it is static. */
    private ?\WeakMap $fontSizes = null;

    /** @var \WeakMap<DOMElement, array<string, string>>|null */
    private ?\WeakMap $declarations = null;

    /** @var \WeakMap<DOMElement, array<string, array{0: ?float, 1: ?float}>>|null */
    private ?\WeakMap $margins = null;

    /**
     * @param \Closure(int): ?DOMElement $sourceElement Source element of a block provenance id.
     */
    public function __construct(
        private readonly StyleResolver $styleResolver,
        private readonly BlockFactory $blockFactory,
        private readonly \Closure $sourceElement,
        private readonly float $rootPx = 16.0
    ) {}

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    public function encode(array $blocks): array
    {
        foreach ( $blocks as $index => $block ) {
            if ( ! is_array($block) ) {
                continue;
            }
            if ( is_array($block['innerBlocks'] ?? null) && array() !== $block['innerBlocks'] ) {
                $block['innerBlocks'] = $this->encode($block['innerBlocks']);
            }
            if ( 'core/group' === ($block['blockName'] ?? null) ) {
                $block = $this->encodeGroup($block);
            }
            $blocks[$index] = $block;
        }

        return $blocks;
    }

    /**
     * @param array<string, mixed> $group
     * @return array<string, mixed>
     */
    private function encodeGroup(array $group): array
    {
        $children = is_array($group['innerBlocks'] ?? null) ? array_values($group['innerBlocks']) : array();
        $layoutType = (string) ($group['attrs']['layout']['type'] ?? 'default');
        if ( in_array($layoutType, array( 'flex', 'grid' ), true) ) {
            return $this->encodeNativeGapGroup($group);
        }
        if ( array() === $children ) {
            return $group;
        }

        $groupSource = $this->innermostSource($group);
        $sources = array();
        $items = array();
        $containers = array();
        foreach ( $children as $i => $child ) {
            $sources[$i] = $this->outermostSource($child);
            $parent = $sources[$i]?->parentNode;
            $containers[$i] = $parent instanceof DOMElement ? $parent : $groupSource;
            $items[$i] = $containers[$i] instanceof DOMElement && $this->laysOutItems($containers[$i]);
        }

        $last = count($children) - 1;
        $spacings = array();
        $relativeTops = array();
        for ( $i = 1; $i <= $last; ++$i ) {
            $item = $items[$i];
            $spacings[$i] = $this->sourceSpacing($sources[$i - 1], $sources[$i], $item);
            // A flow start margin is the child's own only when the previous
            // end margin it collapses with is zero.
            $relativeTops[$i] = $item || 0.0 === $this->margins($sources[$i - 1], false)[1]
                ? $this->relativeMargin($sources[$i], 0, $item)
                : null;
        }
        $gap = $this->mostCommon(array_values(array_filter(
            $spacings,
            static fn (?float $value, int $i): bool => null !== $value && null === $relativeTops[$i],
            ARRAY_FILTER_USE_BOTH
        )));

        foreach ( $children as $i => $child ) {
            $item = $items[$i];
            if ( 0 === $i ) {
                // Core zeroes the first child's start margin. It is the
                // source's unless the margin collapses through the container.
                $collapses = ! $item && $this->collapsesThrough($containers[0], 'top');
                $top = $collapses ? null : $this->margins($sources[0], $item)[0];
                $relativeTop = $collapses ? null : $this->relativeMargin($sources[0], 0, $item);
                $topDefault = 0.0;
            } else {
                $top = $spacings[$i];
                $relativeTop = $relativeTops[$i];
                $topDefault = $gap;
            }

            // Core zeroes every child's end margin. Between flow siblings the
            // source's is part of the next child's start margin.
            $bottom = $relativeBottom = null;
            if ( $item || ( $i === $last && ! $this->collapsesThrough($containers[$i], 'bottom') ) ) {
                $bottom = $this->margins($sources[$i], $item)[1];
                $relativeBottom = $this->relativeMargin($sources[$i], 1, $item);
            }

            $sides = array();
            $top = $this->hasResponsiveMarginTop($child) ? null : $this->marginToWrite($child, 'top', $top, $relativeTop, $topDefault);
            if ( null !== $top ) {
                $sides['top'] = $top;
            }
            $bottom = $this->marginToWrite($child, 'bottom', $bottom, $relativeBottom, 0.0);
            if ( null !== $bottom ) {
                $sides['bottom'] = $bottom;
            }
            if ( array() !== $sides ) {
                $children[$i] = $this->withMargins($child, $sides);
            }
        }

        $group['innerBlocks'] = $children;
        if ( null !== $gap ) {
            $group['attrs'] = $this->withBlockGap(is_array($group['attrs'] ?? null) ? $group['attrs'] : array(), self::cssLength($gap));
        }

        return $group;
    }

    /**
     * The margin a child needs on one side, or null when core already gives it
     * the source's: the default, or a margin the child carries. A relative
     * (em) source margin is written unless the child already carries it.
     *
     * @param array<string, mixed> $child
     */
    private function marginToWrite(array $child, string $side, ?float $source, ?string $relative, ?float $default): ?string
    {
        $own = $child['attrs']['style']['spacing']['margin'][$side] ?? null;
        if ( null !== $relative ) {
            return $own === $relative ? null : $relative;
        }
        if ( null === $source ) {
            return null;
        }
        if ( null !== $own ) {
            $ownPx = is_string($own) ? CssLength::toPx($own, $this->rootPx) : null;

            return null !== $ownPx && abs($ownPx - $source) >= 0.001 ? self::cssLength($source) : null;
        }

        return null === $default || abs($default - $source) >= 0.001 ? self::cssLength($source) : null;
    }

    /**
     * An element's own margin on one side (0 top, 1 bottom) as an em length,
     * when it is em of a font size with no static px value and is not
     * collapsed with a descendant's; null otherwise.
     */
    private function relativeMargin(?DOMElement $element, int $side, bool $item): ?string
    {
        if ( ! $element instanceof DOMElement || $this->fontMetrics($element)[1] ) {
            return null;
        }
        if ( ! $item && ( str_starts_with($this->display($element), 'inline')
            || ( $this->collapsesThrough($element, 0 === $side ? 'top' : 'bottom') && null !== $this->flowChild($element, 1 === $side) ) )
        ) {
            return null;
        }
        $value = strtolower(self::withoutImportant((string) $this->ownMarginValues($element)[$side]));

        return 1 === preg_match('/^(-?(?:\d+\.?\d*|\.\d+))em$/', $value, $match) && 0.0 !== (float) $match[1] ? $value : null;
    }

    /**
     * A native flex or grid group spaces its children with `gap`; give it the
     * source container's gap so the host theme's does not apply.
     *
     * @param array<string, mixed> $group
     * @return array<string, mixed>
     */
    private function encodeNativeGapGroup(array $group): array
    {
        $source = $this->innermostSource($group);
        if ( ! $source instanceof DOMElement ) {
            return $group;
        }
        $declared = $this->styleResolver->specificityResolvedGapDeclarations($source);
        $row = $this->lengthPx($declared['row-gap'] ?? '0', $source);
        $column = $this->lengthPx($declared['column-gap'] ?? '0', $source);
        if ( null === $row || null === $column ) {
            return $group;
        }
        $gap = abs($row - $column) < 0.001
            ? self::cssLength($row)
            : array( 'top' => self::cssLength($row), 'left' => self::cssLength($column) );
        $group['attrs'] = $this->withBlockGap(is_array($group['attrs'] ?? null) ? $group['attrs'] : array(), $gap);

        // Core zeroes flex and grid item margins; items keep the source's.
        $children = is_array($group['innerBlocks'] ?? null) ? array_values($group['innerBlocks']) : array();
        foreach ( $children as $i => $child ) {
            $source = $this->outermostSource($child);
            $margins = $this->margins($source, true);
            $sides = array();
            foreach ( array( 'top', 'bottom' ) as $index => $side ) {
                $value = $this->marginToWrite($child, $side, $margins[$index], $this->relativeMargin($source, $index, true), 0.0);
                if ( null !== $value ) {
                    $sides[$side] = $value;
                }
            }
            if ( array() !== $sides ) {
                $children[$i] = $this->withMargins($child, $sides);
            }
        }
        $group['innerBlocks'] = $children;

        return $group;
    }

    /**
     * The start margin a child needs after its previous sibling, in px, or
     * null when it is not a definite length. Flex and grid items keep their
     * own margins (the previous item's end margin stays on that item); block
     * flow margins collapse.
     */
    private function sourceSpacing(?DOMElement $previous, ?DOMElement $current, bool $item): ?float
    {
        $currentTop = $this->margins($current, $item)[0];
        if ( $item ) {
            return $currentTop;
        }

        return self::collapse($this->margins($previous, false)[1], $currentTop);
    }

    /** Whether a container lays its children out as flex or grid items rather than in block flow. */
    private function laysOutItems(DOMElement $container): bool
    {
        $display = $this->display($container);

        return str_contains($display, 'grid') || str_contains($display, 'flex');
    }

    private static function collapse(?float $a, ?float $b): ?float
    {
        if ( null === $a || null === $b ) {
            return null;
        }
        if ( $a >= 0 && $b >= 0 ) {
            return max($a, $b);
        }
        if ( $a < 0 && $b < 0 ) {
            return min($a, $b);
        }

        return $a + $b;
    }

    /**
     * Source top and bottom margins in px, null for a side that is not a
     * definite length. A block without a source element is a synthesized
     * wrapper and has none; an inline-level box's vertical margins do not
     * space lines. In block flow a box's margin collapses with its first or
     * last child's when nothing separates them.
     *
     * @return array{0: ?float, 1: ?float}
     */
    private function margins(?DOMElement $element, bool $item): array
    {
        if ( ! $element instanceof DOMElement ) {
            return array( 0.0, 0.0 );
        }
        if ( ! $item && str_starts_with($this->display($element), 'inline') ) {
            return array( 0.0, 0.0 );
        }
        $this->margins ??= new \WeakMap();
        $key = $item ? 'item' : 'flow';
        if ( isset($this->margins[$element][$key]) ) {
            return $this->margins[$element][$key];
        }

        $own = $this->ownMargins($element);
        if ( ! $item ) {
            // Flex and grid items establish their own formatting context.
            $first = $this->collapsesThrough($element, 'top') ? $this->flowChild($element, false) : null;
            if ( $first instanceof DOMElement ) {
                $own[0] = self::collapse($own[0], $this->margins($first, false)[0]);
            }
            $lastChild = $this->collapsesThrough($element, 'bottom') ? $this->flowChild($element, true) : null;
            if ( $lastChild instanceof DOMElement ) {
                $own[1] = self::collapse($own[1], $this->margins($lastChild, false)[1]);
            }
        }
        $cached = $this->margins[$element] ?? array();
        $cached[$key] = $own;
        $this->margins[$element] = $cached;

        return $own;
    }

    /**
     * The static author cascade over the user-agent block margins.
     *
     * @return array{0: ?float, 1: ?float}
     */
    private function ownMargins(DOMElement $element): array
    {
        return $this->sidesPx($element, $this->ownMarginValues($element));
    }

    /**
     * The declared top and bottom margin values over the user-agent ones.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function ownMarginValues(DOMElement $element): array
    {
        $tag = strtolower($element->tagName);
        $uaEm = self::UA_BLOCK_MARGIN_EM[$tag] ?? 0.0;
        if ( in_array($tag, array( 'ul', 'ol', 'menu', 'dl' ), true) && $this->isNestedList($element) ) {
            $uaEm = 0.0;
        }
        $top = 0.0 === $uaEm ? '0' : $uaEm . 'em';

        return $this->boxSides($element, 'margin', array( $top, $top ));
    }

    /**
     * @param array{0: ?string, 1: ?string} $sides
     * @return array{0: ?float, 1: ?float}
     */
    private function sidesPx(DOMElement $element, array $sides): array
    {
        return array(
            null === $sides[0] ? null : $this->lengthPx($sides[0], $element),
            null === $sides[1] ? null : $this->lengthPx($sides[1], $element),
        );
    }

    /**
     * Resolve the declared top and bottom values of a box property (`margin`
     * or `padding`) from the element's declarations, in cascade order; null
     * for a side the shorthand cannot be read for.
     *
     * @param array{0: ?string, 1: ?string} $initial
     * @return array{0: ?string, 1: ?string}
     */
    private function boxSides(DOMElement $element, string $box, array $initial): array
    {
        list( $top, $bottom ) = $initial;
        foreach ( $this->declarationsOf($element) as $property => $value ) {
            if ( $box === $property || $box . '-block' === $property ) {
                $parts = CssValueSplitter::splitTopLevelWhitespace(self::withoutImportant($value));
                if ( array() === $parts || count($parts) > ($box === $property ? 4 : 2) ) {
                    $top = $bottom = null;
                    continue;
                }
                $top = $parts[0];
                $bottom = $box === $property ? ($parts[2] ?? $parts[0]) : ($parts[1] ?? $parts[0]);
            } elseif ( $box . '-top' === $property || $box . '-block-start' === $property ) {
                $top = $value;
            } elseif ( $box . '-bottom' === $property || $box . '-block-end' === $property ) {
                $bottom = $value;
            }
        }

        return array( $top, $bottom );
    }

    /**
     * Whether a block box's margin on one side adjoins its first or last
     * child's: block flow, no padding or border on that side, and nothing
     * that establishes a new formatting context.
     */
    private function collapsesThrough(?DOMElement $element, string $side): bool
    {
        if ( ! $element instanceof DOMElement ) {
            return false;
        }
        $tag = strtolower($element->tagName);
        if ( in_array($tag, array( 'html', 'body', 'fieldset', 'button', 'details', 'summary', 'legend' ), true)
            || ! in_array($this->display($element), array( 'block', 'list-item' ), true)
            // A flex or grid item establishes its own formatting context.
            || ( $element->parentNode instanceof DOMElement && $this->laysOutItems($element->parentNode) )
        ) {
            return false;
        }
        $declarations = $this->declarationsOf($element);
        foreach ( array( 'overflow', 'overflow-x', 'overflow-y', 'float', 'position', 'contain' ) as $property ) {
            $value = strtolower(self::withoutImportant((string) ($declarations[$property] ?? '')));
            if ( '' !== $value && ! in_array($value, array( 'visible', 'none', 'static', 'relative', 'sticky' ), true) ) {
                return false;
            }
        }
        $padding = $this->sidesPx($element, $this->boxSides($element, 'padding', array( '0', '0' )))['top' === $side ? 0 : 1];
        if ( null === $padding || abs($padding) >= 0.001 ) {
            return false;
        }
        $physical = 'top' === $side ? 'top' : 'bottom';
        $logical = 'top' === $side ? 'start' : 'end';
        foreach ( $declarations as $property => $value ) {
            if ( 1 === preg_match('/^border(?:-(?:' . $physical . '|block|block-' . $logical . '))?(?:-(?:width|style))?$/', $property)
                && 1 !== preg_match('/^(?:none|hidden|0(?:px)?|initial|unset)$/i', self::withoutImportant($value))
            ) {
                return false;
            }
        }
        if ( 'bottom' === $side ) {
            foreach ( array( 'height', 'min-height' ) as $property ) {
                $value = strtolower(self::withoutImportant((string) ($declarations[$property] ?? '')));
                if ( '' !== $value && ! in_array($value, array( 'auto', '0', '0px', 'initial', 'unset' ), true) ) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * The first (or last) in-flow child when it is a block-level element, so
     * its margin can adjoin the parent's; null when text or an inline box
     * comes first.
     */
    private function flowChild(DOMElement $element, bool $last): ?DOMElement
    {
        $nodes = iterator_to_array($element->childNodes, false);
        if ( $last ) {
            $nodes = array_reverse($nodes);
        }
        foreach ( $nodes as $node ) {
            if ( $node instanceof \DOMText ) {
                if ( '' === trim($node->data) ) {
                    continue;
                }
                return null;
            }
            if ( ! $node instanceof DOMElement ) {
                continue;
            }
            $display = $this->display($node);
            $position = strtolower(self::withoutImportant((string) ($this->declarationsOf($node)['position'] ?? '')));
            if ( 'none' === $display || in_array($position, array( 'absolute', 'fixed' ), true)
                || in_array(strtolower($node->tagName), array( 'script', 'style', 'template', 'noscript' ), true)
                || $node->hasAttribute('hidden')
            ) {
                continue;
            }

            return str_starts_with($display, 'inline') ? null : $node;
        }

        return null;
    }

    private function lengthPx(string $value, DOMElement $element): ?float
    {
        $value = strtolower(self::withoutImportant($value));
        if ( in_array($value, array( 'initial', 'unset', 'revert', 'revert-layer' ), true) ) {
            return 0.0;
        }

        return CssLength::toPx($value, $this->rootPx, $this->fontSize($element));
    }

    private function fontSize(DOMElement $element): float
    {
        return $this->fontMetrics($element)[0];
    }

    /**
     * The element's font size in px and whether it is static: false when the
     * declared size (or an inherited one) has no px value, such as a fluid
     * `clamp(..vw..)` preset, and the px size is only an estimate.
     *
     * @return array{0: float, 1: bool}
     */
    private function fontMetrics(DOMElement $element): array
    {
        $this->fontSizes ??= new \WeakMap();
        if ( isset($this->fontSizes[$element]) ) {
            return $this->fontSizes[$element];
        }

        list( $parentPx, $static ) = $element->parentNode instanceof DOMElement ? $this->fontMetrics($element->parentNode) : array( $this->rootPx, true );
        $tag = strtolower($element->tagName);
        $declared = strtolower(self::withoutImportant((string) ($this->declarationsOf($element)['font-size'] ?? '')));
        $size = null;
        if ( '' !== $declared ) {
            if ( in_array($declared, array( 'inherit', 'unset' ), true) ) {
                $size = $parentPx;
            } elseif ( 'initial' === $declared ) {
                $size = 16.0;
                $static = true;
            } elseif ( isset(CssLength::FONT_SIZE_KEYWORDS[$declared]) ) {
                $size = CssLength::FONT_SIZE_KEYWORDS[$declared];
                $static = true;
            } elseif ( 'smaller' === $declared ) {
                $size = $parentPx / 1.2;
            } elseif ( 'larger' === $declared ) {
                $size = $parentPx * 1.2;
            } elseif ( 1 === preg_match('/^(\d+\.?\d*|\.\d+)%$/', $declared, $match) ) {
                $size = (float) $match[1] / 100 * $parentPx;
            } else {
                $px = CssLength::toPx($declared, $this->rootPx, $parentPx);
                $size = null !== $px && $px > 0 ? $px : null;
                $static = null === $size ? false : $static || 1 !== preg_match('/(?<!r)em\b/', $declared);
            }
        }
        if ( null === $size && 'html' === $tag ) {
            $size = $this->rootPx;
        }
        $size ??= isset(self::UA_FONT_EM[$tag]) ? self::UA_FONT_EM[$tag] * $parentPx : $parentPx;

        return $this->fontSizes[$element] = array( round($size, 3), $static );
    }

    private function display(DOMElement $element): string
    {
        $declared = strtolower(self::withoutImportant((string) ($this->declarationsOf($element)['display'] ?? '')));

        return '' !== $declared ? $declared : $this->styleResolver->defaultTagDisplay($element);
    }

    private function isNestedList(DOMElement $element): bool
    {
        for ( $node = $element->parentNode; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( in_array(strtolower($node->tagName), array( 'ul', 'ol', 'menu', 'dl', 'dir' ), true) ) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> */
    private function declarationsOf(DOMElement $element): array
    {
        $this->declarations ??= new \WeakMap();
        if ( ! isset($this->declarations[$element]) ) {
            $declarations = array();
            foreach ( $this->styleResolver->resolvedPresentationDeclarations($element) as $property => $value ) {
                $declarations[strtolower(trim((string) $property))] = trim((string) $value);
            }
            $this->declarations[$element] = $declarations;
        }

        return $this->declarations[$element];
    }

    /** @param array<string, mixed> $block */
    private function outermostSource(array $block): ?DOMElement
    {
        $elements = $this->sourceElements($block);
        usort($elements, static fn (DOMElement $a, DOMElement $b): int => self::depth($a) <=> self::depth($b));

        return $elements[0] ?? null;
    }

    /** @param array<string, mixed> $block */
    private function innermostSource(array $block): ?DOMElement
    {
        $elements = $this->sourceElements($block);
        usort($elements, static fn (DOMElement $a, DOMElement $b): int => self::depth($b) <=> self::depth($a));

        return $elements[0] ?? null;
    }

    /**
     * @param array<string, mixed> $block
     * @return list<DOMElement>
     */
    private function sourceElements(array $block): array
    {
        $ids = is_array($block['_source_provenance_ids'] ?? null)
            ? $block['_source_provenance_ids']
            : array( $block['_source_provenance_id'] ?? null );
        $elements = array();
        foreach ( $ids as $id ) {
            $element = is_int($id) ? ( $this->sourceElement )($id) : null;
            if ( $element instanceof DOMElement ) {
                $elements[] = $element;
            }
        }

        return $elements;
    }

    private static function depth(DOMElement $element): int
    {
        $depth = 0;
        for ( $node = $element->parentNode; null !== $node; $node = $node->parentNode ) {
            ++$depth;
        }

        return $depth;
    }

    /** @param array<string, mixed> $block */
    private function hasResponsiveMarginTop(array $block): bool
    {
        return str_contains((string) ($block['attrs']['className'] ?? ''), self::RESPONSIVE_MARGIN_TOP_CLASS_PREFIX);
    }

    /**
     * Rebuild a child with its own margins. Only a block whose saved markup
     * the factory reproduces from its attributes can be rebuilt; any other is
     * left as it was.
     *
     * @param array<string, mixed> $block
     * @param array<string, string> $sides
     * @return array<string, mixed>
     */
    private function withMargins(array $block, array $sides): array
    {
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
        // The factory drops a group's blockGap (layout renders it, save does
        // not); carry the one this encoder wrote across the rebuild.
        $blockGap = $attrs['style']['spacing']['blockGap'] ?? null;
        if ( null !== $blockGap ) {
            $attrs = $this->withoutBlockGap($attrs);
            $block['attrs'] = $attrs;
        }
        $same = $this->blockFactory->rebuild($block, $attrs);
        if ( ($same['innerContent'] ?? null) !== ($block['innerContent'] ?? null) || ($same['attrs'] ?? null) !== $attrs ) {
            return null === $blockGap ? $block : array_merge($block, array( 'attrs' => $this->withBlockGap($attrs, $blockGap) ));
        }
        foreach ( $sides as $side => $length ) {
            $attrs['style']['spacing']['margin'][$side] = $length;
        }
        $rebuilt = $this->blockFactory->rebuild($block, $attrs);
        if ( null !== $blockGap ) {
            $rebuilt['attrs'] = $this->withBlockGap(is_array($rebuilt['attrs'] ?? null) ? $rebuilt['attrs'] : array(), $blockGap);
        }

        return $rebuilt;
    }

    /**
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private function withoutBlockGap(array $attrs): array
    {
        unset($attrs['style']['spacing']['blockGap']);
        if ( empty($attrs['style']['spacing']) ) {
            unset($attrs['style']['spacing']);
        }
        if ( empty($attrs['style']) ) {
            unset($attrs['style']);
        }

        return $attrs;
    }

    /**
     * @param array<string, mixed> $attrs
     * @param mixed $gap
     * @return array<string, mixed>
     */
    private function withBlockGap(array $attrs, mixed $gap): array
    {
        $style = is_array($attrs['style'] ?? null) ? $attrs['style'] : array();
        $spacing = is_array($style['spacing'] ?? null) ? $style['spacing'] : array();
        $spacing['blockGap'] = $gap;
        $style['spacing'] = $spacing;
        $attrs['style'] = $style;

        return $attrs;
    }

    /** @param list<float> $values */
    private function mostCommon(array $values): ?float
    {
        $counts = array();
        foreach ( $values as $value ) {
            $key = self::cssLength($value);
            $counts[$key] = array( 'value' => $value, 'count' => ($counts[$key]['count'] ?? 0) + 1 );
        }
        $best = null;
        foreach ( $counts as $entry ) {
            if ( null === $best || $entry['count'] > $best['count'] ) {
                $best = $entry;
            }
        }

        return $best['value'] ?? null;
    }

    /** A length core's style engine accepts: `0` or a px length. */
    private static function cssLength(float $px): string
    {
        return abs($px) < 0.0005 ? '0' : CssLength::format($px);
    }

    private static function withoutImportant(string $value): string
    {
        return trim((string) preg_replace('/\s*!\s*important\s*$/i', '', $value));
    }
}
