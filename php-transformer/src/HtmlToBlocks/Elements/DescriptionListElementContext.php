<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextMaterialization;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\ElementPresentationResolver;
use Closure;
use DOMElement;

/** Explicit transformer collaborator surface for description-list elements. */
final class DescriptionListElementContext
{
    /**
     * @param Closure(DOMElement): ?array<string, mixed> $descriptionListBlock
     * @param Closure(DOMElement): ?array<string, mixed> $metadataGridBlock
     * @param Closure(DOMElement): array<int, array<string, mixed>> $definitionListItems
     * @param Closure(DOMElement): bool $isCssOwnedGridElement
     * @param Closure(DOMElement): array<string, mixed> $cssOwnedGridAttributes
     * @param Closure(DOMElement, array<int, array<string, mixed>>&, bool): array<int, array<string, mixed>> $convertChildren
     * @param Closure(string): bool $hasVisibleText
     */
    public function __construct(
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly Closure $descriptionListBlock,
        private readonly Closure $metadataGridBlock,
        private readonly Closure $definitionListItems,
        private readonly Closure $isCssOwnedGridElement,
        private readonly Closure $cssOwnedGridAttributes,
        private readonly ElementPresentationResolver $presentationResolver,
        private readonly Closure $convertChildren,
        private readonly SourceBlockCreator $createBlock,
        private readonly RichTextMaterialization $richTextMaterializer,
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

    public function isCssOwnedGridElement(DOMElement $element): bool
    {
        return ($this->isCssOwnedGridElement)($element);
    }

    /** @return array<string, mixed> */
    public function cssOwnedGridAttributes(DOMElement $element): array
    {
        return ($this->cssOwnedGridAttributes)($element);
    }

    /** @return array<string, mixed> */
    public function presentationAttributes(DOMElement $element): array
    {
        return $this->presentationResolver->presentationAttributes($element);
    }

    /** @param array<int, array<string, mixed>> $fallbacks @return array<int, array<string, mixed>> */
    public function convertChildren(DOMElement $element, array &$fallbacks, bool $captureUnsupported): array
    {
        return ($this->convertChildren)($element, $fallbacks, $captureUnsupported);
    }

    /** @param array<string, mixed> $attributes @param array<int, array<string, mixed>> $innerBlocks @return array<string, mixed> */
    public function createBlock(string $name, array $attributes, array $innerBlocks, ?DOMElement $sourceElement): array
    {
        return $this->createBlock->createBlock($name, $attributes, $innerBlocks, $sourceElement);
    }

    public function richTextContent(DOMElement $element): string
    {
        return $this->richTextMaterializer->content($element);
    }

    public function hasVisibleText(string $html): bool
    {
        return ($this->hasVisibleText)($html);
    }

    public function hasBlockContentChildren(DOMElement $element): bool
    {
        return $this->sourceElementClassifier->hasBlockContentChildren($element);
    }
}
