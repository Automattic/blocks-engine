# Declaration-addressed generated controls

Refs #3689. This is the first slice, not the full site-wide stylesheet architecture.

`HtmlCompilation` finalizes generated `control` and `native-button` identities after author projection and every support-style family has contributed. The source-context markers used during selector matching are provisional bindings, not published CSS identities. There is no option to publish the old page-context identities.

The published address hashes the normalized emitted rule sequence for that control. It includes selector surfaces (wrapper versus generated descendant, pseudo states), enclosing layer/media/container/supports conditions, stylesheet placement, frontend/editor target and link media. Asset paths, page names and unrelated declarations are not inputs. Normalization removes declaration-boundary whitespace and property-name case; known independent declarations commute. Fallbacks, shorthand/longhand order, custom-property case, strings, importance and competing rule order remain significant. Conservative normalization may retain distinct addresses for equivalent but differently written CSS.

The finalization boundary rewrites block attributes, serialized markup, shell artifacts, stylesheet projections, support assets and compilation references together. Generated stylesheet hashes, lengths and content-addressed paths follow the rewritten bytes. Public result schemas do not change; generated class names are not durable external identifiers.

Existing shared-chrome extraction then lifts identical generated rules once. The multi-route fixture in `tests/fixtures/declaration-addressed-chrome.php` exercises this through `HtmlTransformer` and `ArtifactCompiler`: independent headers have identical bytes, the site has one header part, generated header rules have one shared owner, and route stylesheets contain no header rules. Its browser check compares source, per-page baseline, shared frontend and editor-canvas styles at 390, 768 and 1440 pixels, and around its source breakpoint. Browserless environments still exercise the structural and ownership contracts.

## Remaining slices

- Extend declaration addressing beyond control/native-button support classes; source selector bindings still need their own matching identities.
- Replace the broader route stylesheet/activation architecture with one site stylesheet while preserving the complete source cascade and conditional order.
- Remove frozen responsive geometry and unsourced importance; add Tailwind navigation/CTA, responsive embed and stacking `fr` grid fixtures.
- Express active-link and other genuine route differences as block settings or template-part variants.
- Enforce importer-only selectors and prune every dead class/rule across the full compilation, including Site Editor template isolation.
