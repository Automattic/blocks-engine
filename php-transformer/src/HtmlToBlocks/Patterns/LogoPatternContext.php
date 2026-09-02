<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextMaterialization;
use Closure;
use DOMElement;

final class LogoPatternContext
{
    public function __construct(
        private readonly RichTextMaterialization $richTextMaterializer
    ) {
    }

    public function richText(DOMElement $element): string { return $this->richTextMaterializer->content($element); }
    public function materializeSvgImages(DOMElement $element, string $content): ?string { return $this->richTextMaterializer->contentWithMaterializedSvgImages($element, $content); }
}
