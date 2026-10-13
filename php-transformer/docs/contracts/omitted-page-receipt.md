# Omitted pages (`page_compile_omitted`)

A page that cannot be represented is a limit of that page, not of the site. When one
**non-entrypoint** HTML page fails to compile with a representability (non-integrity)
error, the site compile continues without it and the WordPress site plan names the page
as omitted. This is the engine side of the "degrade, don't throw" ledger
(Automattic/data-liberation-agent#761, Automattic/blocks-engine#2713).

The change is additive. No schema identifier changes: a site with no omitted page
produces byte-identical plans and receipts.

## What counts as representability

The per-page conversion of a non-entrypoint HTML document (`compileHtmlDocumentBlocks`,
reached by `ArtifactCompiler::compile()`, `compilePage()`, `compilePreparedPage()` and
`compilePreparedPages()`) is guarded. A throw there is **omitted** when it is an
`InvalidArgumentException`, which is the engine's exception for "this input cannot be
represented". Everything else keeps failing the compile:

| Failure | Result |
| ------- | ------ |
| `InvalidArgumentException` from converting a non-entrypoint page | page omitted, finding recorded |
| Any failure converting the **entrypoint** page | compile fails |
| `DocumentIdentityException`, `ValidationException` (identity, plan validation) | compile fails |
| `LogicException`, `RuntimeException`, `Error` (programmer errors, engine/environment faults) | compile fails |
| Artifact, shared-plan, payload-digest and receipt integrity checks (`assertSharedPlan`, `assertPagePlan`, payload reads, `compose()` coverage checks) | compile fails; they run outside the guarded call |
| Shared work (normalization, includes, runtime declarations, shared shells, plan build) | unchanged; not attributed to one page |

The guard is deliberately narrow: it wraps only the conversion of one page's HTML into
blocks, after artifact normalization, path containment, ownership and runtime-declaration
checks have already passed for the whole artifact. Everything that conversion produces is
page-local, so dropping the page drops all of it; nothing from an omitted page reaches the
plan. It is not a `Throwable` catch-all.

Shared shells are classified across every page before one is dropped. A dropped page is
removed from each shell's `source_paths` afterwards, and its page-owned files (the
document and the inline styles and scripts expanded out of it) leave the artifact.

## The omitted set

The omitted set lives in **one place in the plan**: `wordpress_site_plan.diagnostics`
entries whose `code` is `page_compile_omitted`. The same diagnostics are on the result
envelope's `diagnostics`. One entry per omitted page, ordered by `source_path`:

```json
{
  "code": "page_compile_omitted",
  "severity": "warning",
  "unit": "page",
  "source_path": "pricing.html",
  "reason_code": "page_compile_representability_limit",
  "message": "Form layout graph exceeds its node limit.",
  "source": "Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler"
}
```

| Field | Meaning |
| ----- | ------- |
| `source_path` | Artifact path of the omitted page. It is absent from `pages`, `routes` and `source.source_documents`. |
| `reason_code` | `page_compile_representability_limit` today. Stable, machine-readable. |
| `message` | The underlying failure message, clipped to 256 bytes. |
| `unit` | Always `page`. |

A consumer recognises the omitted set by filtering the plan diagnostics on
`code === 'page_compile_omitted'`. An omission is never an error: the result status is
`success_with_warnings` (plan `quality.status` too) when nothing else failed, and
`wordpress_site_plan_diagnostics` (the plan-failure channel) stays empty.

### Links to an omitted page

A dropped page owns no route, so a link to it is an ordinary unresolved local link. It is
resolved to the recorded source URL (or neutralized to `#` without one) and reported as
`wordpress_site_plan_unresolved_navigation_link` on each linking page, with `value` set to
the link. The plan does not fail.

## Staged compilation

`compilePreparedPage()` / `compilePreparedPages()` drop the page inside its own receipt
(`compiled-page-receipt/v3`) so the receipt count still equals the frozen page count.
A consumer that fans out per-page workers receives one receipt per page, as before.

An omitting receipt is an ordinary receipt with:

- `compiled_documents` and `owned_document_paths` without the omitted document
  (so the existing coverage checks hold), and `artifact.files` without the files that page
  owns;
- `terminal_reduction.omitted_pages`, a list of `{source_path, reason_code, message}`.
  The key exists only when a page was omitted, and is covered by the receipt `digest`.

`compose()` collects `omitted_pages` from every receipt and emits the same
`page_compile_omitted` diagnostics whole compilation does. It rejects
(`InvalidArgumentException`) a malformed row, an omitted entrypoint, a page omitted by more
than one receipt, and an omission of a path that the shared plan does not declare or that a
receipt still compiles or carries. Whole and staged compilation of the same artifact produce
the same plan, including when each page is compiled by a separate worker and receipts are
serialized between processes.

## Consuming it (Static Site Importer)

Static Site Importer's direct-artifact path (`class-static-site-importer-direct-artifact-import.php`)
needs no protocol change to accept an omitting receipt:

- Workers call `compilePreparedPages($shared, [$plan])` and still get exactly one receipt
  for the requested page id, so the receipt-set checks hold.
- `validate_receipt()` requires the receipt schema, page/shared/reduction digests, array
  `compiled_documents` and `owned_document_paths`, and the five `terminal_reduction` keys.
  An omitting receipt has all of them (the two arrays may be empty).
- `complete_receipts()` still finds one receipt per frozen page id, so composition runs.
- `compose()` returns `success_with_warnings` with a plan, so the
  `'failed' === status && empty(plan)` branch is not taken, and the omitted set survives
  `toCompactWordPressSitePlanView()` in `wordpress_site_plan.diagnostics`.

The omitted set is read in one place, after composition:

```php
$omitted_pages = array_values( array_filter(
    $composed_state['result']['wordpress_site_plan']['diagnostics'] ?? array(),
    static fn ( $d ) => is_array( $d ) && 'page_compile_omitted' === ( $d['code'] ?? null )
) ); // each row: source_path, reason_code, message, unit 'page', severity 'warning'
```

Next to the existing `omitted_artifact_file_count()` gate (the same diagnostics array),
the importer should:

1. Register `page_compile_omitted` in its loss-class vocabulary as a page-unit loss with
   `owning_layer` blocks-engine. Until it does, the row reaches its receipt as an
   unrecognized loss class: visible, but unclassified.
2. Decide acceptance there, once. `page_compile_omitted` is not one of
   `COMPILER_FILE_DROP_TYPES`, so the "never import a partial site" refusal does not count
   it: an importer that upgrades to this Blocks Engine version and changes nothing imports
   the remaining pages instead of failing on the worker's
   `static_site_importer_direct_artifact_worker_failed`. To keep refusing, gate on the
   omitted set explicitly; to accept, carry it into `Receipt_Projection::compose()`.
3. Persist only the pages the plan contains. Links to the omitted page are already
   rewritten in the plan, and the plan passes `WordPressSitePlan::assertValid()`.

`receipt.terminal_reduction.omitted_pages` is available earlier, per worker, for progress
reporting. It is not a second source of truth: the plan diagnostics are authoritative.

## Test seam

`ArtifactCompiler` accepts an optional `beforeDocumentTransform(string $sourcePath,
string $sourceScope): void` closure, called just before a page is converted. A throw there
is handled like a converter throw. It exists so `tests/contract/omitted-page-receipt.php`
can prove the classification without depending on one specific throw site, which the
per-site conversions in Automattic/blocks-engine#2713 are expected to remove. Degrading a
throw site in place remains the preferred fix; this contract is the backstop for those
that still throw.
