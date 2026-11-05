<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\TableClassificationPolicy;
use Closure;
use DOMElement;

/**
 * Explicit collaborator surface for {@see TableElementConverter}.
 *
 * The classification policy is already a first-class collaborator, so it is
 * injected directly rather than wrapped in closures. Only the transformer-owned
 * block builders are passed as closures.
 */
final class TableElementContext
{
    /**
     * @param Closure(DOMElement, array<int, array<string, mixed>>): ?array<string, mixed>                    $nestedLayoutTableColumnsBlock
     * @param Closure(DOMElement, array<int, array<string, mixed>>): ?array<string, mixed>                    $singleColumnLayoutTableGroupBlock
     * @param Closure(DOMElement, array<int, array<string, mixed>>): ?array<string, mixed>                    $mediaLayoutTableColumnsBlock
     * @param Closure(DOMElement): array<string, mixed>                                                       $htmlPreservationBlock
     * @param Closure(DOMElement): array<string, mixed>                                                       $tableAttributes
     */
    public function __construct(
        private readonly TableClassificationPolicy $classificationPolicy,
        private readonly Closure $nestedLayoutTableColumnsBlock,
        private readonly Closure $singleColumnLayoutTableGroupBlock,
        private readonly Closure $mediaLayoutTableColumnsBlock,
        private readonly Closure $htmlPreservationBlock,
        private readonly Closure $tableAttributes
    ) {
    }

    public function classificationPolicy(): TableClassificationPolicy
    {
        return $this->classificationPolicy;
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    public function nestedLayoutTableColumnsBlock(DOMElement $element, array &$fallbacks): ?array
    {
        return ($this->nestedLayoutTableColumnsBlock)($element, $fallbacks);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    public function singleColumnLayoutTableGroupBlock(DOMElement $element, array &$fallbacks): ?array
    {
        return ($this->singleColumnLayoutTableGroupBlock)($element, $fallbacks);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    public function mediaLayoutTableColumnsBlock(DOMElement $element, array &$fallbacks): ?array
    {
        return ($this->mediaLayoutTableColumnsBlock)($element, $fallbacks);
    }

    /**
     * @return array<string, mixed>
     */
    public function htmlPreservationBlock(DOMElement $element): array
    {
        return ($this->htmlPreservationBlock)($element);
    }

    /**
     * @return array<string, mixed>
     */
    public function tableAttributes(DOMElement $element): array
    {
        return ($this->tableAttributes)($element);
    }

}
