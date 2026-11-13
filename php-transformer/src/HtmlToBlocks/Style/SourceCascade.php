<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/**
 * The source document's cascade: which declarations win on an element, which
 * custom properties it sees, how specific a selector is, and whether at-rule
 * conditions hold at a viewport. Results are remembered on the
 * {@see SourceStyleResolutionState} the cascade reads.
 */
final class SourceCascade
{
    public function __construct(private readonly SourceStyleResolutionState $state)
    {
    }

    /**
     * Matched CSS and inline declarations from the generic cascaded-value
     * stream. Unlike {@see StyleResolver::presentationDeclarations()}, this is not limited to
     * classification properties, so callers can resolve custom properties at
     * an element's actual cascade scope.
     *
     * @return array<string, string>
     */
    public function declarations(DOMElement $element): array
    {
        return $this->state->cascadedDeclarations($element, $this->computeDeclarations(...))['declarations'];
    }

    /**
     * Custom properties visible to `$element` through the unfiltered cascade,
     * scoped by ancestry rather than limited to `:root`/`html`. An id- or
     * class-scoped `--token` an ancestor declares is a legitimate source.
     * This supports any property that needs an element-scoped `var()` value,
     * including SVG paint and structural layout declarations.
     *
     * Values resolve at the desktop reference viewport: a token an ancestor
     * declares only under a media query that applies there (for example
     * `@media (min-width:981px)`) fills in where no resting rule on that
     * ancestor declares it. Pass `$resting = true` for the resting cascade
     * alone, so a value conditioned on another viewport stays out of it.
     *
     * @return array<string, string>
     */
    public function customProperties(DOMElement $element, bool $resting = false): array
    {
        return $this->state->cascadedCustomProperties(
            $element,
            $resting,
            fn(array $ancestors): array => $this->computeCustomProperties($ancestors, $resting)
        );
    }

    /**
     * @return array{int, int, int}
     */
    public function specificity(string $selector): array
    {
        $parsed = $this->state->parsedSelector($selector);
        if (! ($parsed['supported'] ?? false)) {
            return array( 0, 0, 0 );
        }

        $ids = 0;
        $classes = 0;
        $elements = 0;
        foreach ($parsed['compounds'] as $compound) {
            if ( true === ($compound['forced_zero_specificity'] ?? false) ) {
                continue;
            }
            $zeroSpecificity = $compound['zero_specificity'] ?? array();
            $ids += count($compound['ids'] ?? array()) - (int) ($zeroSpecificity['ids'] ?? 0);
            $classes += count($compound['classes'] ?? array()) + count($compound['attributes'] ?? array())
                - (int) ($zeroSpecificity['classes'] ?? 0) - (int) ($zeroSpecificity['attributes'] ?? 0);
            if (null !== ($compound['nth_child'] ?? null) || ($compound['first_child'] ?? false) || ($compound['last_child'] ?? false)) {
                ++$classes;
            }
            if (null !== ($compound['type'] ?? null) && 0 === (int) ($zeroSpecificity['types'] ?? 0)) {
                ++$elements;
            }
            $listSpecificity = CssSelectorMatcher::selectorListArgumentSpecificity($compound);
            $ids += $listSpecificity['ids'];
            $classes += $listSpecificity['classes'];
            $elements += $listSpecificity['types'];
        }

        return array( $ids, $classes, $elements );
    }

    /**
     * Whether a declaration's at-rule conditions all hold at one viewport width.
     *
     * `@layer` only orders the cascade and never conditions. `@supports` holds
     * when the allowlist knows the feature to be true. `@media` is evaluated
     * at the given width, with `em`/`rem` read against the 16px media-query
     * base. Any other at-rule, and any media feature the evaluator does not
     * know, does not hold.
     *
     * @param list<string> $conditions
     */
    public function conditionsApplyAtViewport(array $conditions, float $viewportWidth): bool
    {
        foreach ($conditions as $condition) {
            $condition = trim($condition);
            if (preg_match('/^@layer\b/i', $condition)) continue;
            if (preg_match('/^@supports\b/i', $condition)) {
                if (!CssCascade::supportsConditionApplies((string) preg_replace('/^@supports\s*/i', '', $condition))) return false;
            } elseif (preg_match('/^@media\b/i', $condition)) {
                if (!CssCascade::mediaConditionApplies((string) preg_replace('/^@media\s*/i', '', $condition), $viewportWidth)) return false;
            } elseif (preg_match('/^@container\b/i', $condition)) {
                // A style query on a viewport-switched custom property holds
                // wherever the viewport sets the queried value.
                $applies = $this->state->containerStyleQueries()->applies(
                    $condition,
                    fn (array $own): bool => $this->conditionsApplyAtViewport($own, $viewportWidth)
                );
                if (true !== $applies) return false;
            } else return false;
        }
        return true;
    }

    /** @param array{0:int,1:int,2:int} $specificity @return array<string, mixed> */
    public static function entry(string $property, string $value, bool $inline, array $specificity, int $order, ?int $layer): array
    {
        return array(
            'property'    => $property,
            'value'       => $value,
            'important'   => 1 === preg_match('/!\s*important\s*$/i', $value),
            'inline'      => $inline,
            'specificity' => $specificity,
            'order'       => $order,
            'layer'       => $layer,
        );
    }

    /**
     * The subset of a declaration map needed to resolve values outside the
     * classification allow-list: SVG paint/layout, animation identity, and
     * custom properties. Keeping this stream separate lets SVG materialization
     * and structural `var()` resolution share the real matched cascade without
     * changing general classification.
     *
     * @param array<string, string> $declarations
     * @return array<string, string>
     */
    public static function relevantDeclarations(array $declarations): array
    {
        static $cascadeProperties = array(
            'box-sizing' => true,
            'color' => true,
            // Keep paint readers before substitution for state-local token swaps.
            'background-color' => true,
            'background' => true,
            // Anchor carriers must resolve authored decoration even when the
            // general presentation demand filter skips an ordinary text link.
            'text-decoration' => true,
            'text-decoration-line' => true,
            'fill' => true,
            'fill-opacity' => true,
            'stroke' => true,
            'stroke-opacity' => true,
            'stroke-width' => true,
            'animation' => true,
            'animation-name' => true,
            'opacity' => true,
            'transform' => true,
            'transform-origin' => true,
            'rotate' => true,
            'scale' => true,
            'translate' => true,
            'transition-property' => true,
            'transition-duration' => true,
            'transition-timing-function' => true,
            'transition-delay' => true,
            // Grid-item placement: resolved for native core grid child
            // layout (Automattic/blocks-engine#2139).
            'grid-area' => true,
            'grid-column' => true,
            'grid-column-start' => true,
            'grid-column-end' => true,
            'grid-row' => true,
            'grid-row-start' => true,
            'grid-row-end' => true,
        );

        $filtered = array();
        foreach ( $declarations as $name => $value ) {
            if ( str_starts_with($name, '--') || isset($cascadeProperties[$name]) ) {
                $filtered[$name] = $value;
            }
        }

        return $filtered;
    }

    /**
     * HTML presentational hints have zero specificity and precede every author
     * rule (including layered rules). Merge them underneath the resolved author
     * cascade, never as inline declarations, so ordinary inheritance applies.
     *
     * @return array<string, string>
     */
    public static function presentationalHints(DOMElement $element): array
    {
        $tag = strtolower($element->tagName);
        $declarations = array();
        // HTML maps the obsolete <center> element to centered, inherited text alignment.
        if ('center' === $tag) {
            $declarations['text-align'] = 'center';
        }
        if (in_array($tag, array('div', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'td', 'th'), true)) {
            $align = strtolower(trim(SourceDom::attr($element, 'align')));
            if (in_array($align, array('left', 'center', 'right', 'justify'), true)) {
                $declarations['text-align'] = $align;
            }
        }
        if (in_array($tag, array('td', 'th', 'tr'), true)) {
            $valign = strtolower(trim(SourceDom::attr($element, 'valign')));
            if (in_array($valign, array('top', 'middle', 'bottom', 'baseline'), true)) {
                $declarations['vertical-align'] = $valign;
            }
        }
        return $declarations;
    }

    /** @return array<string, string> */
    private function computeDeclarations(DOMElement $element): array
    {
        // Fold declarations weakest first, in the shared cascade order
        // (importance, inline, layer, specificity, source order), so the merge
        // keeps the value a browser computes rather than the last one written.
        $entries = array();
        $order = 0;
        foreach ( $this->state->matchingRules($element, 'cascaded-values') as $rule ) {
            $specificity = $this->specificity($rule['selector']);
            foreach ( $rule['declarations'] as $property => $value ) {
                $entries[] = self::entry((string) $property, (string) $value, false, $specificity, ++$order, $rule['layerRank'] ?? null);
            }
        }
        foreach ( self::relevantDeclarations(SourceDeclarations::inline($element)) as $property => $value ) {
            $entries[] = self::entry((string) $property, (string) $value, true, array( 0, 0, 0 ), ++$order, null);
        }
        usort($entries, static fn (array $left, array $right): int => CssCascade::wins($left, $right) ? 1 : -1);

        // Presentational hints sit beneath every author declaration.
        $declarations = self::presentationalHints($element);
        foreach ( $entries as $entry ) {
            $declarations = SourceDeclarations::merge($declarations, array( $entry['property'] => $entry['value'] ));
        }

        return $declarations;
    }

    /**
     * @param list<DOMElement> $ancestors The element followed by its ancestors, nearest first.
     * @return array<string, string>
     */
    private function computeCustomProperties(array $ancestors, bool $resting): array
    {
        $sourceStyles = $this->state;
        $customProperties = $sourceStyles->customProperties();
        foreach ( array_reverse($ancestors) as $ancestor ) {
            $declared = $sourceStyles->cascadedDeclarations($ancestor, $this->computeDeclarations(...))['custom'];
            if ( ! $resting ) {
                $declared += $this->desktopConditionedCustomProperties($ancestor);
            }
            foreach ( $declared as $name => $propertyValue ) {
                $customProperties[$name] = $propertyValue;
            }
        }

        return $customProperties;
    }

    /**
     * Custom properties the element's own media-conditioned rules declare at
     * the desktop reference viewport, cascade winner per name.
     *
     * @return array<string, string>
     */
    private function desktopConditionedCustomProperties(DOMElement $element): array
    {
        $facts = array();
        foreach ( $this->state->matchingRules($element, 'conditional') as $order => $rule ) {
            $conditions = $rule['conditions'] ?? array();
            if ( array() === $conditions || ! $this->conditionsApplyAtViewport($conditions, StyleResolver::DESKTOP_REFERENCE_WIDTH) ) {
                continue;
            }
            foreach ( $rule['cascadedDeclarations'] ?? array() as $name => $value ) {
                $value = trim((string) $value);
                if ( ! str_starts_with((string) $name, '--') || '' === $value ) {
                    continue;
                }
                CssCascade::apply($facts, (string) $name, array(
                    'value' => $value, 'important' => CssValueInspector::isImportant($value),
                    'specificity' => $this->specificity((string) $rule['selector']),
                    'order' => (int) ($rule['cascadeOrder'] ?? $order),
                    'inline' => false, 'layer' => $rule['layerRank'] ?? null,
                ));
            }
        }

        return array_map(static fn (array $fact): string => $fact['value'], $facts);
    }
}
