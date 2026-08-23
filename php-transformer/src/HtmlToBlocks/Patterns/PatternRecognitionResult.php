<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

/** The block and fallback diagnostics committed when an ordered recognizer wins. */
final class PatternRecognitionResult
{
    /**
     * @param array<string, mixed> $block
     * @param list<array<string, mixed>> $fallbacks
     */
    public function __construct(
        private readonly array $block,
        private readonly array $fallbacks = array()
    ) {
    }

    /** @return array<string, mixed> */
    public function block(): array
    {
        return $this->block;
    }

    /** @return list<array<string, mixed>> */
    public function fallbacks(): array
    {
        return $this->fallbacks;
    }
}
