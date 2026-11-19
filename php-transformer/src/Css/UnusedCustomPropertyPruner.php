<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/**
 * Prunes unused reset tokens and property registrations, preserving component
 * and editor APIs.
 *
 * A registration (`@property --name`) defines a property; it is not a reader.
 * Once nothing reads the name, neither its reset declaration nor its
 * registration changes any computed value, so both go.
 */
final class UnusedCustomPropertyPruner
{
    /** @var array<string, true> */
    private array $used = array();
    private bool $uncertain = false;
    /** @var array<string, array<string, true>> Namespace => names a root inventory declares in it. */
    private array $namespaces = array();

    /**
     * @param list<string> $stylesheets
     * @param list<string> $evidence Markup and runtime sources.
     * @param bool $complete Whether the stylesheets and evidence are every
     *     reader the site will ship. A component-scoped property is then
     *     still kept as the author's styling API, unless its name belongs to
     *     a namespace the author's root inventory declares (`--x-` in
     *     `:root{--x-primary:…;--x-body-color:…}`). Such a framework's
     *     component properties are plumbing between its own rules, like its
     *     root tokens: once every reader is known, one nothing reads computes
     *     nothing and goes, in any rule.
     */
    public function __construct(array $stylesheets, array $evidence, private bool $complete = false)
    {
        // Count even references in dead declarations. This deliberately avoids
        // changing fallback/cycle semantics and is conservative across sheets.
        foreach ($stylesheets as $css) {
            if (preg_match_all('/var\(\s*(--[\w-]+)/', $css, $references)) foreach ($references[1] as $name) $this->used[$name] = true;
            (new CssStylesheetTransformer())->visitStyleRules($css, function (string $selector, string $body): void {
                $this->remember($selector);
                if (str_contains($body, '\\')) $this->uncertain = true;
                $inventory = $this->isInventory($selector);
                foreach (CssRuleAnalyzer::declarationList($body) as $declaration) {
                    $this->remember($declaration['value']);
                    if ($inventory && null !== ($namespace = self::namespaceOf($declaration['name']))) $this->namespaces[$namespace][$declaration['name']] = true;
                }
            });
            // At-rules can read properties too (e.g. style queries). A
            // registration's prelude only names the property it defines.
            if (preg_match_all('/@[^{};]+/', $css, $rules)) foreach ($rules[0] as $rule) {
                if (!preg_match('/^@property\s+--[\w-]+\s*$/Di', $rule)) $this->remember($rule);
            }
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
        // Set keys in place: a property union (`+=`) copies the whole set.
        if (preg_match_all('/--[\w-]+/', $text, $names)) foreach ($names[0] as $name) $this->used[$name] = true;
    }

    /** The leading segment of a `--ns-name` property; WordPress and engine names have none. */
    private static function namespaceOf(string $name): ?string
    {
        if (!preg_match('/^--([a-zA-Z][a-zA-Z0-9]*)-[a-zA-Z0-9]/', $name, $match)) return null;
        return in_array(strtolower($match[1]), array('wp', 'be', 'blocks'), true) ? null : $match[1];
    }

    /** Whether the property is in a namespace the root inventory declares two or more tokens in. */
    private function inInventoryNamespace(string $name): bool
    {
        $namespace = self::namespaceOf($name);
        return null !== $namespace && count($this->namespaces[$namespace] ?? array()) > 1;
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
        // selector merely because it shares a list with `*`. A root token
        // inventory may also name the shadow host (`:root,:host`).
        $arms = explode(',', trim($selector));
        foreach ($arms as $arm) {
            if (!preg_match('/^(?:\*|\*?::?(?:before|after)|\*?::backdrop|:root|:host)$/D', trim($arm))) return false;
        }
        return true;
    }

    public function prune(string $css): string
    {
        if ($this->uncertain) return $css;
        return $this->withoutUnusedRegistrations((new CssStylesheetTransformer())->transformStyleRules($css, function (string $selector, string $body): string {
            $declarations = CssRuleAnalyzer::declarationList($body);
            // Component properties are an authored styling API even without a
            // captured reader. Only root and universal reset inventories are
            // eligible, never class-, state- or component-scoped declarations. Keep
            // WordPress presets (including isolated source presets) for editor
            // consumers. A value's shape does not matter: a font stack, a
            // gradient or an `r,g,b` triple nothing reads computes nothing,
            // and every name a value references is itself counted as read.
            // An unread empty reset value is removable too; a read empty
            // value still suppresses var() fallbacks and must be retained.
            // The final pass (complete) narrows to framework namespaces: root
            // inventories were already pruned while sheets were compiled.
            $inventory = !$this->complete && $this->isInventory($selector);
            $kept = array_filter($declarations, fn (array $declaration): bool => !($inventory || ($this->complete && $this->inInventoryNamespace($declaration['name'])))
                || !str_starts_with($declaration['name'], '--')
                || str_starts_with($declaration['name'], '--wp--')
                || str_starts_with($declaration['name'], '--be--source--preset--')
                || isset($this->used[$declaration['name']]));
            if (count($kept) === count($declarations)) return $selector . '{' . $body . '}';
            if (array() === $kept) return '';
            return $selector . '{' . implode(';', array_map(static fn (array $declaration): string => $declaration['name'] . ':' . $declaration['value'], $kept)) . '}';
        }));
    }

    /** Drops `@property` registrations of names no stylesheet, markup or runtime reads. */
    private function withoutUnusedRegistrations(string $css): string
    {
        if (false === stripos($css, '@property')) return $css;
        $tree = new CssRuleTree($css);
        if (!$tree->wellFormed) return $css;
        $transformer = new CssStylesheetTransformer();
        $removals = array();
        $walk = function (int $scope) use (&$walk, &$removals, $tree, $css, $transformer): void {
            foreach ($tree->rules($scope) as $rule) {
                if ('{' !== $css[$rule['boundary']]) continue;
                $prelude = substr($css, $rule['start'], $rule['boundary'] - $rule['start']);
                $plain = trim(CssSyntaxScanner::withoutComments($prelude));
                if (preg_match('/^@property\s+(--[\w-]+)$/Di', $plain, $match)) {
                    if (str_starts_with($match[1], '--wp--') || isset($this->used[$match[1]])) continue;
                    // Comments (licenses) ahead of the registration stay.
                    $at = strripos($prelude, '@');
                    $removals[] = array(false === $at ? $rule['start'] : $rule['start'] + $at, $rule['end'] + 1);
                } elseif ($transformer->nestsStyleRules($plain)) {
                    $walk($rule['boundary']);
                }
            }
        };
        $walk(-1);
        foreach (array_reverse($removals) as [$start, $end]) $css = substr_replace($css, '', $start, $end - $start);
        return $css;
    }
}
