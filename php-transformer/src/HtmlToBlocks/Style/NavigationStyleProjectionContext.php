<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\RuntimeBehaviorState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\TransformationEvidenceState;
use Closure;
use DOMElement;

/**
 * Explicit collaborator surface for {@see NavigationStyleProjector}.
 *
 * Per-transform state is read from the compilation's typed session. Closures
 * remain only for transformer-owned operations.
 *
 * `materializeStylesheetAsset` is a transformer-owned operation rather than a
 * navigation concern — the transformer uses it for engine-support and author
 * stylesheets too — so the projector reaches it through this surface instead of
 * owning it.
 */
final class NavigationStyleProjectionContext
{
    /**
     * @param Closure(string): array<string, mixed>                       $parsedCssSelector
     * @param Closure(array<int, string>, string, string, string, string): void $materializeStylesheetAsset
     */
    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly Closure $parsedCssSelector,
        private readonly Closure $materializeStylesheetAsset
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

    public function selectorProjections(): AuthorSelectorProjectionState
    {
        return $this->session->authorSelectorProjectionState();
    }

    public function generatedSupportStyles(): GeneratedSupportStylesheetState
    {
        return $this->session->generatedSupportStylesheetState();
    }

    public function runtimeBehavior(): RuntimeBehaviorState
    {
        return $this->session->runtimeBehaviorState();
    }

    public function transformationEvidence(): TransformationEvidenceState
    {
        return $this->session->transformationEvidenceState();
    }

    /**
     * @return array<string, mixed>
     */
    public function parsedCssSelector(string $selector): array
    {
        return ($this->parsedCssSelector)($selector);
    }

    /**
     * @param array<int, string> $cssParts
     */
    public function materializeStylesheetAsset(
        array $cssParts,
        string $source,
        string $placement,
        string $pathPrefix,
        string $target = 'both'
    ): void {
        ($this->materializeStylesheetAsset)($cssParts, $source, $placement, $pathPrefix, $target);
    }
}
