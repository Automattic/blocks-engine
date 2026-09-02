<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use DOMElement;

/** Materialization operations required by {@see SvgElementConverter}. */
interface SvgElementMaterializer
{
    /** @return array<string, mixed>|null */
    public function inlineSvgBlockFromElement(DOMElement $element): ?array;

    public function inlineSvgRichTextImageMarkup(DOMElement $element, bool $includeLink = true): ?string;

    public function svgNeedsPhrasingHost(DOMElement $element): bool;

    public function ensureInlineSvgBoxStyle(string $html, DOMElement $element): string;

    public function restoreSvgCasing(string $html): string;

    public function isSafeDecorativeSvgElement(DOMElement $element): bool;
}
