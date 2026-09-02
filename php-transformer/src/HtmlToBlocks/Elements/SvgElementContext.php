<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Closure;
use DOMElement;

/** Explicit transformer collaborator surface for {@see SvgElementConverter}. */
final class SvgElementContext
{
    /**
     * @param Closure(DOMElement): bool $isInertHiddenStorage
     * @param Closure(DOMElement): bool $isRuntimeDomTarget
     * @param Closure(DOMElement): string $sanitizeMarkup
     * @param Closure(string): bool $isSafeContent
     * @param Closure(DOMElement): bool $hasDrawableContent
     * @param Closure(DOMElement): array<string, mixed> $presentationAttributes
     * @param Closure(string, array<string, mixed>, array<int, array<string, mixed>>, ?DOMElement): array<string, mixed> $createBlock
     * @param Closure(DOMElement, array<int, array<string, mixed>>&): void $captureFallback
     */
    public function __construct(
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly Closure $isInertHiddenStorage,
        private readonly Closure $isRuntimeDomTarget,
        private readonly Closure $sanitizeMarkup,
        private readonly Closure $isSafeContent,
        private readonly Closure $hasDrawableContent,
        private readonly Closure $presentationAttributes,
        private readonly Closure $createBlock,
        private readonly Closure $captureFallback
    ) {
    }

    public function isInertHiddenStorage(DOMElement $element): bool
    {
        return ($this->isInertHiddenStorage)($element);
    }

    public function isRuntimeDomTarget(DOMElement $element): bool
    {
        return ($this->isRuntimeDomTarget)($element);
    }

    public function sanitizeMarkup(DOMElement $element): string
    {
        return ($this->sanitizeMarkup)($element);
    }

    public function isSafeContent(string $html): bool
    {
        return ($this->isSafeContent)($html);
    }

    public function isVisualLayerElement(DOMElement $element): bool
    {
        return $this->sourceElementClassifier->isVisualLayerElement($element);
    }

    public function hasDrawableContent(DOMElement $element): bool
    {
        return ($this->hasDrawableContent)($element);
    }

    /** @return array<string, mixed> */
    public function presentationAttributes(DOMElement $element): array
    {
        return ($this->presentationAttributes)($element);
    }

    /** @param array<string, mixed> $attributes @param array<int, array<string, mixed>> $innerBlocks @return array<string, mixed> */
    public function createBlock(string $name, array $attributes, array $innerBlocks, ?DOMElement $sourceElement): array
    {
        return ($this->createBlock)($name, $attributes, $innerBlocks, $sourceElement);
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function captureFallback(DOMElement $element, array &$fallbacks): void
    {
        ($this->captureFallback)($element, $fallbacks);
    }
}
