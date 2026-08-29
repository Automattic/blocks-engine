<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Closure;
use DOMElement;

/** Explicit collaborator surface consumed by {@see FormDispatcher}. */
final class FormDispatchContext
{
    /**
     * @param Closure(DOMElement): ?array<string, mixed> $searchBlockFromForm
     * @param Closure(DOMElement, array<int, array<string, mixed>>&): ?array{block: array<string, mixed>, slot: array<string, mixed>} $compose
     * @param Closure(DOMElement, ?array<string, mixed>, ?array<string, mixed>): array<string, mixed> $buildFallbackFinding
     * @param Closure(DOMElement, ?array<string, mixed>): void $recordForm
     * @param Closure(DOMElement, bool): ?array<string, mixed> $buildReadableFormBlock
     * @param Closure(DOMElement): bool $requiresPreservation
     * @param Closure(DOMElement): array<string, mixed> $htmlPreservationBlock
     * @param Closure(DOMElement): bool $isPseudoForm
     */
    public function __construct(
        private readonly Closure $searchBlockFromForm,
        private readonly Closure $compose,
        private readonly Closure $buildFallbackFinding,
        private readonly Closure $recordForm,
        private readonly Closure $buildReadableFormBlock,
        private readonly Closure $requiresPreservation,
        private readonly Closure $htmlPreservationBlock,
        private readonly Closure $isPseudoForm
    ) {
    }

    /** @return array<string, mixed>|null */
    public function searchBlockFromForm(DOMElement $element): ?array
    {
        return ($this->searchBlockFromForm)($element);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array{block: array<string, mixed>, slot: array<string, mixed>}|null
     */
    public function compose(DOMElement $element, array &$fallbacks): ?array
    {
        return ($this->compose)($element, $fallbacks);
    }

    /**
     * @param array<string, mixed>|null $readableFormBlock
     * @param array<string, mixed>|null $bindingBlock
     * @return array<string, mixed>
     */
    public function buildFallbackFinding(DOMElement $element, ?array $readableFormBlock, ?array $bindingBlock = null): array
    {
        return ($this->buildFallbackFinding)($element, $readableFormBlock, $bindingBlock);
    }

    /** @param array<string, mixed>|null $readableFormBlock */
    public function recordForm(DOMElement $element, ?array $readableFormBlock): void
    {
        ($this->recordForm)($element, $readableFormBlock);
    }

    /** @return array<string, mixed>|null */
    public function buildReadableFormBlock(DOMElement $element, bool $allowFormEvents = false): ?array
    {
        return ($this->buildReadableFormBlock)($element, $allowFormEvents);
    }

    public function requiresPreservation(DOMElement $element): bool
    {
        return ($this->requiresPreservation)($element);
    }

    /** @return array<string, mixed> */
    public function htmlPreservationBlock(DOMElement $element): array
    {
        return ($this->htmlPreservationBlock)($element);
    }

    public function isPseudoForm(DOMElement $element): bool
    {
        return ($this->isPseudoForm)($element);
    }
}
