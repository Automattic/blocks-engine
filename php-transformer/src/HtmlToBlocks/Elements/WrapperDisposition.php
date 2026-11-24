<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

/**
 * Names what becomes of a source wrapper element once its structural role in
 * the block tree is decided:
 *
 *  - preserve:  the wrapper survives as its own block (e.g. `core/group`).
 *  - fold:      the wrapper's markup is folded into an ancestor/descendant
 *               layout-shell wrapper chain, or its children are emitted in
 *               its place, rather than it staying a block of its own.
 *  - coalesce:  the wrapper disappears entirely; a surviving child block
 *               takes its place and absorbs its class/style identity.
 *  - css-owned: the wrapper's layout is re-expressed as CSS-owned attributes
 *               on a surviving block instead of a retained element boundary.
 *  - drop:      the element and everything it holds is omitted: it is inert
 *               scaffolding, or an empty box that renders nothing.
 *
 * Every disposition carries the reason that produced it. This replaces a
 * bare `null` return from a wrapper-elimination predicate, which recorded a
 * decision but never why it was made — the specific authored signal (an id,
 * a role, an interactive attribute, an unmatched selector, ...) that kept or
 * dropped the wrapper.
 *
 * A refusal also names the {@see WrapperDecision} stage (geometry, identity
 * or restatement) whose guard produced it, so a kept wrapper says not only
 * which signal kept it but which question that signal answered.
 *
 * This value object is intentionally generic: {@see WrapperDecision} is its
 * owner (#1935, #7471), and the remaining wrapper call sites adopt it in
 * later byte-identical slices. `coalesce`, `preserve`, `fold` and `drop`
 * are produced so far; `css-owned` is named here so later slices do not
 * need to introduce a second, incompatible vocabulary.
 */
final class WrapperDisposition
{
    private const PRESERVE = 'preserve';
    private const FOLD = 'fold';
    private const COALESCE = 'coalesce';
    private const CSS_OWNED = 'css-owned';
    private const DROP = 'drop';

    private function __construct(
        public readonly string $kind,
        public readonly string $reason,
        public readonly ?string $stage = null
    ) {
    }

    public static function preserve(string $reason, ?string $stage = null): self
    {
        return new self(self::PRESERVE, $reason, $stage);
    }

    public static function fold(string $reason, ?string $stage = null): self
    {
        return new self(self::FOLD, $reason, $stage);
    }

    public static function coalesce(string $reason, ?string $stage = null): self
    {
        return new self(self::COALESCE, $reason, $stage);
    }

    public static function cssOwned(string $reason, ?string $stage = null): self
    {
        return new self(self::CSS_OWNED, $reason, $stage);
    }

    public static function drop(string $reason, ?string $stage = null): self
    {
        return new self(self::DROP, $reason, $stage);
    }

    public function isCoalesce(): bool
    {
        return self::COALESCE === $this->kind;
    }

    public function isPreserve(): bool
    {
        return self::PRESERVE === $this->kind;
    }

    public function isFold(): bool
    {
        return self::FOLD === $this->kind;
    }

    public function isDrop(): bool
    {
        return self::DROP === $this->kind;
    }
}
