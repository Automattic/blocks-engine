<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

/** Stateless public facade for run-scoped HTML compilations. */
final class HtmlTransformer
{
    public const EMPTY_VISUAL_GROUP_CLASS = HtmlCompilation::EMPTY_VISUAL_GROUP_CLASS;
    public const EMPTY_RUNTIME_TARGET_CLASS = HtmlCompilation::EMPTY_RUNTIME_TARGET_CLASS;

    public function __construct(
        private readonly Runtime $runtime = new Runtime(),
        private readonly HtmlTransformerAnalysisCache $analysisCache = new HtmlTransformerAnalysisCache()
    ) {}

    /**
     * Convert HTML to blocks. Input that already carries block delimiters keeps
     * every parsed block as written; only HTML outside blocks, or loose inside a
     * container block, is converted and spliced back at its position.
     *
     * @param array<string, mixed> $options
     */
    public function transform(string $html, array $options = array()): TransformerResult
    {
        // Each document builds an HtmlCompilation whose services hold the
        // compilation, so its whole graph is cyclic and only the cycle
        // collector frees it. Collector runs inside a document re-scan that
        // live graph without freeing it, so collect once per document instead.
        if ( ! gc_enabled() ) {
            return $this->transformDocument($html, $options);
        }
        gc_disable();
        try {
            return $this->transformDocument($html, $options);
        } finally {
            gc_enable();
            gc_collect_cycles();
        }
    }

    /** @param array<string, mixed> $options */
    private function transformDocument(string $html, array $options): TransformerResult
    {
        if ( BlockMarkupInputCompilation::containsBlockDelimiters($html) ) {
            return (new BlockMarkupInputCompilation(
                $this->runtime,
                fn (string $fragment, array $fragmentOptions): TransformerResult => $this->transformHtml($fragment, $fragmentOptions)
            ))->transform($html, $options);
        }

        return $this->transformHtml($html, $options);
    }

    /** @param array<string, mixed> $options */
    private function transformHtml(string $html, array $options): TransformerResult
    {
        return (new HtmlCompilation($this->runtime, $this->analysisCache))->transform($html, $options);
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    public function reduceCoreHtmlFallbackBlocks(array $blocks): array
    {
        return (new HtmlCompilation($this->runtime, $this->analysisCache))->reduceCoreHtmlFallbackBlocks($blocks);
    }
}
