<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Closure;
use DOMElement;

/** Conversion-policy decisions required while projecting author selector identities. */
final class AuthorSelectorSemanticContext
{
    /**
     * @param Closure(DOMElement): bool        $isDirectChildOfAuthorOwnedLayout
     * @param Closure(string): bool            $isInlineContentElement
     * @param Closure(DOMElement): bool        $isStructuralListItem
     * @param Closure(DOMElement): bool        $requiresIndependentSemanticWrapper
     * @param Closure(DOMElement): bool        $requiresInlineLayoutCarrier
     * @param Closure(DOMElement): bool        $isRepresentableTable
     */
    public function __construct(
        private readonly Closure $isDirectChildOfAuthorOwnedLayout,
        private readonly Closure $isInlineContentElement,
        private readonly Closure $isStructuralListItem,
        private readonly Closure $requiresIndependentSemanticWrapper,
        private readonly Closure $requiresInlineLayoutCarrier,
        private readonly Closure $isRepresentableTable
    ) {}

    public function isDirectChildOfAuthorOwnedLayout(DOMElement $element): bool
    {
        return ($this->isDirectChildOfAuthorOwnedLayout)($element);
    }

    public function isInlineContentElement(string $tagName): bool
    {
        return ($this->isInlineContentElement)($tagName);
    }

    public function isStructuralListItem(DOMElement $element): bool
    {
        return ($this->isStructuralListItem)($element);
    }

    public function requiresIndependentSemanticWrapper(DOMElement $element): bool
    {
        return ($this->requiresIndependentSemanticWrapper)($element);
    }

    public function requiresInlineLayoutCarrier(DOMElement $element): bool
    {
        return ($this->requiresInlineLayoutCarrier)($element);
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
}
