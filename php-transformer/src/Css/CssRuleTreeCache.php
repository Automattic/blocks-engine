<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Only explicitly registered shared author sources survive an operation. */
final class CssRuleTreeCache
{
    private static ?self $current = null;

    /** @var array<string, CssRuleTree> */
    private array $trees = array();

    /** @var array<string, int> Counts have the same lifetime as their trees. */
    private array $counts = array();

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
