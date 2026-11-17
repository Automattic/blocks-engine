<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorCompoundInspector;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;
use Automattic\BlocksEngine\PhpTransformer\Css\CssIdent;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorTokenizer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSyntaxScanner;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSpecificityProjection;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextMarkerSelector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\AddressableInlineLayoutLeaf;
use Automattic\BlocksEngine\PhpTransformer\Support\ShellLandmarkPolicy;
use DOMElement;

/**
 * Projects authored source selectors onto the canonical block markup the
 * source elements convert to: semantic tags, native controls, images,
 * navigation items and tables are addressed through the classes and
 * structure their blocks render, with the authored specificity kept.
 */
final class AuthorSelectorProjector
{
    /**
     * Core's replacement for a dropped menu toggle, excluded from a type rule
     * the toggle shared with source elements that survive conversion.
     */
    private const NAVIGATION_TOGGLE_CHROME_EXCLUSION = ':not(:where(.wp-block-navigation__responsive-container-open,.wp-block-navigation__responsive-container-open *,.wp-block-navigation__responsive-container-close,.wp-block-navigation__responsive-container-close *))';

    /**
     * State pseudo-classes a static source match cannot evaluate. They do not
     * change which element a compound names, only when it applies.
     */
    public const STATE_PSEUDO_CLASS_PATTERN = '/:(?:hover|focus-visible|focus-within|focus|active|visited|any-link|link|disabled|enabled|target)(?![\w(-])/i';

    private const SEMANTIC_ROLE_CLASS = 'blocks-engine-role-semantic';

    public function __construct(
        private readonly StyleResolver $styleResolver,
        private readonly AuthorSelectorSemanticPreparer $semanticPreparer
    ) {
    }

    /**
     * The figure selector for an `img.x` / `img#x` subject whose image became a
     * core/image block, or null when the subject can still reach an <img>.
     *
     * A subject naming the image by class or id follows that identity. A
     * type-only positioned image also needs its figure to own the source box:
     * leaving that figure static would introduce an in-flow flex/grid item.
     * That holds only for a selector naming positioned images alone: its
     * figure arm reaches every figure the type matches, so a global `img`
     * rule would also restyle the figures of static images.
     * Attributes stay on the <img>. Classes and id must be on the figure, so a
     * class the figure did not keep leaves the authored selector alone.
     *
     * @param array<string, mixed> $parsed
     */
    private function isPositionedImage(DOMElement $element): bool
    {
        return 'img' === strtolower($element->tagName)
            && in_array(CssValueInspector::comparable((string) ($this->styleResolver->structuralPresentationDeclarations($element)['position'] ?? '')), array( 'absolute', 'fixed' ), true);
    }

    private function projectImageFigureSubjectSelector(string $selector, array $parsed, DOMElement $element, AuthorStylesheetProjectionContext $context, bool $namesOnlyPositionedImages): ?string
    {
        if ( 'img' !== strtolower($element->tagName) ) {
            return null;
        }
        $leafMarker = $context->selectorProjections->imageFigure($element->getNodePath() ?? '')['leafMarker'] ?? '';
        if ( '' !== $leafMarker ) {
            // The source figure survives as the outer box; all declarations
            // (including important margins) still belong to its image leaf.
            $suffix = null === $parsed['pseudo_state_suffix_span'] ? '' : substr($selector, $parsed['pseudo_state_suffix_span']['start']);
            return NativeImageLeafPresentation::selectors(':where(.' . $leafMarker . ').wp-block-image', $this->selectorSpecificityShims($parsed, $context) . $suffix);
        }
        $compounds = $parsed['compounds'];
        $subject = CssSelectorCompoundInspector::rightmost($compounds);
        // Only an explicit type subject can use the existing type-span rewrite;
        // an img nested in :where() must remain a leaf selector.
        $positionedTypeSubject = $namesOnlyPositionedImages
            && 1 === preg_match('/^img\b/i', substr($selector, (int) ($parsed['rightmost_compound_span']['start'] ?? 0)));
        if ( 'img' !== strtolower((string) ($subject['type'] ?? ''))
            || ( array() === ($subject['classes'] ?? array()) && array() === ($subject['ids'] ?? array())
                && ! $positionedTypeSubject )
            || array() !== ($subject['attributes'] ?? array())
        ) {
            return null;
        }
        $path = $element->getNodePath() ?? '';
        $figure = $context->selectorProjections->imageFigure($path);
        // An image whose link carried an id already follows its own semantic
        // marker onto the figure; that route also drops the link ancestry.
        if ( null === $figure || '' !== $context->selectorProjections->imageWrapperMarker($path) ) {
            return null;
        }
        foreach ( $subject['classes'] as $class ) {
            if ( ! in_array($class, $figure['classes'], true) ) {
                return null;
            }
        }
        foreach ( $subject['ids'] as $id ) {
            if ( $id !== $figure['anchor'] ) {
                return null;
            }
        }
        $projected = $this->projectImageSelector($selector, $parsed, $context, true);

        // The link that wrapped the image now sits inside the figure. A bare
        // `a` compound right above the subject named that link; it becomes a
        // `:has(> a)` condition on the figure so the rule stays bound to
        // linked images only.
        $link = count($compounds) >= 2 ? $compounds[count($compounds) - 2] : null;
        $rightmost = $parsed['rightmost_compound_span'] ?? null;
        if ( $figure['linked']
            && is_array($link)
            && is_array($rightmost)
            && 'a' === strtolower((string) ($link['type'] ?? ''))
            && array() === $link['classes'] && array() === $link['ids'] && array() === $link['attributes']
            && array() === ($link['not'] ?? array()) && array() === ($link['any'] ?? array()) && array() === ($link['has'] ?? array())
        ) {
            foreach ( $parsed['type_spans'] as $typeSpan ) {
                if ( (int) $typeSpan['compound'] !== count($compounds) - 2 ) {
                    continue;
                }
                // Spans index the untrimmed selector text, so keep its
                // surrounding whitespace where it was.
                $suffix = null === $parsed['pseudo_state_suffix_span'] ? '' : rtrim(substr($selector, $parsed['pseudo_state_suffix_span']['start']));
                $end = strlen(rtrim($projected));
                $trailing = substr($projected, $end);
                $head = substr($projected, 0, (int) $typeSpan['start']);
                $core = substr($projected, (int) $rightmost['start'], $end - (int) $rightmost['start']);
                if ( '' !== $suffix && str_ends_with($core, $suffix) ) {
                    $core = substr($core, 0, -strlen($suffix));
                } else {
                    $suffix = '';
                }
                $projected = $head . $core . ':has(> a)' . $suffix . $trailing;
                break;
            }
        }

        if ( ! (new CoreSelectorScoping())->selectorIsScoped($projected) ) {
            $context->evidence->recordUnscopedCoreSelectorDrop($selector, 'no_importer_class_for_core_image_projection');
            return null;
        }

        return $projected;
    }

    /** Keep ancestor states intact when only the image link's identity moved. */
    private function projectImageLinkIdentitySelector(string $selector, AuthorStylesheetProjectionContext $context): string
    {
        $markers = $context->selectorProjections->imageLinkMarkers();
        if ( array() === $markers ) {
            return $selector;
        }
        $state = CssSyntaxScanner::state();
        $replacements = array();
        for ( $offset = 0; $offset < strlen($selector); ) {
            if ( '#' === $selector[$offset] && '' === $state['quote'] && ! $state['comment'] && 0 === $state['brackets']
                && preg_match('/\G#([A-Za-z][A-Za-z0-9_-]*)/', $selector, $match, 0, $offset)
                && isset($markers[$match[1]])
            ) {
                $end = $offset + strlen($match[0]);
                // A non-ASCII or escaped suffix belongs to the same CSS ID.
                // Do not rewrite a safe-ID prefix of that different token.
                if ( isset($selector[$end]) && ('\\' === $selector[$end] || ord($selector[$end]) >= 128) ) {
                    $offset = $end;
                    continue;
                }
                $replacements[$offset] = array(
                    'end' => $end,
                    'value' => ':where(.' . $markers[$match[1]] . '):not(#' . $context->authorStyles->idSpecificityShim() . ')',
                );
                $offset = $end;
                continue;
            }
            $next = CssSyntaxScanner::consume($selector, $offset, $state);
            if ( null === $next ) {
                return $selector;
            }
            $offset = $next;
        }
        return $this->replaceSelectorSpans($selector, $replacements);
    }

    /**
     * Core's captured navigation rules already address the runtime DOM. Keep
     * them together at source weight, including dormant responsive overlay
     * rules: projecting only the matching item rules would invert that cascade.
     */
    private function isNativeCoreNavigationSelector(string $selector, AuthorStylesheetProjectionContext $context): bool
    {
        if (!str_contains($selector, '.wp-block-navigation')) return false;
        $syntax = preg_replace('/\.(?:wp-block-navigation[\w-]*|is-menu-open|disable-default-overlay)(?![\w-])/', '', $selector);
        $syntax = preg_replace('/:(?:where|not)\b/', '', (string) $syntax);
        if ('' !== trim((string) $syntax, " \t\r\n()>+~*")) return false;
        foreach ($context->authorStyles->sourceBody()->getElementsByTagName('nav') as $nav) {
            if (!SourceDom::hasClass($nav, 'wp-block-navigation')) continue;
            foreach ($nav->getElementsByTagName('li') as $item) {
                if ('' !== $context->selectorProjections->navigationSubject($item->getNodePath() ?? '')) return true;
            }
        }
        return false;
    }

    /**
     * @param bool $subjectArms Whether a context-free control arm may address
     *        its carriers through subject classes (see contextFreeSubject()).
     *        Only rules whose declarations the button projection never splits
     *        per element pass true.
     * @param bool $semanticSubjectArms The same for semantic arms.
     */
    public function rewriteSelectorPreludeOnce(string $prelude, AuthorStylesheetProjectionContext $context, bool $controlWrapper = false, bool $subjectArms = false, bool $semanticSubjectArms = false): string
    {
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors ) {
            return $prelude;
        }
        $rewritten = array();
        foreach ( $selectors as $selector ) {
            array_push($rewritten, ...$this->projectSelectorArms($selector, $context, $controlWrapper, $subjectArms, $semanticSubjectArms));
        }
        $projected = implode(',', array_values(array_unique($rewritten)));
        if ( $projected !== $prelude && EngineMarker::matchesAny($projected) ) {
            $context->bindings->record($prelude, $projected);
        }

        return $projected;
    }

    /**
     * Every arm one authored selector projects to, in emission order.
     *
     * @return array<int, string>
     */
    private function projectSelectorArms(string $selector, AuthorStylesheetProjectionContext $context, bool $controlWrapper, bool $subjectArms, bool $semanticSubjectArms): array
    {
        if ( $context->selectorProjections->retainsRuntimeOwnedSelector($selector)
            || $this->isNativeCoreNavigationSelector($selector, $context) ) {
            return array(trim($selector));
        }
        // Arms for retained control descendants reached through the
        // control's own source identity. The selector's other
        // projections still follow.
        $arms = $this->projectRetainedControlRootSelector($selector, $context);
        $socialAnchor = SocialAnchorSelectorProjector::project($selector, $context);
        if (null !== $socialAnchor) {
            $arms[] = $socialAnchor;
            return $arms;
        }
        $structuralParsed = $context->sourceStyles->parsedSelector($selector);
        $documentVariantBody = $this->projectDocumentVariantBodySelector($selector, $structuralParsed, $context);
        if ( null !== $documentVariantBody ) {
            $arms[] = $documentVariantBody;
            return $arms;
        }
        if ( $structuralParsed['supported'] && $this->hasUniversalStructuralLeaf($structuralParsed) ) {
            $arms[] = $this->rewriteSourceTagTypes($selector, $structuralParsed, $context);
            return $arms;
        }
        $entrancePose = $this->projectEntrancePoseSelector($selector, $context, $controlWrapper, $subjectArms, $semanticSubjectArms);
        if ( null !== $entrancePose ) {
            array_push($arms, ...$entrancePose);
            return $arms;
        }
        $runtimeProjection = $this->projectRuntimeAttributeSelector($selector, $context);
        if ( null !== $runtimeProjection ) {
            array_push($arms, ...$runtimeProjection);
            return $arms;
        }
        $pseudoElement = $this->projectPseudoElementSelector($selector, $context, $controlWrapper, $subjectArms, $semanticSubjectArms);
        if ( null !== $pseudoElement ) {
            array_push($arms, ...$pseudoElement);
            return $arms;
        }
        $negatedAttributeCarrier = $this->projectNegatedAttributeCarrierSelector($selector, $context, $controlWrapper);
        if ( null !== $negatedAttributeCarrier ) {
            array_push($arms, ...$negatedAttributeCarrier);
            return $arms;
        }
        $selector = $this->projectSourceAttributeNegationStateSelector($selector, $context);
        $parsed = $context->sourceStyles->parsedSelector($selector);
        if ( ! $parsed['supported'] ) {
            $projectedControls = $this->projectUnsupportedFunctionalControlSelector($selector, $context, $controlWrapper);
            if ( array() === $projectedControls ) {
                $arms[] = $this->rewriteSourceTagTypes($this->projectImageLinkIdentitySelector($selector, $context), $parsed, $context);
            } else {
                array_push($arms, ...$projectedControls);
            }
            return $arms;
        }
        $matches = $this->matchingSourceElements($selector, $parsed, $context);
        foreach ($matches as $element) {
            if ($context->selectorProjections->isRetainedAuthoredControl($element->getNodePath() ?? '')
                && !($parsed['compounds'][count($parsed['compounds']) - 1]['universal'] ?? false)) {
                $arms[] = $this->rewriteSourceTagTypes($selector, $parsed, $context);
                break;
            }
        }
        if ( array() === $matches ) {
            $dormantControls = $this->projectDormantAncestorControlSelector($selector, $parsed, $context, $controlWrapper, $subjectArms);
            if ( array() !== $dormantControls ) {
                array_push($arms, ...$dormantControls);
                return $arms;
            }
            $arms[] = $this->rewriteSourceTagTypes($selector, $parsed, $context, self::sourceResolvedSiblingExclusion($parsed));
            return $arms;
        }

        // Some lowering paths retain the source leaf inside a paragraph
        // without marking it as a potential standalone inline carrier.
        // The emitted tree is authoritative: add a zero-specificity hop
        // only when that topology actually exists, keeping the source arm
        // for matches that were not wrapped.
        $carrierChild = $this->projectParagraphCarrierChildSelector($selector, $parsed, $context);
        if ( null !== $carrierChild ) {
            $arms[] = $carrierChild;
        }

        $anchorAncestor = $this->projectParagraphAnchorAncestorSelector($selector, $parsed, $context);
        if (null !== $anchorAncestor) $arms[] = $anchorAncestor;

        [$navigationArms, $matches] = $this->projectNavigationSubjects($selector, $parsed, $matches, $context);
        array_push($arms, ...$navigationArms);
        if ( array() === $matches ) {
            return $arms;
        }

        [$innerArms, $matches] = $this->projectInnerSubjects($selector, $parsed, $matches, $context);
        array_push($arms, ...$innerArms);
        if ( array() === $matches ) {
            return $arms;
        }

        $attributeAncestryProjection = $this->projectSourceAttributeAncestrySelector($selector, $parsed, $matches, $context, $controlWrapper);
        if ( null !== $attributeAncestryProjection ) {
            array_push($arms, ...$attributeAncestryProjection);
            return $arms;
        }
        $attributeProjection = $this->projectSourceAttributeSelector($selector, $parsed, $matches, $context, $controlWrapper);
        if ( null !== $attributeProjection ) {
            array_push($arms, ...$attributeProjection);
            return $arms;
        }
        if ( AuthorSelectorSemanticPreparer::isRootChildSelector($parsed) ) {
            array_push($arms, ...$this->projectRootChildSelector($selector, $parsed, $matches, $context));
            return $arms;
        }

        [$tableArms, $matches] = $this->projectTableDescendants($selector, $parsed, $matches, $context);
        array_push($arms, ...$tableArms);
        if ( array() === $matches ) {
            return $arms;
        }

        [$figureArms, $matches] = $this->projectImageFigureSubjects($selector, $parsed, $matches, $context);
        array_push($arms, ...$figureArms);
        if ( array() === $matches ) {
            return $arms;
        }

        $navigationRole = $this->projectNavigationRoleSelector($selector, $parsed, $matches, $context);
        if ( null !== $navigationRole ) {
            $arms[] = $navigationRole;
            return $arms;
        }

        array_push($arms, ...$this->projectMarkerCarriers($selector, $parsed, $matches, $context, $controlWrapper, $subjectArms, $semanticSubjectArms));
        return $arms;
    }

    /**
     * A lone class subject that only capture-created document variant roots
     * match addresses the body those roots stand in for.
     *
     * @param array<string, mixed> $structuralParsed
     */
    private function projectDocumentVariantBodySelector(string $selector, array $structuralParsed, AuthorStylesheetProjectionContext $context): ?string
    {
        $subject = $structuralParsed['compounds'][0] ?? array();
        if (($structuralParsed['supported'] ?? false) && 1 === count($structuralParsed['compounds'] ?? array())
            && null === ($subject['type'] ?? null) && !($subject['universal'] ?? false)
            && array() !== ($subject['classes'] ?? array())
            && array() === array_merge($subject['ids'] ?? array(), $subject['attributes'] ?? array(), $subject['not'] ?? array(), $subject['any'] ?? array(), $subject['has'] ?? array())
            && null === ($subject['nth_child'] ?? null) && !($subject['first_child'] ?? false) && !($subject['last_child'] ?? false) && !($subject['root'] ?? false)
            && null === ($structuralParsed['pseudo_state_suffix_span'] ?? null)
            && CssSelectorMatcher::matches($context->authorStyles->sourceBody(), $structuralParsed)['matches']) {
            $subjects = $this->matchingSourceElements($selector, $structuralParsed, $context);
            if (array() !== $subjects && array() === array_filter($subjects, static fn(DOMElement $element): bool => !SourceDom::isDocumentVariantRoot($element))) {
                // Capture-created variant roots stand in for a body; they
                // must not turn canvas paint into an opaque content layer.
                // Keep the real body's state and the authored specificity.
                return ':where(body)' . trim($selector);
            }
        }
        return null;
    }

    /**
     * Captured entrance attributes are written by the engine replay,
     * not by the removed source script. Project the resting subject but
     * keep its live pose predicate rather than freezing the after pose
     * into an attribute-state marker.
     * Stylesheet selector arms can include whitespace before the brace.
     *
     * @return array<int, string>|null
     */
    private function projectEntrancePoseSelector(string $selector, AuthorStylesheetProjectionContext $context, bool $controlWrapper, bool $subjectArms, bool $semanticSubjectArms): ?array
    {
        if (preg_match('/(:not\(\s*\[\s*(data-[a-z0-9_-]+)(?:\s*[~|^$*]?=\s*(?:"[^"]*"|\'[^\']*\'|[^\]\\s]+))?\s*\]\s*\))$/i', rtrim($selector), $pose)) {
            $base = substr(rtrim($selector), 0, -strlen($pose[1]));
            $baseParsed = CssSelectorMatcher::parse($base);
            $subjects = $this->matchingSourceElements($base, $baseParsed, $context);
            // A custom source host may already have been normalized. Its
            // descendant's retained ID still identifies the replay owner.
            if (array() === $subjects && isset($baseParsed['rightmost_compound_span']['start'])) {
                $leaf = substr($base, (int) $baseParsed['rightmost_compound_span']['start']);
                $leafParsed = CssSelectorMatcher::parse($leaf);
                if (str_contains($leaf, '#')) {
                    $base = $leaf;
                    $subjects = $this->matchingSourceElements($base, $leafParsed, $context);
                }
            }
            $driven = array_filter($subjects, static function (DOMElement $element) use ($pose): bool {
                $state = json_decode($element->getAttribute(CaptureEvidence::VIEWPORT_ENTRANCE), true);
                return is_array($state) && array_key_exists($pose[2], $state['attributes'] ?? array());
            });
            if (array() !== $driven && count($driven) === count($subjects)) {
                $projected = $this->rewriteSelectorPreludeOnce($base, $context, $controlWrapper, $subjectArms, $semanticSubjectArms);
                $arms = array();
                foreach (CssStylesheetTransformer::splitSelectorList($projected) ?? array() as $target) {
                    $arms[] = $target . $pose[1];
                }
                return $arms;
            }
        }
        return null;
    }

    /**
     * Project a pseudo-element rule's originating element, then restore the
     * suffix on every arm. Null when the selector has no pseudo-element, or
     * when its host projects to nothing (recorded as a diagnostic).
     *
     * @return array<int, string>|null
     */
    private function projectPseudoElementSelector(string $selector, AuthorStylesheetProjectionContext $context, bool $controlWrapper, bool $subjectArms, bool $semanticSubjectArms): ?array
    {
        $host = CssSelectorMatcher::pseudoElementHost($selector);
        if (null !== $host) {
            // Project the originating element through the same subject
            // pipeline as a non-pseudo rule, then restore the suffix on
            // every arm (including RichText marker arms).
            $projectedHost = $this->rewriteSelectorPreludeOnce(
                preg_match('/[\s>+~]$/', $host['selector']) ? $host['selector'] . '*' : $host['selector'],
                $context, $controlWrapper, $subjectArms, $semanticSubjectArms
            );
            $targets = CssStylesheetTransformer::splitSelectorList($projectedHost) ?? array();
            $arms = array();
            foreach ($targets as $target) {
                $arms[] = $target . $host['suffix'];
            }
            if (array() !== $targets) return $arms;
        }
        // A host that projects to nothing would ship a dead rule. State
        // hosts (:hover, :checked) have no host here; the state path owns them.
        if (null !== $host) {
            $context->evidence->recordConversionDiagnostic(array(
                'code' => 'html_unprojectable_pseudo_element_selector',
                'severity' => 'error',
                'conversion_classification' => 'unacceptable',
                'selector' => $selector,
                'message' => 'Could not project the originating element of an author pseudo-element rule.',
            ));
        }
        return null;
    }

    /**
     * Attribute carriers are keyed by the authored selector. Resolve the
     * complete captured predicate before rewriting a negation to its
     * live state class, which would change that identity.
     *
     * @return array<int, string>|null
     */
    private function projectNegatedAttributeCarrierSelector(string $selector, AuthorStylesheetProjectionContext $context, bool $controlWrapper): ?array
    {
        $parsed = $context->sourceStyles->parsedSelector($selector);
        if ($parsed['supported'] && str_contains($selector, ':not(')) {
            $sourceMatches = $this->matchingSourceElements($selector, $parsed, $context);
            if (array() !== $sourceMatches) {
                $attributeProjection = $this->projectSourceAttributeAncestrySelector($selector, $parsed, $sourceMatches, $context, $controlWrapper)
                    ?? $this->projectSourceAttributeSelector($selector, $parsed, $sourceMatches, $context, $controlWrapper);
                if (null !== $attributeProjection) {
                    return $attributeProjection;
                }
            }
        }
        return null;
    }

    /**
     * Matches core renders as navigation items bind to their recorded marker
     * target, once per rule; the rest stay for the stages that follow.
     *
     * @param array<string, mixed> $parsed
     * @param array<int, DOMElement> $matches
     * @return array{0: array<int, string>, 1: array<int, DOMElement>}
     */
    private function projectNavigationSubjects(string $selector, array $parsed, array $matches, AuthorStylesheetProjectionContext $context): array
    {
        $otherSubjects = array();
        $navigationSubjects = array();
        foreach ($matches as $element) {
            $target = $context->selectorProjections->navigationSubject($element->getNodePath() ?? '');
            $subject = CssSelectorCompoundInspector::rightmost($parsed['compounds']);
            $structural = array_filter($parsed['compounds'], static fn(array $compound): bool => ($compound['first_child'] ?? false) || ($compound['last_child'] ?? false) || null !== ($compound['nth_child'] ?? null) || null !== ($compound['nth_of_type'] ?? null));
            if ('a' === strtolower($element->tagName) && array() !== $structural) {
                // Core still renders this anchor in its source item. Keep
                // existing structural predicates live rather than binding
                // a captured first/last item to a permanent marker.
                $otherSubjects[] = $element;
                continue;
            }
            if (in_array(strtolower($element->tagName), array('li', 'ul', 'ol'), true) && null !== ($subject['type'] ?? null)) {
                // Typed item subjects retain the existing structural
                // projection, including sibling predicates and whitespace
                // participation. The new anchor subject is independent.
                $otherSubjects[] = $element;
                continue;
            }
            if (in_array(strtolower($element->tagName), array('li', 'ul', 'ol'), true) && null === ($subject['type'] ?? null)
                && $this->typedItemRuleOutranks($element, $parsed, $context)) {
                // A typed rule on this item stays on the structural tier
                // above. When it beat this subject in the source, join it
                // there: the carrier baseline would lift this subject above
                // it and invert the authored cascade.
                $otherSubjects[] = $element;
                continue;
            }
            if ('' === $target) {
                $otherSubjects[] = $element;
                continue;
            }
            $suffix = null === $parsed['pseudo_state_suffix_span'] ? '' : substr($selector, $parsed['pseudo_state_suffix_span']['start']);
            // One shared carrier baseline defeats Core defaults while the
            // original specificity and order arbitrate author declarations.
            $stateSelector = substr($selector, 0, null === $parsed['pseudo_state_suffix_span'] ? null : $parsed['pseudo_state_suffix_span']['start']);
            if ($context->selectorProjections->selectsCurrentNavigationState($stateSelector)) $target = AuthorSelectorProjectionState::currentNavigationSubject($target);
            elseif ($context->selectorProjections->selectsRestingNavigationState($stateSelector)) $target = AuthorSelectorProjectionState::restingNavigationSubject($target);
            // Many source items share one recorded marker target. Emit
            // that subject once per source rule, not once per matching item.
            $navigationProjection = ':root ' . $target . $this->selectorSpecificityShims($parsed, $context) . $suffix;
            $navigationSubjects[$navigationProjection] = true;
        }
        return array(array_keys($navigationSubjects), $otherSubjects);
    }

    /**
     * Native blocks that cannot store source classes on an inner part
     * (core/search input/button) register that part as the subject;
     * project onto it with the same specificity/state bridge as
     * button lowering.
     *
     * @param array<string, mixed> $parsed
     * @param array<int, DOMElement> $matches
     * @return array{0: array<int, string>, 1: array<int, DOMElement>}
     */
    private function projectInnerSubjects(string $selector, array $parsed, array $matches, AuthorStylesheetProjectionContext $context): array
    {
        $innerArms = array();
        $remainingSubjects = array();
        foreach ( $matches as $element ) {
            $inner = $context->selectorProjections->innerSubject($element->getNodePath() ?? '');
            if ( null === $inner ) {
                $remainingSubjects[] = $element;
                continue;
            }
            $suffix = null === $parsed['pseudo_state_suffix_span'] ? '' : substr($selector, $parsed['pseudo_state_suffix_span']['start']);
            if ($inner['author_precedence']) {
                $innerArms[] = $this->rewriteSourceTagTypes($selector, $parsed, $context);
            }
            // A constant baseline beats native defaults without flattening
            // source specificity, states, conditional groups or layer order.
            $baseline = $inner['author_precedence']
                ? str_repeat(':not(.' . $context->authorStyles->classSpecificityShim() . ')', 4)
                : '';
            $innerArms[] = $inner['selector'] . $baseline . $this->selectorSpecificityShims($parsed, $context) . $suffix . $inner['pseudo_element'];
        }
        return array($innerArms, $remainingSubjects);
    }

    /**
     * A body-child rule addresses the root-child markers its matches carry and
     * the template-part landmark of a matched header or footer.
     *
     * @param array<string, mixed> $parsed
     * @param array<int, DOMElement> $matches
     * @return array<int, string>
     */
    private function projectRootChildSelector(string $selector, array $parsed, array $matches, AuthorStylesheetProjectionContext $context): array
    {
        $shellTags = array_values(array_unique(array_filter(array_map(
            static function (DOMElement $element) use ($context): string {
                if ( $element->parentNode !== $context->authorStyles->sourceBody() ) {
                    return '';
                }
                $tag = strtolower($element->tagName);
                $area = ShellLandmarkPolicy::landmarkKind($tag, $element->getAttribute('role'));
                return in_array($area, array( 'header', 'footer' ), true) ? $tag : '';
            },
            $matches
        ))));
        $markers = array_values(array_unique(array_filter(array_map(
            static fn (DOMElement $element): string => $context->selectorProjections->rootChildMarker($element->getNodePath() ?? ''),
            $matches
        ))));
        if ( array() === $markers && array() === $shellTags ) {
            return array($selector);
        }
        $rootArms = array();
        foreach ( $markers as $marker ) {
            $rootArms[] = $this->projectSemanticLeafSelector($selector, $parsed, $marker, $context);
        }
        foreach ( $shellTags as $tag ) {
            // A shell part's landmark group is the box and keeps the
            // authored selector's projection. The editor still nests
            // it in Core's template-part transport wrapper.
            $rootArms[] = ':root .editor-styles-wrapper :where(.wp-block-template-part) > ' . $tag . $this->selectorSpecificityShims($parsed, $context);
        }
        return $rootArms;
    }

    /**
     * Matches inside a converted table address their serialized table path,
     * with structural cells compacted per table; the rest stay.
     *
     * @param array<string, mixed> $parsed
     * @param array<int, DOMElement> $matches
     * @return array{0: array<int, string>, 1: array<int, DOMElement>}
     */
    private function projectTableDescendants(string $selector, array $parsed, array $matches, AuthorStylesheetProjectionContext $context): array
    {
        $tableDescendants = array();
        $nonTableMatches = array();
        $tableCellGroups = array();
        foreach ( $matches as $element ) {
            $projected = $this->projectTableDescendantSelector($selector, $parsed, $element, $context);
            if ( null === $projected ) {
                $nonTableMatches[] = $element;
                continue;
            }
            $cellTarget = $this->structuralTableCellTarget($projected, $element, $context);
            if ( null === $cellTarget ) {
                $tableDescendants[] = $projected;
                continue;
            }
            $groupKey = spl_object_id($cellTarget['table']) . "\0" . $cellTarget['tail'];
            $tableCellGroups[$groupKey] ??= array( 'table' => $cellTarget['table'], 'marker' => $cellTarget['marker'], 'tail' => $cellTarget['tail'], 'cells' => array() );
            $tableCellGroups[$groupKey]['cells'][spl_object_id($element)] = $element;
        }
        foreach ( $tableCellGroups as $group ) {
            foreach ( $this->compactTableCellPaths($group['table'], $group['cells']) as $path ) {
                $tableDescendants[] = '.' . $group['marker'] . '>table>' . $path . $group['tail'];
            }
        }
        return array(array_values(array_unique($tableDescendants)), $nonTableMatches);
    }

    /**
     * core/image saves the source image's classes and id on its
     * <figure>, never on the <img>. A subject that names the image
     * through them has nothing left to match, so it follows them to
     * the figure: the box the image bridge already fills.
     *
     * @param array<string, mixed> $parsed
     * @param array<int, DOMElement> $matches
     * @return array{0: array<int, string>, 1: array<int, DOMElement>}
     */
    private function projectImageFigureSubjects(string $selector, array $parsed, array $matches, AuthorStylesheetProjectionContext $context): array
    {
        $figureSubjects = array();
        $otherMatches = array();
        $namesOnlyPositionedImages = array() === array_filter($matches, fn (DOMElement $element): bool => ! $this->isPositionedImage($element));
        foreach ( $matches as $element ) {
            $figureSubject = $this->projectImageFigureSubjectSelector($selector, $parsed, $element, $context, $namesOnlyPositionedImages);
            if ( null === $figureSubject ) {
                $otherMatches[] = $element;
            } else {
                $figureSubjects[] = $figureSubject;
            }
        }
        return array(array_values(array_unique($figureSubjects)), $otherMatches);
    }

    /**
     * When every match plays one navigation role core renders itself (direct
     * anchor, list item, or list host), the subject moves onto core's markup.
     *
     * @param array<string, mixed> $parsed
     * @param array<int, DOMElement> $matches
     */
    private function projectNavigationRoleSelector(string $selector, array $parsed, array $matches, AuthorStylesheetProjectionContext $context): ?string
    {
        // Every match is a direct anchor core re-parents into a list item of
        // its own, so the subject's sibling position belongs to that item.
        // Any other match, or a subject this projection cannot place, keeps
        // the authored selector exactly.
        if ( $this->matchesOnlyNavigationRole($matches, AuthorSelectorProjectionState::NAVIGATION_ANCHOR, $context) ) {
            $itemSelector = $this->projectNavigationItemAnchorSelector($selector, $parsed, $context);
            if ( null !== $itemSelector ) {
                return $itemSelector;
            }
        }
        // Every match is a source list item core renders as a navigation
        // item of its own. The source-type marker never reaches that
        // rendered item, so the subject moves onto core's item class.
        if ( $this->matchesOnlyNavigationRole($matches, AuthorSelectorProjectionState::NAVIGATION_ITEM, $context) ) {
            $itemSelector = $this->projectNavigationListItemSelector($selector, $parsed, $context);
            if ( null !== $itemSelector ) {
                $context->bindings->recordNativeSelector($selector);
                return $itemSelector;
            }
        }
        // Every match is a source list that is itself the element a
        // core/navigation block stands in for. Its classes and id sit on
        // the rendered `<nav>` (and on the inner list copy); the list type
        // has to address that block rather than the copy.
        if ( $this->matchesOnlyNavigationRole($matches, AuthorSelectorProjectionState::NAVIGATION_LIST_HOST, $context) ) {
            $hostSelector = $this->projectNavigationListHostSelector($selector, $parsed, $context, $matches);
            if ( null !== $hostSelector ) {
                return $hostSelector;
            }
        }
        return null;
    }

    /**
     * Classify the remaining matches by the engine marker each carries
     * (control, semantic, RichText, inline layout carrier) and emit one arm
     * per marker kind, keeping the authored arm, minus those carriers, for
     * matches no marker covers.
     *
     * @param array<string, mixed> $parsed
     * @param array<int, DOMElement> $matches
     * @return array<int, string>
     */
    private function projectMarkerCarriers(string $selector, array $parsed, array $matches, AuthorStylesheetProjectionContext $context, bool $controlWrapper, bool $subjectArms, bool $semanticSubjectArms): array
    {
        $arms = array();
        $controls = array();
        $linkOwnedControls = array();
        $semanticLeaves = array();
        $richTextLeaves = array();
        $inlineLayoutCarriers = false;
        $addressableInlineCarriers = false;
        $hasNonProjected = false;
        // A type selector that matched a menu toggle dropped for Core's
        // native overlay control (or something inside it) has lost that
        // subject; left bare it would reach the open/close buttons Core
        // renders in the toggle's place. Only a type subject can reach
        // them: Core's chrome carries no authored class, id or attribute.
        $typeSubject = null !== (CssSelectorCompoundInspector::rightmost($parsed['compounds'])['type'] ?? null);
        $supersededToggles = false;
        foreach ( $matches as $element ) {
            $path = $element->getNodePath() ?? '';
            if ( $context->selectorProjections->isRetainedSourcePath($path) || $this->isPreservedCodeSyntaxElement($element) ) {
                $hasNonProjected = true;
            } elseif ( $typeSubject && $context->selectorProjections->isSupersededControlPath($path) ) {
                $supersededToggles = true;
            } elseif ( $context->selectorProjections->isInlineLayoutCarrierPath($path) ) {
                // Structured card lowering unwraps the fragment and hoists
                // its styling hook onto the paragraph it emits, so the class
                // lands on that paragraph instead of inside a carrier.
                // Conversely, a retained ancestor carrier keeps this element
                // in its source wrapper rather than emitting another carrier.
                if ( $this->isHoistedCardFragment($element) || $this->isInsideRetainedInlineLayoutCarrier($element, $context) ) {
                    $hasNonProjected = true;
                    continue;
                }
                $inlineLayoutCarriers = true;
                // Source analysis may add its own markers before stylesheet
                // projection. Only the emitted paragraph can own this ID;
                // unpromoted leaves make the additional selector inert.
                $addressableInlineCarriers = $addressableInlineCarriers || ('span' === strtolower($element->tagName) && '' !== SourceDom::attr($element, 'id'));
            } elseif ( '' !== ($marker = $context->selectorProjections->richTextMarker($path)) ) {
                $richTextLeaves[] = $marker;
            } elseif ( '' !== ($marker = $context->selectorProjections->controlMarker($path)) ) {
                $controls[] = $marker;
                if ( $controlWrapper && LayoutParticipation::BOX_LINK === LayoutParticipation::resolve($element, $this->styleResolver)->participatingBox() ) {
                    $linkOwnedControls[$marker] = true;
                }
            } elseif ( '' !== ($marker = $context->selectorProjections->imageWrapperMarker($path)) ) {
                $semanticLeaves[] = $marker;
            } elseif ( '' !== ($marker = $context->selectorProjections->semanticMarker($path)) ) {
                $semanticLeaves[] = $marker;
            } else {
                $hasNonProjected = true;
            }
        }
        $controls = array_values(array_unique($controls));
        $semanticLeaves = array_values(array_unique($semanticLeaves));
        $richTextLeaves = array_values(array_unique($richTextLeaves));
        // In a mixed set the authored selector stays for the other matches,
        // but every rendered menu anchor is the only child of its item, so
        // the kept selector has to stop reaching them. Exclude them through
        // the class core hard-codes on them; `:not(:where(…))` adds no
        // specificity.
        if ( array() === $controls && array() === $semanticLeaves && array() === $richTextLeaves && ! $inlineLayoutCarriers ) {
            $insertion = '';
            if ( $supersededToggles ) {
                // Nothing else matched: the rule's subject is gone, so bind
                // it to a marker nothing carries. Otherwise keep it for the
                // surviving subjects but away from Core's toggle chrome.
                $insertion = $hasNonProjected
                    ? self::NAVIGATION_TOGGLE_CHROME_EXCLUSION
                    : ':where(.' . AuthorStylesheetProjector::SUPERSEDED_MENU_TOGGLE_CLASS . ')';
            }
            return array($this->rewriteSourceTagTypes($selector, $parsed, $context, $insertion . self::sourceResolvedSiblingExclusion($parsed)));
        }
        $projectedMarkers = array_merge($controls, $semanticLeaves, $richTextLeaves);
        // Shared stylesheets are projected once per document into that
        // document's marker namespace. Class-bound rules still address
        // emitted markup that kept the authored class (button inner HTML,
        // extracted chrome), so dropping the class leaves those elements
        // unmatched in every other consuming document.
        if ( $context->keepAuthorClassSelectors && array() !== $projectedMarkers && $this->isClassBoundSelector($parsed) ) {
            $hasNonProjected = true;
        }
        // A RichText-marked element can be emitted as a block wrapper in
        // another responsive representation, where only its authored class
        // remains available to the projected rule.
        if ( array() !== $richTextLeaves && $this->hasClassBoundSubject($parsed) ) {
            $hasNonProjected = true;
        }
        // Source analysis marks potential inline carriers before pattern
        // lowering. A native block can keep that source hook and topology
        // instead; retain the authored arm wherever emitted markup still
        // answers it, alongside the possible carrier forms.
        $groupCarrier = false;
        if ( $inlineLayoutCarriers ) {
            $subject = substr($selector, (int) $parsed['rightmost_compound_span']['start']);
            $groupCarrier = $this->matchesEmittedMarkup(CssSelectorMatcher::parse($subject . ':where(.wp-block-group)'), $context->emittedMarkup);
            if ( $groupCarrier || $this->matchesEmittedMarkup($parsed, $context->emittedMarkup) ) {
                $hasNonProjected = true;
            }
        }
        if ( $hasNonProjected ) {
            // Control, semantic and RichText carriers also carry their
            // kind's role class, so the authored arm stays off all of them
            // through one constant class per kind instead of a list of
            // per-element markers that differs on every page.
            $exclusion = array_map(static fn (string $roleClass): string => '.' . $roleClass, EngineMarker::roleClasses($projectedMarkers));
            $arms[] = $this->rewriteSourceTagTypes(
                $selector,
                $parsed,
                $context,
                ( array() === $exclusion ? '' : ':not(:where(' . implode(',', $exclusion) . '))' ) . ( $supersededToggles ? self::NAVIGATION_TOGGLE_CHROME_EXCLUSION : '' )
                    . ( in_array('.' . self::SEMANTIC_ROLE_CLASS, $exclusion, true) ? '' : self::sourceResolvedSiblingExclusion($parsed) )
            );
        }
        // A context-free subject (`a`, `.btn`, `*`) reaches every control
        // carrier whose source it matches, wherever it sits. When this page
        // routes all of those carriers to the control arm, one arm naming
        // their subject classes replaces their per-element arms, and the
        // projection no longer depends on the page's other content. In
        // wrapper mode each control's arm shape depends on its own layout
        // participation, so those stay per element.
        $controlSubject = $subjectArms && ! $controlWrapper && null === $this->universalControlSubject($selector, $parsed, $context) && array() !== $controls
            ? $this->contextFreeSubject('control', $parsed, $controls, $context)
            : null;
        if ( null !== $controlSubject ) {
            $arms[] = $this->projectControlTarget($controlSubject['target'], $selector, $parsed, $context, false);
        }
        foreach ( null === $controlSubject ? $controls : $controlSubject['rest'] as $marker ) {
            $arms[] = $this->projectControlSelector($selector, $parsed, $marker, $context, $controlWrapper && ! isset($linkOwnedControls[$marker]));
        }
        // Semantic arms take one shape whatever the rule declares, so they
        // only need the subject to be context-free.
        $semanticSubject = $semanticSubjectArms && array() !== $semanticLeaves
            ? $this->contextFreeSubject('semantic', $parsed, $semanticLeaves, $context)
            : null;
        if ( null !== $semanticSubject ) {
            $arms[] = $this->projectSemanticTarget($semanticSubject['target'], $selector, $parsed, $context);
        }
        foreach ( null === $semanticSubject ? $semanticLeaves : $semanticSubject['rest'] as $marker ) {
            $arms[] = $this->projectSemanticLeafSelector($selector, $parsed, $marker, $context);
        }
        // RichText arms take one shape too; a carrier the subject arm does
        // not cover keeps its per-element attribute arm.
        $richTextSubject = $semanticSubjectArms && array() !== $richTextLeaves
            ? $this->contextFreeSubject('richtext', $parsed, $richTextLeaves, $context)
            : null;
        if ( null !== $richTextSubject ) {
            $arms[] = $this->projectSemanticTarget($richTextSubject['target'], $selector, $parsed, $context);
        }
        foreach ( null === $richTextSubject ? $richTextLeaves : $richTextSubject['rest'] as $marker ) {
            $arms[] = $this->projectRichTextSemanticSelector($selector, $parsed, $marker, $context);
        }
        if ( $inlineLayoutCarriers ) {
            $arms[] = $this->projectInlineLayoutCarrierSelector($selector, $parsed, $addressableInlineCarriers);
            // Potential inline leaves can instead lower to a native group
            // when their attached source cascade establishes a block box.
            // Keep that hook addressable even when document context (such
            // as a body class) is absent from the emitted fragment here.
            if ( $groupCarrier ) {
                $arms[] = $this->rewriteSourceTagTypes($selector, $parsed, $context, ':where(.wp-block-group)');
            }
        }
        return $arms;
    }

    /**
     * A sibling-structural selector (`:last-of-type`, `+`, …) left live
     * matches by rendered sibling positions, which conversion changes where it
     * turns a sibling into another element type. Every document resolves the
     * elements of such sibling groups against its source and gives them
     * per-element semantic arms, so a live arm, from any document's copy of a
     * shared stylesheet, must stay off semantic carriers. `:not(:where())`
     * adds no specificity.
     *
     * @param array<string, mixed> $parsed
     */
    private static function sourceResolvedSiblingExclusion(array $parsed): string
    {
        return ($parsed['supported'] ?? false) && AuthorSelectorSemanticPreparer::isSiblingStructuralSelector($parsed)
            ? ':not(:where(.' . self::SEMANTIC_ROLE_CLASS . '))'
            : '';
    }

    /** @param array<string, mixed> $parsed */
    private function matchesEmittedMarkup(array $parsed, string $markup): bool
    {
        // Reuse one DOM across rules, retaining only the current output.
        static $cachedMarkup = null;
        static $document = null;
        if ( $cachedMarkup !== $markup ) {
            $cachedMarkup = $markup;
            $document = HtmlSerialization::parseDocument($markup);
        }
        foreach ( $document->getElementsByTagName('*') as $element ) {
            $match = CssSelectorMatcher::matches($element, $parsed);
            if ( $match['supported'] && $match['matches'] ) {
                return true;
            }
        }
        return false;
    }

    /**
     * A carrier serializes its retained source wrapper with outerHtml, so its
     * descendants are not independently lowered even if source analysis marked
     * them as potential carrier leaves. Keep their authored selector topology.
     */
    private function isInsideRetainedInlineLayoutCarrier(DOMElement $element, AuthorStylesheetProjectionContext $context): bool
    {
        for ($ancestor = $element->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode) {
            if ($context->selectorProjections->isInlineLayoutCarrierPath($ancestor->getNodePath() ?? '')
                && ! $this->isHoistedCardFragment($ancestor)
                && ! AddressableInlineLayoutLeaf::isPlainTextSpan($ancestor)
                && null === AddressableInlineLayoutLeaf::identity($ancestor, $this->styleResolver)
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * A styling-hook fragment of a structured card `<li>`.
     *
     * Card lowering unwraps such a fragment and hoists its class and style onto
     * the paragraph it emits, so the hook ends up on that paragraph rather than
     * nested inside an inline-layout carrier. Scoping its rule behind a carrier
     * would leave the rule matching nothing.
     */
    private function isHoistedCardFragment(DOMElement $element): bool
    {
        if ( ! in_array(strtolower($element->tagName), array( 'span', 'a' ), true)
            || '' === trim($element->getAttribute('class')) ) {
            return false;
        }

        $item = $element->parentNode;
        if ( ! $item instanceof DOMElement || 'li' !== strtolower($item->tagName) ) {
            return false;
        }

        $list = $item->parentNode;
        if ( ! $list instanceof DOMElement || ! in_array(strtolower($list->tagName), array( 'ul', 'ol' ), true) ) {
            return false;
        }

        // Two or more classed inline fragments is the shape card lowering
        // recognizes; a single hooked fragment stays ordinary inline flow.
        $hooked = 0;
        foreach ( $item->childNodes as $sibling ) {
            if ( $sibling instanceof DOMElement
                && in_array(strtolower($sibling->tagName), array( 'span', 'a', 'b', 'strong', 'em', 'i' ), true)
                && '' !== trim($sibling->getAttribute('class')) ) {
                ++$hooked;
            }
        }

        return $hooked >= 2;
    }

    private function isPreservedCodeSyntaxElement(DOMElement $element): bool
    {
        for ( $ancestor = $element->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode ) {
            if ( 'code' !== strtolower($ancestor->tagName) ) {
                continue;
            }

            return $ancestor->parentNode instanceof DOMElement
                && 'pre' === strtolower($ancestor->parentNode->tagName);
        }

        return false;
    }

    /**
     * A selector no source element matches as a whole, whose rightmost
     * compound matches controls: keep the authored ancestry and project the
     * subject onto every control carrier it matches. Every such carrier gets
     * the same arm shape, so with `$subjectArms` and a context-free subject the
     * carriers are addressed through their subject classes in one arm.
     *
     * @param array<string, mixed> $parsed
     * @return list<string>
     */
    private function projectDormantAncestorControlSelector(
        string $selector,
        array $parsed,
        AuthorStylesheetProjectionContext $context,
        bool $wrapper,
        bool $subjectArms = false
    ): array {
        $rightmost = $parsed['rightmost_compound_span'] ?? null;
        if ( ! is_array($rightmost) || 0 === (int) $rightmost['start'] ) {
            return array();
        }

        $leafSelector = substr($selector, (int) $rightmost['start']);
        $leafParsed = $context->sourceStyles->parsedSelector($leafSelector);
        if ( ! $leafParsed['supported'] ) {
            return array();
        }

        $markers = array();
        $hasNonProjected = false;
        foreach ( $this->matchingSourceElements($leafSelector, $leafParsed, $context) as $element ) {
            $marker = $context->selectorProjections->controlMarker($element->getNodePath() ?? '');
            if ( '' !== $marker ) {
                $markers[] = $marker;
            } else {
                $hasNonProjected = true;
            }
        }
        $markers = array_values(array_unique($markers));
        $prefix = substr($selector, 0, (int) $rightmost['start']);
        $subject = $subjectArms && array() !== $markers ? $this->contextFreeSubject('control', $leafParsed, $markers, $context) : null;
        $projected = array();
        // A dormant ancestor (including a route-owned body id) does not make
        // the leaf control-only. Keep its other subjects addressable too.
        if ( $hasNonProjected && array() !== $markers ) {
            $projected[] = $this->rewriteSourceTagTypes($selector, $parsed, $context, ':not(:where(.blocks-engine-role-control))');
        }
        if ( null !== $subject ) {
            $projected[] = $prefix . $this->projectControlTarget($subject['target'], $leafSelector, $leafParsed, $context, $wrapper);
            $markers = $subject['rest'];
        }
        foreach ( $markers as $marker ) {
            $projected[] = $prefix . $this->projectControlSelector($leafSelector, $leafParsed, $marker, $context, $wrapper);
        }
        return array_values(array_unique($projected));
    }

    /**
     * A control block (core/button) that keeps its source descendants inside
     * the emitted link renders them with their source identity, but the link
     * itself is core's `wp-block-button__link`: the anchor's own classes and id
     * are gone. A rule that reaches a retained descendant through the anchor
     * (`#comp .root .icon`, `.root:hover .icon`) keeps its authored subject and
     * ancestry, and the compound naming the anchor moves onto the emitted link
     * through the control marker, with that compound's specificity and its
     * state pseudo-classes. The authored selector is still projected as usual.
     *
     * @return list<string>
     */
    private function projectRetainedControlRootSelector(string $selector, AuthorStylesheetProjectionContext $context): array
    {
        if ( ! $context->selectorProjections->hasRetainedSourcePaths() ) {
            return array();
        }
        $selector = trim($selector);
        $tokens = CssSelectorTokenizer::tokenize($selector);
        $last = count($tokens['compounds'] ?? array()) - 1;
        if ( ! ($tokens['supported'] ?? false) || $last < 1 ) {
            return array();
        }

        // Each compound's resting form: state pseudo-classes are not
        // structure, so they are matched away and restored on the projection.
        $resting = array();
        foreach ( $tokens['compounds'] as $index => $compound ) {
            $resting[$index] = trim((string) preg_replace(self::STATE_PSEUDO_CLASS_PATTERN, '', $compound));
            if ( '' === $resting[$index] ) {
                $resting[$index] = '*';
            }
        }
        $join = static function (int $end) use ($resting, $tokens): string {
            $joined = $resting[0];
            for ( $index = 1; $index <= $end; ++$index ) {
                $combinator = trim((string) ($tokens['combinators'][$index - 1] ?? ' '));
                $joined .= ('' === $combinator ? ' ' : ' ' . $combinator . ' ') . $resting[$index];
            }
            return $joined;
        };
        $match = function (string $candidate) use ($context): array {
            $parsed = $context->sourceStyles->parsedSelector($candidate);
            return is_array($parsed) && ($parsed['supported'] ?? false) ? $this->matchingSourceElements($candidate, $parsed, $context) : array();
        };

        $subjects = array_values(array_filter(
            $match($join($last)),
            static fn (DOMElement $element): bool => $context->selectorProjections->isRetainedSourcePath($element->getNodePath() ?? '')
        ));
        if ( array() === $subjects ) {
            return array();
        }

        for ( $index = 0; $index < $last; ++$index ) {
            $markers = array();
            foreach ( $match($join($index)) as $root ) {
                $marker = $context->selectorProjections->controlMarker($root->getNodePath() ?? '');
                if ( '' === $marker || $context->selectorProjections->isRetainedSourcePath($root->getNodePath() ?? '') ) {
                    continue;
                }
                foreach ( $subjects as $subject ) {
                    for ( $node = $subject->parentNode; $node instanceof DOMElement; $node = $node->parentNode ) {
                        if ( $node === $root ) {
                            $markers[$marker] = true;
                            continue 3;
                        }
                    }
                }
            }
            if ( array() === $markers ) {
                continue;
            }

            $span = $tokens['compound_spans'][$index];
            $prefix = substr($selector, 0, (int) $span['end']);
            $parsedPrefix = $context->sourceStyles->parsedSelector($prefix);
            if ( ! ($parsedPrefix['supported'] ?? false) ) {
                continue;
            }
            // Rebind the whole source prefix exactly as a rule targeting the
            // control itself. Its source ancestry need not survive Core's
            // inserted wrappers; descendants keep their authored topology.
            $projected = array();
            foreach ( array_keys($markers) as $marker ) {
                $replacements = array(0 => array(
                    'end' => (int) $span['end'],
                    'value' => $this->projectControlSelector($prefix, $parsedPrefix, $marker, $context),
                ));
                // A materialized SVG inside a button link is a bare image,
                // not the figure leaf used by block images. Retarget source
                // types in the descendant suffix without changing ancestry or
                // specificity, and without admitting ordinary author images.
                if ( str_contains($context->emittedMarkup, 'assets/materialized-svg/inline-svg-') ) {
                    $parsed = $context->sourceStyles->parsedSelector($selector);
                    foreach ( array_merge($parsed['type_spans'], CssSelectorMatcher::functionalTypeSpans($selector)) as $typeSpan ) {
                        if ( 'svg' === strtolower((string) $typeSpan['name']) && (int) $typeSpan['start'] >= (int) $span['end'] ) {
                            $replacements[$typeSpan['start']] = array(
                                'end' => $typeSpan['end'],
                                'value' => ':is(svg,:where(.wp-block-button__link > img[src*="assets/materialized-svg/inline-svg-"]))',
                            );
                        }
                    }
                }
                $projected[] = $this->projectSourceClassSelector($selector, $context, $replacements);
            }
            return array_values(array_unique($projected));
        }

        return array();
    }

    /** @return list<string> */
    public function projectUnsupportedFunctionalControlSelector(
        string $selector,
        AuthorStylesheetProjectionContext $context,
        bool $wrapper
    ): array {
        if ( 1 !== preg_match('/:(?:is|where)\s*\(/i', $selector) ) {
            return array();
        }

        $rewritten = array();
        $spelled = array_flip(SelectorNameRuns::spelled($selector));
        foreach ( $context->authorStyles->sourceBody()->getElementsByTagName('*') as $element ) {
            if ( ! $element instanceof DOMElement || ! in_array(strtolower($element->tagName), array( 'a', 'button' ), true) ) {
                continue;
            }
            // Ask for the control's path only when the selector can name it.
            $runs = SelectorNameRuns::naming($element);
            if ( null !== $runs && array() === array_intersect_key($spelled, array_flip($runs)) ) {
                continue;
            }
            $marker = $context->selectorProjections->controlMarker($element->getNodePath() ?? '');
            if ( '' === $marker ) {
                continue;
            }

            $pattern = '';
            $specificityShim = '';
            $id = trim($element->getAttribute('id'));
            if ( '' !== $id && 1 === preg_match('/#' . preg_quote($id, '/') . '(?![\w-])/', $selector) ) {
                $pattern = '/#' . preg_quote($id, '/') . '(?![\w-])/';
                $specificityShim = ':not(#' . $context->authorStyles->idSpecificityShim() . ')';
            } else {
                foreach ( SourceDom::tokens($element->getAttribute('class')) as $className ) {
                    if ( '' !== $className && 1 === preg_match('/' . CssIdent::classSelectorRegex($className) . '(?![\w-])/', $selector) ) {
                        $pattern = '/' . CssIdent::classSelectorRegex($className) . '(?![\w-])/';
                        $specificityShim = ':not(.' . $context->authorStyles->classSpecificityShim() . ')';
                        break;
                    }
                }
            }
            if ( '' === $pattern ) {
                continue;
            }

            $target = ':where(.' . $marker . ($wrapper ? '.wp-block-buttons)' : ')' . $this->buttonLinkTarget($context)) . $specificityShim;
            $projected = preg_replace($pattern, $target, $selector, 1);
            if ( is_string($projected) && '' !== $projected ) {
                $rewritten[] = $projected;
            }
        }
        return array_values(array_unique($rewritten));
    }

    /** @return list<string>|null */
    private function projectRuntimeAttributeSelector(string $selector, AuthorStylesheetProjectionContext $context): ?array
    {
        $selector = trim($selector);
        $selectorMarkers = $context->selectorProjections->runtimeAttributeSelectorMarkers();
        uksort($selectorMarkers, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));
        foreach ( $selectorMarkers as $runtimeSelector => $markers ) {
            if ( ! str_starts_with($selector, $runtimeSelector) ) {
                continue;
            }
            $suffix = substr($selector, strlen($runtimeSelector));
            if ( '' !== $suffix && ! in_array($suffix[0], array('.', ':'), true) ) {
                continue;
            }
            $parsed = $context->sourceStyles->parsedSelector($runtimeSelector);
            if ( ! $parsed['supported'] ) {
                continue;
            }
            $shims = $this->selectorSpecificityShims($parsed, $context);
            return array_map(static fn (string $marker): string => ':where(.' . $marker . ')' . $shims . $suffix, $markers);
        }
        return null;
    }

    /** @param array<string, mixed> $parsed @param list<DOMElement> $matches @return list<string>|null */
    private function projectSourceAttributeAncestrySelector(string $selector, array $parsed, array $matches, AuthorStylesheetProjectionContext $context, bool $controlWrapper = false): ?array
    {
        if (SourceAttributeSubjects::retainsDataPredicates($parsed, $context->authorStyles)) return null;
        $rightmost = $parsed['rightmost_compound_span'] ?? null;
        $ancestry = is_array($rightmost) ? substr($selector, 0, (int) $rightmost['start']) : '';
        if ( null !== $parsed['pseudo_state_suffix_span'] || ! preg_match('/\[\s*data-[a-z0-9_-]+(?:\s*[~|^$*]?=|\s*\])/i', $ancestry) ) {
            return null;
        }
        // Document attributes survive on their actual ancestors. Flattening a
        // route-owned predicate onto shared content would freeze one page's
        // state into every use of that header/footer.
        foreach (array_slice($parsed['compounds'] ?? array(), 0, -1) as $compound) {
            if (!\Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorCompoundInspector::containsDataAttribute($compound)) continue;
            $predicate = array_replace($parsed, array('compounds' => array($compound), 'combinators' => array()));
            for ($root = $context->authorStyles->sourceBody(); $root instanceof DOMElement; $root = $root->parentNode) {
                if (CssSelectorMatcher::matches($root, $predicate)['matches']) return null;
            }
        }
        // A device gate survives lowering. If this arm still owns every
        // source match, keep its live selector instead of binding the gate to
        // one identity per element. A subset must retain the exclusions below.
        $allMatches = $this->matchingSourceElements($selector, $parsed, $context);
        $deviceOnly = true;
        $hasDevice = false;
        foreach ($parsed['compounds'] ?? array() as $compound) {
            foreach ($compound['attributes'] ?? array() as $attribute) {
                if (in_array($attribute['name'], array(CaptureEvidence::DEVICE_DOCUMENT, EngineMarker::DEVICE_SCOPE_ATTRIBUTE), true)) $hasDevice = true;
                else $deviceOnly = false;
            }
        }
        $plainSubjects = true;
        foreach ($matches as $element) {
            $path = $element->getNodePath() ?? '';
            if ('' !== $context->selectorProjections->navigationSubject($path)
                || '' !== $context->selectorProjections->controlMarker($path)
                || '' !== $context->selectorProjections->imageWrapperMarker($path)
                || null !== $context->selectorProjections->imageFigure($path)) $plainSubjects = false;
        }
        $subject = CssSelectorCompoundInspector::rightmost($parsed['compounds']);
        $resetSubject = array() === array_merge($subject['classes'] ?? array(), $subject['ids'] ?? array(), $subject['attributes'] ?? array());
        foreach ($parsed['compounds'] as $compound) {
            if (array() !== ($compound['ids'] ?? array())) $resetSubject = false;
        }
        if ($hasDevice && $deviceOnly && $plainSubjects && $resetSubject && $matches === $allMatches) {
            return array($this->rewriteSourceTagTypes($selector, $parsed, $context));
        }
        $projected = array();
        $scope = '';
        // The parser folds :where() attributes into the first compound, even
        // when the gate is attached to root classes or preceded by a comment.
        // Retain only the device predicate; root classes retain their weight
        // through the shims below, not through a flattened ancestor compound.
        foreach ($parsed['compounds'][0]['attributes'] ?? array() as $attribute) {
            if (in_array($attribute['name'], array(CaptureEvidence::DEVICE_DOCUMENT, EngineMarker::DEVICE_SCOPE_ATTRIBUTE), true)
                && '=' === $attribute['operator']) {
                $scope = ':where([' . $attribute['name'] . '="' . str_replace(array('\\', '"', "\n", "\r", "\f"), array('\\\\', '\\"', '\\a ', '\\d ', '\\c '), $attribute['value']) . '"]) ';
                break;
            }
        }
        // A functional device list is an OR, not its first attribute. Keep
        // the entire zero-specificity gate, including every captured device.
        $deviceAttribute = '(?:' . preg_quote(CaptureEvidence::DEVICE_DOCUMENT, '/') . '|' . preg_quote(EngineMarker::DEVICE_SCOPE_ATTRIBUTE, '/') . ')';
        $devicePredicate = '\\[\\s*' . $deviceAttribute . '\\s*=\\s*(?:"[^"\\r\\n]*"|\'[^\'\\r\\n]*\')\\s*\\]';
        if (preg_match('/:where\\(\\s*' . $devicePredicate . '(?:\\s*,\\s*' . $devicePredicate . ')*\\s*\\)/i', $ancestry, $deviceList)) {
            $scope = $deviceList[0] . ' ';
        }
        // Preserve the historical output for selectors without a device gate.
        if ('' === $scope && preg_match('/^\s*((?::where\([^(),]+\)\s+)+)/i', $selector, $scopeMatch)) {
            $scope = trim((string) $scopeMatch[1]) . ' ';
        }
        // The attribute marker is per selector: when a match lowered to
        // core/button carries it onto the generated wrappers, the arm for the
        // selector's other subjects must not paint those wrappers too.
        $wrapperGuard = '';
        foreach ( $matches as $element ) {
            if ( '' !== $context->selectorProjections->controlMarker($element->getNodePath() ?? '') ) {
                $wrapperGuard = ':not(.wp-block-buttons):not(.wp-block-button)';
                break;
            }
        }
        foreach ( $matches as $element ) {
            $navigationTarget = $context->selectorProjections->navigationSubject($element->getNodePath() ?? '');
            if ( '' !== $navigationTarget ) {
                // This projection binds the subject to its element identity, so
                // the live predicates a typed item rule keeps elsewhere are gone
                // here anyway. Bind it through the native subject instead, with
                // the shared carrier baseline every other rule on that item gets;
                // otherwise Core defaults and lower ranked author rules win.
                if ($context->selectorProjections->selectsCurrentNavigationState($selector)) $navigationTarget = AuthorSelectorProjectionState::currentNavigationSubject($navigationTarget);
                elseif ($context->selectorProjections->selectsRestingNavigationState($selector)) $navigationTarget = AuthorSelectorProjectionState::restingNavigationSubject($navigationTarget);
                $projected[] = ':root ' . $scope . $navigationTarget . $this->selectorSpecificityShims($parsed, $context);
                continue;
            }
            $id = trim($element->getAttribute('id'));
            $parent = $element->parentNode;
            $parentMarker = $parent instanceof DOMElement && preg_match('/>\s*$/', trim($ancestry))
                ? $context->selectorProjections->attributeMarker($parent->getNodePath() ?? '', AuthorSelectorProjectionState::parentAttributeIdentity($selector))
                : '';
            if ( '' !== $parentMarker && preg_match('/^[a-z_][a-z0-9_-]*$/i', $id) ) {
                $projected[] = $scope . ':where(.' . $parentMarker . ')>:where(#' . $id . ')' . $this->selectorSpecificityShims($parsed, $context);
                continue;
            }
            if ( preg_match('/^[a-z_][a-z0-9_-]*$/i', $id) ) {
                $target = '#' . $id;
            } else {
                $marker = $context->selectorProjections->attributeMarker($element->getNodePath() ?? '', $selector);
                if ( '' === $marker ) {
                    return null;
                }
                $target = '.' . $marker;
            }
            $shims = $this->selectorSpecificityShims($parsed, $context);
            if ( 'img' === strtolower($element->tagName)
                && null !== $context->selectorProjections->imageFigure($element->getNodePath() ?? '') ) {
                // Image identity hooks move onto the synthetic figure. The
                // matched subject is still its image, not that carrier box.
                $projected[] = NativeImageLeafPresentation::selectors($scope . ':where(' . $target . ')', $shims);
                continue;
            }
            if ( '' !== $context->selectorProjections->controlMarker($element->getNodePath() ?? '') ) {
                if ( $controlWrapper && LayoutParticipation::BOX_LINK !== LayoutParticipation::resolve($element, $this->styleResolver)->participatingBox() ) {
                    // Outer box spacing belongs to the layout item, not its link.
                    $projected[] = $scope . ':where(' . $target . ')' . $shims . ':where(.wp-block-buttons)';
                    continue;
                }
                // A source control converted to core/button carries its marker on
                // the generated core/buttons wrapper, and on core/button when that
                // block is built from the control itself. The authored surface is
                // the inner link either way, so the wrappers never take the rule.
                // A control that keeps the marker on itself (a materialized form
                // submit) still matches the first arm.
                $base = $scope . ':where(' . $target . ')' . $shims;
                $projected[] = $scope . ':where(' . $target . ':not(.wp-block-buttons,.wp-block-button))' . $shims;
                $projected[] = $base . $this->buttonLinkTarget($context);
                $projected[] = $base . '> :where(.wp-block-button)' . $this->buttonLinkTarget($context);
                continue;
            }
            $projected[] = $scope . ':where(' . $target . ('#' === $target[0] ? '' : $wrapperGuard) . ')' . $shims;
        }
        return array_values(array_unique($projected));
    }

    /** @param array<string, mixed> $parsed @param list<DOMElement> $matches @return list<string>|null */
    private function projectSourceAttributeSelector(string $selector, array $parsed, array $matches, AuthorStylesheetProjectionContext $context, bool $controlWrapper = false): ?array
    {
        if ( null !== $parsed['pseudo_state_suffix_span'] ) {
            return null;
        }
        $compounds = $parsed['compounds'] ?? array();
        $rightmost = CssSelectorCompoundInspector::rightmost($compounds);
        if ( ! \Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorCompoundInspector::containsDataAttribute($rightmost)
            || \Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorCompoundInspector::hasLiveAttributePredicate($rightmost)
        ) {
            return null;
        }
        $projected = array();
        $controlOwned = array();
        foreach ( $matches as $element ) {
            $path = $element->getNodePath() ?? '';
            $controlOwned[] = '' !== $context->selectorProjections->controlMarker($path);
        }
        // The attribute marker is per selector, so a source control converted
        // to core/button also carries it on the generated core/buttons and
        // core/button wrappers. Its surface is owned by the control arm below;
        // the attribute arm for the selector's other subjects must not paint
        // those wrappers a second time.
        // Only the attribute predicates become static identity. Class tokens
        // survive lowering and can change at runtime, so keep their conditions
        // inside :where() while the shims retain the authored specificity.
        $attributeTarget = CssSelectorCompoundInspector::markerClassConditions($rightmost);
        if (null === $attributeTarget) return null;
        $attributeTarget .= in_array(true, $controlOwned, true) ? ':not(.wp-block-buttons):not(.wp-block-button)' : '';
        foreach ( $matches as $index => $element ) {
            $path = $element->getNodePath() ?? '';
            if ( $controlOwned[$index] ) {
                $controlMarker = $context->selectorProjections->controlMarker($path);
                // Static data predicates identify the source control, not the
                // extra wrapper core/button saves around its painted surface.
                $wrapper = $controlWrapper && LayoutParticipation::BOX_LINK !== LayoutParticipation::resolve($element, $this->styleResolver)->participatingBox();
                $projected[] = $this->projectControlSelector($selector, $parsed, $controlMarker, $context, $wrapper);
                continue;
            }
            $marker = $context->selectorProjections->attributeMarker($path, $selector);
            if ( '' === $marker ) {
                return null;
            }
            // Native navigation subjects carry a shared four-class baseline on
            // every other projection path; keep it here, or a class rule this
            // selector beat in the source would outrank it.
            $baseline = '' === $context->selectorProjections->navigationSubject($path)
                ? ''
                : str_repeat(':not(.' . $context->authorStyles->classSpecificityShim() . ')', 4);
            $projected[] = ':where(.' . $marker . $attributeTarget . ')' . $baseline . $this->selectorSpecificityShims($parsed, $context)
                . (($rightmost['empty'] ?? false) ? ':empty' : '');
        }
        return array_values(array_unique($projected));
    }

    private function projectSourceAttributeNegationStateSelector(string $selector, AuthorStylesheetProjectionContext $context): string
    {
        $marker = $context->selectorProjections->attributeNegationMarker(trim($selector));
        if ( '' === $marker ) {
            return $selector;
        }
        return preg_replace(
            '/:not\(\s*\[\s*data-[a-z0-9_-]+(?:\s*[~|^$*]?=\s*(?:"[^"]*"|\'[^\']*\'|[^\]\s]+))?\s*\]\s*\)/i',
            ':not(.' . $marker . ')',
            $selector
        ) ?? $selector;
    }

    /**
     * @param array<string, mixed> $parsed
     * @param array<int, array{end: int, value: string}> $replacements Further
     *        span replacements, keyed by start offset, that must not overlap a
     *        rewritten type span.
     */
    public function rewriteSourceTagTypes(string $selector, array $parsed, AuthorStylesheetProjectionContext $context, string $rightmostInsertion = '', array $replacements = array()): string
    {
        foreach ( array_merge($parsed['type_spans'], CssSelectorMatcher::functionalTypeSpans($selector)) as $typeSpan ) {
            // A caller that already replaced the compound holding this type has
            // decided where that type goes; the marker must not take it back.
            foreach ( $replacements as $replacementStart => $replacement ) {
                if ( (int) $typeSpan['start'] >= (int) $replacementStart && (int) $typeSpan['start'] < (int) $replacement['end'] ) {
                    continue 2;
                }
            }
            $marker = $context->selectorProjections->tagMarker((string) $typeSpan['name']);
            if ( '' !== $marker ) {
                // In a negation, exclude both native tags and retagged source
                // subjects. :is() keeps the original type weight; the marker
                // arm contributes none, including inside a :not() list.
                $value = ($typeSpan['negated'] ?? false)
                    ? ':is(' . substr($selector, $typeSpan['start'], $typeSpan['end'] - $typeSpan['start']) . ',:where(.' . $marker . '))'
                    : ':where(.' . $marker . ')' . $this->typeSpecificityShim($context);
                $replacements[$typeSpan['start']] = array( 'end' => $typeSpan['end'], 'value' => $value );
            } elseif ( 'img' === strtolower((string) $typeSpan['name'])
                && str_contains($context->emittedMarkup, 'assets/materialized-svg/inline-svg-')
            ) {
                // SVG materialization must not create new author img subjects,
                // even for !important rules or types inside :where/:is/:not.
                // The zero-specificity guard uses the owned asset identity so
                // canonical Core save markup needs no extra image attributes.
                // Only source tokens are guarded; generated SVG image bridges
                // must still reach their intended materialized image leaf.
                $replacements[$typeSpan['start']] = array(
                    'end' => $typeSpan['end'],
                    'value' => substr($selector, $typeSpan['start'], $typeSpan['end'] - $typeSpan['start']) . ':not(:where([src*="assets/materialized-svg/inline-svg-"]))',
                );
            } elseif ( 'span' === strtolower((string) $typeSpan['name']) ) {
                // Core's label span has no source identity. Even a dormant
                // author rule (or an arm retained for unprojected subjects)
                // must not acquire that engine-owned wrapper as a new match.
                // Keep real source spans addressable, without adding weight.
                // A span carrier for another source tag (a lowered `<p>`)
                // matches only that tag's rules, never those for real spans.
                $replacements[$typeSpan['start']] = array( 'end' => $typeSpan['end'], 'value' => 'span:not(:where(.wp-block-navigation-item__label,' . EngineMarker::foreignSubjectCarrier('span') . '))' );
            } elseif ( 'code' === strtolower((string) $typeSpan['name']) ) {
                $replacements[$typeSpan['start']] = array( 'end' => $typeSpan['end'], 'value' => 'code:not(.' . $context->authorStyles->classSpecificityShim() . ')' );
            }
        }
        if ( '' !== $rightmostInsertion ) {
            $replacements[(int) $parsed['rightmost_rewrite_end']] = array( 'end' => (int) $parsed['rightmost_rewrite_end'], 'value' => $rightmostInsertion );
        }
        return $this->projectSourceClassSelector($selector, $context, $replacements);
    }

    /**
     * Preserve class ownership even when a selector is dormant or outside the
     * matcher's subset. Replace simple class tokens, including those inside
     * functional pseudos, without changing specificity or touching strings.
     * @param array<int, array{end: int, value: string}> $replacements
     */
    public function projectSourceClassSelector(string $selector, AuthorStylesheetProjectionContext $context, array $replacements = array()): string
    {
        $state = CssSyntaxScanner::state();
        // Native projection spans own their replacement markup. Only untouched
        // source tokens are eligible; Core classes introduced by a bridge are
        // destination identities, not author selector provenance.
        $ownedSpans = $replacements;
        $length = strlen($selector);
        for ( $offset = 0; $offset < $length; ) {
            if ( '.' === $selector[$offset] && '' === $state['quote'] && ! $state['comment'] && 0 === $state['brackets']
                && 1 === preg_match('/\G\.((?:[A-Za-z0-9_-]|[^\x00-\x7f]|\\\\(?:[0-9a-fA-F]{1,6}\s?|[^\r\n\f]))+)/', $selector, $match, 0, $offset)
            ) {
                $parsed = CssSelectorMatcher::parse($match[0]);
                $class = (string) ($parsed['compounds'][0]['classes'][0] ?? '');
                $marker = $context->authorStyles->sourceClassMarker($class);
                if ( $context->authorStyles->isHostRenderedClass($class) && $this->hasReliableHostClassIdentity($class, $context) ) {
                    $marker = EngineMarker::subjectClassClass($class);
                }
                $end = $offset + strlen($match[0]);
                foreach ( $ownedSpans as $start => $replacement ) {
                    if ( $offset < $replacement['end'] && $end > $start ) {
                        $marker = '';
                        break;
                    }
                }
                if ( '' !== $marker ) {
                    $replacements[$offset] = array('end' => $end, 'value' => '.' . $marker);
                }
                $offset = $end;
                continue;
            }
            $offset = CssSyntaxScanner::consume($selector, $offset, $state) ?? ($offset + 1);
        }
        return $this->replaceSelectorSpans($selector, $replacements);
    }

    /**
     * Rewriting is safe only when every source occurrence has a confirmed
     * wrapper projection and the emitted markup contains enough carriers.
     * A requested marker alone is not proof: lowering can discard a wrapper.
     */
    private function hasReliableHostClassIdentity(string $class, AuthorStylesheetProjectionContext $context): bool
    {
        $elements = $context->authorStyles->sourceElementsByClass($class);
        // With no source occurrences the selector must match nothing, even
        // when the host manufactures this class at runtime.
        if ( array() === $elements ) {
            return true;
        }
        $identity = EngineMarker::subjectClassClass($class);
        foreach ( $elements as $element ) {
            if ( ! $context->selectorProjections->hasHostClassCarrier($element->getNodePath() ?? '') ) {
                return false;
            }
        }
        preg_match_all('/<[^>]+\\sclass="[^"]*\\b' . preg_quote($identity, '/') . '\\b[^"]*"/', $context->emittedMarkup, $carriers);
        return count($carriers[0]) >= count($elements);
    }

    /** @param array<string, mixed> $parsed */
    public function projectControlSelector(string $selector, array $parsed, string $marker, AuthorStylesheetProjectionContext $context, bool $wrapper = false): string
    {
        $universal = $this->universalControlSubject($selector, $parsed, $context);
        if (null !== $universal) {
            return $universal['prefix'] . $this->projectControlTarget(':where(.blocks-engine-role-control)', $universal['selector'], $universal['parsed'], $context, $wrapper);
        }
        return $this->projectControlTarget(':where(.' . $marker . ')', $selector, $parsed, $context, $wrapper);
    }

    /**
     * Only an unrestricted subject can address every control carrier. Pseudo
     * elements are projected through their host before reaching this method;
     * identity, state and structural predicates must keep their marker arms.
     * Keep ancestry live rather than freezing its captured matches into a list.
     *
     * @param array<string, mixed> $parsed
     * @return array{prefix: string, selector: string, parsed: array<string, mixed>}|null
     */
    public function universalControlSubject(string $selector, array $parsed, AuthorStylesheetProjectionContext $context): ?array
    {
        $span = $parsed['rightmost_compound_span'] ?? null;
        if (!is_array($span)) return null;
        $subject = substr($selector, (int) $span['start']);
        // :is(*) and :where(*) impose no restriction on the originating element.
        if (1 !== preg_match('/^\s*\*(?::(?:is|where)\(\s*\*\s*\))*\s*$/i', $subject)) return null;
        $prefix = substr($selector, 0, (int) $span['start']);
        return array(
            'prefix' => $this->rewriteSourceTagTypes($prefix, $context->sourceStyles->parsedSelector($prefix), $context),
            'selector' => $subject,
            'parsed' => $context->sourceStyles->parsedSelector($subject),
        );
    }

    /**
     * The control arm for a zero-specificity carrier target: one marker, or
     * the role and subject classes contextFreeSubject() names.
     *
     * @param array<string, mixed> $parsed
     */
    public function projectControlTarget(string $target, string $selector, array $parsed, AuthorStylesheetProjectionContext $context, bool $wrapper = false): string
    {
        $suffix = null === $parsed['pseudo_state_suffix_span'] ? '' : substr($selector, $parsed['pseudo_state_suffix_span']['start']);
        return $target . $this->selectorSpecificityShims($parsed, $context) . ($wrapper ? ':where(.wp-block-buttons)' : $this->buttonLinkTarget($context)) . $suffix;
    }

    /**
     * The child step onto core's button link for an authored rule. Core's
     * global-styles `:root :where(.wp-element-button, .wp-block-button__link)`
     * weighs (0,1,0), and the editor prints global styles after every other
     * style, so a tie loses there. One type weight on every projected link
     * arm outranks it by specificity on every surface, and leaves the order
     * among authored link rules as it was.
     */
    public function buttonLinkTarget(AuthorStylesheetProjectionContext $context): string
    {
        return '> :where(.wp-block-button__link)' . $this->typeSpecificityShim($context);
    }

    /**
     * A subject arm target, `:where(.blocks-engine-role-<kind>.<subject
     * classes>)`, for a selector that is one context-free compound, and the
     * routed markers it does not cover (which keep their own arms); null when
     * the target would reach a carrier the per-element arms do not.
     *
     * Control, semantic and RichText carriers carry their source element's
     * subject classes (tag and class tokens, EngineMarker::subjectTagClass()), which a
     * type, universal or class compound can test without any context. The
     * target therefore reaches every `$kind` carrier with subject classes whose
     * source the compound matches, on any page. It stands in for the
     * per-element arms of `$routedMarkers` only when every such carrier on this
     * page is among them: none is routed elsewhere (a table or navigation
     * subject, or a control that is also a RichText run, say). A routed marker without such a carrier (a
     * presentation child that shares its control's marker, a carrier that
     * carries no subject classes) keeps its own arm. This page's cascade is
     * unchanged, and the arm text no longer depends on which other elements
     * the page has.
     *
     * @param array<string, mixed> $parsed
     * @param list<string> $routedMarkers
     * @return array{target: string, rest: list<string>}|null
     */
    public function contextFreeSubject(string $kind, array $parsed, array $routedMarkers, AuthorStylesheetProjectionContext $context): ?array
    {
        if ( ! ($parsed['supported'] ?? false) || 1 !== count($parsed['compounds'] ?? array()) ) {
            return null;
        }
        $compound = $parsed['compounds'][0];
        if ( ! self::isContextFreeCompound($compound) ) {
            return null;
        }
        $carriers = $context->selectorProjections->carrierMarkersMatching($kind, $compound);
        if ( null === $carriers ) {
            return null;
        }
        $routed = array_values(array_unique($routedMarkers));
        if ( array() !== array_diff($carriers['reached'], $routed) ) {
            return null;
        }
        $classes = array( 'blocks-engine-role-' . $kind, EngineMarker::SUBJECT_BASE_CLASS );
        if ( is_string($compound['type'] ?? null) ) {
            $classes[] = EngineMarker::subjectTagClass($compound['type']);
        }
        foreach ( array_values(array_unique(array_map('strval', $compound['classes'] ?? array()))) as $class ) {
            $classes[] = EngineMarker::subjectClassClass($class);
        }
        return array( 'target' => ':where(.' . implode('.', $classes) . ')', 'rest' => array_values(array_diff($routed, $carriers['covered'])) );
    }

    /**
     * A compound whose match is decided by the element's own tag and class
     * tokens: a type or `*`, plus classes, and nothing else (no id, attribute,
     * negation, `:is()`/`:where()` argument, `:has()` relationship or
     * structural pseudo-class).
     *
     * @param array<string, mixed> $compound
     */
    private static function isContextFreeCompound(array $compound): bool
    {
        $known = array( 'type', 'universal', 'classes', 'ids', 'attributes', 'not', 'any', 'has', 'nth_child', 'first_child', 'last_child', 'root', 'resting_state_negations', 'zero_specificity', 'nth_type' );
        foreach ( $compound as $key => $value ) {
            if ( ! in_array($key, $known, true) ) {
                return false;
            }
        }
        if ( array() !== ($compound['ids'] ?? array()) || array() !== ($compound['attributes'] ?? array())
            || array() !== ($compound['not'] ?? array()) || array() !== ($compound['any'] ?? array())
            || array() !== ($compound['has'] ?? array())
            || null !== ($compound['nth_child'] ?? null) || null !== ($compound['nth_type'] ?? null)
            || ($compound['first_child'] ?? false) || ($compound['last_child'] ?? false) || ($compound['root'] ?? false)
            || 0 !== (int) ($compound['resting_state_negations'] ?? 0)
        ) {
            return false;
        }
        return is_string($compound['type'] ?? null) || ($compound['universal'] ?? false) || array() !== ($compound['classes'] ?? array());
    }

    /** @param array<string, mixed> $parsed */
    public function projectSemanticLeafSelector(string $selector, array $parsed, string $marker, AuthorStylesheetProjectionContext $context): string
    {
        return $this->projectSemanticTarget(':where(.' . $marker . ')', $selector, $parsed, $context);
    }

    /** @param array<string, mixed> $parsed */
    private function projectSemanticTarget(string $target, string $selector, array $parsed, AuthorStylesheetProjectionContext $context): string
    {
        $suffix = null === $parsed['pseudo_state_suffix_span'] ? '' : substr($selector, $parsed['pseudo_state_suffix_span']['start']);
        return $target . $this->selectorSpecificityShims($parsed, $context) . $suffix;
    }

    /** @param array<string, mixed> $parsed */
    public function projectRichTextSemanticSelector(string $selector, array $parsed, string $marker, AuthorStylesheetProjectionContext $context): string
    {
        $suffix = null === $parsed['pseudo_state_suffix_span'] ? '' : substr($selector, $parsed['pseudo_state_suffix_span']['start']);
        return ':where(' . RichTextMarkerSelector::carrierSelectorList($marker) . ')' . $this->selectorSpecificityShims($parsed, $context) . $suffix;
    }

    /** @param array<string, mixed> $parsed */
    public function projectParagraphCarrierChildSelector(string $selector, array $parsed, AuthorStylesheetProjectionContext $context, bool $itemSlot = false): ?string
    {
        $rightmost = $parsed['rightmost_compound_span'] ?? null;
        $combinators = $parsed['combinators'] ?? array();
        if ( ! is_array($rightmost) || '>' !== end($combinators) ) {
            return null;
        }
        $start = (int) $rightmost['start'];
        // The carrier re-parents the subject, so sibling-indexed pseudo-classes
        // would be evaluated against different siblings than in the source.
        if ( 1 === preg_match('/:(?:first|last|only|nth)-|:nth-/i', substr($selector, $start)) ) {
            return null;
        }
        $arms = array();
        $classes = $itemSlot ? array(SourceBlockAttributeProjector::SYNTHETIC_PARAGRAPH_CLASS) : array(AuthorStylesheetProjector::INLINE_LAYOUT_CARRIER_CLASS, SourceBlockAttributeProjector::SYNTHETIC_PARAGRAPH_CLASS);
        foreach ( $classes as $class ) {
            $carrier = ':where(.' . $class . ')';
            $prefix = substr($selector, 0, $start);
            $subject = substr($selector, $start);
            $projected = $prefix . $carrier . '>' . $subject;
            if ( ! $this->matchesEmittedMarkup(CssSelectorMatcher::parse($projected), $context->emittedMarkup) ) continue;
            // The same subject predicate guards the item slot: unrelated
            // synthetic paragraphs must not receive this item's placement.
            $arms[] = $itemSlot ? $prefix . $carrier . ':has(>' . $subject . ')' : $projected;
        }
        return array() === $arms ? null : implode(',', $arms);
    }

    /** Project child edges to retained anchors even when the rule addresses a descendant. */
    private function projectParagraphAnchorAncestorSelector(string $selector, array $parsed, AuthorStylesheetProjectionContext $context): ?string
    {
        $arms = array();
        foreach ($parsed['type_spans'] ?? array() as $span) {
            $index = (int) $span['compound'];
            if ($index === count($parsed['compounds']) - 1
                || 'a' !== strtolower($span['name']) || '>' !== ($parsed['combinators'][$index - 1] ?? null)) continue;
            $start = (int) $span['start'];
            $target = substr($selector, 0, $start) . ':where(.' . SourceBlockAttributeProjector::SYNTHETIC_PARAGRAPH_CLASS . ')>' . substr($selector, $start);
            $target = $this->rewriteSourceTagTypes($target, CssSelectorMatcher::parse($target), $context);
            if ($this->matchesEmittedMarkup(CssSelectorMatcher::parse($target), $context->emittedMarkup)) $arms[] = $target;
        }
        return array() === $arms ? null : implode(',', $arms);
    }

    /** @param array<string, mixed> $parsed */
    private function projectInlineLayoutCarrierSelector(string $selector, array $parsed, bool $addressable): string
    {
        $rightmost = $parsed['rightmost_compound_span'] ?? null;
        if ( ! is_array($rightmost) ) {
            return $selector;
        }
        $prefix = substr($selector, 0, (int) $rightmost['start']);
        $right = substr($selector, (int) $rightmost['start']);
        $carrierChild = 'p.' . AuthorStylesheetProjector::INLINE_LAYOUT_CARRIER_CLASS . ' > ';
        // A content-wrapping <a> is pushed down onto the carrier as a child
        // wrapping the source leaf. Child combinators that targeted that leaf
        // must also reach it through the propagated anchor, or authored
        // typography on nested lockup spans is dropped.
        $selectors = array($prefix . $carrierChild . 'a > ' . $right, $prefix . $carrierChild . $right);
        // A simple addressable inline leaf may now use the carrier paragraph as
        // its native ID/class owner, keeping that selector alive after a text edit.
        // On ordinary carriers this extra selector matches nothing.
        $subject = trim($right);
        if ($addressable && preg_match('/^[#.][A-Za-z][A-Za-z0-9_-]*(?:[.#][A-Za-z][A-Za-z0-9_-]*)*$/', $subject)) {
            $selectors[] = $prefix . 'p.' . AuthorStylesheetProjector::INLINE_LAYOUT_CARRIER_CLASS . $subject;
        }
        return implode(',', $selectors);
    }

    /** @param array<string, mixed> $parsed */
    private function projectTableDescendantSelector(string $selector, array $parsed, DOMElement $element, AuthorStylesheetProjectionContext $context): ?string
    {
        $table = 'table' === strtolower($element->tagName) ? $element : SourceDom::ancestorElement($element, 'table');
        if (in_array(strtolower($element->tagName), array('table', 'tr', 'td'), true)
            && $table instanceof DOMElement
            && (new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\TableClassificationPolicy())->lowersToColumns($table)
        ) {
            $marker = $context->selectorProjections->semanticMarker($element->getNodePath() ?? '');
            if ('' !== $marker) {
                // A common carrier baseline lets authored rules beat Core's
                // block defaults without changing their relative specificity.
                return ':root .' . $marker . $this->projectSemanticLeafSelector($selector, $parsed, $marker, $context);
            }
        }
        if ( ! in_array(strtolower($element->tagName), array( 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th' ), true)
            || ! TableSelectorProjectionPolicy::needsStructuralProjection($parsed, $element)
        ) {
            return null;
        }
        $table = SourceDom::ancestorElement($element, 'table');
        $marker = $table instanceof DOMElement ? $context->selectorProjections->tableMarker($table->getNodePath() ?? '') : '';
        $path = $table instanceof DOMElement ? $this->serializedTableDescendantPath($table, $element, $context) : '';
        if ( '' === $marker || '' === $path ) {
            return null;
        }
        $suffix = null === $parsed['pseudo_state_suffix_span'] ? '' : substr($selector, $parsed['pseudo_state_suffix_span']['start']);
        return '.' . $marker . '>table>' . $path . $this->selectorSpecificityShims($parsed, $context) . $suffix;
    }

    private function serializedTableDescendantPath(DOMElement $table, DOMElement $element, AuthorStylesheetProjectionContext $context): string
    {
        $tableId = spl_object_id($table);
        return $context->selectorProjections->tableDescendantPath($tableId, spl_object_id($element), function () use ($table): array {
            $paths = array();
            foreach ( array( 'thead', 'tbody', 'tfoot' ) as $section ) {
                $rowIndex = 0;
                foreach ( $table->getElementsByTagName($section) as $sectionElement ) {
                    if ( $sectionElement instanceof DOMElement && $this->belongsToTable($sectionElement, $table) ) {
                        $paths[spl_object_id($sectionElement)] = $section;
                    }
                }
                foreach ( $table->getElementsByTagName('tr') as $row ) {
                    if ( ! $row instanceof DOMElement || ! $this->belongsToTable($row, $table) || $section !== $this->serializedTableSection($row) ) {
                        continue;
                    }
                    ++$rowIndex;
                    $rowPath = $section . '>tr:nth-child(' . $rowIndex . ')';
                    $paths[spl_object_id($row)] = $rowPath;
                    $cellIndex = 0;
                    foreach ( $row->childNodes as $cell ) {
                        if ( ! $cell instanceof DOMElement || ! in_array(strtolower($cell->tagName), array( 'td', 'th' ), true) ) {
                            continue;
                        }
                        ++$cellIndex;
                        $paths[spl_object_id($cell)] = $rowPath . '>' . strtolower($cell->tagName) . ':nth-child(' . $cellIndex . ')';
                    }
                }
            }
            return $paths;
        });
    }

    /**
     * A structurally projected cell selector, split into its table and the
     * declaration tail (specificity shims and pseudo suffix) after the cell.
     *
     * @return array{table: DOMElement, marker: string, tail: string}|null
     */
    private function structuralTableCellTarget(string $projected, DOMElement $element, AuthorStylesheetProjectionContext $context): ?array
    {
        if ( ! in_array(strtolower($element->tagName), array( 'td', 'th' ), true) ) {
            return null;
        }
        $table = SourceDom::ancestorElement($element, 'table');
        if ( ! $table instanceof DOMElement ) {
            return null;
        }
        $marker = $context->selectorProjections->tableMarker($table->getNodePath() ?? '');
        $path = $this->serializedTableDescendantPath($table, $element, $context);
        $prefix = '.' . $marker . '>table>' . $path;
        if ( '' === $marker || '' === $path || ! str_starts_with($projected, $prefix) ) {
            return null;
        }
        return array( 'table' => $table, 'marker' => $marker, 'tail' => substr($projected, strlen($prefix)) );
    }

    /**
     * Cell paths covering exactly the matched cells, folding a whole table,
     * section, column or row into one path. Every folded path keeps the
     * per-cell path's specificity (three types, two pseudo-classes): `:is()`
     * counts as its most specific argument and `:nth-child(n)` matches every
     * position, so the cascade between authored rules is unchanged.
     *
     * @param array<int, DOMElement> $matched Matched cells keyed by object id.
     * @return list<string>
     */
    private function compactTableCellPaths(DOMElement $table, array $matched): array
    {
        $cell = ':is(td,th)';
        $sections = array();
        foreach ( array( 'thead', 'tbody', 'tfoot' ) as $section ) {
            $rows = array();
            foreach ( $table->getElementsByTagName('tr') as $row ) {
                if ( ! $row instanceof DOMElement || ! $this->belongsToTable($row, $table) || $section !== $this->serializedTableSection($row) ) {
                    continue;
                }
                $cells = array();
                foreach ( $row->childNodes as $child ) {
                    if ( $child instanceof DOMElement && in_array(strtolower($child->tagName), array( 'td', 'th' ), true) ) {
                        $cells[] = $child;
                    }
                }
                $rows[] = $cells;
            }
            if ( array() !== $rows ) {
                $sections[$section] = $rows;
            }
        }
        $isMatched = static fn (DOMElement $element): bool => isset($matched[spl_object_id($element)]);
        $fullSection = static function (array $rows) use ($isMatched): bool {
            foreach ( $rows as $cells ) {
                if ( array() === $cells || count($cells) !== count(array_filter($cells, $isMatched)) ) {
                    return false;
                }
            }
            return true;
        };

        $full = array_filter($sections, $fullSection);
        if ( array() !== $sections && count($full) === count($sections) ) {
            $sectionSelector = 1 === count($sections) ? (string) array_key_first($sections) : ':is(' . implode(',', array_keys($sections)) . ')';
            return array( $sectionSelector . '>tr:nth-child(n)>' . $cell . ':nth-child(n)' );
        }

        $paths = array();
        foreach ( $sections as $section => $rows ) {
            if ( isset($full[$section]) ) {
                $paths[] = $section . '>tr:nth-child(n)>' . $cell . ':nth-child(n)';
                continue;
            }
            $covered = array();
            $columns = max(array_map('count', $rows));
            for ( $column = 0; $column < $columns; ++$column ) {
                $wholeColumn = true;
                foreach ( $rows as $cells ) {
                    if ( ! isset($cells[$column]) || ! $isMatched($cells[$column]) ) {
                        $wholeColumn = false;
                        break;
                    }
                }
                if ( $wholeColumn && count($rows) > 1 ) {
                    $paths[] = $section . '>tr:nth-child(n)>' . $cell . ':nth-child(' . ($column + 1) . ')';
                    foreach ( $rows as $cells ) {
                        $covered[spl_object_id($cells[$column])] = true;
                    }
                }
            }
            foreach ( $rows as $rowIndex => $cells ) {
                $remaining = array_filter($cells, static fn (DOMElement $element): bool => $isMatched($element) && ! isset($covered[spl_object_id($element)]));
                if ( array() === $remaining ) {
                    continue;
                }
                $rowPath = $section . '>tr:nth-child(' . ($rowIndex + 1) . ')';
                if ( count($cells) > 1 && count($cells) === count(array_filter($cells, $isMatched)) ) {
                    $paths[] = $rowPath . '>' . $cell . ':nth-child(n)';
                    continue;
                }
                foreach ( $remaining as $column => $element ) {
                    $paths[] = $rowPath . '>' . strtolower($element->tagName) . ':nth-child(' . ($column + 1) . ')';
                }
            }
        }
        return $paths;
    }

    private function serializedTableSection(DOMElement $element): string
    {
        return SourceDom::ancestorElement($element, 'thead') instanceof DOMElement
            ? 'thead'
            : (SourceDom::ancestorElement($element, 'tfoot') instanceof DOMElement ? 'tfoot' : 'tbody');
    }

    private function belongsToTable(DOMElement $element, DOMElement $table): bool
    {
        for ( $parent = $element->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) {
            if ( 'table' === strtolower($parent->tagName) ) {
                return $parent === $table;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $parsed */
    public function projectImageSelector(string $selector, array $parsed, AuthorStylesheetProjectionContext $context, bool $wrapperOnly = false, bool $mediaText = false, string $mediaTextMarker = ''): string
    {
        if ( $mediaText && ! $wrapperOnly ) {
            if ( '' === $mediaTextMarker ) {
                return '';
            }
            // The source image's classes are intentionally not copied to the
            // native media-text wrapper: width constraints there resize the
            // whole text/image row. The marker is installed on the exact
            // generated media-text wrapper for this source image, so project
            // the declaration directly to its generated image.
            return NativeImageLeafPresentation::selectors(
                ':where(.' . $mediaTextMarker . ') .wp-block-media-text__media',
                $this->selectorSpecificityShims($parsed, $context)
            );
        }
        $projected = array();
        $tokens = CssSelectorTokenizer::tokenize($selector);
        $parentIndex = count($parsed['compounds']) - 2;
        $linkSpan = null;
        if ( ! $wrapperOnly && 'a' === strtolower((string) ($parsed['compounds'][$parentIndex]['type'] ?? ''))
            && in_array($parsed['combinators'][$parentIndex] ?? '', array( ' ', '>' ), true)
        ) {
            foreach ( $this->matchingSourceElements($selector, $parsed, $context) as $image ) {
                if ( $image->parentNode instanceof DOMElement && 'a' === strtolower($image->parentNode->tagName) ) {
                    $linkSpan = $tokens['compound_spans'][$parentIndex] ?? null;
                    break;
                }
            }
        }
        foreach ( $wrapperOnly ? array( '' ) : NativeImageLeafPresentation::LEAF_PATHS as $leafPath ) {
            $replacements = array(
                (int) $parsed['rightmost_rewrite_end'] => array(
                    'end' => (int) $parsed['rightmost_rewrite_end'],
                    'value' => '.wp-block-image' . $leafPath,
                ),
            );
            $rightmostType = $parsed['compounds'][count($parsed['compounds']) - 1]['type'] ?? null;
            if ( is_string($rightmostType) && in_array(strtolower($rightmostType), array( 'img', 'svg' ), true) ) {
                $typeSpan = end($parsed['type_spans']);
                if ( is_array($typeSpan) ) {
                    $replacements[(int) $typeSpan['start']] = array(
                        'end' => (int) $typeSpan['end'],
                        'value' => ':where(figure)' . $this->typeSpecificityShim($context),
                    );
                }
            }
            // core/image absorbs the source anchor as its inner link. Move
            // that compound inside the figure, retaining the generated link's
            // baseline type weight as well as the authored compound's rank.
            if ( null !== $linkSpan && ' > a > img' === $leafPath ) {
                $link = substr($selector, $linkSpan['start'], $linkSpan['end'] - $linkSpan['start']);
                $replacements[$linkSpan['start']] = array(
                    'end' => (int) $parsed['rightmost_compound_span']['start'],
                    'value' => '',
                );
                $replacements[(int) $parsed['rightmost_rewrite_end']]['value'] = '.wp-block-image > ' . $link . $this->typeSpecificityShim($context) . ' > img';
            }
            $arm = $this->replaceSelectorSpans($selector, $replacements);
            if ( ! (new CoreSelectorScoping())->selectorIsScoped($arm) ) {
                // The factory class is only a scope key, never a semantic
                // subject. Replacing * with :where() keeps its zero weight
                // and leaves the declaration on the same native image leaf.
                $arm = str_replace('*.wp-block-image', ':where(.blocks-engine-image).wp-block-image', $arm);
                if ( ! (new CoreSelectorScoping())->selectorIsScoped($arm) ) {
                    $arm = str_replace('.wp-block-image', ':where(.blocks-engine-image).wp-block-image', $arm);
                }
            }
            $projected[] = $arm;
        }
        return implode(',', $projected);
    }

    private function typeSpecificityShim(AuthorStylesheetProjectionContext $context): string
    {
        // Deliberately a type selector: :not(.be-t-0) would add class, not
        // type, specificity and change the cascade of projected source rules.
        return '' === $context->authorStyles->specificityShim() ? '' : ':not(' . $context->authorStyles->specificityShim() . ')';
    }

    /** @param array<string, mixed> $parsed */
    public function selectorSpecificityShims(array $parsed, AuthorStylesheetProjectionContext $context, bool $includeState = false): string
    {
        return CssSpecificityProjection::shims(
            $parsed,
            $context->authorStyles->specificityShim(),
            $context->authorStyles->classSpecificityShim(),
            $context->authorStyles->idSpecificityShim(),
            $includeState
        );
    }

    /**
     * core/navigation renders a direct source anchor inside a list item of its
     * own, so every rendered anchor is the only child of its item. A structural
     * pseudo-class authored on that anchor (`nav a:last-child`) then describes
     * the item's position among its siblings, not the anchor's, and would reach
     * every link. Move it onto a zero-specificity item wrapper, and address the
     * anchor through the class core hard-codes on it (with the type specificity
     * shim, as for every other projected type), so the projected rule selects
     * the same links with the authored specificity. The `>` it introduces is a
     * relationship inside one block, which the editor shell variants skip for a
     * `:where(.wp-block-…)` child.
     *
     * @param array<string, mixed> $parsed
     */
    public function projectNavigationItemAnchorSelector(string $selector, array $parsed, AuthorStylesheetProjectionContext $context, bool $itemSubject = false): ?string
    {
        $span = $parsed['rightmost_compound_span'] ?? null;
        if ( ! is_array($span) ) {
            return null;
        }
        $start = (int) $span['start'];
        $end = (int) $span['end'];
        // A dynamic state stays on the content anchor, and only as the
        // compound's trailing suffix; anything after it is left as authored.
        $bodyEnd = $end;
        $trailingState = '';
        $suffix = $parsed['pseudo_state_suffix_span'] ?? null;
        if ( is_array($suffix) ) {
            if ( (int) $suffix['end'] !== $end ) {
                return null;
            }
            $bodyEnd = (int) $suffix['start'];
            $trailingState = substr($selector, $bodyEnd, $end - $bodyEnd);
        }
        $typeLength = 0;
        foreach ( $parsed['type_spans'] as $typeSpan ) {
            if ( (int) $typeSpan['start'] >= $start ) {
                if ( 'a' !== strtolower((string) $typeSpan['name']) ) {
                    return null;
                }
                $typeLength = (int) $typeSpan['end'] - (int) $typeSpan['start'];
            }
        }
        $split = $this->splitNavigationItemCompound(substr($selector, $start, $bodyEnd - $start));
        if ( null === $split || '' === $split['structural'] ) {
            return null;
        }
        $rest = $split['rest'];
        // Core renders a `<ul>` (and, for an overlay menu, more wrappers)
        // between the navigation and its items. A one-to-one menu has no
        // nested lists, so a child combinator before the anchor relaxes to a
        // descendant without reaching any other item.
        $replaceStart = $start;
        $lead = '';
        if ( preg_match('/\s*>\s*$/', substr($selector, 0, $start), $childCombinator) ) {
            $replaceStart = $start - strlen($childCombinator[0]);
            $lead = ' ';
        }
        if ( $itemSubject ) {
            // The item itself, for the declarations the source link owned as
            // its parent's flex item. Only a subject the item fully carries
            // (no anchor-only attribute or dynamic state) is addressed; the
            // type keeps its weight through the shim, and the native-subject
            // baseline of a rendered list item outranks Core's item defaults.
            $anchorOnly = $typeLength > 0 ? substr($rest, $typeLength) : ltrim($rest, '*');
            if ( '' !== $anchorOnly || '' !== $trailingState ) {
                return null;
            }
            return $this->rewriteSourceTagTypes($selector, $parsed, $context, '', array(
                $replaceStart => array(
                    'end' => $end,
                    'value' => $lead . '.wp-block-navigation-item' . str_repeat(':not(.' . $context->authorStyles->classSpecificityShim() . ')', 3)
                        . $split['item'] . $split['structural'] . ( $typeLength > 0 ? $this->typeSpecificityShim($context) : '' ),
                ),
            ));
        }
        // The type leads its compound; the rendered anchor's own class takes
        // that place.
        $subject = ':where(.wp-block-navigation-item__content)';
        if ( $typeLength > 0 ) {
            $subject .= $this->typeSpecificityShim($context) . substr($rest, $typeLength);
        } elseif ( str_starts_with($rest, '*') ) {
            $subject .= substr($rest, 1);
        } else {
            $subject .= $rest;
        }
        $subject .= $trailingState;
        return $this->rewriteSourceTagTypes($selector, $parsed, $context, '', array(
            $replaceStart => array( 'end' => $end, 'value' => $lead . ':where(.wp-block-navigation-item)' . $split['item'] . $split['structural'] . '>' . $subject ),
        ));
    }

    /**
     * Separate a compound selector's simple selectors into what moves onto the
     * rendered navigation item and what stays on its content anchor, or null
     * when the compound holds a simple selector this projection cannot place.
     *
     * core/navigation-link renders the anchor's classes (`className`) and id
     * (`anchor`) on the `<li>`; the `<a>` keeps its class, `href`, and runtime
     * state. So: classes, ids, `[class…]`/`[id…]` attribute selectors, the
     * structural pseudo-classes the matcher models (`:first-child`,
     * `:last-child`, `:nth-child(n)`, `:nth-of-type(n)`), and a `:not()` made of
     * one of those kinds alone move to the item. The type and `[href…]` stay on
     * the anchor (the caller keeps a trailing dynamic state there too). Any
     * other attribute (not rendered on the anchor), pseudo-class,
     * pseudo-element, or namespace declines, and the authored selector is kept.
     * Identifiers are consumed through the CSS scanner, so an escape such as
     * `\26 ` keeps its whitespace terminator.
     *
     * @return array{structural: string, item: string, rest: string}|null
     */
    private function splitNavigationItemCompound(string $compound): ?array
    {
        $structural = '';
        $item = '';
        $rest = '';
        $length = strlen($compound);
        for ( $offset = 0; $offset < $length; ) {
            $character = $compound[$offset];
            if ( '.' === $character || '#' === $character ) {
                $end = $this->cssIdentifierEnd($compound, $offset + 1);
                if ( null === $end ) {
                    return null;
                }
                $item .= substr($compound, $offset, $end - $offset);
                $offset = $end;
                continue;
            }
            if ( '[' === $character ) {
                $close = CssSyntaxScanner::matchingClose($compound, $offset);
                if ( null === $close ) {
                    return null;
                }
                $end = $close + 1;
                $attribute = substr($compound, $offset, $end - $offset);
                $owner = $this->navigationAttributeOwner($attribute);
                if ( null === $owner ) {
                    return null;
                }
                if ( 'item' === $owner ) {
                    $item .= $attribute;
                } else {
                    $rest .= $attribute;
                }
                $offset = $end;
                continue;
            }
            if ( ':' === $character ) {
                if ( ':' === ($compound[$offset + 1] ?? '') ) {
                    return null;
                }
                $nameEnd = $this->cssIdentifierEnd($compound, $offset + 1);
                if ( null === $nameEnd ) {
                    return null;
                }
                $name = strtolower(substr($compound, $offset + 1, $nameEnd - $offset - 1));
                $argument = null;
                $end = $nameEnd;
                if ( '(' === ($compound[$nameEnd] ?? '') ) {
                    $close = CssSyntaxScanner::matchingClose($compound, $nameEnd);
                    if ( null === $close ) {
                        return null;
                    }
                    $end = $close + 1;
                    $argument = trim(substr($compound, $nameEnd + 1, $end - $nameEnd - 2));
                }
                $owner = $this->navigationPseudoClassOwner($name, $argument);
                if ( null === $owner ) {
                    return null;
                }
                $token = substr($compound, $offset, $end - $offset);
                if ( 'structural' === $owner ) {
                    $structural .= $token;
                } elseif ( 'item' === $owner ) {
                    $item .= $token;
                } else {
                    $rest .= $token;
                }
                $offset = $end;
                continue;
            }
            if ( '*' === $character && 0 === $offset ) {
                $rest .= '*';
                ++$offset;
                continue;
            }
            if ( 0 === $offset ) {
                $end = $this->cssIdentifierEnd($compound, 0);
                if ( null !== $end ) {
                    $rest .= substr($compound, 0, $end);
                    $offset = $end;
                    continue;
                }
            }
            return null;
        }

        return array( 'structural' => $structural, 'item' => $item, 'rest' => $rest );
    }

    /**
     * Offset after the CSS identifier starting at $offset, consuming escapes
     * through the scanner (a hex escape takes its whitespace terminator), or
     * null when no identifier starts there.
     */
    private function cssIdentifierEnd(string $value, int $offset): ?int
    {
        $length = strlen($value);
        $end = $offset;
        while ( $end < $length ) {
            $character = $value[$end];
            if ( '\\' === $character ) {
                $escapeEnd = CssSyntaxScanner::escapeEnd($value, $end);
                if ( null === $escapeEnd ) {
                    return null;
                }
                $end = $escapeEnd;
                continue;
            }
            if ( ctype_alnum($character) || '-' === $character || '_' === $character || ord($character) >= 0x80 ) {
                ++$end;
                continue;
            }
            break;
        }

        return $end > $offset ? $end : null;
    }

    /**
     * Which rendered element an attribute selector on a source navigation
     * anchor can still address: `class` and `id` land on the `<li>`, `href` is
     * rendered on the `<a>`; nothing else is retained.
     *
     * @return 'item'|'anchor'|null
     */
    private function navigationAttributeOwner(string $attribute): ?string
    {
        if ( ! preg_match('/^\[\s*((?:\\\\.|[A-Za-z0-9_-])+)\s*(?:[~|^$*]?=|\])/', $attribute, $match) ) {
            return null;
        }
        $name = strtolower(stripslashes($match[1]));
        if ( 'class' === $name || 'id' === $name ) {
            return 'item';
        }

        return 'href' === $name ? 'anchor' : null;
    }

    /**
     * @return 'structural'|'item'|null
     */
    private function navigationPseudoClassOwner(string $name, ?string $argument): ?string
    {
        if ( null === $argument ) {
            return 'first-child' === $name || 'last-child' === $name ? 'structural' : null;
        }
        if ( 'nth-child' === $name || 'nth-of-type' === $name ) {
            return null !== CssSelectorMatcher::parseNthExpression($argument) ? 'structural' : null;
        }
        if ( 'not' !== $name || '' === $argument ) {
            return null;
        }
        // A negation moves with its single kind of content; a mixed argument
        // could not be split without changing what it negates.
        $negated = $this->splitNavigationItemCompound($argument);
        if ( null === $negated || '' !== $negated['rest'] ) {
            return null;
        }
        if ( '' !== $negated['structural'] && '' === $negated['item'] ) {
            return 'structural';
        }

        return '' !== $negated['item'] && '' === $negated['structural'] ? 'item' : null;
    }

    /**
     * Whether every match plays the given AuthorSelectorProjectionState::NAVIGATION_*
     * role in the navigation block that replaced it.
     *
     * @param list<DOMElement> $matches
     */
    public function matchesOnlyNavigationRole(array $matches, string $role, AuthorStylesheetProjectionContext $context): bool
    {
        foreach ( $matches as $element ) {
            if ( ! $context->selectorProjections->isNavigationSourcePath($element->getNodePath() ?? '', $role) ) {
                return false;
            }
        }

        return array() !== $matches;
    }

    /**
     * A source list that is the element core/navigation stands in for renders
     * as `<nav class="wp-block-navigation [classes]" id="[id]">` with the same
     * classes and id copied onto the inner `<ul class="wp-block-navigation__container">`,
     * the row that holds the items. A rule keyed by class or id reaches both,
     * and the navigation container reset keeps the copy from being placed a
     * second time (position, offsets, transforms, margin, padding, border).
     * A rule qualified by the list type (`#header ul#nav{float:right;
     * width:360px;position:relative;top:20px;gap:20px}`) reached only the
     * inner copy: a flex item whose float is ignored and whose offsets are
     * reset, so the menu lost its place. Replace the type with core's block
     * class, which both elements carry, so the rule behaves exactly like its
     * class or id form: placement lands on the block once, and row layout
     * (gap, wrapping, alignment) still reaches the item row. The shim keeps
     * the type's specificity; classes, ids and pseudo-classes in the compound
     * stay where they are, so the usual class projection still applies.
     * Existing semantic identities restrict the rendered match set to these
     * source lists. A list moved into an overlay targets the inner UL only,
     * since the native root now occupies its opener's source-control slot.
     *
     * @param array<string, mixed> $parsed
     * @param list<DOMElement> $matches
     */
    private function projectNavigationListHostSelector(string $selector, array $parsed, AuthorStylesheetProjectionContext $context, array $matches): ?string
    {
        $span = $parsed['rightmost_compound_span'] ?? null;
        if ( ! is_array($span) ) {
            return null;
        }
        $start = (int) $span['start'];
        foreach ( $parsed['type_spans'] as $typeSpan ) {
            if ( (int) $typeSpan['start'] < $start ) {
                continue;
            }
            if ( ! in_array(strtolower((string) $typeSpan['name']), array( 'ul', 'ol' ), true) ) {
                return null;
            }
            // The source match set proves list ownership, not ownership of
            // every rendered navigation. Bind to those source identities so
            // a separate DIV opener cannot inherit a global UL box rule.
            $identities = array();
            foreach ($matches as $element) {
                $marker = $context->selectorProjections->semanticMarker($element->getNodePath() ?? '');
                if ('' === $marker) return null;
                $root = $context->selectorProjections->navigationListOwnsRoot($element->getNodePath() ?? '') ? '' : 'ul';
                $identities[$marker] = $root . '.wp-block-navigation.' . $marker;
            }
            if (array() === $identities) return null;
            return $this->rewriteSourceTagTypes($selector, $parsed, $context, '', array(
                (int) $typeSpan['start'] => array(
                    'end' => (int) $typeSpan['end'],
                    'value' => ':where(' . implode(',', $identities) . ')' . $this->typeSpecificityShim($context),
                ),
            ));
        }
        // Without a type the authored compound already reaches the block.
        return null;
    }

    /**
     * core/navigation-link and core/navigation-submenu render a source `<li>` as
     * `<li class="wp-block-navigation-item">`, carrying the source item's
     * classes and id but not the source-type marker other list items receive.
     * A rule authored on the item (`#menu li{display:inline;padding-right:15px}`)
     * therefore matched nothing in WordPress, and the menu lost the spacing the
     * item's own box provided. Move the subject onto the rendered item class;
     * classes, ids and structural pseudo-classes come along, the `li`
     * type keeps its specificity through the type shim, and a trailing dynamic
     * state stays on the item, which is the same element it described.
     *
     * @param array<string, mixed> $parsed
     */
    public function projectNavigationListItemSelector(string $selector, array $parsed, AuthorStylesheetProjectionContext $context): ?string
    {
        $span = $parsed['rightmost_compound_span'] ?? null;
        if ( ! is_array($span) ) {
            return null;
        }
        $start = (int) $span['start'];
        $end = (int) $span['end'];
        $bodyEnd = $end;
        $trailingState = '';
        $suffix = $parsed['pseudo_state_suffix_span'] ?? null;
        if ( is_array($suffix) ) {
            if ( (int) $suffix['end'] !== $end ) {
                return null;
            }
            $bodyEnd = (int) $suffix['start'];
            $trailingState = substr($selector, $bodyEnd, $end - $bodyEnd);
        }
        $typeLength = 0;
        foreach ( $parsed['type_spans'] as $typeSpan ) {
            if ( (int) $typeSpan['start'] >= $start ) {
                if ( 'li' !== strtolower((string) $typeSpan['name']) ) {
                    return null;
                }
                $typeLength = (int) $typeSpan['end'] - (int) $typeSpan['start'];
            }
        }
        $split = $this->splitNavigationItemCompound(substr($selector, $start, $bodyEnd - $start));
        if ( null === $split ) {
            return null;
        }
        $rest = $split['rest'];
        if ( $typeLength > 0 ) {
            $rest = substr($rest, $typeLength);
        } elseif ( str_starts_with($rest, '*') ) {
            $rest = substr($rest, 1);
        }
        // Anything the rendered item does not carry (an `[href]`-like attribute)
        // leaves the authored selector alone.
        if ( '' !== $rest ) {
            return null;
        }
        $baseline = '';
        $matches = $this->matchingSourceElements($selector, $parsed, $context);
        if (array() !== $matches && array() === array_filter($matches, static fn(DOMElement $element): bool => '' === $context->selectorProjections->navigationSubject($element->getNodePath() ?? ''))) {
            // The same native-subject baseline as the independent anchor/item
            // projections, while retaining live sibling predicates and source
            // ancestry. Core defaults must not outrank typed item declarations.
            // The native item contributes one class of the shared four-class
            // baseline; retain the other three without changing author order.
            $baseline = str_repeat(':not(.' . $context->authorStyles->classSpecificityShim() . ')', 3);
        }
        // The native item class is the rule's single native scope: Core styles
        // `.wp-block-navigation .wp-block-navigation-item` (display:flex), so an
        // authored item rule keeps its own weight plus one class, the same scope
        // WordPressCompatCss gives every other projected native navigation part.
        $subject = '.wp-block-navigation-item' . $split['item'] . $split['structural']
            . ( $typeLength > 0 ? $this->typeSpecificityShim($context) : '' )
            . $baseline
            . $trailingState;
        return $this->rewriteSourceTagTypes($selector, $parsed, $context, '', array(
            $start => array( 'end' => $end, 'value' => $subject ),
        ) + $this->navigationListHostParentReplacements($selector, $parsed, $context, $matches));
    }

    /**
     * A source list that is itself the navigation (`div#menu > ul > li`, with
     * the UL lowered to core/navigation) renders as two boxes: the native
     * `<nav>` root that carries the list's identity, and core's inner
     * `ul.wp-block-navigation__container` that holds the items. An item rule
     * whose parent compound is that list (`#menu > ul > li`) therefore has to
     * step through both: the list compound addresses the root through the
     * list's semantic identity, and a descendant hop through Core's responsive
     * wrappers reaches the container.
     * Otherwise `#menu > ul` looks for a UL child of `#menu` that no longer
     * exists, and the item loses its authored box (padding, height, display).
     * When the carrier owns the root instead, the source list maps directly
     * to the inner container, with a descendant hop through Core's wrappers.
     * Mixed root ownership is left alone.
     *
     * @param array<string, mixed> $parsed
     * @param list<DOMElement> $matches
     * @return array<int, array{end: int, value: string}>
     */
    private function navigationListHostParentReplacements(string $selector, array $parsed, AuthorStylesheetProjectionContext $context, array $matches): array
    {
        $compounds = $parsed['compounds'] ?? array();
        $combinators = $parsed['combinators'] ?? array();
        $parentIndex = count($compounds) - 2;
        if ( $parentIndex < 0 || '>' !== ($combinators[$parentIndex] ?? null) || array() === $matches ) {
            return array();
        }
        $type = strtolower((string) ($compounds[$parentIndex]['type'] ?? ''));
        if ( '' !== $type && ! in_array($type, array( 'ul', 'ol' ), true) ) {
            return array();
        }
        $identities = array();
        $ownsRoot = null;
        foreach ( $matches as $item ) {
            $list = $item->parentNode;
            if ( ! $list instanceof DOMElement ) {
                return array();
            }
            $path = $list->getNodePath() ?? '';
            $listOwnsRoot = $context->selectorProjections->navigationListOwnsRoot($path);
            if ( null !== $ownsRoot && $ownsRoot !== $listOwnsRoot ) {
                return array();
            }
            $ownsRoot = $listOwnsRoot;
            if ( ! $ownsRoot ) {
                if ( $parentIndex < 1 || '>' !== ($combinators[$parentIndex - 1] ?? null)
                    || ! $context->selectorProjections->isNavigationSourcePath($item->getNodePath() ?? '', AuthorSelectorProjectionState::NAVIGATION_ITEM) ) {
                    return array();
                }
                // Submenu lists live inside an item, not in the root container.
                for ( $ancestor = $list->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode ) {
                    if ( $context->selectorProjections->isNavigationSourcePath($ancestor->getNodePath() ?? '', AuthorSelectorProjectionState::NAVIGATION_ITEM) ) {
                        return array();
                    }
                }
                continue;
            }
            if ( '' === $type ) {
                continue;
            }
            $marker = $context->selectorProjections->semanticMarker($path);
            if ( '' === $marker ) {
                return array();
            }
            $identities[$marker] = '.wp-block-navigation.' . $marker;
        }
        if ( false === $ownsRoot ) {
            // The carrier, rather than this list, owns the navigation root.
            // Core inserts responsive wrappers before the item container: relax
            // the hop into the list, but keep its direct-child hop to the item.
            foreach ( $parsed['type_spans'] as $typeSpan ) {
                if ( $parentIndex !== ($typeSpan['compound'] ?? null) ) {
                    continue;
                }
                $listStart = (int) $typeSpan['start'];
                $replacements = array(
                    $listStart => array(
                        'end' => (int) $typeSpan['end'],
                        'value' => ':where(.wp-block-navigation__container)' . $this->typeSpecificityShim($context),
                    ),
                );
                if ( $parentIndex > 0 && '>' === ($combinators[$parentIndex - 1] ?? null) ) {
                    $hop = $listStart;
                    while ( $hop > 0 && ctype_space($selector[$hop - 1]) ) --$hop;
                    if ( $hop < 1 || '>' !== $selector[$hop - 1] ) return array();
                    $replacements[$hop - 1] = array( 'end' => $hop, 'value' => ' ' );
                }
                return $replacements;
            }
            return array();
        }
        // The parent compound ends where the `>` before the item begins.
        $parentEnd = (int) $parsed['rightmost_compound_span']['start'];
        while ( $parentEnd > 0 && ctype_space($selector[$parentEnd - 1]) ) --$parentEnd;
        if ( $parentEnd < 1 || '>' !== $selector[$parentEnd - 1] ) {
            return array();
        }
        --$parentEnd;
        while ( $parentEnd > 0 && ctype_space($selector[$parentEnd - 1]) ) --$parentEnd;
        $replacements = array(
            $parentEnd => array( 'end' => $parentEnd, 'value' => ' :where(.wp-block-navigation__container)' ),
        );
        if ( '' !== $type ) {
            foreach ( $parsed['type_spans'] as $typeSpan ) {
                if ( $parentIndex === ($typeSpan['compound'] ?? null) ) {
                    $replacements[(int) $typeSpan['start']] = array(
                        'end' => (int) $typeSpan['end'],
                        'value' => ':where(' . implode(',', $identities) . ')' . $this->typeSpecificityShim($context),
                    );
                }
            }
            if ( count($replacements) < 2 ) {
                return array();
            }
        }
        return $replacements;
    }

    /** @param array<string, mixed> $parsed */
    public function hasUniversalStructuralLeaf(array $parsed): bool
    {
        $rightmost = CssSelectorCompoundInspector::rightmost($parsed['compounds']);
        return null === ($rightmost['type'] ?? null)
            && array() === ($rightmost['classes'] ?? array())
            && array() === ($rightmost['ids'] ?? array())
            && array() === ($rightmost['attributes'] ?? array())
            && array() === ($rightmost['not'] ?? array())
            && array() === ($rightmost['has'] ?? array())
            && ( null !== ($rightmost['nth_child'] ?? null) || ($rightmost['first_child'] ?? false) || ($rightmost['last_child'] ?? false) );
    }

    /**
     * The selector's subject (its last compound) is addressed by class alone.
     * Ancestor compounds such as a responsive variant scope do not change what
     * the rule sizes, so the subject's class still identifies the element.
     *
     * @param array<string, mixed> $parsed
     */
    private function hasClassBoundSubject(array $parsed): bool
    {
        $compounds = $parsed['compounds'] ?? array();
        if ( array() === $compounds ) {
            return false;
        }

        return $this->isClassBoundSelector(array( 'compounds' => array( $compounds[array_key_last($compounds)] ) ));
    }

    /** @param array<string, mixed> $parsed */
    private function isClassBoundSelector(array $parsed): bool
    {
        $compounds = $parsed['compounds'] ?? array();
        if ( 1 !== count($compounds) ) {
            return false;
        }
        $compound = $compounds[0];

        return array() !== ($compound['classes'] ?? array())
            && null === ($compound['type'] ?? null)
            && array() === ($compound['ids'] ?? array())
            && array() === ($compound['attributes'] ?? array());
    }

    /** @param array<int, array{end: int, value: string}> $replacements */
    private function replaceSelectorSpans(string $selector, array $replacements): string
    {
        ksort($replacements, SORT_NUMERIC);
        $output = '';
        $offset = 0;
        foreach ( $replacements as $start => $replacement ) {
            $output .= substr($selector, $offset, $start - $offset) . $replacement['value'];
            $offset = $replacement['end'];
        }
        return $output . substr($selector, $offset);
    }

    /**
     * Whether a typed selector whose subject is this source item has at least
     * the given selector's source specificity. Typed item subjects keep the
     * structural projection, so an untyped rival must share that tier for the
     * source specificity and order to keep arbitrating between them.
     *
     * @param array<string, mixed> $parsed
     */
    private function typedItemRuleOutranks(DOMElement $element, array $parsed, AuthorStylesheetProjectionContext $context): bool
    {
        $own = array_values(CssSelectorMatcher::specificityCounts($parsed));
        foreach ($context->authorStyles->typeSubjectSelectors() as $typed) {
            if ($typed['weight'] >= $own && CssSelectorMatcher::matches($element, $typed['parsed'])['matches']) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $parsed @return list<DOMElement> */
    public function matchingSourceElements(string $selector, array $parsed, AuthorStylesheetProjectionContext $context): array
    {
        return $this->semanticPreparer->matchingSourceElements($context->authorStyles, $selector, $parsed);
    }
}
