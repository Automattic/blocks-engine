<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Support;

/**
 * Remembers the results of a pure function of a string key.
 *
 * One transform walks the same stylesheet several times, and each walk asks
 * the CSS primitives the same questions about the same selectors and
 * declaration lists. Only a function whose result depends on nothing but its
 * key may be remembered. The oldest entry makes room once the capacity is
 * reached, so a long run keeps remembering what it currently reads.
 */
final class BoundedMemo
{
    /** @var array<string, mixed> */
    private array $values = array();

    public function __construct(private readonly int $capacity = 10000)
    {
    }

    /**
     * @template T
     * @param callable(): T $compute
     * @return T
     */
    public function remember(string $key, callable $compute): mixed
    {
        if ( array_key_exists($key, $this->values) ) {
            return $this->values[ $key ];
        }
        $value = $compute();
        if ( count($this->values) >= $this->capacity ) {
            unset($this->values[ array_key_first($this->values) ]);
        }
        return $this->values[ $key ] = $value;
    }
}
