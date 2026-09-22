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

    /**
     * The authored `font-size` that must be serialized inline on a text block
     * for the authored size to win at the WordPress runtime, or '' when the
     * mapped presentation attributes already carry it.
     *
     * @return string
     */
    public function bakedTypographyFontSize(DOMElement $element): string;

    /** Return a class that restates responsive text size outside author layers. */
    public function responsiveTypographyClassName(DOMElement $element): string;
}
