# Figma WordPress Acceptance Matrix v1

Run the repository-owned acceptance gate from a fresh checkout:

```sh
php scripts/production-acceptance-matrix.php --manifest=path/to/private-fixtures.json --output=artifacts/figma-wordpress-acceptance
```

The manifest supplies private `.fig` files, authoritative screenshots, and generic provider commands. Inputs may be outside the repository; the resulting `summary.json` intentionally never includes their paths. Required fixture IDs are `fse-pilot-build-theme`, `twenty-twenty-five-community`, and `fisiostetic`.

Each fixture declares an additive `blocks-engine/wordpress-site-plan/v1` file and versioned evidence for `decode`, `normalize`, `emit`, `import`, `editor_validity`, `fallback`, `desktop_parity`, `mobile_parity`, and `responsive_selection`. A provider may call Static Site Importer and an isolated WordPress runtime, but this repository only consumes its generic command and evidence contract.

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

`figma_matrix_command` runs the existing Figma fixture matrix for the `.fig` decode, normalized IR, and static-artifact stages. `provider_commands` remain generic external commands for artifact compilation, isolated WordPress materialization, editor validation, and visual capture. Every evidence file has schema `blocks-engine/figma-wordpress-stage-evidence/v1`, `status: "passed"`, and non-empty reviewer-resolvable relative `references` that exist beneath the matrix output directory. Desktop and mobile parity evidence also requires existing relative `source_screenshot`, `rendered_screenshot`, and `diff_report` artifacts. Fallback evidence requires integer `fallback_count: 0`. Responsive selection evidence requires `selection_source` of `dev_status` or `heuristic_fallback` and non-empty `responsive_routes`, where every route identifies at least two `source_frames`. The supplied site plan is validated by `WordPressSitePlan::assertValid()`. Missing or malformed input, site-plan, screenshots, editor proof, imports, or parity evidence fails closed with a stable `<stage>_<reason>` code in `summary.json`.
