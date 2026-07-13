# Solved Fixture Corpus

This directory contains **permanent regression fixtures** for the Static Site Importer (SSI) fixture matrix.

## Promotion convention

A fixture moves from `fixtures/websites/` to `fixtures/solved/` only when:

1. An SSI fixture-matrix run grades it `solved_candidate`.
2. A human reviews the evidence and agrees the fixture is genuinely solved.
3. The move is performed as a deliberate `git mv` (do not copy + delete).

See the SSI fixture-matrix docs for the exact `solved_candidate` definition:

- [docs/fixture-matrix.md](../../../../static-site-importer/blob/main/docs/fixture-matrix.md)
- [docs/gutenberg-incompatibility-registry.md](../../../../static-site-importer/blob/main/docs/gutenberg-incompatibility-registry.md)

## Lifecycle

```
fixtures/candidates/   (untracked raw generation output)
        |
        v
fixtures/websites/     (active corpus under evaluation)
        |
        v
fixtures/solved/       (permanent regression fixtures)
```

- `fixtures/candidates/` is listed in `.gitignore`; raw generation output never commits directly.
- `fixtures/websites/` is the active corpus. The SSI fixture matrix runs against this corpus plus `fixtures/solved/`.
- `fixtures/solved/` is the regression corpus. Once a fixture is promoted here it must stay solved.

## Regression contract

A solved fixture that regresses in the fixture matrix is a **hard failure**. The SSI registry surfaces this as `solved_regression`, distinct from ordinary blockers, and the matrix run fails until the regression is fixed or the fixture is demoted back to `fixtures/websites/` after review.

Do not add fixtures here manually. Use the SSI promotion tool (`tools/promote-solved-fixture.mjs`) or an equivalent reviewed `git mv` workflow.
