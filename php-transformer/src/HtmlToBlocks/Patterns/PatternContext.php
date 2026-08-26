<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

final class PatternContext
{
    /**
     * @param callable(DOMElement): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $innerHtml
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null, DOMElement|null): array<string, mixed> $createBlock
     * @param PatternRecursiveConverter|null $recursiveConverter
     * @param NavigationPatternContext|null $navigationContext
     * @param callable(DOMElement): string|null $mergedPresentationStyle
     * @param callable(DOMElement): array<string, string>|null $htmlAttributes
     * @param callable(string): string|null $resolveAssetImageUrl
     * @param callable(DOMElement, array<int, string>): array<string, mixed>|null $mediaTextPresentationAttributes
     * @param callable(DOMElement): string|null $mediaTextPresentationStyle
     * @param callable(DOMElement): string|null $structuralPresentationStyle
     * @param callable(DOMElement): string|null $safeFallbackHtml
     * @param callable(string): string|null $escapeHtml
     * @param ButtonPatternContext|null $buttonContext
     * @param QuotePatternContext|null $quoteContext
     * @param CodeWindowPatternContext|null $codeWindowContext
     * @param LogoPatternContext|null $logoContext
     * @param GalleryPatternContext|null $galleryContext
     */
    public function __construct(
        private readonly mixed $presentationAttributes,
        private readonly mixed $innerHtml,
        private readonly mixed $createBlock,
        private readonly ?PatternRecursiveConverter $recursiveConverter = null,
        private readonly ?NavigationPatternContext $navigationContext = null,
        private readonly mixed $mergedPresentationStyle = null,
        private readonly mixed $htmlAttributes = null,
        private readonly mixed $resolveAssetImageUrl = null,
        private readonly mixed $mediaTextPresentationAttributes = null,
        private readonly mixed $mediaTextPresentationStyle = null,
        private readonly mixed $structuralPresentationStyle = null,
        private readonly mixed $safeFallbackHtml = null,
        private readonly mixed $escapeHtml = null,
        private readonly ?ButtonPatternContext $buttonContext = null,
        private readonly ?QuotePatternContext $quoteContext = null,
        private readonly ?CodeWindowPatternContext $codeWindowContext = null,
        private readonly ?LogoPatternContext $logoContext = null,
        private readonly ?GalleryPatternContext $galleryContext = null
    ) {
    }

    /**
     * @return callable(DOMElement): array<string, mixed>
     */
    public function presentationAttributesCallback(): callable
    {
        return $this->presentationAttributes;
    }

    /**
     * @return callable(DOMElement): string
     */
    public function innerHtmlCallback(): callable
    {
        return $this->innerHtml;
    }

    /**
     * @return callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null, DOMElement|null): array<string, mixed>
     */
    public function createBlockCallback(): callable
    {
        return $this->createBlock;
    }

    public function recursiveConverter(): ?PatternRecursiveConverter { return $this->recursiveConverter; }
    public function navigationContext(): ?NavigationPatternContext { return $this->navigationContext; }
    public function mergedPresentationStyleCallback(): ?callable { return is_callable($this->mergedPresentationStyle) ? $this->mergedPresentationStyle : null; }
    public function htmlAttributesCallback(): ?callable { return is_callable($this->htmlAttributes) ? $this->htmlAttributes : null; }
    public function resolveAssetImageUrlCallback(): ?callable { return is_callable($this->resolveAssetImageUrl) ? $this->resolveAssetImageUrl : null; }
    public function mediaTextPresentationAttributesCallback(): ?callable { return is_callable($this->mediaTextPresentationAttributes) ? $this->mediaTextPresentationAttributes : null; }
    public function mediaTextPresentationStyleCallback(): ?callable { return is_callable($this->mediaTextPresentationStyle) ? $this->mediaTextPresentationStyle : null; }
    public function structuralPresentationStyleCallback(): ?callable { return is_callable($this->structuralPresentationStyle) ? $this->structuralPresentationStyle : null; }
    public function safeFallbackHtmlCallback(): ?callable { return is_callable($this->safeFallbackHtml) ? $this->safeFallbackHtml : null; }
    public function escapeHtmlCallback(): ?callable { return is_callable($this->escapeHtml) ? $this->escapeHtml : null; }
    public function buttonContext(): ?ButtonPatternContext { return $this->buttonContext; }
    public function quoteContext(): ?QuotePatternContext { return $this->quoteContext; }
    public function codeWindowContext(): ?CodeWindowPatternContext { return $this->codeWindowContext; }
    public function logoContext(): ?LogoPatternContext { return $this->logoContext; }
    public function galleryContext(): ?GalleryPatternContext { return $this->galleryContext; }
}
