# Wrapper decisions: stage 0 inventory (#7471)

Wrapper keep/drop/fold/coalesce decisions are made in many places. This is
the map for moving them into `WrapperDecision`
(`src/HtmlToBlocks/Elements/WrapperDecision.php`) and the checklist for
retiring superseded heuristics (stage 2). Each `WrapperDecision` table runs
its named guards in one of three ordered stages:

1. **geometry**: does the wrapper's own box change rendering?
2. **identity**: id or anchor target, role or landmark, shell or document
   edge, interactive attributes, consumed data, motion and runtime targets.
3. **restatement**: can the rules that match the wrapper be restated on the
   element that keeps them (tag markers, class boundaries, selector survival)?

Outcomes use the `WrapperDisposition` vocabulary: `preserve`, `fold`,
`coalesce`, `css-owned` and `drop`. Each outcome carries a named reason and
the stage that produced it.

## Owned by `WrapperDecision`

| Table | Former decider | Guards (in order) | Pinned by |
| --- | --- | --- | --- |
| `preflight()` + `resolve()` (coalescing) | `WrapperCoalescer::coalescingDisposition()` (#7472) | the 25 names in `WrapperDecision::guardStages()` | `tests/unit/wrapper-coalescer-disposition.php`, `geometry-chain-coalescing.php`, `neutral-child-wrapper-absorption.php`, `layout-geometry-proof-coalesced-target.php`, `inherited-text-wrappers.php` |
| `inertScaffolding()` (drop) | `InertScaffoldingSuppressor::convert()` (this slice) | `capture_device_unavailable_notice` (identity), `closed_empty_dialog`, `inert_hidden_capture_iframe`, `measurement_copy`, `hidden_presentation_scaffolding` (geometry), `inert_live_region_scaffolding` (identity), `inert_empty_custom_element`, `inert_empty_container` (geometry) | `tests/unit/wrapper-decision-retention.php`, `inert-capture-scaffolding.php`, `element-conversion-prelude.php`, `navigation-inert-support-children.php` |
| `wrapperRetention()` (preserve / fold) | `EmptyVisualRetentionPolicy::shouldPreserveWrapper()` (this slice) | fold: `neutral_generated_component_chain` (geometry), `tag_does_not_preserve_wrapper` (identity); preserve: `runtime_dom_target` (identity), `semantic_selector_marker` (restatement), `presentation_attributes`, `responsive_stylesheet_box` (geometry), `structure_signals` (identity), `styled_source_class_boundary`, `tag_marker_box` (restatement); otherwise fold `no_wrapper_box_or_identity` | `combinator-styled-wrapper-retention.php`, `shallow-semantic-wrapper-groups.php`, `wrapper-presentation-promotion.php`, `runtime-data-target-layout-shell.php` |
| `emptyVisualRetention()` (preserve / drop) | `EmptyVisualRetentionPolicy::emptyVisualRetentionPolicy()` generator (this slice) | steps `has_rendered_text` (identity), `inert_out_of_flow_empty_element`, `inert_hidden_empty_element` (geometry), `preserved_wrapper_with_substantive_descendant` (identity), `empty_layout_item_spacer`, `inline_empty_visual` (geometry), `block_identity_or_box` (identity), `static_pseudo_element_rule`, `renderable_box_at_any_viewport`, fallback `empty_visual_inline_candidate` (geometry). The style footprint that consumes each step stays in `EmptyElementFootprint::shouldPreserveEmptyVisualElement()` | `conditional-empty-visual-boundary.php`, `empty-visual-group-shell-transport.php`, `empty-visual-figure.php`, `empty-figure-caption-footprint.php`, `empty-custom-element-slot.php` |

All four tables run in the same order, and with the same short-circuiting and
side-effect gates (`StyleResolver::presentationAttributes()` registers style
carriers), as the predicates they replaced.

## Still deciding locally

These are the next migration candidates. "Duplicates" names the
`WrapperDecision` guard whose signal the site reads again. A blank means the
signal is unique to the site.

| File :: method | Signal read | Outcome | Duplicates | Tests that pin it |
| --- | --- | --- | --- | --- |
| `Elements/WrapperCoalescer` :: `compressProjectedGroupChains()` | saved group chain depth vs `EditabilityPolicy` wrapper ratio / max nesting depth | fold (saved core/group chains) | `saved_group_boundary` | `native-group-wrapper-chain.php`, `native-wrapper-chain-editability.php`, `list-view-wrapper-depth.php`, `wrapper-normalization-saved-depth.php` |
| `Elements/WrapperCoalescer` :: `proofBackedWrapperCoalescing()` | layout-geometry proof for a multi-child wrapper | coalesce | `structural_layout_child_without_proof`, `non_neutral_geometry_without_proof` | `layout-geometry-proof-coalesced-target.php`, `proof-wrapper-fallback-once.php` |
| `Elements/WrapperCoalescer` :: `absorbedNeutralChild()` / inherited-text path | render-neutral child, inherited text declarations | coalesce (child absorbed into wrapper) | `preflight()` guards (already shared) | `neutral-child-wrapper-absorption.php`, `inherited-text-wrappers.php` |
| `Elements/FlowContainerElementConverter` :: `convert()` (single child) | `coalescedSingleGroupWrapper()`, then `shouldPreserveWrapper()` or a direct child of author-owned layout | coalesce / preserve / fold (child replaces wrapper) | reads `resolve()` and `wrapperRetention()` dispositions; adds author-owned-layout child (geometry) | `author-layout-single-item-wrapper.php`, `shallow-semantic-wrapper-groups.php` |
| `Elements/FlowContainerElementConverter` :: `convert()` (author-layout empty child) | `isInertOutOfFlowEmptyElement`, `shouldPreserveEmptyVisualElement` | drop / preserve spacer | `inert_out_of_flow_empty_element`, `emptyVisualRetention()` | `empty-custom-element-slot.php`, `conditional-empty-visual-boundary.php` |
| `Elements/FlowContainerElementConverter` :: `shouldDeferNavigationPatternToChildren()` | `shouldPreserveWrapper()` | defers pattern to children (wrapper kept) | `wrapperRetention()` | `native-navigation-wrapper-boundary.php` |
| `Elements/AuthorLayoutBlockLowering` :: `authorLayoutBlockFromElement()` | `preservesScriptStateWrapper`, author-layout topology change, `coalescedSingleGroupWrapper()` | preserve (layout shell) / coalesce | `preserves_script_state` | `author-layout-topology-output-identity.php`, `runtime-data-target-layout-shell.php` |
| `Elements/ListItemLowering` :: list-item child lowering | `coalescedSingleGroupWrapper()` on a sole child | coalesce | `resolve()` | `list-item-lowering.php`, `wrapped-list-items.php` |
| `Elements/EmptyVisualRetentionPolicy` :: `preservesScriptStateWrapper()` | capture device visibility carrier, boolean attributes, runtime data/aria/role | preserve | `preserves_script_state` (it is that guard's signal), `runtime_dom_target` | `runtime-data-target-layout-shell.php`, `trailing-hidden-panel-wrapper-shell.php` |
| `Elements/EmptyVisualRetentionPolicy` :: `soleElementChild()` | skips inert hidden / display:none empty siblings and comments | fold (siblings ignored, single child found) | `inert_hidden_empty_element` | `uncontrolled-hidden-wrapper.php` |
| `Elements/CustomWrapperConventions` :: `unwrapConventionWrappers()` | recurring custom tag with divergent content, render-neutral CSS, author attribute selectors | fold (`SourceDom::unwrapElement`) or retained selector hook | `semantic_selector_marker` (attribute-selector hooks) | `custom-element-wrapper-attribute-selector.php`, `custom-element-wrapper-motion-structure.php`, `custom-element-wrapper-native-grid.php` |
| `Elements/RenderNeutralElements` :: `isRenderNeutralWrapper()` / `isBoxlessWrapper()` / `isTransparentUnknownElement()` / `isRenderNeutralCustomFlowWrapper()` | inline/author declaration neutrality, empty-host resets, inert positioning | signal consumed by the converters below | `non_neutral_geometry_without_proof`, `box_affecting_declarations_not_neutral` | `render-neutral-custom-flow-wrapper.php`, `render-neutral-custom-control-wrapper.php`, `render-neutral-unrelated-pseudo-element.php` |
| `Elements/RenderNeutralFlowWrapperConverter` :: `convert()` | `isRenderNeutralCustomFlowWrapper()` | fold (lowered as unknown flow element) | geometry neutrality | `render-neutral-custom-flow-wrapper.php` |
| `Elements/TransparentUnknownElementConverter` :: `convert()` | `isTransparentUnknownElement()`, named landmark | fold, landmark preserved | `has_role_attribute` (landmark) | `landmark-custom-element-group.php` |
| `Elements/CustomElementRuntimeConverter` :: `convert()` | runtime disposition `unproven`, empty configured host, render-neutral | drop (`html_custom_element_render_neutral`) / spacer | `runtime_dom_target`, geometry neutrality | `empty-configured-custom-elements.php`, `empty-configured-host-spacing.php`, `hidden-unproven-custom-element.php` |
| `Elements/CustomImageHostLowering` :: `isNeutralPictureBox()` / `hasOnlyInertImageHostAttributes()` | render-neutral `<picture>`, inert host attributes | coalesce into `core/image` | `image_source_child_type_mismatch`, `has_interactive_attributes` | `custom-element-video-host.php`, `textless-media-custom-element.php` |
| `Elements/EmptyInteractiveFeatureShell` :: `isEmptyInteractiveFeatureShell()` | search/cart class-name tokens, runtime target, structural-layout child, layout slot | drop | `runtime_dom_target`, `structural_layout_child_without_proof`, `empty_layout_item_spacer` | empty search/cart shell cases in `tests/contract/run.php` |
| `Elements/InlineTextWrapperLowering` :: `visualTextWrapperBlockFromElement()` / `paragraphBlockFromInlineContentWrapper()` | visual text-wrapper signal, empty visual inline child, author-owned layout | fold into paragraph / preserve | `inline_empty_visual` | `inline-flow-wrapper-box.php`, `styled-wrapper-sole-link-paragraph.php`, `heading-phrasing-wrappers.php` |
| `Elements/GenericFigureLowering` :: caption lowering | `shouldPreserveEmptyVisualElement(caption)` | preserve / drop empty caption | `emptyVisualRetention()` | `empty-figure-caption-footprint.php`, `empty-visual-figure.php` |
| `Elements/AnchorElementConverter` :: `convert()` | `shouldPreserveEmptyVisualElement` on empty anchors and descendants | preserve / drop | `emptyVisualRetention()` | `anchor-wrapper-authored-typography.php` |
| `Elements/AnchorLowering` :: `convertLinkWrapperGroup()` / `recordDroppedLinkWrapper()` | interactive descendants, stretched link card | preserve containers / fold link onto children | `has_interactive_attributes` | `link-wrapper-text-color-parity.php`, `important-undecorated-link-wrapper.php` |
| `Elements/LayoutShellLowering` :: `layoutShellWrapper()` | field box, layout shell class name | preserve (layout shell) | | `layout-shell-root-child-classes.php`, `layout-shell-label.php` |
| `Elements/ElementConversionPrelude` :: `convert()` | dispatches `InertScaffoldingSuppressor` before content | drop (via `inertScaffolding()`) | `inertScaffolding()` | `element-conversion-prelude.php` |
| `HtmlCompilation` :: `boxlessWrapperHookChild()` | retained custom wrapper hook, safe transparent custom element, boxless | fold (hook's Group skipped) | `runtime_dom_target`, geometry neutrality | `custom-element-wrapper-attribute-selector.php` |
| `RichText/RichTextStylingHookNormalizer` :: `normalize()` and wrapper hoisting | attribute-less `<font>`, styling-hook spans, inline wrapper declarations | fold (`SourceDom::unwrapElement`) with restated declarations | `unrepresentable_wrapper_tag` | `heading-phrasing-wrappers.php`, `inline-fragment-wrapper-transport.php` |
| `Style/AuthorLayoutOwnership` :: `isLoneStyledInlineInFlowWrapper()` / `hasBoxChromeWrapperStyling()` | structural layout parent, inline box chrome | preserve | `structural_layout_child_without_proof` | `author-layout-display.php`, `important-display-author-layout.php` |
| `Style/LayoutParticipation` :: `flattensInnerSaveWrapper()` / `neutralizeButtonsWrapper()` / `neutralizeButtonWrapper()` | layout item, native control, positioned, inline | css-owned (save wrapper neutralized) | | `neutralized-flex-button-row.php`, `buttons-wrapper-responsive-width-release.php` |
| `Patterns/ButtonsPattern` :: `unwrapPresentationalSpan()` / `accessibleLinkContentWrapper()` / `labelWrapperPaints()` / `hasWrapperButtonSignal()` | presentational spans, label paint, wrapper button signal | fold / preserve label wrapper | `has_structure_signals_without_proof` | `button-decoration-wrapper.php`, `button-wrapper-inner-fill.php`, `standalone-layout-button-wrapper.php` |
| `Patterns/NavigationPattern` :: `isInertNavigationSupportWrapper()` / `withFlattenedWrapperVisibility()` / `carrierLayoutWrapper()` | aria-hidden support descendants, wrapper visibility per viewport, carrier layout | drop / fold with visibility restated per item / preserve carrier | `has_interactive_attributes`, `restatement` (visibility restated) | `navigation-inert-support-children.php`, `navigation-flattened-wrapper-visibility.php`, `navigation-brand-carrier-layout-wrapper.php` |
| `Patterns/MediaTextPattern`, `Patterns/CoverPattern`, `Patterns/LogoPattern` | pattern-specific media / overlay wrapper shapes | fold into the pattern block | `unsupported_child_block_name` | `media-text-pattern.php`, `cover-pattern.php`, `media-text-classed-overlay.php` |
| `Support/NavigationToggleSuppressor`, `Support/CapturedMenuDialogFold` | capture close helpers, duplicate navigation, captured menu dialog rows | drop / fold | `runtime_dom_target` | `native-navigation-toggle-wrapper-slot.php`, `custom-element-captured-dialog-wrapper.php` |
| `Generators/AuthoredCarouselBlockGenerator` :: `stageWrappers()` | carousel stage wrappers between root and list | fold into carousel shell | `has_motion_structure_token` | `slideshow-layout-wrapper-content.php`, `static-carousel-named-custom-element.php` |
| `WordPressSitePlan/ShellExtraction` :: `unwrapChromeContainers()` / `isLayoutTransparentBlock()` / `isExtraDepthWrapper()` | saved `wrappers`/`config` attrs, `display:contents`, extra single-child depth | fold (for chrome identity only) | `saved_group_boundary` | `shell-wrapper-shared-header-template.php`, `entry-chrome-wrapper.php`, `post-chrome-wrapper-encoding-identity.php` |
| `WordPressSitePlan/ListingQueryLoops` :: `wrapperAttributes()` | listing item wrapper attrs | preserve on the query-loop template | | `wrapper-class-post-date.php` |

## Stage 2 candidates seen while mapping

- Class-name structure signals: `has_structure_signals_without_proof`,
  `structure_signals` (wrapper retention), `block_identity_or_box`, and the
  search/cart tokens in `EmptyInteractiveFeatureShell`.
- Repeated runtime-target checks: `runtime_dom_target` appears in all four
  `WrapperDecision` tables and in about six local sites.
- Repeated geometry neutrality: `RenderNeutralElements` is consulted directly
  by five converters. It is the geometry stage's fallback input and should be
  read through one guard.
