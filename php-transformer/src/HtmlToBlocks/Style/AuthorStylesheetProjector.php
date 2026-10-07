<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;
use Automattic\BlocksEngine\PhpTransformer\Css\CssIdent;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSyntaxScanner;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextMarkerSelector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
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
     * Every path from a generated image wrapper down to the <img> it holds.
     * An unlinked image is the wrapper's direct child; a linked one sits
     * inside the anchor the native block serializes for the link.
     *
     * @var list<string>
     */
    private const GENERATED_IMAGE_LEAF_PATHS = array( ' > img', ' > a > img' );

    public function __construct(
        private readonly StyleResolver $styleResolver,
        private readonly AuthorSelectorSemanticPreparer $semanticPreparer,
        private readonly AuthorStyleRuleProjector $ruleBodyProjector
    ) {}

    /**
     * @param list<string> $outerConditions Conditions that scope the whole
     *     stylesheet from outside its text, such as a link's media attribute.
     *     They reach only the rule-body projections that compare against the
     *     analyzed source cascade, which already reads the stylesheet under them.
     */
    public function project(string $stylesheet, AuthorStylesheetProjectionContext $context, array $outerConditions = array()): string
    {
        $projected = ( new CssStylesheetTransformer() )->transformStyleRules(
            $stylesheet,
            fn (string $prelude, string $body, array $ancestors = array()): string => $this->projectStyleRule($prelude, $body, $context, self::ancestorsAreConditional($ancestors), $ancestors, $outerConditions)
        );
        $ids = ScopedAnchorSelectorProjection::identities($context->authorStyles);
        if (array() === $ids) return $projected;
        return (new CssStylesheetTransformer())->transformStyleRules($projected, static fn(string $selector, string $body): string => ScopedAnchorSelectorProjection::selector($selector, $ids) . '{' . $body . '}');
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
        $lifted = ColorSchemeVariant::liftPrelude($prelude);
        $css = $this->emitProjectedStyleRule($lifted['prelude'], $body, $context, $inConditional, array( ...$outerConditions, ...$ancestors ));

        return ColorSchemeVariant::wrap($css, $lifted['scheme'], $ancestors);
    }

    /** @param list<string> $conditions At-rules the rule sits inside, outermost first. */
    private function emitProjectedStyleRule(string $prelude, string $body, AuthorStylesheetProjectionContext $context, bool $inConditional = false, array $conditions = array()): string
    {
        $parts = str_contains($body, '{') ? (new CssStylesheetTransformer())->splitStyleRuleBody($body) : array();
        $hasNestedRules = array_filter($parts, static fn (array $part): bool => isset($part['prelude']));
        $hasMargins = $hasNestedRules && array_filter(
            $this->styleResolver->verbatimCssDeclarations($body),
            static fn (string $name): bool => 'margin' === $name || str_starts_with($name, 'margin-'),
            ARRAY_FILTER_USE_KEY
        );
        if ( $hasMargins ) {
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
        $margins = array_filter($declarations, static fn (string $name): bool => 'margin' === $name || str_starts_with($name, 'margin-'), ARRAY_FILTER_USE_KEY);
        $mediaTextImagePrelude = $this->projectMediaTextImagePrelude($prelude, $context);
        if ( '' !== $mediaTextImagePrelude ) {
            return $mediaTextImagePrelude . '{' . $body . '}';
        }
        $imagePrelude = $this->projectAuthorImageSelectorPrelude($prelude, $context);
        $svgImagePrelude = $this->projectAuthorImageSelectorPrelude($prelude, $context, 'svg', $declarations);
        $imageRule = '' === $imagePrelude
            ? ''
            : $imagePrelude . '{' . $this->imageProjectionBridgeDeclarations($declarations) . '}';
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
        }
        $svgImageRule = '' === $svgImagePrelude
            ? ''
            : $svgImagePrelude . '{' . $this->imageProjectionBridgeDeclarations($declarations, true) . '}';
        $editorDocumentRootRule = $this->editorDocumentRootRule($prelude, $body, $context);
        $navigationItemSpaceRule = $this->navigationItemSpaceRule($prelude, $declarations, $context);
        if ( array() === $margins ) {
            $css = $this->rewriteStyleRule($prelude, $body, $context, $inConditional) . $imageRule . $svgImageRule . $editorDocumentRootRule . $navigationItemSpaceRule;

            return $this->withEditorProjectionRules($css, $prelude, $body);
        }

        $inner = array_diff_key($declarations, $margins);
        $rules = '' === $this->styleResolver->cssDeclarationString($inner)
            ? ''
            : $this->rewriteStyleRule($prelude, $this->styleResolver->cssDeclarationString($inner), $context, $inConditional);
        $css = $rules
            . $this->marginSelectorPrelude($prelude, $context) . '{' . $this->styleResolver->cssDeclarationString($margins) . '}'
            . $imageRule
            . $svgImageRule
            . $editorDocumentRootRule
            . $navigationItemSpaceRule;

        return $this->withEditorProjectionRules($css, $prelude, $body);
    }

    private function withEditorProjectionRules(string $css, string $prelude, string $body): string
    {
        $css .= $this->visuallyHiddenSourceSelectorRule($prelude, $css, $body);

        return $css . $this->editorPositionRules($css) . $this->editorShellChildCombinatorVariants($css);
    }

    private function visuallyHiddenSourceSelectorRule(string $prelude, string $projectedCss, string $body): string
    {
        if ( ! CssValueInspector::isVisuallyClippedBox($this->styleResolver->cssDeclarations($body)) ) {
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

    private function editorPositionRules(string $css): string
    {
        return ( new CssStylesheetTransformer() )->transformStyleRules(
            $css,
            function (string $prelude, string $body): string {
                $position = trim((string) ($this->styleResolver->cssDeclarations($body)['position'] ?? ''));
                $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
                if ( '' === $position || null === $selectors ) {
                    return '';
                }
                $editorSelectors = array();
                foreach ( $selectors as $selector ) {
                    $selector = trim($selector);
                    if ( '' === $selector || str_starts_with($selector, ':host') || str_contains($selector, '.editor-styles-wrapper') ) {
                        continue;
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
                if ( 'fixed' === strtolower($position) ) {
                    // Core's editor canvas is position:relative. Re-forcing
                    // source `position:fixed` with !important pins site chrome
                    // (sticky headers, FABs) over the document being edited.
                    // Absolute layers still need the override so hero overlays
                    // keep stacking; leave relative/sticky unforced.
                    return '';
                }

                return array() === $editorSelectors
                    ? ''
                    // Core adds `position:relative` to the actual editor block
                    // root. Preserve source visual layers without changing
                    // ordinary relative or sticky editing surfaces.
                    : implode(',', $editorSelectors) . '{position:' . $position . ( 'absolute' === strtolower($position) ? '!important' : '' ) . '}';
            }
        );
    }

    /**
     * The editor canvas renders a layout shell's inner blocks inside one
     * Gutenberg-owned layer (EngineSupportCss::LAYOUT_SHELL_EDITOR_INNER_BLOCKS_CLASS)
     * that the saved front-end markup does not have. An authored child
     * combinator whose two sides land on opposite sides of that layer — a
     * source wrapper folded into the shell and the root of a block nested
     * inside it — matches on the front end and silently stops matching in
     * the editor, so authored cascade decisions such as font-size
     * inheritance revert to engine projections. Emit editor-scoped variants
     * whose child combinators reach through the marked layer. The layer
     * class never exists in saved markup and the editor-styles-wrapper
     * prefix never matches the front end, so every variant is inert outside
     * the editor canvas.
     */
    private function editorShellChildCombinatorVariants(string $css): string
    {
        if ( ! str_contains($css, '>') ) {
            return '';
        }
        return ( new CssStylesheetTransformer() )->transformStyleRules(
            $css,
            function (string $prelude, string $body): string {
                $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
                if ( null === $selectors ) {
                    return '';
                }
                $variants = array();
                foreach ( $selectors as $selector ) {
                    foreach ( $this->shellLayerCombinatorVariants(trim($selector)) as $variant ) {
                        $variants[] = ':root .editor-styles-wrapper ' . $variant;
                    }
                }
                return array() === $variants ? '' : implode(',', $variants) . '{' . $body . '}';
            }
        );
    }

    /** @return list<string> */
    private function shellLayerCombinatorVariants(string $selector): array
    {
        if ( str_contains($selector, EngineSupportCss::LAYOUT_SHELL_EDITOR_INNER_BLOCKS_CLASS) ) {
            return array();
        }
        $combinators = $this->shellCrossingCombinatorOffsets($selector);
        if ( null === $combinators || array() === $combinators ) {
            return array();
        }
        // One authored selector rarely crosses more than a couple of shell
        // boundaries, so relax every subset while the count stays small;
        // past that, single-combinator relaxations keep the emission bounded.
        $subsets = 3 >= count($combinators)
            ? $this->combinatorSubsets($combinators)
            : array_map(static fn (int $offset): array => array($offset), $combinators);
        $variants = array();
        foreach ( $subsets as $subset ) {
            $variants[] = $this->selectorRelaxingChildCombinators($selector, $subset);
        }
        return array_values(array_unique($variants));
    }

    /**
     * Top-level `>` combinator offsets that could cross the shell layer, or
     * null when the selector is malformed CSS. Combinators whose child side
     * addresses native block internals (core/button RichText bridges) are
     * engine-generated relationships inside one block; the shell layer can
     * never sit between them.
     *
     * @return list<int>|null
     */
    private function shellCrossingCombinatorOffsets(string $selector): ?array
    {
        $offsets = $this->childCombinatorOffsets($selector);
        if ( null === $offsets ) {
            return null;
        }
        $length = strlen($selector);
        return array_values(array_filter(
            $offsets,
            static function (int $offset) use ($selector, $length): bool {
                $state = CssSyntaxScanner::state();
                $index = $offset + 1;
                while ( $index < $length && CssSyntaxScanner::isCssWhitespace($selector[ $index ] ) ) {
                    ++$index;
                }
                $compound = '';
                for ( ; $index < $length; ++$index ) {
                    $topLevel = CssSyntaxScanner::isTopLevel($state);
                    $next = CssSyntaxScanner::consume($selector, $index, $state);
                    if ( null === $next ) {
                        return true;
                    }
                    if ( $topLevel && $next === $index + 1 && in_array($selector[ $index ], array( '>', '+', '~' ), true) ) {
                        break;
                    }
                    if ( $topLevel && $next === $index + 1 && ',' === $selector[ $index ] ) {
                        break;
                    }
                    $compound .= $selector[ $index ];
                    $index = $next - 1;
                }
                return ! str_starts_with(ltrim($compound), ':where(.wp-block-');
            }
        ));
    }

    /**
     * Top-level `>` combinator offsets, or null when the selector is
     * malformed CSS. Combinators inside functions, attribute selectors,
     * strings, or comments never qualify.
     *
     * @return list<int>|null
     */
    private function childCombinatorOffsets(string $selector): ?array
    {
        $state = CssSyntaxScanner::state();
        $length = strlen($selector);
        $offsets = array();
        for ( $index = 0; $index < $length; ++$index ) {
            $next = CssSyntaxScanner::consume($selector, $index, $state);
            if ( null === $next ) {
                return null;
            }
            if ( CssSyntaxScanner::isTopLevel($state) && $next === $index + 1 && '>' === $selector[ $index ] ) {
                $offsets[] = $index;
            }
            $index = $next - 1;
        }
        return CssSyntaxScanner::isComplete($state) ? $offsets : null;
    }

    /**
     * @param list<int> $offsets
     * @return list<list<int>>
     */
    private function combinatorSubsets(array $offsets): array
    {
        $subsets = array();
        $count = count($offsets);
        for ( $mask = 1; $mask < ( 1 << $count ); ++$mask ) {
            $subset = array();
            foreach ( $offsets as $index => $offset ) {
                if ( $mask & ( 1 << $index ) ) {
                    $subset[] = $offset;
                }
            }
            $subsets[] = $subset;
        }
        return $subsets;
    }

    /**
     * @param list<int> $offsets
     */
    private function selectorRelaxingChildCombinators(string $selector, array $offsets): string
    {
        $insertion = ' :where(.' . EngineSupportCss::LAYOUT_SHELL_EDITOR_INNER_BLOCKS_CLASS . ')>';
        $relaxed = '';
        $cursor = 0;
        foreach ( $offsets as $offset ) {
            $combinator = $offset;
            while ( $combinator > $cursor && CssSyntaxScanner::isCssWhitespace($selector[ $combinator - 1 ]) ) {
                --$combinator;
            }
            $relaxed .= substr($selector, $cursor, $combinator - $cursor) . $insertion;
            $cursor = $offset + 1;
        }
        return $relaxed . substr($selector, $cursor);
    }

    private function marginSelectorPrelude(string $prelude, AuthorStylesheetProjectionContext $context): string
    {
        $buttonPresentationPseudoPrelude = $this->buttonPresentationPseudoPrelude($prelude, $context);
        if ( '' !== $buttonPresentationPseudoPrelude ) {
            return $buttonPresentationPseudoPrelude;
        }
        $projected = $this->rewriteSelectorPrelude($prelude, $context, true);
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

    private function rewriteStyleRule(string $prelude, string $body, AuthorStylesheetProjectionContext $context, bool $inConditional = false): string
    {
        $buttonPresentationPseudoPrelude = $this->buttonPresentationPseudoPrelude($prelude, $context);
        if ( '' !== $buttonPresentationPseudoPrelude ) {
            return $buttonPresentationPseudoPrelude . '{' . $body . '}';
        }
        $projectedPrelude = $this->rewriteSelectorPrelude($prelude, $context);
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
        $wrapperPrelude = $this->buttonPresentationWrapperPrelude($prelude, $context);
        if ( '' === $wrapperPrelude ) {
            $directWrapperPrelude = $this->directButtonGeometryWrapperPrelude($prelude, $context);
            if ( '' === $directWrapperPrelude ) {
                $mixedButtonProjection = $this->withoutCollapsedButtonProjectedWidths($projectedPrelude, $body);
                return $nonButtonLinkRule . ( null !== $mixedButtonProjection ? $mixedButtonProjection : $projectedPrelude . '{' . $body . '}' ) . $nativeButtonCompatRule;
            }
            [ $placement, $geometry, $inner ] = $this->splitDirectButtonGeometryDeclarations($body);
            $placementPrelude = $this->directButtonPlacementWrapperPrelude($prelude, $context);
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
            return $nonButtonLinkRule . $placementRule . $this->withButtonWrapperInnerFill($directWrapperPrelude, $geometry, ( '' === $inner ? '' : $projectedPrelude . '{' . $inner . '}' ) . $nonButtonGeometry, $inConditional) . $nativeButtonCompatRule;
        }

        [ $layout, $control ] = $this->splitButtonPresentationDeclarations($body);
        $nonButtonLayoutPrelude = $this->withoutButtonPresentationProjectionSelectors($projectedPrelude, $wrapperPrelude);
        $nonButtonLayout = '' === $nonButtonLayoutPrelude ? '' : $nonButtonLayoutPrelude . '{' . $layout . '}';
        if ( '' === $layout ) {
            return $nonButtonLinkRule . ( '' === $control ? '' : $projectedPrelude . '{' . $control . '}' ) . $nativeButtonCompatRule;
        }
        if ( '' === $control ) {
            return $nonButtonLinkRule . $this->withButtonWrapperInnerFill($wrapperPrelude, $layout, $nonButtonLayout, $inConditional) . $nativeButtonCompatRule;
        }
        return $nonButtonLinkRule . $this->withButtonWrapperInnerFill($wrapperPrelude, $layout, $projectedPrelude . '{' . $control . '}' . $nonButtonLayout, $inConditional) . $nativeButtonCompatRule;
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
                array_push($rewritten, ...($this->projectSourceAttributePseudoSelector($selector, $context) ?? array($selector)));
                continue;
            }
            foreach ( array_unique($markers) as $marker ) {
                $rewritten[] = $this->projectControlSelector($baseSelector, $parsed, $marker, $context) . $matches[2];
            }
        }

        return implode(',', array_values(array_unique($rewritten)));
    }

    /**
     * core/button defaults and support styles are emitted after carried author CSS.
     * Keep source button declarations authoritative after their selector is lowered
     * to a generated marker. Padding preserves authored importance: responsive
     * padding lives in these selectors, while explicit source/editor values
     * retain their native inline priority.
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
            if ( '' === $name || '' === $value || ! $this->isButtonLinkLayoutProperty($name) || preg_match('/\s*!important\s*$/i', $value) ) {
                $declarations[] = $declaration;
                continue;
            }
            // Conditional families remain stylesheet-owned rather than carrying
            // an inline base. Preserve padding's authored importance so explicit
            // source/editor padding keeps its normal priority at every width.
            if ( 'padding' === $name || str_starts_with($name, 'padding-') ) {
                $declarations[] = $declaration;
                continue;
            }
            $declarations[] = $name . ':' . $value . '!important';
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
            foreach ( $this->matchingSourceElements($selector, $parsed, $context) as $element ) {
                $path = $element->getNodePath() ?? '';
                $marker = $context->selectorProjections->controlMarker($path);
                if ( 'button' !== strtolower($element->tagName) || '' === $marker || $this->isSpecializedNativeButton($element, $body) ) {
                    continue;
                }
                $projected[] = $this->nativeButtonControlSelector($selector, $parsed, $marker, $context);
            }
        }
        if ( array() === $projected ) {
            return '';
        }

        $declarations = array();
        foreach ( CssValueSplitter::splitTopLevel($body, array( ';' )) as $declaration ) {
            $colon = strpos($declaration, ':');
            if ( false === $colon ) {
                continue;
            }
            $name = trim(substr($declaration, 0, $colon));
            $value = trim(substr($declaration, $colon + 1));
            if ( '' === $name || '' === $value || ( ! $this->isButtonLinkLayoutProperty($name) && ! $this->isButtonLinkPresentationProperty($name) ) ) {
                continue;
            }
            $exclusion = $this->nativeButtonEditedPropertyExclusion($name);
            $declarations[$exclusion][] = preg_match('/\s*!important\s*$/i', $value) ? $name . ':' . $value : $name . ':' . $value . '!important';
        }
        if ( array() === $declarations ) {
            return '';
        }
        $rules = array();
        foreach ( $declarations as $exclusion => $properties ) {
            foreach ( array_values(array_unique($projected)) as $selector ) {
                $rules[] = $selector . $exclusion . '{' . implode(';', $properties) . '}';
            }
        }
        return implode('', $rules);
    }

    private function nativeButtonControlSelector(string $selector, array $parsed, string $marker, AuthorStylesheetProjectionContext $context): string
    {
        $projected = $this->projectControlSelector($selector, $parsed, $marker, $context);
        // Generated button style variations can carry later important declarations.
        // Keep the source-owned control selector specific to its exact target.
        return str_replace(':where(.' . $marker . ')', ':where(.' . $marker . ').' . $marker . '.' . $marker, $projected);
    }

    private function nativeButtonEditedPropertyExclusion(string $property): string
    {
        if ( str_starts_with($property, 'background') ) {
            return ':not([style*="background"])';
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
            return ':not([style*="color:"])';
        }
        return ':not([style*="text-decoration"])';
    }

    private function isSpecializedNativeButton(DOMElement $element, string $body): bool
    {
        return $this->hasNativeButtonGeometryDeclaration($body);
    }

    private function hasNativeButtonGeometryDeclaration(string $body): bool
    {
        if ( preg_match('/(?:^|;)\s*position\s*:\s*(?:absolute|fixed)/i', $body) && preg_match('/(?:^|;)\s*(?:width|height)\s*:\s*0(?:[;\s]|$)/i', $body) ) {
            return true;
        }
        foreach ( CssValueSplitter::splitTopLevel($body, array( ';' )) as $declaration ) {
            $colon = strpos($declaration, ':');
            if ( false !== $colon && $this->isButtonControlBoxSize(trim(substr($declaration, 0, $colon)), trim(substr($declaration, $colon + 1))) ) {
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
            if ( false !== $colon && $this->isCollapsedButtonKeywordWidth($name, $value) ) {
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
                $rewritten[] = ':where(.' . $marker . ')' . $this->selectorSpecificityShims($parsed, $context);
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
                $box = LayoutParticipation::resolve($element, $this->styleResolver)->participatingBox();
                if ( LayoutParticipation::BOX_BUTTON_WRAPPER === $box ) {
                    return $this->projectButtonBoxSelector($selector, $parsed, $marker, $context);
                }
                if ( LayoutParticipation::BOX_LINK === $box ) {
                    return $this->projectControlSelector($selector, $parsed, $marker, $context, false);
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
            fn (string $selector, array $parsed, string $marker): string => $this->projectButtonBoxSelector($selector, $parsed, $marker, $context)
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

    /** @return array{string, string, string} */
    private function splitDirectButtonGeometryDeclarations(string $body): array
    {
        $placement = array();
        $geometry = array();
        $inner = array();
        $placementVars = $this->buttonPlacementCustomProperties($body);
        $wrapperOwned = array(
            'position', 'top', 'right', 'bottom', 'left', 'z-index',
            'width', 'min-width', 'max-width', 'height', 'min-height', 'max-height',
            'grid-area', 'grid-column', 'grid-row',
            'grid-column-start', 'grid-column-end', 'grid-row-start', 'grid-row-end',
            'align-self', 'justify-self', 'order',
                    // Flex sizing is item participation, like the `align-self` and
                    // `order` above it: it describes how the box behaves inside the
                    // author's flex container. These declarations travel with
                    // LayoutParticipation::participatingBox() so neutralizing a
                    // wrapper cannot drop them.
            'flex', 'flex-grow', 'flex-shrink', 'flex-basis',
        );
        foreach ( CssValueSplitter::splitTopLevel($body, array( ';' )) as $declaration ) {
            $colon = strpos($declaration, ':');
            $name = strtolower(trim(false === $colon ? $declaration : substr($declaration, 0, $colon)));
            $value = false === $colon ? '' : trim(substr($declaration, $colon + 1));
            if ( $this->isCollapsedButtonKeywordWidth($name, $value) ) {
                continue;
            }
            $places = '' !== $name && false !== $colon && $this->declarationPlacesTheButton($name, $placementVars);
            $owned = '' !== $name && false !== $colon && in_array($name, $wrapperOwned, true)
                && ! $this->isButtonControlBoxSize($name, $value);
            if ( $places ) {
                $placement[] = $declaration;
            } elseif ( $owned ) {
                $geometry[] = $declaration;
            } else {
                $inner[] = $declaration;
            }
        }
        return array( implode(';', $placement), implode(';', $geometry), implode(';', $inner) );
    }

    /** @return array{string, string} */
    private function splitButtonPresentationDeclarations(string $body): array
    {
        $layout = array();
        $control = array();
        $placementVars = $this->buttonPlacementCustomProperties($body);
        foreach ( CssValueSplitter::splitTopLevel($body, array( ';' )) as $declaration ) {
            $colon = strpos($declaration, ':');
            $name = strtolower(trim(false === $colon ? $declaration : substr($declaration, 0, $colon)));
            $value = false === $colon ? '' : trim(substr($declaration, $colon + 1));
            if ( '' === $name || false === $colon ) {
                $control[] = $declaration;
                continue;
            }
            if ( $this->isCollapsedButtonKeywordWidth($name, $value) ) {
                continue;
            }
            if ( $this->declarationPlacesTheButton($name, $placementVars)
                || ( $this->isButtonWrapperLayoutProperty($name) && ! $this->isButtonControlBoxSize($name, $value) )
            ) {
                $layout[] = $declaration;
            } else {
                $control[] = $declaration;
            }
        }
        return array( implode(';', $layout), implode(';', $control) );
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

    private function withButtonWrapperInnerFill(string $wrapperPrelude, string $layoutCss, string $rest = '', bool $clearInnerAutoHeight = false): string
    {
        $css = $wrapperPrelude . '{' . $layoutCss . '}';
        $hasDefiniteWidth = CssValueInspector::hasDefiniteWidth($layoutCss);
        $hasDefiniteHeight = CssValueInspector::hasDefiniteHeight($layoutCss);
        $hasAutoHeight = CssValueInspector::hasAutoHeight($layoutCss);
        $hasMinimumHeight = CssValueInspector::hasAuthoredMinimumHeight($layoutCss);
        if ( $hasDefiniteWidth || $hasDefiniteHeight || $hasAutoHeight || $hasMinimumHeight ) {
            $selectors = CssStylesheetTransformer::splitSelectorList($wrapperPrelude) ?? array( $wrapperPrelude );
            if ( str_contains($wrapperPrelude, '.wp-block-button__link)') ) {
                return $css . $rest;
            }
            $targetsButtonBox = str_contains($wrapperPrelude, '.wp-block-button)') && ! str_contains($wrapperPrelude, '.wp-block-buttons)');
            if ( $targetsButtonBox ) {
                $button = implode(',', array_map(static fn (string $selector): string => rtrim($selector), $selectors));
                $link = implode(',', array_map(static fn (string $selector): string => rtrim($selector) . '> :where(.wp-block-button__link)', $selectors));
            } else {
                $button = implode(',', array_map(static fn (string $selector): string => rtrim($selector) . '> :where(.wp-block-button)', $selectors));
                $link = implode(',', array_map(static fn (string $selector): string => rtrim($selector) . '> :where(.wp-block-button)> :where(.wp-block-button__link)', $selectors));
            }
            if ( $hasDefiniteWidth ) {
                $css .= $button . '{width:100%!important}'
                    . $link . '{width:100%!important;max-width:100%!important}';
            }
            if ( $hasDefiniteHeight ) {
                $css .= $button . '{height:100%!important}'
                    . $link . '{height:100%!important}';
            } elseif ( $hasAutoHeight && $clearInnerAutoHeight ) {
                $css .= $button . '{height:auto!important}'
                    . $link . '{height:auto!important}';
            }
            if ( $hasMinimumHeight ) {
                // Percentage heights cannot resolve through an auto-height wrapper.
                // Inherit the wrapper's authored computed minimum on both carriers.
                $css .= $button . '{min-height:inherit!important}'
                    . $link . '{min-height:inherit!important}';
            }
        }
        return $css . $rest;
    }

    private function isButtonControlBoxSize(string $property, string $value): bool
    {
        if ( ! in_array($property, array( 'width', 'min-width', 'max-width', 'height', 'min-height', 'max-height' ), true) ) {
            return false;
        }
        $value = strtolower(CssValueInspector::withoutImportant($value));
        return in_array($value, array( 'min-content', 'max-content', 'fit-content', 'content' ), true);
    }

    private function isCollapsedButtonKeywordWidth(string $property, string $value): bool
    {
        $value = strtolower(CssValueInspector::withoutImportant($value));
        if ( 'min-content' !== $value ) {
            return false;
        }
        return in_array($property, array( 'width', 'min-width', 'max-width' ), true)
            || (str_starts_with($property, '--') && str_contains($property, 'width'));
    }

    private function collapsedButtonKeywordWidthDeclarations(string $body): string
    {
        $collapsed = array();
        foreach ( CssValueSplitter::splitTopLevel($body, array( ';' )) as $declaration ) {
            $colon = strpos($declaration, ':');
            $name = strtolower(trim(false === $colon ? $declaration : substr($declaration, 0, $colon)));
            $value = false === $colon ? '' : trim(substr($declaration, $colon + 1));
            if ( false !== $colon && $this->isCollapsedButtonKeywordWidth($name, $value) ) {
                $collapsed[] = $declaration;
            }
        }
        return implode(';', $collapsed);
    }

    private function isButtonWrapperLayoutProperty(string $property): bool
    {
        return $this->isButtonPlacementProperty($property)
            || in_array($property, array(
                'align-content', 'align-items', 'align-self', 'clear', 'display', 'float',
                'flex', 'flex-basis', 'flex-direction', 'flex-flow', 'flex-grow', 'flex-shrink',
                'flex-wrap', 'gap', 'grid', 'grid-area', 'grid-auto-columns', 'grid-auto-flow',
                'grid-auto-rows', 'grid-column', 'grid-row', 'grid-template', 'grid-template-areas',
                'grid-template-columns', 'grid-template-rows', 'isolation', 'justify-content',
                'justify-items', 'justify-self', 'order', 'overflow', 'overflow-x', 'overflow-y',
                'place-content', 'place-items', 'place-self', 'position', 'top', 'right', 'bottom',
                'left', 'z-index', 'width', 'min-width', 'max-width', 'height', 'min-height', 'max-height',
            ), true);
    }

    private function isButtonPlacementProperty(string $property): bool
    {
        return in_array($property, array(
            'translate',
            'rotate',
            'scale',
            'transform',
            '-webkit-transform',
            'transform-origin',
            'offset',
        ), true) || str_starts_with($property, 'offset-');
    }

    /**
     * Custom properties consumed by a placement declaration belong on the same
     * box. `translate: var(--shift)` on the wrapper is inert if `--shift` is
     * left on the inner link.
     *
     * @return array<string, true>
     */
    private function buttonPlacementCustomProperties(string $body): array
    {
        $referenced = array();
        foreach ( CssValueSplitter::splitTopLevel($body, array( ';' )) as $declaration ) {
            $colon = strpos($declaration, ':');
            if ( false === $colon ) {
                continue;
            }
            $name = strtolower(trim(substr($declaration, 0, $colon)));
            $value = trim(substr($declaration, $colon + 1));
            if ( ! $this->isButtonPlacementProperty($name) ) {
                continue;
            }
            if ( preg_match_all('/var\(\s*(--[A-Za-z0-9_-]+)/', $value, $matches) ) {
                foreach ( $matches[1] as $property ) {
                    $referenced[strtolower($property)] = true;
                }
            }
        }

        return $referenced;
    }

    /** @param array<string, true> $placementVars */
    private function declarationPlacesTheButton(string $property, array $placementVars): bool
    {
        return $this->isButtonPlacementProperty($property) || isset($placementVars[$property]);
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

    private function rewriteSelectorPrelude(string $prelude, AuthorStylesheetProjectionContext $context, bool $controlWrapper = false): string
    {
        $projected = $this->rewriteSelectorPreludeOnce($prelude, $context, $controlWrapper);
        if ( ! $context->selectorProjections->hasAncestorAttributeStateConditions() ) {
            return $projected;
        }
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors ) {
            return $projected;
        }
        $variants = array();
        foreach ( $selectors as $selector ) {
            $conditions = $context->selectorProjections->ancestorAttributeStateConditions(trim($selector));
            if ( array() === $conditions ) {
                continue;
            }
            // Derive the class form from this selector's own projection so it
            // inherits every per-element safeguard (superseded toggles,
            // rich-text exclusions, control and editor variants).
            $own = 1 === count($selectors) ? $projected : $this->rewriteSelectorPreludeOnce($selector, $context, $controlWrapper);
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

    private function rewriteSelectorPreludeOnce(string $prelude, AuthorStylesheetProjectionContext $context, bool $controlWrapper = false): string
    {
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors ) {
            return $prelude;
        }
        $rewritten = array();
        foreach ( $selectors as $selector ) {
            $structuralParsed = $context->sourceStyles->parsedSelector($selector);
            $subject = $structuralParsed['compounds'][0] ?? array();
            if (($structuralParsed['supported'] ?? false) && 1 === count($structuralParsed['compounds'] ?? array())
                && null === ($subject['type'] ?? null) && !($subject['universal'] ?? false)
                && array() !== ($subject['classes'] ?? array())
                && array() === array_merge($subject['ids'] ?? array(), $subject['attributes'] ?? array(), $subject['not'] ?? array(), $subject['any'] ?? array())
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
            $pseudoProjection = $this->projectSourceAttributePseudoSelector($selector, $context);
            if (null !== $pseudoProjection && array() !== $pseudoProjection) {
                array_push($rewritten, ...$pseudoProjection);
                continue;
            }
            $selector = $this->projectSourceAttributeNegationStateSelector($selector, $context);
            $parsed = $context->sourceStyles->parsedSelector($selector);
            if ( ! $parsed['supported'] ) {
                $projectedControls = $this->projectUnsupportedFunctionalControlSelector($selector, $context, $controlWrapper);
                if ( array() === $projectedControls ) {
                    $rewritten[] = $this->projectSourceClassSelector($this->projectImageLinkIdentitySelector($selector, $context), $context);
                } else {
                    array_push($rewritten, ...$projectedControls);
                }
                continue;
            }
            $matches = $this->matchingSourceElements($selector, $parsed, $context);
            if ( array() === $matches ) {
                $dormantControls = $this->projectDormantAncestorControlSelector($selector, $parsed, $context, $controlWrapper);
                if ( array() !== $dormantControls ) {
                    array_push($rewritten, ...$dormantControls);
                    continue;
                }
                $rewritten[] = $this->rewriteSourceTagTypes($selector, $parsed, $context);
                continue;
            }

            $attributeAncestryProjection = $this->projectSourceAttributeAncestrySelector($selector, $parsed, $matches, $context);
            if ( null !== $attributeAncestryProjection ) {
                array_push($rewritten, ...$attributeAncestryProjection);
                continue;
            }
            $attributeProjection = $this->projectSourceAttributeSelector($selector, $parsed, $matches, $context);
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
                    static function (DOMElement $element) use ($shellTags, $context): string {
                        return in_array(strtolower($element->tagName), $shellTags, true)
                            ? ''
                            : $context->selectorProjections->rootChildMarker($element->getNodePath() ?? '');
                    },
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
                    $rewritten[] = ':where(' . $tag . '.wp-block-template-part)' . $this->selectorSpecificityShims($parsed, $context);
                    // Core keeps this transport wrapper in the editor but removes it
                    // from the frontend response for inline shell parts.
                    $rewritten[] = ':root .editor-styles-wrapper :where(.wp-block-template-part):has(> ' . $tag . ')' . $this->selectorSpecificityShims($parsed, $context);
                }
                continue;
            }

            $tableDescendants = array();
            $nonTableMatches = array();
            foreach ( $matches as $element ) {
                $projected = $this->projectTableDescendantSelector($selector, $parsed, $element, $context);
                if ( null === $projected ) {
                    $nonTableMatches[] = $element;
                } else {
                    $tableDescendants[] = $projected;
                }
            }
            foreach ( array_values(array_unique($tableDescendants)) as $projected ) {
                $rewritten[] = $projected;
            }
            if ( array() === $nonTableMatches ) {
                continue;
            }
            $matches = $nonTableMatches;

            // Every match is a direct anchor core re-parents into a list item of
            // its own, so the subject's sibling position belongs to that item.
            // Any other match, or a subject this projection cannot place, keeps
            // the authored selector exactly.
            if ( $this->matchesOnlyNavigationItemAnchors($matches, $context) ) {
                $itemSelector = $this->projectNavigationItemAnchorSelector($selector, $parsed, $context);
                if ( null !== $itemSelector ) {
                    $rewritten[] = $itemSelector;
                    continue;
                }
            }
            // Every match is a source list item core renders as a navigation
            // item of its own. The source-type marker never reaches that
            // rendered item, so the subject moves onto core's item class.
            if ( $this->matchesOnlyNavigationListItems($matches, $context) ) {
                $itemSelector = $this->projectNavigationListItemSelector($selector, $parsed, $context);
                if ( null !== $itemSelector ) {
                    $rewritten[] = $itemSelector;
                    continue;
                }
            }
            // Every match is a source list that is itself the element a
            // core/navigation block stands in for. Its classes and id sit on
            // the rendered `<nav>` (and on the inner list copy); the list type
            // has to address that block rather than the copy.
            if ( $this->matchesOnlyNavigationListHosts($matches, $context) ) {
                $hostSelector = $this->projectNavigationListHostSelector($selector, $parsed, $context);
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
            $typeSubject = null !== (($parsed['compounds'][array_key_last($parsed['compounds'])] ?? array())['type'] ?? null);
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
                    if ( $this->isHoistedCardFragment($element) ) {
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
                $rewritten[] = $this->rewriteSourceTagTypes($selector, $parsed, $context, $insertion);
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
            if ( $hasNonProjected ) {
                $rewritten[] = $this->rewriteSourceTagTypes(
                    $selector,
                    $parsed,
                    $context,
                    ':not(:where(.' . implode(',.', $projectedMarkers) . '))' . ( $supersededToggles ? self::NAVIGATION_TOGGLE_CHROME_EXCLUSION : '' )
                );
            }
            foreach ( $controls as $marker ) {
                $rewritten[] = $this->projectControlSelector($selector, $parsed, $marker, $context, $controlWrapper && ! isset($linkOwnedControls[$marker]));
            }
            foreach ( $semanticLeaves as $marker ) {
                $rewritten[] = $this->projectSemanticLeafSelector($selector, $parsed, $marker, $context);
            }
            foreach ( $richTextLeaves as $marker ) {
                $rewritten[] = $this->projectRichTextSemanticSelector($selector, $parsed, $marker, $context);
            }
            if ( $inlineLayoutCarriers ) {
                $rewritten[] = $this->projectInlineLayoutCarrierSelector($selector, $parsed, $addressableInlineCarriers);
            }
        }
        $projected = implode(',', $rewritten);
        if ( $projected !== $prelude && EngineMarker::matchesAny($projected) ) {
            $context->bindings->record($prelude, $projected);
        }

        return $projected;
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

    /** @param array<string, mixed> $parsed @return list<string> */
    private function projectDormantAncestorControlSelector(
        string $selector,
        array $parsed,
        AuthorStylesheetProjectionContext $context,
        bool $wrapper
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

        $projected = array();
        foreach ( $this->matchingSourceElements($leafSelector, $leafParsed, $context) as $element ) {
            $marker = $context->selectorProjections->controlMarker($element->getNodePath() ?? '');
            if ( '' !== $marker ) {
                $projected[] = substr($selector, 0, (int) $rightmost['start'])
                    . $this->projectControlSelector($leafSelector, $leafParsed, $marker, $context, $wrapper);
            }
        }
        return array_values(array_unique($projected));
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
        foreach ( $context->authorStyles->sourceBody()->getElementsByTagName('*') as $element ) {
            if ( ! $element instanceof DOMElement || ! in_array(strtolower($element->tagName), array( 'a', 'button' ), true) ) {
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
                foreach ( preg_split('/\s+/', trim($element->getAttribute('class'))) ?: array() as $className ) {
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

            $target = ':where(.' . $marker . ($wrapper ? '.wp-block-buttons)' : ')> :where(.wp-block-button__link)') . $specificityShim;
            $projected = preg_replace($pattern, $target, $selector, 1);
            if ( is_string($projected) && '' !== $projected ) {
                $rewritten[] = $projected;
            }
        }
        return array_values(array_unique($rewritten));
    }

    private function editorDocumentRootRule(string $prelude, string $body, AuthorStylesheetProjectionContext $context): string
    {
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        $editorSelectors = array();
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
                $editorSelectors[] = ':root ' . substr($sourceSelector, 0, $end) . '.editor-styles-wrapper' . substr($sourceSelector, $end);
            } elseif ($root instanceof DOMElement && CssSelectorMatcher::matches($root, $parsed)['matches']) {
                $editorSelectors[] = $this->projectSourceClassSelector($selector, $context) . ' .editor-styles-wrapper';
            }
        }
        if (array() === $editorSelectors) {
            return '';
        }

        // Gutenberg establishes an explicit canvas presentation context, so
        // document-level body inheritance cannot otherwise win in the editor.
        $inheritedProperties = array(
            'color', 'direction', 'hyphens', 'letter-spacing', 'line-height',
            'tab-size', 'text-align', 'text-indent', 'text-shadow', 'text-transform',
            'visibility', 'white-space', 'word-spacing', 'writing-mode',
        );
        $declarations = array_filter(
            $this->styleResolver->cssDeclarations($body),
            static fn (string $name): bool => str_starts_with($name, '--')
                || 'font' === $name
                || str_starts_with($name, 'font-')
                || in_array($name, $inheritedProperties, true),
            ARRAY_FILTER_USE_KEY
        );
        $css = $this->styleResolver->cssDeclarationString($declarations);
        return '' === $css ? '' : implode(',', $editorSelectors) . '{' . $css . '}';
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

    /** @return list<string>|null */
    private function projectSourceAttributePseudoSelector(string $selector, AuthorStylesheetProjectionContext $context): ?array
    {
        $host = CssSelectorMatcher::pseudoElementHost($selector);
        if (null === $host) return null;
        $matches = $this->matchingSourceElements($host['selector'], $host['parsed'], $context);
        $projected = $this->projectSourceAttributeAncestrySelector($host['selector'], $host['parsed'], $matches, $context)
            ?? $this->projectSourceAttributeSelector($host['selector'], $host['parsed'], $matches, $context);
        return null === $projected ? null : array_map(static fn (string $target): string => $target . $host['suffix'], $projected);
    }

    /** @param array<string, mixed> $parsed @param list<DOMElement> $matches @return list<string>|null */
    private function projectSourceAttributeAncestrySelector(string $selector, array $parsed, array $matches, AuthorStylesheetProjectionContext $context): ?array
    {
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
        $projected = array();
        $scope = '';
        if ( preg_match('/^\s*((?::where\([^(),]+\)\s+)+)/i', $selector, $scopeMatch) ) {
            $scope = trim((string) $scopeMatch[1]) . ' ';
        }
        foreach ( $matches as $element ) {
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
            $projected[] = $scope . ':where(' . $target . ')' . $this->selectorSpecificityShims($parsed, $context);
        }
        return array_values(array_unique($projected));
    }

    /** @param array<string, mixed> $parsed @param list<DOMElement> $matches @return list<string>|null */
    private function projectSourceAttributeSelector(string $selector, array $parsed, array $matches, AuthorStylesheetProjectionContext $context): ?array
    {
        if ( null !== $parsed['pseudo_state_suffix_span'] ) {
            return null;
        }
        $compounds = $parsed['compounds'] ?? array();
        $rightmost = $compounds[array_key_last($compounds)] ?? array();
        if ( ! \Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorCompoundInspector::containsDataAttribute($rightmost)
            || \Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorCompoundInspector::hasLiveAttributePredicate($rightmost)
        ) {
            return null;
        }
        $projected = array();
        foreach ( $matches as $element ) {
            $marker = $context->selectorProjections->attributeMarker($element->getNodePath() ?? '', $selector);
            if ( '' === $marker ) {
                return null;
            }
            $projected[] = ':where(.' . $marker . ')' . $this->selectorSpecificityShims($parsed, $context);
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
    private function projectAuthorImageSelectorPrelude(string $prelude, AuthorStylesheetProjectionContext $context, string $tagName = 'img', array $declarations = array()): string
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
            $imageMatches = array_values(array_filter($matches, fn (DOMElement $element): bool => $tagName === strtolower($element->tagName) && ('svg' !== $tagName || $this->isProjectableFillSvg($element, $declarations))));
            if ( array() === $imageMatches ) {
                continue;
            }
            if ( AuthorSelectorSemanticPreparer::isRootChildSelector($parsed) ) {
                foreach ( $imageMatches as $element ) {
                    $marker = $context->selectorProjections->rootChildMarker($element->getNodePath() ?? '');
                    if ( '' !== $marker ) {
                        $projected[] = $this->imageLeafSelectorList($this->projectSemanticLeafSelector($selector, $parsed, $marker, $context) . '.wp-block-image');
                    }
                }
                continue;
            }
            if ( 'svg' === $tagName ) {
                $projected[] = $this->projectImageSelector($selector, $parsed, $context, true);
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
                    $hasUnmarkedImage = true;
                    continue;
                }
                // The source link's ID now belongs to the native figure. A
                // descendant selector cannot retain that former ancestry.
                $suffix = null === $parsed['pseudo_state_suffix_span'] ? '' : substr($selector, $parsed['pseudo_state_suffix_span']['start']);
                $projected[] = $this->imageLeafSelectorList(':where(.' . $marker . ').wp-block-image', $this->selectorSpecificityShims($parsed, $context) . $suffix);
            }
            if ( $hasUnmarkedImage ) {
                $projected[] = $this->projectImageSelector($selector, $parsed, $context);
            }
        }
        return implode(',', array_values(array_unique($projected)));
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
                    : $this->imageLeafSelectorList(':where(.' . $marker . ') .wp-block-media-text__media');
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
            if ( $image instanceof DOMElement && in_array($match[1], preg_split('/\s+/', trim($image->getAttribute('class'))) ?: array(), true) ) {
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
            $declarations = $this->styleResolver->cssDeclarations($this->styleResolver->mediaTextPresentationStyle($parent));
            if ( in_array(strtolower(trim((string) ($declarations['display'] ?? ''))), array( 'flex', 'grid' ), true)
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

    /** @param array<string, string> $declarations */
    private function imageProjectionBridgeDeclarations(array $declarations, bool $preserveObjectFit = false): string
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
        $bridge[] = 'max-width:100%';
        $objectFit = $this->explicitObjectFit($declarations);
        $bridge[] = 'object-fit:' . ($preserveObjectFit && '' !== $objectFit ? $objectFit : 'inherit');
        $bridge[] = 'object-position:inherit';
        $bridge[] = 'border-radius:inherit';
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
        foreach ( $parsed['type_spans'] as $typeSpan ) {
            // A caller that already replaced the compound holding this type has
            // decided where that type goes; the marker must not take it back.
            foreach ( $replacements as $replacementStart => $replacement ) {
                if ( (int) $typeSpan['start'] >= (int) $replacementStart && (int) $typeSpan['start'] < (int) $replacement['end'] ) {
                    continue 2;
                }
            }
            $marker = $context->selectorProjections->tagMarker((string) $typeSpan['name']);
            if ( '' !== $marker ) {
                $replacements[$typeSpan['start']] = array( 'end' => $typeSpan['end'], 'value' => ':where(.' . $marker . ')' . $this->typeSpecificityShim($context) );
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

    /** @param array<string, mixed> $parsed */
    private function projectControlSelector(string $selector, array $parsed, string $marker, AuthorStylesheetProjectionContext $context, bool $wrapper = false): string
    {
        $suffix = null === $parsed['pseudo_state_suffix_span'] ? '' : substr($selector, $parsed['pseudo_state_suffix_span']['start']);
        return ':where(.' . $marker . ')' . $this->selectorSpecificityShims($parsed, $context) . ($wrapper ? ':where(.wp-block-buttons)' : '> :where(.wp-block-button__link)') . $suffix;
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
        $suffix = null === $parsed['pseudo_state_suffix_span'] ? '' : substr($selector, $parsed['pseudo_state_suffix_span']['start']);
        return ':where(.' . $marker . ')' . $this->selectorSpecificityShims($parsed, $context) . $suffix;
    }

    /** @param array<string, mixed> $parsed */
    private function projectRichTextSemanticSelector(string $selector, array $parsed, string $marker, AuthorStylesheetProjectionContext $context): string
    {
        $suffix = null === $parsed['pseudo_state_suffix_span'] ? '' : substr($selector, $parsed['pseudo_state_suffix_span']['start']);
        return ':where(' . RichTextMarkerSelector::carrierSelectorList($marker) . ')' . $this->selectorSpecificityShims($parsed, $context) . $suffix;
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
        $table = 'table' === strtolower($element->tagName) ? $element : $this->ancestorElement($element, 'table');
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
        $table = $this->ancestorElement($element, 'table');
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

    private function serializedTableSection(DOMElement $element): string
    {
        return $this->ancestorElement($element, 'thead') instanceof DOMElement
            ? 'thead'
            : ($this->ancestorElement($element, 'tfoot') instanceof DOMElement ? 'tfoot' : 'tbody');
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

    private function ancestorElement(DOMElement $element, string $tagName): ?DOMElement
    {
        for ( $parent = $element->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) {
            if ( $tagName === strtolower($parent->tagName) ) {
                return $parent;
            }
        }
        return null;
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
            return $this->imageLeafSelectorList(
                ':where(.' . $mediaTextMarker . ') .wp-block-media-text__media',
                $this->selectorSpecificityShims($parsed, $context)
            );
        }
        $projected = array();
        foreach ( $wrapperOnly ? array( '' ) : self::GENERATED_IMAGE_LEAF_PATHS as $leafPath ) {
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
            $projected[] = $this->replaceSelectorSpans($selector, $replacements);
        }
        return implode(',', $projected);
    }

    /**
     * The generated <img> under `$wrapper`, reached through every shape the
     * native block markup can take. A linked image nests the <img> one level
     * deeper inside the anchor core/image and core/media-text serialize for
     * the link, and a rule projected onto the wrapper has to keep reaching it
     * there: the bridge declarations are what give the generated <img> the
     * box the source rule sized, so losing them collapses a linked image to
     * its intrinsic ratio while the identical unlinked image is fine.
     */
    private function imageLeafSelectorList(string $wrapper, string $suffix = ''): string
    {
        $selectors = array();
        foreach ( self::GENERATED_IMAGE_LEAF_PATHS as $leafPath ) {
            $selectors[] = $wrapper . $leafPath . $suffix;
        }
        return implode(',', $selectors);
    }

    private function typeSpecificityShim(AuthorStylesheetProjectionContext $context): string
    {
        return '' === $context->authorStyles->specificityShim() ? '' : ':not(' . $context->authorStyles->specificityShim() . ')';
    }

    /** @param array<string, mixed> $parsed */
    private function selectorSpecificityShims(array $parsed, AuthorStylesheetProjectionContext $context): string
    {
        $shims = '';
        foreach ( $parsed['compounds'] as $compound ) {
            $zeroSpecificity = $compound['zero_specificity'] ?? array();
            if ( null !== $compound['type'] && 0 === (int) ($zeroSpecificity['types'] ?? 0) ) {
                $shims .= $this->typeSpecificityShim($context);
            }
            $classCount = count($compound['classes']) - (int) ($zeroSpecificity['classes'] ?? 0);
            for ( $index = 0; $index < $classCount; ++$index ) {
                $shims .= ':not(.' . $context->authorStyles->classSpecificityShim() . ')';
            }
            $attributeCount = count($compound['attributes']) - (int) ($zeroSpecificity['attributes'] ?? 0);
            for ( $index = 0; $index < $attributeCount; ++$index ) {
                $shims .= ':not(.' . $context->authorStyles->classSpecificityShim() . ')';
            }
            $idCount = count($compound['ids']) - (int) ($zeroSpecificity['ids'] ?? 0);
            for ( $index = 0; $index < $idCount; ++$index ) {
                $shims .= ':not(#' . $context->authorStyles->idSpecificityShim() . ')';
            }
            if ( null !== $compound['nth_child'] || $compound['first_child'] || $compound['last_child'] ) {
                $shims .= ':not(.' . $context->authorStyles->classSpecificityShim() . ')';
            }
            $listSpecificity = CssSelectorMatcher::selectorListArgumentSpecificity($compound);
            $shims .= str_repeat($this->typeSpecificityShim($context), $listSpecificity['types'])
                . str_repeat(':not(.' . $context->authorStyles->classSpecificityShim() . ')', $listSpecificity['classes'])
                . str_repeat(':not(#' . $context->authorStyles->idSpecificityShim() . ')', $listSpecificity['ids']);
        }
        return $shims;
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
    private function projectNavigationItemAnchorSelector(string $selector, array $parsed, AuthorStylesheetProjectionContext $context): ?string
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
        // The type leads its compound; the rendered anchor's own class takes
        // that place.
        $subject = ':where(.wp-block-navigation-item__content)';
        $rest = $split['rest'];
        if ( $typeLength > 0 ) {
            $subject .= $this->typeSpecificityShim($context) . substr($rest, $typeLength);
        } elseif ( str_starts_with($rest, '*') ) {
            $subject .= substr($rest, 1);
        } else {
            $subject .= $rest;
        }
        $subject .= $trailingState;
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
            return preg_match('/^[1-9][0-9]*$/', $argument) ? 'structural' : null;
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

    /** @param list<DOMElement> $matches */
    private function matchesOnlyNavigationItemAnchors(array $matches, AuthorStylesheetProjectionContext $context): bool
    {
        foreach ( $matches as $element ) {
            if ( ! $context->selectorProjections->isNavigationItemAnchorPath($element->getNodePath() ?? '') ) {
                return false;
            }
        }

        return array() !== $matches;
    }

    /** @param list<DOMElement> $matches */
    private function matchesOnlyNavigationListHosts(array $matches, AuthorStylesheetProjectionContext $context): bool
    {
        foreach ( $matches as $element ) {
            if ( ! $context->selectorProjections->isNavigationListHostPath($element->getNodePath() ?? '') ) {
                return false;
            }
        }

        return array() !== $matches;
    }

    /**
     * A source list that is the element core/navigation stands in for renders
     * as `<nav class="wp-block-navigation [classes]" id="[id]">` with the same
     * classes and id copied onto the inner `<ul class="wp-block-navigation__container">`.
     * A rule keyed by class or id reaches both (and the engine resets the copy's
     * placement); a rule qualified by the list type (`#header ul#nav{float:right;
     * width:360px;position:relative;top:20px}`) reached only the inner copy — a
     * flex item whose float is ignored and whose offsets are reset — so the
     * menu lost its place. Replace the type with the block, excluding the copy,
     * and keep the type's specificity through the shim. Classes, ids and
     * pseudo-classes in the compound stay where they are, so the usual class
     * projection still applies to them.
     *
     * @param array<string, mixed> $parsed
     */
    private function projectNavigationListHostSelector(string $selector, array $parsed, AuthorStylesheetProjectionContext $context): ?string
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
            return $this->rewriteSourceTagTypes($selector, $parsed, $context, '', array(
                (int) $typeSpan['start'] => array(
                    'end' => (int) $typeSpan['end'],
                    'value' => ':where(.wp-block-navigation:not(.wp-block-navigation__container))' . $this->typeSpecificityShim($context),
                ),
            ));
        }
        // Without a type the authored compound already reaches the block.
        return null;
    }

    /** @param list<DOMElement> $matches */
    private function matchesOnlyNavigationListItems(array $matches, AuthorStylesheetProjectionContext $context): bool
    {
        foreach ( $matches as $element ) {
            if ( ! $context->selectorProjections->isNavigationListItemPath($element->getNodePath() ?? '') ) {
                return false;
            }
        }

        return array() !== $matches;
    }

    /**
     * core/navigation-link and core/navigation-submenu render a source `<li>` as
     * `<li class="wp-block-navigation-item">`, carrying the source item's
     * classes and id but not the source-type marker other list items receive.
     * A rule authored on the item (`#menu li{display:inline;padding-right:15px}`)
     * therefore matched nothing in WordPress, and the menu lost the spacing the
     * item's own box provided. Move the subject onto a zero-specificity item
     * wrapper; classes, ids and structural pseudo-classes come along, the `li`
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
        $subject = ':where(.wp-block-navigation-item)' . $split['item'] . $split['structural']
            . ( $typeLength > 0 ? $this->typeSpecificityShim($context) : '' )
            . $trailingState;
        return $this->rewriteSourceTagTypes($selector, $parsed, $context, '', array(
            $start => array( 'end' => $end, 'value' => $subject ),
        ));
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
     * never starts a wrapped line, as in the source.
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
            if ( ! $this->matchesOnlyNavigationListItems($matches, $context) ) {
                continue;
            }
            $item = $this->projectNavigationListItemSelector($selector, $parsed, $context);
            // Only where the authored rule itself landed on the rendered item.
            if ( null === $item || trim($this->rewriteSelectorPreludeOnce($selector, $context)) !== $item ) {
                continue;
            }
            if ( $this->sourceItemsAreSpaceSeparated($matches, $context) && ! $this->authorsItemAfterContent($matches, $context) ) {
                $carriers[] = $item . ':not(:last-child)::after';
            }
        }
        if ( array() === $carriers ) {
            return '';
        }

        return implode(',', $carriers) . '{content:"\\a0"' . ( CssValueInspector::isImportant($display) ? '!important' : '' ) . '}';
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
     * rendered item, and the space must not replace its content.
     *
     * @param list<DOMElement> $items
     */
    private function authorsItemAfterContent(array $items, AuthorStylesheetProjectionContext $context): bool
    {
        foreach ( $context->authorStyles->styleRules() as $rule ) {
            foreach ( is_array($rule['selectors'] ?? null) ? $rule['selectors'] : array() as $record ) {
                $selector = trim((string) ( $record['selector'] ?? '' ));
                if ( 1 !== preg_match('/^(.+?)::?after$/i', $selector, $subject) ) {
                    continue;
                }
                foreach ( $items as $item ) {
                    if ( $this->styleResolver->matchesCssSelector($item, $subject[1]) ) {
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
                $this->styleResolver->referenceViewportAuthorDeclarations($element, self::NAVIGATION_ITEM_SPACE_PROPERTIES)
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
        $rightmost = $parsed['compounds'][array_key_last($parsed['compounds'])] ?? array();
        return null === ($rightmost['type'] ?? null)
            && array() === ($rightmost['classes'] ?? array())
            && array() === ($rightmost['ids'] ?? array())
            && array() === ($rightmost['attributes'] ?? array())
            && array() === ($rightmost['not'] ?? array())
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

    /** @param array<string, mixed> $parsed @return list<DOMElement> */
    private function matchingSourceElements(string $selector, array $parsed, AuthorStylesheetProjectionContext $context): array
    {
        return $this->semanticPreparer->matchingSourceElements($context->authorStyles, $selector, $parsed);
    }
}
