# Figma WordPress Acceptance Matrix v1

This contract is superseded by [Figma WordPress Acceptance Matrix v2](figma-wordpress-acceptance-matrix-v2.md), which separates Figma-to-HTML, HTML-to-WordPress, and end-to-end parity and supports arbitrary manifest fixtures.

Run the repository-owned acceptance gate from a fresh checkout:

```sh
php scripts/production-acceptance-matrix.php --manifest=path/to/private-fixtures.json --output=artifacts/figma-wordpress-acceptance
```

The manifest supplies private `.fig` files, authoritative screenshots, and generic provider commands. Inputs may be outside the repository; the resulting `summary.json` intentionally never includes their paths. Required fixture IDs are `fse-pilot-build-theme`, `twenty-twenty-five-community`, and `fisiostetic`.

Each fixture declares a self-contained deployable `blocks-engine/wordpress-site-plan/v2` block-theme file and versioned evidence for `decode`, `normalize`, `emit`, `import`, `editor_validity`, `fallback`, `desktop_parity`, `mobile_parity`, and `responsive_selection`. A provider may call Static Site Importer and an isolated WordPress runtime, but this repository only consumes its generic command and evidence contract.

```json
{
  "fixtures": [
    {
      "id": "fse-pilot-build-theme",
      "fig": "/private-corpus/fse-pilot-build-theme.fig",
      "site_plan": "evidence/fse-pilot-build-theme/site-plan.json",
      "figma_matrix_command": "php figma-transformer/scripts/figma-fixture-matrix.php --fixture {fig} --output-dir {fixture_output}/figma",
      "provider_commands": {
        "materialize": "my-provider --fig {fig} --output {fixture_output}"
      },
      "evidence": {
        "decode": "evidence/fse-pilot-build-theme/decode.json",
        "normalize": "evidence/fse-pilot-build-theme/normalize.json",
        "emit": "evidence/fse-pilot-build-theme/emit.json",
        "import": "evidence/fse-pilot-build-theme/import.json",
        "editor_validity": "evidence/fse-pilot-build-theme/editor-validity.json",
        "fallback": "evidence/fse-pilot-build-theme/fallback.json",
        "desktop_parity": "evidence/fse-pilot-build-theme/desktop-parity.json",
        "mobile_parity": "evidence/fse-pilot-build-theme/mobile-parity.json",
        "responsive_selection": "evidence/fse-pilot-build-theme/responsive-selection.json"
      }
    }
  ]
}
```

`figma_matrix_command` runs the existing Figma fixture matrix for the `.fig` decode, normalized IR, and static-artifact stages. `provider_commands` remain generic external commands for artifact compilation, isolated WordPress materialization, editor validation, and visual capture.

Every evidence envelope has schema `blocks-engine/figma-wordpress-stage-evidence/v1`, `status: "passed"`, exact `fixture_id` and `stage`, and `source_sha256` equal to the supplied `.fig` file. References are non-empty relative paths that exist beneath the matrix output directory.

- Decode metrics require `missing_text_count`, `missing_asset_count`, and `vector_placeholder_count` all equal to zero.
- Normalize requires positive `normalized_node_count`.
- Emit requires positive `emitted_route_count` and zero missing emitted assets and text.
- Import requires `isolated_fresh_wordpress_import: true`, positive `imported_route_count`, and non-empty provider and runtime identities.
- Editor validity requires positive parsed and native editable block counts with zero invalid blocks.
- Fallback requires integer `fallback_count: 0`.
- Desktop and mobile parity require existing screenshots and a parseable diff report with `pixel_difference_count: 0` and `geometry_difference_count: 0`.
- Responsive selection requires `selection_source` of `dev_status` or `heuristic_fallback`; each route identifies one output route, explicit desktop and mobile source frames, and an increasing bounded breakpoint range.

The supplied site plan is validated by `WordPressSitePlan::assertValid()` and must contain at least one page and route. It includes the v2 deployable theme scaffold, canonical markup, reference tokens, writes, and operations; missing or malformed inputs, metrics, imports, editor proof, parity proof, or site-plan content fail closed with stable stage/reason codes in `summary.json`.
