<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\Support\ShellLandmarkPolicy;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/**
 * Recognizes column / split-layout / documentation-sidebar containers and
 * converts them to a core/columns block of core/column children.
 *
 * Extracted verbatim from HtmlTransformer::columnsBlockFromElement() and its
 * private helpers. The matcher keeps its frozen callback contract while the
 * registry supplies recursive conversion through PatternTreeConverter:
 *   - $convertChildren: convertChildren($child, $fallbacks, true) for wrapper
 *     columns (recursion that may emit fallbacks).
 *   - $convertElement:  convertElement($child, $fallbacks, true) for non-wrapper
 *     children (single-element recursion that may return null / emit fallbacks).
 *   - $fallbacks (by reference): the local transactional accumulator. Per-child
 *     fallbacks are committed only if this recognizer wins.
 *
 * This recognizer is invoked at its specific div/section-like call site only and
 * is intentionally NOT registered in PatternRecognizerRegistry: looksLikeColumnsContainer
 * is a broad flex/grid/class heuristic that would misfire ahead of more specific
 * recognizers in the shared dispatch.
 */
final class ColumnsPattern implements PatternRecognizerInterface
{
    public function __construct(
        private readonly \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator $createBlock
    ) {
    }

    use PatternDomHelpersTrait;
    use PatternGateHelpersTrait;

    public function recognize(DOMElement $element, PatternContext $context): ?PatternRecognitionResult
    {
        $converter = $context->recursiveConverter();
        $columns = $context->columnsContext();
        if ( null === $converter || null === $columns ) {
            return null;
        }

        $fallbacks = array();
        $block = $this->match(
            $element,
            $fallbacks,
            array($converter, 'children'),
            array($converter, 'element'),
            $context->presentationAttributes(...),
            $columns->structuralStyle(...),
            $this->createBlock->createBlock(...),
            $columns->referenceViewportStyle(...),
            $columns->isSingleRowAtMobile(...)
        );

        return null === $block ? null : new PatternRecognitionResult($block, $fallbacks);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @param callable(DOMElement, array<int, array<string, mixed>>&, bool): array<int, array<string, mixed>> $convertChildren
     * @param callable(DOMElement, array<int, array<string, mixed>>&, bool): (array<string, mixed>|null) $convertElement
     * @param callable(DOMElement, array<int, string>): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $resolvedStyle Static author rules plus inline style (structural projection).
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null): array<string, mixed> $createBlock
     * @param (callable(DOMElement): string)|null $referenceViewportStyle Resting cascade at the desktop reference
     *        viewport, media-conditional rules included; classification only. Defaults to $resolvedStyle.
     * @param (callable(DOMElement): bool)|null $isSingleRowAtMobile Whether the source container is still
     *        one non-wrapping flex row at the mobile reference viewport; such a row keeps its columns unstacked.
     * @return array<string, mixed>|null
     */
    public function match(
        DOMElement $element,
        array &$fallbacks,
        callable $convertChildren,
        callable $convertElement,
        callable $presentationAttributes,
        callable $resolvedStyle,
        callable $createBlock,
        ?callable $referenceViewportStyle = null,
        ?callable $isSingleRowAtMobile = null
    ): ?array {
        if ( ! $this->looksLikeColumnsContainer($element, $resolvedStyle($element), $referenceViewportStyle ?? $resolvedStyle) ) {
            return null;
        }

        // core/columns is a flex layout; WordPress rejects it with is-layout-grid.
        // The layout resolver stamps layout:{type:grid} from signals this
        // recognizer's style-based grid bail cannot see (grid-ish class names,
        // class-resolved display:grid), so a would-be columns container whose
        // layout resolves to grid must decline here and demote to core/group,
        // where grid layout is native.
        $layout = ( $presentationAttributes($element) )['layout'] ?? null;
        if ( is_array($layout) && 'grid' === (string) ($layout['type'] ?? '') ) {
            return null;
        }

        $elementChildren = array();
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }

            if ( XML_COMMENT_NODE === $child->nodeType ) {
                continue;
            }

            if ( ! $child instanceof DOMElement ) {
                return null;
            }
            $elementChildren[] = $child;
        }

        if ( count($elementChildren) < 2 ) {
            return null;
        }

        // Explicit grid placement depends on the source parent's track model.
        // core/columns replaces that model with flex and cannot preserve it.
        foreach ( $elementChildren as $child ) {
            $childStyle = strtolower(trim($resolvedStyle($child) . ';' . SourceDom::attr($child, 'style')));
            if ( preg_match('/(?:^|;)\s*grid-(?:column|row|area)\s*:/', $childStyle) ) {
                return null;
            }
        }

        $columns = array();
        $columnFallbacks = array();
        foreach ( $elementChildren as $child ) {
            $children = ShellLandmarkPolicy::isColumnsWrapperTag($child->tagName)
                ? $convertChildren($child, $columnFallbacks, true)
                : array_filter(array( $convertElement($child, $columnFallbacks, true) ));
            // Core saves width as an inline flex-basis, preserving authored
            // [style*=flex-basis] selectors as well as the editable column size.
            $basis = '';
            foreach ( CssRuleAnalyzer::declarations($child->getAttribute('style'), array( 'flex-basis' )) as $declaration ) {
                if ( ! CssValueInspector::isImportant($basis) || CssValueInspector::isImportant($declaration['value']) ) {
                    $basis = $declaration['value'];
                }
            }
            $width = CssValueInspector::comparable($basis);
            if ( ! preg_match('/^\d+(?:\.\d+)?(?:%|px|em|rem|ch|vw|vmin|vmax)$/', $width) ) {
                $width = '';
            }
            $attrs = $presentationAttributes($child, '' !== $width ? array( 'flex-basis' ) : array());
            if ( '' !== $width ) {
                $attrs['width'] = $width;
            }
            $columns[] = $createBlock('core/column', $attrs, $children, $child);
        }
        array_push($fallbacks, ...$columnFallbacks);

        // core/columns stacks below its breakpoint by default; a source row
        // that never stacks keeps its columns side by side.
        $attrs = $presentationAttributes($element);
        if ( null !== $isSingleRowAtMobile && $isSingleRowAtMobile($element) ) {
            $attrs['isStackedOnMobile'] = false;
        }

        return $createBlock('core/columns', $attrs, $columns, $element);
    }

    /**
     * @param callable(DOMElement): string $referenceViewportStyle
     */
    private function looksLikeColumnsContainer(DOMElement $element, string $resolvedStyle, callable $referenceViewportStyle): bool
    {
        $inlineStyle = strtolower(SourceDom::attr($element, 'style'));
        $style = strtolower('' !== trim($resolvedStyle) ? $resolvedStyle : $inlineStyle);

        // An out-of-flow flex track is not a row of independent WordPress
        // columns: its positioning and the containing viewport together define
        // the gallery geometry. core/columns participates in normal flow and
        // can wrap its children at the responsive breakpoint, growing the
        // document by one item per row. Keep it as a group so the geometry
        // carrier can preserve position/display and all child items.
        // resolvedStyle() is the structural projection and can intentionally
        // omit inline properties outside its allow-list (including position).
        // Keep the explicit inline participation signal alongside it.
        if ( preg_match('/(?:^|;)\s*position\s*:\s*(?:absolute|fixed)\b/', $style . ';' . $inlineStyle) ) {
            return false;
        }

        if ( SourceDom::hasClass($element, 'wp-block-columns') ) {
            return true;
        }

        $className = strtolower(SourceDom::attr($element, 'class'));

        if ( preg_match('/(?:^|;)\s*display\s*:\s*(?:inline-)?flex\b/', $style) && null !== SourceDom::firstChildElement($element, 'svg') ) {
            return false;
        }

        // A genuinely vertical flex container (display:flex with
        // flex-direction: column / column-reverse) lays its children out in a
        // vertical stack. Emitting core/columns would render them horizontally —
        // the wrong direction. Decline here so the host transformer routes the
        // element to a vertical core/group instead, preserving its classes and
        // styles. Horizontal flex (row / row-reverse / default) remains eligible.
        if ( $this->isVerticalFlexContainer($style) ) {
            return false;
        }

        // Semantic sidebar/content names can suggest a split layout only when
        // author CSS does not explicitly retain normal document flow.
        if ( preg_match('/(?:^|;)\s*display\s*:\s*(?:block|inline|flow-root|contents)\b/', $style) ) {
            return false;
        }

        // core/columns is a flex layout. Preserve resolved grid containers as
        // groups so their source classes continue to control track geometry.
        if ( preg_match('/(?:^|;)\s*display\s*:\s*(?:inline-)?grid\b/', $style) ) {
            return false;
        }

        // Only a complete column class names a layout. A fragment in a theme
        // option such as theme-single-column-width-full describes no geometry.
        // Split-layout names describe two-pane structures. Multi-child content
        // stacks such as hero copy must stay groups so source CSS controls flow.
        return (bool) preg_match('/(?:^|\s)columns?(?:$|\s)/', $className)
            || ( $this->looksLikeSplitLayout($element) && 2 === SourceDom::childElementCount($element) )
            || ( $this->looksLikeDocumentationLayout($element) && $this->hasSidebarAndContentChildren($element, $referenceViewportStyle) )
            || $this->hasSidebarAndContentChildren($element, $referenceViewportStyle)
            || preg_match('/(?:^|;)\s*display\s*:\s*(?:inline-)?flex/', $inlineStyle);
    }

    private function looksLikeSplitLayout(DOMElement $element): bool
    {
        $name = strtolower(trim(SourceDom::attr($element, 'class') . ' ' . SourceDom::attr($element, 'id')));

        return (bool) preg_match('/(?:^|[\s_-])(?:split|two[\s_-]?col|media[\s_-]?text|text[\s_-]?media|feature[\s_-]?row|hero[\s_-]?(?:inner|grid|content|layout)|content[\s_-]?grid)(?:$|[\s_-])/', $name);
    }
    private function looksLikeDocumentationLayout(DOMElement $element): bool
    {
        $name = strtolower(trim(SourceDom::attr($element, 'class') . ' ' . SourceDom::attr($element, 'id')));
        return (bool) preg_match('/(?:^|[\s_-])(?:docs?|documentation|article|content)(?:[\s_-]+(?:layout|shell|page|with[\s_-]+sidebar)|$)|(?:^|[\s_-])sidebar[\s_-]+layout(?:$|[\s_-])/', $name);
    }

    /**
     * A sidebar-named child and a content-named child suggest a two-pane
     * layout only when the container actually is a two-pane shell at the
     * desktop reference viewport:
     *
     *  - both named children are laid out beside each other in flow there. A
     *    phone-only sticky bar (`<aside>` that is `display:none` from the
     *    tablet breakpoint up and `position:fixed` to the viewport edge) is
     *    never a pane, so it cannot vouch for columns; counting it turns the
     *    page wrapper into a row of equal columns.
     *  - a sidebar-named pane renders content in that flow. A sidebar whose
     *    whole subtree is empty or hangs off an out-of-flow wrapper (an
     *    in-flow carrier for an absolutely positioned widget) holds no pane
     *    box and cannot vouch for columns either.
     *  - the named panes are the container's only in-flow element children.
     *    A sidebar/content naming pair among many stacked children — a
     *    vertical page stack whose footer class happens to contain
     *    "-content" — is normal-flow stacking, not a split layout, and
     *    core/columns would lay those sections out side by side.
     *
     * The layout is read from the resting cascade WITH media-conditional
     * rules, so a mobile-first sidebar hidden at the base viewport and
     * revealed by a `min-width` rule still counts.
     *
     * @param callable(DOMElement): string $referenceViewportStyle
     */
    private function hasSidebarAndContentChildren(DOMElement $element, callable $referenceViewportStyle): bool
    {
        $namesSidebar = false;
        $namesContent = false;
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $name = strtolower(trim($child->tagName . ' ' . SourceDom::attr($child, 'class') . ' ' . SourceDom::attr($child, 'id') . ' ' . SourceDom::attr($child, 'role')));
            $namesSidebar = $namesSidebar || (bool) preg_match('/(?:^|[\s_-])(?:aside|sidebar|toc|table[\s_-]+of[\s_-]+contents)(?:$|[\s_-])/', $name);
            $namesContent = $namesContent || in_array(strtolower($child->tagName), array( 'article', 'form', 'main' ), true)
                || (bool) preg_match('/(?:^|[\s_-])(?:main|content|article|form|docs?[\s_-]+content|documentation[\s_-]+content)(?:$|[\s_-])/', $name);
        }

        if ( ! $namesSidebar || ! $namesContent ) {
            return false;
        }

        // Names alone would promote; resolve the desktop layout only now.
        // $flowPanes counts the in-flow children whose names take part in the
        // decision, $flowChildren every in-flow element child: the names only
        // promise a split layout when the two counts meet.
        $hasSidebar = false;
        $hasContent = false;
        $flowChildren = 0;
        $flowPanes = 0;
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement || ! $this->isLaidOutInFlow($child, $referenceViewportStyle($child)) ) {
                continue;
            }

            ++$flowChildren;

            $name = strtolower(trim($child->tagName . ' ' . SourceDom::attr($child, 'class') . ' ' . SourceDom::attr($child, 'id') . ' ' . SourceDom::attr($child, 'role')));
            $sidebar = (bool) preg_match('/(?:^|[\s_-])(?:aside|sidebar|toc|table[\s_-]+of[\s_-]+contents)(?:$|[\s_-])/', $name);
            $content = in_array(strtolower($child->tagName), array( 'article', 'form', 'main' ), true)
                || (bool) preg_match('/(?:^|[\s_-])(?:main|content|article|form|docs?[\s_-]+content|documentation[\s_-]+content)(?:$|[\s_-])/', $name);
            if ( ! $sidebar && ! $content ) {
                continue;
            }

            // A sidebar pane holds a rendered box in the flow beside its
            // partner. A sidebar-named carrier whose subtree is empty or
            // entirely out of flow is a positioning context, not a pane, so
            // it cannot vouch for the sidebar half of the split.
            if ( ! $content && ! $this->hasInFlowRenderedContent($child, $referenceViewportStyle) ) {
                continue;
            }

            ++$flowPanes;
            $hasSidebar = $hasSidebar || $sidebar;
            $hasContent = $hasContent || $content;
        }

        if ( ! $hasSidebar || ! $hasContent ) {
            return false;
        }

        // A vertical page stack keeps every child in flow regardless of
        // naming; promoting it to core/columns would render its full-width
        // sections side by side.
        return $flowPanes === $flowChildren;
    }

    /**
     * Whether the element renders anything in the normal flow: direct text,
     * or a descendant element that is itself rendered and in flow at the
     * reference viewport. Subtrees below a `display:none` or out-of-flow
     * (`position:absolute|fixed`) descendant do not count: an aside whose
     * only descendants hang off an absolutely positioned wrapper carries no
     * in-flow pane content.
     *
     * @param callable(DOMElement): string $referenceViewportStyle
     */
    private function hasInFlowRenderedContent(DOMElement $element, callable $referenceViewportStyle): bool
    {
        foreach ( $element->childNodes as $node ) {
            if ( XML_TEXT_NODE === $node->nodeType && '' !== trim($node->textContent ?? '') ) {
                return true;
            }

            if ( ! $node instanceof DOMElement ) {
                continue;
            }

            if ( $this->isLaidOutInFlow($node, $referenceViewportStyle($node)) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the element takes part in its parent's normal flow at the
     * viewport the given style was resolved for: rendered (not `display:none`,
     * and not the `hidden` attribute left to the user-agent default) and not
     * removed from flow by `position:absolute|fixed`. Sticky and relative
     * boxes keep their flow slot and remain partners.
     */
    private function isLaidOutInFlow(DOMElement $element, string $resolvedStyle): bool
    {
        $style = strtolower($resolvedStyle);
        if ( preg_match('/(?:^|;)\s*display\s*:\s*none\b/', $style) ) {
            return false;
        }

        if ( $element->hasAttribute('hidden') && ! preg_match('/(?:^|;)\s*display\s*:/', $style) ) {
            return false;
        }

        return ! preg_match('/(?:^|;)\s*position\s*:\s*(?:absolute|fixed)\b/', $style);
    }

}
