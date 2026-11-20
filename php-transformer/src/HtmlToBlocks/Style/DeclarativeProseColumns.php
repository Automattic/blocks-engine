<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use DOMElement;
use Automattic\BlocksEngine\PhpTransformer\Css\CssFluidLength;

/**
 * Resolve only normal block flow, stretched single-column flex flow and
 * explicit fractional grids; unknown layouts fail closed.
 */
final class DeclarativeProseColumns
{
    public static function collect(DOMElement $body, StyleResolver $resolver): array
    {
        $columns = array();
        // A paragraph authored later carries no source class: only the rules a
        // bare paragraph matches style it.
        $bare = $body->ownerDocument?->createElement('p') ?: null;
        $rem = $resolver->documentRootFontSize($body);
        foreach (array(390, 1440) as $viewport) {
            $best = null;
            $visit = function (DOMElement $element, float $left, float $available, bool $inGrid = false, bool $flexItem = false) use (&$visit, &$best, $resolver, $viewport, $bare, $rem): void {
                $properties = array('display', 'position', 'visibility', 'width', 'max-width', 'padding', 'padding-left', 'padding-right', 'margin', 'margin-left', 'margin-right', 'margin-inline', 'grid-template-columns', 'gap', 'column-gap', 'flex-direction', 'flex-flow', 'align-items', 'align-self');
                $s = $resolver->authorViewport->atViewport($element, $properties, $viewport);
                $tag = strtolower($element->tagName);
                if (in_array($tag, array('header', 'footer', 'nav', 'aside', 'form'), true) || $element->hasAttribute('hidden') || in_array($s['display'] ?? '', array('none', 'inline-flex'), true) || in_array($s['position'] ?? '', array('absolute', 'fixed'), true) || 'hidden' === ($s['visibility'] ?? '')) return;
                // A column flex container stretches each item across its inline
                // size, as block flow does; any other flex layout is unknown.
                $flex = 'flex' === ($s['display'] ?? '');
                if ($flex && !self::stretchedColumn($s)) return;
                if ($flexItem && !in_array(strtolower(trim($s['align-self'] ?? '')), array('', 'auto', 'normal', 'stretch'), true)) return;
                $length = static fn(string $value): ?float => CssFluidLength::evaluate($value, $viewport, 900, $available, null, $rem);
                $margin = preg_split('/\s+/', trim($s['margin'] ?? '0'));
                $inline = preg_split('/\s+/', trim($s['margin-inline'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
                $ml = $s['margin-left'] ?? $inline[0] ?? ($margin[3] ?? $margin[1] ?? $margin[0]);
                $mr = $s['margin-right'] ?? $inline[1] ?? $inline[0] ?? ($margin[1] ?? $margin[0]);
                // An auto-width box fills its containing block less its own
                // non-auto horizontal margins.
                $width = isset($s['width']) ? $length($s['width']) : $available - ('auto' === $ml ? 0 : ($length($ml) ?? 0)) - ('auto' === $mr ? 0 : ($length($mr) ?? 0));
                if (null === $width) return;
                // Native paragraphs occupy their containing block's column; a
                // max-width a bare paragraph also gets styles authored ones in
                // place. One its own source classes give it narrows the column.
                if (isset($s['max-width']) && null !== ($max = $length($s['max-width']))) {
                    if ('p' !== $tag || null === $bare || ($resolver->authorViewport->atViewport($bare, array('max-width'), $viewport)['max-width'] ?? null) !== $s['max-width']) $width = min($width, $max);
                }
                $left += 'auto' === $ml ? max(0, ($available - $width) / 2) : ($length($ml) ?? 0);
                $padding = preg_split('/\s+/', trim($s['padding'] ?? '0'));
                $pl = $length($s['padding-left'] ?? ($padding[3] ?? $padding[1] ?? $padding[0])) ?? 0;
                $pr = $length($s['padding-right'] ?? ($padding[1] ?? $padding[0])) ?? 0;
                $left += $pl;
                $width -= $pl + $pr;
                if ('p' === $tag && '' !== trim($element->textContent) && $width > ($best['width'] ?? 0) && $left >= 0 && $left + $width <= $viewport + 1) $best = array('viewport' => $viewport, 'left' => $left, 'width' => $width, 'grid' => $inGrid);
                $tracks = null;
                if ('grid' === ($s['display'] ?? '')) {
                    $tracks = preg_split('/\s+/', trim($s['grid-template-columns'] ?? ''));
                    foreach ($tracks as $track) if (!preg_match('/^\d*\.?\d+fr$/', $track)) return;
                }
                $children = array_values(iterator_to_array($element->childNodes));
                $children = array_values(array_filter($children, static fn($child): bool => $child instanceof DOMElement));
                $gap = $length($s['column-gap'] ?? $s['gap'] ?? '0') ?? 0;
                $total = null === $tracks ? 0 : array_sum(array_map('floatval', $tracks));
                foreach ($children as $index => $child) {
                    if (null === $tracks) $visit($child, $left, $width, $inGrid, $flex);
                    elseif ($index < count($tracks) && $total > 0) {
                        $space = $width - $gap * (count($tracks) - 1);
                        $offset = array_sum(array_map('floatval', array_slice($tracks, 0, $index))) / $total * $space + $index * $gap;
                        $visit($child, $left + $offset, $space * (float) $tracks[$index] / $total, true);
                    }
                }
            };
            $visit($body, 0, $viewport);
            if (null !== $best) $columns[] = $best;
        }
        // A wider centered normal-flow prose column already has native
        // constrained layout support. Do not replace it with a narrower grid
        // panel. Constrained layout centers its column, so an off-center one
        // keeps its margins, at every viewport the column is resolved at.
        $positioned = array_filter($columns, static fn(array $column): bool => $column['grid'] || abs($column['left'] - ($column['viewport'] - $column['left'] - $column['width'])) > 1);
        if (array() === $positioned) return array();
        return array_map(static function (array $column): array { unset($column['grid']); return $column; }, $columns);
    }

    /** @param array<string,string> $s */
    private static function stretchedColumn(array $s): bool
    {
        $direction = preg_split('/\s+/', strtolower(trim(($s['flex-direction'] ?? '') . ' ' . ($s['flex-flow'] ?? ''))), -1, PREG_SPLIT_NO_EMPTY) ?: array();
        if (array() === array_intersect($direction, array('column', 'column-reverse')) || array() !== array_intersect($direction, array('row', 'row-reverse'))) return false;
        return in_array(strtolower(trim($s['align-items'] ?? '')), array('', 'normal', 'stretch'), true);
    }
}
