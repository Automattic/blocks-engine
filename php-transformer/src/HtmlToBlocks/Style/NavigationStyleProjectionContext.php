<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\RuntimeBehaviorState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\TransformationEvidenceState;
use Closure;
use DOMElement;

/**
 * Explicit collaborator surface for {@see NavigationStyleProjector}.
 *
 * Per-transform state (author styles, source styles, generated support styles,
 * materialized assets, runtime behavior, transformation evidence) is resolved
 * through closures because the projector is constructed once with the
 * transformer but must see the state belonging to the transform currently
 * running.
 *
 * `materializeStylesheetAsset` is a transformer-owned operation rather than a
 * navigation concern — the transformer uses it for engine-support and author
 * stylesheets too — so the projector reaches it through this surface instead of
 * owning it.
 */
final class NavigationStyleProjectionContext
{
    /**
     * @param Closure(): AuthorStyleAnalysis                              $authorStyles
     * @param Closure(): SourceStyleResolutionState                       $sourceStyles
     * @param Closure(): GeneratedSupportStylesheetState                  $generatedSupportStyles
     * @param Closure(): RuntimeBehaviorState                             $runtimeBehavior
     * @param Closure(): TransformationEvidenceState                      $transformationEvidence
     * @param Closure(DOMElement, string): string                         $attr
     * @param Closure(DOMElement): string                                 $elementSelector
     * @param Closure(string): array<string, mixed>                       $parsedCssSelector
     * @param Closure(string): string                                     $safeAnchor
     * @param Closure(array<int, string>, string, string, string, string): void $materializeStylesheetAsset
     */
    public function __construct(
        private readonly Closure $authorStyles,
        private readonly Closure $sourceStyles,
        private readonly Closure $generatedSupportStyles,
        private readonly Closure $runtimeBehavior,
        private readonly Closure $transformationEvidence,
        private readonly Closure $attr,
        private readonly Closure $elementSelector,
        private readonly Closure $parsedCssSelector,
        private readonly Closure $safeAnchor,
        private readonly Closure $materializeStylesheetAsset
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

    public function generatedSupportStyles(): GeneratedSupportStylesheetState
    {
        return ($this->generatedSupportStyles)();
    }

    public function runtimeBehavior(): RuntimeBehaviorState
    {
        return ($this->runtimeBehavior)();
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

    /**
     * @return array<string, mixed>
     */
    public function parsedCssSelector(string $selector): array
    {
        return ($this->parsedCssSelector)($selector);
    }

    public function safeAnchor(string $id): string
    {
        return ($this->safeAnchor)($id);
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
