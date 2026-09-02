<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\FallbackEmitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\RuntimeDomState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\RuntimeSelectorState;
use Closure;
use DOMElement;

/**
 * Explicit collaborator surface for {@see RuntimeIslandAnalyzer}.
 *
 * Per-transform state is read from the compilation's typed session. Closures
 * remain only for transformer-owned operations.
 */
final class RuntimeIslandContext
{
    /**
     * @param Closure(DOMElement): iterable<DOMElement>                       $descendantElements
     * @param Closure(DOMElement): array<int, array<string, mixed>>           $requiredScriptsForElement
     * @param Closure(string): ?DOMElement                                    $preservedHtmlRootElement
     * @param Closure(DOMElement): bool                                       $hasWorkspaceSurface
     */
    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly Closure $descendantElements,
        private readonly Closure $requiredScriptsForElement,
        private readonly Closure $preservedHtmlRootElement,
        private readonly Closure $hasWorkspaceSurface
    ) {
    }

    public function fallbackEmitter(): FallbackEmitter
    {
        return $this->session->fallbackEmitter();
    }

    public function runtimeDom(): RuntimeDomState
    {
        return $this->session->runtimeDomState();
    }

    public function runtimeSelectors(): RuntimeSelectorState
    {
        return $this->session->runtimeSelectorState();
    }

    /**
     * @return iterable<DOMElement>
     */
    public function descendantElements(DOMElement $element): iterable
    {
        return ($this->descendantElements)($element);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function requiredScriptsForElement(DOMElement $element): array
    {
        return ($this->requiredScriptsForElement)($element);
    }

    public function preservedHtmlRootElement(string $html): ?DOMElement
    {
        return ($this->preservedHtmlRootElement)($html);
    }

    public function hasWorkspaceSurface(DOMElement $element): bool
    {
        return ($this->hasWorkspaceSurface)($element);
    }

    public function isInlineContentElement(string $tagName): bool
    {
        return $this->sourceElementClassifier->isInlineContentElement($tagName);
    }

    public function isPresentationalAnimationSelector(string $selector): bool
    {
        return $this->sourceElementClassifier->isPresentationalAnimationSelector($selector);
    }
}
