<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText;

use DOMElement;

interface RichTextMaterialization
{
    /** @param array<int, string> $excludedTags */
    public function content(DOMElement $element, array $excludedTags = array()): string;

    public function headingContent(string $content): string;

    public function requiresHtmlFallback(string $content): bool;

    public function hasStructuralHtml(string $content): bool;

    /** @return array<string, string> */
    public function inlineVisualDeclarations(DOMElement $element): array;

    public function contentWithoutDecorativeSvg(DOMElement $element): string;

    public function contentWithMaterializedSvgImages(DOMElement $element, string $content): ?string;

    public function requiresHtmlFallbackWithoutNativeSvgImageObjects(string $content): bool;

    public function containsNativeSvgImageObject(string $content): bool;

    public function stripDecorativeSvg(string $content): string;
}
