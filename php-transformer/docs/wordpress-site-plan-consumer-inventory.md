# WordPress Site Plan Consumer Inventory

Issue #1463 consolidates WordPress materialization on `wordpress-site-plan/v2`.

| Surface | Proven consumer | Decision |
| --- | --- | --- |
| `source_reports.wordpress_site_plan` | `WordPressSitePlanResolver`, WordPress integration tests, generic acceptance tooling | Retained as the sole WordPress materialization contract. |
| `WordPressSitePlanView` | documented Static Site Importer adapter boundary | Retained; it exposes plan, Gutenberg gaps, companion payload, font handoff, editability, and diagnostics. |
| `source_reports.compiled_site` | compiler contract tests and compatibility/reporting consumers | Retained as a compiler report; it is not a materialization handoff. |
| `source_reports.materialization_plan` | no checked-in runtime consumer; SSI evidence is documentation and fixtures only | Removed. Its derived route, navigation, menu, and font facts are consumed by the internal `WordPressSitePlanInput`; font handoff is exposed directly as `source_reports.font_materialization`. |
| Root `blocks`, `documents`, and `assets` | generic transformer consumers and documented SSI adapter example | Retained. They are not used by WordPress materializers. |
| Staged shared/page plans and compiled receipts | `ArtifactCompiler::compose()`, staged contract tests, benchmark tooling | Retained as persisted compilation transport schemas. Direct and staged compilation must produce identical canonical plans. |

Static Site Importer is not implemented in this repository. The checked-in adapter map and SSI fixture evidence show its proven materialization boundary is `WordPressSitePlanView`, so no unproven downstream source was migrated here.
