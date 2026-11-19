<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\Contract\RichTextInlineTags;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceDeclarations;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSubjectIndex;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\EntranceAnimation;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceCascade;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\SourceElementDescription;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\EmptyElementFootprint;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/**
 * Decides which source elements render nothing of their own: render-neutral
 * wrappers, custom elements whose subtree author CSS leaves empty, and unknown
 * elements that are safe to treat as transparent.
 */
final class RenderNeutralElements
{
    /** Subtree tags that render media, graphics, or accept interaction; any of them keeps the host on its existing disposition. */
    private const SUBTREE_RENDERING_TAGS = array(
        'a', 'audio', 'base', 'button', 'canvas', 'details', 'dialog', 'embed', 'fieldset', 'form',
        'iframe', 'img', 'input', 'label', 'link', 'map', 'meta', 'meter', 'object', 'output',
        'picture', 'progress', 'script', 'select', 'slot', 'source', 'style', 'svg', 'template',
        'textarea', 'track', 'video',
    );

    /** URL-bearing attributes; a nonempty value means the subtree references rendered content elsewhere. */
    private const SUBTREE_URL_ATTRIBUTES = array( 'src', 'srcset', 'href', 'data-src', 'data-url' );

    /**
     * Landmark (non-widget) roles a named custom element may carry, and the
     * core/group tag that keeps them. `group` has no native tag core/group can
     * save, so it would lose its role and stays on the fallback path.
     */
    private const LANDMARK_ROLE_TAGS = array(
        'region' => 'section',
        'complementary' => 'aside',
        'navigation' => 'nav',
        'search' => 'search',
    );

    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly SourceElementDescription $sourceElementDescription,
        private readonly RuntimeIslandAnalyzer $runtimeIslands,
        private readonly EmptyElementFootprint $emptyElementFootprint
    ) {
    }

    /**
     * Whether a wrapper instance carries no identity, behavior, or presentation
     * of its own, and can therefore be replaced by its children.
     *
     * `$allowInertPositioning` lets an offsetless `position: relative` pass
     * for callers that have proven the wrapper is neither a containing block
     * nor a paint layer anything depends on.
     */
    public function isRenderNeutralWrapper(DOMElement $element, bool $stylesPrepared = true, bool $ignoreEmptyHostResets = false, bool $allowInertPositioning = false): bool
    {
        return $this->renderNeutralWrapper($element, $stylesPrepared, $ignoreEmptyHostResets, $allowInertPositioning, true);
    }

    /**
     * Whether a wrapper generates no box and carries nothing a descendant
     * inherits: every matched author declaration is `display: contents` or a
     * zero box-model reset, and no pseudo-element rule can render on it.
     * Unlike a render-neutral wrapper, inherited text declarations count as
     * presentation, because they live on the wrapper's own rule.
     */
    public function isBoxlessWrapper(DOMElement $element): bool
    {
        return $this->renderNeutralWrapper($element, true, true, false, false);
    }

    private function renderNeutralWrapper(DOMElement $element, bool $stylesPrepared, bool $ignoreEmptyHostResets, bool $allowInertPositioning, bool $allowInheritedText): bool
    {
        // Custom-element convention removal precedes selector preparation.
        // Keep its original conservative contract; effect-based checks belong
        // to flow lowering, once the author rule index is available.
        if (!$stylesPrepared) {
            return null !== $element->parentNode
                && '' === trim(SourceDom::attr($element, 'id'))
                && '' === trim(SourceDom::attr($element, 'class'))
                && '' === trim(SourceDom::attr($element, 'style'))
                && '' === trim(SourceDom::attr($element, 'role'))
                && array() === $this->sourceElementDescription->interactiveAttributes($element)
                && !$this->runtimeIslands->isRuntimeDomTarget($element)
                && !$this->sourceElementClassifier->hasMotionStructureToken($element);
        }
        if (null === $element->parentNode
            || '' !== trim(SourceDom::attr($element, 'role'))
            || array() !== $this->sourceElementDescription->interactiveAttributes($element)
            || $this->runtimeIslands->isRuntimeDomTarget($element)
            || $this->sourceElementClassifier->hasMotionStructureToken($element)
        ) {
            return false;
        }
        // Attribute spelling is not presentation. Check matched author rules
        // (including conditional rules) as well as the inline declarations.
        $declarations = SourceDeclarations::inline($element);
        foreach ($this->session->authorStyleRuleCandidates($element) as $candidate) {
            if (! ($candidate['parsed']['supported'] ?? false)) {
                // Pseudo-element resets are indexed conservatively as
                // unsupported host candidates. They still cannot create a box
                // without content or other non-neutral declarations.
                $hostSelector = preg_replace('/::?(?:before|after)\s*$/i', '', $candidate['selector']);
                $parsedHost = CssSelectorMatcher::parse($hostSelector);
                if ( $ignoreEmptyHostResets && $hostSelector !== $candidate['selector']
                    && ($parsedHost['supported'] ?? false)
                    && array(array('universal', '')) === CssSubjectIndex::buckets($parsedHost)
                    && array() === array_filter($candidate['declarations'], fn (string $value, string $property): bool => ! $this->isEmptyHostReset($property, $value), ARRAY_FILTER_USE_BOTH)
                ) {
                    continue;
                }
                if ( $ignoreEmptyHostResets && $this->pseudoElementRuleCannotPaintEmptyHost($element, $candidate['selector']) ) {
                    continue;
                }
                // A pseudo-element rule whose host cannot match this wrapper
                // cannot style it, so it does not veto neutrality.
                if ( false === $this->pseudoElementHostMatches($element, $candidate['selector'], true) ) {
                    continue;
                }
                return false;
            }
            if ($this->session->sourceStyleResolutionState()->selectorMatchCache->matches($element, $candidate['selector'], $candidate['parsed'])['matches']) {
                $matched = $candidate['declarations'];
                // A global reset is not host-owned presentation. Only ignore
                // declarations that cannot give this empty host a footprint;
                // targeted rules and universal paint/geometry remain evidence.
                if ( $ignoreEmptyHostResets && array(array('universal', '')) === CssSubjectIndex::buckets($candidate['parsed']) ) {
                    $matched = array_filter($matched, fn (string $value, string $property): bool => ! $this->isEmptyHostReset($property, $value), ARRAY_FILTER_USE_BOTH);
                }
                $declarations = array_merge($declarations, $matched);
            }
        }
        $inheritedText = array('white-space', 'color', 'font', 'font-family', 'font-size', 'font-style', 'font-weight', 'font-variant', 'line-height', 'letter-spacing', 'word-spacing', 'text-align', 'text-transform', 'text-indent');
        foreach ($declarations as $property => $value) {
            if (!($allowInheritedText && in_array($property, $inheritedText, true)) && !('display' === $property && 'contents' === trim($value))
                && !($allowInertPositioning && 'position' === $property && in_array(trim(CssValueInspector::comparable($value)), array('relative', 'static'), true))) {
                return false;
            }
        }
        return true;
    }

    /**
     * Configuration alone may describe a runtime placeholder, but semantic
     * attributes can describe missing visible output or a control. Only the
     * former is safe to omit (or retain as a paint-free spacing group) before
     * recording an unproven-runtime finding.
     * The caller must already have established that the runtime is unproven.
     */
    public function isEmptyRenderNeutralConfiguredHost(DOMElement $element): bool
    {
        if ( 0 !== SourceDom::childElementCount($element)
            || '' !== trim($element->textContent ?? '')
            || $this->sourceElementClassifier->isRuntimeMediaSurfaceElement($element)
            || $this->sourceElementClassifier->hasCommerceToken($element, array( 'checkout', 'cart' ))
            || $this->sourceElementClassifier->hasTokenBoundary($element->tagName, 'checkout')
            || $this->sourceElementClassifier->hasTokenBoundary($element->tagName, 'cart')
            || ! $this->runtimeIslands->isSafeTransparentCustomElement($element, false, true)
            || ( ! $this->isRenderNeutralWrapper($element, true, true) && ! $this->configuredHostStylesArePaintFree($element) )
        ) {
            return false;
        }

        // These attributes describe output, state or form participation, not
        // opaque configuration. Even a textless host can be a visible widget.
        foreach ( array( 'title', 'alt', 'label', 'placeholder', 'name', 'type', 'value', 'status', 'checked', 'selected', 'disabled', 'required', 'readonly', 'form', 'for', 'href', 'src', 'srcset', 'width', 'height' ) as $attribute ) {
            if ( $element->hasAttribute($attribute) ) {
                return false;
            }
        }
        foreach ( $element->attributes as $attribute ) {
            if ( str_starts_with(strtolower($attribute->name), 'aria-') ) {
                return false;
            }
        }
        $id = SourceDom::namedFragmentTargetId($element);
        return '' === $id || ! SourceDom::documentReferencesFragmentId($element, $id);
    }

    /**
     * Only the empty configured-host path may retain geometry without paint.
     * Inspect every matching rule, including conditional rules, rather than a
     * single viewport's computed style. Unknown effects stay on the fallback path.
     */
    private function configuredHostStylesArePaintFree(DOMElement $element): bool
    {
        if ( null === $element->parentNode
            || '' !== trim(SourceDom::attr($element, 'role'))
            || array() !== $this->sourceElementDescription->interactiveAttributes($element)
            || $this->runtimeIslands->isRuntimeDomTarget($element)
            || $this->sourceElementClassifier->hasMotionStructureToken($element)
        ) {
            return false;
        }
        $declarations = SourceDeclarations::inline($element);
        $reveal = $this->configuredHostHasEntranceReveal($element, $declarations);
        foreach ( $this->session->authorStyleRuleCandidates($element) as $candidate ) {
            $selector = $candidate['selector'];
            $parsed = $candidate['parsed'];
            if ( ! ($parsed['supported'] ?? false) ) {
                // Shared with the render-neutral drop: unreachable or boxless
                // pseudo-elements and global resets do not paint this host.
                if ( $this->pseudoElementRuleCannotPaintEmptyHost($element, $selector) ) {
                    continue;
                }
                $pseudo = CssSelectorMatcher::pseudoElementHost($selector);
                if ( null === $pseudo ) {
                    return false;
                }
                if ( $this->session->sourceStyleResolutionState()->selectorMatchCache->matches($element, $pseudo['selector'], $pseudo['parsed'])['matches']
                    && array() !== array_filter($candidate['declarations'], fn (string $value, string $property): bool => ! $this->isEmptyHostReset($property, $value), ARRAY_FILTER_USE_BOTH)
                ) {
                    return false;
                }
                continue;
            }
            if ( $this->session->sourceStyleResolutionState()->selectorMatchCache->matches($element, $selector, $parsed)['matches'] ) {
                // Do not let a later declaration hide possible conditional paint.
                if ( ! $this->configuredHostDeclarationsArePaintFree($candidate['declarations'], $reveal) ) {
                    return false;
                }
            }
        }
        return $this->configuredHostDeclarationsArePaintFree($declarations, $reveal);
    }

    /**
     * A finite entrance reveal (fade/translate in) moves or fades an empty
     * host's box; it paints nothing the placeholder would not already omit.
     *
     * @param array<string, string> $inline
     */
    private function configuredHostHasEntranceReveal(DOMElement $element, array $inline): bool
    {
        $declarations = $inline;
        foreach ( $this->session->authorStyleRuleCandidates($element) as $candidate ) {
            if ( ($candidate['parsed']['supported'] ?? false)
                && $this->session->sourceStyleResolutionState()->selectorMatchCache->matches($element, $candidate['selector'], $candidate['parsed'])['matches']
            ) {
                $declarations = array_merge($declarations, $candidate['declarations']);
            }
        }
        return EntranceAnimation::matches(
            $declarations,
            $this->session->authorStyleAnalysis()->combinedCss(),
            (new SourceCascade($this->session->sourceStyleResolutionState()))->customProperties($element)
        );
    }

    /** @param array<string, string> $declarations */
    private function configuredHostDeclarationsArePaintFree(array $declarations, bool $reveal = false): bool
    {
        foreach ( $declarations as $property => $value ) {
            // Opacity and transitions cannot paint an empty host; reveal
            // motion is only accepted once the keyframes prove a reveal.
            if ( 'opacity' === $property || 'z-index' === $property || str_starts_with($property, 'transition')
                || ('position' === $property && in_array(strtolower(trim($value)), array('static', 'relative'), true))
                || ($reveal && (str_starts_with($property, 'animation') || in_array($property, array('transform', 'translate'), true)))
            ) {
                continue;
            }
            if ( in_array($property, array(
                'white-space', 'color', 'font', 'font-family', 'font-size', 'font-style', 'font-weight',
                'font-variant', 'line-height', 'letter-spacing', 'word-spacing', 'text-align',
                'text-transform', 'text-indent', 'display', 'box-sizing',
            ), true) || 1 === preg_match('/^(?:(?:padding|margin)(?:-(?:top|right|bottom|left|block(?:-start|-end)?|inline(?:-start|-end)?))?|(?:(?:min|max)-)?(?:width|height|inline-size|block-size)|(?:row-|column-)?gap)$/', $property) ) {
                continue;
            }
            return false;
        }
        return true;
    }

    /**
     * Pseudo-element rules are indexed as unsupported candidates for every
     * host. Their subject compound still decides whether they can reach this
     * host: a rule whose host part does not match cannot style it, and
     * pseudo-elements other than ::before/::after generate no box on an empty
     * custom element. Host-matching ::before/::after rules stay evidence.
     */
    private function pseudoElementRuleCannotPaintEmptyHost(DOMElement $element, string $selector): bool
    {
        $pseudo = $this->trailingElementPseudo($selector);
        $matches = $this->pseudoElementHostMatches($element, $selector);
        if ( null === $pseudo || null === $matches ) {
            return false;
        }
        return ! $matches || ! in_array(preg_replace('/\(.*$/', '', $pseudo), array('before', 'after'), true);
    }

    /** The trailing element pseudo of a selector (`before`, `placeholder`, ...), or null. */
    private function trailingElementPseudo(string $selector): ?string
    {
        if ( 1 !== preg_match('/(::?-?[a-z][a-z0-9-]*(?:\([^()]*\))?)\s*$/i', trim($selector), $suffix) ) {
            return null;
        }
        $pseudo = strtolower(ltrim($suffix[1], ':'));
        return str_starts_with($suffix[1], '::') || in_array($pseudo, array('before', 'after', 'first-line', 'first-letter'), true)
            ? $pseudo
            : null;
    }

    /**
     * Whether a trailing pseudo-element rule's host compound, with its
     * ancestor constraints, matches this element. Null when the selector has
     * no trailing pseudo-element or its host is outside the static matcher.
     * With $anyState, user-action states (`:hover`) on the host may match.
     */
    private function pseudoElementHostMatches(DOMElement $element, string $selector, bool $anyState = false): ?bool
    {
        if ( 1 !== preg_match('/(::?-?[a-z][a-z0-9-]*(?:\([^()]*\))?)\s*$/i', trim($selector), $suffix)
            || null === $this->trailingElementPseudo($selector)
        ) {
            return null;
        }
        $host = trim(substr(trim($selector), 0, -strlen($suffix[1])));
        if ( '' === $host || 1 === preg_match('/[\s>+~(]$/', $host) ) {
            $host .= '*';
        }
        $parsed = CssSelectorMatcher::parse($host);
        if ( ! ($parsed['supported'] ?? false) ) {
            return null;
        }
        return $this->session->sourceStyleResolutionState()->selectorMatchCache->matches($element, $host, $parsed, $anyState)['matches'];
    }

    /** Global box-model resets do not paint or size an otherwise empty host. */
    private function isEmptyHostReset(string $property, string $value): bool
    {
        if ( 'box-sizing' === $property ) {
            return true;
        }
        if ( 1 !== preg_match('/^(?:margin|padding)(?:-(?:top|right|bottom|left|block|inline)(?:-(?:start|end))?)?$/', $property) ) {
            return false;
        }
        return 1 === preg_match('/^[+-]?0(?:\.0+)?(?:[a-z]+|%)?(?:\s+[+-]?0(?:\.0+)?(?:[a-z]+|%)?){0,3}$/', CssValueInspector::comparable($value));
    }

    /**
     * The core/group a custom element's landmark semantics lower to: a
     * landmark role (no widget role) with an accessible name that core/group
     * can carry as `ariaLabel`. Returns null for anything else, including an
     * unresolvable `aria-labelledby`, so those hosts keep their existing
     * disposition.
     *
     * @return array{tagName: string, ariaLabel: string}|null
     */
    public function namedLandmark(DOMElement $element): ?array
    {
        if ( ! str_contains($element->tagName, '-') ) {
            return null;
        }
        $tagName = self::LANDMARK_ROLE_TAGS[strtolower(trim(SourceDom::attr($element, 'role')))] ?? null;
        if ( null === $tagName ) {
            return null;
        }
        $label = trim(SourceDom::attr($element, 'aria-label'));
        $labelledBy = array_filter(SourceDom::tokens(SourceDom::attr($element, 'aria-labelledby')), static fn (string $id): bool => '' !== $id);
        if ( array() !== $labelledBy ) {
            // aria-labelledby wins the accessible name; carry its text.
            $parts = array();
            foreach ( $labelledBy as $id ) {
                $reference = $element->ownerDocument?->getElementById($id);
                if ( null === $reference ) {
                    foreach ( $element->ownerDocument?->getElementsByTagName('*') ?? array() as $candidate ) {
                        if ( $candidate instanceof DOMElement && $id === $candidate->getAttribute('id') ) {
                            $reference = $candidate;
                            break;
                        }
                    }
                }
                if ( ! $reference instanceof DOMElement ) {
                    return null;
                }
                $parts[] = trim((string) preg_replace('/\s+/u', ' ', $reference->textContent ?? ''));
            }
            $label = trim(implode(' ', $parts));
        }

        return '' === $label ? null : array( 'tagName' => $tagName, 'ariaLabel' => $label );
    }

    /** A neutral autonomous host whose ordinary light DOM needs no component lowering. */
    public function isRenderNeutralCustomFlowWrapper(DOMElement $element): bool
    {
        if ( ! RichTextInlineTags::isUnknownHtmlElement($element)
            || ! CustomElementRuntimeDependency::isCustomElementTag(strtolower($element->tagName))
            || '' !== trim(SourceDom::attr($element, 'class'))
            || '' !== trim(SourceDom::attr($element, 'id'))
            || $element->hasAttribute('hidden')
            || ! $this->isRenderNeutralWrapper($element)
            || ! $this->runtimeIslands->isSafeTransparentCustomElement($element, false, true)
        ) {
            return false;
        }

        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if ( 'none' === (SourceDeclarations::inline($descendant)['display'] ?? '') ) {
                return false;
            }
        }

        $children = array();
        foreach ( $element->childNodes as $child ) {
            if ( XML_COMMENT_NODE === $child->nodeType || ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) ) {
                continue;
            }
            // Native buttons (including icon-only buttons) are ordinary light
            // DOM content too; their own converter retains control semantics.
            if ( ! $child instanceof DOMElement || ( 'button' !== strtolower($child->tagName) && ! $this->sourceElementClassifier->isStructuralTransparentCustomWrapperChild($child) ) ) {
                return false;
            }
            $children[] = $child;
        }

        // Repeated siblings may be a cohesive component; retain that existing
        // disposition rather than reclassifying collections as wrappers.
        return 1 === count($children)
            && ( '' !== trim($element->textContent ?? '') || 'button' === strtolower($children[0]->tagName) );
    }

    /**
     * A hyphenated custom element whose whole subtree renders nothing: no
     * text, no media, graphics, or iframes, no form or interactive controls,
     * no runtime or event metadata, no accessible role or name, no
     * URL-bearing attributes, and authored presentation limited to `display`
     * and box-neutral positioning in stylesheet rules addressing the tag
     * (inline styles remain display-only). Author
     * CSS reaching the subtree through a descendant's class, id, or
     * hyphenated tag name must not paint or occupy space either: a child
     * styled by `.rule-line{height:1px;background:#ccc}` is a visible
     * divider, not an empty slot. The host itself carries no authored
     * attributes beyond an anchor `id` and such geometry-neutral inline
     * styles — a classed or configured host stays on its explicit diagnostic
     * path. Nothing visible or interactive is lost by omitting such a slot,
     * so it leaves no blocks and no unsupported-element fallback behind;
     * identity attributes on its placeholder children (`class`, `id`,
     * `data-hook`) are not runtime metadata and do not disqualify it.
     *
     * Every content-bearing disposition sits ahead of this check in the
     * dispatch chain: proven runtimes and configuration-bearing hosts (the
     * unproven finding), static graphics, background paint, and captured
     * media have already been handled when an element reaches it.
     */
    public function isEmptyRenderNeutralCustomElement(DOMElement $element, string $authorCss): bool
    {
        if ( ! str_contains(strtolower($element->tagName), '-')
            || '' !== trim($element->textContent ?? '')
            || SourceDom::hasRenderableMediaContent($element)
            || ! $this->runtimeIslands->isSafeTransparentCustomElement($element)
        ) {
            return false;
        }

        // A host with authored attributes is a component instance, not an
        // empty slot: its destination-less or labelled presentation keeps the
        // explicit unsupported diagnostic.
        foreach ( $element->attributes as $attribute ) {
            $name = strtolower($attribute->name);
            if ( 'id' === $name || 'style' === $name || '' === trim((string) $attribute->value) ) {
                continue;
            }
            return false;
        }

        foreach ( SourceDom::subtreeElements($element) as $candidate ) {
            if ( ! $candidate instanceof DOMElement ) {
                continue;
            }
            if ( in_array(strtolower($candidate->tagName), self::SUBTREE_RENDERING_TAGS, true)
                || '' !== trim(SourceDom::attr($candidate, 'role'))
                || '' !== trim(SourceDom::attr($candidate, 'aria-label'))
                || '' !== trim(SourceDom::attr($candidate, 'aria-labelledby'))
            ) {
                return false;
            }
            foreach ( self::SUBTREE_URL_ATTRIBUTES as $attribute ) {
                if ( '' !== trim(SourceDom::attr($candidate, $attribute)) ) {
                    return false;
                }
            }
            if ( ! $this->inlineStyleIsGeometryNeutral($candidate) ) {
                return false;
            }
        }

        return $this->authorCssLeavesSubtreeRenderEmpty($element, $authorCss);
    }

    /** Inline presentation beyond `display` (paint, typography, spacing) means the subtree is not render-empty. */
    private function inlineStyleIsGeometryNeutral(DOMElement $candidate): bool
    {
        $style = trim(SourceDom::attr($candidate, 'style'));
        if ( '' === $style ) {
            return true;
        }
        foreach ( array_keys(SourceDeclarations::parse($style)) as $property ) {
            if ( 'display' !== strtolower(trim((string) $property)) ) {
                return false;
            }
        }

        return true;
    }

    /** Positioning alone cannot paint an otherwise empty, unrendered host. */
    private function isEmptyBoxNeutralProperty(string $property): bool
    {
        return in_array(strtolower(trim($property)), array(
            'display', 'position', 'top', 'right', 'bottom', 'left', 'z-index',
            'inset', 'inset-block', 'inset-block-start', 'inset-block-end',
            'inset-inline', 'inset-inline-start', 'inset-inline-end',
        ), true);
    }

    /** Token-boundary selector match, as the host-tag pattern already uses. */
    private function cssTokenPattern(string $token): string
    {
        return '/(?<![\w-])' . preg_quote($token, '/') . '(?![\w-])/i';
    }

    /**
     * Whether author CSS leaves this custom-element subtree render-empty.
     * Rules addressing the host tag may declare `display` and box-neutral
     * positioning — neither paints empty content. Rules reaching the subtree
     * through a class, id, or hyphenated tag name must not declare paint or
     * size either: descendant CSS such as `.rule-line{height:1px;background:
     * #ccc}` gives an otherwise-empty host visible presence. Token matching
     * over-matches freely — a false positive only keeps the element on its
     * existing unsupported-element diagnostic path.
     */
    private function authorCssLeavesSubtreeRenderEmpty(DOMElement $element, string $authorCss): bool
    {
        $css = trim($authorCss);
        if ( '' === $css ) {
            return true;
        }

        $tagPattern = $this->cssTokenPattern(strtolower($element->tagName));
        $tokens = $this->subtreeCssTokens($element);

        foreach ( CssValueSplitter::ruleBlocks($css) as $rule ) {
            if ( 1 === preg_match($tagPattern, $rule['selector']) ) {
                // Offsets and stacking alone give an empty host no visible content.
                foreach ( array_keys(SourceDeclarations::parse($rule['declarations'])) as $property ) {
                    if ( ! $this->isEmptyBoxNeutralProperty((string) $property) ) {
                        return false;
                    }
                }
                continue;
            }
            if ( ! $this->selectorMentionsSubtreeToken($rule['selector'], $tokens) ) {
                continue;
            }
            if ( $this->emptyElementFootprint->cssDeclarationsPaintOrOccupySpace($rule['declarations']) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Class names, ids, and hyphenated tag names of every subtree element
     * (host included): the selectors that can reach it. Non-hyphenated tags
     * are left out — a bare `div` would match half the page's rules.
     *
     * @return array<int, string>
     */
    private function subtreeCssTokens(DOMElement $element): array
    {
        $tokens = array();
        foreach ( SourceDom::subtreeElements($element) as $candidate ) {
            if ( ! $candidate instanceof DOMElement ) {
                continue;
            }
            if ( str_contains(strtolower($candidate->tagName), '-') ) {
                $tokens[] = strtolower($candidate->tagName);
            }
            foreach ( preg_split('/\s+/', trim(SourceDom::attr($candidate, 'class')), -1, PREG_SPLIT_NO_EMPTY) as $class ) {
                $tokens[] = $class;
            }
            $id = trim(SourceDom::attr($candidate, 'id'));
            if ( '' !== $id ) {
                $tokens[] = $id;
            }
        }

        return array_values(array_unique($tokens));
    }

    /** Whether a selector mentions any subtree token with a boundary match. */
    private function selectorMentionsSubtreeToken(string $selector, array $tokens): bool
    {
        foreach ( $tokens as $token ) {
            if ( 1 === preg_match($this->cssTokenPattern($token), $selector) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * An unknown or custom element (`bdt`, `x-panel`) has no rendering of its
     * own: in block position it is a generic container, like a `div`. Once
     * every custom-element recognizer above has declined it, lower it as one
     * instead of dropping its whole subtree as unsupported.
     *
     * A subtree that carries runtime ownership (a script target, event
     * handlers, Interactivity API directives), a host that names a runtime
     * media component (`media-host`, `x-player`) or has its own ARIA role or
     * accessible name (a widget, not a wrapper) stays on the preservation
     * path, as do the legacy Shadow DOM insertion points `content` and
     * `shadow`, and a textless hyphenated custom element, which a script may
     * still upgrade into rendered shadow content. A named landmark role
     * (`role="region" aria-label="…"`, see namedLandmark()) only names a
     * section of the page, so it does not make the host a widget. Passive
     * graphic-only light DOM, captured native media, or an empty host painting only its inline
     * background is the exception, unless configuration or a proven runtime
     * owns the host. As with a background-paint-only host, a host whose light
     * DOM is nothing but captured native media already renders without a
     * script, so its `data-*` values parameterize existing content rather
     * than prove a missing runtime.
     */
    public function isTransparentUnknownElement(DOMElement $element): bool
    {
        if ( ( ! RichTextInlineTags::isUnknownHtmlElement($element) && ! str_contains(strtolower($element->tagName), '-') )
            || in_array(strtolower($element->tagName), array( 'content', 'shadow' ), true)
            // Motion topology names alone do not make static content runtime-owned.
            || $this->sourceElementClassifier->isRuntimeMediaSurfaceElement($element, false)
            || ! $this->runtimeIslands->isSafeTransparentCustomElement($element)
        ) {
            return false;
        }
        // A landmark host only names a section; a proven runtime definition
        // may still give it widget behaviour, so it keeps the widget path.
        $landmark = null !== $this->namedLandmark($element)
            && ! ( new CustomElementRuntimeDependency() )->hasProvenRuntimeDependency(
                $element,
                $this->session->runtimeBehaviorState()->runtimeScriptMetadata(),
                $this->session->runtimeBehaviorState()->runtimeProjectionScriptAssets()
            );
        foreach ( array( 'role', 'aria-label', 'aria-labelledby' ) as $attribute ) {
            if ( ! $landmark && '' !== trim(SourceDom::attr($element, $attribute)) ) {
                return false;
            }
        }

        if ( ! str_contains($element->tagName, '-') || '' !== trim($element->textContent ?? '') ) {
            return true;
        }

        $runtimeDependency = new CustomElementRuntimeDependency();
        $paintOnly = SourceDom::backgroundPaintOnlyCustomElement($element);
        return ( $paintOnly || SourceDom::staticGraphicOnlyCustomElement($element) || SourceDom::hasRenderableMediaContent($element) )
            && ! $runtimeDependency->hasConfigurationAttribute($element, $paintOnly || SourceDom::hasOnlyRenderableMediaContent($element))
            && 'preserve' !== $runtimeDependency->classify(
                $element,
                $this->session->runtimeBehaviorState()->runtimeScriptMetadata(),
                $this->session->runtimeBehaviorState()->runtimeProjectionScriptAssets()
            )['disposition'];
    }
}
