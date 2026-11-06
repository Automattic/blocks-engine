<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\FallbackDiagnostic;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\SourceElementDescription;
use Closure;
use DOMElement;

/** Explicit transformer collaborator surface for form fallback findings. */
final class FormFallbackFindingContext
{
    /**
     * @param Closure(DOMElement): array<int, string>                                               $runtimeDomSelectors
     * @param Closure(array<string, mixed>, string, array<int, string>, ?DOMElement): array<string, mixed> $blockBinding
     * @param (Closure(DOMElement, string): string)|null                                            $resolvePresentationValue
     * @param (Closure(DOMElement): string)|null                                                    $sanitizeInlineSvgMarkup
     */
    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly Closure $runtimeDomSelectors,
        public readonly SourceElementDescription $sourceElementDescription,
        private readonly Closure $blockBinding,
        private readonly ?Closure $resolvePresentationValue = null,
        private readonly ?Closure $sanitizeInlineSvgMarkup = null
    ) {
    }

    /** @return array{html: string, bytes: int, truncated: bool} */
    public function boundedFallbackHtml(DOMElement $element): array
    {
        return SourceDom::boundedFallbackHtml(SourceDom::safeFallbackHtml($element, $this->session->authorSelectorProjectionState()->tagMarkers()));
    }

    /** @return array<int, string> */
    public function runtimeDomSelectors(DOMElement $element): array
    {
        return ($this->runtimeDomSelectors)($element);
    }

    /**
     * @param array<string, mixed> $block
     * @param array<int, string> $supersededRuntimeSelectors
     * @return array<string, mixed>
     */
    public function blockBinding(array $block, string $role, array $supersededRuntimeSelectors, ?DOMElement $anchorElement = null): array
    {
        return ($this->blockBinding)($block, $role, $supersededRuntimeSelectors, $anchorElement);
    }

    public function resolvePresentationValue(DOMElement $element, string $value): string
    {
        return null !== $this->resolvePresentationValue ? ($this->resolvePresentationValue)($element, $value) : $value;
    }

    public function sanitizeInlineSvgMarkup(DOMElement $element): string
    {
        return null !== $this->sanitizeInlineSvgMarkup ? ($this->sanitizeInlineSvgMarkup)($element) : '';
    }

    /** @param array<string, mixed> $finding @return array<string, mixed> */
    public function buildFallbackDiagnostic(array $finding): array
    {
        return FallbackDiagnostic::build($finding, $this->session->transformationProvenanceState()->fallback());
    }
}
