# Bedford visible-text audit (#7523)

## Evidence and method

Frozen source: `~/Studio/loop-runs/corpus/squarespace-bedford/capture`.
Existing imported comparison: `~/Studio/loop-runs/squarespace-bedford-s3-p7-eval/parity-capture/compare/materialization/report.json`.
Compared each score's source/liberated typography strings and text character counts. Confirmed the otherwise invisible separator differences by inspecting source HTML and browser `innerText` on the frozen website and existing imported site. This is not a fresh import or an acceptance measurement.

The comparison uses the desktop source boundary for both desktop widths; standalone reference JSON at 768 has different responsive counts, so it must not be substituted for the comparison's source evidence.

## Per-route and viewport differences

Deltas below are imported minus source. Each column names an actual comparison cell.

| Route | 390 | 768 | 1440 |
| --- | ---: | ---: | ---: |
| `/` | 0 | +2 | +2 |
| `/contact-bedford/` | 0 | +2 | +2 |
| `/landing-bedford/` | 0 | +2 | +2 |
| `/news-bedford/` | 0 | +2 | +2 |
| `/projects-bedford/` | 0 | +2 | +2 |
| `/read-me-bedford/` | 0 | +2 | +2 |
| `/take-action-bedford/` | 0 | +2 | +2 |
| `/what-we-do-bedford/` | 0 | +2 | +2 |
| `/news-bedford/2014/1/23/appalachia/` | +7 | +9 | +9 |
| `/news-bedford/2014/1/23/get-out-there/` | +7 | +9 | +9 |
| `/news-bedford/2014/1/23/why-deserts-matter-too/` | -15 | -13 | -13 |
| `/partners-bedford/` | -4 | -2 | -2 |
| `/home-alt-bedford/` | -2 | 0 | 0 |

### Shared +2: footer, not header or hidden text

No extra words. `#siteInfo` contains adjacent `.site-address`, `.site-phone`, and `.site-email` elements with no intervening source text nodes. The copy inserts normalized paragraph separators after `459 BROADWAY, NEW YORK` and `(212) 555-0123`. At mobile the source already has line boundaries, masking the discrepancy.

Owner: `StructuredInlineItemLowering` turns each phrasing item into a paragraph, even when its display is inline. Inline paragraphs still introduce `innerText` paragraph separators. The fix keeps genuinely inline items together in editable RichText, retaining independent carriers for atomic inline-block items. No site-specific selectors are used.

### Blog posts

All three gain `Submit` from the importer-generated form action for the source `.comment-form`. That source form contains only a textarea; `Preview` and `Comments Restricted` are sibling controls outside it. The new button contributes seven normalized characters including its separator and explains the extra action-row height. This is not date/byline metadata.

The middle post also loses `Newer Post` and `Older Post` (22 characters including separators). Source: `.pagination .newer .prev-label` and `.pagination .older .next-label`, outside the anchors. `NavigationPattern` flattens this landmark to only its link labels. The fix defers directional (`rel=prev/next`) article navigation to ordinary block conversion so the captions survive.

### Partners

Four missing spaces: `First Name (required)`, `Last Name (required)`, `Email Address (required)`, `Organization Name (required)` become `First Name(required)`, etc. Source elements are the form caption/title containers and their `.description.required` spans. Source block layout creates a text boundary; the importer emits label and required marker inline without one. The desktop +2 footer discrepancy offsets two of the four missing spaces. No words are missing.

### Additional cell

Home Alt has a separate slideshow discrepancy: imported typography includes `Slide 1 of 4` instead of the source `A better world`. Equal character counts on desktop do not prove equal visible strings. This candidate does not address that slideshow behavior.

## Scope and checks

Public-interface regression tests added:

- `tests/unit/inline-item-text-boundaries.php`: adjacent inline items, responsive display, independent atomic items.
- `tests/unit/article-pagination-visible-captions.php`: directional article links retain surrounding captions.

Narrow checks passed: both new tests, `exact-fit-inline-flow.php`, and `form-visible-required-asterisk.php`.

Remaining work: importer form seeding must not invent a submit absent from the source, and required-marker materialization must preserve the source text boundary. Those owning importer changes and their public-interface tests are not included in this checkout. The task is consequently only partially delivered.

## Measurement status

Existing baseline: 0/39 passing; text_content 29, pixel_region:core/group 36, text_position 35, image_geometry 18, page_height 17 (issue baseline). No after counts are claimed: a new corpus import, two-replay Bedford measurement, and eight-site non-regression measurement have not been run. Homeboy owns the declared Composer verification gate.
