<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Closure;
use DOMElement;

/**
 * Explicit collaborator surface for {@see SvgMaterializer}.
 *
 * Per-transform state is read from the compilation's typed session. Closures
 * remain only for transformer-owned operations.
 */
final class SvgMaterializationContext
{
    /**
     * @param Closure(DOMElement): string                                                          $sanitizeInlineSvgMarkup
     * @param Closure(DOMElement, string): array<string, mixed>                                     $svgArtworkBlock
     */
    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly Closure $sanitizeInlineSvgMarkup,
        private readonly Closure $svgArtworkBlock
    ) {
    }

    public function reusableComponentFingerprintFor(DOMElement $element): ?string
    {
        return $this->session->reusableComponentState()->fingerprintForPath((string) $element->getNodePath());
    }

    public function safeFallbackHtml(DOMElement $element): string
    {
        return SourceDom::safeFallbackHtml($element, $this->session->authorSelectorProjectionState()->tagMarkers());
    }

    public function sanitizeInlineSvgMarkup(DOMElement $element): string
    {
        return ($this->sanitizeInlineSvgMarkup)($element);
    }

    /** @return array<string, mixed> */
    public function svgArtworkBlock(DOMElement $element, string $svg): array
    {
        return ($this->svgArtworkBlock)($element, $svg);
    }

}
