<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

final class MarkupPatternContext
{
    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly Runtime $runtime
    ) {
    }

    public function safeFallbackHtml(DOMElement $element): string { return SourceDom::safeFallbackHtml($element, $this->session->authorSelectorProjectionState()->tagMarkers()); }
    public function escapeHtml(string $text): string { return $this->runtime->escapeHtml($text); }
}
