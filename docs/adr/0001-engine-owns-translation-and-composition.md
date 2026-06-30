# blocks-engine owns HTML→blocks translation and composition behind a pluggable extension API

blocks-engine owns the generic work of translating HTML into WordPress blocks — the `@wordpress/blocks` rawHandler core, the block-markup canonicalizer, the deterministic converter catalog, **and** the precedence that composes converters in order. Consumer-specific choices are not baked in; they are supplied through three extension seams: a `ConversionContext`, an ordered list of consumer **Converters** (functions or declarative `selector→block` recipe tables), and an `htmlFallback` override (default `core/html`). The data-liberation-agent is the first consumer but won't be the only one, so the boundary is: *generic translation logic + composition + extension seams → engine; the specific things plugged into those seams → consumer.*

## Considered Options

- **Engine = low-level primitives only; each consumer composes.** Rejected: duplicates the generic catalog and the recipe-table mechanism in every consumer, leaving "the hard work" outside the shared engine.
- **Engine = generic conversion only; consumers own precedence/composition.** Rejected: a second consumer would have to bring its own recipe-table engine and precedence logic; the reusable extensible surface stays trapped in the first consumer.

## Consequences

- The first consumer's types (`AdapterBlocks` / `BlockRecipe` / `BlockRecipeContext`) become, or alias, the engine's `Converter` / recipe-rule / `ConversionContext` types; the consumer passes its platform recipes as **data**.
- "Behavior-preserving / byte-identical" constrains engine **output**, not interface — the type and precedence relocation is deliberate; golden fixtures pin the emitted markup.
- The `htmlFallback` seam must be threaded through the engine's **internal recursion**, not just applied at the outer boundary — otherwise nested fallbacks lose the consumer's chosen block.
- The consumer's fallback marker (DLA's `PIPELINE_ISLAND_OPENER`) and its install-time gate (`validateReplicaInputs`) stay in the consumer, supplied via `htmlFallback`.
