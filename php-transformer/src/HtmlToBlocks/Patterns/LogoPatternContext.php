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
     * @param Closure(DOMElement, array<int, array<string, mixed>>): void|null $captureSvgFallback
     */
    public function __construct(
        private readonly RichTextMaterialization $richTextMaterializer,
        private readonly ?Closure $structuralDeclarations = null,
        private readonly ?Closure $captureSvgFallback = null
    ) {
    }

    public function richText(DOMElement $element): string { return $this->richTextMaterializer->content($element); }
    public function materializeSvgImages(DOMElement $element, string $content): ?string { return $this->richTextMaterializer->contentWithMaterializedSvgImages($element, $content); }

    /**
     * Records the inline SVG artwork a logo label cannot keep, using the
     * standard inline SVG fallback diagnostic.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     */
    public function captureSvgFallback(DOMElement $element, array &$fallbacks): void
    {
        if ( null !== $this->captureSvgFallback ) {
            ($this->captureSvgFallback)($element, $fallbacks);
        }
    }

    /** @return array<string, string> */
    public function structuralPresentationDeclarations(DOMElement $element): array
    {
        return null === $this->structuralDeclarations ? array() : ($this->structuralDeclarations)($element);
    }
}
