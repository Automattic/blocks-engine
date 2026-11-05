<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Closure;
use DOMElement;

/** Conversion-policy decisions required while projecting author selector identities. */
final class AuthorSelectorSemanticContext
{
    /**
     * @param Closure(DOMElement): bool        $isStructuralListItem
     * @param Closure(DOMElement): bool        $requiresIndependentSemanticWrapper
     * @param Closure(DOMElement): bool        $isRepresentableTable
     * @param Closure(DOMElement): bool        $convertsToOtherElementType
     */
    public function __construct(
        private readonly SourceElementClassifier $sourceElementClassifier,
        public readonly AuthorLayoutOwnership $authorLayoutOwnership,
        private readonly Closure $isStructuralListItem,
        private readonly Closure $requiresIndependentSemanticWrapper,
        private readonly Closure $isRepresentableTable,
        private readonly Closure $convertsToOtherElementType
    ) {}

    public function isInlineContentElement(string $tagName): bool
    {
        return $this->sourceElementClassifier->isInlineContentElement($tagName);
    }

    public function isStructuralListItem(DOMElement $element): bool
    {
        return ($this->isStructuralListItem)($element);
    }

    public function requiresIndependentSemanticWrapper(DOMElement $element): bool
    {
        return ($this->requiresIndependentSemanticWrapper)($element);
    }

    /** @param array<string, mixed> $parsed */
    public function tableSelectorNeedsStructuralProjection(array $parsed, DOMElement $element): bool
    {
        return TableSelectorProjectionPolicy::needsStructuralProjection($parsed, $element);
    }

    public function isRepresentableTable(DOMElement $table): bool
    {
        return ($this->isRepresentableTable)($table);
    }

    /**
     * Whether conversion emits this source element as a block of another
     * element type (a paragraph that only wraps an image becomes a figure),
     * which changes the type-sibling positions structural selectors see.
     */
    public function convertsToOtherElementType(DOMElement $element): bool
    {
        return ($this->convertsToOtherElementType)($element);
    }
}
