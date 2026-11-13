<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\Contract\RichTextInlineTags;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\SourceElementDescription;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\EmptyElementFootprint;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
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

    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly StyleResolver $styleResolver,
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly SourceElementDescription $sourceElementDescription,
        private readonly RuntimeIslandAnalyzer $runtimeIslands,
        private readonly EmptyElementFootprint $emptyElementFootprint
    ) {
    }

    /**
     * Whether a wrapper instance carries no identity, behavior, or presentation
     * of its own, and can therefore be replaced by its children.
     */
    public function isRenderNeutralWrapper(DOMElement $element, bool $stylesPrepared = true): bool
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
        $declarations = $this->styleResolver->inlineDeclarations($element);
        foreach ($this->session->authorStyleRuleCandidates($element) as $candidate) {
            if (! ($candidate['parsed']['supported'] ?? false)) return false;
            if ($this->session->sourceStyleResolutionState()->selectorMatchCache->matches($element, $candidate['selector'], $candidate['parsed'])['matches']) {
                $declarations = array_merge($declarations, $candidate['declarations']);
            }
        }
        $inheritedText = array('white-space', 'color', 'font', 'font-family', 'font-size', 'font-style', 'font-weight', 'font-variant', 'line-height', 'letter-spacing', 'word-spacing', 'text-align', 'text-transform', 'text-indent');
        foreach ($declarations as $property => $value) {
            if (!in_array($property, $inheritedText, true) && !('display' === $property && 'contents' === trim($value))) {
                return false;
            }
        }
        return true;
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
            if ( 'none' === ($this->styleResolver->inlineDeclarations($descendant)['display'] ?? '') ) {
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
     * (stylesheet rules addressing the tag and inline styles alike). Author
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
        foreach ( array_keys($this->styleResolver->cssDeclarations($style)) as $property ) {
            if ( 'display' !== strtolower(trim((string) $property)) ) {
                return false;
            }
        }

        return true;
    }

    /** Token-boundary selector match, as the host-tag pattern already uses. */
    private function cssTokenPattern(string $token): string
    {
        return '/(?<![\w-])' . preg_quote($token, '/') . '(?![\w-])/i';
    }

    /**
     * Whether author CSS leaves this custom-element subtree render-empty.
     * Rules addressing the host tag may declare only `display` — the box they
     * generate paints nothing over empty content. Rules reaching the subtree
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
                // The host tag's own presentation stays display-only.
                foreach ( array_keys($this->styleResolver->cssDeclarations($rule['declarations'])) as $property ) {
                    if ( 'display' !== strtolower(trim((string) $property)) ) {
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
     * still upgrade into rendered shadow content. Passive graphic-only light
     * DOM, captured native media, or an empty host painting only its inline
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
            || $this->sourceElementClassifier->isRuntimeMediaSurfaceElement($element)
            || ! $this->runtimeIslands->isSafeTransparentCustomElement($element)
        ) {
            return false;
        }
        foreach ( array( 'role', 'aria-label', 'aria-labelledby' ) as $attribute ) {
            if ( '' !== trim(SourceDom::attr($element, $attribute)) ) {
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
