<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatchCache;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorTokenizer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceDeclarations;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Closure;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * The restatement proof behind {@see WrapperDecision::restatedWrapperFold()}
 * (#7505, part of #7471 stage 2).
 *
 * A geometry- and paint-neutral wrapper (box-tree evidence, see
 * {@see BoxTreeWrapperEvidence}) survives only because emitted CSS consumes
 * its class or id. Folding it moves those classes, and the id when the child
 * has none, onto its only child. This class proves, on the source DOM, that
 * every author rule still reaches exactly the elements it reached before:
 *
 *  - A rule whose subject was the wrapper (`.col`) must now match the child,
 *    and each declaration it brings must be restatable there: an inherited
 *    text property the child already wins over is dropped by the cascade;
 *    one that would beat the child's own declaration refuses. A box
 *    declaration may only land where the child declares nothing that
 *    competes, and only for properties whose transfer reproduces the
 *    wrapper's box on a child whose box already equals it.
 *  - A rule that reached the child or a descendant through the wrapper
 *    (`.col .block`, `.col > .x`) gains a compound arm (`.col.block`) that
 *    restores the match. The arm is verified to match nothing new, here or
 *    anywhere in the source document, and is emitted in the same rule by the
 *    selector projection ({@see \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorSelectorProjector::rewriteSelectorPreludeOnce()}).
 *  - Any other change in which rules match the child, its descendants or
 *    (when a sibling combinator names a moved token) the wrapper's following
 *    siblings refuses the fold.
 *
 * The proof temporarily applies the fold to the source DOM, reads matches
 * through a private selector cache, and restores the DOM before returning.
 * A failure names its condition so refusals can be counted per condition.
 */
final class RestatedWrapperFold
{
    public const UNVERIFIABLE_SELECTOR = 'unverifiable_selector';
    public const SELECTOR_CHECK_BUDGET = 'selector_check_budget';
    public const WRAPPER_RULE_NOT_RESTATED = 'wrapper_rule_not_restated';
    public const SELECTOR_MATCH_CHANGED = 'selector_match_changed';
    public const INHERITED_TEXT_OVERRIDE = 'inherited_text_override';
    public const WRAPPER_DECLARATION_CONTESTED = 'wrapper_declaration_contested';
    public const WRAPPER_DECLARATION_UNRESTATABLE = 'wrapper_declaration_unrestatable';
    public const CHILD_RELATIVE_TO_WRAPPER = 'child_relative_to_wrapper';

    /**
     * The proof gives the child a projected wrapper attribute under this
     * prefix, so the rules reading the attribute can be evaluated as if the
     * child carried the engine-owned class that replaces it, without
     * touching an attribute of the same name the child has of its own.
     */
    public const PROJECTED_ATTRIBUTE_PREFIX = 'data-blocks-engine-restated-';

    /** Elements read before and after the fold; a larger region is not proven. */
    private const MAX_CHECKED_ELEMENTS = 4000;

    /** Inherited, but not text: they reach the child through the wrapper today. */
    private const OTHER_INHERITED_PROPERTIES = array(
        'cursor', 'visibility', 'pointer-events', 'direction', 'text-shadow', 'word-break', 'overflow-wrap', 'word-wrap',
        'hyphens', 'text-rendering', '-webkit-font-smoothing', '-moz-osx-font-smoothing', 'text-align-last', 'text-justify',
        'list-style', 'list-style-type', 'list-style-position', 'list-style-image', 'quotes', 'tab-size', 'caret-color',
        'accent-color', 'text-decoration-skip-ink', 'text-underline-offset', 'text-underline-position', 'font-feature-settings',
        'font-variant', 'font-kerning', 'font-optical-sizing', 'font-variation-settings',
    );

    /** Sizing the child resolves to the wrapper's box when it sizes its border box or has no padding or border. */
    private const SIZE_PROPERTIES = array('width', 'height', 'min-width', 'max-width', 'min-height', 'max-height', 'aspect-ratio');

    /** The wrapper's own placement in its parent's layout, which the child takes over. */
    private const ITEM_PROPERTIES = array(
        'flex', 'flex-grow', 'flex-shrink', 'flex-basis', 'align-self', 'justify-self', 'place-self', 'order',
        'grid-area', 'grid-column', 'grid-column-start', 'grid-column-end', 'grid-row', 'grid-row-start', 'grid-row-end',
    );

    /** Container properties, inert on a block wrapper and on a block child. */
    private const CONTAINER_PROPERTIES = array(
        'justify-content', 'align-items', 'align-content', 'justify-items', 'place-items', 'place-content',
        'gap', 'row-gap', 'column-gap', 'grid-gap', 'grid-row-gap', 'grid-column-gap', 'flex-direction', 'flex-wrap', 'flex-flow',
        'grid-template', 'grid-template-columns', 'grid-template-rows', 'grid-template-areas', 'grid-auto-flow',
        'grid-auto-columns', 'grid-auto-rows', 'vertical-align',
    );

    /** Values that state the initial, neutral box: restating them on the child changes nothing it renders. */
    private const NEUTRAL_VALUES = array(
        'position' => array('static'),
        'float' => array('none'),
        'clear' => array('none'),
        'z-index' => array('auto'),
        'overflow' => array('visible'),
        'overflow-x' => array('visible'),
        'overflow-y' => array('visible'),
        'opacity' => array('1'),
        'transform' => array('none'),
        'filter' => array('none'),
        'backdrop-filter' => array('none'),
        'box-shadow' => array('none'),
        'clip-path' => array('none'),
        'mask-image' => array('none'),
        'background' => array('none', 'transparent'),
        'background-color' => array('transparent', 'rgba(0,0,0,0)'),
        'background-image' => array('none'),
        'outline' => array('none', '0'),
        'transition' => array('none'),
        'animation' => array('none'),
        'animation-name' => array('none'),
        'top' => array('auto'),
        'right' => array('auto'),
        'bottom' => array('auto'),
        'left' => array('auto'),
        'inset' => array('auto'),
    );

    /** Dynamic states are evaluated in both truth assignments, never guessed from capture. */
    private bool $stateSatisfied = true;

    private static function statePattern(): string
    {
        return '/(?<!:):(hover|focus-visible|focus-within|focus|visited|active|-moz-placeholder|-ms-input-placeholder)(?![\w-])/i';
    }

    private function elementSelector(string $selector): string
    {
        $selector = self::withoutPseudoElement($selector)[0];
        return (string) preg_replace(self::statePattern(), $this->stateSatisfied ? ':not(:not(*))' : ':not(*)', $selector);
    }

    /** @var array<string, array{0: string, 1: array<string, mixed>}> The projected-attribute form of each rule reading one, for the proof under way. */
    private array $variants = array();

    /** @var array<string, list<array<string, mixed>>>|null Author selectors by the class (`.x`), id (`#x`) or `data-*` attribute (`[name`) they name. */
    private ?array $tokenSelectors = null;

    /** @param Closure(string): bool $isInheritedTextProperty The inherited-text property set of the inherited-text restatement path. */
    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly Closure $isInheritedTextProperty
    ) {
    }

    /**
     * Projected attributes (`data-*` attributes only author CSS reads) are
     * evaluated as if the child carried them under
     * {@see self::PROJECTED_ATTRIBUTE_PREFIX}: each rule reading one is
     * matched in its original form or in the form reading the projected
     * name. Arms that read a projected name are emitted with the
     * engine-owned class in its place ({@see self::projectedArm()}).
     *
     * A child converted from a deeper element (`$chain['elements']` lie
     * between the wrapper and it, and already folded into its block) is
     * first put in place of that chain, with the chain classes its block
     * carries, so the proof starts from what the block renders.
     *
     * @param string $movedId The wrapper id the child takes (replacing an unreferenced id of its own), or ''.
     * @param array<string, string> $movedAttributes Consumed wrapper attributes the child takes.
     * @param array<string, string> $projectedAttributes Wrapper attributes only author CSS reads, projected to a class.
     * @param array{elements: list<DOMElement>, classes: list<string>, anchor: string} $chain
     * @return array{failure: ?string, detail: string, arms: array<string, list<string>>}
     */
    public function prove(DOMElement $wrapper, DOMElement $child, string $movedId, array $movedAttributes, BoxTreeWrapperEvidence $evidence, array $projectedAttributes = array(), array $chain = array('elements' => array(), 'classes' => array(), 'anchor' => '')): array
    {
        $restoreChain = $this->collapsedChain($wrapper, $child, $chain);
        try {
            $this->stateSatisfied = true;
            $satisfied = $this->proveFold($wrapper, $child, $movedId, $movedAttributes, $evidence, $projectedAttributes, null !== $restoreChain);
            if (null !== $satisfied['failure']) return $satisfied;
            $this->stateSatisfied = false;
            $unsatisfied = $this->proveFold($wrapper, $child, $movedId, $movedAttributes, $evidence, $projectedAttributes, null !== $restoreChain);
            if (null !== $unsatisfied['failure']) return $unsatisfied;
            foreach ($unsatisfied['arms'] as $selector => $arms) $satisfied['arms'][$selector] = array_values(array_unique(array_merge($satisfied['arms'][$selector] ?? array(), $arms)));
            return $satisfied;
        } finally {
            if (null !== $restoreChain) $restoreChain();
        }
    }

    /**
     * The projected name the proof gives a wrapper attribute.
     */
    public static function projectedAttributeName(string $name): string
    {
        $name = strtolower($name);
        return self::PROJECTED_ATTRIBUTE_PREFIX . (str_starts_with($name, 'data-') ? substr($name, 5) : $name);
    }

    /**
     * The arm as emitted: each predicate on a projected attribute name
     * (`[data-blocks-engine-restated-testid="x"]`) becomes the engine-owned
     * class the child carries in its place. A class and an attribute
     * predicate weigh the same, so the arm keeps the authored specificity.
     */
    public static function projectedArm(string $arm, string $class): string
    {
        return (string) preg_replace(
            '/\[\s*' . preg_quote(self::PROJECTED_ATTRIBUTE_PREFIX, '/') . '[\w-]*\s*(?:[~|^$*]?=\s*(?:"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|[^\]\s]+)\s*(?:[iIsS]\s*)?)?\]/',
            '.' . $class,
            $arm
        );
    }

    /**
     * Puts `$child` in place of the already folded chain between it and the
     * wrapper, carrying the chain classes and anchor its block carries.
     * Returns the restore, or null when there is no chain.
     *
     * @param array{elements: list<DOMElement>, classes: list<string>, anchor: string} $chain
     */
    private function collapsedChain(DOMElement $wrapper, DOMElement $child, array $chain): ?Closure
    {
        $outermost = $chain['elements'][0] ?? null;
        if (! $outermost instanceof DOMElement || $outermost->parentNode !== $wrapper || ! $outermost->contains($child)) return null;
        $childParent = $child->parentNode;
        $childNext = $child->nextSibling;
        $childClass = $child->hasAttribute('class') ? $child->getAttribute('class') : null;
        $childId = $child->hasAttribute('id') ? $child->getAttribute('id') : null;
        $wrapper->replaceChild($child, $outermost);
        $classes = SourceDom::mergeClassNames(implode(' ', $chain['classes']), (string) $childClass);
        if ('' !== $classes) $child->setAttribute('class', $classes);
        if ('' !== $chain['anchor'] && $chain['anchor'] !== trim((string) $childId)) $child->setAttribute('id', $chain['anchor']);
        return static function () use ($wrapper, $child, $outermost, $childParent, $childNext, $childClass, $childId): void {
            $wrapper->replaceChild($outermost, $child);
            if ($childParent instanceof DOMNode) $childParent->insertBefore($child, $childNext instanceof DOMNode && $childNext->parentNode === $childParent ? $childNext : null);
            if (null === $childClass) $child->removeAttribute('class'); else $child->setAttribute('class', $childClass);
            if (null === $childId) $child->removeAttribute('id'); else $child->setAttribute('id', $childId);
        };
    }

    /**
     * @param array<string, string> $movedAttributes
     * @param array<string, string> $projectedAttributes
     * @return array{failure: ?string, detail: string, arms: array<string, list<string>>}
     */
    private function proveFold(DOMElement $wrapper, DOMElement $child, string $movedId, array $movedAttributes, BoxTreeWrapperEvidence $evidence, array $projectedAttributes, bool $collapsed): array
    {
        $this->variants = array();
        $parent = $wrapper->parentNode;
        if (! $parent instanceof DOMNode || $child->parentNode !== $wrapper) return self::failure(self::SELECTOR_MATCH_CHANGED, 'detached');
        $tokens = array();
        foreach (SourceDom::classNames($wrapper) as $class) $tokens['.' . $class] = true;
        if ('' !== $movedId) $tokens['#' . $movedId] = true;

        // Every rule that names a moved token, matchable or not.
        $tokenEntries = array();
        $siblingCombinator = false;
        foreach (array_keys($tokens) as $token) {
            foreach ($this->tokenSelectors()[$token] ?? array() as $entry) {
                if (null === $this->matchable($entry)) return self::failure(self::UNVERIFIABLE_SELECTOR, (string) $entry['selector']);
                $tokenEntries[$entry['key']] = $entry;
                if (array_intersect($entry['parsed']['combinators'] ?? array(), array('+', '~'))) $siblingCombinator = true;
            }
        }
        // Each rule reading a projected attribute is also read in the form
        // naming its projected name, which only the child carries.
        $variants = array();
        $armTokens = $tokens;
        foreach (array_keys($projectedAttributes) as $name) {
            $projected = self::projectedAttributeName($name);
            $armTokens['[' . $projected] = true;
            foreach ($this->tokenSelectors()['[' . strtolower($name)] ?? array() as $entry) {
                if (null === $this->matchable($entry)) return self::failure(self::UNVERIFIABLE_SELECTOR, (string) $entry['selector']);
                $text = self::withProjectedAttribute($variants[$entry['key']][0] ?? (string) $entry['selector'], $name, $projected);
                if (null === $text) return self::failure(self::UNVERIFIABLE_SELECTOR, (string) $entry['selector']);
                $variants[$entry['key']] = array($text, array());
                $tokenEntries[$entry['key']] = $entry;
                if (array_intersect($entry['parsed']['combinators'] ?? array(), array('+', '~'))) $siblingCombinator = true;
            }
        }
        foreach ($variants as $key => [$text]) {
            $parsed = CssSelectorMatcher::parse($this->elementSelector($text));
            if (! ($parsed['supported'] ?? false)) return self::failure(self::UNVERIFIABLE_SELECTOR, (string) $tokenEntries[$key]['selector']);
            $variants[$key] = array($text, $parsed);
        }
        $this->variants = $variants;
        $descendants = SourceDom::descendantElements($child);
        $outside = array();
        if ($siblingCombinator) {
            for ($sibling = $wrapper->nextSibling; null !== $sibling; $sibling = $sibling->nextSibling) {
                if (! $sibling instanceof DOMElement) continue;
                $outside[] = $sibling;
                array_push($outside, ...SourceDom::descendantElements($sibling));
            }
        }
        if (count($descendants) + count($outside) > self::MAX_CHECKED_ELEMENTS) return self::failure(self::SELECTOR_CHECK_BUDGET, (string) (count($descendants) + count($outside)));

        // A collapsed chain changed the source document: read it afresh.
        $before = $collapsed ? new CssSelectorMatchCache() : $this->session->sourceStyleResolutionState()->selectorMatchCache;
        $wrapperRules = $this->matched($wrapper, $before, $tokenEntries);
        $childBefore = $this->matched($child, $before, $tokenEntries);
        $descendantsBefore = array_map(fn (DOMElement $node): array => $this->matched($node, $before, $tokenEntries), $descendants);
        $outsideBefore = array_map(fn (DOMElement $node): array => $this->matched($node, $before, $tokenEntries, true), $outside);


        // Apply the fold to the source DOM, read, then restore it exactly.
        $childClass = $child->hasAttribute('class') ? $child->getAttribute('class') : null;
        $childId = $child->hasAttribute('id') ? $child->getAttribute('id') : null;
        $childAttributes = array();
        foreach (array_keys($movedAttributes) as $name) $childAttributes[$name] = $child->hasAttribute($name) ? $child->getAttribute($name) : null;
        foreach (array_keys($projectedAttributes) as $name) $childAttributes[self::projectedAttributeName($name)] = null;
        $childNext = $child->nextSibling;
        $parent->insertBefore($child, $wrapper);
        $parent->removeChild($wrapper);
        $child->setAttribute('class', SourceDom::mergeClassNames(SourceDom::attr($wrapper, 'class'), (string) $childClass));
        if ('' !== $movedId) $child->setAttribute('id', $movedId);
        foreach ($movedAttributes as $name => $value) $child->setAttribute($name, $value);
        foreach ($projectedAttributes as $name => $value) $child->setAttribute(self::projectedAttributeName($name), $value);
        try {
            $after = new CssSelectorMatchCache();
            $childAfter = $this->matched($child, $after, $tokenEntries);
            $descendantsAfter = array_map(fn (DOMElement $node): array => $this->matched($node, $after, $tokenEntries), $descendants);
            $outsideAfter = array_map(fn (DOMElement $node): array => $this->matched($node, $after, $tokenEntries, true), $outside);

            foreach ($wrapperRules as $key => $entry) {
                if (! isset($childAfter[$key])) return self::failure(self::WRAPPER_RULE_NOT_RESTATED, (string) $entry['selector']);
            }
            foreach ($childAfter as $key => $entry) {
                if (! isset($childBefore[$key]) && ! isset($wrapperRules[$key])) return self::failure(self::SELECTOR_MATCH_CHANGED, 'gained:' . $entry['selector']);
            }
            // Lost matches get a compound arm, or refuse the fold.
            $arms = array();
            $lost = array();
            foreach ($childBefore as $key => $entry) {
                if (! isset($childAfter[$key])) $lost[] = array($child, $entry);
            }
            foreach ($descendants as $index => $node) {
                foreach ($descendantsAfter[$index] as $key => $entry) {
                    if (! isset($descendantsBefore[$index][$key])) return self::failure(self::SELECTOR_MATCH_CHANGED, 'gained:' . $entry['selector']);
                }
                foreach ($descendantsBefore[$index] as $key => $entry) {
                    if (! isset($descendantsAfter[$index][$key])) $lost[] = array($node, $entry);
                }
            }
            foreach ($outside as $index => $node) {
                if (array_keys($outsideBefore[$index]) != array_keys($outsideAfter[$index])) return self::failure(self::SELECTOR_MATCH_CHANGED, 'sibling:' . implode(',', array_map(static fn (array $entry): string => (string) $entry['selector'], array_merge(array_diff_key($outsideBefore[$index], $outsideAfter[$index]), array_diff_key($outsideAfter[$index], $outsideBefore[$index])))));
            }
            $entriesByKey = array();
            foreach ($lost as [$node, $entry]) {
                $arm = null;
                if (isset($variants[$entry['key']])) $arm = $this->restoringArm($variants[$entry['key']][0], $armTokens, $node, $after);
                $arm ??= $this->restoringArm((string) $entry['selector'], $tokens, $node, $after);
                if (null === $arm) return self::failure(self::SELECTOR_MATCH_CHANGED, 'lost:' . $entry['selector']);
                $arms[$entry['key']][$arm] = true;
                $entriesByKey[$entry['key']] = $entry;
            }
            // A rule that reaches an element through the projected attribute
            // reaches it, in the output, through the class that replaces it.
            $reachable = array_merge(array($child), $descendants, $outside);
            foreach ($variants as $key => [$text, $parsed]) {
                $base = $this->elementSelector($text);
                foreach ($reachable as $node) {
                    if (! $after->matches($node, $base, $parsed, true)['matches']) continue;
                    $arms[$key][$text] = true;
                    $entriesByKey[$key] = $tokenEntries[$key];
                    break;
                }
            }
            // An arm may only restore: it reaches nothing the selector did not reach before.
            foreach ($arms as $key => $selectorArms) {
                $entry = $entriesByKey[$key];
                foreach (array_keys($selectorArms) as $arm) {
                    $parsed = CssSelectorMatcher::parse($this->elementSelector($arm));
                    $checked = array(array($child, isset($childBefore[$key]) || isset($wrapperRules[$key])));
                    foreach ($descendants as $index => $node) $checked[] = array($node, isset($descendantsBefore[$index][$key]));
                    foreach ($outside as $index => $node) $checked[] = array($node, isset($outsideBefore[$index][$key]));
                    foreach ($checked as [$node, $matchedBefore]) {
                        if ($after->matches($node, $this->elementSelector($arm), $parsed, true)['matches'] && true !== $matchedBefore) return self::failure(self::SELECTOR_MATCH_CHANGED, 'arm:' . $arm);
                    }
                }
            }
        } finally {
            $this->variants = array();
            $parent->insertBefore($wrapper, $child);
            $wrapper->insertBefore($child, $childNext instanceof DOMNode && $childNext->parentNode === $wrapper ? $childNext : null);
            if (null === $childClass) $child->removeAttribute('class'); else $child->setAttribute('class', $childClass);
            if ('' !== $movedId) {
                if (null === $childId) $child->removeAttribute('id'); else $child->setAttribute('id', $childId);
            }
            foreach ($childAttributes as $name => $value) {
                if (null === $value) $child->removeAttribute($name); else $child->setAttribute($name, $value);
            }
        }
        foreach ($arms as $key => $selectorArms) {
            foreach (array_keys($selectorArms) as $arm) {
                if ($this->matchesInSourceDocument($arm, $tokens, $wrapper)) return self::failure(self::SELECTOR_MATCH_CHANGED, 'arm:' . $arm);
            }
        }

        $failure = $this->declarationFailure($wrapperRules, $childBefore, $wrapper, $child, $evidence);
        if (null !== $failure) return $failure;

        $armsBySelector = array();
        foreach ($arms as $key => $selectorArms) {
            $selector = trim((string) $entriesByKey[$key]['selector']);
            $armsBySelector[$selector] = array_values(array_unique(array_merge($armsBySelector[$selector] ?? array(), array_keys($selectorArms))));
        }
        return array('failure' => null, 'detail' => '', 'arms' => $armsBySelector);
    }

    /**
     * Each declaration a wrapper rule brings to the child must be one the
     * fold restates: see the class comment.
     *
     * @param array<string, array<string, mixed>> $wrapperRules
     * @param array<string, array<string, mixed>> $childRules
     * @return array{failure: string, detail: string, arms: array<string, list<string>>}|null
     */
    private function declarationFailure(array $wrapperRules, array $childRules, DOMElement $wrapper, DOMElement $child, BoxTreeWrapperEvidence $evidence): ?array
    {
        $competitors = array();
        foreach ($childRules as $key => $entry) {
            if (isset($wrapperRules[$key]) || '' !== self::withoutPseudoElement((string) $entry['selector'])[1]) continue;
            foreach ($entry['declarations'] as $property => $value) {
                $competitors[] = array('property' => (string) $property, 'value' => (string) $value, 'precedence' => self::precedence($entry), 'inline' => false);
            }
        }
        foreach (SourceDeclarations::parse(SourceDom::attr($child, 'style')) as $property => $value) {
            $competitors[] = array('property' => (string) $property, 'value' => (string) $value, 'precedence' => array(0, 0), 'inline' => true);
        }
        // The child's percentages resolve against its containing block, the
        // wrapper before the fold and the wrapper's parent after it.
        $fontSized = false;
        $inset = $evidence->insetTransfersTo($wrapper, $child);
        foreach ($competitors as $other) {
            $property = strtolower($other['property']);
            if ($inset && in_array($property, self::SIZE_PROPERTIES, true)) {
                return self::failure(self::CHILD_RELATIVE_TO_WRAPPER, $property . ':inset_sizing');
            }
            if (in_array($property, array('font', 'font-size'), true)) $fontSized = true;
            if (! $this->isInherited($property) && str_contains($other['value'], '%')) {
                return self::failure(self::CHILD_RELATIVE_TO_WRAPPER, $property);
            }
        }
        foreach ($wrapperRules as $key => $entry) {
            $pseudo = self::withoutPseudoElement((string) $entry['selector'])[1];
            if ('' !== $pseudo && ! isset($childRules[$key])) {
                foreach ($childRules as $other) {
                    $otherPseudo = self::withoutPseudoElement((string) $other['selector'])[1];
                    if ('' !== $otherPseudo && ltrim($pseudo, ':') === ltrim($otherPseudo, ':')) return self::failure(self::WRAPPER_RULE_NOT_RESTATED, 'generated_box_collision:' . $entry['selector']);
                }
            }
            // A rule that already reached the child brings nothing new.
            if (isset($childRules[$key])) continue;
            // Generated-box declarations style the pseudo-element, not its host.
            // The geometry proof and host match proof move that box with its token.
            if ('' !== self::withoutPseudoElement((string) $entry['selector'])[1]) continue;
            $precedence = self::precedence($entry);
            foreach ($entry['declarations'] as $property => $value) {
                $property = strtolower((string) $property);
                $value = (string) $value;
                $overlapping = array_values(array_filter($competitors, static fn (array $other): bool => self::covers($other['property'], $property) || self::covers($property, $other['property'])));
                if ($this->isInherited($property)) {
                    // Inherited, a relative length was resolved against the
                    // wrapper's font; declared on a child with its own font
                    // size it would resolve against the child's.
                    if ($fontSized && ! in_array($property, array('font', 'font-size'), true) && 1 === preg_match('/\d(?:em|ex|ch|lh|%)\b|\d%/i', $value)) {
                        return self::failure(self::INHERITED_TEXT_OVERRIDE, $property . ':relative');
                    }
                    foreach ($overlapping as $other) {
                        // The child's own declaration must win wherever it
                        // applies, as it did over the inherited value.
                        if (! self::covers($other['property'], $property) || ! self::outranks($other, $precedence, $value)) {
                            return self::failure(self::INHERITED_TEXT_OVERRIDE, $property);
                        }
                        // A relative font size the child keeps would now resolve against the wrapper's parent.
                        if (in_array($property, array('font', 'font-size'), true) && 1 === preg_match('/(?:\d(?:em|ex|ch|%))|smaller|larger/i', $other['value'])) {
                            return self::failure(self::INHERITED_TEXT_OVERRIDE, $property . ':relative');
                        }
                    }
                    continue;
                }
                if (array() !== $overlapping) return self::failure(self::WRAPPER_DECLARATION_CONTESTED, $property);
                if (! $this->restatesOnChild($property, CssValueInspector::comparable(CssValueInspector::withoutImportant($value)), $wrapper, $child, $evidence)) {
                    return self::failure(self::WRAPPER_DECLARATION_UNRESTATABLE, $property);
                }
            }
        }
        return null;
    }

    private function isInherited(string $property): bool
    {
        return str_starts_with($property, '--')
            || ($this->isInheritedTextProperty)($property)
            || in_array($property, self::OTHER_INHERITED_PROPERTIES, true);
    }

    /** Whether a box declaration the child does not contest lands on the child without changing what it renders. */
    private function restatesOnChild(string $property, string $value, DOMElement $wrapper, DOMElement $child, BoxTreeWrapperEvidence $evidence): bool
    {
        // The evidence already proved the wrapper's float layout-neutral: it
        // fills its parent's content box, as the child fills it.
        if ('position' === $property && 'relative' === $value) return $evidence->positionTransfersTo($wrapper, $child);
        if ('float' === $property) return null !== $evidence->usedValues($wrapper, 'float');
        if ('opacity' === $property) return self::observedEverywhere($evidence, $child, 'opacity', array('1', '1.0'));
        if (in_array($property, self::SIZE_PROPERTIES, true)) return $evidence->sizeTransfersTo($child);
        if ('box-sizing' === $property) return self::observedEverywhere($evidence, $child, 'box-sizing', array($value));
        if (1 === preg_match('/^margin(?:-(?:top|right|bottom|left|block|inline)(?:-(?:start|end))?)?$/', $property)) return true;
        if (in_array($property, self::ITEM_PROPERTIES, true)) return true;
        if ('display' === $property) return 'block' === $value && self::observedEverywhere($evidence, $child, 'display', array('block'));
        if (in_array($property, self::CONTAINER_PROPERTIES, true)) return self::observedEverywhere($evidence, $child, 'display', array('block', 'flow-root'));
        if (1 === preg_match('/^padding(?:-(?:top|right|bottom|left|block|inline)(?:-(?:start|end))?)?$/', $property)) return 1 === preg_match('/^(?:0(?:px)?\s*){1,4}$/', $value) || $evidence->insetTransfersTo($wrapper, $child);
        if (1 === preg_match('/^border(?:-(?:top|right|bottom|left))?(?:-(?:width|style))?$/', $property)) return 1 === preg_match('/^(?:(?:0(?:px)?|none)\s*)+$/', $value);
        return in_array($value, self::NEUTRAL_VALUES[$property] ?? array(), true);
    }

    /** @param list<string> $values */
    private static function observedEverywhere(BoxTreeWrapperEvidence $evidence, DOMElement $element, string $property, array $values): bool
    {
        $observed = $evidence->usedValues($element, $property);
        if (null === $observed || array() === $observed) return false;
        foreach ($observed as $value) {
            if (! in_array($value, $values, true)) return false;
        }
        return true;
    }

    /** Whether declaring `$a` sets `$b`: the same property, or a shorthand that includes it. */
    private static function covers(string $a, string $b): bool
    {
        if ($a === $b || str_starts_with($b, $a . '-')) return true;
        return match ($a) {
            'font' => 'line-height' === $b,
            'inset' => in_array($b, array('top', 'right', 'bottom', 'left'), true),
            'gap' => in_array($b, array('row-gap', 'column-gap'), true),
            'flex-flow' => in_array($b, array('flex-direction', 'flex-wrap'), true),
            'white-space' => in_array($b, array('white-space-collapse', 'text-wrap', 'text-wrap-mode'), true),
            'text-wrap' => in_array($b, array('text-wrap-mode', 'text-wrap-style'), true),
            'all' => true,
            default => false,
        };
    }

    /**
     * @param array{property: string, value: string, precedence: array{0: int, 1: int}, inline: bool} $other
     * @param array{0: int, 1: int} $precedence
     */
    private static function outranks(array $other, array $precedence, string $value): bool
    {
        $otherImportant = CssValueInspector::isImportant($other['value']);
        $important = CssValueInspector::isImportant($value);
        if ($otherImportant !== $important) return $otherImportant;
        if ($other['inline']) return true;
        return $other['precedence'] > $precedence;
    }

    /** @param array<string, mixed> $entry @return array{0: int, 1: int} */
    private static function precedence(array $entry): array
    {
        return array(CssSelectorMatcher::specificity($entry['parsed'] ?? array()), (int) $entry['rule_order']);
    }

    /**
     * The selector with the compound naming a moved token merged into the
     * compound after it (`.col .block` to `.col.block`, `.col > .x` to
     * `.col.x`), if that form matches `$node` after the fold.
     *
     * @param array<string, true> $tokens
     */
    private function restoringArm(string $selector, array $tokens, DOMElement $node, CssSelectorMatchCache $after): ?string
    {
        // A trailing pseudo-element (`.tok *::selection`) stays on the arm
        // (`.tok::selection`); its originating element is what must match.
        [$selector, $pseudoElement] = self::withoutPseudoElement($selector);
        $tokenized = CssSelectorTokenizer::tokenize(trim($selector));
        if (! $tokenized['supported']) return null;
        $compounds = $tokenized['compounds'];
        $combinators = $tokenized['combinators'];
        for ($index = 0; $index < count($combinators); ++$index) {
            if (! in_array(trim($combinators[$index]), array('', '>'), true) || ! self::namesToken($compounds[$index], $tokens)) continue;
            $merged = self::mergedCompound($compounds[$index], $compounds[$index + 1]);
            if (null === $merged) continue;
            $parts = array_merge(array_slice($compounds, 0, $index), array($merged), array_slice($compounds, $index + 2));
            $joins = array_merge(array_slice($combinators, 0, $index), array_slice($combinators, $index + 1));
            $arm = $parts[0];
            foreach ($joins as $offset => $join) {
                $join = trim($join);
                $arm .= ('' === $join ? ' ' : ' ' . $join . ' ') . $parts[$offset + 1];
            }
            $parsed = CssSelectorMatcher::parse($this->elementSelector($arm));
            if ($parsed['supported'] && $after->matches($node, $this->elementSelector($arm), $parsed, true)['matches']) return $arm . $pseudoElement;
        }
        return null;
    }

    /** @param array<string, true> $tokens */
    private static function namesToken(string $compound, array $tokens): bool
    {
        foreach (array_keys($tokens) as $token) {
            if (1 === preg_match('/' . preg_quote($token, '/') . '(?![\w-])/', $compound)) return true;
        }
        return false;
    }

    private static function mergedCompound(string $left, string $right): ?string
    {
        if (str_contains($left, '::') || str_contains($left, '|') || str_contains($right, '|')) return null;
        $type = static fn (string $compound): array => 1 === preg_match('/^(\*|[a-zA-Z][\w-]*)(.*)$/s', $compound, $match) ? array(strtolower($match[1]), $match[2]) : array('', $compound);
        [$leftType, $leftRest] = $type($left);
        [$rightType, $rightRest] = $type($right);
        if ('*' === $leftType) $leftType = '';
        if ('*' === $rightType) $rightType = '';
        if ('' !== $leftType && '' !== $rightType && $leftType !== $rightType) return null;
        $merged = ('' !== $leftType ? $leftType : $rightType) . $leftRest . $rightRest;
        return '' === $merged ? null : $merged;
    }

    /**
     * Whether `$arm` matches any element of the source document as it is,
     * outside the folded wrapper: nothing else should carry the merged
     * compound.
     *
     * @param array<string, true> $tokens
     */
    private function matchesInSourceDocument(string $arm, array $tokens, DOMElement $wrapper): bool
    {
        $document = $wrapper->ownerDocument;
        if (! $document instanceof DOMDocument) return true;
        $arm = $this->elementSelector($arm);
        $parsed = CssSelectorMatcher::parse($this->elementSelector($arm));
        if (! $parsed['supported']) return true;
        $xpath = new DOMXPath($document);
        $cache = $this->session->sourceStyleResolutionState()->selectorMatchCache;
        $checked = 0;
        foreach (array_keys($tokens) as $token) {
            $query = '.' === $token[0]
                ? "//*[contains(concat(' ', normalize-space(@class), ' '), concat(' ', " . self::xpathLiteral(substr($token, 1)) . ", ' '))]"
                : '//*[@id=' . self::xpathLiteral(substr($token, 1)) . ']';
            foreach ($xpath->query($query) ?: array() as $holder) {
                if (! $holder instanceof DOMElement) continue;
                foreach (array_merge(array($holder), SourceDom::descendantElements($holder)) as $node) {
                    // The folded wrapper leaves the output, and its subtree was proven under the fold.
                    if ($node === $wrapper || $wrapper->contains($node)) continue;
                    if (++$checked > self::MAX_CHECKED_ELEMENTS * 4) return true;
                    if ($cache->matches($node, $arm, $parsed, true)['matches']) return true;
                }
            }
        }
        return false;
    }

    /**
     * The selector and its trailing pseudo-element (`::selection`,
     * `:before`), split; the pseudo-element is '' when there is none.
     *
     * @return array{0: string, 1: string}
     */
    private static function withoutPseudoElement(string $selector): array
    {
        $selector = trim($selector);
        if (1 !== preg_match('/^(.*?)((?:::?(?:before|after|first-line|first-letter|marker|placeholder|-moz-placeholder|-ms-input-placeholder|selection|backdrop|file-selector-button)|::[a-z-]+(?:\([^)]*\))?))$/is', $selector, $match)) return array($selector, '');
        return '' === trim($match[1]) ? array($selector, '') : array($match[1], $match[2]);
    }

    private static function xpathLiteral(string $value): string
    {
        if (! str_contains($value, "'")) return "'" . $value . "'";
        if (! str_contains($value, '"')) return '"' . $value . '"';
        return "concat('" . str_replace("'", "', \"'\", '", $value) . "')";
    }

    /**
     * Author rules matching `$node`: its indexed candidates plus every rule
     * naming a moved token, keyed by selector key.
     *
     * @param array<string, array<string, mixed>> $tokenEntries
     * @return array<string, array<string, mixed>>
     */
    private function matched(DOMElement $node, CssSelectorMatchCache $cache, array $tokenEntries, bool $tokenEntriesOnly = false): array
    {
        $matched = array();
        $candidates = $tokenEntriesOnly ? array() : $this->session->authorStyleRuleCandidates($node, $cache);
        foreach (array_merge($candidates, array_values($tokenEntries)) as $entry) {
            $key = (string) $entry['key'];
            $matchable = isset($matched[$key]) ? null : $this->matchable($entry);
            if (null === $matchable) continue;
            if ($cache->matches($node, $matchable[0], $matchable[1], true)['matches']) {
                $matched[$key] = $entry;
                continue;
            }
            $variant = $this->variants[$key] ?? null;
            if (null !== $variant && $cache->matches($node, $this->elementSelector($variant[0]), $variant[1], true)['matches']) $matched[$key] = $entry;
        }
        return $matched;
    }

    /**
     * The selector text and parse this matcher evaluates for an entry: the
     * authored form, or the form without a trailing pseudo-element.
     *
     * @param array<string, mixed> $entry
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function matchable(array $entry): ?array
    {
        // Two independent dynamic predicates need more than the two uniform
        // truth assignments. Keep them until a per-element state proof exists.
        if (preg_match_all(self::statePattern(), (string) $entry['selector']) > 1) return null;
        $text = $this->elementSelector((string) $entry['selector']);
        $parsed = CssSelectorMatcher::parse($text);
        return ($parsed['supported'] ?? false) ? array($text, $parsed) : null;
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function tokenSelectors(): array
    {
        if (null !== $this->tokenSelectors) return $this->tokenSelectors;
        $index = array();
        foreach ($this->session->authorStyleAnalysis()->styleRules() as $rule) {
            foreach ($rule['selectors'] as $selectorIndex => $selector) {
                $text = (string) $selector['selector'];
                $tokens = array();
                if (preg_match_all('/([.#])(-?(?:[_a-zA-Z\x80-\xff]|\\\\.)(?:[\w\-\x80-\xff]|\\\\.)*)/', $text, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) $tokens[] = $match[1] . stripslashes($match[2]);
                }
                // Attribute predicates index under `[name`.
                if (preg_match_all('/\[\s*(data-[\w-]+)\s*[\]~|^$*=]/i', $text, $matches)) {
                    foreach ($matches[1] as $name) $tokens[] = '[' . strtolower($name);
                }
                if (array() === $tokens) continue;
                $entry = array_merge($selector, array('declarations' => $rule['declarations'], 'rule_order' => $rule['order'], 'key' => $rule['order'] . ':' . $selectorIndex, 'source_path' => $rule['source_path'] ?? '', 'source_hash' => $rule['source_hash'] ?? ''));
                foreach ($tokens as $token) {
                    $index[$token][$entry['key']] = $entry;
                }
            }
        }
        return $this->tokenSelectors = array_map('array_values', $index);
    }

    /**
     * The selector with every predicate on attribute `$name` reading
     * `$projected` instead; null when one sits inside a functional
     * pseudo-class (`:not([data-x])`), whose meaning the substitution does
     * not keep.
     */
    private static function withProjectedAttribute(string $selector, string $name, string $projected): ?string
    {
        $pattern = '/(\[\s*)' . preg_quote($name, '/') . '(?=\s*[\]~|^$*=])/i';
        if (! preg_match_all($pattern, $selector, $matches, PREG_OFFSET_CAPTURE)) return $selector;
        foreach ($matches[0] as [, $offset]) {
            $prefix = substr($selector, 0, (int) $offset);
            if (substr_count($prefix, '(') > substr_count($prefix, ')')) return null;
        }
        return (string) preg_replace($pattern, '${1}' . $projected, $selector);
    }

    /** @return array{failure: string, detail: string, arms: array<string, list<string>>} */
    private static function failure(string $failure, string $detail): array
    {
        return array('failure' => $failure, 'detail' => $detail, 'arms' => array());
    }
}
