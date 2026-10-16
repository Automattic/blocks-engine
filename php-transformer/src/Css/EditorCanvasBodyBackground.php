<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/**
 * Hands the editor canvas `body` background back to a layered author rule.
 *
 * Gutenberg's editor iframe document starts with an unlayered fallback,
 * `:where(body){margin:0;background-color:white}`. Unlayered declarations win
 * over every layered one whatever their specificity, so an author who paints
 * the document inside a cascade layer (Tailwind's `@layer base{body{…}}`) gets
 * the right ground on the frontend, where nothing competes, and a white canvas
 * in the editor, where everything drawn for a dark ground disappears.
 *
 * `revert-layer` in an unlayered rule rolls the property back to the layered
 * author cascade, which restores the frontend's precedence. It is emitted only
 * when a layered author rule paints `body` and no unlayered author rule does:
 * an unlayered author background already beats the zero-specificity fallback,
 * and without any layered background the fallback is the right answer.
 */
final class EditorCanvasBodyBackground
{
    public const RULE = ':root body.editor-styles-wrapper{background-color:revert-layer}';

    public function supportCss(string $stylesheet): string
    {
        $layered = false;
        $unlayered = false;
        (new CssStylesheetTransformer())->visitStyleRules($stylesheet, static function (string $prelude, string $body, array $ancestors) use (&$layered, &$unlayered): void {
            if (array() === CssRuleAnalyzer::declarations($body, array('background', 'background-color')) || ! self::targetsBody($prelude)) {
                return;
            }
            foreach ($ancestors as $ancestor) {
                if (1 === preg_match('/^@layer\b/i', $ancestor)) {
                    $layered = true;
                    return;
                }
            }
            $unlayered = true;
        });

        return $layered && ! $unlayered ? self::RULE : '';
    }

    /** Whether a selector list contains a selector whose subject is the document body. */
    private static function targetsBody(string $prelude): bool
    {
        foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
            if (1 === preg_match('/^(?:(?:html|:root)\s*>?\s*)?body(?=$|[.#:\[])/i', trim($selector))) {
                return true;
            }
        }

        return false;
    }
}
