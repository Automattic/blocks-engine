# Compose rule-unit reuse

Slice of [#2897](https://github.com/Automattic/blocks-engine/issues/2897#issuecomment-6594636437).
Run `php tools/benchmarks/compose-rule-units.php` from `php-transformer`.
The unit suite also runs its deterministic assertions.

The benchmark invokes `ArtifactCompiler::applyAuthorStylesheetProjections` with
60 and 120 routes sharing 102,426 bytes of CSS, a small unique rule per route,
and an authoritative base rule. It measures the projection portion of compose,
not the full import. Each mode runs in a fresh PHP process. A process-local copy
of the transformer counts collection requests and actual rule-unit scans;
"before" disables only the new memoization. No instrumentation ships in the
production class. Serialized output hashes must match, and the shared source
must be scanned exactly once after caching (at least once per route before).

Example native PHP run in this workspace:

| Routes | Projection compose before → after | Collection calls before → after | Total scans before → after | Shared scans before → after |
| --- | --- | --- | --- | --- |
| 60 | 1.315 s → 0.879 s | 304 → 304 | 304 → 124 | 61 → 1 |
| 120 | 3.692 s → 2.365 s | 844 → 844 | 844 → 243 | 121 → 1 |

Timings are illustrative, not gates. Unique concatenated route CSS still needs
its own first parse, and selector/cascade work and stylesheet validation are not
cached by this change. The cache is source-keyed and scoped to one transformer
instance, already shared across the compose projection pass; it trades retained
rule-unit arrays for eliminating repeated scans. There is no cross-compose or
static cache. Nested contexts are stored relative to their source and prefixed
on reuse without mutating the cached arrays.

AI assistance: implemented, benchmarked, and tested by the `cook-heavy` model
through Pi's coding-agent tools.
