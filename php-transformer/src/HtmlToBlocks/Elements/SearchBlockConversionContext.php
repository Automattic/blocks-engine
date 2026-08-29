<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\GeneratedSupportStylesheetState;
use Closure;
use DOMElement;

/** Explicit transformer-owned surface consumed by {@see SearchBlockConverter}. */
final class SearchBlockConversionContext
{
    /**
     * @param Closure(DOMElement, string): string                                                    $attr
     * @param Closure(DOMElement): array<string, mixed>                                              $eventMetadata
     * @param Closure(DOMElement, DOMElement): bool                                                  $hasSearchFormSignal
     * @param Closure(DOMElement): array<string, mixed>                                              $presentationAttributes
     * @param Closure(DOMElement): array<string, string>                                             $presentationDeclarations
     * @param Closure(string, array<string, mixed>, array<int, array<string, mixed>>, ?DOMElement): array<string, mixed> $createBlock
     * @param Closure(DOMElement): string                                                            $outerHtml
     * @param Closure(string): string                                                                $restoreSvgCasing
     * @param Closure(): GeneratedSupportStylesheetState                                             $generatedSupportStyles
     * @param Closure(DOMElement): int                                                               $childElementCount
     * @param Closure(DOMElement): bool                                                              $isRuntimeDomTarget
     * @param Closure(DOMElement): array<string, mixed>                                              $htmlPreservationBlock
     */
    public function __construct(
        private readonly Closure $attr,
        private readonly Closure $eventMetadata,
        private readonly Closure $hasSearchFormSignal,
        private readonly Closure $presentationAttributes,
        private readonly Closure $presentationDeclarations,
        private readonly Closure $createBlock,
        private readonly Closure $outerHtml,
        private readonly Closure $restoreSvgCasing,
        private readonly Closure $generatedSupportStyles,
        private readonly Closure $childElementCount,
        private readonly Closure $isRuntimeDomTarget,
        private readonly Closure $htmlPreservationBlock
    ) {
    }

    public function attr(DOMElement $element, string $name): string
    {
        return ($this->attr)($element, $name);
    }

    /** @return array<string, mixed> */
    public function eventMetadata(DOMElement $element): array
    {
        return ($this->eventMetadata)($element);
    }

    public function hasSearchFormSignal(DOMElement $form, DOMElement $input): bool
    {
        return ($this->hasSearchFormSignal)($form, $input);
    }

    /** @return array<string, mixed> */
    public function presentationAttributes(DOMElement $element): array
    {
        return ($this->presentationAttributes)($element);
    }

    /** @return array<string, string> */
    public function presentationDeclarations(DOMElement $element): array
    {
        return ($this->presentationDeclarations)($element);
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

    public function outerHtml(DOMElement $element): string
    {
        return ($this->outerHtml)($element);
    }

    public function restoreSvgCasing(string $html): string
    {
        return ($this->restoreSvgCasing)($html);
    }

    public function generatedSupportStyles(): GeneratedSupportStylesheetState
    {
        return ($this->generatedSupportStyles)();
    }

    public function childElementCount(DOMElement $element): int
    {
        return ($this->childElementCount)($element);
    }

    public function isRuntimeDomTarget(DOMElement $element): bool
    {
        return ($this->isRuntimeDomTarget)($element);
    }

    /** @return array<string, mixed> */
    public function htmlPreservationBlock(DOMElement $element): array
    {
        return ($this->htmlPreservationBlock)($element);
    }
}
