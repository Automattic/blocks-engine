# Editability policy (`blocks-engine/php-transformer/editability-policy/v1`)

`Contract\EditabilityPolicy` evaluates the measured `EditabilityReport` for every
compiled document against `EditabilityPolicy::THRESHOLDS`. In a site compile it is a
per-page quality signal: a page over a threshold is kept and reported; editability
breaches never reject a site import.

## Shape

| Field | Meaning |
| ----- | ------- |
| `status` | `passed` or `failed`. `failed` only when `failures` is non-empty. |
| `failures` | Blocking breaches. Each one emits an `editability_policy_failed` error diagnostic and rejects the import. |
| `findings` | Per-page breaches that do not block. The page stays in the import; each one emits an `editability_policy_degraded` warning diagnostic and the site plan quality becomes `success_with_warnings`. |
| `failure_totals`, `finding_totals` | `observed`, `reported`, `omitted`, `truncated`. Each list is bounded to `MAX_REPORTED_FAILURES` entries. |

Every failure and finding carries `metric`, `actual`, `maximum`, `message` and
`source_path`. The policy is exposed in `source_reports.editability_policy` and in the
WordPress site plan receipt at `quality.editability_policy`, so the metrics stay
measurable after an import succeeds.

## Site-import behavior

Every breach is a finding on the offending page, including
`wrapper_to_content_ratio` on the **entrypoint** document:

- `empty_wrapper_count`: empty wrappers are List View noise; the page content is intact.
- `structural_rich_text_attribute_count`: structural markup inside RichText still renders
  and stays editable as text.
- `wrapper_to_content_ratio` on a non-entrypoint page: one noisy page does not cost the site.
- `max_nesting_depth` is measured with its threshold but never reported as a breach.

## Strict evaluation

`evaluate($report)` without an entrypoint path remains strict and treats every breach as a
blocking failure for converter self-consistency checks. Site compilation supplies the
entrypoint path, so all breaches are non-blocking findings.
Converter self-consistency gates (for example
`tests/contract/html-transformer-editability-policy.php`) use this to keep converters from
emitting blocks over any threshold, even though a site compile would only report them.
