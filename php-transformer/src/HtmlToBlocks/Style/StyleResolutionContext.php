<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\TransformationEvidenceState;
use Closure;
use DOMElement;

/**
 * Explicit collaborator surface for {@see StyleResolver}.
 *
 * Per-transform state (author styles, source styles, layout geometry, the
 * presentation cache, transformation evidence) is resolved through closures
 * because the resolver is constructed once with the transformer but must see
 * the state belonging to the transform currently running.
 */
final class StyleResolutionContext
{
    /**
     * @param Closure(): AuthorStyleAnalysis           $authorStyles
     * @param Closure(): SourceStyleResolutionState    $sourceStyles
     * @param Closure(): LayoutGeometryState           $layoutGeometry
     * @param Closure(): PresentationResolutionCache   $presentationResolutionCache
     * @param Closure(): TransformationEvidenceState   $transformationEvidence
     * @param Closure(DOMElement, string): string      $attr
     * @param Closure(DOMElement): string              $elementSelector
     * @param Closure(DOMElement): int                 $cardLikeChildCount
     * @param Closure(DOMElement): int                 $directElementChildCount
     * @param Closure(string): string                  $cssComparableValue
     * @param Closure(string): array<string, mixed>    $parsedCssSelector
     * @param Closure(string): string                  $promotedClassName
     * @param Closure(string): string                  $resolvedAssetImageUrl
     * @param Closure(string): string                  $safeAnchor
     */
    public function __construct(
        private readonly Closure $authorStyles,
        private readonly Closure $sourceStyles,
        private readonly Closure $layoutGeometry,
        private readonly Closure $presentationResolutionCache,
        private readonly Closure $transformationEvidence,
        private readonly Closure $attr,
        private readonly Closure $elementSelector,
        private readonly Closure $cardLikeChildCount,
        private readonly Closure $directElementChildCount,
        private readonly Closure $cssComparableValue,
        private readonly Closure $parsedCssSelector,
        private readonly Closure $promotedClassName,
        private readonly Closure $resolvedAssetImageUrl,
        private readonly Closure $safeAnchor
    ) {
    }

    public function authorStyles(): AuthorStyleAnalysis
    {
        return ($this->authorStyles)();
    }

    public function sourceStyles(): SourceStyleResolutionState
    {
        return ($this->sourceStyles)();
    }

    public function layoutGeometry(): LayoutGeometryState
    {
        return ($this->layoutGeometry)();
    }

    public function presentationResolutionCache(): PresentationResolutionCache
    {
        return ($this->presentationResolutionCache)();
    }

    public function transformationEvidence(): TransformationEvidenceState
    {
        return ($this->transformationEvidence)();
    }

    public function attr(DOMElement $element, string $name): string
    {
        return ($this->attr)($element, $name);
    }

    public function elementSelector(DOMElement $element): string
    {
        return ($this->elementSelector)($element);
    }

    public function cardLikeChildCount(DOMElement $element): int
    {
        return ($this->cardLikeChildCount)($element);
    }

    public function directElementChildCount(DOMElement $element): int
    {
        return ($this->directElementChildCount)($element);
    }

    public function cssComparableValue(string $value): string
    {
        return ($this->cssComparableValue)($value);
    }

    /**
     * @return array<string, mixed>
     */
    public function parsedCssSelector(string $selector): array
    {
        return ($this->parsedCssSelector)($selector);
    }

    public function promotedClassName(string $className): string
    {
        return ($this->promotedClassName)($className);
    }

    public function resolvedAssetImageUrl(string $url): string
    {
        return ($this->resolvedAssetImageUrl)($url);
    }

    public function safeAnchor(string $id): string
    {
        return ($this->safeAnchor)($id);
    }
}
