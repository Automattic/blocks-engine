<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;

/**
 * Viewport truth of `@container style(--name: value)` conditions.
 *
 * A style query reads a custom property on the query container instead of
 * the viewport, so a viewport-only evaluator treats every rule behind one as
 * inapplicable. Builders that switch presets this way (one wrapper sets
 * `--preset: desktop`, and `--preset: mobile` under a width query; component
 * rules then key off `@container style(--preset: mobile)`) leave the whole
 * responsive contract behind those queries, so the engine would read neither
 * preset as ever applying.
 *
 * Per declaring selector, the last declaration whose own conditions hold at
 * a width wins. When every selector declaring the queried property agrees on
 * that value, the value is fixed by the viewport alone and the query holds
 * exactly when it equals the queried one. Selectors that disagree depend on
 * which element is the container, and a declaration behind a condition this
 * class cannot evaluate has no known value at all; both stay unknown, which
 * callers treat as not applying, as before.
 */
final class ContainerStyleQueries
{
    /** @var array<string, list<array{selector: string, value: string, conditions: list<string>}>> */
    private array $declarations = array();

    public function __construct(string $css)
    {
        if ( ! str_contains($css, '@container') || ! str_contains($css, 'style(') ) {
            return;
        }
        $names = array();
        if ( preg_match_all('/@container\b[^{]*\bstyle\(\s*(--[A-Za-z0-9_-]+)\s*:/', $css, $matches) ) {
            $names = array_fill_keys($matches[1], true);
        }
        if ( array() === $names ) {
            return;
        }
        (new CssStylesheetTransformer())->visitStyleRules($css, function (string $prelude, string $body, array $conditions) use ($names): void {
            foreach ( CssRuleAnalyzer::declarationList($body) as [ 'name' => $name, 'value' => $value ] ) {
                if ( ! isset($names[$name]) ) {
                    continue;
                }
                $this->declarations[$name][] = array(
                    'selector' => trim((string) preg_replace('/\s+/', ' ', $prelude)),
                    'value' => trim((string) preg_replace('/\s*!\s*important\s*$/i', '', $value)),
                    'conditions' => array_values(array_map('strval', $conditions)),
                );
            }
        });
    }

    /**
     * The condition stacks under which the property a style query reads is
     * declared: the viewport queries that can switch the query's truth.
     *
     * @return list<list<string>>
     */
    public function switchingConditions(string $condition): array
    {
        if ( 1 !== preg_match('/^@container\s+style\(\s*(--[A-Za-z0-9_-]+)\s*:/i', trim($condition), $query) ) {
            return array();
        }

        return array_values(array_filter(array_column($this->declarations[$query[1]] ?? array(), 'conditions')));
    }

    /**
     * Whether a `@container style(...)` condition holds at a viewport width;
     * null when the condition is not a single unnamed custom-property style
     * query, or its property's value is not a function of the viewport.
     *
     * @param callable(list<string>): bool $conditionsApply Viewport truth of a declaration's own conditions.
     */
    public function applies(string $condition, callable $conditionsApply): ?bool
    {
        if ( 1 !== preg_match('/^@container\s+style\(\s*(--[A-Za-z0-9_-]+)\s*:\s*([^()]*?)\s*\)\s*$/i', trim($condition), $query) ) {
            return null;
        }
        $declarations = $this->declarations[$query[1]] ?? array();
        if ( array() === $declarations ) {
            return null;
        }
        $values = array();
        foreach ( $declarations as $declaration ) {
            foreach ( $declaration['conditions'] as $own ) {
                if ( 1 === preg_match('/^@container\b/i', trim($own)) ) {
                    return null;
                }
            }
            if ( array() === $declaration['conditions'] || $conditionsApply($declaration['conditions']) ) {
                $values[$declaration['selector']] = $declaration['value'];
            }
        }
        // Each declaring selector names a possible query container; the
        // answer is known only when every one of them agrees at this width.
        if ( count($values) !== count(array_unique(array_column($declarations, 'selector'))) || 1 !== count(array_unique($values)) ) {
            return null;
        }
        $value = reset($values);

        return null !== $value && $value === $query[2];
    }
}
