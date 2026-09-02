<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Tests\Support;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\ElementPresentationResolver;
use Closure;
use DOMElement;

final class ElementPresentationResolverFixture implements ElementPresentationResolver
{
    public function __construct(
        private readonly Closure $presentationAttributes,
        private readonly ?Closure $presentationDeclarations = null,
        private readonly ?Closure $structuralPresentationDeclarations = null
    ) {}

    public function presentationAttributes(DOMElement $element, array $excludedGeometryProperties = array(), array $forcedGeometryProperties = array()): array
    { return ($this->presentationAttributes)($element, $excludedGeometryProperties, $forcedGeometryProperties); }

    public function presentationDeclarations(DOMElement $element): array
    { return null === $this->presentationDeclarations ? array() : ($this->presentationDeclarations)($element); }

    public function structuralPresentationDeclarations(DOMElement $element): array
    { return null === $this->structuralPresentationDeclarations ? array() : ($this->structuralPresentationDeclarations)($element); }
}
