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

    /**
     * Drop the parent-relative declarations a format tag keeps once RichText
     * strips its class and style; its nested carrier inherits them already.
     *
     * @param array<string, string> $declarations
     * @return array<string, string>
     */
    public function withoutKeptFormatTagDeclarations(DOMElement $element, array $declarations): array;

    public function contentWithoutDecorativeSvg(DOMElement $element): string;

    public function contentWithMaterializedSvgImages(DOMElement $element, string $content): ?string;

    /**
     * When every `<button>` descendant of `$element` is inline-safe (see
     * {@see \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier::isInlineSafeButton()}),
     * lowers each into a RichText-legal `<mark>` carrier — the same mechanical
     * attribute carrier already used for a class-bearing span/font — instead of
     * leaving a `<button>` tag that neither RichText's format library nor the
     * engine's editability policy recognizes. Returns null when unchanged.
     */
    public function contentWithInlineSafeButtonsLowered(DOMElement $element, string $content): ?string;

    public function requiresHtmlFallbackWithoutNativeSvgImageObjects(string $content): bool;

    /** The fallback gate for content materialized from a source text element. */
    public function requiresHtmlFallbackForElement(DOMElement $element, string $content): bool;

    public function containsNativeSvgImageObject(string $content): bool;

    public function stripDecorativeSvg(string $content): string;
}
