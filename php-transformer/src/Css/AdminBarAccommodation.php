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
            $ruleCss = 'body.admin-bar ' . $rule['selector'] . '{top:calc((' . $top . ') + var(--wp-admin--admin-bar--height, 32px))!important}';
            foreach (array_reverse($rule['ancestors']) as $ancestor) {
                $ruleCss = $ancestor . '{' . $ruleCss . '}';
            }
            $css[] = $ruleCss;
        }

        return implode("\n", $css);
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
