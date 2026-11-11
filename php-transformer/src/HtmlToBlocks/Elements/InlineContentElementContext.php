<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternTreeConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Closure;
use DOMElement;

/** Explicit transformer collaborator surface for {@see InlineContentElementConverter}. */
final class InlineContentElementContext
{
    /**
     * @param Closure(DOMElement): ?array<string, mixed> $inlineSvgTextGroupBlock
     * @param Closure(DOMElement): bool $ownsPositioningGeometry
     * @param Closure(DOMElement, array<int, array<string, mixed>>&): ?array<string, mixed> $positionedInlineCarrierBlock
     * @param Closure(DOMElement): bool $hasAuthorSemanticMarker
     * @param Closure(DOMElement): string $richTextMarker
     * @param Closure(DOMElement): bool $shouldPreserveEmptyVisualElement
     * @param Closure(DOMElement): array<string, mixed> $emptyVisualSpacerBlock
     * @param (Closure(DOMElement): void)|null $rememberNativeDisclosureRoot
     * @param (Closure(DOMElement): bool)|null $retainsRuntimeTextLeafInRichText
     */
    public function __construct(
        private readonly PatternTreeConverter $tree,
        private readonly RuntimeDomTargets $runtimeDomTargets,
        private readonly Closure $inlineSvgTextGroupBlock,
        private readonly Closure $ownsPositioningGeometry,
        private readonly Closure $positionedInlineCarrierBlock,
        private readonly Closure $hasAuthorSemanticMarker,
        private readonly Closure $richTextMarker,
        private readonly ListStructure $listStructure,
        private readonly Closure $shouldPreserveEmptyVisualElement,
        private readonly Closure $emptyVisualSpacerBlock,
        private readonly ?Closure $rememberNativeDisclosureRoot = null,
        private readonly ?Closure $retainsRuntimeTextLeafInRichText = null
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks @param array<int, class-string> $patterns @return array<string, mixed>|null */
    public function recognizePatterns(DOMElement $element, array &$fallbacks, array $patterns): ?array { return $this->tree->patterns($element, $fallbacks, $patterns); }
    public function isRuntimeDomTarget(DOMElement $element): bool { return $this->runtimeDomTargets->isRuntimeDomTarget($element); }
    public function retainsRuntimeTextLeafInRichText(DOMElement $element): bool { return null !== $this->retainsRuntimeTextLeafInRichText && ($this->retainsRuntimeTextLeafInRichText)($element); }
    /** @return array<string, mixed>|null */
    public function inlineSvgTextGroupBlock(DOMElement $element): ?array { return ($this->inlineSvgTextGroupBlock)($element); }
    public function ownsPositioningGeometry(DOMElement $element): bool { return ($this->ownsPositioningGeometry)($element); }
    /** @param array<int, array<string, mixed>> $fallbacks @return array<string, mixed>|null */
    public function positionedInlineCarrierBlock(DOMElement $element, array &$fallbacks): ?array { return ($this->positionedInlineCarrierBlock)($element, $fallbacks); }
    public function hasAuthorSemanticMarker(DOMElement $element): bool { return ($this->hasAuthorSemanticMarker)($element); }

    /** @param array<int, array<string, mixed>> $fallbacks @return array<int, array<string, mixed>> */
    public function convertChildren(DOMElement $element, array &$fallbacks): array { return $this->tree->children($element, $fallbacks, true); }

    public function richTextMarker(DOMElement $element): string { return ($this->richTextMarker)($element); }

    public function dynamicTextContent(DOMElement $element): ?string
    {
        $target = trim(SourceDom::attr($element, 'data-target'));
        if ( '' === $target ) {
            $target = trim(SourceDom::attr($element, 'data-count'));
        }
        if ( '' === $target || ! is_numeric($target) ) {
            return null;
        }

        $isFloat = 'true' === strtolower(trim(SourceDom::attr($element, 'data-float'))) || str_contains($target, '.');
        $value = $isFloat
            ? number_format((float) $target, 1, '.', ',')
            : number_format((float) $target, 0, '.', ',');

        return SourceDom::attr($element, 'data-prefix') . $value . SourceDom::attr($element, 'data-suffix');
    }
    public function isStructuralListItem(DOMElement $element): bool { return $this->listStructure->isStructuralListItem($element); }
    public function shouldPreserveEmptyVisualElement(DOMElement $element): bool { return ($this->shouldPreserveEmptyVisualElement)($element); }
    /** @return array<string, mixed> */
    public function emptyVisualSpacerBlock(DOMElement $element): array { return ($this->emptyVisualSpacerBlock)($element); }
    public function rememberNativeDisclosureRoot(DOMElement $element): void { if ( null !== $this->rememberNativeDisclosureRoot ) { ($this->rememberNativeDisclosureRoot)($element); } }
}
