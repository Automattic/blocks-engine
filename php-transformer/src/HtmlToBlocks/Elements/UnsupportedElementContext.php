<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Closure;
use DOMElement;

/**
 * Explicit collaborator surface for {@see UnsupportedElementRecorder}.
 */
final class UnsupportedElementContext
{
    /**
     * @param Closure(DOMElement): ?array<string, mixed>  $maybeGenerateCustomBlock
     * @param Closure(array<string, mixed>, DOMElement): array<string, mixed> $generatedComponentBlock
     * @param Closure(DOMElement): string                 $elementSelector
     * @param Closure(DOMElement): array<string, mixed>   $htmlAttributes
     * @param Closure(DOMElement): array<string, mixed>   $sourceContext
     * @param Closure(DOMElement): array<string, mixed>   $classifyFallbackSubtree
     * @param Closure(DOMElement): array<string, mixed>   $eventMetadata
     * @param Closure(DOMElement): int                    $childElementCount
     * @param Closure(DOMElement): string                 $safeFallbackHtml
     * @param Closure(DOMElement): array<string, mixed>   $formControlMetadata
     * @param Closure(array<string, mixed>): array<string, mixed> $buildFallbackDiagnostic
     */
    public function __construct(
        private readonly Closure $maybeGenerateCustomBlock,
        private readonly Closure $generatedComponentBlock,
        private readonly Closure $elementSelector,
        private readonly Closure $htmlAttributes,
        private readonly Closure $sourceContext,
        private readonly Closure $classifyFallbackSubtree,
        private readonly Closure $eventMetadata,
        private readonly Closure $childElementCount,
        private readonly Closure $safeFallbackHtml,
        private readonly Closure $formControlMetadata,
        private readonly Closure $buildFallbackDiagnostic
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function maybeGenerateCustomBlock(DOMElement $element): ?array
    {
        return ($this->maybeGenerateCustomBlock)($element);
    }

    /**
     * @param array<string, mixed> $generated
     * @return array<string, mixed>
     */
    public function generatedComponentBlock(array $generated, DOMElement $element): array
    {
        return ($this->generatedComponentBlock)($generated, $element);
    }

    public function elementSelector(DOMElement $element): string
    {
        return ($this->elementSelector)($element);
    }

    /**
     * @return array<string, mixed>
     */
    public function htmlAttributes(DOMElement $element): array
    {
        return ($this->htmlAttributes)($element);
    }

    /**
     * @return array<string, mixed>
     */
    public function sourceContext(DOMElement $element): array
    {
        return ($this->sourceContext)($element);
    }

    /**
     * @return array<string, mixed>
     */
    public function classifyFallbackSubtree(DOMElement $element): array
    {
        return ($this->classifyFallbackSubtree)($element);
    }

    /**
     * @return array<string, mixed>
     */
    public function eventMetadata(DOMElement $element): array
    {
        return ($this->eventMetadata)($element);
    }

    public function childElementCount(DOMElement $element): int
    {
        return ($this->childElementCount)($element);
    }

    public function safeFallbackHtml(DOMElement $element): string
    {
        return ($this->safeFallbackHtml)($element);
    }

    /**
     * @return array<string, mixed>
     */
    public function formControlMetadata(DOMElement $element): array
    {
        return ($this->formControlMetadata)($element);
    }

    /**
     * @param array<string, mixed> $fallback
     * @return array<string, mixed>
     */
    public function buildFallbackDiagnostic(array $fallback): array
    {
        return ($this->buildFallbackDiagnostic)($fallback);
    }
}
