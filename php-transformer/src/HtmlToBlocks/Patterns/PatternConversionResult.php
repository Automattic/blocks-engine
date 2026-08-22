<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

/** A conversion performed while recognizing a pattern, including diagnostics. */
final class PatternConversionResult
{
    /**
     * @param list<array<string, mixed>> $blocks
     * @param list<array<string, mixed>> $fallbacks
     */
    public function __construct(private readonly array $blocks, private readonly array $fallbacks = array())
    {
    }

    /** @return list<array<string, mixed>> */
    public function blocks(): array { return $this->blocks; }

    /** @return array<string, mixed>|null */
    public function firstBlock(): ?array { return $this->blocks[0] ?? null; }

    /** @return list<array<string, mixed>> */
    public function fallbacks(): array { return $this->fallbacks; }
}
