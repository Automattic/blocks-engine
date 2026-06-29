# Reconstruct fidelity benchmark

A graded, regression-gated benchmark for the theme-reconstruct path
(`SectionSpec → native blocks`). It scores reconstruct output 0–100 against
expected block trees, ratchets against committed baselines, and attributes score
deltas to catch overfitting. It ports the *shape* of `humanmade/block-runner`'s
tuner — **block-runner is not a dependency**; all code here is independent.

Lives entirely under `scripts/tuner/` + `benchmarks/`, so nothing ships in the
published package (`files: ["dist"]`).

## Commands

```sh
pnpm bench            # score the suite → scoreboard; exit 1 on regression, 2 on harness error
pnpm bench:record     # also append a provenance-tagged run to results.jsonl (gitignored)
pnpm bench:derive     # (re)seed structure-only specs from current output (manual-only; preserves ideals)
pnpm bench -- --baseline-update   # accept the current run as the new baseline (deliberate act)
```

Flags: `--specs <dir>`, `--baselines <dir>`, `--results <path>`, `--threshold <n>`,
`--baseline-update`, `--record`.

## Developer workflow — when to run what

```
changed a renderer / native-block-builder?
        │
        ▼
   pnpm bench ───────────────► scores unchanged → you're done.
        │
        ├─ a fixture went DOWN (ratchet exits 1)?
        │     ├─ unintended  → it's a real regression. Fix it.
        │     └─ intended    → pnpm bench -- --baseline-update,
        │                       then COMMIT the changed baselines/ file
        │                       (the bump is a reviewed line in your PR).
        │
        └─ a fixture went UP / you added a new corpus case?
              ├─ new case     → pnpm bench:derive   (writes a derived spec; never clobbers ideals)
              └─ score up      → pnpm bench -- --baseline-update to ratchet the ceiling up.
```

Rules of thumb:
- **Never edit `baselines/` by hand.** Only `--baseline-update` writes it, and the
  diff must be reviewed in the PR — a baseline that drops is a downgrade and should
  be questioned.
- **`bench:derive` is manual-only.** Running it after a regression would bake the
  regression into "expected" and hide it. Derive only to seed a *new* fixture.
- **Tuning toward an `ideal`:** improve the renderer until the ideal's score rises,
  then `--baseline-update`. The `_note` in each ideal spec says what the gap is.
- **`results.jsonl` is local.** Attribution (class-move vs overfit) only has history
  within your own machine's runs; it's informational, never the gate.

## Running in CI

The ratchet runs as its own workflow, `.github/workflows/js-bench.yml`
(`JS Fidelity Ratchet`), in **parallel** with the typecheck/build/test job in
`js.yml` — same triggers (any PR/push touching `packages/blocks-engine/**`), so a
fidelity regression and a failing test surface together, not one behind the other.

It's a single `pnpm bench` step. The ratchet is deterministic and offline (Tier A
only — no model, no network, no secrets). `pnpm bench` exits `1` on regression, `2`
on a harness error (missing/corrupt spec or baseline), `0` when clean — gating a PR
the same way tests do. In CI there's no `results.jsonl`, so attribution reports "no
comparable run"; the **committed baselines do the gating**. CI never runs
`bench:record`, `bench:derive`, or `--baseline-update` — those mutate committed or
local state.

## How it scores

- **score** = `round((0.75·structure + 0.25·content) · (valid ? 1 : 0.5) · 100)`.
- **structure**: expected blocks found at the right nesting (in order).
- **content**: a `contains` substring present in the matched block (image-asset
  assertions are satisfied by any image source, not an exact filename).
- **coverage** (separate axis): fraction of the section's expected text that
  survives into output — catches silent content loss.
- **valid**: structural validity via `validateBlockMarkup` (no Gutenberg boot).

## Two tiers

- **Tier A — deterministic core.** `reconstructNativeAggregate` with no hooks.
  Threshold **0**: any drop below baseline trips a non-zero exit. This is the gate.
- **Tier B — hook path.** Scored only via cached propose/realize artifacts
  (`hook-captures/`), replayed deterministically. v1 ships a deterministic stub
  hook to prove the seam; a real DLA-hook capture is a fast follow. No live model
  in CI, ever.

## Expected trees (hybrid)

Each `specs/<producer>/<case>/expected.json` is `{ source, tree }`:

- `derived` — mechanically generated from current output. Scores ~100 by
  construction; the **ratchet**, not the score, catches regressions here.
- `ideal` — hand-authored toward the ideal structure. A `<100` score is an honest
  fidelity gap, not a regression (e.g. `structured-review-cards-dark-band` scores
  79 because reviews should render as `core/quote`, not loose paragraphs).

`bench:derive` never overwrites an `ideal` spec.

## Committed vs local

- Committed: `specs/`, `baselines/`, `hook-captures/`.
- Gitignored: `.cache/` (local propose cache), `results.jsonl` (attribution is a
  local tuning aid; the committed baselines carry the cross-session signal).

See `docs/superpowers/specs/2026-06-29-blocks-engine-benchmark-harness-design.md`
for the full design + plan-deep-review outcomes.
