<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Closure;
use DOMElement;

final class PatternContext
{
    /**
     * @param Closure(DOMElement, array<int, string>): array<string, mixed> $presentationAttributes
     * @param Closure(DOMElement): string $innerHtml
     * @param Closure(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null, DOMElement|null): array<string, mixed> $createBlock
     * @param PatternRecursiveConverter|null $recursiveConverter
     * @param NavigationPatternContext|null $navigationContext
     * @param Closure(DOMElement): string|null $mergedPresentationStyle
     * @param Closure(DOMElement): array<string, string>|null $htmlAttributes
     * @param Closure(string): string|null $resolveAssetImageUrl
     * @param Closure(DOMElement, array<int, string>): array<string, mixed>|null $mediaTextPresentationAttributes
     * @param Closure(DOMElement): string|null $mediaTextPresentationStyle
     * @param Closure(DOMElement): string|null $structuralPresentationStyle
     * @param Closure(DOMElement): string|null $safeFallbackHtml
     * @param Closure(string): string|null $escapeHtml
     * @param ButtonPatternContext|null $buttonContext
     * @param QuotePatternContext|null $quoteContext
     * @param CodeWindowPatternContext|null $codeWindowContext
     * @param LogoPatternContext|null $logoContext
     * @param GalleryPatternContext|null $galleryContext
     */
    public function __construct(
        private readonly Closure $presentationAttributes,
        private readonly Closure $innerHtml,
        private readonly Closure $createBlock,
        private readonly ?PatternRecursiveConverter $recursiveConverter = null,
        private readonly ?NavigationPatternContext $navigationContext = null,
        private readonly ?Closure $mergedPresentationStyle = null,
        private readonly ?Closure $htmlAttributes = null,
        private readonly ?Closure $resolveAssetImageUrl = null,
        private readonly ?Closure $mediaTextPresentationAttributes = null,
        private readonly ?Closure $mediaTextPresentationStyle = null,
        private readonly ?Closure $structuralPresentationStyle = null,
        private readonly ?Closure $safeFallbackHtml = null,
        private readonly ?Closure $escapeHtml = null,
        private readonly ?ButtonPatternContext $buttonContext = null,
        private readonly ?QuotePatternContext $quoteContext = null,
        private readonly ?CodeWindowPatternContext $codeWindowContext = null,
        private readonly ?LogoPatternContext $logoContext = null,
        private readonly ?GalleryPatternContext $galleryContext = null
    ) {
    }

    /**
     * @param array<int, string> $excludedGeometryProperties
     * @return array<string, mixed>
     */
    public function presentationAttributes(DOMElement $element, array $excludedGeometryProperties = array()): array
    {
        return ($this->presentationAttributes)($element, $excludedGeometryProperties);
    }

    public function innerHtml(DOMElement $element): string
    {
        return ($this->innerHtml)($element);
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<int, array<string, mixed>> $innerBlocks
     * @return array<string, mixed>
     */
    public function createBlock(string $name, array $attributes = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null, ?DOMElement $logicalSourceElement = null): array
    {
        return ($this->createBlock)($name, $attributes, $innerBlocks, $sourceElement, $logicalSourceElement);
    }

    public function recursiveConverter(): ?PatternRecursiveConverter { return $this->recursiveConverter; }
    public function navigationContext(): ?NavigationPatternContext { return $this->navigationContext; }
    public function mergedPresentationStyleCallback(): ?Closure { return $this->mergedPresentationStyle; }
    public function htmlAttributesCallback(): ?Closure { return $this->htmlAttributes; }
    public function resolveAssetImageUrlCallback(): ?Closure { return $this->resolveAssetImageUrl; }
    public function mediaTextPresentationAttributesCallback(): ?Closure { return $this->mediaTextPresentationAttributes; }
    public function mediaTextPresentationStyleCallback(): ?Closure { return $this->mediaTextPresentationStyle; }
    public function structuralPresentationStyleCallback(): ?Closure { return $this->structuralPresentationStyle; }
    public function safeFallbackHtmlCallback(): ?Closure { return $this->safeFallbackHtml; }
    public function escapeHtmlCallback(): ?Closure { return $this->escapeHtml; }
    public function buttonContext(): ?ButtonPatternContext { return $this->buttonContext; }
    public function quoteContext(): ?QuotePatternContext { return $this->quoteContext; }
    public function codeWindowContext(): ?CodeWindowPatternContext { return $this->codeWindowContext; }
    public function logoContext(): ?LogoPatternContext { return $this->logoContext; }
    public function galleryContext(): ?GalleryPatternContext { return $this->galleryContext; }
}
