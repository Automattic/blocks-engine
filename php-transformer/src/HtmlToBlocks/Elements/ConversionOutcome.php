<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

/**
 * Result of an element converter.
 *
 * The transformer's dispatch chain uses `null` for two different outcomes:
 * "this element intentionally produces no block" (`br`, suppressed controls)
 * and "this branch did not apply, keep dispatching". Collapsing both into a
 * bare `?array` return is what forces converters to stay inline in the chain.
 * This type keeps them distinct so a converter can be consulted and then
 * skipped without changing behavior.
 */
final class ConversionOutcome
{
    /**
     * @param array<string, mixed>|null $block
     */
    private function __construct(
        public readonly bool $handled,
        public readonly ?array $block
    ) {
    }

    /**
     * The converter owned this element. `$block` may still be null when the
     * element intentionally converts to nothing.
     *
     * @param array<string, mixed>|null $block
     */
    public static function handled(?array $block): self
    {
        return new self(true, $block);
    }

    /**
     * The converter did not own this element; dispatch continues.
     */
    public static function unhandled(): self
    {
        return new self(false, null);
    }
}
