<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/**
 * Removes definition inventories (`@keyframes`, `@font-face`) that no
 * published consumer names, once all stylesheets and runtime sources are known.
 *
 * Names are collected first and each consumer source is scanned on its own,
 * so pruning never holds a concatenated copy of every evidence source.
 */
final class UnusedDefinitionPruner
{
    /** @var array<string, bool> Keyframe name => referenced. */
    private array $keyframes = array();

    /** @var array<string, bool> Lowercase font family => referenced. */
    private array $fonts = array();

    private bool $keyframesUncertain = false;
    private bool $fontsUncertain = false;

    /** @param list<string> $stylesheets @param list<string> $evidence Markup and runtime sources. */
    public function __construct(array $stylesheets, array $evidence)
    {
        foreach ($stylesheets as $css) {
            $this->walk($css, function (string $kind, ?string $name): void {
                if ('keyframes' === $kind) $this->keyframes[(string) $name] ??= false;
                elseif (null !== $name) $this->fonts[$name] ??= false;
            });
        }
        if (array() === $this->keyframes && array() === $this->fonts) return;
        foreach ($stylesheets as $css) {
            // Include opaque at-rules too: @property initial-value, for
            // example, can supply an animation name through var(). Only the
            // definitions themselves are not evidence of a consumer: an
            // animation body may name another animation or a font family.
            $removals = array();
            $this->walk($css, static function (string $kind, ?string $name, int $start, int $boundary, int $end) use (&$removals): void {
                $removals[] = 'keyframes' === $kind ? array($start, $boundary) : array($start, $end + 1);
            });
            foreach (array_reverse($removals) as [$start, $end]) $css = substr_replace($css, '', $start, $end - $start);
            // Decode CSS escapes before checking names, including consumers
            // such as animation-name: sp\69 n. Selector escapes are harmless.
            $this->observe((string) preg_replace_callback('/\\\\(?:[0-9a-fA-F]{1,6}\\s?|[^\\r\\n\\f])/', static function (array $match): string {
                $escape = substr($match[0], 1);
                return preg_match('/^[0-9a-fA-F]/', $escape) ? mb_chr((int) hexdec(trim($escape)), 'UTF-8') : $escape;
            }, $css));
        }
        foreach ($evidence as $text) {
            // Dynamically constructed runtime names cannot prove absence.
            // Keep the inventory rather than guessing at those consumers.
            if (preg_match('/(?:animate|setProperty|insertRule|replaceSync)\s*\(/', $text)) $this->keyframesUncertain = true;
            if (preg_match('/FontFace\s*\(|insertRule\s*\(|replaceSync\s*\(|\.fonts\b|\.fontFamily\s*=(?!=)|setProperty\s*\(\s*(?!(["\'])(?!font)[a-z-]+\1)/i', $text)) $this->fontsUncertain = true;
            $this->observe($text);
        }
    }

    public function prune(string $css): string
    {
        $removals = array();
        $this->walk($css, function (string $kind, ?string $name, int $start, int $boundary, int $end) use (&$removals): void {
            $unused = 'keyframes' === $kind
                ? !$this->keyframesUncertain && false === ($this->keyframes[$name] ?? true)
                : !$this->fontsUncertain && null !== $name && false === ($this->fonts[$name] ?? true);
            if ($unused) $removals[] = array($start, $end + 1);
        });
        foreach (array_reverse($removals) as [$start, $end]) $css = substr_replace($css, '', $start, $end - $start);
        return $css;
    }

    private function observe(string $text): void
    {
        foreach ($this->keyframes as $name => $used) {
            // A name is an identifier: `enter` inside `center` or
            // `slide-enter` names no animation.
            if (!$used && str_contains($text, $name) && preg_match('/(?<![\w-])' . preg_quote($name, '/') . '(?![\w-])/', $text)) $this->keyframes[$name] = true;
        }
        foreach ($this->fonts as $name => $used) {
            if (!$used && false !== stripos($text, $name)) $this->fonts[$name] = true;
        }
    }

    /**
     * Visits each definition at-rule with its `@` offset, block boundary and
     * end. A font face reports null, and is kept, unless it embeds its payload
     * and its family reads plainly: a face with a url() or local() source
     * costs a few bytes and nothing is fetched until a consumer uses it.
     *
     * @param callable(string, ?string, int, int, int): void $visit
     */
    private function walk(string $css, callable $visit): void
    {
        if (!str_contains($css, '@')) return;
        $tree = new CssRuleTree($css);
        if (!$tree->wellFormed) return;
        $transformer = new CssStylesheetTransformer();
        $walk = function (int $scope) use (&$walk, $tree, $css, $visit, $transformer): void {
            foreach ($tree->rules($scope) as $rule) {
                if ('{' !== $css[$rule['boundary']]) continue;
                $prelude = substr($css, $rule['start'], $rule['boundary'] - $rule['start']);
                $plain = trim(CssSyntaxScanner::withoutComments($prelude));
                // Preserve comments (including licenses) preceding the rule.
                $at = strripos($prelude, '@');
                $start = false === $at ? $rule['start'] : $rule['start'] + $at;
                if (preg_match('/^@(?:-webkit-)?keyframes\s+([a-zA-Z_][\w-]*)$/D', $plain, $match)) {
                    $visit('keyframes', $match[1], $start, $rule['boundary'], $rule['end']);
                } elseif (preg_match('/^@font-face$/Di', $plain)) {
                    $visit('font-face', $this->family(substr($css, $rule['boundary'] + 1, $rule['end'] - $rule['boundary'] - 1)), $start, $rule['boundary'], $rule['end']);
                } elseif ($transformer->nestsStyleRules($plain)) {
                    $walk($rule['boundary']);
                }
            }
        };
        $walk(-1);
    }

    private function family(string $body): ?string
    {
        if (!preg_match('/url\(\s*["\']?\s*data:/i', $body)) return null;
        $family = null;
        foreach (CssRuleAnalyzer::declarationList($body) as ['name' => $name, 'value' => $value]) {
            if ('font-family' === strtolower($name)) $family = trim($value);
        }
        if (null === $family) return null;
        if (preg_match('/^(["\'])(.*)\1$/sD', $family, $quoted)) $family = $quoted[2];
        // Escapes, lists and quotes make the consumer spelling uncertain.
        return '' === $family || preg_match('/[\\\\,"\']/', $family) ? null : strtolower($family);
    }
}
