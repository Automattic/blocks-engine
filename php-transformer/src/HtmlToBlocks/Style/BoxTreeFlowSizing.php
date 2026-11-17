<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

/**
 * Restate captured horizontal sizes that the box's container decided, as the
 * container-relative values that decided them (ADR 0005 §4).
 *
 * A used width is a pixel snapshot of one viewport. Frozen into the base
 * carrier rule, the widest capture's width also applies at every width
 * between captures, so a 1440px-wide `<main>` overflows a 768px viewport.
 * Where the evidence proves, at a capture, that the container set the size,
 * that capture's value is replaced by the container-relative equivalent, which
 * reproduces the capture exactly and follows the container elsewhere:
 *
 * - An in-flow block-level box of a block container whose border box plus
 *   horizontal margins equals the container's content width fills it:
 *   `width:auto`. When every such capture has equal side margins that are
 *   zero or that centre the box at its pixel `max-width`, the margins are
 *   `auto` and the `max-width` stays on the carrier.
 * - A grid item whose margin box spans exactly the tracks it sits on fills
 *   its grid area: `width:100%` (border-box, no margins) or `width:auto`.
 * - Pixel column tracks that, with the gaps, fill the grid's content width
 *   are proportional to it: `repeat(n, minmax(0, 1fr))` when equal, one
 *   `minmax(0, <px>fr)` per track otherwise.
 *
 * Replaced elements keep their pixels: an auto width there is intrinsic.
 */
final class BoxTreeFlowSizing
{
    private const TOLERANCE = 0.5;

    private const BLOCK_CONTAINERS = array('block', 'flow-root', 'list-item');
    private const BLOCK_LEVEL = array('block', 'flow-root', 'list-item', 'flex', 'grid');
    private const REPLACED = array('img', 'video', 'audio', 'canvas', 'iframe', 'embed', 'object', 'svg', 'input', 'select', 'textarea', 'button', 'picture', 'meter', 'progress');
    /** Blocks whose carrier lands on a non-replaced wrapper of the replaced source element. */
    private const FIGURE_BLOCKS = array('core/image', 'core/video', 'core/audio', 'core/embed');

    /**
     * @param string $sourceTag Tag of the source element the block comes from.
     * @param array<string, array<string, mixed>> $observations Captured observations of the element, by viewport key.
     * @param array<string, array<string, mixed>> $parents Captured observations of its parent, by viewport key.
     * @return array<string, array<string, string>> Viewport key => property => container-relative value.
     */
    public function lift(string $sourceTag, string $blockName, array $observations, array $parents): array
    {
        $replaced = in_array(strtolower($sourceTag), self::REPLACED, true) && !in_array($blockName, self::FIGURE_BLOCKS, true);
        $values = $fills = array();
        foreach ($observations as $key => $observation) {
            $styles = $observation['used_styles'] ?? array();
            $box = $observation['border_box'] ?? null;
            if (!is_array($styles) || !is_array($box)) continue;
            $tracks = self::fluidTracks($styles, $box);
            if (null !== $tracks) $values[$key]['grid-template-columns'] = $tracks;
            if ($replaced) continue;
            $parent = $parents[$key] ?? null;
            $fill = is_array($parent) ? self::fill($styles, $box, $parent) : null;
            if (null !== $fill) {
                $values[$key]['width'] = $fill;
                $fills[$key] = 'auto' === $fill && in_array(strtolower((string) ($parent['used_styles']['display'] ?? '')), self::BLOCK_CONTAINERS, true);
            }
        }
        return self::withAutoMargins($values, $fills, $observations);
    }

    /**
     * Auto side margins reproduce every block-flow fill capture when the
     * margins are equal and either zero or the room left beside the box at
     * its pixel `max-width`; one capture must actually centre the box.
     *
     * @param array<string, array<string, string>> $values
     * @param array<string, bool> $fills Fill captures by key: true where the container is a block container.
     */
    private static function withAutoMargins(array $values, array $fills, array $observations): array
    {
        $centred = false;
        foreach ($fills as $key => $blockFlow) {
            if (!$blockFlow) return $values;
            $styles = $observations[$key]['used_styles'];
            $left = self::px($styles['margin-left'] ?? null);
            $right = self::px($styles['margin-right'] ?? null);
            $max = self::px($styles['max-width'] ?? null);
            if (null === $left || null === $right || abs($left - $right) > self::TOLERANCE) return $values;
            if ($left <= self::TOLERANCE) continue;
            if (null === $max || abs((float) $observations[$key]['border_box']['width'] - $max) > self::TOLERANCE) return $values;
            $centred = true;
        }
        if (!$centred) return $values;
        foreach ($fills as $key => $_) {
            $values[$key]['margin-left'] = $values[$key]['margin-right'] = 'auto';
            $values[$key]['max-width'] = (string) $observations[$key]['used_styles']['max-width'];
        }
        return $values;
    }

    /** The container-relative width that reproduces this capture, if the container set it. */
    private static function fill(array $styles, array $box, array $parent): ?string
    {
        $display = strtolower((string) ($styles['display'] ?? ''));
        if (!in_array($display, self::BLOCK_LEVEL, true)) return null;
        if (!in_array($styles['position'] ?? 'static', array('static', 'relative', 'sticky'), true) || 'none' !== ($styles['float'] ?? 'none')) return null;
        $parentStyles = $parent['used_styles'] ?? null;
        $parentBox = $parent['border_box'] ?? null;
        if (!is_array($parentStyles) || !is_array($parentBox)) return null;
        $left = self::px($styles['margin-left'] ?? null);
        $right = self::px($styles['margin-right'] ?? null);
        $content = self::contentBox($parentStyles, $parentBox);
        if (null === $left || null === $right || null === $content) return null;
        $outer = (float) $box['width'] + $left + $right;
        $parentDisplay = strtolower((string) ($parentStyles['display'] ?? ''));
        if (in_array($parentDisplay, self::BLOCK_CONTAINERS, true)) {
            return abs($outer - $content[1]) <= self::TOLERANCE ? 'auto' : null;
        }
        if ('grid' !== $parentDisplay) return null;
        if (!in_array($styles['justify-self'] ?? 'auto', array('auto', 'normal', 'stretch'), true)
            || !in_array($parentStyles['justify-items'] ?? 'normal', array('normal', 'legacy', 'stretch'), true)) return null;
        $tracks = self::pixelTracks((string) ($parentStyles['grid-template-columns'] ?? ''));
        $gap = self::px($parentStyles['column-gap'] ?? null) ?? (('normal' === ($parentStyles['column-gap'] ?? null)) ? 0.0 : null);
        if (null === $tracks || null === $gap) return null;
        // The margin box must start on one track line and end on another.
        $start = (float) $box['x'] - $left - $content[0];
        $end = $start + $outer;
        $line = 0.0; $starts = $ends = array();
        foreach ($tracks as $track) {
            $starts[] = $line;
            $ends[] = $line + $track;
            $line += $track + $gap;
        }
        $first = self::lineIndex($starts, $start);
        $last = self::lineIndex($ends, $end);
        if (null === $first || null === $last || $last < $first) return null;
        if ('border-box' === ($styles['box-sizing'] ?? '') && abs($left) <= self::TOLERANCE && abs($right) <= self::TOLERANCE) return '100%';
        // A non-replaced item with a preferred aspect ratio aligns as start, not stretch.
        return 'auto' === ($styles['aspect-ratio'] ?? 'auto') ? 'auto' : null;
    }

    /** Fluid column tracks for a grid whose pixel tracks and gaps fill its content width. */
    private static function fluidTracks(array $styles, array $box): ?string
    {
        if (!in_array(strtolower((string) ($styles['display'] ?? '')), array('grid', 'inline-grid'), true)) return null;
        $tracks = self::pixelTracks((string) ($styles['grid-template-columns'] ?? ''));
        $gap = self::px($styles['column-gap'] ?? null) ?? (('normal' === ($styles['column-gap'] ?? null)) ? 0.0 : null);
        $content = self::contentBox($styles, $box);
        if (null === $tracks || null === $gap || null === $content) return null;
        if (abs(array_sum($tracks) + $gap * (count($tracks) - 1) - $content[1]) > self::TOLERANCE * count($tracks)) return null;
        if (max($tracks) - min($tracks) <= self::TOLERANCE) return 'repeat(' . count($tracks) . ', minmax(0, 1fr))';
        return implode(' ', array_map(static fn(float $track): string => 'minmax(0, ' . self::number($track) . 'fr)', $tracks));
    }

    /** @return array{0: float, 1: float}|null Content-box left offset (absolute) and width. */
    private static function contentBox(array $styles, array $box): ?array
    {
        $parts = array();
        foreach (array('padding-left', 'padding-right') as $property) $parts[] = self::px($styles[$property] ?? null);
        foreach (array('border-left', 'border-right') as $property) {
            $value = (string) ($styles[$property] ?? '');
            $tokens = preg_split('/\s+/', strtolower(trim($value))) ?: array();
            $parts[] = in_array('none', $tokens, true) || in_array('hidden', $tokens, true) ? 0.0 : self::px($tokens[0] ?? null);
        }
        if (in_array(null, $parts, true) || !isset($box['x'], $box['width'])) return null;
        return array((float) $box['x'] + $parts[0] + $parts[2], (float) $box['width'] - array_sum($parts));
    }

    /** @return list<float>|null */
    private static function pixelTracks(string $value): ?array
    {
        $tracks = array();
        foreach (preg_split('/\s+/', trim($value)) ?: array() as $token) {
            $track = self::px($token);
            if (null === $track) return null;
            $tracks[] = $track;
        }
        return array() === $tracks ? null : $tracks;
    }

    /** @param list<float> $lines */
    private static function lineIndex(array $lines, float $position): ?int
    {
        foreach ($lines as $index => $line) {
            if (abs($line - $position) <= self::TOLERANCE) return $index;
        }
        return null;
    }

    private static function px(mixed $value): ?float
    {
        return is_string($value) && 1 === preg_match('/^-?(?:\d*\.)?\d+px$/', trim($value)) ? (float) trim($value) : null;
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
