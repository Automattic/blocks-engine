<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

use Automattic\BlocksEngine\PhpTransformer\WordPress\SourceClassIdentity;

/**
 * Drops stylesheet selector arms that require a class or id the site never
 * carries.
 *
 * A theme stylesheet shared by many sites styles layouts, widgets and states
 * this site never renders. Projection then multiplies each such arm into
 * padded, editor and native-block alternatives. An arm that requires (outside
 * any functional pseudo-class) a class or id named by no source document,
 * script or other non-stylesheet file matched nothing on the source site, so
 * it is dropped with all its alternatives.
 *
 * Tokens the converter or WordPress mint are not source evidence and never
 * decide an arm: `wp-`, `is-`, `has-`, editor and engine names. Neither do
 * state tokens that route-state projection strips from the served item.
 * A string ending in `-` or `_` keeps every token it may build live. An arm with an escaped or non-ASCII identifier is
 * kept.
 */
final class UnreachableSelectorPruner
{
    private const MINTED = '/^(?:wp-|is-|has-|blocks-engine-|be-|editor-|block-editor-|components-)/';

    /** @var array<string, true> */
    private array $live = array();
    /** @var list<string> */
    private array $livePrefixes = array();

    /** @param list<string> $texts Every non-stylesheet file of the site, including its metadata. */
    public function __construct(array $texts)
    {
        $prefixes = array();
        foreach ($texts as $text) {
            if (preg_match_all('/[\w-]+/', $text, $matches)) $this->live += array_fill_keys($matches[0], true);
            if (preg_match_all('/[A-Za-z][\w-]*[-_](?=["\'`$+])/', $text, $matches)) $prefixes += array_fill_keys($matches[0], true);
        }
        $this->livePrefixes = array_keys($prefixes);
    }

    public function prune(string $css): string
    {
        $pruned = (new CssStylesheetTransformer())->transformStyleRules($css, function (string $prelude, string $body): string {
            $arms = CssStylesheetTransformer::splitSelectorList($prelude);
            if (null === $arms) return $prelude . '{' . $body . '}';
            $kept = array_values(array_filter($arms, fn (string $arm): bool => $this->reachable($arm)));
            if (count($kept) === count($arms)) return $prelude . '{' . $body . '}';
            // Comments ahead of the rule (licenses, source maps) stay in place.
            $comments = preg_match('#^(?:\s*/\*.*?\*/)+\s*#s', $prelude, $leading) ? $leading[0] : '';
            if (array() === $kept) return $comments;
            if ('' !== $comments && !str_starts_with($kept[0], $comments)) $kept[0] = $comments . ltrim($kept[0]);
            return implode(',', $kept) . '{' . $body . '}';
        });
        if ($pruned === $css) return $css;
        // Grouping rules whose every rule was dropped go with them.
        do {
            $pruned = (string) preg_replace('/@(?:-webkit-)?(?:media|supports|container|layer|document)\b[^{};]*\{\s*\}/i', '', $pruned, -1, $count);
        } while (0 < $count);
        return $pruned;
    }

    public function reachable(string $selector): bool
    {
        $selector = (string) preg_replace('#/\*.*?\*/#s', ' ', $selector);
        if (str_contains($selector, '\\') || 1 === preg_match('/[^\x20-\x7E\t\n\r]/', $selector)) return true;
        foreach (self::requiredTokens($selector) as $token) {
            if (!$this->sourceNamed($token)) return false;
        }
        return true;
    }

    private function sourceNamed(string $token): bool
    {
        if (isset($this->live[$token]) || 1 === preg_match(self::MINTED, $token) || 1 === preg_match('/^' . SourceClassIdentity::REVEAL_STATE . '$/i', $token)
            || SourceClassIdentity::containsCurrentStateToken($token)) return true;
        foreach ($this->livePrefixes as $prefix) {
            if (str_starts_with($token, $prefix)) return true;
        }
        return false;
    }

    /**
     * Class and id names every element the selector matches must carry:
     * those outside functional pseudo-class arguments, attribute selectors
     * and strings.
     *
     * @return list<string>
     */
    private static function requiredTokens(string $selector): array
    {
        $outside = '';
        $depth = 0;
        $quote = null;
        $length = strlen($selector);
        for ($i = 0; $i < $length; ++$i) {
            $char = $selector[$i];
            if (null !== $quote) {
                if ($char === $quote) $quote = null;
                continue;
            }
            if ('"' === $char || "'" === $char) $quote = $char;
            elseif ('(' === $char || '[' === $char) ++$depth;
            elseif (')' === $char || ']' === $char) $depth = max(0, $depth - 1);
            elseif (0 === $depth) {
                $outside .= $char;
                continue;
            }
            $outside .= ' ';
        }
        preg_match_all('/[.#]([\w-]+)/', $outside, $matches);
        return $matches[1];
    }
}
