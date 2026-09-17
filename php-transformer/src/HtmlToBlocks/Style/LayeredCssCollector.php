<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

/**
 * Collects {@see CascadeRule} contributions and orders the resulting
 * stylesheet by each rule's declared {@see CascadeLayer} — stable within a
 * layer — instead of by the order contributions were collected in.
 *
 * This is deliberately the only place cascade position is decided. A caller
 * declares what layer a contribution belongs to; it never decides where
 * that contribution lands relative to another caller's.
 */
final class LayeredCssCollector
{
    /** @var list<CascadeRule> */
    private array $rules = array();

    public function add(CascadeLayer $layer, string $css): void
    {
        if ('' === $css) {
            return;
        }
        $this->rules[] = new CascadeRule($layer, $css);
    }

    /** @param iterable<string> $css */
    public function addAll(CascadeLayer $layer, iterable $css): void
    {
        foreach ($css as $rule) {
            $this->add($layer, $rule);
        }
    }

    /** Absorb rules an emitter already tagged with their own layer. @param iterable<CascadeRule> $rules */
    public function absorb(iterable $rules): void
    {
        foreach ($rules as $rule) {
            if ('' === $rule->css) {
                continue;
            }
            $this->rules[] = $rule;
        }
    }

    /**
     * The collected CSS, ordered by declared layer rank ascending and
     * stable (insertion order preserved) within a layer.
     *
     * @return list<string>
     */
    public function orderedCss(): array
    {
        $ordered = $this->rules;
        usort($ordered, static fn (CascadeRule $left, CascadeRule $right): int => $left->layer->value <=> $right->layer->value);

        return array_map(static fn (CascadeRule $rule): string => $rule->css, $ordered);
    }
}
