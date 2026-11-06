<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\FallbackDiagnostic;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\SourceElementDescription;
use Closure;
use DOMElement;

/**
 * Explicit collaborator surface for {@see UnsupportedElementRecorder}.
 */
final class UnsupportedElementContext
{
    /**
     * @param Closure(array<string, mixed>, DOMElement): array<string, mixed> $generatedComponentBlock
     */
    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly Closure $generatedComponentBlock,
        public readonly SourceElementDescription $sourceElementDescription
    ) {
    }

    /**
     * @param array<string, mixed> $generated
     * @return array<string, mixed>
     */
    public function generatedComponentBlock(array $generated, DOMElement $element): array
    {
        return ($this->generatedComponentBlock)($generated, $element);
    }

    public function safeFallbackHtml(DOMElement $element): string
    {
        return SourceDom::safeFallbackHtml($element, $this->session->authorSelectorProjectionState()->tagMarkers());
    }

    /**
     * @param array<string, mixed> $fallback
     * @return array<string, mixed>
     */
    public function buildFallbackDiagnostic(array $fallback): array
    {
        return FallbackDiagnostic::build($fallback, $this->session->transformationProvenanceState()->fallback());
    }
}
