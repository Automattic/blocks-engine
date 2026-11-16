<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Prunes unused literal root tokens, preserving component and editor APIs. */
final class UnusedCustomPropertyPruner
{
    /** @var array<string, true> */
    private array $used = array();
    private bool $uncertain = false;

    /** @param list<string> $stylesheets @param list<string> $evidence Markup and runtime sources. */
    public function __construct(array $stylesheets, array $evidence)
    {
        // Count even references in dead declarations. This deliberately avoids
        // changing fallback/cycle semantics and is conservative across sheets.
        foreach ($stylesheets as $css) {
            if (preg_match_all('/var\(\s*(--[\w-]+)/', $css, $references)) $this->used += array_fill_keys($references[1], true);
            (new CssStylesheetTransformer())->visitStyleRules($css, function (string $selector, string $body): void {
                $this->remember($selector);
                if (str_contains($body, '\\')) $this->uncertain = true;
                foreach (CssRuleAnalyzer::declarationList($body) as $declaration) $this->remember($declaration['value']);
            });
            // At-rules can read properties too (e.g. style queries).
            if (preg_match_all('/@[^{};]+/', $css, $rules)) foreach ($rules[0] as $rule) $this->remember($rule);
        }
        foreach ($evidence as $text) {
            $this->remember($text);
            // A runtime can construct property names; do not infer absence
            // when its custom-property API arguments need evaluation.
            $dynamic = preg_replace('/(?:setProperty|getPropertyValue|removeProperty)\s*\(\s*(["\'])(?:--)?[a-zA-Z][\w-]*\1\s*(?=[,)])/', '', $text) ?? $text;
            if (preg_match('/(?:setProperty|getPropertyValue|removeProperty)\s*\(/', $dynamic)) $this->uncertain = true;
        }
    }

    private function remember(string $text): void
    {
        if (preg_match_all('/--[\w-]+/', $text, $names)) $this->used += array_fill_keys($names[0], true);
    }

    public function prune(string $css): string
    {
        if ($this->uncertain) return $css;
        return (new CssStylesheetTransformer())->transformStyleRules($css, function (string $selector, string $body): string {
            $declarations = CssRuleAnalyzer::declarationList($body);
            // Component properties are an authored styling API even without a
            // captured reader. Only root token inventories are eligible. Keep
            // WordPress presets (including isolated source presets) for editor
            // consumers, and compound values whose binding must stay intact.
            $rootInventory = in_array(trim($selector), array(':root', ':where(:root) .editor-styles-wrapper'), true);
            $kept = array_filter($declarations, fn (array $declaration): bool => !$rootInventory
                || !str_starts_with($declaration['name'], '--')
                || str_starts_with($declaration['name'], '--wp--')
                || str_starts_with($declaration['name'], '--be--source--preset--')
                || !preg_match('/^[#a-zA-Z0-9.+%-]+$/D', trim($declaration['value']))
                || isset($this->used[$declaration['name']]));
            if (count($kept) === count($declarations)) return $selector . '{' . $body . '}';
            if (array() === $kept) return '';
            return $selector . '{' . implode(';', array_map(static fn (array $declaration): string => $declaration['name'] . ':' . $declaration['value'], $kept)) . '}';
        });
    }
}
