<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

use Closure;


final class LogoPatternContext
{
    /**
     * @param Closure(DOMElement): array<string, string>|null $structuralDeclarations
     * @param Closure(DOMElement, array<int, array<string, mixed>>): void|null $captureSvgFallback
     */
    public function __construct(
        private readonly ?Closure $structuralDeclarations = null,
        private readonly ?Closure $captureSvgFallback = null
    ) {
    }

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
