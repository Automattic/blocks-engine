<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Removes animation inventories only after all published stylesheet consumers are known. */
final class UnusedKeyframePruner
{
    private string $references;
    private bool $uncertain = false;

    /** @param list<string> $stylesheets @param list<string> $evidence Markup and runtime sources. */
    public function __construct(array $stylesheets, array $evidence)
    {
        $references = $evidence;
        foreach ($stylesheets as $css) {
            // Include opaque at-rules too: @property initial-value, for
            // example, can supply an animation name through var(). Only the
            // definitions themselves are not evidence of a consumer.
            $consumers = $this->withoutDefinitions($css);
            // An animation can itself set a custom property used as another
            // animation's name. Its body is a consumer, not its own prelude.
            (new CssStylesheetTransformer())->visitKeyframeRules($css, static function (string $name, string $body) use (&$consumers): void {
                $consumers .= "\n" . $body;
            });
            // Decode CSS escapes before checking names, including consumers
            // such as animation-name: sp\\69 n. Selector escapes are harmless.
            $references[] = (string) preg_replace_callback('/\\\\(?:[0-9a-fA-F]{1,6}\\s?|[^\\r\\n\\f])/', static function (array $match): string {
                $escape = substr($match[0], 1);
                return preg_match('/^[0-9a-fA-F]/', $escape) ? mb_chr(hexdec(trim($escape)), 'UTF-8') : $escape;
            }, $consumers);
        }
        $this->references = implode("\n", $references);
        // Dynamically constructed runtime names cannot prove absence. Keep
        // the inventory rather than guessing at those consumers.
        $this->uncertain = $this->uncertain
            || (bool) preg_match('/(?:animate|setProperty|insertRule|replaceSync)\s*\(/', $this->references);
    }

    public function prune(string $css): string
    {
        return $this->uncertain ? $css : $this->withoutDefinitions($css, true);
    }

    private function withoutDefinitions(string $css, bool $onlyUnused = false): string
    {
        $tree = new CssRuleTree($css);
        if (!$tree->wellFormed) return $css;
        $removals = array();
        $walk = function (int $scope) use (&$walk, &$removals, $tree, $css, $onlyUnused): void {
            foreach ($tree->rules($scope) as $rule) {
                $prelude = substr($css, $rule['start'], $rule['boundary'] - $rule['start']);
                $plain = trim(CssSyntaxScanner::withoutComments($prelude));
                if (preg_match('/^@(?:-webkit-)?keyframes\s+([a-zA-Z_][\w-]*)$/D', $plain, $match)) {
                    if (!$onlyUnused || !str_contains($this->references, $match[1])) {
                        // Preserve comments (including licenses) preceding the rule.
                        $at = strripos($prelude, '@');
                        if (false !== $at) $removals[] = array($rule['start'] + $at, $rule['end'] + 1);
                    }
                } elseif ('{' === $css[$rule['boundary']] && (new CssStylesheetTransformer())->nestsStyleRules($plain)) {
                    $walk($rule['boundary']);
                }
            }
        };
        $walk(-1);
        foreach (array_reverse($removals) as [$start, $end]) $css = substr_replace($css, '', $start, $end - $start);
        return $css;
    }
}
