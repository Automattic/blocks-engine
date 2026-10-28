<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/**
 * Restores source body defaults suppressed by core's body rule.
 *
 * Core also resets the UA's 8px body margin on the frontend and in the editor.
 * Restore only sides the resolved author cascade leaves unset; an authored
 * margin, including zero, must remain in control.
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

    /** @param array<string, string>|null $bodyDeclarations Resolved source author declarations. */
    public function supportCss(string $stylesheet, ?array $bodyDeclarations = null): string
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

        $css = $layered && ! $unlayered ? self::RULE : '';
        if (null !== $bodyDeclarations && ! isset($bodyDeclarations['margin'])) {
            $margin = '';
            foreach (array('top', 'right', 'bottom', 'left') as $side) {
                $property = 'margin-' . $side;
                if (! isset($bodyDeclarations[$property])) {
                    $margin .= $property . ':8px;';
                }
            }
            if ('' !== $margin) {
                $css .= 'body,body.editor-styles-wrapper{' . rtrim($margin, ';') . '}';
            }
        }

        return $css;
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
