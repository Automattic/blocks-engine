<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternTreeConverter;
use Closure;
use DOMElement;

/** Explicit transformer collaborator surface for {@see FigureElementConverter}. */
final class FigureElementContext
{
    /**
     * @param Closure(DOMElement, array<int, array<string, mixed>>&): ?array<string, mixed> $mediaGalleryBlock
     * @param Closure(DOMElement): ?DOMElement $linkedMediaAnchor
     * @param Closure(DOMElement, ?DOMElement, ?DOMElement): ?array<string, mixed> $convertPicture
     * @param Closure(DOMElement, ?DOMElement, ?DOMElement, ?DOMElement): ?array<string, mixed> $convertImage
     * @param Closure(DOMElement, string): ?DOMElement $mediaElement
     * @param Closure(DOMElement, array<int, array<string, mixed>>&): ?array<string, mixed> $convertGeneric
     * @param Closure(string): bool $hasVisibleText
     */
    public function __construct(
        private readonly Closure $mediaGalleryBlock,
        private readonly PatternTreeConverter $tree,
        private readonly Closure $linkedMediaAnchor,
        private readonly Closure $convertPicture,
        private readonly Closure $convertImage,
        private readonly Closure $mediaElement,
        private readonly Closure $convertGeneric,
        private readonly Closure $hasVisibleText
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
        return $this->tree->patterns($element, $fallbacks, $patterns);
    }

    public function linkedMediaAnchor(DOMElement $figure): ?DOMElement
    {
        return ($this->linkedMediaAnchor)($figure);
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

    public function hasVisibleText(string $html): bool
    {
        return ($this->hasVisibleText)($html);
    }

}
