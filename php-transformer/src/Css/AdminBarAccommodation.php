<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Builds logged-in-only offsets for authored viewport-anchored layers. */
final class AdminBarAccommodation
{
    private const MAX_RULES = 100;

    public function supportCss(string $stylesheet): string
    {
        $rules = array();
        (new CssStylesheetTransformer())->visitStyleRules($stylesheet, static function (string $prelude, string $body, array $ancestors) use (&$rules): void {
            if (count($rules) >= self::MAX_RULES) {
                return;
            }

            $declarations = CssRuleAnalyzer::declarations($body, array('position', 'top'));
            $values = array();
            foreach ($declarations as $declaration) {
                $important = self::isImportant($declaration['value']);
                if (!isset($values[$declaration['name']]) || $important || !$values[$declaration['name']]['important']) {
                    $values[$declaration['name']] = array('value' => self::withoutImportant($declaration['value']), 'important' => $important);
                }
            }
            if (!in_array(strtolower($values['position']['value'] ?? ''), array('fixed', 'sticky'), true)) {
                return;
            }

            $top = trim($values['top']['value'] ?? '');
            if ('' === $top || 'auto' === strtolower($top)) {
                return;
            }

            $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
            if (null === $selectors) {
                return;
            }
            foreach ($selectors as $selector) {
                $selector = trim($selector);
                $key = implode("\0", $ancestors) . "\0" . $selector . "\0" . $top;
                if ('' === $selector || isset($rules[$key])) {
                    continue;
                }
                $rules[$key] = array('selector' => $selector, 'top' => $top, 'ancestors' => $ancestors);
                if (count($rules) >= self::MAX_RULES) {
                    break;
                }
            }
        });

        $css = array();
        foreach ($rules as $rule) {
            $top = '0' === trim($rule['top']) || '+0' === trim($rule['top']) || '-0' === trim($rule['top']) ? '0px' : $rule['top'];
            $ruleCss = self::adminBarSelector($rule['selector']) . '{top:calc((' . $top . ') + var(--wp-admin--admin-bar--height, 32px))!important}';
            foreach (array_reverse($rule['ancestors']) as $ancestor) {
                $ruleCss = $ancestor . '{' . $ruleCss . '}';
            }
            $css[] = $ruleCss;
        }

        return implode("\n", $css);
    }

    /**
     * Scope a selector to `body.admin-bar` while keeping its subject: a leading
     * `body` compound gains `.admin-bar`, and a leading `html`/`:root` compound
     * keeps its place as the root ahead of the body scope.
     */
    public static function adminBarSelector(string $selector): string
    {
        $selector = trim($selector);
        [$first, $rest] = self::splitFirstCompound($selector);
        if (self::compoundStartsWith($first, 'body')) {
            return 'body.admin-bar' . substr($first, 4) . $rest;
        }
        if (!self::compoundStartsWith($first, 'html') && !self::compoundStartsWith($first, ':root')) {
            return 'body.admin-bar ' . $selector;
        }

        $trimmedRest = ltrim($rest);
        if ('' === $trimmedRest) {
            return $first . ':has(> body.admin-bar)';
        }
        $combinator = '';
        if (in_array($trimmedRest[0], array('>', '+', '~'), true)) {
            $combinator = $trimmedRest[0];
            $trimmedRest = ltrim(substr($trimmedRest, 1));
        }
        if ('' === $combinator || '>' === $combinator) {
            [$second, $after] = self::splitFirstCompound($trimmedRest);
            if (self::compoundStartsWith($second, 'body')) {
                return $first . ('' === $combinator ? ' ' : ' > ') . 'body.admin-bar' . substr($second, 4) . $after;
            }
        }

        return $first . ' body.admin-bar ' . ('' === $combinator ? '' : $combinator . ' ') . $trimmedRest;
    }

    /** @return array{0: string, 1: string} The leading compound and everything after it. */
    private static function splitFirstCompound(string $selector): array
    {
        $length = strlen($selector);
        $depth = 0;
        $quote = null;
        for ($offset = 0; $offset < $length; ++$offset) {
            $character = $selector[$offset];
            if ('\\' === $character) {
                ++$offset;
                continue;
            }
            if (null !== $quote) {
                if ($character === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ('"' === $character || "'" === $character) {
                $quote = $character;
            } elseif ('(' === $character || '[' === $character) {
                ++$depth;
            } elseif ((')' === $character || ']' === $character) && $depth > 0) {
                --$depth;
            } elseif (0 === $depth && (ctype_space($character) || '>' === $character || '+' === $character || '~' === $character)) {
                return array(substr($selector, 0, $offset), substr($selector, $offset));
            }
        }

        return array($selector, '');
    }

    private static function compoundStartsWith(string $compound, string $name): bool
    {
        if (0 !== strncasecmp($compound, $name, strlen($name))) {
            return false;
        }
        $next = substr($compound, strlen($name), 1);

        return '' === $next || 1 !== preg_match('/[-_a-zA-Z0-9(\\\\]|[^\\x00-\\x7f]/', $next);
    }

    private static function withoutImportant(string $value): string
    {
        return trim((string) preg_replace('/\s*!important\s*$/i', '', $value));
    }

    private static function isImportant(string $value): bool
    {
        return 1 === preg_match('/\s*!important\s*$/i', $value);
    }
}
