<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/**
 * Lets authored sticky chrome stick inside a template part.
 *
 * A sticky element moves only within its parent. In the source a sticky header
 * sits in the page itself; as a shared template part it becomes a child of the
 * part's transport wrapper, which is exactly as tall as what it holds, so the
 * header has no room to move and scrolls away. The wrapper is not source
 * structure: on the front end it gives up its box (`display: contents`) when
 * it directly holds an authored sticky subject, which restores the source
 * containing block. The editor (its canvas body, or the admin body that hosts
 * a legacy canvas) keeps the wrapper, which carries the template part's
 * selection, and has no sticky scrolling to restore.
 */
final class TemplatePartStickyContainment
{
    private const MAX_RULES = 100;

    public function supportCss(string $stylesheet): string
    {
        $rules = array();
        (new CssStylesheetTransformer())->visitStyleRules($stylesheet, static function (string $prelude, string $body, array $ancestors) use (&$rules): void {
            if (count($rules) >= self::MAX_RULES) {
                return;
            }
            $position = null;
            $important = false;
            foreach (CssRuleAnalyzer::declarations($body, array('position')) as $declaration) {
                $isImportant = 1 === preg_match('/\s*!important\s*$/i', $declaration['value']);
                if (null === $position || $isImportant || !$important) {
                    $position = strtolower(trim((string) preg_replace('/\s*!important\s*$/i', '', $declaration['value'])));
                    $important = $isImportant;
                }
            }
            if ('sticky' !== $position && '-webkit-sticky' !== $position) {
                return;
            }
            foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
                $subject = self::compoundSubject($selector);
                if (null === $subject) {
                    continue;
                }
                $rules[implode("\0", $ancestors) . "\0" . $subject] = array('subject' => $subject, 'ancestors' => $ancestors);
                if (count($rules) >= self::MAX_RULES) {
                    return;
                }
            }
        });

        $css = array();
        foreach ($rules as $rule) {
            $ruleCss = ':where(body:not(.editor-styles-wrapper):not(.wp-admin)) :where(.wp-block-template-part):has(> ' . $rule['subject'] . '){display:contents}';
            foreach (array_reverse($rule['ancestors']) as $ancestor) {
                $ruleCss = $ancestor . '{' . $ruleCss . '}';
            }
            $css[] = $ruleCss;
        }

        return implode("\n", $css);
    }

    /**
     * The selector as a single compound selector (an optional leading `:root`
     * scope dropped; the rule walker already removed comments), or null when it relates elements through a combinator or
     * names a pseudo-element: only a compound can stand as the wrapper's direct
     * child.
     */
    private static function compoundSubject(string $selector): ?string
    {
        $selector = trim((string) preg_replace('/^:root(?=[\s>])\s*>?\s*/i', '', $selector));
        if ('' === $selector || str_contains($selector, '::') || 1 === preg_match('/^(html|body|:root)\b/i', $selector)) {
            return null;
        }
        $depth = 0;
        $quote = null;
        $length = strlen($selector);
        for ($i = 0; $i < $length; $i++) {
            $char = $selector[$i];
            if (null !== $quote) {
                if ('\\' === $char) {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ('\\' === $char) {
                $i++;
            } elseif ('"' === $char || "'" === $char) {
                $quote = $char;
            } elseif ('(' === $char || '[' === $char) {
                $depth++;
            } elseif (')' === $char || ']' === $char) {
                $depth--;
            } elseif (0 === $depth && (ctype_space($char) || '>' === $char || '+' === $char || '~' === $char || ',' === $char)) {
                return null;
            }
        }

        return $selector;
    }
}
