<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

/**
 * The complete, ordered-recognizer outcome. Effects are data, never hidden in
 * a recognizer callback, so the dispatcher can commit them with the block.
 */
final class PatternRecognitionResult
{
    /**
     * @param array<string, mixed> $block
     * @param list<array<string, mixed>> $fallbacks
     * @param list<array<string, mixed>> $provenance
     * @param list<string> $declaredSideEffects
     */
    public function __construct(
        private readonly array $block,
        private readonly array $fallbacks = array(),
        private readonly array $provenance = array(),
        private readonly array $declaredSideEffects = array()
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

    /** @return list<array<string, mixed>> */
    public function provenance(): array
    {
        return $this->provenance;
    }

    /** @return list<string> */
    public function declaredSideEffects(): array
    {
        return $this->declaredSideEffects;
    }
}
