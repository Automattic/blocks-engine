<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextMaterialization;
use Closure;
use DOMElement;

final class LogoPatternContext
{
    /**
     * @param Closure(DOMElement): array<string, string>|null $structuralDeclarations
     */
    public function __construct(
        private readonly RichTextMaterialization $richTextMaterializer,
        private readonly ?Closure $structuralDeclarations = null
    ) {
    }

    public function richText(DOMElement $element): string { return $this->richTextMaterializer->content($element); }
    public function materializeSvgImages(DOMElement $element, string $content): ?string { return $this->richTextMaterializer->contentWithMaterializedSvgImages($element, $content); }

    /** @return array<string, string> */
    public function structuralPresentationDeclarations(DOMElement $element): array
    {
        return null === $this->structuralDeclarations ? array() : ($this->structuralDeclarations)($element);
    }
}
