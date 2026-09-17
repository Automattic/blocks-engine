<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

/**
 * Everything a source stylesheet declares for one property on one element.
 *
 * A property an author states per viewport is a *set* of values, not one value.
 * Carriers used to resolve that set at a single reference viewport and return a
 * scalar, and because each carrier did its own resolution the same flattening
 * defect kept reappearing in unrelated features — a responsive `font-size`
 * frozen at the desktop breakpoint, a `md:hidden` control frozen visible.
 *
 * This is that set, kept whole: an unconditional {@see base()} value plus the
 * {@see conditional()} entries the author scoped to a media or feature query,
 * each carrying the cascade layer it was declared in. Dropping a breakpoint
 * stops being something a carrier can do by accident, because a carrier that
 * wants one value has to say which one it means.
 *
 * Cascade order is the caller's input order: entries arrive in the order the
 * resolver produced them, so the last matching entry is the winning one.
 */
final class DeclaredPresentation
{
    /**
     * `conditions` is every at-rule the declaration sits inside, `queries` only
     * those an author could restate — a cascade `@layer` scopes a declaration
     * without conditioning it on the viewport, so it belongs to `layer` and is
     * not something a carrier can re-emit as a media rule.
     *
     * @param list<array{value: string, conditions: list<string>, queries: list<string>, layer: int|null, applies: bool}> $entries
     */
    private function __construct(private readonly array $entries)
    {
    }

    /**
     * @param list<array{value: string, conditions: list<string>, queries: list<string>, layer: int|null, applies: bool}> $entries
     */
    public static function fromEntries(array $entries): self
    {
        return new self(array_values($entries));
    }

    public static function none(): self
    {
        return new self(array());
    }

    /** Does the source declare this property for the element at all? */
    public function isEmpty(): bool
    {
        return array() === $this->entries;
    }

    /**
     * Does the author state this property under a viewport or feature query?
     *
     * This is the question a carrier has to ask before it flattens: when the
     * answer is yes, one value cannot stand in for the declaration.
     */
    public function isConditional(): bool
    {
        foreach ( $this->entries as $entry ) {
            if ( array() !== $entry['conditions'] ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The unconditional declared value, or '' when the author only states this
     * property inside a condition.
     */
    public function base(): string
    {
        $value = '';
        foreach ( $this->entries as $entry ) {
            if ( array() === $entry['conditions'] ) {
                $value = $entry['value'];
            }
        }

        return $value;
    }

    /**
     * Entries the author scoped to a viewport or feature query, keyed by the
     * query chain so a caller can emit one rule per distinct query.
     *
     * @return array<string, string>
     */
    public function conditional(): array
    {
        $rules = array();
        foreach ( $this->entries as $entry ) {
            if ( array() === $entry['queries'] ) {
                continue;
            }
            $rules[ implode('{', $entry['queries']) ] = $entry['value'];
        }

        return $rules;
    }

    /**
     * The subset the author stated inside a condition.
     *
     * Combined with {@see resolvedValue()} this answers "does a conditioned
     * declaration win here", which is a different question from "does something
     * win here, and is anything conditioned" — conflating the two bakes an
     * unconditional value whenever an unrelated breakpoint exists.
     */
    public function conditionalOnly(): self
    {
        return new self(array_values(array_filter(
            $this->entries,
            static fn (array $entry): bool => array() !== $entry['conditions']
        )));
    }

    /**
     * The value that wins at the reference viewport, conditions included.
     *
     * Callers that genuinely need a scalar — an inline style attribute, a block
     * attribute — go through here, so the flattening is explicit and happens in
     * one place instead of once per carrier.
     */
    public function resolvedValue(): string
    {
        $value = '';
        foreach ( $this->entries as $entry ) {
            if ( $entry['applies'] ) {
                $value = $entry['value'];
            }
        }

        return $value;
    }

    /** The subset declared inside a cascade layer. */
    public function layered(): self
    {
        return new self(array_values(array_filter(
            $this->entries,
            static fn (array $entry): bool => null !== $entry['layer']
        )));
    }

    /** The subset declared outside every cascade layer. */
    public function unlayered(): self
    {
        return new self(array_values(array_filter(
            $this->entries,
            static fn (array $entry): bool => null === $entry['layer']
        )));
    }
}
