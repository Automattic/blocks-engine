<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Remove animation inventories only when the whole site's consumers prove absence. */
final class UnusedKeyframePruner
{
    private string $evidence;
    private bool $uncertain = false;

    /** @param list<string> $stylesheets @param list<string> $evidence */
    public function __construct(array $stylesheets, array $evidence)
    {
        foreach ($stylesheets as $css) {
            $tree = new CssRuleTree($css);
            if (!$tree->wellFormed) $this->uncertain = true;
            $ranges = $this->definitions($tree);
            // Keep declaration bodies as evidence too: custom properties and
            // even animation declarations can occur inside keyframes.
            foreach (array_reverse($ranges) as $range) {
                $css = substr_replace($css, substr($css, $range['boundary'] + 1, $range['end'] - $range['boundary'] - 1), $range['start'], $range['end'] - $range['start'] + 1);
            }
            $evidence[] = $css;
        }
        $this->evidence = implode("\n", $evidence);
        // Computed runtime names cannot prove absence. Escaped animation
        // values can spell an otherwise absent name; utility selector escapes
        // elsewhere in the stylesheet are not animation consumers.
        if (preg_match('/(?:animationName|animation)\s*=|setProperty\s*\(\s*["\']animation(?:-name)?["\']/i', $this->evidence)) $this->uncertain = true;
        foreach ($stylesheets as $css) {
            (new CssStylesheetTransformer())->visitStyleRules($css, function (string $selector, string $body): void {
                foreach (CssRuleAnalyzer::declarationList($body) as $declaration) {
                    if (preg_match('/^(?:-webkit-)?animation(?:-name)?$/i', $declaration['name']) && str_contains($declaration['value'], '\\')) $this->uncertain = true;
                }
            });
        }
    }

    /** @return list<array{start:int,boundary:int,end:int,name:string}> */
    private function definitions(CssRuleTree $tree, int $scope = -1): array
    {
        $ranges = array();
        foreach ($tree->rules($scope) as $rule) {
            $prelude = preg_replace('#/\*.*?\*/#s', '', substr($tree->source, $rule['start'], $rule['boundary'] - $rule['start'])) ?? '';
            if (preg_match('/^\s*@(?:-webkit-)?keyframes\s+([a-zA-Z_][\w-]*)\s*$/D', $prelude, $match)) {
                $ranges[] = $rule + array('name' => $match[1]);
            } elseif (preg_match('/^\s*@(?:media|supports|container|layer|document)\b/i', $prelude) && '{' === $tree->source[$rule['boundary']]) {
                $ranges = array_merge($ranges, $this->definitions($tree, $rule['boundary']));
            }
        }
        return $ranges;
    }

    public function prune(string $css): string
    {
        if ($this->uncertain) return $css;
        $tree = new CssRuleTree($css);
        if (!$tree->wellFormed) return $css;
        foreach (array_reverse($this->definitions($tree)) as $range) {
            // Substring matching deliberately retains quoted animation names
            // and references in other declaration APIs.
            if (str_contains($this->evidence, $range['name'])) continue;
            $css = substr_replace($css, '', $range['start'], $range['end'] - $range['start'] + 1);
        }
        return $css;
    }
}
