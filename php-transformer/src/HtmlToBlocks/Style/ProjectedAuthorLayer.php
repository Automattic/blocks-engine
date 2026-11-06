<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

/** Let the author's layers, rather than Core's unlayered defaults, decide projected properties. */
final class ProjectedAuthorLayer
{
    public function rule(string $css): CascadeRule
    {
        $rules = array();
        $unlayered = array();
        $transformer = new CssStylesheetTransformer();
        $transformer->visitStyleRules($css, static function (string $selector, string $body, array $ancestors) use (&$rules, &$unlayered): void {
            $layered = (bool) preg_grep('/^@layer\b/i', $ancestors);
            preg_match_all('/(?:^|;)\s*([\w-]+)\s*:/', $body, $matches);
            foreach ($matches[1] as $property) {
                if (str_starts_with($property, '--')) continue;
                if (!$layered) {
                    $unlayered[$property][] = $selector;
                } else {
                    $rules[] = array($selector, $property, $ancestors);
                }
            }
        });
        $result = '';
        foreach ($rules as [$prelude, $property, $ancestors]) {
            $selectors = array();
            foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
                // Only engine-bound native markup, not arbitrary authored CSS.
                if (!str_contains($selector, '.wp-block-') || str_contains($selector, '::')) continue;
                $exclude = $unlayered[$property] ?? array();
                $selectors[] = ':root :is(' . $selector . ')' . (array() === $exclude ? '' : ':not(:where(' . implode(',', array_unique($exclude)) . '))');
            }
            if (array() === $selectors) continue;
            $rule = implode(',', $selectors) . '{' . $property . ':revert-layer}';
            foreach (array_reverse($ancestors) as $ancestor) {
                if (!preg_match('/^@layer\b/i', $ancestor)) $rule = $ancestor . '{' . $rule . '}';
            }
            $result .= $rule;
        }
        return new CascadeRule(CascadeLayer::SOURCE_STYLE_PROJECTION, $result);
    }
}
