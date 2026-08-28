<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\FallbackEmitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\RuntimeDomState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\RuntimeSelectorState;
use Closure;
use DOMElement;

/**
 * Explicit collaborator surface for {@see RuntimeIslandAnalyzer}.
 *
 * The session state objects and the fallback emitter are resolved lazily
 * through closures because they are per-transform: the analyzer is constructed
 * once with the transformer, but must see the state belonging to the transform
 * currently running.
 */
final class RuntimeIslandContext
{
    /**
     * @param Closure(): FallbackEmitter                                      $fallbackEmitter
     * @param Closure(): RuntimeDomState                                      $runtimeDom
     * @param Closure(): RuntimeSelectorState                                 $runtimeSelectors
     * @param Closure(DOMElement, string): string                             $attr
     * @param Closure(DOMElement): iterable<DOMElement>                       $descendantElements
     * @param Closure(DOMElement): string                                     $runtimeIslandSelector
     * @param Closure(DOMElement): array<string, mixed>                       $eventMetadata
     * @param Closure(DOMElement): array<int, array<string, mixed>>           $requiredScriptsForElement
     * @param Closure(string): ?DOMElement                                    $preservedHtmlRootElement
     * @param Closure(DOMElement): bool                                       $formHasDataEntryControls
     * @param Closure(DOMElement): bool                                       $hasFormAncestor
     * @param Closure(DOMElement): bool                                       $hasWorkspaceSurface
     * @param Closure(DOMElement): bool                                       $isDivBasedPseudoForm
     * @param Closure(DOMElement): bool                                       $isFormControlElement
     * @param Closure(string): bool                                           $isInlineContentElement
     * @param Closure(string): bool                                           $isPresentationalAnimationSelector
     * @param Closure(array<int, array<string, mixed>>): array<int, array<string, mixed>> $dedupeArrayRows
     */
    public function __construct(
        private readonly Closure $fallbackEmitter,
        private readonly Closure $runtimeDom,
        private readonly Closure $runtimeSelectors,
        private readonly Closure $attr,
        private readonly Closure $descendantElements,
        private readonly Closure $runtimeIslandSelector,
        private readonly Closure $eventMetadata,
        private readonly Closure $requiredScriptsForElement,
        private readonly Closure $preservedHtmlRootElement,
        private readonly Closure $formHasDataEntryControls,
        private readonly Closure $hasFormAncestor,
        private readonly Closure $hasWorkspaceSurface,
        private readonly Closure $isDivBasedPseudoForm,
        private readonly Closure $isFormControlElement,
        private readonly Closure $isInlineContentElement,
        private readonly Closure $isPresentationalAnimationSelector,
        private readonly Closure $dedupeArrayRows
    ) {
    }

    public function fallbackEmitter(): FallbackEmitter
    {
        return ($this->fallbackEmitter)();
    }

    public function runtimeDom(): RuntimeDomState
    {
        return ($this->runtimeDom)();
    }

    public function runtimeSelectors(): RuntimeSelectorState
    {
        return ($this->runtimeSelectors)();
    }

    public function attr(DOMElement $element, string $name): string
    {
        return ($this->attr)($element, $name);
    }

    /**
     * @return iterable<DOMElement>
     */
    public function descendantElements(DOMElement $element): iterable
    {
        return ($this->descendantElements)($element);
    }

    public function runtimeIslandSelector(DOMElement $element): string
    {
        return ($this->runtimeIslandSelector)($element);
    }

    /**
     * @return array<string, mixed>
     */
    public function eventMetadata(DOMElement $element): array
    {
        return ($this->eventMetadata)($element);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function requiredScriptsForElement(DOMElement $element): array
    {
        return ($this->requiredScriptsForElement)($element);
    }

    public function preservedHtmlRootElement(string $html): ?DOMElement
    {
        return ($this->preservedHtmlRootElement)($html);
    }

    public function formHasDataEntryControls(DOMElement $element): bool
    {
        return ($this->formHasDataEntryControls)($element);
    }

    public function hasFormAncestor(DOMElement $element): bool
    {
        return ($this->hasFormAncestor)($element);
    }

    public function hasWorkspaceSurface(DOMElement $element): bool
    {
        return ($this->hasWorkspaceSurface)($element);
    }

    public function isDivBasedPseudoForm(DOMElement $element): bool
    {
        return ($this->isDivBasedPseudoForm)($element);
    }

    public function isFormControlElement(DOMElement $element): bool
    {
        return ($this->isFormControlElement)($element);
    }

    public function isInlineContentElement(string $tagName): bool
    {
        return ($this->isInlineContentElement)($tagName);
    }

    public function isPresentationalAnimationSelector(string $selector): bool
    {
        return ($this->isPresentationalAnimationSelector)($selector);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public function dedupeArrayRows(array $rows): array
    {
        return ($this->dedupeArrayRows)($rows);
    }
}
