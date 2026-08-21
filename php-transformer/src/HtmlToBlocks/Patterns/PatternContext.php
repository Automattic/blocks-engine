<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

final class PatternContext
{
    /**
     * @param callable(DOMElement): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $innerHtml
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null): array<string, mixed> $createBlock
     * @param callable(DOMElement): bool|null $isRuntimeDomTarget
     * @param callable(DOMElement): PatternResult|null $convertChildren
     * @param callable(DOMElement, array<int, string>): PatternResult|null $convertChildrenWithoutTags
     * @param callable(DOMElement, DOMElement): string|null $navigationUnderlineColor
     * @param callable(DOMElement): string|null $resolvedStyle
     * @param callable(DOMElement): PatternResult|null $convertElement
     * @param callable(DOMElement): list<string>|null $navigationColorInteractionStates
     * @param callable(DOMElement): string|null $navigationOverlayMenu
     * @param callable(): callable|null $beginMutationScope
     */
    public function __construct(
        private readonly mixed $presentationAttributes,
        private readonly mixed $innerHtml,
        private readonly mixed $createBlock,
        private readonly mixed $isRuntimeDomTarget = null,
        private readonly mixed $convertChildren = null,
        private readonly mixed $convertChildrenWithoutTags = null,
        private readonly mixed $navigationUnderlineColor = null,
        private readonly mixed $resolvedStyle = null,
        private readonly mixed $convertElement = null,
        private readonly mixed $navigationColorInteractionStates = null,
        private readonly mixed $navigationOverlayMenu = null,
        private readonly mixed $beginMutationScope = null
    ) {
    }

    /** @var array<int, array<string, mixed>> */
    private array $fallbacks = array();

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
     * @return callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null): array<string, mixed>
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
     * @return callable(DOMElement): PatternResult|null
     */
    public function convertChildrenCallback(): ?callable
    {
        if ( ! is_callable($this->convertChildren) ) {
            return null;
        }

        return function (DOMElement $element): PatternResult {
            $this->beginMutationScope();
            $result = ($this->convertChildren)($element);
            $this->record($result);
            return $result;
        };
    }

    /**
     * Convert one element to the block the generic pipeline would emit for it.
     * A pattern that keeps a sibling element out of its own block needs the
     * element's own conversion rather than its children's, so the sibling is
     * emitted exactly as it would be anywhere else in the document.
     *
     * @return callable(DOMElement): PatternResult|null
     */
    public function convertElementCallback(): ?callable
    {
        if ( ! is_callable($this->convertElement) ) {
            return null;
        }

        return function (DOMElement $element): PatternResult {
            $this->beginMutationScope();
            $result = ($this->convertElement)($element);
            $this->record($result);
            return $result;
        };
    }

    /**
     * @return callable(DOMElement, array<int, string>): PatternResult|null
     */
    public function convertChildrenWithoutTagsCallback(): ?callable
    {
        if ( ! is_callable($this->convertChildrenWithoutTags) ) {
            return null;
        }

        return function (DOMElement $element, array $excludedTags): PatternResult {
            $this->beginMutationScope();
            $result = ($this->convertChildrenWithoutTags)($element, $excludedTags);
            $this->record($result);
            return $result;
        };
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

    public function matchScope(): self
    {
        return new self(
            $this->presentationAttributes,
            $this->innerHtml,
            $this->createBlock,
            $this->isRuntimeDomTarget,
            $this->convertChildren,
            $this->convertChildrenWithoutTags,
            $this->navigationUnderlineColor,
            $this->resolvedStyle,
            $this->convertElement,
            $this->navigationColorInteractionStates,
            $this->navigationOverlayMenu,
            $this->beginMutationScope
        );
    }

    public function commitMutations(): void
    {
        $this->rollbackMutations = null;
    }

    public function discardMutations(): void
    {
        if ( null !== $this->rollbackMutations ) {
            ($this->rollbackMutations)();
            $this->rollbackMutations = null;
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function fallbacks(): array
    {
        return $this->fallbacks;
    }

    private function record(PatternResult $result): void
    {
        PatternResult::mergeFallbacksInto($this->fallbacks, $result->fallbacks());
    }

    /** @var callable|null */
    private mixed $rollbackMutations = null;

    private function beginMutationScope(): void
    {
        if ( null !== $this->rollbackMutations || ! is_callable($this->beginMutationScope) ) {
            return;
        }

        $rollback = ($this->beginMutationScope)();
        $this->rollbackMutations = is_callable($rollback) ? $rollback : null;
    }
}
