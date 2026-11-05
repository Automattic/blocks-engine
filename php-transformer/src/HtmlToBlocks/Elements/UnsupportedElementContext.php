<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\FallbackDiagnostic;
use Closure;
use DOMElement;

/**
 * Explicit collaborator surface for {@see UnsupportedElementRecorder}.
 */
final class UnsupportedElementContext
{
    /**
     * @param Closure(array<string, mixed>, DOMElement): array<string, mixed> $generatedComponentBlock
     * @param Closure(DOMElement): array<string, mixed>   $sourceContext
     */
    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly Closure $generatedComponentBlock,
        private readonly Closure $sourceContext
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

    /**
     * @return array<string, mixed>
     */
    public function sourceContext(DOMElement $element): array
    {
        return ($this->sourceContext)($element);
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
