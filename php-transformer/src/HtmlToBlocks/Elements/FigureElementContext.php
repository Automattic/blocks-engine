<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Closure;
use DOMElement;

/** Explicit transformer collaborator surface for {@see FigureElementConverter}. */
final class FigureElementContext
{
    /**
     * @param Closure(DOMElement, array<int, array<string, mixed>>&): ?array<string, mixed> $mediaGalleryBlock
     * @param Closure(DOMElement, array<int, array<string, mixed>>&, array<int, class-string>): ?array<string, mixed> $recognizePatterns
     * @param Closure(DOMElement): ?DOMElement $linkedMediaAnchor
     * @param Closure(DOMElement, string): ?DOMElement $firstChildElement
     * @param Closure(DOMElement, ?DOMElement, ?DOMElement): ?array<string, mixed> $convertPicture
     * @param Closure(DOMElement, ?DOMElement, ?DOMElement, ?DOMElement): ?array<string, mixed> $convertImage
     * @param Closure(DOMElement, string): ?DOMElement $mediaElement
     * @param Closure(DOMElement, array<int, array<string, mixed>>&): ?array<string, mixed> $convertGeneric
     * @param Closure(DOMElement): string $innerHtml
     * @param Closure(string): bool $hasVisibleText
     * @param Closure(DOMElement): array<string, mixed> $presentationAttributes
     * @param Closure(string, array<string, mixed>, array<int, array<string, mixed>>, ?DOMElement): array<string, mixed> $createBlock
     */
    public function __construct(
        private readonly Closure $mediaGalleryBlock,
        private readonly Closure $recognizePatterns,
        private readonly Closure $linkedMediaAnchor,
        private readonly Closure $firstChildElement,
        private readonly Closure $convertPicture,
        private readonly Closure $convertImage,
        private readonly Closure $mediaElement,
        private readonly Closure $convertGeneric,
        private readonly Closure $innerHtml,
        private readonly Closure $hasVisibleText,
        private readonly Closure $presentationAttributes,
        private readonly Closure $createBlock
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks @return array<string, mixed>|null */
    public function mediaGalleryBlock(DOMElement $element, array &$fallbacks): ?array
    {
        return ($this->mediaGalleryBlock)($element, $fallbacks);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @param array<int, class-string> $patterns
     * @return array<string, mixed>|null
     */
    public function recognizePatterns(DOMElement $element, array &$fallbacks, array $patterns): ?array
    {
        return ($this->recognizePatterns)($element, $fallbacks, $patterns);
    }

    public function linkedMediaAnchor(DOMElement $figure): ?DOMElement
    {
        return ($this->linkedMediaAnchor)($figure);
    }

    public function firstChildElement(DOMElement $element, string $tagName): ?DOMElement
    {
        return ($this->firstChildElement)($element, $tagName);
    }

    /** @return array<string, mixed>|null */
    public function convertPicture(DOMElement $picture, ?DOMElement $figure = null, ?DOMElement $link = null): ?array
    {
        return ($this->convertPicture)($picture, $figure, $link);
    }

    /** @return array<string, mixed>|null */
    public function convertImage(DOMElement $image, ?DOMElement $figure = null, ?DOMElement $picture = null, ?DOMElement $link = null): ?array
    {
        return ($this->convertImage)($image, $figure, $picture, $link);
    }

    public function mediaElement(DOMElement $figure, string $tagName): ?DOMElement
    {
        return ($this->mediaElement)($figure, $tagName);
    }

    /** @param array<int, array<string, mixed>> $fallbacks @return array<string, mixed>|null */
    public function convertGeneric(DOMElement $figure, array &$fallbacks): ?array
    {
        return ($this->convertGeneric)($figure, $fallbacks);
    }

    public function innerHtml(DOMElement $element): string
    {
        return ($this->innerHtml)($element);
    }

    public function hasVisibleText(string $html): bool
    {
        return ($this->hasVisibleText)($html);
    }

    /** @return array<string, mixed> */
    public function presentationAttributes(DOMElement $element): array
    {
        return ($this->presentationAttributes)($element);
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<int, array<string, mixed>> $innerBlocks
     * @return array<string, mixed>
     */
    public function createBlock(string $name, array $attributes = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array
    {
        return ($this->createBlock)($name, $attributes, $innerBlocks, $sourceElement);
    }
}
