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

    /** @return array<string, string> */
    public static function emittedCoreBlockContracts(): array
    {
        return HtmlCompilation::emittedCoreBlockContracts();
    }

    /**
     * Convert HTML to blocks. Input that already carries block delimiters keeps
     * every parsed block as written; only HTML outside blocks, or loose inside a
     * container block, is converted and spliced back at its position.
     *
     * @param array<string, mixed> $options
     */
    public function transform(string $html, array $options = array()): TransformerResult
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
