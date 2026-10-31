<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;

/**
 * `box-sizing:content-box` restatements collected while projecting one author
 * stylesheet, for the after-author support stylesheet.
 *
 * A sized and padded source box is restated as content-box because core's
 * group stylesheet resets groups to border-box ({@see AuthorStyleRuleProjector}).
 * That restatement is support CSS: a host that keeps its own theme stylesheet
 * as the author CSS installs only the support stylesheets, so the restatement
 * lives there, once, as a rule with the projected selector inside the same
 * at-rule conditions, and the projected author rule no longer carries it.
 *
 * Source rules that set `box-sizing` under a narrower condition and won over a
 * restatement in the authored cascade are restated too, so the after-author
 * position does not override them. Every rule here keeps its authored cascade
 * position relative to the others, and its projected specificity, so among
 * themselves they resolve as the authored rules did.
 */
final class ContentBoxRestatements
{
    private const DECLARATION = 'box-sizing:content-box';

    /** @var array<string, array{order: int, sequence: int, css: string}> Rules keyed by their own text. */
    private array $rules = array();

    /** Authored cascade position of the restatements being extracted. */
    private int $order = -1;

    /**
     * Move every `box-sizing:content-box` declaration out of the projected
     * `$css` into a restatement rule with the same selector and conditions.
     * Returns `$css` without them; a rule left without declarations is dropped.
     *
     * @param list<string> $conditions At-rules `$css` sits inside, outermost first.
     * @param ?int $order The restated rule's authored cascade position; when
     *     unknown it follows the previous restatement.
     */
    public function extract(string $css, array $conditions, ?int $order = null): string
    {
        if ( null !== $order ) {
            $this->order = $order;
        }

        return $this->extractRules($css, $conditions, array());
    }

    /**
     * Record a source rule a restatement must not override, at its authored
     * cascade position.
     *
     * @param list<string> $conditions At-rules the rule sits inside, outermost first.
     */
    public function addOverride(string $css, array $conditions, int $order): void
    {
        $css = trim($css);
        if ( '' !== $css ) {
            $this->record(self::wrap($css, $conditions), $order);
        }
    }

    /** The after-author support CSS, in authored cascade order. */
    public function css(): string
    {
        $rules = array_values($this->rules);
        usort($rules, static fn (array $left, array $right): int => array( $left['order'], $left['sequence'] ) <=> array( $right['order'], $right['sequence'] ));

        return implode('', array_column($rules, 'css'));
    }

    private function record(string $rule, int $order): void
    {
        $this->rules[ $rule ] ??= array( 'order' => $order, 'sequence' => count($this->rules), 'css' => $rule );
    }

    /**
     * @param list<string> $conditions
     * @param list<string> $parents Selectors of enclosing (nested) style rules, outermost first.
     */
    private function extractRules(string $css, array $conditions, array $parents): string
    {
        $transformer = new CssStylesheetTransformer();

        return $transformer->transformStyleRules(
            $css,
            function (string $prelude, string $body, array $ancestors) use ($conditions, $parents, $transformer): string {
                $scope = array( ...$conditions, ...array_map(static fn (mixed $ancestor): string => (string) $ancestor, $ancestors) );
                if ( ! str_contains($body, '{') ) {
                    return $this->extractRule($prelude, $body, $scope, $parents);
                }
                $rebuilt = '';
                foreach ( $transformer->splitStyleRuleBody($body) as $part ) {
                    if ( isset($part['declarations']) ) {
                        $rebuilt .= $this->withoutRestatement($part['declarations'], $prelude, $scope, $parents);
                    } elseif ( '' === $part['at_rule'] ) {
                        $rebuilt .= $this->extractRules($part['prelude'] . '{' . $part['body'] . '}', $scope, array( ...$parents, trim($prelude) ));
                    } elseif ( in_array($part['at_rule'], array( 'media', 'supports', 'container', 'layer' ), true) ) {
                        $rebuilt .= $part['prelude'] . '{' . $this->withoutRestatement($part['body'], $prelude, array( ...$scope, trim($part['prelude']) ), $parents) . '}';
                    } else {
                        $rebuilt .= $part['prelude'] . '{' . $part['body'] . '}';
                    }
                }

                return '' === trim($rebuilt) ? '' : $prelude . '{' . $rebuilt . '}';
            }
        );
    }

    /**
     * @param list<string> $scope
     * @param list<string> $parents
     */
    private function extractRule(string $prelude, string $body, array $scope, array $parents): string
    {
        $remaining = $this->withoutRestatement($body, $prelude, $scope, $parents);

        return '' === trim($remaining) ? '' : $prelude . '{' . $remaining . '}';
    }

    /**
     * Declarations of `$declarations` other than the restatement, recording a
     * restatement rule for `$prelude` when it carried one.
     *
     * @param list<string> $scope
     * @param list<string> $parents
     */
    private function withoutRestatement(string $declarations, string $prelude, array $scope, array $parents): string
    {
        $kept = array();
        $restated = false;
        foreach ( CssValueSplitter::splitTopLevel($declarations, array( ';' )) as $declaration ) {
            if ( self::DECLARATION === strtolower((string) preg_replace('/\s+/', '', $declaration)) ) {
                $restated = true;
                continue;
            }
            if ( '' !== trim($declaration) ) {
                $kept[] = $declaration;
            }
        }
        if ( ! $restated ) {
            return $declarations;
        }
        // A comment ahead of the rule is part of its prelude; it stays with the author rule.
        $selector = self::scopedSelector(trim((string) preg_replace('#/\*.*?\*/#s', '', $prelude)), $parents);
        if ( '' === $selector ) {
            return implode(';', $kept);
        }
        $rule = $selector . '{' . self::DECLARATION . '}';
        foreach ( array_reverse($parents) as $parent ) {
            $rule = $parent . '{' . $rule . '}';
        }
        $this->record(self::wrap($rule, $scope), $this->order);

        return implode(';', $kept);
    }

    /**
     * The selector arms of `$selector` that reach imported markup through an
     * importer-emitted class. An authored arm keyed only on core-shaped classes
     * (`.wp-block-*`, `.has-*`) is left out: the projection restates the same
     * element through its importer-class twin, and the bare arm would also
     * resize blocks an owner adds after import.
     *
     * @param list<string> $parents
     */
    private static function scopedSelector(string $selector, array $parents): string
    {
        $arms = CssStylesheetTransformer::splitSelectorList($selector);
        if ( null === $arms ) {
            return $selector;
        }
        $scoping = new CoreSelectorScoping();
        $chain = implode(' ', $parents);

        $scoped = array_filter($arms, static fn (string $arm): bool => $scoping->selectorIsScoped(trim($chain . ' ' . $arm)));

        return count($scoped) === count($arms) ? $selector : implode(',', $scoped);
    }

    /** @param list<string> $conditions */
    private static function wrap(string $css, array $conditions): string
    {
        foreach ( array_reverse($conditions) as $condition ) {
            $condition = trim($condition);
            if ( '' !== $condition ) {
                $css = $condition . '{' . $css . '}';
            }
        }

        return $css;
    }
}
