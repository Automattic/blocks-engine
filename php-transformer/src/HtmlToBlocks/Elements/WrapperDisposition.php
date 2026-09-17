<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

/**
 * Names what becomes of a source wrapper element once its structural role in
 * the block tree is decided:
 *
 *  - preserve:  the wrapper survives as its own block (e.g. `core/group`).
 *  - fold:      the wrapper's markup is folded into an ancestor/descendant
 *               layout-shell wrapper chain rather than staying a block of
 *               its own.
 *  - coalesce:  the wrapper disappears entirely; a surviving child block
 *               takes its place and absorbs its class/style identity.
 *  - css-owned: the wrapper's layout is re-expressed as CSS-owned attributes
 *               on a surviving block instead of a retained element boundary.
 *
 * Every disposition carries the reason that produced it. This replaces a
 * bare `null` return from a wrapper-elimination predicate, which recorded a
 * decision but never why it was made — the specific authored signal (an id,
 * a role, an interactive attribute, an unmatched selector, ...) that kept or
 * dropped the wrapper.
 *
 * This value object is intentionally generic: it is the first of several
 * call sites across wrapper coalescing expected to adopt it (see
 * php-transformer issue #1935). Only `coalesce`/`preserve` are produced by
 * this slice; `fold` and `css-owned` are named here so later slices do not
 * need to introduce a second, incompatible vocabulary.
 */
final class WrapperDisposition
{
    private const PRESERVE = 'preserve';
    private const FOLD = 'fold';
    private const COALESCE = 'coalesce';
    private const CSS_OWNED = 'css-owned';

    private function __construct(
        public readonly string $kind,
        public readonly string $reason
    ) {
    }

    public static function preserve(string $reason): self
    {
        return new self(self::PRESERVE, $reason);
    }

    public static function fold(string $reason): self
    {
        return new self(self::FOLD, $reason);
    }

    public static function coalesce(string $reason): self
    {
        return new self(self::COALESCE, $reason);
    }

    public static function cssOwned(string $reason): self
    {
        return new self(self::CSS_OWNED, $reason);
    }

    public function isPreserve(): bool
    {
        return self::PRESERVE === $this->kind;
    }

    public function isFold(): bool
    {
        return self::FOLD === $this->kind;
    }

    public function isCoalesce(): bool
    {
        return self::COALESCE === $this->kind;
    }

    public function isCssOwned(): bool
    {
        return self::CSS_OWNED === $this->kind;
    }
}
