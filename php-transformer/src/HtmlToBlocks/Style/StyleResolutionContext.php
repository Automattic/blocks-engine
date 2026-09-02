<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\TransformationEvidenceState;
use Closure;
use DOMElement;

/**
 * Explicit collaborator surface for {@see StyleResolver}.
 *
 * Per-transform state is read from the compilation's typed session. Closures
 * remain only for transformer-owned operations.
 */
final class StyleResolutionContext
{
    /**
     * @param Closure(DOMElement): int                 $cardLikeChildCount
     * @param Closure(string): string                  $cssComparableValue
     * @param Closure(string): array<string, mixed>    $parsedCssSelector
     * @param Closure(string): string                  $promotedClassName
     * @param Closure(string): string                  $resolvedAssetImageUrl
     * @param Closure(DOMElement): bool                $hasRetainedPresentationRuntime
     */
    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly Closure $cardLikeChildCount,
        private readonly Closure $cssComparableValue,
        private readonly Closure $parsedCssSelector,
        private readonly Closure $promotedClassName,
        private readonly Closure $resolvedAssetImageUrl,
        private readonly Closure $hasRetainedPresentationRuntime
    ) {
    }

    public function authorStyles(): AuthorStyleAnalysis
    {
        return $this->session->authorStyleAnalysis();
    }

    public function sourceStyles(): SourceStyleResolutionState
    {
        return $this->session->sourceStyleResolutionState();
    }

    public function layoutGeometry(): LayoutGeometryState
    {
        return $this->session->layoutGeometryState();
    }

    public function presentationResolutionCache(): PresentationResolutionCache
    {
        return $this->session->presentationResolutionCache();
    }

    public function transformationEvidence(): TransformationEvidenceState
    {
        return $this->session->transformationEvidenceState();
    }

    public function cardLikeChildCount(DOMElement $element): int
    {
        return ($this->cardLikeChildCount)($element);
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

    public function hasRetainedPresentationRuntime(DOMElement $element): bool
    {
        return ($this->hasRetainedPresentationRuntime)($element);
    }
}
