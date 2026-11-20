<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use DOMElement;
use Automattic\BlocksEngine\PhpTransformer\Css\CssFluidLength;

/** Resolve only normal block flow and explicit fractional grids; unknown layouts fail closed. */
final class DeclarativeProseColumns
{
    public static function collect(DOMElement $body, StyleResolver $resolver): array
    {
        $columns = array();
        foreach (array(390, 1440) as $viewport) {
            $best = null;
            $visit = function (DOMElement $element, float $left, float $available, bool $inGrid = false) use (&$visit, &$best, $resolver, $viewport): void {
                $properties = array('display', 'position', 'visibility', 'width', 'max-width', 'padding', 'padding-left', 'padding-right', 'margin', 'margin-left', 'margin-right', 'margin-inline', 'grid-template-columns', 'gap', 'column-gap');
                $s = $resolver->authorViewport->atViewport($element, $properties, $viewport);
                $tag = strtolower($element->tagName);
                if (in_array($tag, array('header', 'footer', 'nav', 'aside', 'form'), true) || $element->hasAttribute('hidden') || in_array($s['display'] ?? '', array('none', 'flex', 'inline-flex'), true) || in_array($s['position'] ?? '', array('absolute', 'fixed'), true) || 'hidden' === ($s['visibility'] ?? '')) return;
                $length = static fn(string $value): ?float => CssFluidLength::evaluate($value, $viewport, 900, $available);
                $width = isset($s['width']) ? $length($s['width']) : $available;
                if (null === $width) return;
                // Native paragraphs occupy their containing block's column;
                // paragraph max-width is not a core/paragraph layout support.
                if ('p' !== $tag && isset($s['max-width']) && null !== ($max = $length($s['max-width']))) $width = min($width, $max);
                $margin = preg_split('/\s+/', trim($s['margin'] ?? '0'));
                $ml = $s['margin-left'] ?? $s['margin-inline'] ?? ($margin[3] ?? $margin[1] ?? $margin[0]);
                $left += 'auto' === $ml ? max(0, ($available - $width) / 2) : ($length($ml) ?? 0);
                $padding = preg_split('/\s+/', trim($s['padding'] ?? '0'));
                $pl = $length($s['padding-left'] ?? ($padding[3] ?? $padding[1] ?? $padding[0])) ?? 0;
                $pr = $length($s['padding-right'] ?? ($padding[1] ?? $padding[0])) ?? 0;
                $left += $pl;
                $width -= $pl + $pr;
                if ($inGrid && 'p' === $tag && '' !== trim($element->textContent) && $width > ($best['width'] ?? 0) && $left >= 0 && $left + $width <= $viewport + 1) $best = array('viewport' => $viewport, 'left' => $left, 'width' => $width);
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
                    if (null === $tracks) $visit($child, $left, $width, $inGrid);
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
        return $columns;
    }
}
