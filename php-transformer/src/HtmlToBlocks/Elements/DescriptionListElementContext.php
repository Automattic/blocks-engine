<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternTreeConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorLayoutOwnership;
use Closure;
use DOMElement;

/** Explicit transformer collaborator surface for description-list elements. */
final class DescriptionListElementContext
{
    /**
     * @param Closure(DOMElement): ?array<string, mixed> $descriptionListBlock
     * @param Closure(DOMElement): ?array<string, mixed> $metadataGridBlock
     * @param Closure(DOMElement): array<int, array<string, mixed>> $definitionListItems
     * @param Closure(string): bool $hasVisibleText
     */
    public function __construct(
        private readonly Closure $descriptionListBlock,
        private readonly Closure $metadataGridBlock,
        private readonly Closure $definitionListItems,
        public readonly AuthorLayoutOwnership $authorLayoutOwnership,
        private readonly PatternTreeConverter $tree,
        private readonly Closure $hasVisibleText
    ) {
    }

    /** @return array<string, mixed>|null */
    public function descriptionListBlock(DOMElement $element): ?array
    {
        return ($this->descriptionListBlock)($element);
    }

    /** @return array<string, mixed>|null */
    public function metadataGridBlock(DOMElement $element): ?array
    {
        return ($this->metadataGridBlock)($element);
    }

    /** @return array<int, array<string, mixed>> */
    public function definitionListItems(DOMElement $element): array
    {
        return ($this->definitionListItems)($element);
    }

    /** @param array<int, array<string, mixed>> $fallbacks @return array<int, array<string, mixed>> */
    public function convertChildren(DOMElement $element, array &$fallbacks, bool $captureUnsupported): array
    {
        return $this->tree->children($element, $fallbacks, $captureUnsupported);
    }

    public function hasVisibleText(string $html): bool
    {
        return ($this->hasVisibleText)($html);
    }

}
