<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Closure;
use DOMElement;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;

final class MarkupPatternContext
{
    public function __construct(
        private readonly HtmlTransformerSession $session,
        /** @var Closure(string, DOMElement): string|null */
        private readonly ?Closure $svgDefinitions = null
    ) {
    }

    public function safeFallbackHtml(DOMElement $element): string { return SourceDom::safeFallbackHtml($element, $this->session->authorSelectorProjectionState()->tagMarkers()); }

    /**
     * Retained markup for a child of emitted artwork. An `<svg>` carries the
     * definitions it references, such as a page-level sprite `<symbol>`.
     */
    public function safeArtworkHtml(DOMElement $element): string
    {
        $html = $this->safeFallbackHtml($element);

        return 'svg' === strtolower($element->tagName) && null !== $this->svgDefinitions ? ($this->svgDefinitions)($html, $element) : $html;
    }

}
