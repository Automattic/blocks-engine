<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ParagraphImageUnwrapping;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ListStructure;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\TableClassificationPolicy;
use Closure;
use DOMElement;

/** Conversion-policy decisions required while projecting author selector identities. */
final class AuthorSelectorSemanticContext
{
    /**
     * @param Closure(DOMElement): bool        $requiresIndependentSemanticWrapper
     */
    public function __construct(
        public readonly AuthorLayoutOwnership $authorLayoutOwnership,
        private readonly ListStructure $listStructure,
        private readonly Closure $requiresIndependentSemanticWrapper,
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
        return ($this->requiresIndependentSemanticWrapper)($element);
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
