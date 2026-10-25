# WordPress Site Plan v3

`blocks-engine/wordpress-site-plan/v3` is a destination-independent materialization contract. A consumer resolves it with `WordPressSitePlanResolver` and combines the resolved plan with its own materialization receipt. Destination IDs, paths outside declared writes, and product report formats remain consumer-owned.

## Omitted Pages

A non-entrypoint page that cannot be compiled is omitted rather than failing the plan. Each one is a `page_compile_omitted` warning in the plan's `diagnostics` (unit `page`, with `source_path`, `reason_code` and `message`); the page is absent from `pages` and `routes`, and links to it are reported as unresolved navigation links. See [omitted-page-receipt](contracts/omitted-page-receipt.md).

## Degraded Units

A problem confined to one asset or binding degrades that unit and records a warning in the plan's `diagnostics`; it does not fail the plan. The strict validator still rejects a plan that carries the condition.

- `wordpress_site_plan_colliding_asset_target` (unit `asset`): two assets resolved to one theme target. Identical bytes are written once (`reason_code` `identical_content`); the later occurrence is kept so its cascade position wins. Different bytes give the later asset a deterministic numbered target such as `name-2.css`, with a token and reconciliation identity derived from it (`reason_code` `colliding_asset_target`). The row names `source_path`, `colliding_source_path`, `target_path` and `resolved_target_path`.
- `runtime_entity_binding_unanchored` (unit `binding`): a runtime entity binding names a block the page does not emit (`anchor_not_emitted`) or a page the plan does not emit (`document_not_emitted`). The binding is dropped and the block stays static; the entity keeps its other bindings. An anchor that is emitted but cannot be told apart from an identical block still fails closed.

Rows with identical fields are reported once with an `omitted_count`. A plan that carries any of these findings has `quality.status` `success_with_warnings`, the same rule `WordPressSitePlan::withFindings()` applies to findings measured after the plan is built. The `document_head_*_dropped` findings in [document-head-runtime](document-head-runtime.md) follow the same rule.

## Document Metadata

Every page and template part has `document_metadata`. It is normalized compiler output, not source HTML:

```php
array(
    'source_context' => array('source_path' => 'nested/about.html', 'kind' => 'html'),
    'title' => 'About',
    'title_declaration' => array('order' => 0, 'placement' => 'head'),
    'meta' => array(),
    'links' => array(),
    'scripts' => array(),
)
```

`meta`, `links`, and `scripts` are ordered source rows. Their zero-based `order` equals their array index. `placement` is `head` or `body`; `title_declaration` always has `order: 0` and `placement: head`. `source_context` identifies the compiler document that supplied the declarations.

### Native post field handoff

A post's `metadata.excerpt`, when declared, is the complete authored excerpt
string. Consumers persist it as `post_excerpt`, independently of
`canonical_block_markup` / `post_content`, including an explicitly empty value.
They apply normal WordPress slashing at insertion and preserve the entire value.
Compact listing slots use native `core/post-excerpt` only when their description
is proven against that post's source declaration; full-content listings retain
`core/post-content`. The block's length is derived from the complete source
descriptions, rather than applying the default crop to authored excerpts.

`metadata.featured_image`, when declared, hands a compact listing card's image
to the post it summarizes: `asset_token` names the plan asset, `alt` is the
source alt text, and `target_path` is that asset's declared theme write.
Consumers attach that file and set it as the post thumbnail. The card's image
slot is then a native `core/post-featured-image` carrying the source width,
height, scale and class, so each post renders its own image. A card image that
is not a plan-owned asset is not handed off.

`metadata.post_meta` is an optional map of nonempty string keys to string values.
Consumers pass the map through native `meta_input` with the same WordPress
slashing contract. Linked listing metadata uses these post-owned fields through
native `core/post-meta` block bindings. The generated theme bootstrap registers
the string fields with REST visibility, so the frontend and Gutenberg read the
same post-owned value instead of repeating the first card's metadata.

## Page Routes

Each page has `route.path`, `route.parent_path`, and `route.slug`. The route is derived
from the normalized source path: root `index.*` is `/`, `nested/index.*` is `/nested`,
and `nested/about.*` is `/nested/about`. `operations` first provides topologically
ordered `create_page` rows, including parent source references and reconciliation
identities, then the `site_reading` front-page operation. Missing directory parents are
declared synthetic pages, so materializers create the same hierarchy without inferring
or manually reparenting pages. A physical directory index replaces the corresponding
synthetic parent; route collisions fail closed. A safe lowercase `metadata.route_path`
is preserved when the source contract declares an explicit canonical route.
A percent sequence inside a source path segment is the author's title punctuation that
the source CMS slugified into a filename, so each segment is decoded once before it is
slugified and an encoded path resolves to the route its decoded literal spelling
produces. That single decode may not give a path structure the undecoded path did not
have: an encoded separator, dot segment, or NUL byte fails closed on the same rules
that reject their literal spellings.
The canonical route map is computed before page, link, metadata, operation, resolver,
report, and script-scope projection. It rewrites relative and root-relative document
links while preserving query and fragment suffixes; declared asset references continue
to use asset tokens rather than page routes.

## Content Decisions

Each page row may add `content_decision` with schema
`blocks-engine/content-decision/v1`. Its `state` is `declared`, `inferred`, or
`defaulted`; `provenance` identifies an explicit declaration; and bounded
`evidence` records normalized source signals. `post_type` remains the
consumer-facing materialization intent. Explicit frontmatter `page` and `post`
values are declared decisions. Otherwise article semantics, publication dates,
and dated `/{YYYY}/{MM}/` routes infer `post`; unresolved documents default to
`page`. Publication timestamps are RFC3339 UTC strings in
`publication_timestamp` and evidence rows. Evidence accepts only complete
`YYYY-MM-DD` dates or ISO datetimes with an explicit `Z` or numeric offset, so
projection never depends on the runtime clock or timezone.

Routes remain canonical source-document and link-reference paths. Page routes
form page hierarchy and may receive synthetic parents. Post routes never create
or inherit page parents: `parent_source_path` is empty, and a WordPress
materializer applies its configured post permalink policy. This preserves the
source route for deterministic references without claiming it is a post
permalink.

## Author Archives

The additive, required `author_entities` list contains at most 256 proven authors:

```js
author_entities: [{
  kind: 'author',
  slug: 'adam', // nicename: ^[a-z0-9][a-z0-9-]{0,49}$
  name: 'Adam Zieliński',
  membership_source_paths: ['stories/first.html', 'stories/second.html'],
  archive: {
    source_path: 'author/adam/index.html',
    source_route: '/author/adam',
    presentation_markup: '<!-- wp:query ... -->...',
    query: { post_type: 'post', author: 'adam' }
  },
  evidence: { membership: true, name: true, archive: true }
}]
```

The slug equals the archive route leaf. The name is the exact archive heading
name (a single named element beside prefix text, such as
`Author: <span>Adam Zieliński</span>`, names the author). At least one canonical
post listed by the archive must link back with that exact name. Membership
contains posts only, is unique and sorted, and is bounded to 10,000 source paths.
Link resolution, source-file aliases, same-origin restrictions and heading
extraction are shared with taxonomy proof. Matching numbered archive captures
can contribute reciprocal members; pagination captures remain suppressed views.

Duplicate slug claims produce `wordpress_site_plan_author_archive_ambiguous_slug`;
a post claimed by multiple proven authors produces
`wordpress_site_plan_author_membership_ambiguous`. Conflicting authors are
withheld entirely, without guessing either identity or membership. Unproven
archives remain ordinary documents with
`wordpress_site_plan_author_archive_unproven`. A presentation that cannot become
a native inherited query keeps the author and membership, omits
`archive.presentation_markup`, and emits the info diagnostic
`wordpress_site_plan_author_archive_presentation_template_hierarchy` with the
source path and route. No per-author template is emitted; WordPress's template
hierarchy serves the archive.

Proven archives follow the category presentation path into native WordPress
`author` fallback template and `author-<slug>` source-specific templates, retaining
source-owned shell and head resources. As with category entities, the canonical
archive document remains source evidence in the plan, but its source path and
pagination sources are absent from `pages`, `routes`, and page operations. Consumers create users
with the nicename and display name and assign each member post to that user.
Core's author rewrite owns `/author/<slug>/` and pagination; no custom author
rewrite is registered. New posts then appear through the inherited native query.

## Taxonomy Archives

The additive `taxonomy_entities` list contains only proven native term/archive
relationships. The producer recognizes a captured category document only when
its canonical route has category-archive form, its visible heading names a
bounded slug on that route, it links to at least one canonical source document
classified as a post, and those article documents link back to that exact route
with the same visible term name. Thus the route is one evidence source, not a
guessing rule. Uncorroborated collection candidates remain ordinary documents
and produce `wordpress_site_plan_taxonomy_archive_unproven` when they list
posts. Route or slug spellings outside the canonical term vocabulary produce
`wordpress_site_plan_taxonomy_archive_unsupported_spelling`, and two
corroborated archives claiming one term slug produce
`wordpress_site_plan_taxonomy_archive_ambiguous_term`; neither constructs an
entity that could fail the whole plan. Link evidence counts only when it is
root-relative or absolute on the declared source origin, so an unrelated
external link can never corroborate local term membership.

Hierarchical routes (`/category/parent/child`, at any depth) use the leaf as
`slug` and carry an optional `parent` string naming the immediate parent's slug
in the same taxonomy. Top-level entities omit `parent`. The parent must itself
have a proven archive at the immediate ancestor route; otherwise the child
stays unproven with `wordpress_site_plan_taxonomy_archive_unproven_parent`
naming the missing parent route. Presentation failure does not invalidate a
proven parent or its descendants. Leaf slugs are unique across the taxonomy, not just
within one parent. Child membership and numbered captures use the same proof
as top-level archives. Validation rejects absent, self-referential, malformed,
or route-detached parents, independently of entity order.

The original two-member threshold (introduced in `0535c3e5`) had no documented
single-member false-positive case. Reciprocal matching-label links, canonical
post classification, and same-origin checks remain mandatory. Single-card
query conversion is allowed only for proven taxonomy archives; ordinary
listing inference still requires multiple cards.

SSI's current `materialize_taxonomy_entities()` does not read `parent`: it
inserts terms with only `slug`. To persist this hierarchy, SSI must resolve the
optional parent slug to a term ID in the same taxonomy (parents first, or in a
second pass), pass that ID as WordPress's `parent` argument on insertion, and
reconcile existing terms' parent IDs with ownership/conflict protection,
rollback, and receipts. Missing `parent` means a root term. The plan field is a
slug, not a WordPress database ID. SSI's existing exact archive-route rewrites
already accept nested source paths and need no route-contract change.

Each emitted `taxonomy_term` records its bounded canonical post source paths,
the archive source identity and route, optional captured presentation markup, and native
query scope (`post_type`, taxonomy, and term). A consumer materializes the term
and memberships and uses the archive document as presentation evidence, not as
a frozen page owner. The generated theme scaffold retains an exact rewrite and
term-link mapping for the source route, and rewrites the source route's
`/page/N` suffix onto the same native term query with `paged` for bounded
positive page numbers, without changing the destination's global category/tag
base. Future posts belong to WordPress's native archive query.

If presentation cannot become an inherited Query Loop, the term keeps its name,
parent, membership, and pagination ownership but omits `presentation_markup` and
its per-term template. The info diagnostic
`wordpress_site_plan_taxonomy_archive_presentation_template_hierarchy` records
the source path and route; WordPress's template hierarchy serves the archive.
Neither projected nor fallback archive sources materialize as plan pages, and
page ancestry skips archive sources so every nonempty `parent_source_path`
names a materialized page.

The producer-only HTTP acceptance serves a resolved generated theme in a
disposable WordPress 7.1 runtime without SSI. It follows the rendered base-page
Next link to `/page/2/`, follows Previous back to the base route, and checks the
native query contents and unrelated-post exclusion:

```sh
BLOCKS_ENGINE_TAXONOMY_HTTP_WORDPRESS_VERSION=7.1 \
BLOCKS_ENGINE_TAXONOMY_HTTP_EVIDENCE=/path/to/durable/evidence \
WP_CODEBOX_CLI=/path/to/wp-codebox \
node tests/wordpress-site-plan-taxonomy-http.mjs
```

An HTML detail document with exactly one valid schema.org `Event` JSON-LD claim
can add a provider-neutral `entity_collection:events` declaration with
`generic/events/v1` entities. Each entity retains `source_path`, its canonical
`source_route`, name, ISO `start_date` and `end_date` with explicit offsets,
optional description, venue name/address, and HTTP(S) or portable root-relative
image where observed. The existing page
continues to own the route; event metadata does not create another page or
assume tickets, a provider, or a time-relative publication policy. Malformed or
ambiguous event evidence leaves the source document as an ordinary page.

`operations.kind` remains `create_page` for v2 compatibility. Its additive
`post_type` is authoritative for materialization, so consumers create the
declared document type rather than assuming a page. For WordPress runtime
scopes, materializers persist each row's existing `reconciliation_identity` as
`_blocks_engine_reconciliation_identity`; post and page asset guards query that
value. This distinguishes duplicate post slugs and materialized page slugs that
diverge from the planned route (WordPress reserves numeric hierarchical slugs)
without assigning page ancestry or depending on a permalink structure.

A page-scoped stylesheet asset can list several page `scopes` (and document
`stylesheet_instances` for each of them). Page-scoped stylesheets with identical
bytes and delivery contract (media, target, placement, parent stylesheet,
directory) are published as one asset when that leaves every page's enqueue
order unchanged: a page's slice of a shared stylesheet, its compatibility
replay or its editor state is then one file for all the pages that produce it,
instead of one copy per page. Consumers must enqueue such an asset for each
scope, as they already do for an asset scoped by the pages that link it.

## Asset consumers and destinations

Every asset row carries `consumers`, `destination` and `theme_write_reasons`, derived from where its token lands in final block markup and stylesheet writes, not from source selectors:

- `consumers[]`: `{kind: "block", document_kind, document, block_name, block_path, attribute_path, count}` for pages, templates, template parts and synced patterns (`attribute_path` is `url` for a core/image `<img src>`, `innerHTML` for saved markup, otherwise the dotted attribute path), or `{kind: "stylesheet", target_path, count}` for CSS writes.
- `destination`: `media_library` when any block consumes the asset, otherwise `theme`.
- `theme_write_reasons[]`: for `media_library` assets, one `{reason: "attachment_url_required", target_path, count}` per stylesheet consumer. Theme CSS has no portable reference to uploads, so the theme write is kept unless the publisher resolves the asset.

`WordPressSitePlanResolver::resolve()` accepts `attachment_resolutions` (`token => {id, url}`, positive integer id, absolute http(s) URL) for `media_library` assets only. A resolved asset loses its theme write; every reference, including stylesheets, resolves to the attachment URL; and image blocks carry core attachment attributes: `id` + `url` (core/image, plus the `wp-image-<id>` class) and core/cover, `mediaId` + `mediaUrl` (core/media-text), and `style.background.backgroundImage` `{id, url, source: "file"}`. The resolved plan records the map under `resolution.attachment_resolutions`. Without attachment resolutions the output is byte-identical to theme-URL resolution.

Meta rows preserve `charset`, `name`, `property`, `http_equiv`, and `content`. Link rows preserve `rel`, `type`, `media`, `integrity`, `crossorigin`, `referrerpolicy`, `as`, `fetchpriority`, and `sizes`. Script rows preserve `type`, `integrity`, `crossorigin`, `referrerpolicy`, and `fetchpriority`, plus independent booleans for `async`, `defer`, `module`, and `nomodule`. Explicitly present empty values are retained as `''` so consumers can distinguish them from absent attributes, except `crossorigin`: its empty or boolean HTML state is normalized to `anonymous`, matching browser CORS semantics. `effective_loading` records browser loading semantics: `async` wins over `defer`; non-async module scripts are `defer`; other scripts are `blocking`. Inline scripts carry `source_kind: inline` and `body_hash`, not their source body.

URL-bearing link and external-script declarations contain either an explicit absolute or protocol-relative `url`, or an `asset_reference` token. Local artifact URLs must use `asset_reference`; undeclared local URLs are invalid. Resolver output adds `resolved_url` for each `asset_reference`. That URL is exactly the URL of a declared resolved theme-asset write. Explicit external URLs remain unchanged.

Document metadata is canonical runtime input as well as reporting data. The generated
theme scaffold registers supported local-write and external HTTP(S) script declarations
once, preserves their normalized loading and tag attributes, and enqueues them only for
their source scope. Entry-page declarations run on `is_front_page()`, other page
declarations resolve the queried page by its persisted reconciliation identity
and fall back to comparing the page URI with their normalized source route path
when that meta is absent, and bound template-part declarations run
as global shell scripts. The scaffold preserves declaration order through scope-local
enqueue-hook priorities, without dependencies that would change WordPress loading strategy.

`reference_semantics.dynamic_script_references` and
`dynamic_client_assets.status` are both `proven` for fully materialized static script
declarations. They are both `not_proven` with `materializer_may_reject: true` when
inline source, an external or unsupported URL or contradictory declaration, an unbound template
part, dynamic import, script injection, or runtime URL construction prevents complete
proof. The accompanying diagnostics are the deterministic explanation. Consumers that
set `require_proven_dynamic_client_assets` are rejected only for that unproven state.
Supported external URLs remain in the generated scaffold for callers that accept the
runtime-reference risk, but cannot pass that proof gate.

## Shared regions and navigation ownership

Header/footer content ownership and its rendered position are separate decisions.
An extracted region with layout ancestors uses `inline_shared_shell`: the
template-part reference sits at its exact source position inside those ancestors.
For a page, the ancestors, the references and the chrome around the main content
live in the route's template. The generic `page` template carries the frame most inner pages share in structure (compared without per-document marker seeds and dialog ids), so a page made later in WordPress gets the site chrome. A page uses it only when its frame is byte-identical, because the seeded markers in a frame are what that page's own stylesheet targets. Any other page gets `page-{slug}`; the home page gets `front-page`, with
`post-content` in the gap, and the page keeps only the blocks between the header
and the footer (`ShellExtraction::hoistInlineShellFrames`). The theme drops Core's
post-content wrapper element for that marked block, so the rendered tree matches
the source. A page that cannot be split safely (two references to one region, a
`main` landmark in the frame, or a runtime binding in the frame) and every post
keeps its references in the page content. `placement.source_paths` accounts for the participating routes.
The shared part carries its authored landmark; the generated runtime unwraps
Core's template-part transport wrapper. This preserves direct-child CSS, flex/grid
participation, sibling order, and native disclosure containment without detached
selector or paint-order compensation. Later article-template projection retains
these occurrence-owned regions. Top-level shells can still bind through templates;
heterogeneous responsive bundles retain their explicit viewport-partition contract.

Navigation entities use the producer-owned `explicit_refs/v1` contract declared in
`reference_semantics.navigation_entities`. Every factored `core/navigation` host
has a `ref` of `{{wordpress-site-plan:navigation:navigation-<16hex>}}`, and retains
its own overlay, layout and class attributes. Entity content preserves hierarchy,
full destinations, link behavior, rich labels and item presentation. Different
presentation remains separate unless its serialized content is equivalent.
Native `core/details` owns its panel even when a desktop sibling repeats the same
menu; capture-specific class names do not determine this ownership.
Post article chrome (one single template) and listing cards (one query loop
template) keep one copy of repeated markup. They do so only when the kept copy
renders the same menus as every dropped copy; otherwise the posts or the listing
stay as captured, so no menu entity is left without a reference.

Synced patterns follow the same token-and-entity model. `core/tabs` admits only a
tab list and its panels, so content a captured selectable set leaves unchanged is
repeated in every `core/tab-panel`. A maximal block subtree whose serialized
markup is identical at the same position in every panel of one tabs block is
declared once in the optional `synced_patterns` group as
`{identity, title, canonical_block_markup, source_path, provenance}`. It must
render in every panel: a subtree a panel hides (a `hidden` attribute, inline
`display:none`, or a class some stylesheet rule hides) stays inline, as do the
panel's whole content and single text blocks (paragraph, heading, list). Each
panel references it as `<!-- wp:block {"ref":"{{wordpress-site-plan:pattern:pattern-<16hex>}}"} /-->`,
where the token is `pattern-` plus the first 16 hex of `identity`
(`SyncedPatternProjection`). The title is the subtree's first heading, else the
nearest heading before it in the panel, else the tab list label, else `Shared
content`. `source_path` is `wordpress-site-plan/synced-patterns/<token>`, and
`provenance.occurrences` records each page, panel count and in-panel block path.
The group and `reference_semantics.synced_patterns` (`explicit_refs/v1`) are
present only when a pattern is declared. `core/block` renders pattern content in
place without a wrapper on the front end, so panel-ancestor author styles still
apply; in the editor its wrapper takes no box and the editor restatement of child
combinators tolerates it.

Runtime bindings inside a synced pattern belong to the pattern document, like
bindings in a shared template part. The final `canonicalEntityBindings()` pass
matches bindings on the page markup before the projection, then rebases each
one: a binding in the first panel's copy moves to the pattern `source_path` with
`occurrence` and `position` recomputed on the pattern markup, the copies in the
other panels are dropped (with entities left without bindings, whose fallback
identities the kept entity lists in `replaced_fallback_identities`), and the
remaining page bindings renumber on the final page markup. Consumers apply
bindings to synced pattern documents as they do to template parts.

Consumers persist declared entities and resolve their references to integer IDs.
They do not rediscover menu equivalence. The plan validator checks declarations
and reference ownership together; the resolver projects entity asset references
into `resolved_block_markup` without introducing destination IDs.

## Design system

The theme's palette, type scale, font families, spacing scale and content width
come from what the documents render, not from every literal the stylesheets
mention (`WordPressSitePlan\DesignSystem`).

The HTML transformer records each document's rendered usage as
`compiled_site.pages[].design_usage` (`blocks-engine/design-usage/v1`). It reads
the resolved styles the conversion already computed, with inheritance and user
agent defaults. Usage holds text colour, size and family per role (body, link,
heading, button), the background behind the text, backgrounds, spacing and
centred `max-width` containers. `class_styles` records, per class list, the
colour, background colour, font size, font family and padding sides the
elements with those classes render, with the values media queries give them,
or `!` when a value cannot be bound. Each is weighted by element count and by text
area (characters × size²). Documents are summed with integer weights, so route
order cannot change the result. Each document's text and background weights
are first scaled to the same total (1,000,000), so every route counts the
same: one long route in its own style cannot outweigh the shared header and
footer and the other routes.

Clustering is deterministic:

- A preset binding never changes a painted colour: a block attribute, stylesheet declaration or element style binds a colour only to a token whose value is that exact colour, so an import paints 1:1.
- Opaque colours within CIE76 ΔE 2.3 (one just-noticeable difference) group together for ranking.
- Colours are ranked by prominence: the mean of their share of the document's text and background area and their share of its coloured runs (text runs and painted elements). A colour reached through an authored custom property (`color:var(--cyan)`) counts like a literal. An accent on many small elements (bullets, eyebrow labels) therefore ranks beside a grey on a few long paragraphs, though it covers far less area.
- A group within ΔE 5 of a more prominent one joins it for ranking however much it is used, so a near-duplicate (`#1a1a1a` beside `#212121`, ΔE 3.5) does not compete with a distinct colour for a role. Grouping only names the roles (`base`, `contrast`, `primary`, `secondary`, each its group's heaviest colour) and breaks ranking ties. Every other painted colour takes an `accent-N` token of its own exact value by its own painted weight, heaviest first, whatever group it ranks in: a heavily painted near shade (`#ffffff` cards on a `#f7fafc` page, `#f6f8fa` sections beside a `#ffffff` card) keeps a token ahead of a distinct colour painted on one tiny element. On a tie a group's own colour goes before a near shade of another. The palette keeps at most 12; the painted colours past the bound are the lightest, keep their literal everywhere and are reported in the design-system report's `literal_colors` (and `literal_color_count` in the metrics), never bound to a near token.
- Font sizes within 0.5px merge. The scale keeps the 8 heaviest steps.
- Spacing merges only identical pixel lengths and keeps 10 steps.
- Font stacks group by primary family, ignoring case and quotes, and keep the most used stack. Only families some rendered text uses as its primary become presets. A stack with a generic primary (`ui-monospace`) is not a token. A primary family that is declared but not rendered is reported as unused. Fallbacks and `@font-face` names are not reported.

Roles make the slugs stable:

| Group | Slugs |
| --- | --- |
| Colours | `base` is the root background every document renders, else the background covering most text (within 90% of the heaviest, the first that has a readable text colour). `contrast` is the main text colour on `base`: among text colours with at least 10% of the text weight on `base` (or of all text when none is on `base`), the root text colour when it has a WCAG contrast ratio of at least 4.5 against `base`, else the candidate with the highest ratio of at least 4.5. A candidate under 4.5 is only used when none reaches it, and never under 3. `primary` and `secondary` are call-to-action backgrounds, then chromatic colours. Every other colour is `accent-N`. |
| Fonts | `body` is the root family every document renders, else the heaviest body-text family. `heading` is the family every heading renders, else the heaviest heading family. Each route's headings share one route weight, and within a route a heading weighs 3 (`h1`), 2 (`h2`) or 1 (`h3`–`h6`) times its font size over the root size, per heading: a blog index of many small entry titles does not outvote the display `h1`/`h2` other routes open with. A family other than the body's or a system UI stack needs 25% of that weight. A heading family identical to the body family is emitted once. |
| Sizes | `medium` is the body size. Smaller steps are `small`, `x-small`, …; larger steps are `large`, `x-large`, `xx-large`, …. |
| Spacing | `20`, `30`, … from smallest to largest. Core's default palette, sizes and spacing are turned off. |

Global Styles also get:

- the root background, text colour and family, when every document renders them alike and no media query varies them;
- element styles (`styles.elements`) for what every element of a kind renders on every document, as preset references:
  - `link`: colour, and `:hover` colour;
  - `button`: text and background colour, family, size and weight, and `:hover` text and background colour;
  - `heading`: colour, family and weight; `h1`–`h6`: colour, family, size and weight where the level differs from `heading`.

  Colours must be a token's own value (within ΔE 2.3), families a preset's primary family, sizes a preset size. A `:hover` value is the one the most specific `:hover` rule matching the element gives (an `!important` tie or a non-colour leaves it out), and counts only when every element of the kind has the same token. A property a media query can change for any element of the kind, on it or an ancestor (`md:text-5xl`, a dark-mode colour), is left to the source: an element style would override the variant. Button values are only kept when every `core/button` carries that property itself before binding, so no button is restyled; their colours then replace a colour projected from an authored `button` rule. Weights count the user agent's bold for headings, `th`, `b` and `strong`;
- `contentSize` and `wideSize`, from centred containers when no `main` rule sets them.

Every value is a `var:preset|…` reference. Because the roles follow the root,
the root background is `base`, its text `contrast` and its family `body`.

`PresetBinding` rewrites block markup wherever it enters the plan. A literal
`style.color.text`, `style.color.background`, `style.typography.fontSize` or
`style.typography.fontFamily` that matches a token becomes `textColor`,
`backgroundColor`, `fontSize` or `fontFamily`. It gets the matching `has-*`
class, and the redundant inline declaration is removed. Padding, margin and
block gap values become `var:preset|spacing|…`. Root custom properties
(`:root`, `html`, `:host`, also inside `@layer`) defined once with one value
are resolved first.

A value set only by author classes is bound the same way, from the block's
author class list and `class_styles`. Every element with that class list must
render the same value. Paragraphs, headings, groups, lists, quotes and columns
can get colour, font size, family and padding. Buttons can get colour and
padding. The author class and its projected rule stay. The preset wins because
core's preset classes are `!important` and spacing presets are inline. The
projected native-button rules step aside for `.has-text-color` and
`.has-background`, as they do for inline values.

A value stays literal (or with its class) when binding could change the
cascade:

- the inline style is not exactly what the attributes serialize;
- an `!important` rule for the property could match the same element, unless it excludes the preset class (`:not(.has-text-color)`);
- a selector reads the inline style text (`[style*=…]`);
- a `:hover`, `:focus` or `:active` rule sets the property on the element (a preset class is `!important` and would mask it). A button whose literal colour is the `button` element style's token, with a `:hover` element value, drops the literal and its `has-text-color`/`has-background` class instead: it renders through the element style and its `:hover`;
- a media query (breakpoint, colour scheme) gives the property a value matching another token, or redefines a custom property it reads;
- elements with the same class list render different values.

Page content, template parts, shell parts and navigation are bound alike.

`PresetBinding::bindStylesheet()` then binds the stylesheets the plan ships
(author CSS, projected class rules, engine support CSS), so links, navigation
items, states (`:hover`) and buttons whose colour only a rule sets follow the
palette too. A declaration keeps its selector, place and `!important`; only
its value changes, to the preset variable with the source value as fallback
(`color:var(--wp--preset--color--primary,#3b82f6)`), so it renders the same
until the palette changes:

- `color`, `background-color`, a `background` that is one colour, and a custom property whose value is a colour bind within the palette tolerance;
- `font-size` and `font-family` bind only when equal to the preset, so no layout moves;
- a `var()` resolves as for block values. A fallback (`var(--tw-text-opacity, 1)`) stands for a property every rule sets to that same value. A property a scope (`.dark`) or media query redefines keeps the declaration literal;
- `@font-face`, `@keyframes`, values already reading a preset and every other property keep their bytes.

`theme.design_system` reports the tokens and `metrics`, which the editability
report repeats as `design_system`:

- `preset_share`: the share of block colour, size, family and spacing attribute values that are preset references (zero lengths, `auto` and CSS-wide keywords are not counted);
- `restyle_coverage`: of the text and background colours on rendered blocks, the share a palette edit changes, because they come from a preset through the block's attributes, its classes' rules, an ancestor or the root styles (`RestyleCoverage`). Template parts count once per page. `restyle_text_elements`, `restyle_text_preset`, `restyle_backgrounds` and `restyle_backgrounds_preset` are the counts;
- `palette_size`;
- `unused_font_families`: declared primary families no rendered text uses;
- `theme_css_bytes`: every stylesheet the theme ships (author projections, per-page copies, shared chrome, engine support) plus inline block `style` attribute bytes;
- `unique_source_css_bytes`: the author's stylesheets as captured, each counted once (compiled-site CSS assets other than engine support, deduplicated by `source_hash`, using their `source_bytes`);
- `engine_css_bytes`: what the conversion adds rather than projects, engine support CSS plus inline block style bytes;
- `generated_to_source_css_ratio`: `theme_css_bytes` over `unique_source_css_bytes`, so a stylesheet shipped as three per-page copies reads as about 3.

`composer test:design-system-restyle` (`tests/integration/design-system-restyle.sh`) checks the same in WordPress and
Chromium. For the `tailwind-v3`, `tailwind-v4` and multi-route
`tailwind-shell` fixtures, it installs the theme and pages in a disposable
site, records every text element's colour, gives each palette entry a new
value in the user's Global Styles, and requires at least 80% of the text
elements to change colour (`RESTYLE_MINIMUM`). It needs `RESTYLE_WP_CLI`,
`RESTYLE_EVIDENCE_DIR` and `PLAYWRIGHT_MODULE`, and wipes the site's content.

## Reporting

`reporting` is a compiler-output summary:

```php
array(
    'source_documents' => array(
        array(
            'source_path' => 'index.html',
            'kind' => 'html',
            'body_format' => 'blocks',
            'block_document' => true,
            'provenance' => array(),
        ),
    ),
    'metrics' => array(
        'source_document_count' => 1,
        'block_document_count' => 1,
        'native_block_count' => 0,
        'fallback_count' => 0,
    ),
    'diagnostic_codes' => array(),
)
```

It provides generic source-document identity, native/block and fallback metrics, provenance, and diagnostic linkage. It intentionally excludes destination IDs, filesystem locations, and consumer report paths. A consumer can project its own stable report from the resolved plan and a receipt that confirms every declared write and page reconciliation identity.
