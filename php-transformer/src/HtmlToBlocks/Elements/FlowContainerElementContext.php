<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternTreeConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorLayoutOwnership;
use Closure;
use DOMElement;

/** Explicit transformer collaborator surface for {@see FlowContainerElementConverter}. */
final class FlowContainerElementContext
{
    public function __construct(
        private readonly Closure $runtimeAppShellBlock,
        private readonly PatternTreeConverter $tree,
        public readonly AuthorLayoutOwnership $authorLayoutOwnership,
        private readonly Closure $hasMultipleRuntimeInlineTextTargets,
        private readonly Closure $paragraphBlockFromInlineContentWrapper,
        private readonly Closure $inlineAddressableRunGroupBlock,
        private readonly Closure $proofBackedWrapperCoalescing,
        private readonly Closure $isInertEmptyElement,
        private readonly PatternContext $patternContext,
        private readonly Closure $visualTextWrapperBlock,
        private readonly Closure $coalescedSingleGroupWrapper,
        private readonly Closure $shouldPreserveWrapper
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks @return array<string, mixed>|null */
    public function runtimeAppShellBlock(DOMElement $element, array &$fallbacks): ?array { return ($this->runtimeAppShellBlock)($element, $fallbacks); }
    /** @param array<int, array<string, mixed>> $fallbacks @param array<int, class-string> $patterns @return array<string, mixed>|null */
    public function recognizePatterns(DOMElement $element, array &$fallbacks, array $patterns): ?array { return $this->tree->patterns($element, $fallbacks, $patterns); }

    public function hasMultipleRuntimeInlineTextTargets(DOMElement $element): bool { return ($this->hasMultipleRuntimeInlineTextTargets)($element); }
    /** @return array<string, mixed>|null */
    public function paragraphBlockFromInlineContentWrapper(DOMElement $element): ?array { return ($this->paragraphBlockFromInlineContentWrapper)($element); }
    /** @return array<string, mixed>|null */
    public function inlineAddressableRunGroupBlock(DOMElement $element): ?array { return ($this->inlineAddressableRunGroupBlock)($element); }
    /** @param array<int, array<string, mixed>> $fallbacks @return array<string, mixed>|null */
    public function proofBackedWrapperCoalescing(DOMElement $element, array &$fallbacks): ?array { return ($this->proofBackedWrapperCoalescing)($element, $fallbacks); }
    public function isInertEmptyElement(DOMElement $element): bool { return ($this->isInertEmptyElement)($element); }

    public function patternContext(): PatternContext { return $this->patternContext; }
    /** @return array<string, mixed>|null */
    public function visualTextWrapperBlock(DOMElement $element): ?array { return ($this->visualTextWrapperBlock)($element); }
    /** @param array<int, array<string, mixed>> $fallbacks @return array<int, array<string, mixed>> */
    public function convertChildren(DOMElement $element, array &$fallbacks): array { return $this->tree->children($element, $fallbacks, true); }

    /** @param array<string, mixed> $child @return array<string, mixed>|null */
    public function coalescedSingleGroupWrapper(DOMElement $element, array $child): ?array { return ($this->coalescedSingleGroupWrapper)($element, $child); }
    public function shouldPreserveWrapper(DOMElement $element, ?string $asTag = null): bool { return ($this->shouldPreserveWrapper)($element, $asTag); }

}
