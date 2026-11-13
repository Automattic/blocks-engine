<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorCompoundInspector;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;
use Automattic\BlocksEngine\PhpTransformer\Css\CssIdent;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorListCompactor;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorTokenizer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSyntaxScanner;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSpecificityProjection;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ImageLowering;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\WordPressCommentForm;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextMarkerSelector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\AddressableInlineLayoutLeaf;
use Automattic\BlocksEngine\PhpTransformer\Support\ShellLandmarkPolicy;
use DOMElement;

/** Projects authored selectors and declarations onto canonical block markup. */
final class AuthorStylesheetProjector
{
    public const INLINE_LAYOUT_CARRIER_CLASS = 'blocks-engine-inline-layout-carrier';

    /**
     * Class no emitted element carries. A type selector whose only source
     * subjects were menu toggles dropped for Core's native overlay control is
     * bound to it, so the rule stays readable in the projected stylesheet but
     * can no longer reach the open/close buttons Core renders in their place.
     */
    public const SUPERSEDED_MENU_TOGGLE_CLASS = 'blocks-engine-superseded-menu-toggle';

    /** Properties that decide whether the source drew a space between inline menu items. */
    private const NAVIGATION_ITEM_SPACE_PROPERTIES = array( 'display', 'float', 'position', 'font', 'font-family', 'font-size', 'font-style', 'font-weight', 'letter-spacing', 'word-spacing', 'white-space', 'white-space-collapse' );

    /**
     * Core's replacement for a dropped menu toggle, excluded from a type rule
     * the toggle shared with source elements that survive conversion.
     */
    private const NAVIGATION_TOGGLE_CHROME_EXCLUSION = ':not(:where(.wp-block-navigation__responsive-container-open,.wp-block-navigation__responsive-container-open *,.wp-block-navigation__responsive-container-close,.wp-block-navigation__responsive-container-close *))';

    /**
     * State pseudo-classes a static source match cannot evaluate. They do not
     * change which element a compound names, only when it applies.
     */
    private const STATE_PSEUDO_CLASS_PATTERN = '/:(?:hover|focus-visible|focus-within|focus|active|visited|any-link|link|disabled|enabled|target)(?![\w(-])/i';

    /** Whether the rule being projected restated `box-sizing:content-box`. */
    private bool $contentBoxRestated = false;

    /** Authored cascade position of the rule that restated `box-sizing:content-box`. */
    private ?int $contentBoxOrder = null;

    /** @var list<array{selector: string, conditions: list<string>, value: string, order: int}> */
    private array $contentBoxOverrides = array();

    private readonly ButtonDeclarationRouting $buttonDeclarationRouting;

    public function __construct(
        private readonly StyleResolver $styleResolver,
        private readonly AuthorSelectorSemanticPreparer $semanticPreparer,
        private readonly AuthorStyleRuleProjector $ruleBodyProjector,
        private readonly SourceStyleResolutionState $sourceStyles
    ) {
        $this->buttonDeclarationRouting = new ButtonDeclarationRouting($styleResolver, $semanticPreparer);
    }

    /**
     * @param list<string> $outerConditions Conditions that scope the whole
     *     stylesheet from outside its text, such as a link's media attribute.
     *     They reach only the rule-body projections that compare against the
     *     analyzed source cascade, which already reads the stylesheet under them.
     */
    public function project(string $stylesheet, AuthorStylesheetProjectionContext $context, array $outerConditions = array()): string
    {
        $bridges = array();
        $clips = \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SvgDefinitionClips::replacements($context->authorStyles->sourceBody());
        $projected = ( new CssStylesheetTransformer() )->transformStyleRules(
            $stylesheet,
            function (string $prelude, string $body, array $ancestors = array()) use ($context, $outerConditions, $clips, &$bridges): string {
                $css = $this->projectStyleRule($prelude, \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SvgDefinitionClips::rewrite($body, $clips), $context, self::ancestorsAreConditional($ancestors), $ancestors, $outerConditions);
                $bridge = $this->projectedLayerBridge($prelude, $css, $ancestors, $context);
                if ( '' !== $bridge ) $bridges[$bridge] = true;
                return $css;
            }
        );
        $projected .= implode('', array_keys($bridges));
        if ( str_contains($context->emittedMarkup, ImageLowering::BACKGROUND_IMAGE_CLASS) ) {
            // A materialized background is paint, not another author subject.
            // Exclude both its synthetic wrapper and leaf without changing the
            // author's specificity or resetting legitimate inherited values.
            // Those elements are a figure and an img carrying only engine
            // classes, so only a subject compound typed for them (or untyped)
            // and without a class or id hook can reach them.
            $projected = (new CssStylesheetTransformer())->transformStyleRules($projected, static function (string $prelude, string $body): string {
                $arms = CssStylesheetTransformer::splitSelectorList($prelude);
                if ( null === $arms ) return $prelude . '{' . $body . '}';
                $class = '.' . ImageLowering::BACKGROUND_IMAGE_CLASS;
                $guard = ':where(:not(' . $class . ',' . $class . ' *))';
                foreach ( $arms as &$arm ) {
                    $tokens = CssSelectorTokenizer::tokenize($arm);
                    if ( ! $tokens['supported'] ) continue;
                    $span = $tokens['compound_spans'][count($tokens['compound_spans']) - 1];
                    $insert = $span['end'];
                    $hooked = 1 === preg_match('/\G(?:[a-z][\w-]*\|)?([a-z][\w-]*)/i', $arm, $type, 0, $span['start'])
                        && ! in_array(strtolower($type[1]), array( 'img', 'figure' ), true);
                    $state = CssSyntaxScanner::state();
                    for ( $offset = $span['start']; ! $hooked && $offset < $span['end']; ) {
                        $topLevel = CssSyntaxScanner::isTopLevel($state);
                        $next = CssSyntaxScanner::consume($arm, $offset, $state);
                        if ( null === $next ) break;
                        if ( $topLevel && ('.' === $arm[$offset] || '#' === $arm[$offset] || preg_match('/\G(?::(?:where|is)\([.#][\w-]+(?:\s*,\s*[.#][\w-]+)*\)|\[(?!(?:src|srcset|alt|class|style|sizes|width|height|loading|decoding)\b))/i', $arm, $_, 0, $offset)) ) {
                            $hooked = true;
                            break;
                        }
                        if ( $topLevel && ':' === $arm[$offset]
                            && (':' === ($arm[$offset + 1] ?? '') || preg_match('/\G:(?:before|after|first-line|first-letter)(?![\w-])/i', $arm, $_, 0, $offset)) ) {
                            $insert = $offset;
                            break;
                        }
                        $offset = $next;
                    }
                    if ( ! $hooked ) $arm = substr($arm, 0, $insert) . $guard . substr($arm, $insert);
                }
                unset($arm);
                return implode(',', $arms) . '{' . $body . '}';
            });
        }
        // Engine class tokens must not satisfy an authored class substring.
        $projected = $context->authorStyles->classAttributeSelectors()->stylesheet($projected);
        $ids = ScopedAnchorSelectorProjection::identities($context->authorStyles);
        $projected = EngineMarker::compactDeviceScope($projected);
        if ( array() !== $ids ) {
            $hasNamespace = (bool) preg_match('/@namespace\b/i', $projected);
            return (new CssStylesheetTransformer())->transformStyleRules($projected, static function (string $selector, string $body) use ($ids, $hasNamespace): string {
                $selector = ScopedAnchorSelectorProjection::selector($selector, $ids);
                return ($hasNamespace ? $selector : CssSelectorListCompactor::compact($selector)) . '{' . $body . '}';
            });
        }
        return CssSelectorListCompactor::stylesheet($projected);
    }

    /**
     * Roll Core's unlayered defaults back to the original author cascade, not
     * to a copied value. Layer order, important declarations and state/media
     * changes therefore remain owned by the source stylesheet.
     *
     * @param list<string> $ancestors
     */
    private function projectedLayerBridge(string $prelude, string $css, array $ancestors, AuthorStylesheetProjectionContext $context): string
    {
        if ( ! array_filter($ancestors, static fn (string $condition): bool => 1 === preg_match('/^@layer\\b/i', trim($condition))) ) return '';
        $subjects = array();
        foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector ) {
            // Match potential state subjects; the emitted selector retains the
            // state itself, so the bridge cannot activate it at rest.
            $selector = preg_replace(self::STATE_PSEUDO_CLASS_PATTERN, '', $selector) ?? $selector;
            foreach ( $this->matchingSourceElements($selector, $context->sourceStyles->parsedSelector($selector), $context) as $element ) {
                $subjects[spl_object_id($element)] = $element;
            }
        }
        if ( array() === $subjects ) return '';
        $rules = '';
        (new CssStylesheetTransformer())->visitStyleRules($css, function (string $selector, string $body) use (&$rules, $subjects): void {
            // Only canonical block targets or source-instance projections own
            // this escape. Never install a document-wide author reset.
            if ( ! preg_match('/\\.(?:wp-block-|blocks-engine-)/', $selector) ) return;
            $properties = array();
            foreach ( SourceDeclarations::verbatim($body) as $property => $value ) {
                if ( str_starts_with($property, '--') || str_contains($value, '!important') ) continue;
                $family = explode('-', $property)[0];
                // Shorthands and their longhands must veto each other. Custom
                // properties stay in their source origin and need no bridge.
                $pattern = '/^' . preg_quote($family, '/') . '(?:-.*)?$/';
                $layered = true;
                foreach ( $subjects as $element ) {
                    $ownership = $this->styleResolver->authorPropertyFamilyLayering($element, array($property => $pattern), true);
                    if ( ! ($ownership[$property] ?? false) ) $layered = false;
                }
                if ( $layered ) $properties[] = $property . ':revert-layer';
            }
            if ( array() !== $properties ) {
                $rules .= ':root:root:root :is(' . $selector . '){' . implode(';', $properties) . '}';
            }
        });
        foreach ( array_reverse($ancestors) as $condition ) {
            if ( ! preg_match('/^@layer\\b/i', trim($condition)) && '' !== $rules ) $rules = $condition . '{' . $rules . '}';
        }
        return $rules;
    }

    /**
     * Core's unlayered element-button defaults otherwise hide layered source
     * paint, including hover. Keep the escape in support CSS, outside @layer,
     * and never promote paint to native button attributes/inline styles.
     */
    public function controlCarrierLayerRevertRule(DOMElement $element, string $marker): string
    {
        $families = array(
            'background-color' => '/^background(?:-.*)?$/',
            'color' => '/^color$/',
            'padding' => '/^padding(?:-.*)?$/',
            'border-width' => '/^border(?:-(?:width|top|right|bottom|left|block|inline)(?:-(?:start|end))?(?:-width)?)?$/',
            'font-size' => '/^font(?:-size)?$/',
            'font-family' => '/^font(?:-family)?$/',
            'font-style' => '/^font(?:-style)?$/',
            'font-weight' => '/^font(?:-weight)?$/',
            'letter-spacing' => '/^letter-spacing$/',
            'text-transform' => '/^text-transform$/',
            'line-height' => '/^(?:font|line-height)$/',
            'text-decoration' => '/^text-decoration(?:-.*)?$/',
        );
        $reverted = array_keys(array_filter($this->styleResolver->authorPropertyFamilyLayering($element, $families)));
        if ( array() === $reverted ) {
            return '';
        }

        return ':root .' . $marker . '>.wp-block-button__link{' . implode(';', array_map(static fn (string $property): string => $property . ':revert-layer', $reverted)) . '}';
    }

    /** @param list<string> $ancestors */
    private static function ancestorsAreConditional(array $ancestors): bool
    {
        foreach ( $ancestors as $ancestor ) {
            if ( 1 === preg_match('/^@(?:media|supports)\b/i', trim((string) $ancestor) ) ) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $ancestors @param list<string> $outerConditions */
    private function projectStyleRule(string $prelude, string $body, AuthorStylesheetProjectionContext $context, bool $inConditional = false, array $ancestors = array(), array $outerConditions = array()): string
    {
        if ( ! $context->nativeDocumentContext ) {
            // A standalone block fragment cannot restore source body classes.
            // Do not activate a source-false negative gate on its host body.
            // Native site plans must retain these selectors: route metadata
            // (including an authoritative empty body) owns their truth there.
            $selectors = CssStylesheetTransformer::splitSelectorList($prelude) ?? array($prelude);
            $classes = SourceDom::tokens($context->authorStyles->sourceBody()->getAttribute('class'));
            $selectors = array_filter($selectors, static fn (string $selector): bool =>
                !preg_match('/^body:not\(\.([A-Za-z0-9_-]+)\)(?=\s|>|\+|~)/', trim($selector), $predicate)
                || !in_array($predicate[1], $classes, true));
            if ( array() === $selectors ) return '';
            $prelude = implode(',', $selectors);
        }
        if ( $context->onlyMatchedRules ) {
            $prelude = $this->matchedSelectorPrelude($prelude, $context);
            if ( '' === $prelude ) {
                return '';
            }
        }
        // These subjects are not lowered source elements. Core renders them
        // afresh, without our source-tag, class or attribute markers. Keep the
        // authored rule (including its declaration body and conditional context)
        // rather than binding it to markers absent from the runtime form.
        $runtime = array();
        $lowered = array();
        foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array($prelude) as $selector ) {
            if ( $this->targetsRuntimeCommentForm($selector, $context) ) {
                $runtime[] = $selector;
            } else {
                $lowered[] = $selector;
            }
        }
        if ( array() !== $runtime ) {
            return implode(',', $runtime) . '{' . $body . '}'
                . (array() === $lowered ? '' : $this->projectStyleRule(implode(',', $lowered), $body, $context, $inConditional, $ancestors, $outerConditions));
        }
        $body = $this->withoutConsumedCoverBackgroundImages($prelude, $body, $context);
        $lifted = ColorSchemeVariant::liftPrelude($prelude);
        $this->contentBoxRestated = false;
        $this->contentBoxOrder = null;
        $this->contentBoxOverrides = array();
        $css = $this->emitProjectedStyleRule($lifted['prelude'], $body, $context, $inConditional, array( ...$outerConditions, ...$ancestors ));
        $css = ColorSchemeVariant::wrap($css, $lifted['scheme'], $ancestors);
        if ( ! $this->contentBoxRestated ) {
            return $css;
        }

        return $this->moveContentBoxRestatements($css, $context, array( ...$outerConditions, ...$ancestors ));
    }

    /**
     * A core/cover that consumed its source's stylesheet background owns that
     * image as an editable attachment. When every subject of a rule is such a
     * cover, its background image layers become `none`: the value stays valid,
     * its other layers and declarations keep their cascade, and the theme no
     * longer ships images nothing displays. Pseudo-element, unmatched and
     * mixed-subject rules keep their images.
     */
    private function withoutConsumedCoverBackgroundImages(string $prelude, string $body, AuthorStylesheetProjectionContext $context): string
    {
        if ( ! preg_match('/\bbackground(?:-image)?\s*:[^;}]*url\(/i', $body) ) {
            return $body;
        }
        foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector ) {
            if ( str_contains($selector, '::') || preg_match('/:(?:before|after|first-line|first-letter)\b/i', $selector) ) {
                return $body;
            }
            $selector = preg_replace(self::STATE_PSEUDO_CLASS_PATTERN, '', $selector) ?? $selector;
            $subjects = $this->matchingSourceElements($selector, $context->sourceStyles->parsedSelector($selector), $context);
            if ( array() === $subjects ) {
                return $body;
            }
            foreach ( $subjects as $element ) {
                if ( ! $context->selectorProjections->consumedCoverBackground($element->getNodePath() ?? '') ) {
                    return $body;
                }
            }
        }
        foreach ( SourceDeclarations::verbatim($body) as $property => $value ) {
            if ( 'background' !== $property && 'background-image' !== $property ) {
                continue;
            }
            $pruned = preg_replace('/-webkit-image-set\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)|image-set\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)/i', 'none', $value) ?? $value;
            $pruned = preg_replace('/url\(\s*(?:"[^"]*"|\'[^\']*\'|[^)]*)\s*\)/i', 'none', $pruned) ?? $pruned;
            if ( $pruned !== $value ) {
                $body = str_replace($value, $pruned, $body);
            }
        }

        return $body;
    }

    private function targetsRuntimeCommentForm(string $selector, AuthorStylesheetProjectionContext $context): bool
    {
        // Require the runtime block's identity and a non-Core scope hook. A
        // bare Core selector must not become a site-wide WordPress override.
        if ( ! preg_match('/\.wp-block-post-comments-form(?![\w-])/', $selector)
            || ! (new CoreSelectorScoping())->selectorIsScoped($selector) ) {
            return false;
        }
        $host = CssSelectorMatcher::pseudoElementHost($selector);
        $parsed = $host['parsed'] ?? $context->sourceStyles->parsedSelector($selector);
        if ( ! ($parsed['supported'] ?? false) ) return false;
        $matches = $this->matchingSourceElements($host['selector'] ?? $selector, $parsed, $context);
        if ( array() === $matches ) return false;
        foreach ( $matches as $element ) {
            for ( $ancestor = $element; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode ) {
                if ( WordPressCommentForm::matches($ancestor) ) continue 2;
            }
            return false;
        }
        return true;
    }

    /**
     * The selectors of `$prelude` that may reach an element of this document.
     * A selector the matcher cannot evaluate is kept: it may.
     */
    private function matchedSelectorPrelude(string $prelude, AuthorStylesheetProjectionContext $context): string
    {
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors ) {
            return $prelude;
        }
        $matched = array_filter($selectors, function (string $selector) use ($context): bool {
            $parsed = $context->sourceStyles->parsedSelector($selector);
            return ! ($parsed['supported'] ?? false) || array() !== $this->matchingSourceElements($selector, $parsed, $context);
        });
        return count($matched) === count($selectors) ? $prelude : implode(',', $matched);
    }

    /**
     * A `box-sizing:content-box` restatement is support CSS, so it moves out of
     * the projected author rule into the after-author support stylesheet,
     * keeping the projected selector and conditions. Source rules it must not
     * override are restated there too, in authored order ({@see ContentBoxRestatements}).
     *
     * @param list<string> $conditions At-rules `$css` sits inside, outermost first.
     */
    private function moveContentBoxRestatements(string $css, AuthorStylesheetProjectionContext $context, array $conditions): string
    {
        $restatements = $context->contentBoxRestatements;
        $css = $restatements->extract($css, $conditions, $this->contentBoxOrder);
        // Re-projecting a source rule here must not record its selector a second time.
        $overrideContext = new AuthorStylesheetProjectionContext(
            $context->authorStyles,
            $context->sourceStyles,
            $context->selectorProjections,
            $context->evidence,
            $context->keepAuthorClassSelectors,
            new ProjectedSelectorBindings(),
            $context->emittedMarkup
        );
        foreach ( $this->contentBoxOverrides as $override ) {
            $inConditional = self::ancestorsAreConditional($override['conditions']);
            $restatements->addOverride(
                $this->rewriteStyleRule($override['selector'], 'box-sizing:' . $override['value'], $overrideContext, $inConditional),
                $override['conditions'],
                $override['order']
            );
        }

        return $css;
    }

    /** @param list<string> $conditions At-rules the rule sits inside, outermost first. */
    private function emitProjectedStyleRule(string $prelude, string $body, AuthorStylesheetProjectionContext $context, bool $inConditional = false, array $conditions = array()): string
    {
        $parts = str_contains($body, '{') ? (new CssStylesheetTransformer())->splitStyleRuleBody($body) : array();
        $hasNestedRules = array_filter($parts, static fn (array $part): bool => isset($part['prelude']));
        $hasButtonSubjects = (bool) preg_match('/(?:^|[;{])\s*(?:align-self|justify-self|place-self)\s*:/i', $body)
            && array() !== $this->buttonDeclarationRouting->buttonSizeSubjects($prelude, $context);
        // Body projection and button declaration routing read a flat
        // declaration list split at `;`. A nested rule's own `;` would cut its
        // block mid-way (e.g. lifting `transform` out of it onto a wrapper
        // rule) and leave stray `}` that truncate the stylesheet for every
        // later reader. Project each declaration run and nested rule apart.
        if ( $hasNestedRules ) {
            $css = '';
            foreach ( $parts as $part ) {
                if ( isset($part['declarations']) ) {
                    // Splitting only contiguous runs retains declarations after
                    // a nested condition at their original cascade position.
                    $css .= $this->emitProjectedStyleRule($prelude, $part['declarations'], $context, $inConditional, $conditions);
                } elseif ( in_array($part['at_rule'], array('media', 'supports'), true) ) {
                    $css .= $part['prelude'] . '{' . $this->emitProjectedStyleRule($prelude, $part['body'], $context, true, array( ...$conditions, trim((string) $part['prelude']) )) . '}';
                } else {
                    // Relative selectors and scoped/layer rules retain their
                    // original host. Only media/supports conditions can lift.
                    $nested = '' === $part['at_rule']
                        ? $this->emitProjectedStyleRule($part['prelude'], $part['body'], $context, $inConditional, $conditions)
                        : $part['prelude'] . '{' . $part['body'] . '}';
                    $css .= $this->rewriteSelectorPrelude($prelude, $context) . '{' . $nested . '}';
                }
            }
            return $css;
        }
        $projection = $this->ruleBodyProjector->projectWithDeclarations(
            $prelude,
            $body,
            $context->authorStyles,
            $context->sourceStyles,
            $context->evidence,
            $conditions
        );
        $body = $projection['body'];
        $declarations = $projection['declarations'];
        if ( $projection['content_box'] ) {
            $this->contentBoxRestated = true;
            $this->contentBoxOrder ??= $projection['content_box_order'];
            array_push($this->contentBoxOverrides, ...$projection['content_box_overrides']);
        }
        // Margins and self-alignment position the outer box in its parent.
        // Keep them out of surface projection, including attribute-ancestry arms.
        $margins = array_filter($declarations, static fn (string $name): bool => 'margin' === $name || str_starts_with($name, 'margin-') || ($hasButtonSubjects && in_array($name, array('align-self', 'justify-self', 'place-self'), true)), ARRAY_FILTER_USE_KEY);
        $mediaTextImagePrelude = $this->projectMediaTextImagePrelude($prelude, $context);
        if ( '' !== $mediaTextImagePrelude ) {
            return $mediaTextImagePrelude . '{' . $body . '}';
        }
        $imagePrelude = $this->projectAuthorImageSelectorPrelude($prelude, $context);
        $imagePlacement = $this->imageLeafPlacementProjection($prelude, $body, $declarations, $imagePrelude, $context);
        $body = $imagePlacement['body'];
        if ( '' !== $imagePlacement['rules'] ) {
            $declarations = array_filter($declarations, static fn (string $property): bool => ! NativeImageLeafPresentation::isPlacementProperty($property), ARRAY_FILTER_USE_KEY);
            // Placement has already been emitted on the image leaf, with the
            // synthetic figure excluded. Do not replay fixed margins on the
            // wrapper as well. Horizontal auto margins still center a sized
            // figure (the leaf cannot position its parent in normal flow).
            $margins = array_intersect_key($margins, $declarations) + array_filter($margins, static fn (string $value, string $property): bool =>
                in_array($property, array('margin-left', 'margin-right', 'margin-inline', 'margin-inline-start', 'margin-inline-end'), true)
                && 'auto' === trim(str_replace('!important', '', $value)), ARRAY_FILTER_USE_BOTH);
        }
        $svgImagePrelude = $this->projectAuthorImageSelectorPrelude($prelude, $context, 'svg', $declarations);
        $imageRule = '' === $imagePrelude
            ? ''
            : $this->imageProjectionBridgeRule($prelude, $imagePrelude, $declarations, $context);
        if ('' !== $imagePrelude) {
            // The generic Image bridge introduces block display and responsive
            // shrinkage. An inline source image in an auto table must instead
            // participate in the cell's line box and intrinsic track sizing.
            // Carry authored leaf declarations at their original rule position.
            $inlineSelectors = array_map(
                static fn (string $selector): string => ':where(.blocks-engine-layout-table-cell) ' . str_replace('.wp-block-image', '.wp-block-image:where(.blocks-engine-synthetic-image-figure-inline)', $selector),
                CssValueSplitter::splitTopLevel($imagePrelude, array(','))
            );
            $imageRule .= implode(',', $inlineSelectors) . '{display:' . ($declarations['display'] ?? 'inline')
                . ';max-width:' . ($declarations['max-width'] ?? 'none') . ';vertical-align:' . ($declarations['vertical-align'] ?? 'baseline') . '}';
            $imageRule .= $this->lineImageFigureRule($imagePrelude, $declarations, $context);
            // Any inline-flow image aligns on its line as the source declared,
            // not at the bottom where core's Image rule would put it.
            if ( isset($declarations['vertical-align']) ) {
                $imageRule .= str_replace('.wp-block-image', '.wp-block-image:where(.' . SourceBlockAttributeProjector::SYNTHETIC_INLINE_IMAGE_FIGURE_CLASS . ')', $imagePrelude)
                    . '{vertical-align:' . $declarations['vertical-align'] . '}';
            }
            $imageRule .= $this->flexItemImageBoxRule($prelude, $imagePrelude, $declarations, $context);
        }
        // Keep the normal bridge defaults, then the authored replaced-box
        // declarations at this rule's cascade position. Do not promote them
        // above SvgMaterializer's inline-geometry carrier or replay SVG paint.
        $svgImageRule = '' === $svgImagePrelude
            ? ''
            : $svgImagePrelude . '{' . $this->imageProjectionBridgeDeclarations($declarations, true) . ';' . $this->styleResolver->cssDeclarationString($this->imageApplicableSvgDeclarations($declarations)) . '}'
                . $this->lineImageFigureRule($svgImagePrelude, $declarations, $context);
        $svgImageRule .= $this->flexItemImageBoxRule(
            $prelude,
            $this->projectAuthorImageSelectorPrelude($prelude, $context, 'svg', $declarations, true),
            $declarations,
            $context
        );
        $editorDocumentRootRule = $this->editorDocumentRootRule($prelude, $body, $context) . $this->separatorBorderRule($prelude, $declarations, $context);
        $navigationItemSpaceRule = $this->navigationItemSpaceRule($prelude, $declarations, $context)
            . $this->navigationListBoxRule($prelude, $declarations, $context)
            . $this->directNavigationHostRule($prelude, $declarations, $context)
            . $this->navigationItemFlexItemRule($prelude, $declarations, $context)
            . $this->syntheticParagraphItemRule($prelude, $declarations, $context)
            . $this->navigationToggleRowRule($prelude, $declarations, $context);
        if ( array() === $margins ) {
            $css = $this->rewriteStyleRule($prelude, $body, $context, $inConditional) . $imageRule . $imagePlacement['rules'] . $svgImageRule . $editorDocumentRootRule . $navigationItemSpaceRule;

            return $this->withEditorProjectionRules($css, $prelude, $body, $context);
        }

        $inner = array_diff_key($declarations, $margins);
        $rules = '' === $this->styleResolver->cssDeclarationString($inner)
            ? ''
            : $this->rewriteStyleRule($prelude, $this->styleResolver->cssDeclarationString($inner), $context, $inConditional);
        $carrierReset = $this->buttonCarrierMarginReset($this->styleResolver->cssDeclarationString($margins));
        $carrierPrelude = '' === $carrierReset ? '' : $this->buttonCarrierResetPrelude($prelude, $context);
        if ( '' !== $carrierPrelude ) {
            $rules .= $carrierPrelude . '{' . $carrierReset . '}';
        }
        $rules .= $this->tableVerticalMarginRules($prelude, $margins, $context);
        $css = $rules
            . $this->marginSelectorPrelude($prelude, $context) . '{' . $this->styleResolver->cssDeclarationString($margins) . '}'
            . $imageRule
            . $imagePlacement['rules']
            . $svgImageRule
            . $editorDocumentRootRule
            . $navigationItemSpaceRule;

        return $this->withEditorProjectionRules($css, $prelude, $body, $context);
    }

    /**
     * Project host display here, never in an after-author reference-value replay.
     * The host's cross-axis alignment rides along: the list-navigation baseline
     * outranks a bare class, and Core's toggle is a direct child of the host.
     */
    private function directNavigationHostRule(string $prelude, array $declarations, AuthorStylesheetProjectionContext $context): string
    {
        $lifted = array_intersect_key($declarations, array('display' => true, 'align-items' => true));
        if (array() === $lifted) return '';
        $targets = array();
        foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
            // Class compounds only (`.menus`, `.menus.res`): every lifted rule
            // gains the same prefix, so their relative specificity holds.
            if (!preg_match('/^(?:\.(?:\\\\.|[A-Za-z0-9_-])+)+$/', trim($selector), $match)) continue;
            $parsed = $context->sourceStyles->parsedSelector($selector);
            if (!$parsed['supported']) continue;
            foreach ($this->matchingSourceElements($selector, $parsed, $context) as $element) {
                // A current-page link run lowers to core/navigation whatever its tag.
                if (('nav' === strtolower($element->tagName) && 0 === $element->getElementsByTagName('ul')->length && 0 === $element->getElementsByTagName('ol')->length)
                    || \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\MenuVocabulary::isCurrentPageLinkRun($element)) {
                    $targets[] = ':root .wp-block-navigation' . $match[0];
                    break;
                }
            }
        }
        return array() === $targets ? '' : implode(',', array_unique($targets)) . '{' . $this->styleResolver->cssDeclarationString($lifted) . '}';
    }

    private function withEditorProjectionRules(string $css, string $prelude, string $body, AuthorStylesheetProjectionContext $context): string
    {
        $css .= $this->visuallyHiddenSourceSelectorRule($prelude, $css, $body);

        $css .= $this->editorOverrideRules($css, $context);

        return $css . $this->editorShellChildCombinatorVariants($css, $context->emittedMarkup) . $this->editorEmptyRichTextVariants($css);
    }

    /**
     * An empty core/paragraph is `:empty` saved, but not in the editor: its
     * RichText holds a padding character and a placeholder. Core marks it
     * `data-empty="true"` instead. Emit editor-scoped variants of authored
     * `:empty` rules that match that marker. Negated `:empty` cannot be
     * mirrored by an added rule, so those selectors are left as they are.
     */
    private function editorEmptyRichTextVariants(string $css): string
    {
        if ( ! str_contains($css, ':empty') ) {
            return '';
        }
        return ( new CssStylesheetTransformer() )->transformStyleRules(
            $css,
            static function (string $prelude, string $body): string {
                $variants = array();
                foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector ) {
                    $selector = trim($selector);
                    if ( 1 !== preg_match('/:empty(?![\\w-])/', $selector) || 1 === preg_match('/:not\([^)]*:empty/', $selector) || str_contains($selector, '.editor-styles-wrapper') ) {
                        continue;
                    }
                    // `[data-empty]` and `:empty` weigh the same, and the editor
                    // scope adds nothing, so the variant ties as its source rule does.
                    $variants[] = ':where(:root .editor-styles-wrapper) ' . preg_replace('/:empty(?![\\w-])/', '[data-empty="true"]', $selector);
                }
                return array() === $variants ? '' : implode(',', $variants) . '{' . $body . '}';
            }
        );
    }

    private function visuallyHiddenSourceSelectorRule(string $prelude, string $projectedCss, string $body): string
    {
        if ( ! CssValueInspector::isVisuallyClippedBox(SourceDeclarations::parse($body)) ) {
            return '';
        }
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors ) {
            return '';
        }
        $projected = array();
        ( new CssStylesheetTransformer() )->visitStyleRules($projectedCss, static function (string $projectedPrelude) use (&$projected): void {
            foreach ( CssStylesheetTransformer::splitSelectorList($projectedPrelude) ?? array() as $selector ) {
                $projected[self::selectorKey($selector)] = true;
            }
        });
        $kept = array();
        foreach ( $selectors as $selector ) {
            $selector = trim($selector);
            if ( '' === $selector || ! str_contains($selector, '.') || isset($projected[self::selectorKey($selector)]) ) {
                continue;
            }
            $kept[] = $selector;
        }

        return array() === $kept ? '' : implode(',', $kept) . '{' . $body . '}';
    }

    private static function selectorKey(string $selector): string
    {
        return preg_replace('/\s+/', ' ', trim($selector)) ?? trim($selector);
    }

    private function editorOverrideRules(string $css, AuthorStylesheetProjectionContext $context): string
    {
        $ids = array_fill_keys($context->authorStyles->sourceElementIds(), true);
        return ( new CssStylesheetTransformer() )->transformStyleRules(
            $css,
            function (string $prelude, string $body) use ($ids): string {
                $declarations = SourceDeclarations::parse($body);
                $position = trim((string) ($declarations['position'] ?? ''));
                $whiteSpace = strtolower(trim(preg_replace('/\s*!important\s*$/i', '', $declarations['white-space'] ?? '') ?? ''));
                $nowrap = in_array($whiteSpace, array('nowrap', 'pre'), true);
                $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
                if ( ( '' === $position && ! $nowrap ) || null === $selectors ) {
                    return '';
                }
                $editorSelectors = array();
                foreach ( $selectors as $selector ) {
                    $selector = trim($selector);
                    if ( '' === $selector || str_starts_with($selector, ':host') || str_contains($selector, '.editor-styles-wrapper') ) {
                        continue;
                    }
                    // Block ids become client ids in the canvas. Give every
                    // position copy an anchor alternative with the same source
                    // specificity, not only copies of static-state repairs.
                    if ( '' !== $position ) {
                        $selector = ScopedAnchorSelectorProjection::selector($selector, $ids);
                    }
                    if ( 1 === preg_match('/^body(?=$|[.#:\[])/', $selector) ) {
                        $editorSelectors[] = preg_replace('/^body/', ':root body.editor-styles-wrapper', $selector, 1) ?? $selector;
                        continue;
                    }
                    if ( 1 === preg_match('/^:root(?=$|[.#:\[])/', $selector) ) {
                        $editorSelectors[] = preg_replace('/^:root/', ':root .editor-styles-wrapper', $selector, 1) ?? $selector;
                        continue;
                    }
                    $editorSelectors[] = ':root .editor-styles-wrapper ' . $selector;
                }
                $fixedSizing = '';
                if ( 'fixed' === strtolower(trim(preg_replace('/\s*!important\s*$/i', '', $position) ?? $position)) ) {
                    // Core's editor canvas is position:relative. Re-forcing
                    // source `position:fixed` pins site chrome (sticky
                    // headers, FABs) over the document being edited; leave
                    // it out of the editor canvas entirely. Its viewport-sized
                    // box must also stop stretching now that it is in flow,
                    // and overlay the grid edge rather than auto-place a track.
                    $fixedSizing = $this->editorFixedSizing($declarations);
                    $position = '';
                }
                // Core adds `position:relative` to the actual editor block
                // root, but every copy here shares the
                // `:root .editor-styles-wrapper` prefix plus the source
                // selector's own specificity, which outranks that default and
                // keeps the source rules' relative order. Mirror the value
                // plainly so a more specific source `relative`/`static` rule
                // still wins in the editor as it does on the frontend.
                $overrides = $fixedSizing . ( '' === $position ? '' : 'position:' . $position . ';' );
                // RichText's inline white-space:pre-wrap must keep preserving
                // typed spaces. Override only its wrapping longhand, not the
                // whitespace-collapse half of the shorthand. Scoping keeps
                // the authored frontend declarations untouched.
                if ( $nowrap ) {
                    $overrides .= 'text-wrap-mode:nowrap!important';
                }

                return array() === $editorSelectors || '' === $overrides
                    ? ''
                    : implode(',', $editorSelectors) . '{' . rtrim($overrides, ';') . '}';
            }
        );
    }

    /** @param array<string, string> $declarations */
    private function editorFixedSizing(array $declarations): string
    {
        $plain = static fn (string $value): string => strtolower(trim(preg_replace('/\s*!important\s*$/i', '', $value) ?? $value));
        $relative = static fn (string $value): bool => 1 === preg_match('/(?:[0-9.]%|[0-9.](?:s|d|l)?vh\b)/i', $value);
        $height = $plain($declarations['height'] ?? 'auto');
        // Expand inset in declaration order so later top/bottom longhands (or
        // a later shorthand) still decide whether the fixed box was stretched.
        $edges = array();
        foreach ( $declarations as $name => $value ) {
            if ( 'inset' === $name ) {
                $parts = CssValueSplitter::splitTopLevelWhitespace($plain($value));
                if ( count($parts) < 1 || count($parts) > 4 ) continue;
                $sides = array('top' => $parts[0], 'bottom' => $parts[2] ?? $parts[0]);
            } elseif ( in_array($name, array('top', 'bottom'), true) ) {
                $sides = array($name => $plain($value));
            } else {
                continue;
            }
            foreach ( $sides as $side => $edge ) {
                if ( isset($edges[$side]) && $edges[$side]['important'] && ! CssValueInspector::isImportant($value) ) continue;
                $edges[$side] = array('value' => $edge, 'important' => CssValueInspector::isImportant($value));
            }
        }
        $stretched = 'auto' === $height && isset($edges['top'], $edges['bottom'])
            && 'auto' !== $edges['top']['value'] && 'auto' !== $edges['bottom']['value'];
        // Grid placement is inert for non-grid parents. Keep fixed layers on
        // an explicit edge so the editor's in-flow copy cannot consume an
        // auto-placed track. Prefer the top edge when both anchors are set.
        $bottomAnchored = 'auto' === ($edges['top']['value'] ?? 'auto')
            && 'auto' !== ($edges['bottom']['value'] ?? 'auto');
        $overrides = $bottomAnchored
            ? 'grid-row:-2/-1;grid-column:1/-1;align-self:end;'
            : 'grid-row:1;grid-column:1/-1;align-self:start;';
        if ( isset($declarations['z-index']) ) {
            $overrides .= 'z-index:' . $declarations['z-index'] . ';';
        }
        if ( $relative($height) || $stretched ) {
            $overrides .= 'height:auto' . (CssValueInspector::isImportant($declarations['height'] ?? '') ? '!important' : '') . ';';
            if ( $relative($declarations['min-height'] ?? '') ) {
                $overrides .= 'min-height:0' . (CssValueInspector::isImportant($declarations['min-height']) ? '!important' : '') . ';';
            }
        }
        return $overrides;
    }

    /**
     * Restate only child steps crossing a known third-party editor layer in
     * the emitted markup. Layout shells need no editor selector projection.
     */
    private function editorShellChildCombinatorVariants(string $css, string $markup): string
    {
        if ( ! str_contains($css, '>') || ! str_contains($markup, '<!-- wp:jetpack/contact-form') ) {
            return '';
        }
        return ( new CssStylesheetTransformer() )->transformStyleRules(
            $css,
            static function (string $prelude, string $body) use ($markup): string {
                $rules = '';
                foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector ) {
                    $rules .= EditorShellBoundary::css(EditorShellBoundary::rule($selector, $body, markup: $markup));
                }
                return $rules;
            }
        );
    }

    /**
     * Core restyles every lowered `<hr>` with a class rule
     * (`.wp-block-separator{border:none;border-top:2px solid}`) that outranks an
     * author `hr` type rule. Re-emit the borders of each author rule reaching a
     * source `hr`, scoped to the separator and raised by one uniform class
     * shim, so they beat Core's default while keeping their own cascade order.
     * Elements other than separators keep the unshimmed rule.
     *
     * @param array<string, string> $declarations
     */
    private function separatorBorderRule(string $prelude, array $declarations, AuthorStylesheetProjectionContext $context): string
    {
        $borders = array_filter($declarations, static fn (string $name): bool => 1 === preg_match('/^border(?:-(?:top|right|bottom|left|block|inline)(?:-(?:start|end))?)?(?:-(?:width|style|color))?$/', $name), ARRAY_FILTER_USE_KEY);
        $selectors = array() === $borders ? null : CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors ) {
            return '';
        }
        $reaching = array();
        foreach ( $selectors as $selector ) {
            $parsed = $context->sourceStyles->parsedSelector(trim($selector));
            if ( ! ($parsed['supported'] ?? false) ) {
                continue;
            }
            foreach ( $this->matchingSourceElements(trim($selector), $parsed, $context) as $element ) {
                if ( 'hr' === strtolower($element->tagName) ) {
                    $reaching[] = trim($selector);
                    break;
                }
            }
        }
        $projected = array() === $reaching ? null : CssStylesheetTransformer::splitSelectorList($this->rewriteSelectorPrelude(implode(',', $reaching), $context));
        if ( null === $projected ) {
            return '';
        }
        $scope = ':where(.wp-block-separator):not(.' . $context->authorStyles->classSpecificityShim() . ')';

        return implode(',', array_map(static fn (string $selector): string => trim($selector) . $scope, $projected))
            . '{' . $this->styleResolver->cssDeclarationString($borders) . '}';
    }

    /** @param array<string, string> $margins */
    private function tableVerticalMarginRules(string $prelude, array $margins, AuthorStylesheetProjectionContext $context): string
    {
        $vertical = array();
        foreach ( $margins as $property => $value ) {
            if ( 'margin' === $property ) {
                $important = CssValueInspector::isImportant($value) ? '!important' : '';
                $sides = CssValueInspector::expandBoxShorthand(CssValueInspector::withoutImportant($value));
                $vertical['margin-top'] = $sides[0] . $important;
                $vertical['margin-bottom'] = $sides[2] . $important;
            } elseif ( in_array($property, array( 'margin-top', 'margin-bottom', 'margin-block', 'margin-block-start', 'margin-block-end' ), true) ) {
                $vertical[$property] = $value;
            }
        }
        if ( array() === $vertical ) {
            return '';
        }
        $rules = array();
        foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector ) {
            $parsed = $context->sourceStyles->parsedSelector($selector);
            // Class-only rules already follow their source class onto the figure.
            if ( ! $parsed['supported'] || 'table' !== (CssSelectorCompoundInspector::rightmost($parsed['compounds'])['type'] ?? null) ) {
                continue;
            }
            foreach ( $this->matchingSourceElements($selector, $parsed, $context) as $table ) {
                $marker = $context->selectorProjections->tableMarker($table->getNodePath() ?? '');
                if ( '' === $marker ) {
                    continue;
                }
                $target = $this->projectSemanticLeafSelector($selector, $parsed, $marker, $context);
                $rules[] = $target . ':not(.' . $context->authorStyles->classSpecificityShim() . '){' . $this->styleResolver->cssDeclarationString($vertical) . '}';
                // The inner table remains the horizontal-margin and border owner.
                $rules[] = '.' . $marker . '>table{margin-block:0!important}';
            }
        }
        return implode('', array_unique($rules));
    }

    private function marginSelectorPrelude(string $prelude, AuthorStylesheetProjectionContext $context): string
    {
        $buttonPresentationPseudoPrelude = $this->buttonPresentationPseudoPrelude($prelude, $context);
        if ( '' !== $buttonPresentationPseudoPrelude ) {
            return $buttonPresentationPseudoPrelude;
        }
        // Margins carry no button geometry, so a dormant control arm may name
        // its subject classes (the main arm stays per element in wrapper mode).
        $projected = $this->rewriteSelectorPrelude($prelude, $context, true, true, true);
        $selectors = CssStylesheetTransformer::splitSelectorList($projected);
        if ( null === $selectors ) {
            return $projected;
        }

        // Linked author CSS precedes Gutenberg's inline flow resets, so authored margins cannot rely on source order.
        $shim = ':not(.' . $context->authorStyles->classSpecificityShim() . ')';
        return implode(',', array_map(static function (string $selector) use ($shim): string {
            $selector = trim($selector);
            // Legacy single-colon pseudo-elements must remain last. Appending the
            // shim after :before/:after invalidates their entire selector list.
            if ( 1 !== preg_match('/(?:::[A-Za-z_-][A-Za-z0-9_-]*|:(?:before|after|first-letter|first-line))(?:\([^)]*\))?$/', $selector) ) {
                return $selector . $shim;
            }
            return preg_replace('/((?:::[A-Za-z_-][A-Za-z0-9_-]*|:(?:before|after|first-letter|first-line))(?:\([^)]*\))?)$/', $shim . '$1', $selector, 1) ?? $selector;
        }, $selectors));
    }

    /** @param list<DOMElement>|null $controls Limit a partition to these matched controls. */
    private function rewriteStyleRule(string $prelude, string $body, AuthorStylesheetProjectionContext $context, bool $inConditional = false, ?array $controls = null): string
    {
        $buttonPresentationPseudoPrelude = $this->buttonPresentationPseudoPrelude($prelude, $context);
        if ( '' !== $buttonPresentationPseudoPrelude ) {
            return $buttonPresentationPseudoPrelude . '{' . $body . '}';
        }
        // A shared utility can size padding-free wrappers for one control but
        // the padded link for another. Partition only those declarations, and
        // send each group through the same projection and inner-fill APIs.
        if ( null === $controls ) {
            $subjects = null;
            $rest = array();
            $partitioned = '';
            foreach ( CssValueSplitter::splitTopLevel($body, array( ';' )) as $declaration ) {
                $colon = strpos($declaration, ':');
                $groups = array( array(), array() );
                $name = false === $colon ? '' : strtolower(trim(substr($declaration, 0, $colon)));
                // Bare variable sizes need the same length/keyword classification
                // as literals. Math expressions already retain their sizing chain;
                // link-owned declarations keep their source variable scope intact.
                if ( in_array($name, array( 'width', 'min-width', 'max-width', 'height', 'min-height', 'max-height' ), true)
                    && 1 === preg_match('/^var\(/', trim(substr($declaration, $colon + 1)))
                ) {
                    $subjects ??= $this->buttonDeclarationRouting->buttonSizeSubjects($prelude, $context);
                    $value = trim(substr($declaration, $colon + 1));
                    $resolvedGroups = array();
                    foreach ( $subjects as $element ) {
                        $resolved = $this->buttonDeclarationRouting->isButtonControlBoxSize($name, $value, array( $element ))
                            ? $value
                            : $this->styleResolver->resolveCssVariablesInValue($value, $element);
                        $resolvedGroups[$resolved][] = $element;
                    }
                    // Resolve at the source element, before its custom properties
                    // and geometry are distributed across different block boxes.
                    // Keep non-controls in the original rule's variable scope.
                    if ( array() !== array_diff(array_keys($resolvedGroups), array( $value )) ) {
                        if ( array() !== $rest ) {
                            $partitioned .= $this->rewriteStyleRule($prelude, implode(';', $rest), $context, $inConditional);
                            $rest = array();
                        }
                        foreach ( $resolvedGroups as $resolved => $group ) {
                            $partitioned .= $this->rewriteStyleRule($prelude, $name . ':' . $resolved, $context, $inConditional, $group);
                        }
                        $projected = $this->rewriteSelectorPrelude($prelude, $context, false, false, true);
                        $nonControls = $this->withoutButtonPresentationProjectionSelectors($projected, implode(',', array_map(
                            static fn (DOMElement $element): string => ':where(.' . $context->selectorProjections->controlMarker($element->getNodePath() ?? '') . ')',
                            $subjects
                        )));
                        if ( '' !== $nonControls ) {
                            $partitioned .= $nonControls . '{' . $declaration . '}';
                        }
                        continue;
                    }
                }
                if ( in_array($name, array( 'height', 'min-height', 'max-height' ), true) ) {
                    $subjects ??= $this->buttonDeclarationRouting->buttonSizeSubjects($prelude, $context);
                    $value = trim(substr($declaration, $colon + 1));
                    foreach ( $subjects as $element ) {
                        $groups[(int) $this->buttonDeclarationRouting->isButtonControlBoxSize($name, $value, array( $element ))][] = $element;
                    }
                }
                if ( array() === $groups[0] || array() === $groups[1] ) {
                    $rest[] = $declaration;
                    continue;
                }
                if ( array() !== $rest ) {
                    $partitioned .= $this->rewriteStyleRule($prelude, implode(';', $rest), $context, $inConditional);
                    $rest = array();
                }
                foreach ( $groups as $group ) {
                    $partitioned .= $this->rewriteStyleRule($prelude, $declaration, $context, $inConditional, $group);
                }
                $projected = $this->rewriteSelectorPrelude($prelude, $context, false, false, true);
                $nonControls = $this->withoutButtonPresentationProjectionSelectors($projected, implode(',', array_map(
                    static fn (DOMElement $element): string => ':where(.' . $context->selectorProjections->controlMarker($element->getNodePath() ?? '') . ')',
                    $subjects
                )));
                if ( '' !== $nonControls ) {
                    $partitioned .= $nonControls . '{' . $declaration . '}';
                }
            }
            if ( '' !== $partitioned ) {
                return $partitioned . ( array() === $rest ? '' : $this->rewriteStyleRule($prelude, implode(';', $rest), $context, $inConditional) );
            }
        }
        $markers = null === $controls ? null : array_map(static fn (DOMElement $element): string => ':where(.' . $context->selectorProjections->controlMarker($element->getNodePath() ?? '') . ')', $controls);
        $selectControls = static function (string $projected) use ($markers): string {
            if ( null === $markers ) {
                return $projected;
            }
            return implode(',', array_filter(CssStylesheetTransformer::splitSelectorList($projected) ?? array(), static function (string $selector) use ($markers): bool {
                foreach ( $markers as $marker ) {
                    if ( str_contains($selector, $marker) && ! str_contains($selector, ':not(' . $marker . ')') ) {
                        return true;
                    }
                }
                return false;
            }));
        };
        $projectedPrelude = $selectControls($this->rewriteSelectorPrelude($prelude, $context, false, null === $controls && $this->allowsSubjectArms($prelude, $body), true));
        // Centered source dropdown boxes are desktop presentation, not Core's
        // open-overlay stack. Keep the authored rule outside that stack rather
        // than relying on a later, higher-specificity alignment override.
        if ( preg_match('/(?:^|;)\s*(?:text-align|align-items|justify-content)\s*:\s*center\b/i', $body) ) {
            $submenuOnly = true;
            $found = false;
            foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector ) {
                $parsed = $context->sourceStyles->parsedSelector($selector);
                foreach ( $this->matchingSourceElements($selector, $parsed, $context) as $element ) {
                    $lists = 0;
                    $inNavigation = false;
                    for ( $node = $element; $node instanceof DOMElement; $node = $node->parentNode ) {
                        if ( in_array(strtolower($node->tagName), array('ul', 'ol'), true) ) ++$lists;
                        if ( 'nav' === strtolower($node->tagName) ) $inNavigation = true;
                    }
                    $found = true;
                    if ( ! $inNavigation || $lists < 2 ) $submenuOnly = false;
                }
            }
            if ( $found && $submenuOnly ) {
                $projectedPrelude = implode(',', array_map(
                    static fn (string $selector): string => $selector . ':not(:where(.wp-block-navigation__responsive-container.is-menu-open *))',
                    CssStylesheetTransformer::splitSelectorList($projectedPrelude) ?? array()
                ));
            }
        }
        $authoredBody = $body;
        $body = $this->buttonLinkCompatDeclarations($prelude, $projectedPrelude, $body, $context);
        $nativeButtonCompatRule = $this->nativeButtonLinkCompatRule($prelude, $authoredBody, $context);
        $nonButtonLinkRule = '';
        if ( $body !== $authoredBody ) {
            $partition = $this->partitionProjectedButtonLinkSelectors($projectedPrelude);
            if ( is_array($partition) ) {
                [ $projectedPrelude, $nonButtonPrelude ] = $partition;
                $nonButtonLinkRule = $nonButtonPrelude . '{' . $authoredBody . '}';
            }
        }
        $wrapperPrelude = $selectControls($this->buttonPresentationWrapperPrelude($prelude, $context));
        if ( '' === $wrapperPrelude ) {
            $directWrapperPrelude = $selectControls($this->directButtonGeometryWrapperPrelude($prelude, $context));
            if ( '' === $directWrapperPrelude ) {
                $mixedButtonProjection = $this->withoutCollapsedButtonProjectedWidths($projectedPrelude, $body);
                return $nonButtonLinkRule . ( null !== $mixedButtonProjection ? $mixedButtonProjection : $projectedPrelude . '{' . $body . '}' ) . $nativeButtonCompatRule;
            }
            [ $placement, $geometry, $inner ] = $this->buttonDeclarationRouting->splitDirectButtonGeometryDeclarations($body, $prelude, $context, $controls);
            $placementPrelude = $selectControls($this->directButtonPlacementWrapperPrelude($prelude, $context));
            $placementRule = '' === $placement || '' === $placementPrelude
                ? ''
                : $placementPrelude . '{' . $placement . '}';
            $nonButtonGeometryPrelude = $this->withoutButtonPresentationProjectionSelectors($projectedPrelude, $directWrapperPrelude);
            $nonButtonGeometryDeclarations = array_filter(array( $placement, $geometry, $this->collapsedButtonKeywordWidthDeclarations($body) ));
            $nonButtonGeometry = '' === $nonButtonGeometryPrelude || array() === $nonButtonGeometryDeclarations
                ? ''
                : $nonButtonGeometryPrelude . '{' . implode(';', $nonButtonGeometryDeclarations) . '}';
            if ( '' === $geometry ) {
                return $nonButtonLinkRule . $placementRule . ( '' === $inner ? '' : $projectedPrelude . '{' . $inner . '}' ) . $nonButtonGeometry . $nativeButtonCompatRule;
            }
            return $nonButtonLinkRule . $placementRule . $this->withButtonWrapperInnerFill($directWrapperPrelude, $geometry, ( '' === $inner ? '' : $projectedPrelude . '{' . $inner . '}' ) . $nonButtonGeometry, $inConditional, $context) . $nativeButtonCompatRule;
        }

        [ $layout, $control ] = $this->buttonDeclarationRouting->splitButtonPresentationDeclarations($body, $prelude, $context, $controls);
        $nonButtonLayoutPrelude = $this->withoutButtonPresentationProjectionSelectors($projectedPrelude, $wrapperPrelude);
        $nonButtonLayout = '' === $nonButtonLayoutPrelude ? '' : $nonButtonLayoutPrelude . '{' . $layout . '}';
        if ( '' === $layout ) {
            return $nonButtonLinkRule . ( '' === $control ? '' : $projectedPrelude . '{' . $control . '}' ) . $nativeButtonCompatRule;
        }
        if ( '' === $control ) {
            return $nonButtonLinkRule . $this->withButtonWrapperInnerFill($wrapperPrelude, $layout, $nonButtonLayout, $inConditional, $context) . $nativeButtonCompatRule;
        }
        return $nonButtonLinkRule . $this->withButtonWrapperInnerFill($wrapperPrelude, $layout, $projectedPrelude . '{' . $control . '}' . $nonButtonLayout, $inConditional, $context) . $nativeButtonCompatRule;
    }

    /**
     * Whether a rule's control arms may name subject classes instead of
     * per-element markers.
     *
     * The button projection moves some declarations per control: layout and
     * placement onto the wrapper box each control's own layout participation
     * picks, collapsed keyword widths
     * off the link. It finds those arms by their per-element marker, so a rule
     * carrying any such declaration keeps per-element arms. The test reads only
     * the rule itself, never the page, so every page decides alike; a subject
     * arm in a stylesheet copy another page loads then carries the same
     * declarations this page's own projection would.
     */
    private function allowsSubjectArms(string $prelude, string $body): bool
    {
        $universalReset = $this->preludeIsUniversalReset($prelude);
        foreach ( CssRuleAnalyzer::declarationList($body) as [ 'name' => $name ] ) {
            if ( '' === $name ) {
                continue;
            }
            // Layout can move to wrapper boxes; retain exact subject identity.
            $padding = 'padding' === $name || str_starts_with($name, 'padding-');
            if ( $padding ? $universalReset : $this->isButtonLinkLayoutProperty($name) ) {
                return false;
            }
        }
        [ $placement, $geometry ] = $this->buttonDeclarationRouting->splitDirectButtonGeometryDeclarations($body);
        [ $layout ] = $this->buttonDeclarationRouting->splitButtonPresentationDeclarations($body);
        return '' === $placement && '' === $geometry && '' === $layout && '' === $this->collapsedButtonKeywordWidthDeclarations($body);
    }

    /**
     * Project generated content from an unwrapped button label surface onto the
     * native link that replaces it. A pseudo rule is safe only when every
     * matching source element has already been mapped to that link.
     */
    private function buttonPresentationPseudoPrelude(string $prelude, AuthorStylesheetProjectionContext $context): string
    {
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors ) {
            return '';
        }

        $rewritten = array();
        foreach ( $selectors as $selector ) {
            if ( 1 !== preg_match('/^(.*?)(::(?:before|after))\s*$/i', trim($selector), $matches) ) {
                return '';
            }
            $baseSelector = trim($matches[1]);
            $parsed = $context->sourceStyles->parsedSelector($baseSelector);
            if ( ! $parsed['supported'] ) {
                $rewritten[] = $selector;
                continue;
            }
            $sourceElements = $this->matchingSourceElements($baseSelector, $parsed, $context);
            if ( array() === $sourceElements ) {
                $rewritten[] = $selector;
                continue;
            }
            $markers = array();
            $hasLabelProjection = false;
            foreach ( $sourceElements as $element ) {
                $path = $element->getNodePath() ?? '';
                if ( $context->selectorProjections->isButtonLabelPath($path) ) {
                    $marker = $context->selectorProjections->richTextMarker($path);
                    if ( '' === $marker ) {
                        $markers = array();
                        break;
                    }
                    $rewritten[] = $this->projectRichTextSemanticSelector($baseSelector, $parsed, $marker, $context) . $matches[2];
                    $hasLabelProjection = true;
                    continue;
                }
                if ( ! $context->selectorProjections->isButtonPresentationPath($path) ) {
                    $markers = array();
                    break;
                }
                $marker = $context->selectorProjections->controlMarker($path);
                if ( '' === $marker ) {
                    $markers = array();
                    break;
                }
                $markers[] = $marker;
            }
            if ( array() === $markers && ! $hasLabelProjection ) {
                $rewritten[] = $this->rewriteSelectorPrelude($selector, $context);
                continue;
            }
            foreach ( array_unique($markers) as $marker ) {
                $rewritten[] = $this->projectControlSelector($baseSelector, $parsed, $marker, $context) . $matches[2];
            }
        }

        return implode(',', array_values(array_unique($rewritten)));
    }

    /**
     * Drop only synthetic universal padding resets on lowered anchor controls.
     * All remaining declarations keep their source importance and order.
     */
    private function buttonLinkCompatDeclarations(string $prelude, string $projectedPrelude, string $body, AuthorStylesheetProjectionContext $context): string
    {
        if ( ! str_contains($projectedPrelude, '.wp-block-button__link') || ! $this->projectsAnchorButtonControl($prelude, $context) ) {
            return $body;
        }

        $declarations = array();
        foreach ( CssValueSplitter::splitTopLevel($body, array( ';' )) as $declaration ) {
            $colon = strpos($declaration, ':');
            if ( false === $colon ) {
                $declarations[] = $declaration;
                continue;
            }
            $name = trim(substr($declaration, 0, $colon));
            $value = trim(substr($declaration, $colon + 1));
            $plainValue = trim((string) preg_replace('/\s*!important\s*$/i', '', $value));
            if ( $this->preludeIsUniversalReset($prelude)
                && ( 'padding' === $name || str_starts_with($name, 'padding-') )
                && 1 === preg_match('/^(?:0(?:px|rem|em)?)$/i', $plainValue)
            ) {
                continue;
            }
            $declarations[] = $declaration;
        }
        return implode(';', $declarations);
    }

    /**
     * Split a projected selector list that mixed native button links with other
     * source tags. Button-link !important must not ride along onto those tags:
     * a shared `div,a,button{padding:0}` reset would otherwise beat later
     * authored padding on the non-button matches.
     *
     * @return array{0: string, 1: string}|null
     */
    private function partitionProjectedButtonLinkSelectors(string $projectedPrelude): ?array
    {
        $selectors = CssStylesheetTransformer::splitSelectorList($projectedPrelude);
        if ( null === $selectors ) {
            return null;
        }

        $buttonSelectors = array();
        $otherSelectors = array();
        foreach ( $selectors as $selector ) {
            if ( str_contains($selector, '.wp-block-button__link') ) {
                $buttonSelectors[] = $selector;
            } else {
                $otherSelectors[] = $selector;
            }
        }
        if ( array() === $buttonSelectors || array() === $otherSelectors ) {
            return null;
        }

        return array( implode(',', $buttonSelectors), implode(',', $otherSelectors) );
    }

    private function projectsAnchorButtonControl(string $prelude, AuthorStylesheetProjectionContext $context): bool
    {
        return $this->projectsControlTag($prelude, $context, 'a');
    }

    private function projectsControlTag(string $prelude, AuthorStylesheetProjectionContext $context, string $tagName): bool
    {
        foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector ) {
            $parsed = $context->sourceStyles->parsedSelector($selector);
            if ( ! $parsed['supported'] ) {
                continue;
            }
            foreach ( $this->matchingSourceElements($selector, $parsed, $context) as $element ) {
                if ( $tagName === strtolower($element->tagName)
                    && '' !== $context->selectorProjections->controlMarker($element->getNodePath() ?? '') ) {
                    return true;
                }
            }
        }
        return false;
    }

    private function preludeIsUniversalReset(string $prelude): bool
    {
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors || array() === $selectors ) {
            return false;
        }

        foreach ( $selectors as $selector ) {
            $selector = strtolower(trim($selector));
            if ( in_array($selector, array( '*', '::before', '::after', ':before', ':after', '::backdrop' ), true) ) {
                continue;
            }

            // Tailwind v4 preflight is authored as `*:not(:where(…))` once the
            // engine has excluded its own generated markers, so the universal
            // selector arrives carrying a negation list rather than bare `*`.
            if ( 1 === preg_match('/^\*:not\(/', $selector) ) {
                continue;
            }

            return false;
        }

        return true;
    }

    private function isButtonLinkLayoutProperty(string $property): bool
    {
        return 'display' === $property
            || 'gap' === $property
            || str_starts_with($property, 'flex-')
            || str_starts_with($property, 'align-')
            || str_starts_with($property, 'justify-')
            || 'width' === $property
            || 'height' === $property
            || str_starts_with($property, 'min-')
            || str_starts_with($property, 'max-')
            || 'padding' === $property
            || str_starts_with($property, 'padding-');
    }

    private function nativeButtonLinkCompatRule(string $prelude, string $body, AuthorStylesheetProjectionContext $context): string
    {
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors ) {
            return '';
        }
        $projected = array();
        foreach ( $selectors as $selector ) {
            $parsed = $context->sourceStyles->parsedSelector($selector);
            if ( ! $parsed['supported'] ) {
                continue;
            }
            $markers = array();
            $flattened = true;
            foreach ( $this->matchingSourceElements($selector, $parsed, $context) as $element ) {
                $path = $element->getNodePath() ?? '';
                $marker = $context->selectorProjections->controlMarker($path);
                if ( 'button' !== strtolower($element->tagName) || '' === $marker || $this->isSpecializedNativeButton($element, $body) ) {
                    continue;
                }
                $markers[] = $marker;
                $flattened = $flattened && LayoutParticipation::resolve($element, $this->styleResolver)->neutralizeButtonWrapper();
            }
            $arms = array();
            // Every native button carrier the context-free subject matches is
            // reached through the role and subject classes; `.button` stands
            // for the tag test the per-element list applied.
            $subject = array() !== $markers ? $this->contextFreeSubject('control', $this->withButtonSubject($parsed), $markers, $context) : null;
            if ( null !== $subject ) {
                $arms[] = $this->nativeButtonControlTarget($subject['target'], $selector, $parsed, $context);
                $markers = $subject['rest'];
            }
            foreach ( $markers as $marker ) {
                $arms[] = $this->nativeButtonControlSelector($selector, $parsed, $marker, $context);
            }
            foreach ( $arms as $arm ) {
                $projected[] = array( $arm, $flattened ? $this->flattenedWrapperLinkArm($arm) : $arm );
            }
        }
        if ( array() === $projected ) {
            return '';
        }

        $declarations = array();
        foreach ( CssRuleAnalyzer::declarationList($body) as [ 'name' => $name, 'value' => $value ] ) {
            if ( '' === $name || '' === $value || ( ! $this->isButtonLinkLayoutProperty($name) && ! $this->isButtonLinkPresentationProperty($name) ) ) {
                continue;
            }
            $exclusion = $this->nativeButtonEditedPropertyExclusion($name);
            $declarations[$exclusion][ButtonDeclarationRouting::isWrapperOwnedProperty($name) ? 1 : 0][] = $name . ':' . $value;
        }
        if ( array() === $declarations ) {
            return '';
        }
        $rules = array();
        foreach ( $declarations as $exclusion => $groups ) {
            foreach ( $groups as $wrapperOwned => $properties ) {
                foreach ( array_values(array_unique(array_column($projected, $wrapperOwned))) as $selector ) {
                    $rules[] = $selector . $exclusion . '{' . implode(';', $properties) . '}';
                }
            }
        }
        return implode('', $rules);
    }

    /**
     * The link arm for wrapper geometry that reaches the link only because
     * the core/button save wrapper is flattened (LayoutParticipation::
     * neutralizeButtonWrapper()). The arm requires that flattened carrier, so
     * a provider that saves its own core/button around the same control marker
     * keeps a wrapper box and its own wrapper geometry; a percentage size then
     * cannot resolve a second time against that wrapper.
     */
    private function flattenedWrapperLinkArm(string $arm): string
    {
        $at = strpos($arm, '> :where(.wp-block-button__link)');
        return false === $at
            ? $arm
            : substr($arm, 0, $at) . ':where(.' . SourceBlockAttributeProjector::LAYOUT_NEUTRAL_BUTTON_CLASS . ')' . substr($arm, $at);
    }

    private function nativeButtonControlSelector(string $selector, array $parsed, string $marker, AuthorStylesheetProjectionContext $context): string
    {
        $projected = $this->projectControlSelector($selector, $parsed, $marker, $context);
        // Generated button style variations can carry later important declarations.
        // Keep the source-owned control selector specific to its exact target.
        return str_replace(':where(.' . $marker . ')', ':where(.' . $marker . ').' . $marker . '.' . $marker, $projected);
    }

    /**
     * The native-button arm for a subject target. The per-element form adds
     * two class weights by repeating its marker outside `:where()`; the role
     * class every control carrier has repeats instead, for the same weight.
     *
     * @param array<string, mixed> $parsed
     */
    private function nativeButtonControlTarget(string $target, string $selector, array $parsed, AuthorStylesheetProjectionContext $context): string
    {
        return $this->projectControlTarget($target . '.blocks-engine-role-control.blocks-engine-role-control', $selector, $parsed, $context);
    }

    /**
     * The selector restricted to button elements: a context-free compound
     * without a type gains `button`; any other shape stays as it is (and a
     * typed compound other than `button` matches no native button carrier).
     *
     * @param array<string, mixed> $parsed
     * @return array<string, mixed>
     */
    private function withButtonSubject(array $parsed): array
    {
        if ( 1 !== count($parsed['compounds'] ?? array()) || null !== ($parsed['compounds'][0]['type'] ?? null) ) {
            return $parsed;
        }
        $parsed['compounds'][0]['type'] = 'button';
        $parsed['compounds'][0]['universal'] = false;
        return $parsed;
    }

    /**
     * The guard that lets a block's own value win over a restated author rule.
     * A colour the block carries as a preset class (`has-text-color`,
     * `has-background`; see PresetBinding) is the block's own value just like
     * an inline one, so the restated rule steps aside for it too.
     */
    private function nativeButtonEditedPropertyExclusion(string $property): string
    {
        if ( str_starts_with($property, 'background') ) {
            return ':not(.has-background):not([style*="background"])';
        }
        if ( str_starts_with($property, 'padding') ) {
            return ':not([style*="padding"])';
        }
        if ( str_starts_with($property, 'border') ) {
            return ':not([style*="border"])';
        }
        if ( str_starts_with($property, 'font') || 'line-height' === $property || 'letter-spacing' === $property || 'text-transform' === $property ) {
            return ':not([style*="font"]):not([style*="line-height"]):not([style*="letter-spacing"]):not([style*="text-transform"])';
        }
        if ( 'color' === $property ) {
            return ':not(.has-text-color):not([style*="color:"])';
        }
        return ':not([style*="text-decoration"])';
    }

    private function isSpecializedNativeButton(DOMElement $element, string $body): bool
    {
        return $this->hasNativeButtonGeometryDeclaration($body, $element);
    }

    private function hasNativeButtonGeometryDeclaration(string $body, ?DOMElement $element = null): bool
    {
        if ( preg_match('/(?:^|;)\s*position\s*:\s*(?:absolute|fixed)/i', $body) && preg_match('/(?:^|;)\s*(?:width|height)\s*:\s*0(?:[;\s]|$)/i', $body) ) {
            return true;
        }
        foreach ( CssRuleAnalyzer::declarationList($body) as [ 'name' => $property, 'value' => $value ] ) {
            $controls = null === $element ? array() : array( $element );
            // A layout-item width release competes on the link and rides the
            // compatibility restatement; it does not specialize the button the
            // way wrapper-owned geometry does.
            if ( $this->buttonDeclarationRouting->isButtonLinkCompetingBoxSize($property, $value, $controls) ) {
                continue;
            }
            if ( $this->buttonDeclarationRouting->isButtonControlBoxSize($property, $value, $controls) ) {
                return true;
            }
        }
        return false;
    }

    private function isButtonLinkPresentationProperty(string $property): bool
    {
        return 'background' === $property
            || str_starts_with($property, 'background-')
            || 'color' === $property
            || 'font' === $property
            || str_starts_with($property, 'font-')
            || 'line-height' === $property
            || 'letter-spacing' === $property
            || 'text-transform' === $property
            || 'border' === $property
            || str_starts_with($property, 'border-')
            || 'box-shadow' === $property
            || 'text-decoration' === $property;
    }

    private function withoutCollapsedButtonProjectedWidths(string $prelude, string $body): ?string
    {
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors ) {
            return null;
        }
        $buttonSelectors = array();
        $otherSelectors = array();
        foreach ( $selectors as $selector ) {
            if ( str_contains($selector, '> :where(.wp-block-button__link)') ) {
                $buttonSelectors[] = $selector;
            } else {
                $otherSelectors[] = $selector;
            }
        }
        if ( array() === $buttonSelectors ) {
            return null;
        }
        $safe = array();
        $collapsed = array();
        foreach ( CssValueSplitter::splitTopLevel($body, array( ';' )) as $declaration ) {
            $colon = strpos($declaration, ':');
            $name = strtolower(trim(false === $colon ? $declaration : substr($declaration, 0, $colon)));
            $value = false === $colon ? '' : trim(substr($declaration, $colon + 1));
            if ( false !== $colon && $this->buttonDeclarationRouting->isCollapsedButtonKeywordWidth($name, $value) ) {
                $collapsed[] = $declaration;
            } else {
                $safe[] = $declaration;
            }
        }
        if ( array() === $collapsed ) {
            return null;
        }
        $css = array() === $safe ? '' : $prelude . '{' . implode(';', $safe) . '}';
        if ( array() !== $otherSelectors ) {
            $css .= implode(',', $otherSelectors) . '{' . implode(';', $collapsed) . '}';
        }
        return $css;
    }

    /**
     * A lowered control's link and carrier both replace its source margin box.
     * Keep element/universal margin resets on the carrier too, even when the
     * link is the inline participant. Do not copy paint or authored spacing.
     */
    private function buttonCarrierResetPrelude(string $prelude, AuthorStylesheetProjectionContext $context): string
    {
        $rewritten = array();
        foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector ) {
            if ( ! preg_match('/^\s*(?:\*|[a-zA-Z][a-zA-Z0-9-]*)\s*$/', $selector) ) {
                continue;
            }
            $parsed = $context->sourceStyles->parsedSelector($selector);
            if ( ! ($parsed['supported'] ?? false) ) {
                continue;
            }
            foreach ( $this->matchingSourceElements($selector, $parsed, $context) as $element ) {
                $marker = $context->selectorProjections->controlMarker($element->getNodePath() ?? '');
                if ( '' !== $marker ) {
                    $rewritten[] = ('*' === trim($selector) ? ':where(.blocks-engine-role-control)' : ':where(.' . $marker . ')') . ':where(.wp-block-buttons):not(.'
                        . $context->authorStyles->classSpecificityShim() . ')'
                        . $this->selectorSpecificityShims($parsed, $context);
                }
            }
        }
        return implode(',', array_values(array_unique($rewritten)));
    }

    private function buttonCarrierMarginReset(string $body): string
    {
        $resets = array();
        foreach ( CssValueSplitter::splitTopLevel($body, array( ';' )) as $declaration ) {
            if ( preg_match('/^\s*margin(?:-(?:top|right|bottom|left|block(?:-start|-end)?|inline(?:-start|-end)?))?\s*:\s*(?:0(?:px|em|rem|%)?\s*){1,4}(?:!\s*important)?\s*$/i', $declaration) ) {
                $resets[] = $declaration;
            }
        }
        return implode(';', $resets);
    }

    private function buttonPresentationWrapperPrelude(string $prelude, AuthorStylesheetProjectionContext $context): string
    {
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors ) {
            return '';
        }
        $rewritten = array();
        foreach ( $selectors as $selector ) {
            $parsed = $context->sourceStyles->parsedSelector($selector);
            if ( ! $parsed['supported'] ) {
                array_push($rewritten, ...$this->projectUnsupportedFunctionalControlSelector($selector, $context, true));
                continue;
            }
            if ( null !== $parsed['pseudo_state_suffix_span'] || $this->hasUniversalStructuralLeaf($parsed) ) {
                continue;
            }
            $matches = $this->matchingSourceElements($selector, $parsed, $context);
            if ( array() === $matches ) {
                continue;
            }
            $markers = array();
            foreach ( $matches as $element ) {
                $path = $element->getNodePath() ?? '';
                $marker = $context->selectorProjections->isButtonPresentationPath($path)
                    ? $context->selectorProjections->controlMarker($path)
                    : '';
                if ( '' === $marker ) {
                    continue;
                }
                $markers[] = $marker;
            }
            foreach ( array_unique($markers) as $marker ) {
                $universal = $this->universalControlSubject($selector, $parsed, $context);
                $rewritten[] = null === $universal
                    ? ':where(.' . $marker . ')' . $this->selectorSpecificityShims($parsed, $context)
                    : $universal['prefix'] . ':where(.blocks-engine-role-control)' . $this->selectorSpecificityShims($universal['parsed'], $context);
            }
        }
        return implode(',', $rewritten);
    }

    private function directButtonGeometryWrapperPrelude(string $prelude, AuthorStylesheetProjectionContext $context): string
    {
        return $this->directButtonWrapperPrelude(
            $prelude,
            $context,
            function (string $selector, array $parsed, string $marker, DOMElement $element) use ($context): string {
                // Layout-participating declarations follow the box that still
                // generates a box after synthesized wrappers are neutralized.
                $participation = LayoutParticipation::resolve($element, $this->styleResolver);
                $box = $participation->participatingBox();
                if ( LayoutParticipation::BOX_BUTTON_WRAPPER === $box ) {
                    return $this->projectButtonBoxSelector($selector, $parsed, $marker, $context);
                }
                if ( LayoutParticipation::BOX_LINK === $box ) {
                    $link = $this->projectControlSelector($selector, $parsed, $marker, $context, false);
                    return $participation->neutralizeButtonWrapper() ? $this->flattenedWrapperLinkArm($link) : $link;
                }

                return $this->projectControlSelector($selector, $parsed, $marker, $context, true);
            }
        );
    }

    private function directButtonPlacementWrapperPrelude(string $prelude, AuthorStylesheetProjectionContext $context): string
    {
        return $this->directButtonWrapperPrelude(
            $prelude,
            $context,
            function (string $selector, array $parsed, string $marker, DOMElement $element) use ($context): string {
                // Placement shifts the same box geometry sizes. It reaches the
                // link only because the save wrapper is flattened, so it needs
                // the flattened carrier like the geometry arm: a provider that
                // saves its own core/button around the same control marker
                // keeps a wrapper box and applies the source shift itself, and
                // a second application on the link would double it.
                $participation = LayoutParticipation::resolve($element, $this->styleResolver);
                if ( LayoutParticipation::BOX_LINK !== $participation->participatingBox() ) {
                    return $this->projectButtonBoxSelector($selector, $parsed, $marker, $context);
                }
                $link = $this->projectControlSelector($selector, $parsed, $marker, $context);
                return $participation->neutralizeButtonWrapper() ? $this->flattenedWrapperLinkArm($link) : $link;
            }
        );
    }

    /** @param callable(string, array<string, mixed>, string, DOMElement): string $project */
    private function directButtonWrapperPrelude(string $prelude, AuthorStylesheetProjectionContext $context, callable $project): string
    {
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors ) {
            return '';
        }
        $rewritten = array();
        foreach ( $selectors as $selector ) {
            $parsed = $context->sourceStyles->parsedSelector($selector);
            if ( ! $parsed['supported'] || null !== $parsed['pseudo_state_suffix_span'] || $this->hasUniversalStructuralLeaf($parsed) ) {
                continue;
            }
            $matches = $this->matchingSourceElements($selector, $parsed, $context);
            if ( array() === $matches ) {
                continue;
            }
            foreach ( $matches as $element ) {
                $path = $element->getNodePath() ?? '';
                $marker = $context->selectorProjections->controlMarker($path);
                if ( '' === $marker || $context->selectorProjections->isButtonPresentationPath($path) ) {
                    continue;
                }
                $rewritten[] = $project($selector, $parsed, $marker, $element);
            }
        }
        return implode(',', array_values(array_unique($rewritten)));
    }

    /**
     * Geometry moved to a presentation wrapper must remain on other source
     * elements matched by the same shared selector.
     */
    private function withoutButtonPresentationProjectionSelectors(string $projectedPrelude, string $wrapperPrelude): string
    {
        $selectors = CssStylesheetTransformer::splitSelectorList($projectedPrelude);
        if ( null === $selectors || '' === $wrapperPrelude ) {
            return $projectedPrelude;
        }
        preg_match_all('/:where\(\.([^)]*)\)/', $wrapperPrelude, $matches);
        $markers = array_unique($matches[1] ?? array());
        if ( array() === $markers ) {
            return $projectedPrelude;
        }
        return implode(',', array_filter($selectors, static function (string $selector) use ($markers): bool {
            foreach ( $markers as $marker ) {
                $markerSelector = ':where(.' . $marker . ')';
                if ( str_contains($selector, $markerSelector) && ! str_contains($selector, ':not(' . $markerSelector . ')') ) {
                    return false;
                }
            }
            return true;
        }));
    }

    private function withButtonWrapperInnerFill(string $wrapperPrelude, string $layoutCss, string $rest, bool $clearInnerAutoHeight, AuthorStylesheetProjectionContext $context): string
    {
        $css = $wrapperPrelude . '{' . $layoutCss . '}';
        // A minimum width only floors a shrink-to-fit button; it is not a width to fill.
        $widths = array( 'width' => '', 'min-width' => '' );
        foreach ( CssValueSplitter::splitTopLevel($layoutCss, array( ';' )) as $declaration ) {
            $name = strtolower(trim((string) strtok($declaration, ':')));
            if ( array_key_exists($name, $widths) ) {
                $widths[ $name ] .= $name . ':' . substr($declaration, strpos($declaration, ':') + 1) . ';';
            }
        }
        $hasDefiniteWidth = CssValueInspector::hasDefiniteWidth($widths['width']);
        $hasMinimumWidth = ! $hasDefiniteWidth && CssValueInspector::hasDefiniteWidth($widths['min-width']);
        $hasDefiniteHeight = CssValueInspector::hasDefiniteHeight($layoutCss);
        $hasAutoHeight = CssValueInspector::hasAutoHeight($layoutCss);
        $hasMinimumHeight = CssValueInspector::hasAuthoredMinimumHeight($layoutCss);
        if ( $hasDefiniteWidth || $hasMinimumWidth || $hasDefiniteHeight || $hasAutoHeight || $hasMinimumHeight ) {
            $selectors = CssStylesheetTransformer::splitSelectorList($wrapperPrelude) ?? array( $wrapperPrelude );
            if ( str_contains($wrapperPrelude, '.wp-block-button__link)') ) {
                return $css . $rest;
            }
            $targetsButtonBox = str_contains($wrapperPrelude, '.wp-block-button)') && ! str_contains($wrapperPrelude, '.wp-block-buttons)');
            $linkTarget = $this->buttonLinkTarget($context);
            if ( $targetsButtonBox ) {
                $button = implode(',', array_map(static fn (string $selector): string => rtrim($selector), $selectors));
                $link = implode(',', array_map(static fn (string $selector): string => rtrim($selector) . $linkTarget, $selectors));
            } else {
                $button = implode(',', array_map(static fn (string $selector): string => rtrim($selector) . '> :where(.wp-block-button)', $selectors));
                $link = implode(',', array_map(static fn (string $selector): string => rtrim($selector) . '> :where(.wp-block-button)' . $linkTarget, $selectors));
            }
            if ( $hasDefiniteWidth ) {
                $css .= $button . '{width:100%}'
                    . $link . '{width:100%;max-width:100%}';
            }
            if ( $hasMinimumWidth ) {
                $css .= ($targetsButtonBox ? '' : $button . '{min-width:inherit}')
                    . $link . '{min-width:inherit}';
            }
            if ( $hasDefiniteHeight ) {
                $css .= $button . '{height:100%}'
                    . $link . '{height:100%}';
            } elseif ( $hasAutoHeight && $clearInnerAutoHeight ) {
                $css .= $button . '{height:auto}'
                    . $link . '{height:auto}';
            }
            if ( $hasMinimumHeight ) {
                // Percentage heights cannot resolve through an auto-height wrapper.
                // Inherit the wrapper's authored computed minimum on both carriers.
                $css .= $button . '{min-height:inherit}'
                    . $link . '{min-height:inherit}';
            }
        }
        return $css . $rest;
    }

    private function collapsedButtonKeywordWidthDeclarations(string $body): string
    {
        $collapsed = array();
        foreach ( CssValueSplitter::splitTopLevel($body, array( ';' )) as $declaration ) {
            $colon = strpos($declaration, ':');
            $name = strtolower(trim(false === $colon ? $declaration : substr($declaration, 0, $colon)));
            $value = false === $colon ? '' : trim(substr($declaration, $colon + 1));
            if ( false !== $colon && $this->buttonDeclarationRouting->isCollapsedButtonKeywordWidth($name, $value) ) {
                $collapsed[] = $declaration;
            }
        }
        return implode(';', $collapsed);
    }

    private static function canMatchSyntheticImageFigure(string $selector): bool
    {
        $compounds = CssSelectorMatcher::parse($selector)['compounds'] ?? array();
        $subject = end($compounds);
        if ( ! is_array($subject) ) {
            return true;
        }
        $type = strtolower((string) ($subject['type'] ?? ''));
        return '' === $type || in_array($type, array( 'figure', 'img' ), true);
    }

    /**
     * A source img's percentage transforms reference its own used image box.
     * The synthetic native figure may have zero height and a different origin;
     * carry placement to the recorded image leaf instead of that new wrapper.
     * Other source subjects (including authored figures) keep their own rules.
     *
     * @param array<string, string> $declarations
     * @return array{body: string, rules: string}
     */
    private function imageLeafPlacementProjection(string $prelude, string $body, array $declarations, string $imagePrelude, AuthorStylesheetProjectionContext $context): array
    {
        $placement = array_filter($declarations, NativeImageLeafPresentation::isPlacementProperty(...), ARRAY_FILTER_USE_KEY);
        if ( '' === $imagePrelude || array() === $placement ) {
            return array( 'body' => $body, 'rules' => '' );
        }
        $retained = array();
        foreach ( CssValueSplitter::splitTopLevel($body, array(';')) as $declaration ) {
            $property = strtolower(trim((string) strtok($declaration, ':')));
            if ( ! isset($placement[$property]) ) {
                $retained[] = $declaration;
            }
        }
        $original = CssStylesheetTransformer::splitSelectorList($this->rewriteSelectorPrelude($prelude, $context)) ?? array();
        // Only an arm whose subject can be the synthetic figure (a figure,
        // an untyped compound, or an image whose identity moved to it) needs
        // the guard; a typed `body`/`p` subject never matches that wrapper.
        $original = array_map(static fn (string $selector): string => self::canMatchSyntheticImageFigure($selector)
            ? rtrim($selector) . ':not(:where(.' . SourceBlockAttributeProjector::SYNTHETIC_IMAGE_FIGURE_CLASS . '))'
            : rtrim($selector), $original);
        $leaves = array();
        foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector ) {
            $parsed = $context->sourceStyles->parsedSelector($selector);
            $projected = $this->projectAuthorImageSelectorPrelude($selector, $context);
            foreach ( CssStylesheetTransformer::splitSelectorList($projected) ?? array() as $leaf ) {
                // Native transport contributes no weight. Dynamic state stays
                // inside the target gate, so restore its authored weight too.
                $leaves[] = ':where(' . $leaf . ')' . $this->selectorSpecificityShims($parsed, $context, true);
            }
        }
        $placed = $this->styleResolver->cssDeclarationString($placement);
        return array(
            'body' => implode(';', $retained),
            'rules' => implode(',', $original) . '{' . $placed . '}' . implode(',', $leaves) . '{' . $placed . '}',
        );
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

    private function rewriteSelectorPrelude(string $prelude, AuthorStylesheetProjectionContext $context, bool $controlWrapper = false, bool $subjectArms = false, bool $semanticSubjectArms = false): string
    {
        $projected = $this->rewriteSelectorPreludeOnce($prelude, $context, $controlWrapper, $subjectArms, $semanticSubjectArms);
        if ( ! $context->selectorProjections->hasAttributeStateProjectionConditions() ) {
            return $projected;
        }
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors ) {
            return $projected;
        }
        $variants = array();
        foreach ( $selectors as $selector ) {
            $conditions = $context->selectorProjections->attributeStateProjectionConditions(trim($selector));
            if ( array() === $conditions ) {
                continue;
            }
            // Derive the class form from this selector's own projection so it
            // inherits every per-element safeguard (superseded toggles,
            // rich-text exclusions, control and editor variants).
            $own = 1 === count($selectors) ? $projected : $this->rewriteSelectorPreludeOnce($selector, $context, $controlWrapper, $subjectArms, $semanticSubjectArms);
            foreach ( CssStylesheetTransformer::splitSelectorList($own) ?? array() as $ownSelector ) {
                $variant = $ownSelector;
                foreach ( $conditions as $condition => $marker ) {
                    $variant = str_replace($condition, '.' . $marker, $variant);
                }
                if ( $variant !== $ownSelector ) {
                    $variants[] = trim($variant);
                }
            }
        }
        if ( array() === $variants ) {
            return $projected;
        }
        return ( '' === trim($projected) ? '' : $projected . ',' ) . implode(',', array_values(array_unique($variants)));
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
    private function rewriteSelectorPreludeOnce(string $prelude, AuthorStylesheetProjectionContext $context, bool $controlWrapper = false, bool $subjectArms = false, bool $semanticSubjectArms = false): string
    {
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors ) {
            return $prelude;
        }
        $rewritten = array();
        foreach ( $selectors as $selector ) {
            if ( $context->selectorProjections->retainsRuntimeOwnedSelector($selector)
                || $this->isNativeCoreNavigationSelector($selector, $context) ) {
                $rewritten[] = trim($selector);
                continue;
            }
            // Arms for retained control descendants reached through the
            // control's own source identity. The selector's other
            // projections still follow.
            array_push($rewritten, ...$this->projectRetainedControlRootSelector($selector, $context));
            $socialAnchor = SocialAnchorSelectorProjector::project($selector, $context);
            if (null !== $socialAnchor) {
                $rewritten[] = $socialAnchor;
                continue;
            }
            $structuralParsed = $context->sourceStyles->parsedSelector($selector);
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
                    $rewritten[] = ':where(body)' . trim($selector);
                    continue;
                }
            }
            if ( $structuralParsed['supported'] && $this->hasUniversalStructuralLeaf($structuralParsed) ) {
                $rewritten[] = $this->rewriteSourceTagTypes($selector, $structuralParsed, $context);
                continue;
            }
            $runtimeProjection = $this->projectRuntimeAttributeSelector($selector, $context);
            if ( null !== $runtimeProjection ) {
                array_push($rewritten, ...$runtimeProjection);
                continue;
            }
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
                foreach ($targets as $target) {
                    $rewritten[] = $target . $host['suffix'];
                }
                if (array() !== $targets) continue;
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
            // Attribute carriers are keyed by the authored selector. Resolve the
            // complete captured predicate before rewriting a negation to its
            // live state class, which would change that identity.
            $parsed = $context->sourceStyles->parsedSelector($selector);
            if ($parsed['supported'] && str_contains($selector, ':not(')) {
                $sourceMatches = $this->matchingSourceElements($selector, $parsed, $context);
                if (array() !== $sourceMatches) {
                    $attributeProjection = $this->projectSourceAttributeAncestrySelector($selector, $parsed, $sourceMatches, $context, $controlWrapper)
                        ?? $this->projectSourceAttributeSelector($selector, $parsed, $sourceMatches, $context, $controlWrapper);
                    if (null !== $attributeProjection) {
                        array_push($rewritten, ...$attributeProjection);
                        continue;
                    }
                }
            }
            $selector = $this->projectSourceAttributeNegationStateSelector($selector, $context);
            $parsed = $context->sourceStyles->parsedSelector($selector);
            if ( ! $parsed['supported'] ) {
                $projectedControls = $this->projectUnsupportedFunctionalControlSelector($selector, $context, $controlWrapper);
                if ( array() === $projectedControls ) {
                    $rewritten[] = $this->rewriteSourceTagTypes($this->projectImageLinkIdentitySelector($selector, $context), $parsed, $context);
                } else {
                    array_push($rewritten, ...$projectedControls);
                }
                continue;
            }
            $matches = $this->matchingSourceElements($selector, $parsed, $context);
            foreach ($matches as $element) {
                if ($context->selectorProjections->isRetainedAuthoredControl($element->getNodePath() ?? '')
                    && !($parsed['compounds'][count($parsed['compounds']) - 1]['universal'] ?? false)) {
                    $rewritten[] = $this->rewriteSourceTagTypes($selector, $parsed, $context);
                    break;
                }
            }
            if ( array() === $matches ) {
                $dormantControls = $this->projectDormantAncestorControlSelector($selector, $parsed, $context, $controlWrapper, $subjectArms);
                if ( array() !== $dormantControls ) {
                    array_push($rewritten, ...$dormantControls);
                    continue;
                }
                $rewritten[] = $this->rewriteSourceTagTypes($selector, $parsed, $context, self::sourceResolvedSiblingExclusion($parsed));
                continue;
            }

            // Some lowering paths retain the source leaf inside a paragraph
            // without marking it as a potential standalone inline carrier.
            // The emitted tree is authoritative: add a zero-specificity hop
            // only when that topology actually exists, keeping the source arm
            // for matches that were not wrapped.
            $carrierChild = $this->projectParagraphCarrierChildSelector($selector, $parsed, $context);
            if ( null !== $carrierChild ) {
                $rewritten[] = $carrierChild;
            }

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
            array_push($rewritten, ...array_keys($navigationSubjects));
            if (array() === $otherSubjects) continue;
            $matches = $otherSubjects;

            // Native blocks that cannot store source classes on an inner part
            // (core/search input/button) register that part as the subject;
            // project onto it with the same specificity/state bridge as
            // button lowering.
            $remainingSubjects = array();
            foreach ( $matches as $element ) {
                $inner = $context->selectorProjections->innerSubject($element->getNodePath() ?? '');
                if ( null === $inner ) {
                    $remainingSubjects[] = $element;
                    continue;
                }
                $suffix = null === $parsed['pseudo_state_suffix_span'] ? '' : substr($selector, $parsed['pseudo_state_suffix_span']['start']);
                if ($inner['author_precedence']) {
                    $rewritten[] = $this->rewriteSourceTagTypes($selector, $parsed, $context);
                }
                // A constant baseline beats native defaults without flattening
                // source specificity, states, conditional groups or layer order.
                $baseline = $inner['author_precedence']
                    ? str_repeat(':not(.' . $context->authorStyles->classSpecificityShim() . ')', 4)
                    : '';
                $rewritten[] = $inner['selector'] . $baseline . $this->selectorSpecificityShims($parsed, $context) . $suffix . $inner['pseudo_element'];
            }
            if ( array() === $remainingSubjects ) continue;
            $matches = $remainingSubjects;

            $attributeAncestryProjection = $this->projectSourceAttributeAncestrySelector($selector, $parsed, $matches, $context, $controlWrapper);
            if ( null !== $attributeAncestryProjection ) {
                array_push($rewritten, ...$attributeAncestryProjection);
                continue;
            }
            $attributeProjection = $this->projectSourceAttributeSelector($selector, $parsed, $matches, $context, $controlWrapper);
            if ( null !== $attributeProjection ) {
                array_push($rewritten, ...$attributeProjection);
                continue;
            }
            if ( AuthorSelectorSemanticPreparer::isRootChildSelector($parsed) ) {
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
                    $rewritten[] = $selector;
                    continue;
                }
                foreach ( $markers as $marker ) {
                    $rewritten[] = $this->projectSemanticLeafSelector($selector, $parsed, $marker, $context);
                }
                foreach ( $shellTags as $tag ) {
                    // A shell part's landmark group is the box and keeps the
                    // authored selector's projection. The editor still nests
                    // it in Core's template-part transport wrapper.
                    $rewritten[] = ':root .editor-styles-wrapper :where(.wp-block-template-part) > ' . $tag . $this->selectorSpecificityShims($parsed, $context);
                }
                continue;
            }

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
            foreach ( array_values(array_unique($tableDescendants)) as $projected ) {
                $rewritten[] = $projected;
            }
            if ( array() === $nonTableMatches ) {
                continue;
            }
            $matches = $nonTableMatches;

            // core/image saves the source image's classes and id on its
            // <figure>, never on the <img>. A subject that names the image
            // through them has nothing left to match, so it follows them to
            // the figure: the box the image bridge already fills.
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
            foreach ( array_values(array_unique($figureSubjects)) as $figureSubject ) {
                $rewritten[] = $figureSubject;
            }
            if ( array() === $otherMatches ) {
                continue;
            }
            $matches = $otherMatches;

            // Every match is a direct anchor core re-parents into a list item of
            // its own, so the subject's sibling position belongs to that item.
            // Any other match, or a subject this projection cannot place, keeps
            // the authored selector exactly.
            if ( $this->matchesOnlyNavigationRole($matches, AuthorSelectorProjectionState::NAVIGATION_ANCHOR, $context) ) {
                $itemSelector = $this->projectNavigationItemAnchorSelector($selector, $parsed, $context);
                if ( null !== $itemSelector ) {
                    $rewritten[] = $itemSelector;
                    continue;
                }
            }
            // Every match is a source list item core renders as a navigation
            // item of its own. The source-type marker never reaches that
            // rendered item, so the subject moves onto core's item class.
            if ( $this->matchesOnlyNavigationRole($matches, AuthorSelectorProjectionState::NAVIGATION_ITEM, $context) ) {
                $itemSelector = $this->projectNavigationListItemSelector($selector, $parsed, $context);
                if ( null !== $itemSelector ) {
                    $context->bindings->recordNativeSelector($selector);
                    $rewritten[] = $itemSelector;
                    continue;
                }
            }
            // Every match is a source list that is itself the element a
            // core/navigation block stands in for. Its classes and id sit on
            // the rendered `<nav>` (and on the inner list copy); the list type
            // has to address that block rather than the copy.
            if ( $this->matchesOnlyNavigationRole($matches, AuthorSelectorProjectionState::NAVIGATION_LIST_HOST, $context) ) {
                $hostSelector = $this->projectNavigationListHostSelector($selector, $parsed, $context, $matches);
                if ( null !== $hostSelector ) {
                    $rewritten[] = $hostSelector;
                    continue;
                }
            }

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
                        : ':where(.' . self::SUPERSEDED_MENU_TOGGLE_CLASS . ')';
                }
                $rewritten[] = $this->rewriteSourceTagTypes($selector, $parsed, $context, $insertion . self::sourceResolvedSiblingExclusion($parsed));
                continue;
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
            if ( $inlineLayoutCarriers && $this->matchesEmittedMarkup($parsed, $context->emittedMarkup) ) {
                $hasNonProjected = true;
            }
            if ( $hasNonProjected ) {
                // Control, semantic and RichText carriers also carry their
                // kind's role class, so the authored arm stays off all of them
                // through one constant class per kind instead of a list of
                // per-element markers that differs on every page.
                $exclusion = array_map(static fn (string $roleClass): string => '.' . $roleClass, EngineMarker::roleClasses($projectedMarkers));
                $rewritten[] = $this->rewriteSourceTagTypes(
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
                $rewritten[] = $this->projectControlTarget($controlSubject['target'], $selector, $parsed, $context, false);
            }
            foreach ( null === $controlSubject ? $controls : $controlSubject['rest'] as $marker ) {
                $rewritten[] = $this->projectControlSelector($selector, $parsed, $marker, $context, $controlWrapper && ! isset($linkOwnedControls[$marker]));
            }
            // Semantic arms take one shape whatever the rule declares, so they
            // only need the subject to be context-free.
            $semanticSubject = $semanticSubjectArms && array() !== $semanticLeaves
                ? $this->contextFreeSubject('semantic', $parsed, $semanticLeaves, $context)
                : null;
            if ( null !== $semanticSubject ) {
                $rewritten[] = $this->projectSemanticTarget($semanticSubject['target'], $selector, $parsed, $context);
            }
            foreach ( null === $semanticSubject ? $semanticLeaves : $semanticSubject['rest'] as $marker ) {
                $rewritten[] = $this->projectSemanticLeafSelector($selector, $parsed, $marker, $context);
            }
            // RichText arms take one shape too; a carrier the subject arm does
            // not cover keeps its per-element attribute arm.
            $richTextSubject = $semanticSubjectArms && array() !== $richTextLeaves
                ? $this->contextFreeSubject('richtext', $parsed, $richTextLeaves, $context)
                : null;
            if ( null !== $richTextSubject ) {
                $rewritten[] = $this->projectSemanticTarget($richTextSubject['target'], $selector, $parsed, $context);
            }
            foreach ( null === $richTextSubject ? $richTextLeaves : $richTextSubject['rest'] as $marker ) {
                $rewritten[] = $this->projectRichTextSemanticSelector($selector, $parsed, $marker, $context);
            }
            if ( $inlineLayoutCarriers ) {
                $rewritten[] = $this->projectInlineLayoutCarrierSelector($selector, $parsed, $addressableInlineCarriers);
            }
        }
        $projected = implode(',', array_values(array_unique($rewritten)));
        if ( $projected !== $prelude && EngineMarker::matchesAny($projected) ) {
            $context->bindings->record($prelude, $projected);
        }

        return $projected;
    }

    private const SEMANTIC_ROLE_CLASS = 'blocks-engine-role-semantic';

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
    private function projectUnsupportedFunctionalControlSelector(
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

    /**
     * Re-state document-root presentation onto the editor canvas.
     *
     * Body-subject rules receive the canvas scope: the editor establishes an
     * explicit canvas presentation context, so authored body declarations
     * must target that body directly to keep speaking there. Rules whose
     * subject is the document root itself (`html`, `:root`) reach the canvas
     * through a restatement, because the editor iframe's root does not carry
     * the source identity until the canvas runtime reconciles it. That
     * restatement never speaks over a body-level style: inherited
     * presentation restates wholly inside `:where()`, weighing (0,0,0), so it
     * stays the inherited default that any body rule — the source's own
     * body rule or theme.json body typography — outranks, while root-owned
     * custom properties are tokens no body-level style competes with and
     * carry on the canvas-body carrier the reconciled canvas root
     * satisfies.
     */
    private function editorDocumentRootRule(string $prelude, string $body, AuthorStylesheetProjectionContext $context): string
    {
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        $bodySelectors = $rootSelectors = array();
        $root = $context->authorStyles->sourceBody()->ownerDocument?->documentElement;
        foreach ($selectors ?? array() as $selector) {
            $parsed = $context->sourceStyles->parsedSelector($selector);
            if (!($parsed['supported'] ?? false) || null !== ($parsed['pseudo_state_suffix_span'] ?? null)) continue;
            if (1 === count($parsed['compounds'] ?? array()) && (
                'body' === strtolower((string) ($parsed['compounds'][0]['type'] ?? ''))
                || CssSelectorMatcher::matches($context->authorStyles->sourceBody(), $parsed)['matches']
            )) {
                // Keep the authored predicate and specificity. All body subject
                // rules receive the same editor-only scope, rather than letting
                // a lifted bare body reset beat a later body.class declaration.
                $sourceSelector = $this->projectSourceClassSelector($selector, $context);
                $sourceParsed = $context->sourceStyles->parsedSelector($sourceSelector);
                $end = (int) $sourceParsed['rightmost_rewrite_end'];
                $bodySelectors[] = ':root ' . substr($sourceSelector, 0, $end) . '.editor-styles-wrapper' . substr($sourceSelector, $end);
            } elseif ($root instanceof DOMElement && CssSelectorMatcher::matches($root, $parsed)['matches']) {
                $rootSelectors[] = $this->projectSourceClassSelector($selector, $context);
            }
        }
        $bodySelectors = array_values(array_unique($bodySelectors));
        $rootSelectors = array_values(array_unique($rootSelectors));

        // Gutenberg establishes an explicit canvas presentation context, so
        // document-level body inheritance cannot otherwise win in the editor.
        $inheritedProperties = array(
            'color', 'direction', 'hyphens', 'letter-spacing', 'line-height',
            'tab-size', 'text-align', 'text-indent', 'text-shadow', 'text-transform',
            'visibility', 'white-space', 'word-spacing', 'writing-mode',
        );
        $declarations = array_filter(
            SourceDeclarations::parse($body),
            static fn (string $name): bool => str_starts_with($name, '--')
                || 'font' === $name
                || str_starts_with($name, 'font-')
                || in_array($name, $inheritedProperties, true),
            ARRAY_FILTER_USE_KEY
        );
        if (array() === $declarations) {
            return '';
        }

        $css = array() === $bodySelectors ? '' : implode(',', $bodySelectors) . '{' . $this->styleResolver->cssDeclarationString($declarations) . '}';
        if (array() === $rootSelectors) {
            return $css;
        }

        // The root restatement carries disjoint declarations on two arms.
        // Presentation restates wholly inside :where() and weighs (0,0,0): it
        // acts as the inherited default and every body-level rule — theme.json
        // body typography or the source's own body rule — outranks it, exactly
        // as it does on the frontend where the declaration stays on the root.
        // Custom properties are route-scoped tokens rather than presentation,
        // so they carry on the canvas-body carrier without any body-level
        // style to outrank.
        $tokens = array_filter(
            $declarations,
            static fn (string $name): bool => str_starts_with($name, '--'),
            ARRAY_FILTER_USE_KEY
        );
        $default = $this->styleResolver->cssDeclarationString(array_diff_key($declarations, $tokens));
        $carrier = $this->styleResolver->cssDeclarationString($tokens);
        $carrierSelectors = implode(',', $rootSelectors);
        $defaultSelectors = implode(',', array_map(
            static fn (string $selector): string => $selector . ' .editor-styles-wrapper',
            $rootSelectors
        ));
        return $css
            . ('' === $default ? '' : ':where(' . $defaultSelectors . '){' . $default . '}')
            . ('' === $carrier ? '' : ':where(' . $carrierSelectors . ') .editor-styles-wrapper{' . $carrier . '}');
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

    /** @param array<string, mixed> $parsed */
    private function sourceAttributeMarker(string $selector, array $parsed, DOMElement $element, AuthorStylesheetProjectionContext $context): string
    {
        $rightmost = CssSelectorCompoundInspector::rightmost($parsed['compounds'] ?? array());
        if ( null !== $parsed['pseudo_state_suffix_span']
            || ! CssSelectorCompoundInspector::containsDataAttribute($rightmost)
            || CssSelectorCompoundInspector::hasLiveAttributePredicate($rightmost)
        ) {
            return '';
        }
        return $context->selectorProjections->attributeMarker($element->getNodePath() ?? '', $selector);
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
        $attributeTarget = in_array(true, $controlOwned, true) ? ':not(.wp-block-buttons):not(.wp-block-button)' : '';
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

    /** @param array<string, string> $declarations */
    private function projectAuthorImageSelectorPrelude(string $prelude, AuthorStylesheetProjectionContext $context, string $tagName = 'img', array $declarations = array(), bool $flexBox = false): string
    {
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors ) {
            return '';
        }
        $projected = array();
        foreach ( $selectors as $selector ) {
            $parsed = $context->sourceStyles->parsedSelector($selector);
            if ( ! $parsed['supported'] ) {
                continue;
            }
            $matches = $this->matchingSourceElements($selector, $parsed, $context);
            $imageMatches = array_values(array_filter($matches, fn (DOMElement $element): bool =>
                $tagName === strtolower($element->tagName)
                && '' === ($context->selectorProjections->imageFigure($element->getNodePath() ?? '')['leafMarker'] ?? '')
                && ('svg' !== $tagName
                    || ($flexBox && $this->styleResolver->imageIsFlexOrGridItem($element))
                    || $this->isProjectableFillSvg($element, $declarations)
                    || (! $flexBox
                        && ! $context->selectorProjections->isSupersededControlPath($element->getNodePath() ?? '')
                        && array() !== $this->imageApplicableSvgDeclarations($declarations)))
            ));
            if ( array() === $imageMatches ) {
                continue;
            }
            if ( AuthorSelectorSemanticPreparer::isRootChildSelector($parsed) ) {
                foreach ( $imageMatches as $element ) {
                    $marker = $context->selectorProjections->rootChildMarker($element->getNodePath() ?? '');
                    if ( '' !== $marker ) {
                        $projected[] = NativeImageLeafPresentation::selectors($this->projectSemanticLeafSelector($selector, $parsed, $marker, $context) . '.wp-block-image');
                    }
                }
                continue;
            }
            // Only the existing fill/flex paths need a synthetic wrapper box.
            // Ordinary SVG presentation belongs on the replaced image itself.
            if ( 'svg' === $tagName && ($flexBox || $this->isProjectableFillSvg($imageMatches[0], $declarations)) ) {
                $this->pushScopedImageArm($projected, $selector, $this->projectImageSelector($selector, $parsed, $context, true), $context);
            }
            $mediaTextImages = array_values(array_filter(
                $imageMatches,
                fn (DOMElement $element): bool => $tagName === 'img'
                    && $this->isMediaTextImage($element)
                    && '' !== $this->mediaTextImageMarkerForElement($element, $context)
            ));
            foreach ( $mediaTextImages as $element ) {
                $projected[] = $this->projectImageSelector(
                    $selector,
                    $parsed,
                    $context,
                    false,
                    true,
                    $this->mediaTextImageMarkerForElement($element, $context)
                );
            }
            $ordinaryImages = array_values(array_filter(
                $imageMatches,
                fn (DOMElement $element): bool => ! in_array($element, $mediaTextImages, true)
            ));
            $hasUnmarkedImage = false;
            foreach ( $ordinaryImages as $element ) {
                $marker = $context->selectorProjections->imageWrapperMarker($element->getNodePath() ?? '');
                if ( '' === $marker ) {
                    // The image's figure stands in for the paragraph or heading
                    // that held it, so ancestry naming that element ends at the
                    // figure: reach the leaf through the figure's marker.
                    $marker = $context->selectorProjections->unwrappedImageCarrierMarker($element->getNodePath() ?? '');
                }
                if ( '' === $marker ) {
                    // A static data predicate is not carried to the native image;
                    // its attribute marker moves to the figure, so reach the leaf
                    // through that marker, as the figure's own box rule does.
                    $marker = $this->sourceAttributeMarker($selector, $parsed, $element, $context);
                }
                if ( '' === $marker ) {
                    $hasUnmarkedImage = true;
                    continue;
                }
                // The source link's ID now belongs to the native figure. A
                // descendant selector cannot retain that former ancestry.
                $suffix = null === $parsed['pseudo_state_suffix_span'] ? '' : substr($selector, $parsed['pseudo_state_suffix_span']['start']);
                $projected[] = NativeImageLeafPresentation::selectors(':where(.' . $marker . ').wp-block-image', $this->selectorSpecificityShims($parsed, $context) . $suffix);
            }
            if ( $hasUnmarkedImage ) {
                $this->pushScopedImageArm($projected, $selector, $this->projectImageSelector($selector, $parsed, $context), $context);
            }
        }
        return implode(',', array_values(array_unique($projected)));
    }

    /**
     * Emits a projected image-rule arm only when every core block class it
     * names is reached through an importer-emitted class as well. An arm that
     * would stand on the bare core class is dropped and recorded instead of
     * leaking onto blocks a host adds to the served page later. Ordinary
     * image arms carry a zero-specificity factory scope, not a semantic marker.
     *
     * @param list<string> $projected
     */
    private function pushScopedImageArm(array &$projected, string $authoredSelector, string $arm, AuthorStylesheetProjectionContext $context): void
    {
        if ( '' === $arm ) {
            return;
        }
        if ( ! (new CoreSelectorScoping())->selectorIsScoped($arm) ) {
            $context->evidence->recordUnscopedCoreSelectorDrop($authoredSelector, 'no_importer_class_for_core_image_projection');
            return;
        }
        $projected[] = $arm;
    }

    private function projectMediaTextImagePrelude(string $prelude, AuthorStylesheetProjectionContext $context): string
    {
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors ) {
            return '';
        }

        $projected = array();
        foreach ( $selectors as $selector ) {
            $parsed = $context->sourceStyles->parsedSelector($selector);
            $matches = $parsed['supported']
                ? $this->matchingSourceElements($selector, $parsed, $context)
                : $this->matchingSimpleClassImages($selector, $context);
            if ( array() === $matches || count(array_filter($matches, fn (DOMElement $element): bool => $this->isMediaTextImage($element))) !== count($matches) ) {
                continue;
            }
            foreach ( $matches as $element ) {
                $marker = $this->mediaTextImageMarkerForElement($element, $context);
                if ( '' === $marker ) {
                    continue;
                }
                $projected[] = $parsed['supported']
                    ? $this->projectImageSelector($selector, $parsed, $context, false, true, $marker)
                    : NativeImageLeafPresentation::selectors(':where(.' . $marker . ') .wp-block-media-text__media');
            }
        }

        return implode(',', array_values(array_unique($projected)));
    }

    /** @return array<int, DOMElement> */
    private function matchingSimpleClassImages(string $selector, AuthorStylesheetProjectionContext $context): array
    {
        if ( 1 !== preg_match('/^\.([A-Za-z0-9_-]+\[[^\]]+\])$/', trim($selector), $match) ) {
            return array();
        }
        $matches = array();
        foreach ( $context->authorStyles->sourceBody()->getElementsByTagName('img') as $image ) {
            if ( $image instanceof DOMElement && SourceDom::hasClass($image, $match[1]) ) {
                $matches[] = $image;
            }
        }
        return $matches;
    }

    private function isMediaTextImage(DOMElement $element): bool
    {
        if ( 'img' !== strtolower($element->tagName) ) {
            return false;
        }
        for ( $parent = $element->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) {
            $declarations = SourceDeclarations::parse($this->styleResolver->mediaTextPresentationStyle($parent));
            if ( in_array(CssValueInspector::declared($declarations, 'display'), array( 'flex', 'grid' ), true)
                && $this->hasMediaTextBranches($parent, $element) ) {
                return true;
            }
            if ( 'body' === strtolower($parent->tagName) ) {
                break;
            }
        }
        return false;
    }

    private function mediaTextImageMarkerForElement(DOMElement $element, AuthorStylesheetProjectionContext $context): string
    {
        for ( $node = $element; $node instanceof DOMElement; $node = $node->parentNode ) {
            $marker = $context->selectorProjections->mediaTextImageMarker($node->getNodePath() ?? '');
            if ( '' !== $marker ) {
                return $marker;
            }
        }

        return '';
    }

    private function hasMediaTextBranches(DOMElement $container, DOMElement $image): bool
    {
        $hasImageBranch = false;
        $hasTextBranch = false;
        $branchCount = 0;
        foreach ( $container->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }
            ++$branchCount;
            $containsImage = $child === $image || in_array($image, iterator_to_array($child->getElementsByTagName('img')), true);
            $hasImageBranch = $hasImageBranch || $containsImage;
            $hasTextBranch = $hasTextBranch || ( ! $containsImage && '' !== trim($child->textContent ?? '') );
        }
        return $branchCount >= 2 && $hasImageBranch && $hasTextBranch;
    }

    /**
     * Only the outer replaced box survives SVG materialization. Paint such as
     * fill/stroke still belongs to the asset, not the generated image.
     *
     * @param array<string, string> $declarations
     * @return array<string, string>
     */
    private function imageApplicableSvgDeclarations(array $declarations): array
    {
        return array_filter($declarations, static fn (string $property): bool =>
            NativeImageLeafPresentation::isBoxProperty($property)
            || NativeImageLeafPresentation::isPlacementProperty($property)
            || in_array($property, array('display', 'filter', 'opacity', 'object-fit', 'object-position', 'visibility'), true),
            ARRAY_FILTER_USE_KEY
        );
    }

    /** @param array<string, string> $declarations */
    private function isExplicitParentFillSvg(DOMElement $element, array $declarations): bool
    {
        if ( '' === $this->explicitObjectFit($declarations)
            || '100%' !== trim((string) ($declarations['width'] ?? ''))
            || '100%' !== trim((string) ($declarations['height'] ?? ''))
        ) {
            return false;
        }
        $parent = $element->parentNode;
        if ( ! $parent instanceof DOMElement ) {
            return false;
        }
        $parentStyle = $this->styleResolver->structuralPresentationDeclarations($parent);
        if ( ! in_array(strtolower(trim((string) ($parentStyle['position'] ?? ''))), array( 'absolute', 'fixed' ), true) ) {
            return false;
        }
        return isset($parentStyle['inset']) && '' !== trim((string) $parentStyle['inset']);
    }

    /** @param array<string, string> $declarations */
    private function isProjectableFillSvg(DOMElement $element, array $declarations): bool
    {
        if ( $this->isExplicitParentFillSvg($element, $declarations) ) {
            return true;
        }
        if ( ! $this->svgFillDimension($element, $declarations, 'width') || ! $this->svgFillDimension($element, $declarations, 'height') ) {
            return false;
        }

        $preserveAspectRatio = trim($element->getAttribute('preserveaspectratio'));
        return 'none' === strtolower($preserveAspectRatio)
            || (bool) preg_match('/(?:^|\s)(?:defer\s+)?x(?:min|mid|max)y(?:min|mid|max)\s+slice(?:\s|$)/i', $preserveAspectRatio);
    }

    /** @param array<string, string> $declarations */
    private function svgFillDimension(DOMElement $element, array $declarations, string $dimension): bool
    {
        return '100%' === trim($this->styleResolver->resolveCssVariablesInValue((string) ($declarations[$dimension] ?? ''), $element));
    }

    /** @param array<string, string> $declarations */
    private function explicitObjectFit(array $declarations): string
    {
        $objectFit = strtolower(trim((string) ($declarations['object-fit'] ?? '')));
        return in_array($objectFit, array( 'contain', 'cover', 'fill', 'none', 'scale-down' ), true) ? $objectFit : '';
    }

    /**
     * A source flex/grid-item image keeps its core/image figure in the DOM but
     * with `display:contents` (see EngineSupportCss), so an author class on
     * that figure sizes no box. The generic bridge's `width:100%;height:100%`
     * then resolves against the flex container instead of the author's box.
     * Restate the author's own item-box declarations on the image itself, at
     * this rule's position and under its conditions, so a media-conditioned
     * size still takes over exactly where it did in the source (#2973).
     *
     * @param array<string, string> $declarations
     */
    private function flexItemImageBoxRule(string $prelude, string $imagePrelude, array $declarations, AuthorStylesheetProjectionContext $context): string
    {
        if ( '' === $imagePrelude || ! $this->selectsFlexItemImage($prelude, $context) ) {
            return '';
        }
        $box = array();
        $minimum = array();
        foreach ( $declarations as $property => $declaration ) {
            if ( ! NativeImageLeafPresentation::isBoxProperty($property) ) {
                continue;
            }
            $value = trim($declaration);
            if ( '' !== $value && 1 !== preg_match('~[{}<>;]|/\*~', $value) ) {
                $box[] = $property . ':' . $value;
                if ( in_array($property, array( 'min-width', 'min-height' ), true) ) {
                    $minimum[] = $property . ':' . $value;
                }
            }
        }
        if ( array() === $box ) {
            return '';
        }
        // A listing card's image becomes core/post-featured-image, whose
        // figure keeps the same classes but not `.wp-block-image`.
        $selectors = array();
        $linkSelectors = array();
        foreach ( CssValueSplitter::splitTopLevel($imagePrelude, array(',')) as $selector ) {
            foreach ( array( '.wp-block-image', '.wp-block-post-featured-image' ) as $figureClass ) {
                $selectors[] = $itemSelector = str_replace('.wp-block-image', $figureClass . ':where(.' . SourceBlockAttributeProjector::SYNTHETIC_FLEX_IMAGE_FIGURE_CLASS . ')', $selector);
                if ( 1 === preg_match('/^(.*>\s*a)\s*>\s*img$/s', trim($itemSelector), $link) ) {
                    $linkSelectors[] = $link[1];
                }
            }
        }
        $rule = implode(',', $selectors) . '{' . implode(';', $box) . '}';
        // A linked image's anchor, not the image, is the flex/grid item once
        // the figure is display:contents. Its automatic minimum size comes from
        // the image's natural size, so a `min-height:0` the author set to let
        // the item shrink into its track would stop working (#5286). Carry the
        // item's minimum size to the box that is now the item.
        if ( array() !== $minimum && array() !== $linkSelectors ) {
            $rule .= implode(',', $linkSelectors) . '{' . implode(';', $minimum) . '}';
        }

        return $rule;
    }

    private function selectsFlexItemImage(string $prelude, AuthorStylesheetProjectionContext $context): bool
    {
        foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector ) {
            $parsed = $context->sourceStyles->parsedSelector($selector);
            if ( ! $parsed['supported'] ) {
                continue;
            }
            foreach ( $this->matchingSourceElements($selector, $parsed, $context) as $element ) {
                if ( in_array(strtolower($element->tagName), array( 'img', 'svg' ), true) && $this->styleResolver->imageIsFlexOrGridItem($element) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * An image that sat on its source parent's line (the paragraph it
     * unwrapped from, or the inline wrapper its figure is transparent in)
     * stays on that line, so its margins and the strut keep sizing the same
     * box. Override the bridge's block display at the bridge's position; the
     * line alignment is the rule's own, or whatever earlier rule set it.
     *
     * @param array<string, string> $declarations
     */
    private function lineImageFigureRule(string $imagePrelude, array $declarations, AuthorStylesheetProjectionContext $context): string
    {
        $figures = array_filter(
            array( SourceBlockAttributeProjector::PARAGRAPH_LINE_BOX_IMAGE_FIGURE_CLASS, SourceBlockAttributeProjector::SYNTHETIC_INLINE_IMAGE_FIGURE_CLASS, SourceBlockAttributeProjector::INLINE_IMAGE_LEAF_CLASS ),
            static fn (string $class): bool => str_contains($context->emittedMarkup, $class)
        );
        if ( array() === $figures ) {
            return '';
        }

        return str_replace('.wp-block-image', '.wp-block-image:where(.' . implode(',.', $figures) . ')', $imagePrelude)
            . '{display:' . ($declarations['display'] ?? 'inline')
            . ( isset($declarations['vertical-align']) ? ';vertical-align:' . $declarations['vertical-align'] : '' ) . '}';
    }

    /**
     * The bridge lets the native image follow the figure that now carries the
     * source rule. A selector whose subject is a bare `img` has nothing that
     * moves to the figure, so the source rule still paints the image itself:
     * inheriting the figure's corners there would replace the image's own
     * radius at the same specificity, later in the cascade.
     *
     * @param array<string, string> $declarations
     */
    private function imageProjectionBridgeRule(string $prelude, string $imagePrelude, array $declarations, AuthorStylesheetProjectionContext $context): string
    {
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude) ?? array();
        $moved = array_values(array_filter($selectors, fn (string $selector): bool => ! $this->subjectStaysOnImage($selector, $context)));
        if ( count($moved) === count($selectors) ) {
            return $imagePrelude . '{' . $this->imageProjectionBridgeDeclarations($declarations) . '}';
        }
        $rule = $imagePrelude . '{' . $this->imageProjectionBridgeDeclarations($declarations, false, false) . '}';
        $movedPrelude = array() === $moved ? '' : $this->projectAuthorImageSelectorPrelude(implode(',', $moved), $context);

        return '' === $movedPrelude ? $rule : $rule . $movedPrelude . '{border-radius:inherit}';
    }

    private function subjectStaysOnImage(string $selector, AuthorStylesheetProjectionContext $context): bool
    {
        $parsed = $context->sourceStyles->parsedSelector($selector);
        if ( ! $parsed['supported'] ) {
            return false;
        }
        $subject = CssSelectorCompoundInspector::rightmost($parsed['compounds']);

        return 'img' === strtolower((string) ($subject['type'] ?? ''))
            && array() === ($subject['classes'] ?? array())
            && array() === ($subject['ids'] ?? array())
            && array() === ($subject['attributes'] ?? array());
    }

    /** @param array<string, string> $declarations */
    private function imageProjectionBridgeDeclarations(array $declarations, bool $preserveObjectFit = false, bool $inheritRadius = true): string
    {
        $bridge = array( 'display:block' );
        $position = strtolower(trim((string) ($declarations['position'] ?? '')));
        $width = strtolower(trim((string) ($declarations['width'] ?? '')));
        $height = strtolower(trim((string) ($declarations['height'] ?? '')));
        $ownsBox = ! in_array($width, array( '', 'auto' ), true) && ! in_array($height, array( '', 'auto' ), true);
        if ( $ownsBox || in_array($position, array( 'absolute', 'fixed' ), true) ) {
            $bridge[] = 'width:100%';
            $bridge[] = 'height:100%';
        }
        // Constraints and free axes belong to the replaced image, not its
        // synthetic figure. Keep them at the source rule's cascade position
        // instead of replaying a desktop winner in an important carrier.
        foreach (array('min-width', 'max-width', 'min-height', 'max-height', 'aspect-ratio') as $property) {
            if (isset($declarations[$property])) $bridge[] = $property . ':' . $declarations[$property];
        }
        // The responsive max-width default belongs to zero-specificity base
        // support, not this authored position: an unrelated later rule must
        // not reset a cap declared by an earlier rule.
        foreach (array('width', 'height') as $property) {
            if ('auto' === CssValueInspector::declared($declarations, $property)) {
                $bridge[] = $property . ':' . $declarations[$property];
            } elseif (! $ownsBox && ! in_array($position, array('absolute', 'fixed'), true) && isset($declarations[$property])) {
                // A responsive rule can change only one axis. The figure owns
                // that declaration; its leaf must follow the new value too.
                $bridge[] = $property . ':inherit';
            }
        }
        $objectFit = $this->explicitObjectFit($declarations);
        $bridge[] = 'object-fit:' . ($preserveObjectFit && '' !== $objectFit ? $objectFit : 'inherit');
        $bridge[] = 'object-position:inherit';
        if ( $inheritRadius ) {
            $bridge[] = 'border-radius:inherit';
        }
        return implode(';', $bridge);
    }

    /**
     * @param array<string, mixed> $parsed
     * @param array<int, array{end: int, value: string}> $replacements Further
     *        span replacements, keyed by start offset, that must not overlap a
     *        rewritten type span.
     */
    private function rewriteSourceTagTypes(string $selector, array $parsed, AuthorStylesheetProjectionContext $context, string $rightmostInsertion = '', array $replacements = array()): string
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
    private function projectSourceClassSelector(string $selector, AuthorStylesheetProjectionContext $context, array $replacements = array()): string
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
    private function projectControlSelector(string $selector, array $parsed, string $marker, AuthorStylesheetProjectionContext $context, bool $wrapper = false): string
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
    private function universalControlSubject(string $selector, array $parsed, AuthorStylesheetProjectionContext $context): ?array
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
    private function projectControlTarget(string $target, string $selector, array $parsed, AuthorStylesheetProjectionContext $context, bool $wrapper = false): string
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
    private function buttonLinkTarget(AuthorStylesheetProjectionContext $context): string
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
    private function contextFreeSubject(string $kind, array $parsed, array $routedMarkers, AuthorStylesheetProjectionContext $context): ?array
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
    private function projectButtonBoxSelector(string $selector, array $parsed, string $marker, AuthorStylesheetProjectionContext $context): string
    {
        $suffix = null === $parsed['pseudo_state_suffix_span'] ? '' : substr($selector, $parsed['pseudo_state_suffix_span']['start']);
        return ':where(.' . $marker . ')' . $this->selectorSpecificityShims($parsed, $context) . ':where(.wp-block-button)' . $suffix;
    }

    /** @param array<string, mixed> $parsed */
    private function projectSemanticLeafSelector(string $selector, array $parsed, string $marker, AuthorStylesheetProjectionContext $context): string
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
    private function projectRichTextSemanticSelector(string $selector, array $parsed, string $marker, AuthorStylesheetProjectionContext $context): string
    {
        $suffix = null === $parsed['pseudo_state_suffix_span'] ? '' : substr($selector, $parsed['pseudo_state_suffix_span']['start']);
        return ':where(' . RichTextMarkerSelector::carrierSelectorList($marker) . ')' . $this->selectorSpecificityShims($parsed, $context) . $suffix;
    }

    /** @param array<string, mixed> $parsed */
    private function projectParagraphCarrierChildSelector(string $selector, array $parsed, AuthorStylesheetProjectionContext $context, bool $itemSlot = false): ?string
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
        $classes = $itemSlot ? array(SourceBlockAttributeProjector::SYNTHETIC_PARAGRAPH_CLASS) : array(self::INLINE_LAYOUT_CARRIER_CLASS, SourceBlockAttributeProjector::SYNTHETIC_PARAGRAPH_CLASS);
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

    /**
     * Restate only non-cumulative item placement on the inserted paragraph.
     * Its source-box and synthetic-anchor variants share this carrier marker.
     * The leaf still owns its typography, paint and inner layout.
     *
     * @param array<string, string> $declarations
     */
    private function syntheticParagraphItemRule(string $prelude, array $declarations, AuthorStylesheetProjectionContext $context): string
    {
        $placement = array_filter($declarations, static fn (string $property): bool => in_array($property, array('flex', 'flex-grow', 'flex-shrink', 'flex-basis', 'align-self', 'justify-self', 'place-self', 'order', 'grid-area', 'grid-row', 'grid-row-start', 'grid-row-end', 'grid-column', 'grid-column-start', 'grid-column-end'), true), ARRAY_FILTER_USE_KEY);
        if ( array() === $placement ) return '';
        $targets = array();
        foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector ) {
            $parsed = $context->sourceStyles->parsedSelector($selector);
            if ( ! $parsed['supported'] || array() === $this->matchingSourceElements($selector, $parsed, $context) ) continue;
            $target = $this->projectParagraphCarrierChildSelector($selector, $parsed, $context, true);
            if ( null !== $target ) $targets[] = $this->rewriteSourceTagTypes($target, CssSelectorMatcher::parse($target), $context);
        }
        return array() === $targets ? '' : implode(',', array_unique($targets)) . '{' . $this->styleResolver->cssDeclarationString($placement) . '}';
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
        $carrierChild = 'p.' . self::INLINE_LAYOUT_CARRIER_CLASS . ' > ';
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
            $selectors[] = $prefix . 'p.' . self::INLINE_LAYOUT_CARRIER_CLASS . $subject;
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
    private function projectImageSelector(string $selector, array $parsed, AuthorStylesheetProjectionContext $context, bool $wrapperOnly = false, bool $mediaText = false, string $mediaTextMarker = ''): string
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
    private function selectorSpecificityShims(array $parsed, AuthorStylesheetProjectionContext $context, bool $includeState = false): string
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
    private function projectNavigationItemAnchorSelector(string $selector, array $parsed, AuthorStylesheetProjectionContext $context, bool $itemSubject = false): ?string
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
                $end = $this->balancedGroupEnd($compound, $offset);
                if ( null === $end ) {
                    return null;
                }
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
                    $end = $this->balancedGroupEnd($compound, $nameEnd);
                    if ( null === $end ) {
                        return null;
                    }
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
     * Offset after the `)` or `]` matching the group opened at $open, honouring
     * strings and escapes, or null when the group never closes.
     */
    private function balancedGroupEnd(string $value, int $open): ?int
    {
        $state = CssSyntaxScanner::state();
        $length = strlen($value);
        $offset = $open;
        while ( $offset < $length ) {
            $offset = CssSyntaxScanner::consume($value, $offset, $state);
            if ( null === $offset ) {
                return null;
            }
            if ( CssSyntaxScanner::isTopLevel($state) ) {
                return $offset;
            }
        }

        return null;
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
    private function matchesOnlyNavigationRole(array $matches, string $role, AuthorStylesheetProjectionContext $context): bool
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
    private function projectNavigationListItemSelector(string $selector, array $parsed, AuthorStylesheetProjectionContext $context): ?string
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
     * list's semantic identity, and a child hop reaches core's container.
     * Otherwise `#menu > ul` looks for a UL child of `#menu` that no longer
     * exists, and the item loses its authored box (padding, height, display).
     * Only applies when every matched item's parent is such a root-owning list.
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
        foreach ( $matches as $item ) {
            $list = $item->parentNode;
            if ( ! $list instanceof DOMElement ) {
                return array();
            }
            $path = $list->getNodePath() ?? '';
            if ( ! $context->selectorProjections->navigationListOwnsRoot($path) ) {
                return array();
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
        // The parent compound ends where the `>` before the item begins.
        $parentEnd = (int) $parsed['rightmost_compound_span']['start'];
        while ( $parentEnd > 0 && ctype_space($selector[$parentEnd - 1]) ) --$parentEnd;
        if ( $parentEnd < 1 || '>' !== $selector[$parentEnd - 1] ) {
            return array();
        }
        --$parentEnd;
        while ( $parentEnd > 0 && ctype_space($selector[$parentEnd - 1]) ) --$parentEnd;
        $replacements = array(
            $parentEnd => array( 'end' => $parentEnd, 'value' => ' > :where(.wp-block-navigation__container)' ),
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

    /**
     * The source list's row becomes display:contents. Its display, overflow
     * and multi-column layout therefore belong to the native root that now
     * renders that list's box. Emit at the author rule's position so
     * conditions, importance and cascade remain authored rather than being
     * frozen into resolved base styles.
     *
     * A list of inline-level items packs them with its `text-align`; on the
     * root's flex row the same packing is `justify-content`.
     */
    private function navigationListBoxRule(string $prelude, array $declarations, AuthorStylesheetProjectionContext $context): string
    {
        $box = array_intersect_key($declarations, array_flip(AuthorSelectorProjectionState::NAVIGATION_LIST_BOX_PROPERTIES));
        $packing = $this->inlineItemPacking((string) ( $declarations['text-align'] ?? '' ));
        if (array() === $box && '' === $packing) return '';
        // Declarations => projected selectors: one rule may reach lists whose shown box differs.
        $groups = array();
        foreach (CssValueSplitter::splitTopLevel($prelude, array(',')) as $selector) {
            $selector = trim($selector);
            $parsed = $context->sourceStyles->parsedSelector($selector);
            if (!$parsed['supported']) continue;
            foreach ($this->matchingSourceElements($selector, $parsed, $context) as $element) {
                $path = $element->getNodePath() ?? '';
                // Overlay lists still own an inner UL, not the opener's root.
                $target = $context->selectorProjections->navigationListBoxSubject($path);
                if ('' === $target) continue;
                $suffix = null === $parsed['pseudo_state_suffix_span'] ? '' : substr($selector, $parsed['pseudo_state_suffix_span']['start']);
                $display = $this->navigationListBoxDisplay($box['display'] ?? null, $context->selectorProjections->navigationListShownDisplay($path));
                $rendered = '' === $display ? $box : array('display' => $display) + $box;
                if ('' !== $packing && $this->packsInlineItems($element)) $rendered['justify-content'] = $packing;
                if (array() === $rendered) continue;
                $groups[$this->styleResolver->cssDeclarationString($rendered)][] = ':root ' . $target . $this->selectorSpecificityShims($parsed, $context) . $suffix;
            }
        }
        $rules = '';
        foreach ($groups as $body => $selectors) {
            $rules .= implode(',', array_unique($selectors)) . '{' . $body . '}';
        }
        return $rules;
    }

    /**
     * Core renders a submenu's toggle as a sibling of the item's content, and
     * its own item is the flex row that keeps them on one line. Where Core's
     * toggle stands in for the source chevron, an authored block or
     * inline-block item box would stack that toggle below the label, so the
     * projected item keeps its outer display as Core's row. Emitted right after
     * the item's projected rule: hiding, conditions and importance stay authored.
     */
    private function navigationToggleRowRule(string $prelude, array $declarations, AuthorStylesheetProjectionContext $context): string
    {
        $display = (string) ( $declarations['display'] ?? '' );
        $row = array( 'block' => 'flex', 'list-item' => 'flex', 'flow-root' => 'flex', 'inline-block' => 'inline-flex', 'inline' => 'inline-flex' )[CssValueInspector::comparable(CssValueInspector::withoutImportant($display))] ?? '';
        if ( '' === $row ) return '';
        $selectors = array();
        foreach ( CssValueSplitter::splitTopLevel($prelude, array( ',' )) as $selector ) {
            $selector = trim($selector);
            $parsed = $context->sourceStyles->parsedSelector($selector);
            if ( ! $parsed['supported'] || null !== ( CssSelectorCompoundInspector::rightmost($parsed['compounds'])['type'] ?? null ) ) continue;
            foreach ( $this->matchingSourceElements($selector, $parsed, $context) as $element ) {
                $path = $element->getNodePath() ?? '';
                $target = $context->selectorProjections->navigationSubject($path);
                if ( '' === $target || ! $context->selectorProjections->isNavigationToggleRowItem($path) ) continue;
                $state = null === $parsed['pseudo_state_suffix_span'] ? '' : substr($selector, $parsed['pseudo_state_suffix_span']['start']);
                $stateSelector = substr($selector, 0, null === $parsed['pseudo_state_suffix_span'] ? null : $parsed['pseudo_state_suffix_span']['start']);
                if ( $context->selectorProjections->selectsCurrentNavigationState($stateSelector) ) $target = AuthorSelectorProjectionState::currentNavigationSubject($target);
                elseif ( $context->selectorProjections->selectsRestingNavigationState($stateSelector) ) $target = AuthorSelectorProjectionState::restingNavigationSubject($target);
                $selectors[] = ':root ' . $target . $this->selectorSpecificityShims($parsed, $context) . $state;
            }
        }
        return array() === $selectors ? '' : implode(',', array_unique($selectors)) . '{display:' . $row . ( CssValueInspector::isImportant($display) ? ' !important' : '' ) . '}';
    }

    /** The flex packing of a row for the `text-align` that packed its inline items, with its importance. */
    private function inlineItemPacking(string $textAlign): string
    {
        $value = CssValueInspector::comparable(CssValueInspector::withoutImportant($textAlign));
        $packing = array( 'start' => 'flex-start', 'end' => 'flex-end', 'left' => 'left', 'right' => 'right', 'center' => 'center' )[$value] ?? '';
        return '' === $packing ? '' : $packing . ( CssValueInspector::isImportant($textAlign) ? ' !important' : '' );
    }

    /**
     * Whether a source list lays its items out as inline content at the
     * desktop reference viewport: the list is not a flex or grid container
     * and every item is inline-level and in flow.
     */
    private function packsInlineItems(DOMElement $list): bool
    {
        $listDisplay = CssValueInspector::comparable($this->styleResolver->authorViewport->reference($list, array( 'display' ))['display'] ?? '');
        if ( 1 === preg_match('/(?<![\w-])(?:inline-)?(?:flex|grid)(?![\w-])/', $listDisplay) ) {
            return false;
        }
        $items = 0;
        foreach ( $list->childNodes as $item ) {
            if ( ! $item instanceof DOMElement ) {
                continue;
            }
            $own = $this->styleResolver->authorViewport->reference($item, array( 'display', 'float', 'position' ));
            $float = CssValueInspector::comparable($own['float'] ?? '');
            $position = CssValueInspector::comparable($own['position'] ?? '');
            if ( ! $this->isInlineLevelDisplay((string) ( $own['display'] ?? '' )) || ! in_array($float, array( '', 'none' ), true) || in_array($position, array( 'absolute', 'fixed' ), true) ) {
                return false;
            }
            ++$items;
        }
        return $items > 0;
    }

    /**
     * The root's display for an authored list display. Hiding transfers; any
     * shown value becomes the list's native shown box (a flex row, or a block
     * for a list laid out in columns), keeping the authored importance.
     */
    private function navigationListBoxDisplay(?string $display, string $shownDisplay): string
    {
        if (!is_string($display)) return '';
        $importance = preg_match('/!\s*important\s*$/i', $display) ? ' !important' : '';
        if ('none' === strtolower(trim(preg_replace('/!\s*important\s*$/i', '', $display) ?? ''))) return $display;
        return $shownDisplay . $importance;
    }

    /**
     * Give back the space between inline menu items that core's flex row drops.
     *
     * Inline-level list items share one line box with the whitespace between
     * `</li>` and `<li>`, which renders as one space of the list's font. core
     * renders the items as flex items with nothing between them, so every item
     * moved one space towards the start of the row, cumulatively (about 4px per
     * item in a 12px menu). The space comes back as a no-break space after
     * every rendered item but the last: it has the advance of a space but does
     * not collapse at the end of the item, and because it trails the item it
     * never starts a wrapped line, as in the source. The carrier item is an
     * inline flex row so this advance stays beside even a block-level link,
     * rather than creating a second line below it.
     *
     * The rule follows the authored rule that makes the items inline: the same
     * projected item selector, emitted inside the same at-rules, with the same
     * importance. It is added only where the source really had the space —
     * {@see sourceItemsAreSpaceSeparated()}.
     *
     * @param array<string, string> $declarations
     */
    private function navigationItemSpaceRule(string $prelude, array $declarations, AuthorStylesheetProjectionContext $context): string
    {
        $display = (string) ( $declarations['display'] ?? '' );
        if ( ! $this->isInlineLevelDisplay($display) ) {
            return '';
        }
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors ) {
            return '';
        }
        $carriers = array();
        foreach ( $selectors as $selector ) {
            $selector = trim($selector);
            $parsed = $context->sourceStyles->parsedSelector($selector);
            if ( ! $parsed['supported'] || null !== ( $parsed['pseudo_state_suffix_span'] ?? null ) ) {
                continue;
            }
            $matches = $this->matchingSourceElements($selector, $parsed, $context);
            if ( ! $this->matchesOnlyNavigationRole($matches, AuthorSelectorProjectionState::NAVIGATION_ITEM, $context) ) {
                continue;
            }
            $item = $this->projectNavigationListItemSelector($selector, $parsed, $context);
            // Only where the authored rule itself landed on the rendered item.
            if ( null === $item || trim($this->rewriteSelectorPreludeOnce($selector, $context)) !== $item ) {
                continue;
            }
            if ( $this->sourceItemsAreSpaceSeparated($matches, $context) && ! $this->authorsItemAfterContent($matches, $context) ) {
                $carriers[] = $item . ':not(:last-child)';
            }
        }
        if ( array() === $carriers ) {
            return '';
        }

        return implode(',', $carriers) . '{display:inline-flex' . ( CssValueInspector::isImportant($display) ? '!important' : '' ) . '}'
            . implode(',', array_map(static fn (string $carrier): string => $carrier . '::after', $carriers)) . '{content:"\\a0"' . ( CssValueInspector::isImportant($display) ? '!important' : '' ) . '}';
    }

    /**
     * A direct source link is its menu's flex item; core/navigation renders it
     * inside `li.wp-block-navigation-item`, which is the flex item instead.
     * Declarations that place or generate that box (`display`, `order`, the
     * `flex` family, self-alignment) are restated on the item, so a link
     * hidden at a breakpoint takes its item, and the item's gap, with it.
     * Only box generation follows the source display: `none` and `contents`
     * pass through, any other value restores Core's item display, and the
     * authored value stays on the content anchor for its own inner layout.
     * The restated properties are not cumulative, so the anchor copy is
     * kept; margins are, and stay on the anchor, where they size the item.
     *
     * @param array<string, string> $declarations
     */
    private function navigationItemFlexItemRule(string $prelude, array $declarations, AuthorStylesheetProjectionContext $context): string
    {
        $item = array();
        foreach ( $declarations as $name => $value ) {
            if ( 'display' === $name ) {
                $box = CssValueInspector::comparable($value);
                $item[$name] = ( in_array($box, array( 'none', 'contents' ), true) ? $box : 'flex' ) . ( CssValueInspector::isImportant($value) ? '!important' : '' );
            } elseif ( in_array($name, array( 'order', 'flex', 'flex-grow', 'flex-shrink', 'flex-basis', 'align-self', 'justify-self', 'place-self' ), true) ) {
                $item[$name] = $value;
            }
        }
        if ( array() === $item ) {
            return '';
        }
        $targets = array();
        foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector ) {
            $selector = trim($selector);
            $parsed = $context->sourceStyles->parsedSelector($selector);
            if ( ! $parsed['supported'] || ! $this->matchesOnlyNavigationRole($this->matchingSourceElements($selector, $parsed, $context), AuthorSelectorProjectionState::NAVIGATION_ANCHOR, $context) ) {
                continue;
            }
            $anchor = $this->projectNavigationItemAnchorSelector($selector, $parsed, $context);
            $target = $this->projectNavigationItemAnchorSelector($selector, $parsed, $context, true);
            // Only where the authored rule itself landed on the rendered item.
            if ( null !== $anchor && null !== $target && trim($this->rewriteSelectorPreludeOnce($selector, $context)) === $anchor ) {
                $targets[] = $target;
            }
        }

        return array() === $targets ? '' : implode(',', array_unique($targets)) . '{' . $this->styleResolver->cssDeclarationString($item) . '}';
    }

    /** One spelling for the initial value, so a reset restating it compares equal to no statement. */
    private static function effectiveFontValue(string $property, string $value): string
    {
        return match ( true ) {
            'font-weight' === $property => array( '' => '400', 'normal' => '400', 'bold' => '700' )[$value] ?? $value,
            in_array($property, array( 'font-style', 'letter-spacing', 'word-spacing' ), true) && ( '' === $value || CssValueInspector::isZeroLength($value) ) => 'normal',
            default => $value,
        };
    }

    /**
     * Whether an authored rule already generates `::after` content on one of
     * the items (`#menu li:after{content:"|"}`). That rule stays live on the
     * rendered item, and the space must not replace its content. The space is
     * measured at the desktop reference viewport, so only rules that apply
     * there count: a mobile menu's `li:after` does not own the desktop row.
     *
     * @param list<DOMElement> $items
     */
    private function authorsItemAfterContent(array $items, AuthorStylesheetProjectionContext $context): bool
    {
        foreach ( $context->authorStyles->styleRules() as $rule ) {
            $conditions = array_values(array_filter(array_map(
                static fn ($condition): string => trim((string) preg_replace('#/\*.*?\*/#s', '', (string) $condition)),
                ArrayField::array($rule, 'conditions')
            )));
            if ( array() !== $conditions && ! $this->styleResolver->cascade->conditionsApplyAtViewport($conditions, StyleResolver::DESKTOP_REFERENCE_WIDTH) ) {
                continue;
            }
            foreach ( ArrayField::array($rule, 'selectors') as $record ) {
                $selector = trim((string) ( $record['selector'] ?? '' ));
                if ( 1 !== preg_match('/^(.+?)::?after$/i', $selector, $subject) ) {
                    continue;
                }
                foreach ( $items as $item ) {
                    if ( $this->sourceStyles->matches($item, $subject[1]) ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function isInlineLevelDisplay(string $display): bool
    {
        $keywords = preg_split('/\s+/', CssValueInspector::comparable($display)) ?: array();

        return ( 1 === count($keywords) && 1 === preg_match('/^inline(?:-[a-z-]+)?$/', $keywords[0]) )
            || ( 1 < count($keywords) && in_array('inline', $keywords, true) );
    }

    /**
     * Whether the source rendered one collapsible space between each matched
     * item and the next, and nothing else. True when, at the desktop
     * reference viewport:
     *
     * - each item is a recorded navigation item whose rendered siblings are its
     *   source siblings, and every element child of its list is matched, so
     *   the rendered `:not(:last-child)` is exactly "has a next source item";
     * - the items are separated only by whitespace (and comments), with some
     *   whitespace between every pair — `</li><li>` has no space to give back;
     * - each item is inline-level: not floated or taken out of flow, both of
     *   which blockify it;
     * - the list lays out inline content (not flex or grid, which blockify the
     *   items and drop the whitespace) and collapses whitespace;
     * - the item's font matches the list's: the source space takes the list's
     *   font, the generated one takes the item's. This also rules out the
     *   usual gap removal, `font-size:0` on the list with a size on the item.
     *
     * @param list<DOMElement> $items
     */
    private function sourceItemsAreSpaceSeparated(array $items, AuthorStylesheetProjectionContext $context): bool
    {
        $declared = array();
        $own = function (DOMElement $element) use (&$declared): array {
            return $declared[$element->getNodePath() ?? ''] ??= array_map(
                static fn (string $value): string => CssValueInspector::comparable($value),
                $this->styleResolver->authorViewport->reference($element, self::NAVIGATION_ITEM_SPACE_PROPERTIES)
            );
        };
        // The first stated value up the ancestors. A value that restates the
        // parent's (`inherit`, or a reset's `font-size:100%`) is no statement.
        $inherited = static function (DOMElement $element, string $property) use ($own): string {
            for ( $node = $element; $node instanceof DOMElement; $node = $node->parentNode instanceof DOMElement ? $node->parentNode : null ) {
                $value = $own($node)[$property] ?? '';
                if ( '' !== $value
                    && ! in_array($value, array( 'inherit', 'unset' ), true)
                    && ! ( 'font-size' === $property && 1 === preg_match('/^(?:100(?:\.0+)?%|1(?:\.0+)?em)$/', $value) )
                ) {
                    return $value;
                }
            }
            return '';
        };

        $matched = array();
        foreach ( $items as $item ) {
            $matched[$item->getNodePath() ?? ''] = true;
        }
        $lists = array();
        foreach ( $items as $item ) {
            $list = $item->parentNode;
            if ( ! $list instanceof DOMElement || ! $context->selectorProjections->navigationListItemRendersSourceSiblings($item->getNodePath() ?? '') ) {
                return false;
            }
            $itemDeclarations = $own($item);
            if ( ! $this->isInlineLevelDisplay($itemDeclarations['display'] ?? '')
                || ! in_array($itemDeclarations['float'] ?? '', array( '', 'none' ), true)
                || in_array($itemDeclarations['position'] ?? '', array( 'absolute', 'fixed' ), true)
            ) {
                return false;
            }
            foreach ( array( 'font', 'font-family', 'font-size', 'font-style', 'font-weight', 'letter-spacing', 'word-spacing' ) as $property ) {
                if ( self::effectiveFontValue($property, $inherited($item, $property)) !== self::effectiveFontValue($property, $inherited($list, $property)) ) {
                    return false;
                }
            }
            $lists[$list->getNodePath() ?? ''] = $list;
        }

        $spaced = false;
        foreach ( $lists as $list ) {
            if ( 1 === preg_match('/flex|grid|box|contents|none/', $own($list)['display'] ?? '')
                || ! in_array($inherited($list, 'white-space'), array( '', 'normal', 'nowrap' ), true)
                || ! in_array($inherited($list, 'white-space-collapse'), array( '', 'collapse' ), true)
            ) {
                return false;
            }
            $previous = false;
            $whitespace = '';
            foreach ( $list->childNodes as $node ) {
                if ( $node instanceof DOMElement ) {
                    if ( ! isset($matched[$node->getNodePath() ?? '']) ) {
                        return false;
                    }
                    if ( $previous ) {
                        if ( '' === $whitespace ) {
                            return false;
                        }
                        $spaced = true;
                    }
                    $previous = true;
                    $whitespace = '';
                } elseif ( XML_TEXT_NODE === $node->nodeType ) {
                    // Collapsible whitespace only; a no-break space or visible
                    // text between items is content of its own.
                    if ( 1 !== preg_match('/^[ \t\n\r\f]*$/', (string) $node->nodeValue) ) {
                        return false;
                    }
                    $whitespace .= (string) $node->nodeValue;
                } elseif ( XML_COMMENT_NODE !== $node->nodeType ) {
                    return false;
                }
            }
        }

        return $spaced;
    }

    /** @param array<string, mixed> $parsed */
    private function hasUniversalStructuralLeaf(array $parsed): bool
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
    private function matchingSourceElements(string $selector, array $parsed, AuthorStylesheetProjectionContext $context): array
    {
        return $this->semanticPreparer->matchingSourceElements($context->authorStyles, $selector, $parsed);
    }
}
