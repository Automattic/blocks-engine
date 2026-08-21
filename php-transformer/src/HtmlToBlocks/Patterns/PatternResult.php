<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

/**
 * The output of a recursive pattern conversion or a matched registry pattern.
 * Findings stay with the candidate until the registry accepts its block.
 */
final class PatternResult
{
    /**
     * @param array<string, mixed>|null $block
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $fallbacks
     * @param list<string> $consumedSourceNodes
     * @param array<string, mixed> $provenance
     */
    public function __construct(
        private readonly ?array $block = null,
        private readonly array $blocks = array(),
        private readonly array $fallbacks = array(),
        private readonly array $consumedSourceNodes = array(),
        private readonly array $provenance = array()
    ) {
    }

    /** @return array<string, mixed>|null */
    public function block(): ?array
    {
        return $this->block;
    }

    /** @return array<int, array<string, mixed>> */
    public function blocks(): array
    {
        return $this->blocks;
    }

    /** @return array<int, array<string, mixed>> */
    public function fallbacks(): array
    {
        return $this->fallbacks;
    }

    /** @return list<string> */
    public function consumedSourceNodes(): array
    {
        return $this->consumedSourceNodes;
    }

    /** @return array<string, mixed> */
    public function provenance(): array
    {
        return $this->provenance;
    }

    /**
     * Keep first-seen finding order while avoiding duplicate findings when a
     * pattern combines multiple recursive conversions.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     */
    public static function mergeFallbacksInto(array &$fallbacks, array $additional): void
    {
        $seen = array();
        foreach ( $fallbacks as $fallback ) {
            $seen[serialize($fallback)] = true;
        }

        foreach ( $additional as $fallback ) {
            $key = serialize($fallback);
            if ( isset($seen[$key]) ) {
                continue;
            }
            $fallbacks[] = $fallback;
            $seen[$key] = true;
        }
    }
}
