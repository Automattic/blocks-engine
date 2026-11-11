<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ParagraphImageUnwrapping;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\WrapperCoalescer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ListStructure;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\TableClassificationPolicy;
use Closure;
use DOMElement;

/** Conversion-policy decisions required while projecting author selector identities. */
final class AuthorSelectorSemanticContext
{
    /**
     * @param Closure(DOMElement): bool $preservesEmptyVisualElement
     */
    public function __construct(
        public readonly AuthorLayoutOwnership $authorLayoutOwnership,
        private readonly ListStructure $listStructure,
        private readonly StyleResolver $styleResolver,
        private readonly WrapperCoalescer $wrapperCoalescer,
        private readonly Closure $preservesEmptyVisualElement,
        private readonly HtmlTransformerSession $session,
        private readonly TableClassificationPolicy $tableClassificationPolicy,
        private readonly ParagraphImageUnwrapping $paragraphImages
    ) {}

    public function isStructuralListItem(DOMElement $element): bool
    {
        return $this->listStructure->isStructuralListItem($element);
    }

    public function requiresIndependentSemanticWrapper(DOMElement $element): bool
    {
        if ( 'span' !== strtolower($element->tagName) || $this->authorLayoutOwnership->isRichTextInlineContext($element) ) {
            return false;
        }

        if ( $this->authorLayoutOwnership->ownsPositioningGeometry($element) ) {
            return true;
        }

        // An empty painted span outside RichText lowers to its own spacer
        // block (InlineContentElementConverter), never to a `<mark>` carrier.
        // Author selectors that name it must address that block, so it needs
        // a semantic marker rather than a rich-text marker no block carries.
        if ( 0 === SourceDom::childElementCount($element)
            && '' === trim($element->textContent ?? '')
            && ($this->preservesEmptyVisualElement)($element)
        ) {
            return true;
        }

        $parent = $element->parentNode instanceof DOMElement ? $element->parentNode : null;
        if ( ! $parent instanceof DOMElement || ! $this->wrapperCoalescer->isStructuralLayoutElement($parent) ) {
            return false;
        }

        $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
        // A grid placement belongs to this inline node. Keep phrasing-only grid
        // siblings in one RichText container rather than replacing their direct
        // grid items with Group/Paragraph wrappers.
        if ( 'grid' === CssValueInspector::comparable((string) ($this->styleResolver->presentationDeclarations($parent)['display'] ?? '')) && ( '' !== trim((string) ($declarations['grid-column'] ?? '')) || '' !== trim((string) ($declarations['grid-row'] ?? '')) ) ) {
            return false;
        }
        $display = CssValueInspector::comparable((string) ($declarations['display'] ?? 'inline'));
        if ( 'block' === $display ) {
            return true;
        }

        foreach ( array( 'font-size', 'line-height', 'letter-spacing', 'text-transform', 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'border', 'border-width', 'border-color', 'border-radius', 'margin', 'width', 'height', 'min-width', 'min-height', 'max-width', 'max-height' ) as $property ) {
            if ( '' !== trim((string) ($declarations[$property] ?? '')) ) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $parsed */
    public function tableSelectorNeedsStructuralProjection(array $parsed, DOMElement $element): bool
    {
        return TableSelectorProjectionPolicy::needsStructuralProjection($parsed, $element);
    }

    public function isRepresentableTable(DOMElement $table): bool
    {
        return $this->session->authorSelectorProjectionState()->tableRepresentable(
            spl_object_id($table),
            fn (): bool => (bool) $this->tableClassificationPolicy->classify($table)['representable']
        );
    }

    /**
     * Whether conversion emits this source element as a block of another
     * element type (a paragraph that only wraps an image becomes a figure),
     * which changes the type-sibling positions structural selectors see.
     */
    public function convertsToOtherElementType(DOMElement $element): bool
    {
        return 'p' === strtolower($element->tagName) && $this->paragraphImages->paragraphImageChild($element) instanceof DOMElement;
    }
}
