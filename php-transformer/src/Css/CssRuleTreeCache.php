<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Compiler-owned memo. The dynamic context is restored even when compilation throws. */
final class CssRuleTreeCache
{
    private static ?self $current = null;

    /** @var array<string, CssRuleTree> */
    private array $trees = array();

    /** @var array<string, int> */
    private array $counts = array();

    public function tree(string $stylesheet): CssRuleTree
    {
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
        $previous = self::$current;
        self::$current = $this;
        try {
            return $operation();
        } finally {
            self::$current = $previous;
        }
    }

    /** @return array<string, int> Tokenizations per exact source, for contract instrumentation. */
    public function tokenizations(): array
    {
        return $this->counts;
    }
}
