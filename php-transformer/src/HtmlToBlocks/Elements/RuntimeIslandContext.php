<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

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
        private readonly Closure $descendantElements,
        private readonly Closure $requiredScriptsForElement,
        private readonly Closure $preservedHtmlRootElement,
        private readonly Closure $hasWorkspaceSurface
    ) {
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

}
