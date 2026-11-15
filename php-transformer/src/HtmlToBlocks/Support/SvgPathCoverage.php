<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

/**
 * Rasterises filled SVG path data onto a sample grid so two glyphs can be
 * compared by the area they paint rather than by how their path text is
 * spelled (precision, start point, relative or absolute commands).
 */
final class SvgPathCoverage
{
    private const CURVE_STEPS = 16;


    /** Cells across the viewBox: finer than the 24-48px an icon renders at. */
    private const CELLS = 64;

    private const MEMO_LIMIT = 256;

    /** @var array<string, float|null> */
    private static array $differences = array();

    /** Samples per cell side; a cell's coverage is the share of its samples that are filled. */
    private const SUPERSAMPLE = 4;

    /**
     * The largest per-cell coverage difference (0..1) between two filled
     * paths, or null when either path cannot be parsed. A value of 0.25 means
     * no edge moved more than about a quarter of a cell.
     *
     * @param array{0:float,1:float,2:float,3:float} $viewBox
     */
    public static function maxCellDifference(string $a, string $aRule, string $b, string $bRule, array $viewBox): ?float
    {
        // Pages repeat the same icons, so the same comparison recurs across
        // documents. Results are scalars, so a small bounded memo is cheap.
        $key = hash('xxh128', serialize(array($a, $aRule, $b, $bRule, $viewBox)));
        if (array_key_exists($key, self::$differences)) return self::$differences[$key];
        if (count(self::$differences) >= self::MEMO_LIMIT) self::$differences = array();

        return self::$differences[$key] = self::computeMaxCellDifference($a, $aRule, $b, $bRule, $viewBox);
    }

    /** @param array{0:float,1:float,2:float,3:float} $viewBox */
    private static function computeMaxCellDifference(string $a, string $aRule, string $b, string $bRule, array $viewBox): ?float
    {
        $first = self::polygons($a);
        $second = self::polygons($b);
        if (null === $first || null === $second || $viewBox[2] <= 0 || $viewBox[3] <= 0) return null;
        $samples = self::CELLS * self::SUPERSAMPLE;
        $difference = array_fill(0, self::CELLS * self::CELLS, 0);
        for ($row = 0; $row < $samples; ++$row) {
            $y = $viewBox[1] + ($row + 0.5) * $viewBox[3] / $samples;
            $left = self::crossings($first, $y);
            $right = self::crossings($second, $y);
            $cellRow = intdiv($row, self::SUPERSAMPLE) * self::CELLS;
            for ($column = 0; $column < $samples; ++$column) {
                $x = $viewBox[0] + ($column + 0.5) * $viewBox[2] / $samples;
                $sign = (int) self::filled($left, $x, $aRule) - (int) self::filled($right, $x, $bRule);
                if (0 !== $sign) $difference[$cellRow + intdiv($column, self::SUPERSAMPLE)] += $sign;
            }
        }

        return max(array_map('abs', $difference)) / (self::SUPERSAMPLE * self::SUPERSAMPLE);
    }

    /** @return array<int,array<int,array{0:float,1:float}>>|null */
    private static function polygons(string $data): ?array
    {
        $polygons = array();
        $points = array();
        $offset = 0;
        $length = strlen($data);
        $x = $y = $startX = $startY = 0.0;
        $command = '';
        $controlX = $controlY = null;
        while (true) {
            self::skipSeparators($data, $offset);
            if ($offset >= $length) break;
            if (ctype_alpha($data[$offset])) {
                $command = $data[$offset++];
            } elseif ('' === $command || 'z' === strtolower($command)) {
                return null;
            }
            $relative = ctype_lower($command);
            $baseX = $relative ? $x : 0.0;
            $baseY = $relative ? $y : 0.0;
            switch (strtolower($command)) {
                case 'z':
                    if (array() !== $points) $polygons[] = $points;
                    $points = array();
                    $x = $startX;
                    $y = $startY;
                    $controlX = $controlY = null;
                    continue 2;
                case 'm':
                    $args = self::numbers($data, $offset, 2);
                    if (null === $args) return null;
                    if (count($points) > 1) $polygons[] = $points;
                    $x = $startX = $baseX + $args[0];
                    $y = $startY = $baseY + $args[1];
                    $points = array(array($x, $y));
                    // Further coordinate pairs are implicit line-tos.
                    $command = $relative ? 'l' : 'L';
                    $controlX = $controlY = null;
                    continue 2;
                case 'l':
                    $args = self::numbers($data, $offset, 2);
                    if (null === $args) return null;
                    $x = $baseX + $args[0];
                    $y = $baseY + $args[1];
                    $points[] = array($x, $y);
                    $controlX = $controlY = null;
                    continue 2;
                case 'h':
                    $args = self::numbers($data, $offset, 1);
                    if (null === $args) return null;
                    $x = $baseX + $args[0];
                    $points[] = array($x, $y);
                    $controlX = $controlY = null;
                    continue 2;
                case 'v':
                    $args = self::numbers($data, $offset, 1);
                    if (null === $args) return null;
                    $y = $baseY + $args[0];
                    $points[] = array($x, $y);
                    $controlX = $controlY = null;
                    continue 2;
                case 'c':
                case 's':
                    $smooth = 's' === strtolower($command);
                    $args = self::numbers($data, $offset, $smooth ? 4 : 6);
                    if (null === $args) return null;
                    if ($smooth) {
                        $x1 = null === $controlX ? $x : 2 * $x - $controlX;
                        $y1 = null === $controlY ? $y : 2 * $y - $controlY;
                        array_unshift($args, $x1 - $baseX, $y1 - $baseY);
                    }
                    [$x1, $y1, $x2, $y2] = array($baseX + $args[0], $baseY + $args[1], $baseX + $args[2], $baseY + $args[3]);
                    $endX = $baseX + $args[4];
                    $endY = $baseY + $args[5];
                    for ($step = 1; $step <= self::CURVE_STEPS; ++$step) {
                        $t = $step / self::CURVE_STEPS;
                        $u = 1 - $t;
                        $points[] = array(
                            $u * $u * $u * $x + 3 * $u * $u * $t * $x1 + 3 * $u * $t * $t * $x2 + $t * $t * $t * $endX,
                            $u * $u * $u * $y + 3 * $u * $u * $t * $y1 + 3 * $u * $t * $t * $y2 + $t * $t * $t * $endY,
                        );
                    }
                    [$controlX, $controlY, $x, $y] = array($x2, $y2, $endX, $endY);
                    continue 2;
                case 'q':
                case 't':
                    $smooth = 't' === strtolower($command);
                    $args = self::numbers($data, $offset, $smooth ? 2 : 4);
                    if (null === $args) return null;
                    if ($smooth) {
                        $x1 = null === $controlX ? $x : 2 * $x - $controlX;
                        $y1 = null === $controlY ? $y : 2 * $y - $controlY;
                    } else {
                        $x1 = $baseX + $args[0];
                        $y1 = $baseY + $args[1];
                        $args = array_slice($args, 2);
                    }
                    $endX = $baseX + $args[0];
                    $endY = $baseY + $args[1];
                    for ($step = 1; $step <= self::CURVE_STEPS; ++$step) {
                        $t = $step / self::CURVE_STEPS;
                        $u = 1 - $t;
                        $points[] = array($u * $u * $x + 2 * $u * $t * $x1 + $t * $t * $endX, $u * $u * $y + 2 * $u * $t * $y1 + $t * $t * $endY);
                    }
                    [$controlX, $controlY, $x, $y] = array($x1, $y1, $endX, $endY);
                    continue 2;
                case 'a':
                    $args = self::arcArguments($data, $offset);
                    if (null === $args) return null;
                    $endX = $baseX + $args[5];
                    $endY = $baseY + $args[6];
                    foreach (self::arcPoints($x, $y, $args[0], $args[1], $args[2], $args[3], $args[4], $endX, $endY) as $point) $points[] = $point;
                    $x = $endX;
                    $y = $endY;
                    $controlX = $controlY = null;
                    continue 2;
                default:
                    return null;
            }
        }
        if (count($points) > 1) $polygons[] = $points;

        return array() === $polygons ? null : $polygons;
    }

    private static function skipSeparators(string $data, int &$offset): void
    {
        $length = strlen($data);
        while ($offset < $length && (ctype_space($data[$offset]) || ',' === $data[$offset])) ++$offset;
    }

    /** @return array<int,float>|null */
    private static function numbers(string $data, int &$offset, int $count): ?array
    {
        $values = array();
        for ($index = 0; $index < $count; ++$index) {
            self::skipSeparators($data, $offset);
            if (1 !== preg_match('/\G[-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?/', $data, $match, 0, $offset)) return null;
            $values[] = (float) $match[0];
            $offset += strlen($match[0]);
        }

        return $values;
    }

    /** Arc flags are single digits that may be written without separators. @return array<int,float>|null */
    private static function arcArguments(string $data, int &$offset): ?array
    {
        $radii = self::numbers($data, $offset, 3);
        if (null === $radii) return null;
        $flags = array();
        for ($index = 0; $index < 2; ++$index) {
            self::skipSeparators($data, $offset);
            if (!isset($data[$offset]) || ('0' !== $data[$offset] && '1' !== $data[$offset])) return null;
            $flags[] = (float) $data[$offset++];
        }
        $end = self::numbers($data, $offset, 2);

        return null === $end ? null : array_merge($radii, $flags, $end);
    }

    /** Endpoint-to-centre conversion from the SVG implementation notes (F.6.5). @return array<int,array{0:float,1:float}> */
    private static function arcPoints(float $x1, float $y1, float $rx, float $ry, float $rotation, float $large, float $sweep, float $x2, float $y2): array
    {
        $rx = abs($rx);
        $ry = abs($ry);
        if (0.0 === $rx || 0.0 === $ry || ($x1 === $x2 && $y1 === $y2)) return array(array($x2, $y2));
        $phi = deg2rad($rotation);
        [$cos, $sin] = array(cos($phi), sin($phi));
        $dx = ($x1 - $x2) / 2;
        $dy = ($y1 - $y2) / 2;
        $px = $cos * $dx + $sin * $dy;
        $py = -$sin * $dx + $cos * $dy;
        $scale = ($px * $px) / ($rx * $rx) + ($py * $py) / ($ry * $ry);
        if ($scale > 1) {
            $rx *= sqrt($scale);
            $ry *= sqrt($scale);
        }
        $numerator = $rx * $rx * $ry * $ry - $rx * $rx * $py * $py - $ry * $ry * $px * $px;
        $factor = sqrt(max(0.0, $numerator / ($rx * $rx * $py * $py + $ry * $ry * $px * $px)));
        if ($large === $sweep) $factor = -$factor;
        $cx = $factor * $rx * $py / $ry;
        $cy = -$factor * $ry * $px / $rx;
        $centerX = $cos * $cx - $sin * $cy + ($x1 + $x2) / 2;
        $centerY = $sin * $cx + $cos * $cy + ($y1 + $y2) / 2;
        $start = atan2(($py - $cy) / $ry, ($px - $cx) / $rx);
        $delta = atan2((-$py - $cy) / $ry, (-$px - $cx) / $rx) - $start;
        if ($sweep && $delta < 0) $delta += 2 * M_PI;
        if (!$sweep && $delta > 0) $delta -= 2 * M_PI;
        $points = array();
        for ($step = 1; $step <= self::CURVE_STEPS; ++$step) {
            $angle = $start + $delta * $step / self::CURVE_STEPS;
            $ex = $rx * cos($angle);
            $ey = $ry * sin($angle);
            $points[] = array($cos * $ex - $sin * $ey + $centerX, $sin * $ex + $cos * $ey + $centerY);
        }

        return $points;
    }

    /**
     * Sorted edge crossings of a horizontal sample line with their winding direction.
     *
     * @param array<int,array<int,array{0:float,1:float}>> $polygons
     * @return array<int,array{0:float,1:int}>
     */
    private static function crossings(array $polygons, float $y): array
    {
        $crossings = array();
        foreach ($polygons as $points) {
            $count = count($points);
            for ($index = 0; $index < $count; ++$index) {
                [$ax, $ay] = $points[$index];
                [$bx, $by] = $points[($index + 1) % $count];
                if (($ay <= $y) === ($by <= $y)) continue;
                $crossings[] = array($ax + ($y - $ay) * ($bx - $ax) / ($by - $ay), $by > $ay ? 1 : -1);
            }
        }
        usort($crossings, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

        return $crossings;
    }

    /** @param array<int,array{0:float,1:int}> $crossings */
    private static function filled(array $crossings, float $x, string $rule): bool
    {
        $winding = 0;
        $count = 0;
        foreach ($crossings as [$crossing, $direction]) {
            if ($crossing >= $x) break;
            $winding += $direction;
            ++$count;
        }

        return 'evenodd' === $rule ? 1 === $count % 2 : 0 !== $winding;
    }
}
