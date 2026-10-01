# Native collection owner acceptance evidence

This record anchors the owner-editing relationship repair in
[Blocks Engine PR #2404](https://github.com/Automattic/blocks-engine/pull/2404).
The published implementation is `99934bf191bbb5f78eaef7456837610d7ac38705`,
cherry-picked from the original scoped implementation `05c8fadb7`. Both commits
retain the implementation author's session attribution:
`ses_f0b8cad76ffeso9219OTUnTVru`.

## Verified authoring contract

The importer preserves the observed input type, including an explicit text input
and HTML's omitted-type text default. A source search input remains a search
input. Changing a field's appearance does not substitute global WordPress search
for the verified local predicate.

Each captured item has an opaque marker saved in ordinary native `className`
metadata. The collection stores category membership by that marker rather than
array position or question label. Editing an answer or moving its native block
therefore preserves its category association. Duplicate question headings with
different answers retain separate identities.

Unassigned owner-added items remain searchable in the captured, verified
full-collection category. Adding or deleting an item does not disable filtering
for the remaining collection. The editor explains this default.

## Actual WordPress editor proof

The public source fixture was
[Ward FAQ](https://ward-behavior-path.base44.app/faq). A fresh retained-source import
materialized one native `core/accordion`, 21 native accordion items, and editable
native answer paragraphs. Source category sizes were All 21, General 6, Services
5, Insurance & Billing 3, Referrals 4, and Careers 3.

The following operations used the real Gutenberg editor, saved its post through
the normal editor API, reopened the editor, and checked the actual frontend:

| Operation | Observed result |
| --- | --- |
| Reverse all 21 native items | Reversed saved marker order persisted; all six category membership sets remained identical. |
| Add one native item and answer | Saved/reopened item appeared in All only; an uppercase answer-only query returned exactly that item. Existing category sets were unchanged. |
| Delete one captured item | The remaining captured memberships and the new item's search continued working. |
| Type into an actual iframe RichText answer | Edited text persisted and became searchable while the answer was closed. |
| Save a visible author `+` | A `+` query matched exactly the edited item; unpainted zero-font, aria-hidden native icon text did not produce extra matches. |
| Replace a real native image with another imported image URL | Replacement survived save/reopen. |
| Reopen after each edit | Zero invalid blocks. |
| Restore the original content and ordering | Restoration verified. |
| Inspect the frontend field | Captured `type="text"` retained. |

After restoration, source/capture/native checks at 390, 768 and 1440 pixels passed
63 exact native answers and 126 category/query transitions with zero comparison
differences. The additional `+` query passed all 54 stage/category/width records.

## Neutral regression and reproduction

The browser regression first failed on the prior implementation: reordering two
items with identical headings selected the wrong category answer, and adding an
item caused the cardinality guard to stop refreshing the filter. The repaired
regression exercises reordering, deletion, new-item behavior, and answer edits
against actual browser DOM. PHP checks also verify saved native marker metadata
and item-key lookup when evidence ordering differs.

From `php-transformer`:

```sh
php tests/unit/collection-filter-block.php
node tests/unit/collection-filter-browser.mjs
php tests/unit/core-block-snapshot-validity.php
php tests/unit/captured-selectable-set-projector.php
php tests/contract/namespace-resolution.php
php tests/contract/layer-direction.php
```

The browser fixture accepts `PLAYWRIGHT_MODULE` when using an external installed
Playwright harness. The repository's existing browser CI job generates its PHP
fixture and executes this regression.

Retained acceptance artifacts are named `owner-item-identities.json`,
`collection-owner-e2e.json`, `search-e2e.json`, and
`collection-plus-owner-fixed.json`. The actual editor harness is
`ward-native-owner-identities.mjs`; it records all category ID sets, persisted
order, new-item search, deletion results, invalid-block checks, and restoration.

## Immutable integration revisions

| Role | Revision |
| --- | --- |
| Scoped published owner implementation | `99934bf191bbb5f78eaef7456837610d7ac38705` |
| Original owner implementation | `05c8fadb7` |
| Verified native integration with separately owned namespace/word-wrap dependencies | `fc11619bdd21` |
| Current Static Site Importer used for independent canonical intake proof | `337199072569` |

The source-shaped input fix was independently published as `723515592` in merged
[PR #2395](https://github.com/Automattic/blocks-engine/pull/2395). The parent
controller also reproduced the owner operations on its final integrated
candidate `8661262fe20a`. Those presentation integrations are evidence inputs,
not extra implementation ownership claimed by this document.

The bounded helper ZIP is excluded from final acceptance lineage. Final proof
uses the original retained producer directory and its declared capture reports.
No installed host/plugin patch or source-specific runtime workaround was used.

## CI recovery evidence

The original promotion
[run 36803386196](https://github.com/Automattic/blocks-engine/actions/runs/36803386196)
reported `IN_PROGRESS` after its matrix step was cancelled and its Complete job
step finished. Supported rerun, failed-job rerun, exact job rerun and force
cancellation were refused by inconsistent GitHub lifecycle responses.

Controller-authorized recovery briefly closed/reopened the same draft PR to
trigger its configured `pull_request.reopened` workflows at the unchanged
immutable implementation head. It preserved the tracker and code; there was no
empty commit, force push, gate bypass or merge. The recovery is recorded in
[the PR discussion](https://github.com/Automattic/blocks-engine/pull/2404#issuecomment-5923665183).

Both recovered runs passed:

- [Promotion run 36807117088](https://github.com/Automattic/blocks-engine/actions/runs/36807117088):
  solved-fixture matrix and verified promotion receipt.
- [PHP/browser/integration run 36807116645](https://github.com/Automattic/blocks-engine/actions/runs/36807116645):
  Composer PHP 8.2–8.5, all WordPress integration jobs, and the actual browser
  owner regression.

All ten latest checks for that implementation head were green. This proof
document is a meaningful additional publication; its new candidate head runs the
same normal gates again rather than inheriting or bypassing the historical ghost
check.

## AI contribution

OpenAI **gpt-6.1-sol via OpenCode** implemented the scoped owner relationship
repair, neutral regressions and proof publication, executed the actual editor
verification, and performed the controller-authorized CI recovery under Chris
Huber's direction. The original implementation attribution is preserved above;
presentation and final combined visual acceptance remain separately owned.
