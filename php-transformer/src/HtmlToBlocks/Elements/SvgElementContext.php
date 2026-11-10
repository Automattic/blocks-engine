<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Closure;
use DOMElement;

/** Explicit transformer collaborator surface for {@see SvgElementConverter}. */
final class SvgElementContext
{
    /**
     * @param Closure(DOMElement): bool $isInertHiddenStorage
     * @param Closure(DOMElement): string $sanitizeMarkup
     * @param Closure(string): bool $isSafeContent
     * @param Closure(DOMElement): bool $hasDrawableContent
     * @param Closure(DOMElement, array<int, array<string, mixed>>&): void $captureFallback
     * @param Closure(DOMElement, array<int, array<string, mixed>>&): ?array<string, mixed> $foreignObjectTextBlock
     */
    public function __construct(
        private readonly Closure $isInertHiddenStorage,
        private readonly RuntimeDomTargets $runtimeDomTargets,
        private readonly Closure $sanitizeMarkup,
        private readonly Closure $isSafeContent,
        private readonly Closure $hasDrawableContent,
        private readonly Closure $captureFallback,
        private readonly ?Closure $omitEmptyPaint = null,
        private readonly ?Closure $foreignObjectTextBlock = null
    ) {
    }

    public function omitEmptyPaint(DOMElement $element): bool
    {
        return null !== $this->omitEmptyPaint && ($this->omitEmptyPaint)($element);
    }

    public function isInertHiddenStorage(DOMElement $element): bool
    {
        return ($this->isInertHiddenStorage)($element);
    }

    public function isRuntimeDomTarget(DOMElement $element): bool
    {
        return $this->runtimeDomTargets->isRuntimeDomTarget($element);
    }

    public function sanitizeMarkup(DOMElement $element): string
    {
        return ($this->sanitizeMarkup)($element);
    }

    public function isSafeContent(string $html): bool
    {
        return ($this->isSafeContent)($html);
    }

    public function hasDrawableContent(DOMElement $element): bool
    {
        return ($this->hasDrawableContent)($element);
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function captureFallback(DOMElement $element, array &$fallbacks): void
    {
        ($this->captureFallback)($element, $fallbacks);
    }

    /**
     * The core/group a foreignObject-only fit-text SVG lowers to — it holds the
     * SVG's presentation and the native text block — or null when the SVG is
     * not that shape.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    public function foreignObjectTextBlock(DOMElement $element, array &$fallbacks): ?array
    {
        return null === $this->foreignObjectTextBlock ? null : ($this->foreignObjectTextBlock)($element, $fallbacks);
    }
}
