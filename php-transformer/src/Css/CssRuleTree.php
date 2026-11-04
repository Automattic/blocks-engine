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

    /** @var array<int, list<array{start:int, boundary:int, end:int}>> Rules by enclosing opening brace (-1 for the root). */
    private array $rules = array();

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
                    $this->rules[$scope][] = array('start' => $start, 'boundary' => $boundary, 'end' => $offset);
                    $start = $offset + 1;
                } elseif (';' === $source[$offset]) {
                    $this->rules[$scope][] = array('start' => $start, 'boundary' => $offset, 'end' => $offset);
                    $start = $offset + 1;
                }
            }
            $offset = $next;
        }
        $this->wellFormed = $valid && !$stack && CssSyntaxScanner::isComplete($state);
    }

    public function nextBoundary(int $offset): ?int
    {
        foreach ($this->rules() as $rule) {
            if ($rule['start'] >= $offset) return $rule['boundary'];
        }
        return null;
    }

    /** @return list<array{start:int, boundary:int, end:int}> */
    public function rules(int $scope = -1): array
    {
        return $this->rules[$scope] ?? array();
    }
}
