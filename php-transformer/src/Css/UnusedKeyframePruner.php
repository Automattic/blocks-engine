<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Remove animation definitions only when no published consumer names them. */
final class UnusedKeyframePruner
{
    private string $references;
    private bool $dynamic;

    /** @param list<string> $stylesheets @param list<string> $evidence */
    public function __construct(array $stylesheets, array $evidence)
    {
        // Keep all value text, including custom properties and inline/runtime
        // sources. A reference in another sheet or conditional branch counts.
        $this->references = implode("\n", $evidence);
        $this->dynamic = (bool) preg_match('/\.\s*(?:animationName|animation)\s*=\s*(?![\'"\s])|(?:setProperty|insertRule|replaceSync)\s*\(/', $this->references);
        foreach ($stylesheets as $css) {
            (new CssStylesheetTransformer())->visitStyleRules($css, function (string $selector, string $body): void {
                // Escaped values can name an animation without spelling its
                // identifier literally. Retain the inventory when uncertain.
                if (str_contains($body, '\\')) $this->dynamic = true;
            });
            $this->references .= "\n" . $this->rewrite($css, true);
        }
    }

    public function prune(string $css): string
    {
        // Dynamic animation API consumers cannot prove a name absent.
        if ($this->dynamic) return $css;
        return $this->rewrite($css, false);
    }

    private function rewrite(string $css, bool $inventory): string
    {
        $result = '';
        foreach ((new CssStylesheetTransformer())->splitStyleRuleBody($css) as $part) {
            if (isset($part['declarations'])) { $result .= $part['declarations']; continue; }
            $prelude = $part['prelude'];
            $body = $part['body'];
            if (preg_match('/^\s*@(?:-webkit-)?keyframes\s+([\w-]+)\s*$/iD', $prelude, $match)) {
                if ($inventory || !preg_match('/(?<![\w-])' . preg_quote($match[1], '/') . '(?![\w-])/', $this->references)) continue;
            } elseif (in_array($part['at_rule'], array('media', 'supports', 'layer', 'container', 'document'), true)) {
                $body = $this->rewrite($body, $inventory);
            }
            $result .= $prelude . '{' . $body . '}';
        }
        return $result;
    }
}
