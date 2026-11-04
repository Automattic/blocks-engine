<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Shared author trees and bounded flat syntax memos live with the compiler. */
final class CssRuleTreeCache
{
    private static ?self $current = null;

    /** @var array<string, CssRuleTree> */
    private array $trees = array();

    /** @var array<string, int> Counts have the same lifetime as their trees. */
    private array $counts = array();

    /** @var array<string, array<string, array<string|int, string|int>|int>> */
    private array $strings = array();
    private int $memoEntries = 0;
    private int $memoScalars = 0;
    private int $memoBytes = 0;

    /**
     * Derived syntax is shared across pages, but never retains nested records,
     * callbacks or page state. Budgets bound even compiles with unique inputs.
     * Variants separate every non-source argument; source keys are exact bytes.
     *
     * @param callable(): (array<string|int, string|int>|int) $parse
     * @return array<string|int, string|int>|int
     */
    public static function memo(string $variant, string $source, callable $parse): array|int
    {
        $cache = self::$current;
        if (null === $cache) return $parse();
        $cache = $cache->shared ?? $cache;
        if (isset($cache->strings[$variant][$source])) return $cache->strings[$variant][$source];
        $result = $parse();
        $scalars = is_array($result) ? count($result) : 1;
        $bytes = strlen($source) + strlen($variant);
        foreach (is_array($result) ? $result : array($result) as $key => $value) {
            $bytes += (is_string($key) ? strlen($key) : 0) + (is_string($value) ? strlen($value) : 0);
        }
        if ($cache->memoEntries < 16384 && $cache->memoScalars + $scalars <= 131072 && $cache->memoBytes + $bytes <= 4194304) {
            $cache->strings[$variant][$source] = $result;
            ++$cache->memoEntries;
            $cache->memoScalars += $scalars;
            $cache->memoBytes += $bytes;
        }
        return $result;
    }

    public function __construct(private ?self $shared = null)
    {
    }

    /** Register exact shared-plan source bytes, never transformed or page-owned CSS. */
    public function retain(string $stylesheet): void
    {
        if (isset($this->trees[$stylesheet])) return;
        $this->trees[$stylesheet] = self::$current?->trees[$stylesheet] ?? new CssRuleTree($stylesheet);
        $this->counts[$stylesheet] = self::$current?->counts[$stylesheet] ?? 1;
    }

    public function tree(string $stylesheet): CssRuleTree
    {
        if (isset($this->shared->trees[$stylesheet])) return $this->shared->trees[$stylesheet];
        if (!isset($this->trees[$stylesheet])) {
            $this->counts[$stylesheet] = ($this->counts[$stylesheet] ?? 0) + 1;
            $this->trees[$stylesheet] = new CssRuleTree($stylesheet);
        }
        return $this->trees[$stylesheet];
    }

    public static function parse(string $stylesheet, ?self $fallback = null): CssRuleTree
    {
        return (self::$current ?? $fallback ?? new self())->tree($stylesheet);
    }

    public function run(callable $operation): mixed
    {
        if (self::$current?->shared === $this) return $operation();
        return $this->runPage($operation);
    }

    /** A fresh memo even inside a batch; finally releases intermediates on errors too. */
    public function runPage(callable $operation): mixed
    {
        $previous = self::$current;
        self::$current = new self($this);
        try {
            return $operation();
        } finally {
            self::$current = $previous;
        }
    }

    /** @return array<string, int> Shared-source tokenizations only; no page source is retained for instrumentation. */
    public function tokenizations(): array
    {
        return $this->counts;
    }
}
