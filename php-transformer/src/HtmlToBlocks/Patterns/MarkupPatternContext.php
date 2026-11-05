<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;

final class MarkupPatternContext
{
    public function __construct(
        private readonly HtmlTransformerSession $session
    ) {
    }

    public function safeFallbackHtml(DOMElement $element): string { return SourceDom::safeFallbackHtml($element, $this->session->authorSelectorProjectionState()->tagMarkers()); }

}
