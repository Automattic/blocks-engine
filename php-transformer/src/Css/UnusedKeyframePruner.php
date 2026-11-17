<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Remove animation inventories only when the whole site's consumers prove absence. */
final class UnusedKeyframePruner
{
    /** @var array<string,true> Names defined somewhere and referenced by a consumer. */
    private array $referenced = array();
    private bool $uncertain = false;

    /**
     * Evidence is scanned piece by piece and only the referenced name set is
     * retained: joining whole-site markup and every stylesheet into one string
     * would hold a second copy of the site for the rest of compilation.
     *
     * @param list<string> $stylesheets @param list<string> $evidence
     */
    public function __construct(array $stylesheets, array $evidence)
    {
        $names = array();
        $consumers = array();
        foreach ($stylesheets as $css) {
            $tree = new CssRuleTree($css);
            if (!$tree->wellFormed) $this->uncertain = true;
            $ranges = $this->definitions($tree);
            foreach ($ranges as $range) $names[$range['name']] = true;
            // Keep declaration bodies as evidence too: custom properties and
            // even animation declarations can occur inside keyframes.
            $consumer = $css;
            foreach (array_reverse($ranges) as $range) {
                $consumer = substr_replace($consumer, substr($consumer, $range['boundary'] + 1, $range['end'] - $range['boundary'] - 1), $range['start'], $range['end'] - $range['start'] + 1);
            }
            $consumers[] = $consumer;
            // Escaped animation values can spell an otherwise absent name;
            // utility selector escapes elsewhere are not animation consumers.
            (new CssStylesheetTransformer())->visitStyleRules($css, function (string $selector, string $body): void {
                foreach (CssRuleAnalyzer::declarationList($body) as $declaration) {
                    if (preg_match('/^(?:-webkit-)?animation(?:-name)?$/i', $declaration['name']) && str_contains($declaration['value'], '\\')) $this->uncertain = true;
                }
            });
        }
        if ($this->uncertain || array() === $names) return;
        foreach (array($consumers, $evidence) as $pieces) {
            foreach ($pieces as $piece) {
                // Computed runtime names cannot prove absence.
                if (preg_match('/(?:animationName|animation)\s*=|setProperty\s*\(\s*["\']animation(?:-name)?["\']/i', $piece)) {
                    $this->uncertain = true;
                    return;
                }
                foreach ($names as $name => $_) {
                    if (str_contains($piece, (string) $name)) {
                        $this->referenced[$name] = true;
                        unset($names[$name]);
                    }
                }
                if (array() === $names) return;
            }
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
            if (isset($this->referenced[$range['name']])) continue;
            $css = substr_replace($css, '', $range['start'], $range['end'] - $range['start'] + 1);
        }
        return $css;
    }
}
