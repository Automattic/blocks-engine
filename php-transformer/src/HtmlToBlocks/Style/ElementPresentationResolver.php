<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use DOMElement;

/** Resolves the source presentation consumed by element converters. */
interface ElementPresentationResolver
{
    /** @return array<string, mixed> */
    public function presentationAttributes(DOMElement $element, array $excludedGeometryProperties = array(), array $forcedGeometryProperties = array()): array;

    /** @return array<string, string> */
    public function presentationDeclarations(DOMElement $element): array;

    /** @return array<string, string> */
    public function structuralPresentationDeclarations(DOMElement $element): array;
}
