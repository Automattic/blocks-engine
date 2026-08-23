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
     * @param callable(DOMElement): bool|null $isRuntimeDomTarget
     * @param PatternRecursiveConverter|null $recursiveConverter
     * @param callable(DOMElement, DOMElement): string|null $navigationUnderlineColor
     * @param callable(DOMElement): string|null $resolvedStyle
     * @param callable(DOMElement): list<string>|null $navigationColorInteractionStates
     * @param callable(DOMElement): string|null $navigationOverlayMenu
     * @param callable(DOMElement): string|null $mergedPresentationStyle
     * @param callable(DOMElement): array<string, string>|null $htmlAttributes
     * @param callable(string): string|null $resolveAssetImageUrl
     * @param callable(DOMElement, array<int, string>): array<string, mixed>|null $mediaTextPresentationAttributes
     * @param callable(DOMElement): string|null $mediaTextPresentationStyle
     * @param callable(DOMElement): string|null $structuralPresentationStyle
     */
    public function __construct(
        private readonly mixed $presentationAttributes,
        private readonly mixed $innerHtml,
        private readonly mixed $createBlock,
        private readonly mixed $isRuntimeDomTarget = null,
        private readonly ?PatternRecursiveConverter $recursiveConverter = null,
        private readonly mixed $navigationUnderlineColor = null,
        private readonly mixed $resolvedStyle = null,
        private readonly mixed $navigationColorInteractionStates = null,
        private readonly mixed $navigationOverlayMenu = null,
        private readonly mixed $mergedPresentationStyle = null,
        private readonly mixed $htmlAttributes = null,
        private readonly mixed $resolveAssetImageUrl = null,
        private readonly mixed $mediaTextPresentationAttributes = null,
        private readonly mixed $mediaTextPresentationStyle = null,
        private readonly mixed $structuralPresentationStyle = null
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

    /**
     * @return callable(DOMElement): bool|null
     */
    public function isRuntimeDomTargetCallback(): ?callable
    {
        return is_callable($this->isRuntimeDomTarget) ? $this->isRuntimeDomTarget : null;
    }

    /**
     * @return callable(DOMElement, DOMElement): string|null
     */
    public function navigationUnderlineColorCallback(): ?callable
    {
        return is_callable($this->navigationUnderlineColor) ? $this->navigationUnderlineColor : null;
    }

    /**
     * @return callable(DOMElement): string|null
     */
    public function resolvedStyleCallback(): ?callable
    {
        return is_callable($this->resolvedStyle) ? $this->resolvedStyle : null;
    }

    /**
     * @return callable(DOMElement): list<string>|null
     */
    public function navigationColorInteractionStatesCallback(): ?callable
    {
        return is_callable($this->navigationColorInteractionStates) ? $this->navigationColorInteractionStates : null;
    }

    /**
     * @return callable(DOMElement): string|null
     */
    public function navigationOverlayMenuCallback(): ?callable
    {
        return is_callable($this->navigationOverlayMenu) ? $this->navigationOverlayMenu : null;
    }

    public function recursiveConverter(): ?PatternRecursiveConverter { return $this->recursiveConverter; }
    public function mergedPresentationStyleCallback(): ?callable { return is_callable($this->mergedPresentationStyle) ? $this->mergedPresentationStyle : null; }
    public function htmlAttributesCallback(): ?callable { return is_callable($this->htmlAttributes) ? $this->htmlAttributes : null; }
    public function resolveAssetImageUrlCallback(): ?callable { return is_callable($this->resolveAssetImageUrl) ? $this->resolveAssetImageUrl : null; }
    public function mediaTextPresentationAttributesCallback(): ?callable { return is_callable($this->mediaTextPresentationAttributes) ? $this->mediaTextPresentationAttributes : null; }
    public function mediaTextPresentationStyleCallback(): ?callable { return is_callable($this->mediaTextPresentationStyle) ? $this->mediaTextPresentationStyle : null; }
    public function structuralPresentationStyleCallback(): ?callable { return is_callable($this->structuralPresentationStyle) ? $this->structuralPresentationStyle : null; }
}
