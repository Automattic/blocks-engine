<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\FallbackDiagnostic;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Closure;
use DOMElement;

/** Explicit transformer collaborator surface for form fallback findings. */
final class FormFallbackFindingContext
{
    /**
     * @param Closure(DOMElement): array{html: string, bytes: int, truncated: bool}                $boundedFallbackHtml
     * @param Closure(DOMElement): array<int, string>                                               $runtimeDomSelectors
     * @param Closure(DOMElement): array<string, mixed>                                             $sourceContext
     * @param Closure(DOMElement): array<string, mixed>                                             $classifyFallbackSubtree
     * @param Closure(array<string, mixed>, string, array<int, string>): array<string, mixed>       $blockBinding
     */
    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly Closure $boundedFallbackHtml,
        private readonly Closure $runtimeDomSelectors,
        private readonly Closure $sourceContext,
        private readonly Closure $classifyFallbackSubtree,
        private readonly Closure $blockBinding
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function stylesheetAssets(): array
    {
        return $this->session->authorStyleAnalysis()->stylesheetAssets();
    }

    public function formLayoutCss(): string
    {
        return $this->session->sourceStyleResolutionState()->formLayoutCss();
    }

    /** @return array{html: string, bytes: int, truncated: bool} */
    public function boundedFallbackHtml(DOMElement $element): array
    {
        return ($this->boundedFallbackHtml)($element);
    }

    /** @return array<int, string> */
    public function runtimeDomSelectors(DOMElement $element): array
    {
        return ($this->runtimeDomSelectors)($element);
    }

    /** @return array<string, mixed> */
    public function sourceContext(DOMElement $element): array
    {
        return ($this->sourceContext)($element);
    }

    /** @return array<string, mixed> */
    public function classifyFallbackSubtree(DOMElement $element): array
    {
        return ($this->classifyFallbackSubtree)($element);
    }

    /**
     * @param array<string, mixed> $block
     * @param array<int, string> $supersededRuntimeSelectors
     * @return array<string, mixed>
     */
    public function blockBinding(array $block, string $role, array $supersededRuntimeSelectors): array
    {
        return ($this->blockBinding)($block, $role, $supersededRuntimeSelectors);
    }

    /** @param array<string, mixed> $finding @return array<string, mixed> */
    public function buildFallbackDiagnostic(array $finding): array
    {
        return FallbackDiagnostic::build($finding, $this->session->transformationProvenanceState()->fallback());
    }
}
