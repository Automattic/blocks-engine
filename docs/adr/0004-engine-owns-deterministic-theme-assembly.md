# Engine identity expands to deterministic theme assembly

The engine's scope expands from **HTML→blocks translation only** to **translation +
deterministic theme assembly**: a static site directory → a block theme on disk
(`theme.json`, templates, parts, patterns, `style.css`, carried/rewritten assets),
exposed as a `siteToTheme` convenience command over composable, public **stages**.

This supersedes the framing in ADR 0001 / `CONTEXT.md` that treated a
pipeline/importer as exclusively the **consumer's** job and the engine as a pure
translator. The engine still knows nothing about any one consumer; "theme assembly"
is generic, consumer-agnostic work — but it IS more than translation.

Non-deterministic quality (visual-parity polish, decorative-asset triage, repair) is
explicitly NOT engine code. It enters through optional async **hooks**
(`onFoundation`/`onSection`/`onAssets`/`onRefine`); absent hook = deterministic
identity. The engine owns the deterministic skeleton; consumers inject judgment.

## Considered Options

- **Keep the engine translation-only; theme assembly stays in the consumer (DLA).**
  Rejected: the deterministic theme-assembly logic is generic (not DLA-specific) and
  other implementers want it; leaving it in DLA blocks reuse, which is the whole point.
- **Make theme assembly a separate sibling package.** Rejected: it shares the
  translation core, the worker pool, and the React-isolation machinery; a package
  split re-introduces the cross-package version coupling ADR 0003 avoided.
- **Engine does its own browser capture to reach DLA-grade fidelity.** Rejected:
  violates the browser-free posture and ADR 0003. Instead, `SectionSpec` is a shared
  input contract — the engine ships a browser-free cheerio extractor for a best-effort
  static path, and consumers inject richer captured specs for full fidelity.

## Consequences

- `CONTEXT.md` gains: Theme assembly / Assembler, Stage, Hook, SectionSpec, ThemeModel.
  "pipeline" stays reserved for the consumer's end-to-end run; the engine has an
  *assembler*.
- The browser-free **static-dir** path is a NEW, lower-fidelity capability with its own
  golden fixtures. It is NOT held to DLA-output parity.
- The migration definition-of-done for lifting DLA's logic is: **engine + DLA-injected
  specs/aggregates/hooks == DLA-today, byte-identical** (proves the lift preserved
  behavior). Static-path output is governed by golden fixtures, not DLA parity.
- DLA eventually deletes its deterministic copies and imports the engine, wiring its AI
  skills into the hooks and feeding its captured `SectionSpec`s via the `sections` input.
  The Studio/WP install stays in DLA.
