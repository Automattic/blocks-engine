<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use DOMElement;

/**
 * Author declarations an element resolves to at a reference viewport, read
 * from the author analysis (every authored property, with its condition
 * stack) rather than the source-style allow-list. The rules that apply at
 * each viewport are built once per author rule set.
 */
final class AuthorViewportDeclarations
{
    /** @var list<array<string, mixed>> */
    private array $ruleSource = array();
    /** @var array<string, array{rules: list<array<string, mixed>>, parsed: array<string, array<string, mixed>>, declaring: array<string, list<array<string, mixed>>>}> */
    private array $rulesByViewport = array();

    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly SourceCascade $cascade
    ) {
    }

    /**
     * {@see SourceCascade::collapsedViewportDeclarations()} read from the author analysis
     * instead of the source-style collections.
     *
     * The source-style collections keep only the classification allow-list,
     * which leaves out the transform family (runtime-animatable, so kept out
     * of the resting cascade) and the logical inset and margin properties. An
     * element's placement needs those, and the author analysis keeps every
     * declaration together with its condition stack. The cascade rules are
     * the same: author importance, layer and specificity priority, authored
     * declaration order, inline style last, conditions evaluated at
     * {@see StyleResolver::MOBILE_REFERENCE_WIDTH}.
     *
     * @param list<string> $properties
     * @return array<string, string>
     */
    public function collapsed(DOMElement $element, array $properties): array
    {
        return $this->atViewport($element, $properties, StyleResolver::MOBILE_REFERENCE_WIDTH);
    }

    /**
     * The same author-analysis cascade as
     * {@see self::collapsed()}, with conditions evaluated at
     * the desktop reference viewport ({@see StyleResolver::DESKTOP_REFERENCE_WIDTH}).
     * Unlike the source-style collections it sees every authored property
     * (`float`, `white-space`, `word-spacing`, …), so a caller can ask how an
     * element lays out where the allow-list is silent. A value is the declared
     * one, not an inherited one: a caller walks the ancestors itself.
     *
     * Capture disclosure CSS remains available for presentation, but callers
     * judging source visibility can exclude the producer's runtime rules.
     *
     * @param list<string> $properties
     * @return array<string, string>
     */
    public function reference(DOMElement $element, array $properties, bool $excludeCaptureDisclosure = false): array
    {
        return $this->atViewport($element, $properties, StyleResolver::DESKTOP_REFERENCE_WIDTH, $excludeCaptureDisclosure);
    }

    /**
     * @param list<string> $properties
     * @return array<string, string>
     */
    public function atViewport(DOMElement $element, array $properties, float $viewportWidth, bool $excludeCaptureDisclosure = false): array
    {
        $authorStyles = $this->session->authorStyleAnalysis();
        $styleRules = $authorStyles->styleRules();
        if ( $excludeCaptureDisclosure ) {
            $styleRules = array_values(array_filter($styleRules, static fn (array $rule): bool => empty($rule['capture_only'])));
        }
        ['parsed' => $parsedBySelector] = $this->rules($styleRules, $viewportWidth);
        $rules = $this->rulesDeclaring($viewportWidth, $properties);
        $selectorCache = $authorStyles->selectorMatchCache();
        $matches = static function (string $selector) use ($element, $selectorCache, $parsedBySelector): bool {
            $match = $selectorCache->matches($element, $selector, $parsedBySelector[ $selector ] ?? array());

            return ( $match['supported'] ?? false ) && ( $match['matches'] ?? false );
        };

        return $this->cascade->declarationsAtViewport($element, $properties, $rules, $matches, $viewportWidth);
    }

    /**
     * The author rules that apply at one viewport, in cascade order.
     *
     * Which rules apply depends only on the stylesheet and the viewport, never
     * on the element being resolved, so the list is built once per author rule
     * set and viewport instead of once per element. Conditions are already
     * evaluated here, so each kept rule carries none.
     *
     * @param list<array<string, mixed>> $styleRules
     * @return array{rules: list<array{selectors: list<string>, declarations: array<string, string>, conditions: list<string>, layer: mixed}>, parsed: array<string, array<string, mixed>>}
     */
    private function rules(array $styleRules, float $viewportWidth): array
    {
        if ( $styleRules !== $this->ruleSource ) {
            $this->ruleSource = $styleRules;
            $this->rulesByViewport = array();
        }
        $key = (string) $viewportWidth;
        if ( isset($this->rulesByViewport[ $key ]) ) {
            return $this->rulesByViewport[ $key ];
        }

        $rules = array();
        $parsedBySelector = array();
        foreach ( $styleRules as $rule ) {
            $selectors = array();
            foreach ( ArrayField::array($rule, 'selectors') as $record ) {
                $selector = (string) ( $record['selector'] ?? '' );
                // Interaction states and generated content are not the
                // resting element, as in the source-style collections.
                if ( '' === $selector || StyleResolver::selectorCarriesPseudoState($selector) ) {
                    continue;
                }
                $selectors[] = $selector;
                $parsedBySelector[ $selector ] ??= ArrayField::array($record, 'parsed');
            }
            // The author analysis keeps at-rule preludes verbatim, so a
            // comment written before `@media` travels with the condition
            // (`/* tablet */ @media(max-width:1300px)`); the viewport
            // evaluator reads the at-rule itself.
            $conditions = array();
            foreach ( ArrayField::array($rule, 'conditions') as $condition ) {
                $condition = trim((string) preg_replace('#/\*.*?\*/#s', '', (string) $condition));
                if ( '' !== $condition ) {
                    $conditions[] = $condition;
                }
            }
            if ( array() !== $conditions && ! $this->cascade->conditionsApplyAtViewport($conditions, $viewportWidth) ) {
                continue;
            }
            $rules[] = array(
                'selectors' => $selectors,
                'declarations' => ArrayField::array($rule, 'declarations'),
                'conditions' => array(),
                'layer' => $rule['layer'] ?? null,
            );
        }

        return $this->rulesByViewport[ $key ] = array( 'rules' => $rules, 'parsed' => $parsedBySelector, 'declaring' => array() );
    }

    /**
     * The viewport rules that declare at least one requested property.
     *
     * A rule declaring none of them cannot contribute a fact, so matching its
     * selectors against every element asked about is wasted work. Callers ask
     * about a handful of fixed property sets, so each filtered list is built
     * once per rule set, viewport and property set.
     *
     * @param list<string> $properties
     * @return list<array<string, mixed>>
     */
    private function rulesDeclaring(float $viewportWidth, array $properties): array
    {
        $key = (string) $viewportWidth;
        $set = implode("\0", $properties);
        if ( isset($this->rulesByViewport[ $key ]['declaring'][ $set ]) ) {
            return $this->rulesByViewport[ $key ]['declaring'][ $set ];
        }
        $requested = array_flip($properties);
        $rules = array_values(array_filter(
            $this->rulesByViewport[ $key ]['rules'],
            static fn(array $rule): bool => array() !== array_intersect_key($rule['declarations'], $requested)
        ));

        return $this->rulesByViewport[ $key ]['declaring'][ $set ] = $rules;
    }
}
