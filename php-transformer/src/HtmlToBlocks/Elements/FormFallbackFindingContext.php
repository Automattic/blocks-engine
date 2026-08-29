<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Closure;
use DOMElement;

/** Explicit transformer collaborator surface for form fallback findings. */
final class FormFallbackFindingContext
{
    /**
     * @param Closure(): array<int, array<string, mixed>>                                         $stylesheetAssets
     * @param Closure(): string                                                                    $formLayoutCss
     * @param Closure(DOMElement): array{html: string, bytes: int, truncated: bool}                $boundedFallbackHtml
     * @param Closure(DOMElement): array<int, string>                                               $runtimeDomSelectors
     * @param Closure(DOMElement): string                                                           $runtimeIslandSelector
     * @param Closure(DOMElement): string                                                           $elementSelector
     * @param Closure(DOMElement): array<string, string>                                            $htmlAttributes
     * @param Closure(DOMElement): array<string, mixed>                                             $sourceContext
     * @param Closure(DOMElement): array<string, mixed>                                             $classifyFallbackSubtree
     * @param Closure(DOMElement): array<string, mixed>                                             $eventMetadata
     * @param Closure(array<string, mixed>, string, array<int, string>): array<string, mixed>       $blockBinding
     * @param Closure(DOMElement): int                                                              $childElementCount
     * @param Closure(array<string, mixed>): array<string, mixed>                                   $buildFallbackDiagnostic
     */
    public function __construct(
        private readonly Closure $stylesheetAssets,
        private readonly Closure $formLayoutCss,
        private readonly Closure $boundedFallbackHtml,
        private readonly Closure $runtimeDomSelectors,
        private readonly Closure $runtimeIslandSelector,
        private readonly Closure $elementSelector,
        private readonly Closure $htmlAttributes,
        private readonly Closure $sourceContext,
        private readonly Closure $classifyFallbackSubtree,
        private readonly Closure $eventMetadata,
        private readonly Closure $blockBinding,
        private readonly Closure $childElementCount,
        private readonly Closure $buildFallbackDiagnostic
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function stylesheetAssets(): array
    {
        return ($this->stylesheetAssets)();
    }

    public function formLayoutCss(): string
    {
        return ($this->formLayoutCss)();
    }

    /** @return array{html: string, bytes: int, truncated: bool} */
    public function boundedFallbackHtml(DOMElement $element): array
    {
        return ($this->boundedFallbackHtml)($element);
    }

    /** @return array<int, string> */
    public function runtimeDomSelectors(DOMElement $element): array
    {
        return ($this->runtimeDomSelectors)($element);
    }

    public function runtimeIslandSelector(DOMElement $element): string
    {
        return ($this->runtimeIslandSelector)($element);
    }

    public function elementSelector(DOMElement $element): string
    {
        return ($this->elementSelector)($element);
    }

    /** @return array<string, string> */
    public function htmlAttributes(DOMElement $element): array
    {
        return ($this->htmlAttributes)($element);
    }

    /** @return array<string, mixed> */
    public function sourceContext(DOMElement $element): array
    {
        return ($this->sourceContext)($element);
    }

    /** @return array<string, mixed> */
    public function classifyFallbackSubtree(DOMElement $element): array
    {
        return ($this->classifyFallbackSubtree)($element);
    }

    /** @return array<string, mixed> */
    public function eventMetadata(DOMElement $element): array
    {
        return ($this->eventMetadata)($element);
    }

    /**
     * @param array<string, mixed> $block
     * @param array<int, string> $supersededRuntimeSelectors
     * @return array<string, mixed>
     */
    public function blockBinding(array $block, string $role, array $supersededRuntimeSelectors): array
    {
        return ($this->blockBinding)($block, $role, $supersededRuntimeSelectors);
    }

    public function childElementCount(DOMElement $element): int
    {
        return ($this->childElementCount)($element);
    }

    /** @param array<string, mixed> $finding @return array<string, mixed> */
    public function buildFallbackDiagnostic(array $finding): array
    {
        return ($this->buildFallbackDiagnostic)($finding);
    }
}
