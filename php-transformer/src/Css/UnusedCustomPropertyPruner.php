<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Prunes unused reset tokens, preserving component and editor APIs. */
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

    private function isInventory(string $selector): bool
    {
        // A prelude carries the comments ahead of its rule (a framework's
        // license banner usually precedes its `:root` inventory); they do not
        // change which elements the selector matches.
        $selector = CssSyntaxScanner::withoutComments($selector);
        if (in_array(trim($selector), array(':root', ':where(:root) .editor-styles-wrapper', ':root *.editor-styles-wrapper'), true)) return true;
        // Universal resets may initialize pseudo-elements too. Require every
        // arm to be a universal subject, rather than accepting a component
        // selector merely because it shares a list with `*`.
        $arms = explode(',', trim($selector));
        foreach ($arms as $arm) {
            if (!preg_match('/^(?:\*|\*?::?(?:before|after)|\*?::backdrop)$/D', trim($arm))) return false;
        }
        return true;
    }

    public function prune(string $css): string
    {
        if ($this->uncertain) return $css;
        return (new CssStylesheetTransformer())->transformStyleRules($css, function (string $selector, string $body): string {
            $declarations = CssRuleAnalyzer::declarationList($body);
            // Component properties are an authored styling API even without a
            // captured reader. Only root and universal reset inventories are
            // eligible, never class-, state- or component-scoped declarations. Keep
            // WordPress presets (including isolated source presets) for editor
            // consumers, and compound values whose binding must stay intact.
            $inventory = $this->isInventory($selector);
            $kept = array_filter($declarations, fn (array $declaration): bool => !$inventory
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
