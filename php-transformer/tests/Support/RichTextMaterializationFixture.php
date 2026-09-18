<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Tests\Support;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextMaterialization;
use Closure;
use DOMElement;

final class RichTextMaterializationFixture implements RichTextMaterialization
{
    /** @param array<string, Closure> $operations */
    public function __construct(private readonly array $operations = array()) {}

    /** @param array<int, string> $excludedTags */
    public function content(DOMElement $element, array $excludedTags = array()): string { return isset($this->operations['content']) ? ($this->operations['content'])($element, $excludedTags) : (string) $element->textContent; }
    public function headingContent(string $content): string { return isset($this->operations['headingContent']) ? ($this->operations['headingContent'])($content) : $content; }
    public function requiresHtmlFallback(string $content): bool { return isset($this->operations['requiresHtmlFallback']) && ($this->operations['requiresHtmlFallback'])($content); }
    public function hasStructuralHtml(string $content): bool { return isset($this->operations['hasStructuralHtml']) && ($this->operations['hasStructuralHtml'])($content); }
    public function inlineVisualDeclarations(DOMElement $element): array { return isset($this->operations['inlineVisualDeclarations']) ? ($this->operations['inlineVisualDeclarations'])($element) : array(); }
    public function contentWithoutDecorativeSvg(DOMElement $element): string { return $this->content($element); }
    public function contentWithMaterializedSvgImages(DOMElement $element, string $content): ?string { return isset($this->operations['contentWithMaterializedSvgImages']) ? ($this->operations['contentWithMaterializedSvgImages'])($element, $content) : $content; }
    public function requiresHtmlFallbackWithoutNativeSvgImageObjects(string $content): bool { return $this->requiresHtmlFallback($content); }
    public function containsNativeSvgImageObject(string $content): bool { return isset($this->operations['containsNativeSvgImageObject']) && ($this->operations['containsNativeSvgImageObject'])($content); }
    public function stripDecorativeSvg(string $content): string { return $content; }
}
