<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\TransformationEvidenceState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
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
     * @param Closure(string): string                  $cssComparableValue
     * @param Closure(string): array<string, mixed>    $parsedCssSelector
     * @param Closure(string): string                  $promotedClassName
     * @param Closure(string): string                  $resolvedAssetImageUrl
     */
    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly Closure $cssComparableValue,
        private readonly Closure $parsedCssSelector,
        private readonly Closure $promotedClassName,
        private readonly Closure $resolvedAssetImageUrl
    ) {
    }

    public function authorStyles(): AuthorStyleAnalysis
    {
        return $this->session->authorStyleAnalysis();
    }

    public function authorSelectorProjectionState(): AuthorSelectorProjectionState
    {
        return $this->session->authorSelectorProjectionState();
    }

    public function sourceStyles(): SourceStyleResolutionState
    {
        return $this->session->sourceStyleResolutionState();
    }

    public function generatedSupportStyles(): GeneratedSupportStylesheetState
    {
        return $this->session->generatedSupportStylesheetState();
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
        return $this->session->authorSelectorProjectionState()->isRuntimeAttributePath(SourceDom::sourceElementIdentity($element));
    }
}
