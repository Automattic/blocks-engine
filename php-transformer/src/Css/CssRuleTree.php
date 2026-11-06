<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Immutable source offsets from one tokenization, including opaque declaration blocks. */
final class CssRuleTree
{
    public readonly bool $wellFormed;

    /** @var array<int, int> */
    private array $ends = array();

    public function blockEnd(int $opening): ?int
    {
        return $this->ends[$opening] ?? null;
    }

    /** @var list<int> Packed start, boundary, end, next-index records. */
    private array $rules = array();

    /** @var array<int, int> First record by enclosing brace (-1 for root). */
    private array $heads = array();

    /** @var array<int, int> Last record by enclosing brace. */
    private array $tails = array();

    private function append(int $scope, int $start, int $boundary, int $end): void
    {
        $index = count($this->rules);
        if (isset($this->tails[$scope])) $this->rules[$this->tails[$scope] + 3] = $index;
        else $this->heads[$scope] = $index;
        $this->tails[$scope] = $index;
        array_push($this->rules, $start, $boundary, $end, -1);
    }

    public function __construct(public readonly string $source)
    {
        $state = CssSyntaxScanner::state();
        $stack = array();
        $scope = -1;
        $start = 0;
        $valid = true;
        $length = strlen($source);
        for ($offset = 0; $offset < $length;) {
            $offset = CssSyntaxScanner::inertEnd($source, $offset, $state, '{};');
            if ($offset >= $length) break;
            $top = CssSyntaxScanner::isTopLevel($state);
            $next = CssSyntaxScanner::consume($source, $offset, $state);
            // A stray `)` or `]` is an ordinary token to CSS Syntax: it
            // invalidates only its declaration or prelude, never the block
            // structure. Unquoted `url(data:…%23var(--x)…)` ends at the first
            // `)` and leaves one behind, as Google Sites' stylesheets do.
            if (null === $next && (')' === $source[$offset] || ']' === $source[$offset])) $next = $offset + 1;
            if (null === $next) { $valid = false; break; }
            if ($top && $next === $offset + 1) {
                if ('{' === $source[$offset]) {
                    $stack[] = array($scope, $start, $offset);
                    $scope = $offset;
                    $start = $offset + 1;
                } elseif ('}' === $source[$offset]) {
                    if (!$stack) { $valid = false; break; }
                    [$scope, $start, $boundary] = array_pop($stack);
                    $this->ends[$boundary] = $offset;
                    $this->append($scope, $start, $boundary, $offset);
                    $start = $offset + 1;
                } elseif (';' === $source[$offset]) {
                    $this->append($scope, $start, $offset, $offset);
                    $start = $offset + 1;
                }
            }
            $offset = $next;
        }
        $this->tails = array();
        $this->wellFormed = $valid && !$stack && CssSyntaxScanner::isComplete($state);
    }

    public function nextBoundary(int $offset): ?int
    {
        foreach ($this->rules() as $rule) {
            if ($rule['start'] >= $offset) return $rule['boundary'];
        }
        return null;
    }

    /** @return iterable<array{start:int, boundary:int, end:int}> Ephemeral visitor records, not retained nodes. */
    public function rules(int $scope = -1): iterable
    {
        for ($index = $this->heads[$scope] ?? -1; -1 !== $index; $index = $this->rules[$index + 3]) {
            yield array('start' => $this->rules[$index], 'boundary' => $this->rules[$index + 1], 'end' => $this->rules[$index + 2]);
        }
    }
}
