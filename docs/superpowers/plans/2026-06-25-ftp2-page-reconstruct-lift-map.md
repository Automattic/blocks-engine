# FTP2a page-reconstruct deterministic-core lift map

Prepared against:

- Engine: `/Users/matt/projects/a8c/blocks-engine`, branch `feature/site-to-theme`, HEAD `203668b5`.
- DLA: `/Users/matt/projects/a8c/data-liberation-agent`, branch `feature/adopt-blocks-engine`, HEAD `e2f3de7`.
- Source: `/Users/matt/projects/a8c/data-liberation-agent/src/lib/replicate/page-reconstruct.ts` has 1844 lines.

This map defines how to lift the deterministic native-first reconstruction behavior from DLA into the engine `reconstruct` stage without moving code in this slice.

Classification key:

- `LIFT`: deterministic behavior that should move to `packages/blocks-engine/src/theme/`.
- `STAY-DLA`: DLA runtime, plugin, capture, adapter, editable-html, Jetpack emit, or post-engine integration behavior.
- `SHARED`: already engine-owned, contract-shaped, or move-with-care because the current DLA surface combines engine and DLA responsibilities.

Phase 0 disagreements preserved in the handoff:

- The frozen G2 grep counts 46 symbols, but an AST pass finds 57 top-level declarations when local types, constants, sets, and `export { escapeHtml }` are included. This doc classifies all 57 and reports the 46/46 gate explicitly.
- The Phase 6 phrase "walk the section" does not match the live DLA implementation literally. Live DLA uses a `SectionSpec` renderer, an optional converted native fast path, and coverage-gated fallback. This map describes that live decision chain.
- Jetpack form emit is currently inside `reconstructPagePattern`; the target boundary moves the emit decision to DLA hooks while keeping the pure mapper shared.

## 1. INVENTORY

Frozen G2 command:

```sh
cd /Users/matt/projects/a8c/data-liberation-agent && grep -nE "^(export )?(async )?function |^export (const|interface|type) " src/lib/replicate/page-reconstruct.ts | wc -l
```

Current result: `46`.

Inventory result in this doc:

- G2 symbols classified: `46 / 46`.
- Top-level declarations classified: `57 / 57`.
- Extra declarations beyond G2: local type aliases, local interfaces, local constants, dispatch sets, and the `export { escapeHtml }` re-export.

### page-reconstruct.ts top-level declarations

| Symbol | Lines | G2 | Class | Lift note |
|---|---:|---:|---|---|
| `FaqPair` | `page-reconstruct.ts:61-64` | yes | SHARED | Same shape as engine `ExtractedFaq`; keep as a section data contract, not DLA runtime. |
| `SectionSpecWithFaqs` | `page-reconstruct.ts:67` | no | SHARED | Local alias over `SectionSpec`; engine `SectionSpec` already has `faqs`. |
| `ReconstructOptions` | `page-reconstruct.ts:69-120` | yes | SHARED | Split later: deterministic options move to engine; adapter recipe and PHP/page-pattern options stay DLA. |
| `ReconstructResult` | `page-reconstruct.ts:122-152` | yes | SHARED | Split later: block markup, diagnostics, assets, and hero signal move; PHP pattern wrapper and page install metadata stay DLA. |
| `{ escapeHtml }` export | `page-reconstruct.ts:160` | no | SHARED | Engine already has `escapeHtml` in `packages/blocks-engine/src/escape.ts:13-15`; DLA re-export is compatibility surface. |
| `visibleText` | `page-reconstruct.ts:165` | no | LIFT | Registers visible converted headings/paragraphs into the deterministic provenance corpus. |
| `MISSING_IMAGE_PLACEHOLDER` | `page-reconstruct.ts:167` | no | LIFT | Deterministic honest placeholder for missing media. |
| `MIN_LEAD_IMAGE_PX` | `page-reconstruct.ts:176` | no | LIFT | Deterministic lead-image threshold. |
| `MIN_CELL_IMAGE_PX` | `page-reconstruct.ts:181` | no | LIFT | Deterministic cell/recovery image threshold. |
| `pickLeadImage` | `page-reconstruct.ts:184-186` | yes | LIFT | Native recipe image selection. |
| `isWpUrl` | `page-reconstruct.ts:189-191` | yes | LIFT | Deterministic migrated-media predicate; likely rename to `isWpMediaUrl`. |
| `isTintedSection` | `page-reconstruct.ts:201-208` | yes | LIFT | Layout/color recipe for raised section surfaces. |
| `BlockOut` | `page-reconstruct.ts:214-222` | no | LIFT | Internal render accumulator for markup, corpus, assets, flags, and icon assets. |
| `emptyOut` | `page-reconstruct.ts:224-226` | yes | LIFT | Render accumulator initializer. |
| `recolorSvg` | `page-reconstruct.ts:235-240` | yes | LIFT | Deterministic SVG color normalization after sanitization. |
| `RenderCtx` | `page-reconstruct.ts:243-252` | no | LIFT | Per-page render context for alternating media, icon ids, palette, and fonts. |
| `iconImageBlock` | `page-reconstruct.ts:261-289` | yes | LIFT | Native icon asset emission; engine must thread assets through `ThemeModel.assets`. |
| `resolveImage` | `page-reconstruct.ts:292-306` | yes | LIFT | Honest media fallback and provenance flagging. |
| `imageBlock` | `page-reconstruct.ts:308-346` | yes | LIFT | Native `core/image` emission with source dimensions. |
| `responsiveFontSize` | `page-reconstruct.ts:353-358` | yes | LIFT | Deterministic responsive font-size clamp. |
| `responsiveSpace` | `page-reconstruct.ts:364-370` | yes | LIFT | Deterministic responsive spacing clamp. |
| `sectionPad` | `page-reconstruct.ts:375-382` | yes | LIFT | Maps captured section padding into block spacing. |
| `centerOf` | `page-reconstruct.ts:388-390` | yes | LIFT | Text alignment helper with back-compat fallback. |
| `buttonJustify` | `page-reconstruct.ts:395-397` | yes | LIFT | Button row alignment helper. |
| `typographyStyle` | `page-reconstruct.ts:403-418` | yes | LIFT | Block typography attr/inline style generation. |
| `familyHash` | `page-reconstruct.ts:420-423` | yes | LIFT | Font-family matching helper. |
| `familyMatches` | `page-reconstruct.ts:427-438` | yes | LIFT | Font-family token matching. |
| `nearestFamily` | `page-reconstruct.ts:449-466` | yes | LIFT | Maps captured computed font-family to theme token slug. |
| `headingBlock` | `page-reconstruct.ts:468-502` | yes | LIFT | Native heading emitter and expected-text registration. |
| `paragraphBlock` | `page-reconstruct.ts:504-541` | yes | LIFT | Native paragraph emitter and body-text registration. |
| `buttonBlock` | `page-reconstruct.ts:543-560` | yes | LIFT | Hrefless fallback CTA block. |
| `rgbToHex` | `page-reconstruct.ts:563-568` | yes | LIFT | Captured RGB to hex conversion for icon color. |
| `ctaButton` | `page-reconstruct.ts:577-629` | yes | LIFT | Native CTA block with captured href, colors, and icon. |
| `sectionButtons` | `page-reconstruct.ts:633-639` | yes | LIFT | Shared CTA selection for renderers. |
| `renderTextBand` | `page-reconstruct.ts:642-681` | yes | LIFT | Native text-band recipe. |
| `renderCover` | `page-reconstruct.ts:688-721` | yes | LIFT | Native hero/cover recipe and overlay-header input. |
| `renderMediaText` | `page-reconstruct.ts:724-758` | yes | LIFT | Native media-text recipe. |
| `renderCardGrid` | `page-reconstruct.ts:765-789` | yes | LIFT | Native product/project/blog card grid recipe. |
| `dedupeAdjacent` | `page-reconstruct.ts:792-798` | yes | LIFT | Review/grid copy cleanup. |
| `renderReviewGrid` | `page-reconstruct.ts:804-876` | yes | LIFT | Native review/testimonial grid recipe. |
| `galleryBlock` | `page-reconstruct.ts:881-955` | yes | LIFT | Native gallery/scroller emission. |
| `renderImageRow` | `page-reconstruct.ts:959-968` | yes | LIFT | Native gallery/logo/image-row section recipe. |
| `renderFaq` | `page-reconstruct.ts:971-1001` | yes | LIFT | Native FAQ/details recipe; engine `SectionSpec` already has `faqs`. |
| `column` | `page-reconstruct.ts:1005-1013` | yes | LIFT | Low-level columns wrapper. |
| `columns` | `page-reconstruct.ts:1015-1031` | yes | LIFT | Columns layout wrapper with full-bleed switch. |
| `wrapSection` | `page-reconstruct.ts:1033-1107` | yes | LIFT | Main native section wrapper, layout, spacing, and background decisions. |
| `opaqueTintHex` | `page-reconstruct.ts:1116-1140` | yes | LIFT | Deterministic captured-tint normalization. |
| `isDarkSection` | `page-reconstruct.ts:1143-1145` | yes | LIFT | Inverse text/background predicate. |
| `renderCellGrid` | `page-reconstruct.ts:1157-1238` | yes | LIFT | Native cell/card grid recipe, including full-bleed card rows. |
| `cardGroup` | `page-reconstruct.ts:1244-1275` | yes | LIFT | Native styled-card wrapper. |
| `NON_CELL_GRID_MODELS` | `page-reconstruct.ts:1278-1284` | no | LIFT | Dispatch guard for cell-grid routing. |
| `FLATTEN_PRONE_MODELS` | `page-reconstruct.ts:1290-1296` | no | LIFT | Dispatch guard for relaxed unflattening. |
| `MEDIA_LAYOUT_DENY` | `page-reconstruct.ts:1301-1311` | no | LIFT | Dispatch guard for media-layout rerouting. |
| `renderSection` | `page-reconstruct.ts:1317-1430` | yes | LIFT | Core native recipe dispatch. |
| `renderSectionForms` | `page-reconstruct.ts:1444-1462` | yes | STAY-DLA | Emits Jetpack/contact-form markup and provenance flags; pure mapper is shared, emit decision stays DLA. |
| `reconstructPagePattern` | `page-reconstruct.ts:1468-1840` | yes | SHARED | Split: deterministic section normalization, native render, coverage, fallback, diagnostics move; PHP header, adapter recipe, Jetpack emit, and DLA page corpus integration stay DLA. |
| `dedupe` | `page-reconstruct.ts:1842-1844` | yes | LIFT | Deterministic output de-duplication helper. |

### Key imported helpers

| Imported helper | Import site | Source lines | Class | Lift note |
|---|---:|---:|---|---|
| `SectionSpec`, `SectionSpecImage`, `SectionSpecIcon`, `SectionSpecCell` | `page-reconstruct.ts:29` | DLA `section-extract.ts:526-759`; engine `section-spec.ts:35-174` | SHARED | The data contract belongs in engine; Playwright/browser capture that populates it stays DLA. |
| `nearestToken`, `brightness`, `PaletteToken` | `page-reconstruct.ts:30` | DLA `footer-color.ts:19-123` | LIFT | Pure color/token math used by native recipes; image sampling helpers in the same DLA file stay DLA. |
| `ExtractedReview` | `page-reconstruct.ts:31` | DLA `review-extract.ts:27`; engine `section-spec.ts:23-28` | SHARED | Data type only; extraction stays DLA. |
| `cheerio` | `page-reconstruct.ts:32` | external dep; used at `page-reconstruct.ts:1516` | SHARED | Deterministic HTML text decoding is allowed in engine; no browser. |
| `buildHtmlFallbackBlock`, `selectIslandSource` | `page-reconstruct.ts:33-48` | engine `html-fallback.ts:48-86` | SHARED | Already engine-owned fallback island helpers. |
| `measureSectionCoverage`, `measureConvertedCoverage`, `foldText` | `page-reconstruct.ts:33-48` | engine `section-coverage.ts:17-107` | SHARED | Already engine-owned coverage primitives. |
| `formToBlocks`, `SKIPPED_FIELD_KINDS` | `page-reconstruct.ts:33-48` | engine `form-blocks.ts:55-159` | SHARED | Pure HTML-form to Jetpack-block mapper is engine-owned; deciding to emit/activate Jetpack stays DLA. |
| `buildFallbackDiagnostic`, `FallbackDiagnostic` | `page-reconstruct.ts:33-48` | engine `fallback-diagnostic.ts:4-69` | SHARED | Already engine-owned deterministic diagnostics. |
| `normalizeCopy`, `sanitizePatternHeaderField`, `stripChrome`, `sanitizeSvgAsset`, `FontFamilyToken` | `page-reconstruct.ts:33-48` | engine `page-reconstruct-helpers.ts:6-123` | SHARED | Already engine-owned page-reconstruct helper subset. |
| `rewriteMediaUrls` | `page-reconstruct.ts:49` | DLA `media-url-rewrite.ts:45-107`; engine `url-rewrite.ts:3-95` | SHARED | Use engine implementation after lift. |
| `hasUnmigratedRemoteAsset`, `scanForInjection` | `page-reconstruct.ts:50` | DLA `validate-artifacts.ts:130-195`; engine `injection-scan.ts:1-24` | SHARED | Use engine implementation after lift; full DLA artifact validation stays DLA. |
| `applyBlockRecipe` | `page-reconstruct.ts:51` | DLA `apply-block-recipe.ts:16-65` | STAY-DLA | Adapter/platform recipe hook; not part of standalone engine core. |
| `escapeHtml` | `page-reconstruct.ts:159-160` | DLA `html-escape.ts:23-25`; engine `escape.ts:13-15` | SHARED | Use engine escape helper after lift; DLA re-export can remain for compatibility until consumers move. |

### Adjacent deterministic surfaces not top-level in page-reconstruct.ts

- Variation hoisting is named by the Phase 6 spec but is not implemented inside `page-reconstruct.ts`. DLA owns it in `src/lib/replicate/variation-hoist.ts:1-186`; it depends on `serializeBlockAttrs` from DLA `form-blocks.ts:9-13`. Engine has a leaf `serializeBlockAttrs` at `packages/blocks-engine/src/theme/form-blocks.ts:80-86`, but the public barrel currently exports only `formToBlocks` and `SKIPPED_FIELD_KINDS` at `packages/blocks-engine/src/theme/index.ts:22`. Treat variation hoisting as a later dedicated sub-slice, not part of the first page-reconstruct renderer lift.
- The DLA `dla/editable-html` block plugin is outside `page-reconstruct.ts`; it is built by `src/blocks/editable-html-plugin.ts:4-215`, emitted by `src/lib/replicate/normalize/island-bindings.ts:375-386`, converted by `src/lib/replicate/normalize/make-islands-editable.ts:27-49`, and installed from `convert-local-site.ts:806`. It stays DLA.

## 2. NATIVE-FIRST DESCENT

Live DLA does not contain a generic raw-DOM recursive descent in `page-reconstruct.ts`. The native-first behavior is a decision chain over `SectionSpec` plus optional pre-converted native blocks.

### Decision chain

1. Normalize the section list.
   - `reconstructPagePattern` strips header/footer chrome with `stripChrome`, resolves captured font families to theme tokens, and removes only verified promoted-heading echoes. See `page-reconstruct.ts:1468-1543`.
   - This is deterministic and should move, but `patternSlug`, PHP title, and page pattern header concerns should not.

2. Accept pre-converted native blocks when they are safe.
   - `opts.convertedSections` is checked per `sectionIndex`; accepted only when markup exists, `wpHtmlResidue === 0`, media URLs are rewritten, remote assets are gone, unresolved placeholders are absent, injection scan is clean, and `measureConvertedCoverage` reports no loss. See `page-reconstruct.ts:1558-1602`.
   - When accepted, DLA registers visible `<h*>` and `<p>` text from the converted markup into provenance and appends captured forms after the converted blocks. See `page-reconstruct.ts:1591-1606`.
   - Lift target: engine-owned converted-section acceptance. Sever point: form append moves to DLA hook behavior.

3. Suppress form echo before native rendering.
   - For sections with forms, DLA suppresses recaptured submit labels/buttons and field-label echo cells so the structured renderer does not duplicate dead CTAs or route a form band into the wrong cell grid. See `page-reconstruct.ts:1614-1670`.
   - This logic is coupled to Jetpack form emit. It should stay DLA unless the engine receives a plugin-free "form remainder" contract.

4. Render native blocks from the spec model.
   - `renderSection` dispatches by FAQ, cell-grid eligibility, captured `mediaLayout`, and `interactionModel`. See `page-reconstruct.ts:1317-1430`.
   - The recipe catalog includes text bands, cover heroes, media-text, cards, reviews, galleries, FAQ/details, cell grids, image rows, and layout wrappers. See `page-reconstruct.ts:642-1275`.
   - Lift target: engine native renderer modules.

5. Recover recoverable image-only loss.
   - After native render, `measureSectionCoverage` checks captured text and images against emitted markup. If only recoverable WP images are missing, DLA appends image blocks and re-measures. See `page-reconstruct.ts:1717-1757`.
   - Lift target: engine recovery module using existing engine coverage primitives.

6. Try DLA adapter recipe, then core/html island.
   - If coverage is still lost, DLA first tries `applyBlockRecipe` only when `adapterBlocks` exists. See `page-reconstruct.ts:1760-1775`.
   - If no recipe applies, it picks `sectionHtml` or `styledHtml` via `selectIslandSource`, builds a sanitized `core/html` island, records provenance, and creates a fallback diagnostic. See `page-reconstruct.ts:1778-1805`.
   - Lift target: engine fallback island and diagnostics. Sever point: adapter recipe stays DLA.

7. Return page-level artifacts.
   - DLA builds a PHP pattern header, body markup, expected text/body/assets, provenance flags, fallback diagnostics, section count, icon assets, and `heroIsCover`. See `page-reconstruct.ts:1815-1840`.
   - Lift target: body markup, diagnostics, icon assets, and hero signal. DLA keeps PHP pattern file shape and install-time page artifacts.

### Current entanglements to sever

- Jetpack: `renderSectionForms` emits `jetpack/contact-form` blocks and form provenance inside the reconstruct loop at `page-reconstruct.ts:1444-1462`, with call sites at `page-reconstruct.ts:1603-1606` and `page-reconstruct.ts:1671-1702`. The engine must not decide to emit Jetpack or activate modules.
- Editable HTML: DLA convert defaults `editableIslands` on at `convert-local-site.ts:393-395` and installs the block plugin only when islands are converted at `convert-local-site.ts:806`. Engine must emit plain `core/html` islands.
- Adapter recipes: `adapterBlocks` in `ReconstructOptions` at `page-reconstruct.ts:95-106` and `applyBlockRecipe` at `page-reconstruct.ts:1760-1775` are platform/adapter aware. Keep them DLA-side.
- Artifact validation: `scanForInjection` and remote asset predicates are shared primitives, but full DLA artifact validation remains DLA in `validate-artifacts.ts:198-259`.
- PHP pattern wrapper: `sanitizePatternHeaderField` is shared, but the PHP header in `page-reconstruct.ts:1815-1822` is a DLA pattern file concern.

## 3. ENGINE DECOMPOSITION

Keep the public stage entrypoint centered on the existing engine stage:

- Existing public stage: `packages/blocks-engine/src/theme/reconstruct.ts:111-141`.
- Existing hook/context types: `SectionBlocks` at `packages/blocks-engine/src/theme/types.ts:45-49`, `StageCtx` at `packages/blocks-engine/src/theme/types.ts:60-65`, and `SiteToThemeHooks` at `packages/blocks-engine/src/theme/types.ts:73-78`.
- Existing orchestrator: `packages/blocks-engine/src/theme/site-to-theme.ts:46-92`.

The lift should not move the DLA monolith whole. Proposed engine modules:

| Engine file | Responsibility | Frozen interface to expose |
|---|---|---|
| `src/theme/reconstruct.ts` | Keep the public stage. Iterate specs, call converted acceptance, native renderer, recovery, fallback, hooks, and return `SectionBlocks[]`. | Existing `reconstruct(specs, ctx, pool, hooks, coverageFloor): Promise<SectionBlocks[]>`. No new public package export required in the first behavior slice. |
| `src/theme/native-reconstruct-types.ts` | Internal contracts for the lifted renderer: render accumulator, render context, accepted converted result, fallback result, icon asset output. | `NativeRenderOut`, `NativeRenderCtx`, `NativeSectionResult`, `ConvertedSectionInput`, `SectionRenderOptions`. |
| `src/theme/native-converted-section.ts` | Accept or reject pre-converted native block markup using residue, media rewrite, remote asset, placeholder, injection, and coverage gates. | `acceptConvertedSection(spec, converted, opts): NativeSectionResult | null`. |
| `src/theme/native-block-builders.ts` | Low-level block emitters for headings, paragraphs, buttons, CTAs, images, icons, galleries, columns, and groups. | `buildHeadingBlock`, `buildParagraphBlock`, `buildImageBlock`, `buildCtaButton`, `buildGalleryBlock`, `wrapNativeSection`. Keep most exports internal to theme tests. |
| `src/theme/native-layout.ts` | Section layout decisions: spacing clamps, full-bleed/constrained layout, tint/dark/background decisions, button alignment, media alternation. | `resolveSectionLayout(spec, ctx)`, `responsiveSpace`, `responsiveFontSize`, `isTintedSection`, `isDarkSection`. |
| `src/theme/native-fonts.ts` | Captured font-family to theme font token mapping. | `nearestFamily(computed, tokens): string | null`, `familyMatches`. |
| `src/theme/native-media.ts` | Lead image selection, migrated-media predicate, SVG sanitization/recolor use, image recovery eligibility, icon asset naming. | `pickLeadImage`, `isWpMediaUrl`, `recoverMissingImages`, `buildIconAssetImage`. |
| `src/theme/native-section-recipes.ts` | Recipe catalog and dispatch for text band, cover, media-text, cards, reviews, image rows, FAQ/details, cell grids. | `renderNativeSection(spec, ctx): NativeRenderOut`. |
| `src/theme/native-fallback.ts` | Coverage loss fallback, source selection, `core/html` island build, and fallback diagnostic creation using existing `html-fallback.ts` and `fallback-diagnostic.ts`. | `buildNativeFallback(spec, coverage, opts): NativeSectionResult`. |
| `src/theme/native-form-remainder.ts` | Plugin-free form boundary. Do not emit Jetpack. Decide whether a form remains a plain `core/html` island or an event for DLA hooks. | `extractFormRemainder(spec): FormRemainder | null`; no Jetpack block names. This interface needs a freeze slice before use. |
| `src/theme/native-page-render.ts` | Optional pure page renderer for tests and DLA parity harness, without PHP pattern files. | `renderNativePage(sections, opts): NativePageRenderResult` with body, diagnostics, icon assets, hero signal, and provenance corpus. |

Reuse existing modules rather than duplicating:

- `html-fallback.ts` for sanitized `core/html` islands.
- `section-coverage.ts` for text/image coverage.
- `fallback-diagnostic.ts` for fallback records.
- `page-reconstruct-helpers.ts` for copy/header/chrome/SVG helper surface already ported.
- `url-rewrite.ts` and `injection-scan.ts` for media rewrite and safety gates.
- `section-spec.ts` for section data contracts.
- `form-blocks.ts` only as a pure shared mapper; Jetpack emission policy stays outside engine.

Package-barrel rule:

- Do not export every new native helper from `src/theme/index.ts` by default.
- Export only contracts required by DLA parity/adoption tests. Internal recipe modules can remain leaf-internal until DLA needs them.

## 4. HOOK SEAM

Current hook types:

- `StageCtx` contains `srcDir`, `site`, `themeMeta`, and `warn` at `packages/blocks-engine/src/theme/types.ts:60-65`.
- `SectionBlocks` contains `spec`, `blocks`, and `coverage` at `packages/blocks-engine/src/theme/types.ts:45-49`.
- `SiteToThemeHooks` defines `onSection`, `onAssets`, and `onRefine` at `packages/blocks-engine/src/theme/types.ts:73-78`.
- `siteToTheme` passes hooks to `reconstruct`, applies `onAssets`, then applies `onRefine` before `writeTheme` at `packages/blocks-engine/src/theme/site-to-theme.ts:49-92`.

Current limitation:

- `reconstruct` only invokes `hooks.onSection` when `section.coverage <= coverageFloor` at `packages/blocks-engine/src/theme/reconstruct.ts:126-136`.
- The behavior is intentionally covered by `theme-reconstruct.test.ts:288-319`, which expects `onSection` to observe only a fallback/coverage-0 section.
- This is enough for plain `core/html` islands because `skeletonCoverage` returns 0 for html islands at `packages/blocks-engine/src/theme/reconstruct.ts:76-78`.
- It is not enough to let DLA append Jetpack forms after a fully native form-containing section unless the lifted engine marks form remainder as an island/low-coverage section or a later contract changes the hook policy.

Target seam:

1. Engine deterministic output, hook absent.
   - Native sections emit core blocks.
   - Irreducible remainders emit sanitized, plugin-free `core/html` islands through `buildHtmlFallbackBlock`.
   - Forms do not become Jetpack blocks. If a form cannot be represented with core blocks, it remains a deterministic remainder island or a typed form remainder.
   - No `dla/editable-html`, no `jetpack/*` activation decision, no Playwright, no vision, no plugin install.

2. DLA `onSection`, hook present.
   - Input: `SectionBlocks` with original `section.spec` and deterministic engine `blocks`.
   - Editable-html conversion: replace engine `core/html` island markup with `dla/editable-html` only in DLA. DLA owns the block implementation in `src/blocks/editable-html-plugin.ts:4-215` and the install decision in `convert-local-site.ts:806`.
   - Jetpack emit: when `section.spec.forms` exists, DLA uses shared `formToBlocks` grammar but decides whether and where to append live `jetpack/contact-form` markup. It also records skipped-field provenance and sets any counters used to decide Jetpack install/module activation.
   - If the current coverage-gated hook policy is retained, engine must ensure sections needing DLA form/editable processing have `coverage` at or below the floor. The cleaner later contract is an explicit `onSection` policy or typed `SectionBlocks.events`, frozen before implementation.

3. DLA `onRefine`, hook present.
   - Input: full `ThemeModel` after assemble and before write.
   - DLA can make final theme-model substitutions that require whole-theme context: replace residual islands in templates/patterns, inject page-local templates, preserve sidecar page content, and align interior chrome. Current DLA already uses `onRefine` in `convert-local-site.ts:760-779`.
   - DLA still performs post-return plugin packaging/install, `functions.php`, Studio/WP install, Jetpack module activation, safe-svg, parity repair, and editor scoring outside engine. See `convert-local-site.ts:873-991` and `convert-local-site.ts:1014-1683`.

Required contract decision before code lift:

- Either freeze "engine calls `onSection` for every section after deterministic render" or freeze "engine emits typed low-coverage form/editable remainders so current gated `onSection` semantics remain enough." The second option changes less of the existing hook contract, but it forces form sections through a remainder marker when DLA needs Jetpack emit.
- A third option is to move `onSection` to a pre-finalization section-tail point, where DLA can append Jetpack markup before final coverage/fallback. That is a real contract change because it reverses the current post-fallback expectation in `theme-reconstruct.test.ts:288-319`; freeze it explicitly before any renderer port.

## 5. PARITY PROOF STRATEGY

The safety net must prove deterministic behavior, not only shape. The proof should be layered so each sub-slice catches drift early.

### Golden corpus

Create a DLA-owned fixture corpus from captured `SectionSpec[]` and known `ReconstructOptions` that covers:

- Converted-native acceptance with `convertedSections` and `wpHtmlResidue === 0`.
- Converted-native rejection on remote assets, unresolved placeholders, injection, and coverage loss.
- Text band, cover hero, media-text, product/project/blog cards, review grid, gallery/image row, FAQ/details, cell grid, full-bleed card row.
- Heading echo suppression with decoded HTML entities.
- Missing image placeholder, recoverable image append, and unrecoverable island fallback.
- `styledHtml` vs `sectionHtml` island tier selection.
- Fallback diagnostics.
- Form sections, but split into deterministic core expectation and DLA hook expectation.
- Adapter recipe cases kept as DLA-only expectations.

### Byte comparisons

1. DLA-today baseline.
   - Run current DLA `reconstructPagePattern(sections, opts)` over the corpus.
   - Save `body`, `expectedText`, `bodyText`, `expectedAssets`, `provenanceFlags`, `fallbackDiagnostics`, `iconAssets`, and `heroIsCover`.
   - For DLA-only surfaces, save `php` separately so engine is not forced to own PHP pattern headers.

2. Engine deterministic core.
   - Run the engine lifted renderer over the same injected `SectionSpec[]`, palette tokens, font tokens, media map, and converted-section data.
   - Compare body markup, diagnostics, assets, icon assets, and hero signal byte-for-byte against the DLA deterministic subset.
   - Exclude `adapterBlocks`, Jetpack emit, editable-html conversion, and PHP header from this core comparison unless DLA hooks are intentionally enabled.

3. Engine plus DLA hooks.
   - Run `siteToTheme(srcDir, { sections, foundationAggregates, hooks, coverageFloor, fetchImpl })` with captured specs injected through `SiteToThemeOptions.sections`.
   - DLA hooks convert islands to `dla/editable-html`, append/replace Jetpack forms, and then DLA performs post-return plugin/module steps.
   - Compare final DLA-generated theme/page artifacts to DLA-today bytes for the deterministic path. Any allowed difference must be predeclared in the frozen gate.

### Test placement

- Engine tests own pure renderer parity for deterministic helpers and recipes.
- DLA tests own hook integration, editable-html conversion, Jetpack emission, plugin activation decisions, and byte comparison against DLA-today.
- No browser, Playwright, WP install, network, AI, time, random, or screenshot dependency belongs in deterministic-core gates.

### Gate style

- Every sub-slice gets a corpus count and byte-diff count.
- Gates must report both exact command and raw result, for example `cases=12 diffs=0`.
- If a grep gate is used, it must target module paths or exported symbols, not data/provenance strings. Do not repeat the prior gate-gaming failure mode.

## 6. SUB-SLICE PLAN

### FTP2b - freeze native reconstruct contracts and parity corpus

Scope:

- Add engine contract files for native reconstruct internal result types and form remainder/event shape.
- Add DLA or shared fixtures that serialize the DLA-today deterministic corpus without changing runtime behavior.
- Freeze whether `onSection` runs for all sections, runs pre-finalization, or remains low-coverage only with typed remainders.

Out of scope:

- No renderer behavior port.
- No DLA adoption.
- No editable-html or Jetpack behavior change.

Parity/acceptance gate:

- Engine typecheck and tests pass.
- DLA corpus generator/test reports current DLA baseline cases with `diffs=0` against checked-in goldens or freshly frozen snapshots.
- Contract freeze commit contains only contract/test fixture files; contracts are read-only after this slice.

### FTP2c - port low-level native block builders and layout/media helpers

Scope:

- Port deterministic helpers from `page-reconstruct.ts:165-639` plus low-level wrappers/layout primitives from `page-reconstruct.ts:1005-1145`.
- Include color token helpers from DLA `footer-color.ts:103-123`.
- Add unit tests for copy normalization, image placeholder/recovery eligibility, SVG icon asset emission, responsive font/space clamps, tint/dark/full-bleed decisions, and font-family token mapping.

Out of scope:

- No `renderSection` dispatch.
- No `reconstruct.ts` wiring.
- No forms, adapter recipe, editable-html, Jetpack, or DLA import changes.

Parity/acceptance gate:

- Engine helper tests compare each ported helper against DLA-today corpus outputs with `diffs=0`.
- Browser-free grep over new engine theme files prints OK.
- `git show --name-status HEAD` lists only new/changed engine helper and test files for this slice.

### FTP2d - port native section recipe catalog

Scope:

- Port `renderTextBand`, `renderCover`, `renderMediaText`, `renderCardGrid`, `renderReviewGrid`, `galleryBlock`, `renderImageRow`, `renderFaq`, `renderCellGrid`, `cardGroup`, dispatch sets, and `renderSection`.
- Use engine `SectionSpec` and engine helper modules.
- Produce `NativeSectionResult` without PHP header or Jetpack form append.

Out of scope:

- No converted-section fast path.
- No coverage fallback integration into the public `reconstruct` stage.
- No DLA adoption.

Parity/acceptance gate:

- Engine recipe corpus covers every `interactionModel` routed by `renderSection` and reports `cases>=10 diffs=0` against DLA deterministic native output with forms disabled or separated.
- Typecheck, full engine tests, and browser-free grep pass.

### FTP2e - wire converted acceptance, coverage recovery, and core/html fallback into engine reconstruct

Scope:

- Add `native-converted-section.ts` and `native-fallback.ts`.
- Wire existing `reconstruct.ts` to use injected/precomputed converted sections where available, native recipes otherwise, recover image-only loss, and fall back to engine `core/html` islands with diagnostics.
- Preserve current `reconstruct(specs, ctx, pool, hooks, coverageFloor)` public signature unless FTP2b froze a change.

Out of scope:

- No DLA editable-html conversion.
- No Jetpack emit.
- No adapter recipe port.
- No PHP pattern generation.

Parity/acceptance gate:

- Engine reconstruct parity corpus reports converted accept/reject, recovery, fallback tier, and diagnostics with `diffs=0` against DLA deterministic subset.
- Existing site-to-theme e2e fixtures still pass.
- Hook-absent identity and React-isolation tests remain green.

### FTP2f - DLA hook seam for editable-html and Jetpack forms

Scope:

- In DLA, implement `onSection`/`onRefine` hook behavior that converts engine `core/html` islands to `dla/editable-html` and emits Jetpack forms after engine deterministic output.
- Keep `buildEditableHtmlPlugin`, plugin install, Jetpack install/module activation, safe-svg, and WP/Studio steps in DLA.
- Use engine `formToBlocks` as a shared pure mapper; do not move activation decisions.

Out of scope:

- No engine recipe changes except contract fixes required by FTP2b.
- No Playwright capture changes.
- No vision asset triage changes.
- No adapter recipe deletion.

Parity/acceptance gate:

- DLA engine-injected corpus with hooks enabled matches DLA-today deterministic output with `diffs=0`, including form sections and editable islands.
- Targeted DLA tests for editable-html plugin install decision and Jetpack activation decision pass.
- No `dla/editable-html` or `jetpack/*` strings are introduced into engine source files outside existing pure form grammar.

### FTP2g - DLA adoption of engine native reconstruct core

Scope:

- Repoint DLA `page-reconstruct.ts` deterministic renderer calls to engine native reconstruct exports or replace the monolith's deterministic body with engine calls.
- Delete or strip DLA duplicate deterministic helpers only after the parity harness is green.
- Keep DLA `applyBlockRecipe`, PHP pattern wrapper, editable-html hook, Jetpack hook, plugin activation, artifact validation, and repair/editor flow.

Out of scope:

- No delete of Playwright capture, source extraction, asset triage, adapter recipe, or plugin-aware install modules.
- No npm publish without user authorization.
- No broad grep gates that match provenance strings.

Parity/acceptance gate:

- DLA targeted reconstruct tests pass.
- DLA byte parity corpus reports `diffs=0` for engine-injected deterministic core plus DLA hooks.
- Deletion/repoint proof shows no DLA production consumer imports removed deterministic helpers from the old DLA module.
- Hygiene gate lists only intended DLA files and engine dependency/import changes.

### FTP2h - variation hoisting follow-up

Scope:

- Decide whether variation hoisting moves into engine after native reconstruct output is engine-owned.
- If lifted, expose `serializeBlockAttrs` or an equivalent attr serializer through the engine public theme barrel and port `variation-hoist.ts` deterministically.

Out of scope:

- Not part of the initial page-reconstruct renderer lift.
- No Jetpack variation behavior changes; current DLA guard that excludes `jetpack/*` from hoisting must survive.

Parity/acceptance gate:

- Variation corpus reports `diffs=0` for hoist decisions and swapped markup.
- Jetpack and `core/html` exclusion tests remain green.
