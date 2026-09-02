<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\ElementPresentationResolver;
use Closure;
use DOMElement;

/** Explicit transformer collaborator surface for ordered and unordered lists. */
final class ListElementContext
{
    /**
     * @param Closure(DOMElement, array<int, array<string, mixed>>&, array<int, class-string>): ?array<string, mixed> $recognizePatterns
     * @param Closure(array<string, mixed>, DOMElement): array<string, mixed> $rememberAccordionDisclosureRoot
     * @param Closure(DOMElement): bool $isStructuredCardList
     * @param Closure(DOMElement, array<int, array<string, mixed>>&): ?array<string, mixed> $decomposeStructuredCardList
     * @param Closure(DOMElement): bool $containsStructuralItemContent
     * @param Closure(DOMElement, array<int, array<string, mixed>>&): ?array<string, mixed> $decomposeStructuralList
     * @param Closure(DOMElement, array<int, array<string, mixed>>&): array<int, array<string, mixed>> $listItems
     * @param Closure(DOMElement): bool $isCssOwnedGridElement
     * @param Closure(DOMElement): array<string, mixed> $cssOwnedGridAttributes
     * @param Closure(string, array<string, mixed>, array<int, array<string, mixed>>, ?DOMElement): array<string, mixed> $createBlock
     */
    public function __construct(
        private readonly Closure $recognizePatterns,
        private readonly Closure $rememberAccordionDisclosureRoot,
        private readonly Closure $isStructuredCardList,
        private readonly Closure $decomposeStructuredCardList,
        private readonly Closure $containsStructuralItemContent,
        private readonly Closure $decomposeStructuralList,
        private readonly Closure $listItems,
        private readonly Closure $isCssOwnedGridElement,
        private readonly Closure $cssOwnedGridAttributes,
        private readonly ElementPresentationResolver $presentationResolver,
        private readonly Closure $createBlock
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks @param array<int, class-string> $patterns @return array<string, mixed>|null */
    public function recognizePatterns(DOMElement $element, array &$fallbacks, array $patterns): ?array
    {
        return ($this->recognizePatterns)($element, $fallbacks, $patterns);
    }

    /** @param array<string, mixed> $block @return array<string, mixed> */
    public function rememberAccordionDisclosureRoot(array $block, DOMElement $element): array
    {
        return ($this->rememberAccordionDisclosureRoot)($block, $element);
    }

    public function isStructuredCardList(DOMElement $element): bool
    {
        return ($this->isStructuredCardList)($element);
    }

    /** @param array<int, array<string, mixed>> $fallbacks @return array<string, mixed>|null */
    public function decomposeStructuredCardList(DOMElement $element, array &$fallbacks): ?array
    {
        return ($this->decomposeStructuredCardList)($element, $fallbacks);
    }

    public function containsStructuralItemContent(DOMElement $element): bool
    {
        return ($this->containsStructuralItemContent)($element);
    }

    /** @param array<int, array<string, mixed>> $fallbacks @return array<string, mixed>|null */
    public function decomposeStructuralList(DOMElement $element, array &$fallbacks): ?array
    {
        return ($this->decomposeStructuralList)($element, $fallbacks);
    }

    /** @param array<int, array<string, mixed>> $fallbacks @return array<int, array<string, mixed>> */
    public function listItems(DOMElement $element, array &$fallbacks): array
    {
        return ($this->listItems)($element, $fallbacks);
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

    /** @param array<string, mixed> $attributes @param array<int, array<string, mixed>> $innerBlocks @return array<string, mixed> */
    public function createBlock(string $name, array $attributes, array $innerBlocks, ?DOMElement $sourceElement): array
    {
        return ($this->createBlock)($name, $attributes, $innerBlocks, $sourceElement);
    }
}
