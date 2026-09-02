<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\TableClassificationPolicy;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\ElementPresentationResolver;
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
     * @param Closure(DOMElement, array<int, array<string, mixed>>): ?array<string, mixed>                    $mediaLayoutTableColumnsBlock
     * @param Closure(DOMElement): array<string, mixed>                                                       $htmlPreservationBlock
     * @param Closure(DOMElement): array<string, mixed>                                                       $tableAttributes
     * @param Closure(string, array<string, mixed>, array<int, array<string, mixed>>, ?DOMElement): array<string, mixed> $createBlock
     */
    public function __construct(
        private readonly TableClassificationPolicy $classificationPolicy,
        private readonly Closure $nestedLayoutTableColumnsBlock,
        private readonly Closure $mediaLayoutTableColumnsBlock,
        private readonly Closure $htmlPreservationBlock,
        private readonly ElementPresentationResolver $presentationResolver,
        private readonly Closure $tableAttributes,
        private readonly Closure $createBlock
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
     * @param array<int, string> $excludedProperties
     * @param array<int, string> $excludedGeometryProperties
     * @return array<string, mixed>
     */
    public function presentationAttributes(DOMElement $element, array $excludedProperties = array(), array $excludedGeometryProperties = array()): array
    {
        return $this->presentationResolver->presentationAttributes($element, $excludedProperties, $excludedGeometryProperties);
    }

    /**
     * @return array<string, mixed>
     */
    public function tableAttributes(DOMElement $element): array
    {
        return ($this->tableAttributes)($element);
    }

    /**
     * @param array<string, mixed>             $attributes
     * @param array<int, array<string, mixed>> $innerBlocks
     * @return array<string, mixed>
     */
    public function createBlock(string $name, array $attributes = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array
    {
        return ($this->createBlock)($name, $attributes, $innerBlocks, $sourceElement);
    }
}
