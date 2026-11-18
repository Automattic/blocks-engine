<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use DOMElement;

/**
 * Resolves `var()` references against the source document's custom
 * properties: at the reference viewport, at another viewport width, in the
 * default colour scheme, or as possible paint for empty-box evidence.
 */
final class CustomPropertyResolver
{
    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly SourceCascade $cascade,
        private readonly AuthorViewportDeclarations $authorViewport
    ) {
    }

    public function resolve(string $value, ?DOMElement $element = null): string
    {
        if ( false === strpos($value, 'var(') ) {
            return $value;
        }

        $customProperties = $element instanceof DOMElement
            ? $this->cascade->customProperties($element)
            : $this->session->sourceStyleResolutionState()->customProperties();

        // Conditional palette definitions are runtime values, not capture
        // constants. Keep their references live in every emitted carrier.
        $live = array();
        if ($element instanceof DOMElement) {
            for ($node = $element; $node instanceof DOMElement; $node = $node->parentNode instanceof DOMElement ? $node->parentNode : null) {
                foreach ($this->session->sourceStyleResolutionState()->matchingRules($node, 'conditional') as $rule) {
                    if (!str_contains(implode(' ', $rule['conditions'] ?? array()), 'prefers-color-scheme')) continue;
                    foreach ($rule['cascadedDeclarations'] ?? array() as $name => $_declared) {
                        if (str_starts_with((string) $name, '--')) $live[$name] = true;
                    }
                }
            }
        }
        return $this->expand($value, $customProperties, $live);
    }

    /**
     * Expand `var()` against the element's unconditional custom-property
     * cascade ({@see SourceCascade::customProperties()}). This is the value a
     * reference renders in the default (no-preference) colour scheme, since a
     * `prefers-color-scheme` redefinition does not apply, and the value a
     * structural declaration (`display`, `align-self`) resolves to.
     */
    public function resolveCascaded(string $value, DOMElement $element): string
    {
        return false === strpos($value, 'var(') ? $value : $this->expand($value, $this->cascade->customProperties($element));
    }

    /**
     * Expand `var()` references the way they compute at one viewport width.
     *
     * Each custom property takes the author cascade winner at that width on
     * the element or its nearest declaring ancestor, so a token a width query
     * (or a style query keyed to one) switches resolves to that width's value
     * rather than the reference viewport's. A reference without a value or
     * fallback is invalid at computed-value time: '' is returned.
     */
    public function resolveAtViewport(string $value, DOMElement $element, float $viewportWidth): string
    {
        if ( ! str_contains($value, 'var(') ) {
            return $value;
        }
        $resolve = function (string $name) use ($element, $viewportWidth): ?string {
            for ( $node = $element; $node instanceof DOMElement; $node = $node->parentNode instanceof DOMElement ? $node->parentNode : null ) {
                $declared = CssValueInspector::withoutImportant(trim((string) ($this->authorViewport->atViewport($node, array( $name ), $viewportWidth)[$name] ?? '')));
                if ( '' !== $declared ) {
                    return in_array(strtolower($declared), array( 'unset', 'initial', 'inherit', 'revert', 'revert-layer' ), true) ? null : $declared;
                }
            }
            $root = trim((string) ($this->session->sourceStyleResolutionState()->customProperties()[$name] ?? ''));

            return '' === $root ? null : $root;
        };

        return CssVariableExpander::expand($value, $resolve) ?? '';
    }

    /**
     * Empty-box evidence asks whether paint is possible at any viewport, not
     * what its computed value is at the reference viewport. A conditional
     * definition on this element or an ancestor must not become a missing
     * variable's fallback. Use an opaque paint token rather than choosing an
     * arbitrary winner across mutually exclusive conditions. Static references
     * (including their dependencies and fallbacks) still expand normally.
     */
    public function resolvePossiblePaint(string $value, DOMElement $element): string
    {
        if ( ! str_contains($value, 'var(') ) {
            return $value;
        }

        $customProperties = $this->cascade->customProperties($element);
        for ( $node = $element; $node instanceof DOMElement; $node = $node->parentNode instanceof DOMElement ? $node->parentNode : null ) {
            foreach ( $this->session->sourceStyleResolutionState()->matchingRules($node, 'conditional') as $rule ) {
                foreach ( $rule['cascadedDeclarations'] ?? array() as $name => $declared ) {
                    if ( str_starts_with((string) $name, '--') ) {
                        $customProperties[(string) $name] = 'blocks-engine-possible-paint';
                    }
                }
            }
        }

        return $this->expand($value, $customProperties);
    }

    /**
     * Condition stacks of the author rules that declare any of the custom
     * properties a value reads, with each container style query expanded to
     * the conditions that switch the property it queries. These are the
     * viewport queries under which the value's resolved `var()` can change.
     *
     * @return list<list<string>>
     */
    public function conditionStacks(string $value): array
    {
        if ( ! preg_match_all('/var\(\s*(--[A-Za-z0-9_-]+)/', $value, $matches) ) {
            return array();
        }
        $names = array_fill_keys($matches[1], true);
        $queries = $this->session->sourceStyleResolutionState()->containerStyleQueries();
        $stacks = array();
        foreach ( $this->session->authorStyleAnalysis()->styleRules() as $rule ) {
            $conditions = is_array($rule['conditions'] ?? null) ? array_values(array_map('strval', $rule['conditions'])) : array();
            if ( array() === $conditions || array() === array_intersect_key(ArrayField::array($rule, 'declarations'), $names) ) {
                continue;
            }
            $stacks[] = $conditions;
            foreach ( $conditions as $condition ) {
                array_push($stacks, ...$queries->switchingConditions($condition));
            }
        }

        return $stacks;
    }

    /**
     * Custom properties the stylesheets declare on `:root` or `html`.
     *
     * @return array<string, string>
     */
    public function root(): array
    {
        return $this->session->sourceStyleResolutionState()->customProperties();
    }

    /**
     * The custom properties a declaration reads, resolved from the source
     * cascade of the element that declared it.
     *
     * A materializer that rebuilds a source subtree into a single element
     * carries the author declarations that matched it, but not the custom
     * properties those declarations read: the elements that declared them are
     * exactly the ones the rebuild collapses. Re-rooting the definitions on the
     * surviving element keeps a carried `var()` resolvable instead of leaving
     * it invalid at computed-value time. Nested references are left alone so a
     * definition that survives in the output still resolves at runtime.
     *
     * @return array<string, string>
     */
    public function carried(string $value, DOMElement $element): array
    {
        if ( ! str_contains($value, 'var(') || ! preg_match_all('/var\(\s*(--[A-Za-z0-9_-]+)/', $value, $matches) ) {
            return array();
        }

        $customProperties = $this->cascade->customProperties($element);
        $carried = array();
        foreach ( array_unique($matches[1]) as $name ) {
            $declared = trim((string) ( $customProperties[ $name ] ?? '' ));
            if ( '' === $declared ) {
                continue;
            }
            $carried[ $name ] = $declared;
        }

        return $carried;
    }

    /** @return array<string, string> */
    public function conditionalCascaded(DOMElement $element): array
    {
        $customProperties = $this->cascade->customProperties($element);
        $ancestors = array();
        for ( $current = $element; $current instanceof DOMElement; $current = $current->parentNode instanceof DOMElement ? $current->parentNode : null ) {
            $ancestors[] = $current;
        }
        foreach ( array_reverse($ancestors) as $ancestor ) {
            foreach ( $this->session->sourceStyleResolutionState()->matchingRules($ancestor, 'conditional') as $rule ) {
                foreach ( $rule['cascadedDeclarations'] ?? array() as $name => $value ) {
                    if ( str_starts_with((string) $name, '--') ) {
                        $customProperties[(string) $name] = (string) $value;
                    }
                }
            }
        }

        return $customProperties;
    }

    /**
     * @param array<string, string> $customProperties
     * @param array<string,true> $live Names kept as live references.
     */
    public function expand(string $value, array $customProperties, array $live = array()): string
    {
        $keep = array() === $live ? null : static fn(string $name): bool => isset($live[$name]);
        return CssVariableExpander::expand($value, static function (string $name) use ($customProperties): ?string {
            $propertyValue = trim((string) ($customProperties[$name] ?? ''));
            // Retain the existing design-token sentinel policy while sharing
            // balanced nested/function fallback substitution with form paint.
            return '' === $propertyValue || in_array(strtolower($propertyValue), array( 'unset', 'initial', 'inherit', 'revert', 'revert-layer' ), true)
                ? null
                : $propertyValue;
        }, 0, $keep) ?? trim($value);
    }
}
