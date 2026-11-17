<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorCompoundInspector;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorListCompactor;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorTokenizer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSyntaxScanner;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ImageLowering;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\LayoutTableLowering;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\WordPressCommentForm;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
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

    /** Whether the rule being projected restated `box-sizing:content-box`. */
    private bool $contentBoxRestated = false;

    /** Authored cascade position of the rule that restated `box-sizing:content-box`. */
    private ?int $contentBoxOrder = null;

    /** @var list<array{selector: string, conditions: list<string>, value: string, order: int}> */
    private array $contentBoxOverrides = array();

    private readonly ButtonDeclarationRouting $buttonDeclarationRouting;

    /** Projects source selectors onto the block markup they convert to. */
    private readonly AuthorSelectorProjector $selectors;

    public function __construct(
        private readonly StyleResolver $styleResolver,
        private readonly AuthorSelectorSemanticPreparer $semanticPreparer,
        private readonly AuthorStyleRuleProjector $ruleBodyProjector,
        private readonly SourceStyleResolutionState $sourceStyles
    ) {
        $this->buttonDeclarationRouting = new ButtonDeclarationRouting($styleResolver, $semanticPreparer);
        $this->selectors = new AuthorSelectorProjector($styleResolver, $semanticPreparer);
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
        $projected = $this->buttonLinkWidthRules($projected, $context);
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
                    $insert = self::backgroundGuardOffset($arm);
                    if ( null !== $insert ) $arm = substr($arm, 0, $insert) . $guard . substr($arm, $insert);
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
     * Only width competes with core/buttons' (0,2,0) width:100% default.
     * Weight all projected authored widths equally, including auto and
     * conditional resets, but leave color and other presentation at their
     * source specificity so theme.json's lifted button values can still win.
     */
    private function buttonLinkWidthRules(string $css, AuthorStylesheetProjectionContext $context): string
    {
        $target = '> :where(.wp-block-button__link)';
        $shim = ':not(.' . $context->authorStyles->classSpecificityShim() . ')';
        return (new CssStylesheetTransformer())->transform($css, static function (string $prelude, string $body) use ($target, $shim): string|array {
            if ( ! str_contains($prelude, $target) ) return $prelude;
            $weighted = implode(',', array_map(
                static fn (string $arm): string => str_replace($target, $target . $shim . $shim, $arm),
                CssStylesheetTransformer::splitSelectorList($prelude) ?? array($prelude)
            ));
            $runs = array();
            foreach ( CssValueSplitter::splitTopLevel($body, array(';')) as $declaration ) {
                if ( '' === trim($declaration) ) continue;
                $selector = 1 === preg_match('/^\s*width\s*:/i', $declaration) ? $weighted : $prelude;
                $last = count($runs) - 1;
                if ( $last >= 0 && $runs[$last]['prelude'] === $selector ) {
                    $runs[$last]['body'] .= ';' . $declaration;
                } else {
                    $runs[] = array('prelude' => $selector, 'body' => $declaration);
                }
            }
            return $runs;
        });
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
            $selector = preg_replace(AuthorSelectorProjector::STATE_PSEUDO_CLASS_PATTERN, '', $selector) ?? $selector;
            foreach ( $this->selectors->matchingSourceElements($selector, $context->sourceStyles->parsedSelector($selector), $context) as $element ) {
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
    /**
     * Where a materialized background guard belongs in a selector arm: before
     * its subject's pseudo-element, or null when the subject compound already
     * requires a hook the background figure and img never carry.
     */
    private static function backgroundGuardOffset(string $arm): ?int
    {
        $tokens = CssSelectorTokenizer::tokenize($arm);
        if ( ! $tokens['supported'] || array() === $tokens['compound_spans'] ) return null;
        $span = $tokens['compound_spans'][count($tokens['compound_spans']) - 1];
        if ( 1 === preg_match('/\G(?:[a-z][\w-]*\|)?([a-z][\w-]*)/i', $arm, $type, 0, $span['start'])
            && ! in_array(strtolower($type[1]), array( 'img', 'figure' ), true) ) return null;
        $state = CssSyntaxScanner::state();
        for ( $offset = $span['start']; $offset < $span['end']; ) {
            $topLevel = CssSyntaxScanner::isTopLevel($state);
            if ( $topLevel && ('.' === $arm[$offset] || '#' === $arm[$offset] || preg_match('/\G\[(?!(?:src|srcset|alt|class|style|sizes|width|height|loading|decoding)\b)/i', $arm, $_, 0, $offset)) ) {
                return null;
            }
            // A matches-any list hooks the subject when every argument's own
            // subject is hooked, such as :where(.a.b) or :where(mark[x],span[y]).
            if ( $topLevel && 1 === preg_match('/\G:(?:where|is)\(/i', $arm, $open, 0, $offset) ) {
                $close = CssSyntaxScanner::matchingClose($arm, $offset + strlen($open[0]) - 1);
                $arguments = null === $close ? null : CssStylesheetTransformer::splitSelectorList(substr($arm, $offset + strlen($open[0]), $close - $offset - strlen($open[0])));
                if ( null !== $arguments && array() !== $arguments
                    && array() === array_filter($arguments, static fn (string $argument): bool => '' === trim($argument) || ! CssSelectorTokenizer::tokenize(trim($argument))['supported'] || null !== self::backgroundGuardOffset(trim($argument))) ) {
                    return null;
                }
            }
            if ( $topLevel && ':' === $arm[$offset]
                && (':' === ($arm[$offset + 1] ?? '') || preg_match('/\G:(?:before|after|first-line|first-letter)(?![\w-])/i', $arm, $_, 0, $offset)) ) {
                return $offset;
            }
            $next = CssSyntaxScanner::consume($arm, $offset, $state);
            if ( null === $next ) break;
            $offset = $next;
        }
        return $span['end'];
    }

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
            $selector = preg_replace(AuthorSelectorProjector::STATE_PSEUDO_CLASS_PATTERN, '', $selector) ?? $selector;
            $subjects = $this->selectors->matchingSourceElements($selector, $context->sourceStyles->parsedSelector($selector), $context);
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
        $matches = $this->selectors->matchingSourceElements($host['selector'] ?? $selector, $parsed, $context);
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
            return ! ($parsed['supported'] ?? false) || array() !== $this->selectors->matchingSourceElements($selector, $parsed, $context);
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
            // Like the other synthetic-figure repairs, these arms only reach
            // markup that carries their classes: skip them when it does not.
            $inlineFigures = str_contains($context->emittedMarkup, SourceBlockAttributeProjector::SYNTHETIC_INLINE_IMAGE_FIGURE_CLASS);
            if ( $inlineFigures && str_contains($context->emittedMarkup, LayoutTableLowering::LAYOUT_TABLE_CELL_CLASS) ) {
                $inlineSelectors = array_map(
                    static fn (string $selector): string => ':where(.' . LayoutTableLowering::LAYOUT_TABLE_CELL_CLASS . ') ' . str_replace('.wp-block-image', '.wp-block-image:where(.' . SourceBlockAttributeProjector::SYNTHETIC_INLINE_IMAGE_FIGURE_CLASS . ')', $selector),
                    CssValueSplitter::splitTopLevel($imagePrelude, array(','))
                );
                $imageRule .= implode(',', $inlineSelectors) . '{display:' . ($declarations['display'] ?? 'inline')
                    . ';max-width:' . ($declarations['max-width'] ?? 'none') . ';vertical-align:' . ($declarations['vertical-align'] ?? 'baseline') . '}';
            }
            $imageRule .= $this->lineImageFigureRule($imagePrelude, $declarations, $context);
            // Any inline-flow image aligns on its line as the source declared,
            // not at the bottom where core's Image rule would put it.
            if ( $inlineFigures && isset($declarations['vertical-align']) ) {
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
            : $svgImagePrelude . '{' . $this->imageProjectionBridgeDeclarations($declarations, true, ! $this->everySubjectStaysOnImage($prelude, $context)) . ';' . $this->styleResolver->cssDeclarationString($this->imageApplicableSvgDeclarations($declarations)) . '}'
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
        $marginGroups = array();
        foreach ( $margins as $property => $value ) {
            $marginPrelude = $this->marginSelectorPrelude($prelude, $context);
            if ( '' !== $imagePlacement['rules'] && 1 !== preg_match('/\bauto\b/i', $value) ) {
                // The leaf placement projection already spaces the image.
                // Keep non-auto margins off its synthetic wrapper; real source
                // boxes retain them. Auto margins can still center a wrapper
                // whose authored width owns the source image box.
                $marginPrelude = implode(',', array_map(
                    static fn (string $selector): string => self::canMatchSyntheticImageFigure($selector)
                        ? rtrim($selector) . ':not(:where(.' . SourceBlockAttributeProjector::SYNTHETIC_IMAGE_FIGURE_CLASS . '))'
                        : rtrim($selector),
                    CssStylesheetTransformer::splitSelectorList($marginPrelude) ?? array()
                ));
            }
            $marginGroups[$marginPrelude][$property] = $value;
        }
        $marginRules = '';
        foreach ( $marginGroups as $marginPrelude => $marginDeclarations ) {
            $marginRules .= $marginPrelude . '{' . $this->styleResolver->cssDeclarationString($marginDeclarations) . '}';
        }
        $css = $rules
            . $marginRules
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
            foreach ($this->selectors->matchingSourceElements($selector, $parsed, $context) as $element) {
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
            foreach ( $this->selectors->matchingSourceElements(trim($selector), $parsed, $context) as $element ) {
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
            foreach ( $this->selectors->matchingSourceElements($selector, $parsed, $context) as $table ) {
                $marker = $context->selectorProjections->tableMarker($table->getNodePath() ?? '');
                if ( '' === $marker ) {
                    continue;
                }
                $target = $this->selectors->projectSemanticLeafSelector($selector, $parsed, $marker, $context);
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
                            : $this->styleResolver->customProperties->resolve($value, $element);
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
        if ( self::anyDeclaredAs($body, array( 'text-align', 'align-items', 'justify-content' ), array( 'center' )) ) {
            $submenuOnly = true;
            $found = false;
            foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector ) {
                $parsed = $context->sourceStyles->parsedSelector($selector);
                foreach ( $this->selectors->matchingSourceElements($selector, $parsed, $context) as $element ) {
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
            $sourceElements = $this->selectors->matchingSourceElements($baseSelector, $parsed, $context);
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
                    $rewritten[] = $this->selectors->projectRichTextSemanticSelector($baseSelector, $parsed, $marker, $context) . $matches[2];
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
                $rewritten[] = $this->selectors->projectControlSelector($baseSelector, $parsed, $marker, $context) . $matches[2];
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
            foreach ( $this->selectors->matchingSourceElements($selector, $parsed, $context) as $element ) {
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
        // Each matched native button, with the longhands of this rule that a
        // stronger source rule wins on it. The restatement below carries two
        // extra class weights, so it must leave those longhands to the winner.
        $carriers = array();
        foreach ( $selectors as $selector ) {
            $parsed = $context->sourceStyles->parsedSelector($selector);
            if ( ! $parsed['supported'] ) {
                continue;
            }
            foreach ( $this->selectors->matchingSourceElements($selector, $parsed, $context) as $element ) {
                $path = $element->getNodePath() ?? '';
                $marker = $context->selectorProjections->controlMarker($path);
                if ( 'button' !== strtolower($element->tagName) || '' === $marker || $this->isSpecializedNativeButton($element, $body) ) {
                    continue;
                }
                $carriers[$selector][] = array(
                    'marker' => $marker,
                    'flattened' => LayoutParticipation::resolve($element, $this->styleResolver)->neutralizeButtonWrapper(),
                    'outranked' => array_filter(
                        $this->styleResolver->outrankedAuthorLonghands($element, $selector, $body),
                        fn (string $longhand): bool => ! $this->isBlockCarriedColourLonghand($longhand),
                        ARRAY_FILTER_USE_KEY
                    ),
                );
            }
        }
        if ( array() === $carriers ) {
            return '';
        }

        $declarations = array();
        foreach ( CssRuleAnalyzer::declarationList($body) as [ 'name' => $name, 'value' => $value ] ) {
            if ( '' === $name || '' === $value || ( ! $this->isButtonLinkLayoutProperty($name) && ! $this->isButtonLinkPresentationProperty($name) ) ) {
                continue;
            }
            $exclusion = $this->nativeButtonEditedPropertyExclusion($name);
            $declarations[$exclusion][ButtonDeclarationRouting::isWrapperOwnedProperty($name) ? 1 : 0][] = array(
                'declaration' => $name . ':' . $value,
                'longhands' => array_map('strtolower', array_keys(SourceDeclarations::parse($name . ':' . CssValueInspector::withoutImportant($value)))),
            );
        }
        if ( array() === $declarations ) {
            return '';
        }
        $arms = array();
        $rules = array();
        foreach ( $declarations as $exclusion => $groups ) {
            foreach ( $groups as $wrapperOwned => $entries ) {
                $bySelector = array();
                foreach ( $carriers as $selector => $matched ) {
                    // Declarations whose winning carriers are the same share one rule.
                    $partitions = array();
                    foreach ( $entries as $entry ) {
                        $markers = array_values(array_filter(
                            $matched,
                            static fn (array $carrier): bool => array() === array_intersect_key($carrier['outranked'], array_flip($entry['longhands']))
                        ));
                        if ( array() !== $markers ) {
                            $key = implode(' ', array_column($markers, 'marker'));
                            $partitions[$key]['carriers'] = $markers;
                            $partitions[$key]['declarations'][] = $entry['declaration'];
                        }
                    }
                    foreach ( $partitions as $key => $partition ) {
                        $arms[$selector][$key] ??= $this->nativeButtonCompatArms((string) $selector, $partition['carriers'], $context);
                        foreach ( array_column($arms[$selector][$key], $wrapperOwned) as $arm ) {
                            $bySelector[$arm] = array_merge($bySelector[$arm] ?? array(), $partition['declarations']);
                        }
                    }
                }
                foreach ( $bySelector as $arm => $properties ) {
                    $rules[] = $arm . $exclusion . '{' . implode(';', array_values(array_unique($properties))) . '}';
                }
            }
        }
        return implode('', $rules);
    }

    /**
     * core/button serializes the source button's colours itself (preset class
     * or inline style), and the restatement of a colour longhand is guarded by
     * `.has-background` / `.has-text-color` / the inline style, so it already
     * yields to a stronger source colour. It stays as the owner's fallback
     * when that colour is cleared in the editor.
     */
    private function isBlockCarriedColourLonghand(string $longhand): bool
    {
        return 'color' === $longhand || str_starts_with($longhand, 'background');
    }

    /**
     * The restatement arms for the native buttons one source selector matches:
     * each pairs the link arm with its flattened-wrapper geometry form.
     *
     * @param list<array{marker: string, flattened: bool}> $carriers
     * @return list<array{0: string, 1: string}>
     */
    private function nativeButtonCompatArms(string $selector, array $carriers, AuthorStylesheetProjectionContext $context): array
    {
        $parsed = $context->sourceStyles->parsedSelector($selector);
        $markers = array_column($carriers, 'marker');
        $flattened = ! in_array(false, array_column($carriers, 'flattened'), true);
        $arms = array();
        // Every native button carrier the context-free subject matches is
        // reached through the role and subject classes; `.button` stands
        // for the tag test the per-element list applied.
        $subject = $this->selectors->contextFreeSubject('control', $this->withButtonSubject($parsed), $markers, $context);
        if ( null !== $subject ) {
            $arms[] = $this->nativeButtonControlTarget($subject['target'], $selector, $parsed, $context);
            $markers = $subject['rest'];
        }
        foreach ( $markers as $marker ) {
            $arms[] = $this->nativeButtonControlSelector($selector, $parsed, $marker, $context);
        }
        return array_map(fn (string $arm): array => array( $arm, $flattened ? $this->flattenedWrapperLinkArm($arm) : $arm ), $arms);
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
            // Core applies width:100% with two class weights. The flattened
            // link is the authored box, so its geometry must beat that reset.
            : substr($arm, 0, $at) . '.' . SourceBlockAttributeProjector::LAYOUT_NEUTRAL_BUTTON_CLASS . substr($arm, $at);
    }

    private function nativeButtonControlSelector(string $selector, array $parsed, string $marker, AuthorStylesheetProjectionContext $context): string
    {
        $projected = $this->selectors->projectControlSelector($selector, $parsed, $marker, $context);
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
        return $this->selectors->projectControlTarget($target . '.blocks-engine-role-control.blocks-engine-role-control', $selector, $parsed, $context);
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

    /**
     * @param list<string> $properties
     * @param list<string> $values
     */
    private static function anyDeclaredAs(string $body, array $properties, array $values): bool
    {
        foreach ( CssRuleAnalyzer::declarations($body, $properties) as [ 'value' => $value ] ) {
            if ( in_array(CssValueInspector::comparable($value), $values, true) ) {
                return true;
            }
        }

        return false;
    }

    private function hasNativeButtonGeometryDeclaration(string $body, ?DOMElement $element = null): bool
    {
        if ( self::anyDeclaredAs($body, array( 'position' ), array( 'absolute', 'fixed' )) && self::anyDeclaredAs($body, array( 'width', 'height' ), array( '0' )) ) {
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
            foreach ( $this->selectors->matchingSourceElements($selector, $parsed, $context) as $element ) {
                $marker = $context->selectorProjections->controlMarker($element->getNodePath() ?? '');
                if ( '' !== $marker ) {
                    $rewritten[] = ('*' === trim($selector) ? ':where(.blocks-engine-role-control)' : ':where(.' . $marker . ')') . ':where(.wp-block-buttons):not(.'
                        . $context->authorStyles->classSpecificityShim() . ')'
                        . $this->selectors->selectorSpecificityShims($parsed, $context);
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
                array_push($rewritten, ...$this->selectors->projectUnsupportedFunctionalControlSelector($selector, $context, true));
                continue;
            }
            if ( null !== $parsed['pseudo_state_suffix_span'] || $this->selectors->hasUniversalStructuralLeaf($parsed) ) {
                continue;
            }
            $matches = $this->selectors->matchingSourceElements($selector, $parsed, $context);
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
                $universal = $this->selectors->universalControlSubject($selector, $parsed, $context);
                $rewritten[] = null === $universal
                    ? ':where(.' . $marker . ')' . $this->selectors->selectorSpecificityShims($parsed, $context)
                    : $universal['prefix'] . ':where(.blocks-engine-role-control)' . $this->selectors->selectorSpecificityShims($universal['parsed'], $context);
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
                    $link = $this->selectors->projectControlSelector($selector, $parsed, $marker, $context, false);
                    return $participation->neutralizeButtonWrapper() ? $this->flattenedWrapperLinkArm($link) : $link;
                }

                return $this->selectors->projectControlSelector($selector, $parsed, $marker, $context, true);
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
                $link = $this->selectors->projectControlSelector($selector, $parsed, $marker, $context);
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
            if ( ! $parsed['supported'] || null !== $parsed['pseudo_state_suffix_span'] || $this->selectors->hasUniversalStructuralLeaf($parsed) ) {
                continue;
            }
            $matches = $this->selectors->matchingSourceElements($selector, $parsed, $context);
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
            $linkTarget = $this->selectors->buttonLinkTarget($context);
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
                $leaves[] = ':where(' . $leaf . ')' . $this->selectors->selectorSpecificityShims($parsed, $context, true);
            }
        }
        $placed = $this->styleResolver->cssDeclarationString($placement);
        return array(
            'body' => implode(';', $retained),
            'rules' => implode(',', $original) . '{' . $placed . '}' . implode(',', $leaves) . '{' . $placed . '}',
        );
    }

    private function rewriteSelectorPrelude(string $prelude, AuthorStylesheetProjectionContext $context, bool $controlWrapper = false, bool $subjectArms = false, bool $semanticSubjectArms = false): string
    {
        $projected = $this->selectors->rewriteSelectorPreludeOnce($prelude, $context, $controlWrapper, $subjectArms, $semanticSubjectArms);
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
            $own = 1 === count($selectors) ? $projected : $this->selectors->rewriteSelectorPreludeOnce($selector, $context, $controlWrapper, $subjectArms, $semanticSubjectArms);
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
        // A license comment ahead of the source rule stays with that rule;
        // the restatement must not ship it again inside its selectors.
        $selectors = CssStylesheetTransformer::splitSelectorList(trim(CssSyntaxScanner::withoutComments($prelude)));
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
                $sourceSelector = $this->selectors->projectSourceClassSelector($selector, $context);
                $sourceParsed = $context->sourceStyles->parsedSelector($sourceSelector);
                $end = (int) $sourceParsed['rightmost_rewrite_end'];
                $bodySelectors[] = ':root ' . substr($sourceSelector, 0, $end) . '.editor-styles-wrapper' . substr($sourceSelector, $end);
            } elseif ($root instanceof DOMElement && CssSelectorMatcher::matches($root, $parsed)['matches']) {
                $rootSelectors[] = $this->selectors->projectSourceClassSelector($selector, $context);
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

    /** @param array<string, mixed> $parsed */
    private function sourceAttributeMarker(string $selector, array $parsed, DOMElement $element, AuthorStylesheetProjectionContext $context): string
    {
        $rightmost = CssSelectorCompoundInspector::rightmost($parsed['compounds'] ?? array());
        if ( null !== $parsed['pseudo_state_suffix_span']
            || ! CssSelectorCompoundInspector::containsDataAttribute($rightmost)
            || CssSelectorCompoundInspector::hasLiveAttributePredicate($rightmost)
            || null === CssSelectorCompoundInspector::markerClassConditions($rightmost)
        ) {
            return '';
        }
        return $context->selectorProjections->attributeMarker($element->getNodePath() ?? '', $selector);
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
            $matches = $this->selectors->matchingSourceElements($selector, $parsed, $context);
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
                        $projected[] = NativeImageLeafPresentation::selectors($this->selectors->projectSemanticLeafSelector($selector, $parsed, $marker, $context) . '.wp-block-image');
                    }
                }
                continue;
            }
            // Only the existing fill/flex paths need a synthetic wrapper box.
            // Ordinary SVG presentation belongs on the replaced image itself.
            if ( 'svg' === $tagName && ($flexBox || $this->isProjectableFillSvg($imageMatches[0], $declarations)) ) {
                $this->pushScopedImageArm($projected, $selector, $this->selectors->projectImageSelector($selector, $parsed, $context, true), $context);
            }
            $mediaTextImages = array_values(array_filter(
                $imageMatches,
                fn (DOMElement $element): bool => $tagName === 'img'
                    && $this->isMediaTextImage($element)
                    && '' !== $this->mediaTextImageMarkerForElement($element, $context)
            ));
            foreach ( $mediaTextImages as $element ) {
                $projected[] = $this->selectors->projectImageSelector(
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
                $conditions = CssSelectorCompoundInspector::markerClassConditions(CssSelectorCompoundInspector::rightmost($parsed['compounds']));
                if ( '' === $marker || null === $conditions ) {
                    $hasUnmarkedImage = true;
                    continue;
                }
                // The source link's ID now belongs to the native figure. A
                // descendant selector cannot retain that former ancestry.
                $suffix = null === $parsed['pseudo_state_suffix_span'] ? '' : substr($selector, $parsed['pseudo_state_suffix_span']['start']);
                $projected[] = NativeImageLeafPresentation::selectors(':where(.' . $marker . $conditions . ').wp-block-image', $this->selectors->selectorSpecificityShims($parsed, $context) . $suffix);
            }
            if ( $hasUnmarkedImage ) {
                $this->pushScopedImageArm($projected, $selector, $this->selectors->projectImageSelector($selector, $parsed, $context), $context);
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
                ? $this->selectors->matchingSourceElements($selector, $parsed, $context)
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
                    ? $this->selectors->projectImageSelector($selector, $parsed, $context, false, true, $marker)
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
        return '100%' === trim($this->styleResolver->customProperties->resolve((string) ($declarations[$dimension] ?? ''), $element));
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
        if ( '' === $imagePrelude
            || ! str_contains($context->emittedMarkup, SourceBlockAttributeProjector::SYNTHETIC_FLEX_IMAGE_FIGURE_CLASS)
            || ! $this->selectsFlexItemImage($prelude, $context) ) {
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
            foreach ( $this->selectors->matchingSourceElements($selector, $parsed, $context) as $element ) {
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

        $rule = str_replace('.wp-block-image', '.wp-block-image:where(.' . implode(',.', $figures) . ')', $imagePrelude)
            . '{display:' . ($declarations['display'] ?? 'inline')
            . ( isset($declarations['vertical-align']) ? ';vertical-align:' . $declarations['vertical-align'] : '' ) . '}';
        // The inline synthetic figure is display:contents, so class-owned
        // dimensions cannot size it. Keep the replaced box on the image,
        // after the generic 100% bridge and at the author's cascade position
        // (including responsive conditions). Placement is routed separately.
        if ( in_array(SourceBlockAttributeProjector::SYNTHETIC_INLINE_IMAGE_FIGURE_CLASS, $figures, true) ) {
            $box = array_filter($declarations, static fn (string $property): bool =>
                NativeImageLeafPresentation::isBoxProperty($property)
                && ! NativeImageLeafPresentation::isPlacementProperty($property), ARRAY_FILTER_USE_KEY);
            if ( array() !== $box ) {
                $rule .= str_replace('.wp-block-image', '.wp-block-image:where(.' . SourceBlockAttributeProjector::SYNTHETIC_INLINE_IMAGE_FIGURE_CLASS . ')', $imagePrelude)
                    . '{' . $this->styleResolver->cssDeclarationString($box) . '}';
            }
        }

        return $rule;
    }

    /**
     * The bridge lets the native image follow the figure that now carries the
     * source rule. A selector whose subject is a bare `img` has nothing that
     * moves to the figure, so the source rule still paints the image itself:
     * inheriting the figure's corners there would replace the image's own
     * radius at the same specificity, later in the cascade. A universal
     * subject (`*`) matches the image the same way, as does a bare `svg`
     * subject for the image an inline SVG is materialized into.
     *
     * @param array<string, string> $declarations
     */
    private function imageProjectionBridgeRule(string $prelude, string $imagePrelude, array $declarations, AuthorStylesheetProjectionContext $context): string
    {
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude) ?? array();
        $moved = array_values(array_filter($selectors, fn (string $selector): bool => ! $this->subjectStaysOnImage($selector, $context)));
        // A percentage on the moved figure already sized the source box.
        // Inheriting that percentage on its child applies it a second time.
        // Bare img/universal subjects still size the leaf directly and must
        // not receive this reset.
        $fill = array();
        foreach ( array('width', 'height') as $property ) {
            if ( 1 === preg_match('/^\d*\.?\d+%$/', CssValueInspector::declared($declarations, $property)) ) {
                $fill[] = $property . ':100%' . (str_contains($declarations[$property], '!important') ? '!important' : '');
            }
        }
        $fillBody = array() === $fill ? '' : ';' . implode(';', $fill);
        if ( count($moved) === count($selectors) ) {
            return $imagePrelude . '{' . $this->imageProjectionBridgeDeclarations($declarations, false, true, true) . '}';
        }
        $rule = $imagePrelude . '{' . $this->imageProjectionBridgeDeclarations($declarations, false, false, false, array() !== $moved) . '}';
        $movedPrelude = array() === $moved ? '' : $this->projectAuthorImageSelectorPrelude(implode(',', $moved), $context);

        return '' === $movedPrelude ? $rule : $rule . $movedPrelude . '{border-radius:inherit' . $fillBody . '}';
    }

    private function everySubjectStaysOnImage(string $prelude, AuthorStylesheetProjectionContext $context): bool
    {
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude) ?? array();

        return array() !== $selectors && array() === array_filter($selectors, fn (string $selector): bool => ! $this->subjectStaysOnImage($selector, $context));
    }

    private function subjectStaysOnImage(string $selector, AuthorStylesheetProjectionContext $context): bool
    {
        $parsed = $context->sourceStyles->parsedSelector($selector);
        if ( ! $parsed['supported'] ) {
            return false;
        }
        $subject = CssSelectorCompoundInspector::rightmost($parsed['compounds']);

        // Explicit img subjects naming only positioned images move to the
        // figure too, so it can own their out-of-flow source box.
        if ( 1 === preg_match('/^img\b/i', substr($selector, (int) ($parsed['rightmost_compound_span']['start'] ?? 0))) ) {
            $matches = $this->selectors->matchingSourceElements($selector, $parsed, $context);
            if ( array() !== $matches && array() === array_filter($matches, fn (DOMElement $element): bool =>
                'img' !== strtolower($element->tagName)
                || ! in_array(CssValueInspector::comparable((string) ($this->styleResolver->structuralPresentationDeclarations($element)['position'] ?? '')), array('absolute', 'fixed'), true)) ) {
                return false;
            }
        }

        // A universal subject matches the image itself, exactly like `img`.
        return in_array(strtolower((string) ($subject['type'] ?? '*')), array( 'img', 'svg', '*' ), true)
            && array() === ($subject['classes'] ?? array())
            && array() === ($subject['ids'] ?? array())
            && array() === ($subject['attributes'] ?? array());
    }

    /** @param array<string, string> $declarations */
    private function imageProjectionBridgeDeclarations(array $declarations, bool $preserveObjectFit = false, bool $inheritRadius = true, bool $fillPercentageBox = false, bool $projectDimensions = true): string
    {
        $bridge = array( 'display:block' );
        $position = strtolower(trim((string) ($declarations['position'] ?? '')));
        $width = strtolower(trim((string) ($declarations['width'] ?? '')));
        $height = strtolower(trim((string) ($declarations['height'] ?? '')));
        $ownsBox = ! in_array($width, array( '', 'auto' ), true) && ! in_array($height, array( '', 'auto' ), true);
        // Stay-on-image subjects already size the leaf with the authored rule.
        // A higher-specificity bridge must not replace those axes with the
        // synthetic figure's dimensions (or its otherwise automatic height).
        if ( $projectDimensions && ($ownsBox || in_array($position, array( 'absolute', 'fixed' ), true)) ) {
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
        foreach ($projectDimensions ? array('width', 'height') : array() as $property) {
            if ('auto' === CssValueInspector::declared($declarations, $property)) {
                $bridge[] = $property . ':' . $declarations[$property];
            } elseif (! $ownsBox && ! in_array($position, array('absolute', 'fixed'), true) && isset($declarations[$property])) {
                // A responsive rule can change only one axis. The figure owns
                // that declaration; its leaf must follow the new value too.
                // Fill only axes moved to a percentage-sized figure, without
                // applying the ratio twice; direct leaf selectors keep their sizing.
                $percentage = $fillPercentageBox && 1 === preg_match('/^\d*\.?\d+%$/', CssValueInspector::declared($declarations, $property));
                $bridge[] = $property . ':' . ($percentage ? '100%' : 'inherit')
                    . (str_contains($declarations[$property], '!important') ? '!important' : '');
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

    /** @param array<string, mixed> $parsed */
    private function projectButtonBoxSelector(string $selector, array $parsed, string $marker, AuthorStylesheetProjectionContext $context): string
    {
        $suffix = null === $parsed['pseudo_state_suffix_span'] ? '' : substr($selector, $parsed['pseudo_state_suffix_span']['start']);
        return ':where(.' . $marker . ')' . $this->selectors->selectorSpecificityShims($parsed, $context) . ':where(.wp-block-button)' . $suffix;
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
            if ( ! $parsed['supported'] || array() === $this->selectors->matchingSourceElements($selector, $parsed, $context) ) continue;
            $target = $this->selectors->projectParagraphCarrierChildSelector($selector, $parsed, $context, true);
            if ( null !== $target ) $targets[] = $this->selectors->rewriteSourceTagTypes($target, CssSelectorMatcher::parse($target), $context);
        }
        return array() === $targets ? '' : implode(',', array_unique($targets)) . '{' . $this->styleResolver->cssDeclarationString($placement) . '}';
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
            foreach ($this->selectors->matchingSourceElements($selector, $parsed, $context) as $element) {
                $path = $element->getNodePath() ?? '';
                // Overlay lists still own an inner UL, not the opener's root.
                $target = $context->selectorProjections->navigationListBoxSubject($path);
                if ('' === $target) continue;
                $suffix = null === $parsed['pseudo_state_suffix_span'] ? '' : substr($selector, $parsed['pseudo_state_suffix_span']['start']);
                $display = $this->navigationListBoxDisplay($box['display'] ?? null, $context->selectorProjections->navigationListShownDisplay($path));
                $rendered = '' === $display ? $box : array('display' => $display) + $box;
                if ('' !== $packing && $this->packsInlineItems($element)) $rendered['justify-content'] = $packing;
                if (array() === $rendered) continue;
                $groups[$this->styleResolver->cssDeclarationString($rendered)][] = ':root ' . $target . $this->selectors->selectorSpecificityShims($parsed, $context) . $suffix;
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
            foreach ( $this->selectors->matchingSourceElements($selector, $parsed, $context) as $element ) {
                $path = $element->getNodePath() ?? '';
                $target = $context->selectorProjections->navigationSubject($path);
                if ( '' === $target || ! $context->selectorProjections->isNavigationToggleRowItem($path) ) continue;
                $state = null === $parsed['pseudo_state_suffix_span'] ? '' : substr($selector, $parsed['pseudo_state_suffix_span']['start']);
                $stateSelector = substr($selector, 0, null === $parsed['pseudo_state_suffix_span'] ? null : $parsed['pseudo_state_suffix_span']['start']);
                if ( $context->selectorProjections->selectsCurrentNavigationState($stateSelector) ) $target = AuthorSelectorProjectionState::currentNavigationSubject($target);
                elseif ( $context->selectorProjections->selectsRestingNavigationState($stateSelector) ) $target = AuthorSelectorProjectionState::restingNavigationSubject($target);
                $selectors[] = ':root ' . $target . $this->selectors->selectorSpecificityShims($parsed, $context) . $state;
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
            $matches = $this->selectors->matchingSourceElements($selector, $parsed, $context);
            if ( ! $this->selectors->matchesOnlyNavigationRole($matches, AuthorSelectorProjectionState::NAVIGATION_ITEM, $context) ) {
                continue;
            }
            $item = $this->selectors->projectNavigationListItemSelector($selector, $parsed, $context);
            // Only where the authored rule itself landed on the rendered item.
            if ( null === $item || trim($this->selectors->rewriteSelectorPreludeOnce($selector, $context)) !== $item ) {
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
            if ( ! $parsed['supported'] || ! $this->selectors->matchesOnlyNavigationRole($this->selectors->matchingSourceElements($selector, $parsed, $context), AuthorSelectorProjectionState::NAVIGATION_ANCHOR, $context) ) {
                continue;
            }
            $anchor = $this->selectors->projectNavigationItemAnchorSelector($selector, $parsed, $context);
            $target = $this->selectors->projectNavigationItemAnchorSelector($selector, $parsed, $context, true);
            // Only where the authored rule itself landed on the rendered item.
            if ( null !== $anchor && null !== $target && trim($this->selectors->rewriteSelectorPreludeOnce($selector, $context)) === $anchor ) {
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
}
