<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

/**
 * A uniform grid over CIELAB that answers "which indexed colours could lie
 * within `radius` of this one" from the 27 cells around it.
 *
 * Any two points within the radius differ by at most the radius on every
 * axis, so they fall in the same or adjacent cells; cells are made a hair
 * wider than the radius so floating-point division cannot push such a pair
 * two cells apart. The grid only narrows candidates: callers still measure
 * the exact distance, so a lookup selects exactly what a scan of every
 * indexed colour would.
 */
final class ColorGrid
{
    private readonly float $cell;

    /** @var array<string,list<int>> */
    private array $cells = array();

    public function __construct(float $radius)
    {
        $this->cell = $radius * (1 + 1e-9);
    }

    /** @param array{0:float,1:float,2:float} $lab */
    public function add(int $index, array $lab): void
    {
        [$l, $a, $b] = $this->coordinates($lab);
        $this->cells[$l . ':' . $a . ':' . $b][] = $index;
    }

    /**
     * Indexes in the cells around the colour, unordered.
     *
     * @param array{0:float,1:float,2:float} $lab
     * @return list<int>
     */
    public function near(array $lab): array
    {
        [$l, $a, $b] = $this->coordinates($lab);
        $near = array();
        for ($dl = -1; $dl <= 1; ++$dl) {
            for ($da = -1; $da <= 1; ++$da) {
                for ($db = -1; $db <= 1; ++$db) {
                    foreach ($this->cells[($l + $dl) . ':' . ($a + $da) . ':' . ($b + $db)] ?? array() as $index) $near[] = $index;
                }
            }
        }
        return $near;
    }

    /**
     * @param array{0:float,1:float,2:float} $lab
     * @return array{0:int,1:int,2:int}
     */
    private function coordinates(array $lab): array
    {
        return array((int) floor($lab[0] / $this->cell), (int) floor($lab[1] / $this->cell), (int) floor($lab[2] / $this->cell));
    }
}
