# #3279: retained control-label state, not per-page translation URLs

This is the investigation/design-stop outcome requested in
[issue #3279](https://github.com/Automattic/blocks-engine/issues/3279), not a
shared-header implementation. Do not close the underlying behavior as fixed.
Findings were posted in [the issue comment](https://github.com/Automattic/blocks-engine/issues/3279#issuecomment-6349556240).

## Evidence and scope

Read-only provider evidence `securetia` (tree SHA-256
`41023be2353545ce6766c8b0e014c0e55edd59980ea58cc1e976bdf1a044a0ef`)
contains 50 captured HTML documents, 18 exported post-content files, seven
post-content-only theme templates, and no parts directory. The manifest declares
51 desired page/post records, including a synthesized route. The original issue's
27-page count is not the number of exported page files supplied here.

The original import used php-transformer 0.44.102. A fresh compilation of all
50 captured documents and their supplied assets in this checkout also produces
**zero template parts** (51 planned records). No browser or WordPress is needed.
The final header diagnostic is:

```
wordpress_site_plan_shell_retained_ambiguous
provenance.reason = route_template_ambiguous
message = header shell exclusions cannot be assigned distinct route templates.
```

This is more specific than simply saying every header has a unique identity.

## Header diff: the URL hypothesis is false

Extract `<header ...>...</header>` from every capture and compare the ordered
`href` lists. There is exactly **one href list per language population**:
11 default-language documents, 19 English documents, 20 Portuguese documents.
Translation switchers point to language home routes, not each page's translation.
Logo and CTA targets likewise do not vary within each population.

For example, `index.html` and `nosotros/index.html` both have 29,481-byte
headers and identical link targets. Their markup differs in the selected menu
wrapper (`item-content-wrapper--active`) and the anchor's data attribute
(`navigationblock-page-nosotros` versus `navigationblock-page-active-nosotros`).
`vulseek/index.html` selects a different wrapper/anchor and also lacks empty
`style=""` attributes on checkbox inputs. Desktop and mobile duplicate these
menus, but there is only one header landmark per document; this is not #3267's
nested-header problem.

Across languages the differences are substantive, not just state: translated
labels and data attributes, a different Services menu structure, different CTA
text and targets, and different spacing. For example, default versus English
Careers changes `--link-spacing` from 44px to 38px, `--element-spacing` from 25px
to 42px and mobile logo width from 148px to 147px. These cannot become one
language-independent part by ignoring hrefs.

## Trace through the existing primitives

`ShellExtraction::shellCandidates()` finds the landmark; the supplied imported
post-content files each produce exactly one non-overlapping nested header
candidate. Normalizing those 18 supplied files produces 14 identities: the
largest cluster has four members (Home, thank-you, landing, credential report),
the English resting cluster has two (English home and blog), and twelve are
singletons. These are post-import identities, not a reconstruction of the
original compiler's pre-import route map.

Diffing normalized Home and About candidates pinpoints retained state in both:

- `authored-input.labelMarkup`, containing the active wrapper and data attribute;
- the corresponding saved `<label>` HTML;
- the icon color, `var(--nav-link-text-color)` versus
  `var(--nav-link-text-color-hover,var(--nav-link-text-color))`.

The input is a captured native checkbox wrapping a menu label. Its descendant
links are retained label markup, **not core/navigation-link blocks**.
`normalizeNestedChromeMarkup()` delegates to `withoutCurrentNavigationState()`;
that primitive restores peer presentation for native navigation links, but does
not give arbitrary retained label descendants a route-state binding. Generic
block-comment canonicalization does not erase `labelMarkup` or authored data
attributes. The resulting state differences therefore remain identity differences.

`sharedShells()` hashes area plus normalized identity, selects a dominant cluster,
and plans exclusions. With the full capture, it reaches the exclusion-template
check and stops: excluded multilingual routes have colliding terminal slugs.
Examples include default/English/Portuguese `vulseek-pricing` (all page records),
default/Portuguese `h4ck3d`, and English/Portuguese `blog`. The override key is
`page-` plus slug (or the equivalent single-post key); two excluded routes claim
the same key. The planner retains the entire area rather than assign the wrong
header to a route. This is the immediate zero-part decision in the fresh full
compilation. An identity-only patch would not address this gate.

For comparison, passing just the 18 exported post-content documents to shell
planning without runtime declarations allows a four-page dominant header. That
is not the original import replay: it lacks captured routes, pre-import URLs,
entities and compiler context. It explains why exported fragments alone cannot
establish the full-import decision.

## Neutral three-page reduction

`tests/contract/fixtures/retained-label-shell/` contains only Home, About, Work,
neutral markup, and captured native-control evidence. Each page has one header,
three checkboxes whose labels contain links, the same hrefs, and a different
selected item's class/data attribute. No source assets, brand text, platform
knowledge or original evidence files are in the fixture.

`tests/contract/retained-label-shell.php` compiles those pages with ArtifactCompiler
and checks the discovered candidates and normalized identities. It isolates the
upstream grouping problem; three singleton identities cannot reproduce the full
capture's later exclusion-key collision as well.

| Input | Header candidates | Identity clusters | Shared header parts |
| --- | ---: | ---: | ---: |
| Selected retained labels | 3 | 3 | 0 |
| Same pages with only selected state removed | 3 | 1 | 1 |
| Full captured site, fresh compilation | 50 | multiple | 0 |

The reduced failure signature is `retained/non_equivalent`, not missing or
nested landmarks. Nine authored controls survive conversion; each selected-state
page retains its one active label. The control compilation proves that discovery
and retained controls alone do not prevent sharing. A separate href-only
three-page probe confirms that genuinely varying unbound URLs are also retained,
but that hypothetical gap is not the cause in these source headers.

These are characterization tests of the design stop, not fail-before/pass-after
regression tests for a behavior change. No extraction code has been changed and
there is intentionally no claim of improved rendering or header counts after a
fix. A future implementation must replace the characterization expectations with
sharing plus route-state round-trip assertions.

## Proposed approach / design decisions

1. Extend the existing navigation route-state representation and renderer to
   cover retained control-label descendants, using structural anchor ownership
   and cross-page resting peers. Preserve checkbox behavior and restore selected
   class, data attributes and descendant presentation on the correct route.
   Update both serialized attributes and rendered HTML, not only shell identity.
   Do not special-case this site's classes, framework attributes or language URLs.
2. Keep genuinely different language/appearance shells as shared variants. Decide
   how variant identity maps to native WordPress templates where source routes
   have the same terminal slug; extend the existing route/placement contract
   rather than introduce a second routing system or silently share one locale.
3. Only then relax shell identity for state whose render-time restoration is
   guaranteed. Test retained labels, translated variants, duplicate source slugs,
   unknown/new routes, and edited menu links. If truly varying targets are also
   supported, represent them as generic route-owned values through existing
   runtime binding primitives, with an explicit fallback policy—not URL-pattern
   guessing or a language-plugin dependency.

This needs a route-state/placement design decision rather than an href-stripping
patch. The requested stop after reduction is appropriate: ignoring these
attributes would freeze or discard real state, and normalizing multilingual
headers together would discard actual content and layout differences.

AI disclosure: model `cook-heavy` (provider `roadie`), using the Pi coding agent,
inspected the evidence, traced shell decisions, built and tested the neutral
reduction, and drafted this report.
