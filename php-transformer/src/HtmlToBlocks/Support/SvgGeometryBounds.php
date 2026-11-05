<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use DOMElement;

/** Conservative coordinate bounds (Bezier control hulls, not curve extrema). */
final class SvgGeometryBounds
{
    /** @return array{float,float,float,float}|null min-x, min-y, max-x, max-y */
    public static function of(DOMElement $svg): ?array
    {
        $points = array();
        foreach ($svg->getElementsByTagName('*') as $shape) {
            // Definitions do not paint. Transformed subtrees need a CTM, not raw coordinates.
            for ($node = $shape; $node !== $svg; $node = $node->parentNode) {
                if ($node->hasAttribute('transform') || in_array(strtolower($node->tagName), array('defs', 'clippath', 'mask', 'symbol', 'svg'), true)) continue 2;
            }
            $n = static fn (string $key, float $default = 0): float => is_numeric($shape->getAttribute($key)) ? (float) $shape->getAttribute($key) : $default;
            switch (strtolower($shape->tagName)) {
                case 'rect':
                    $points[] = array($n('x'), $n('y'));
                    $points[] = array($n('x') + $n('width'), $n('y') + $n('height'));
                    break;
                case 'circle':
                case 'ellipse':
                    $rx = $n('rx', $n('r')); $ry = $n('ry', $n('r'));
                    $points[] = array($n('cx') - $rx, $n('cy') - $ry);
                    $points[] = array($n('cx') + $rx, $n('cy') + $ry);
                    break;
                case 'line':
                    $points[] = array($n('x1'), $n('y1')); $points[] = array($n('x2'), $n('y2'));
                    break;
                case 'polygon':
                case 'polyline':
                    preg_match_all('/[-+]?(?:\d*\.\d+|\d+\.?\d*)(?:[eE][-+]?\d+)?/', $shape->getAttribute('points'), $matches);
                    for ($i = 0; $i + 1 < count($matches[0]); $i += 2) $points[] = array((float) $matches[0][$i], (float) $matches[0][$i + 1]);
                    break;
                case 'path':
                    $points = array_merge($points, self::path($shape->getAttribute('d')));
                    break;
            }
        }
        if (!$points) return null;
        return array(min(array_column($points, 0)), min(array_column($points, 1)), max(array_column($points, 0)), max(array_column($points, 1)));
    }

    private static function path(string $d): array
    {
        preg_match_all('/[a-zA-Z]|[-+]?(?:\d*\.\d+|\d+\.?\d*)(?:[eE][-+]?\d+)?/', $d, $matches);
        $tokens = $matches[0]; $points = array(); $x = $y = $sx = $sy = 0.0; $command = ''; $i = 0; $previous = ''; $cx = $cy = 0.0;
        $counts = array('M'=>2, 'L'=>2, 'H'=>1, 'V'=>1, 'C'=>6, 'S'=>4, 'Q'=>4, 'T'=>2, 'A'=>7);
        while ($i < count($tokens)) {
            if (ctype_alpha($tokens[$i])) {
                $command = $tokens[$i++];
                if (strtoupper($command) === 'Z') { $x = $sx; $y = $sy; continue; }
            }
            $upper = strtoupper($command); $count = $counts[$upper] ?? 0;
            // Elliptical arcs need rotated extrema, not coordinate pairs.
            if (!$count || $upper === 'A' || $i + $count > count($tokens)) return array();
            $values = array_slice($tokens, $i, $count);
            foreach ($values as $value) if (!is_numeric($value)) return array();
            $i += $count; $relative = $command !== $upper;
            if ($upper === 'H') $x = (float) $values[0] + ($relative ? $x : 0);
            elseif ($upper === 'V') $y = (float) $values[0] + ($relative ? $y : 0);
            else {
                $ox = $x; $oy = $y;
                if ($upper === 'S' || $upper === 'T') {
                    $reflect = $upper === 'S' ? in_array($previous, array('C', 'S'), true) : in_array($previous, array('Q', 'T'), true);
                    $points[] = array($reflect ? 2 * $x - $cx : $x, $reflect ? 2 * $y - $cy : $y);
                }
                for ($j = 0; $j < $count; $j += 2) {
                    $x = (float) $values[$j] + ($relative ? $ox : 0);
                    $y = (float) $values[$j + 1] + ($relative ? $oy : 0);
                    $points[] = array($x, $y);
                    if ($j === $count - 4) { $cx = $x; $cy = $y; }
                }
                if ($upper === 'T') { [$cx, $cy] = $points[count($points) - 2]; }
            }
            $previous = $upper;
            $points[] = array($x, $y);
            if ($upper === 'M') { $sx = $x; $sy = $y; $command = $relative ? 'l' : 'L'; }
        }
        return $points;
    }
}
