<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternTreeConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorLayoutOwnership;
use Closure;
use DOMElement;

/** Explicit transformer collaborator surface for ordered and unordered lists. */
final class ListElementContext
{
    /**
     * @param Closure(array<string, mixed>, DOMElement): array<string, mixed> $rememberAccordionDisclosureRoot
     * @param Closure(DOMElement): bool $isStructuredCardList
     * @param Closure(DOMElement, array<int, array<string, mixed>>&): ?array<string, mixed> $decomposeStructuredCardList
     * @param Closure(DOMElement): bool $containsStructuralItemContent
     * @param Closure(DOMElement, array<int, array<string, mixed>>&): ?array<string, mixed> $decomposeStructuralList
     * @param Closure(DOMElement, array<int, array<string, mixed>>&): array<int, array<string, mixed>> $listItems
     */
    public function __construct(
        private readonly PatternTreeConverter $tree,
        private readonly Closure $rememberAccordionDisclosureRoot,
        private readonly Closure $isStructuredCardList,
        private readonly Closure $decomposeStructuredCardList,
        private readonly Closure $containsStructuralItemContent,
        private readonly Closure $decomposeStructuralList,
        private readonly Closure $listItems,
        public readonly AuthorLayoutOwnership $authorLayoutOwnership
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks @param array<int, class-string> $patterns @return array<string, mixed>|null */
    public function recognizePatterns(DOMElement $element, array &$fallbacks, array $patterns): ?array
    {
        return $this->tree->patterns($element, $fallbacks, $patterns);
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

}
