<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Keeps authored fixed layers out of flow despite Core's editor block reset. */
final class EditorCanvasPositioning
{
    public function supportCss(string $stylesheet): string
    {
        $fixedSelectors = array();
        (new CssStylesheetTransformer())->visitStyleRules($stylesheet, static function (string $prelude, string $body) use (&$fixedSelectors): void {
            foreach (CssRuleAnalyzer::declarations($body, array('position')) as $declaration) {
                if (1 === preg_match('/^fixed\s*(?:!important)?$/i', trim($declaration['value']))) {
                    foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
                        $fixedSelectors[trim($selector)] = true;
                    }
                }
            }
        });
        if (array() === $fixedSelectors) {
            return '';
        }
        $rules = array();
        (new CssStylesheetTransformer())->visitStyleRules($stylesheet, static function (string $prelude, string $body, array $ancestors) use (&$rules, $fixedSelectors): void {
            $declarations = CssRuleAnalyzer::declarations($body, array('position'));
            if (array() === $declarations) {
                return;
            }
            $position = array();
            foreach ($declarations as $declaration) {
                $position[] = 'position:' . $declaration['value'];
            }
            $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
            $selectors = array_values(array_filter($selectors ?? array(), static fn (string $selector): bool => isset($fixedSelectors[trim($selector)])));
            if (array() === $selectors) {
                return;
            }
            foreach ($selectors as &$selector) {
                // Two scope classes plus the body type beat Core's two-class
                // reset even for a universal author selector. No frontend match.
                $selector = 'body.editor-styles-wrapper.editor-styles-wrapper :is(' . trim($selector) . ')';
            }
            unset($selector);
            $rule = implode(',', $selectors) . '{' . implode(';', $position) . '}';
            foreach (array_reverse($ancestors) as $ancestor) {
                $rule = $ancestor . '{' . $rule . '}';
            }
            $rules[] = $rule;
        });

        // Keep later and conditional position overrides for the same subjects.
        return implode("\n", $rules);
    }
}
