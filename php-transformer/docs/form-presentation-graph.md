# Form Presentation Graph

## Companion layout graph: direct child counts

`generic/computed-layout-graph/v3` container nodes optionally carry
`in_flow_children: int` (non-negative). It counts direct element children,
excluding resolved base `display:none` and `position:absolute|fixed` boxes,
plus non-whitespace direct text runs (anonymous flex items). Non-authored
controls and honeypot-owned copy containers excluded by existing form projection
are excluded here too. It uses the
layout graph's existing bounded source-CSS cascade, not browser geometry;
conditional variants do not change this base count. Control nodes do not
carry it, and graphs omitting it remain valid.

The count includes siblings omitted from the control topology and is independent
of emitted-node `order` and the fallback HTML excerpt limit. Existing graph
limits (128 nodes, depth 16, 16 rules per node) still apply to CSS evidence;
`truncated` continues to signal exceeded analysis limits. The child count itself
is not capped by the graph node limit or the roughly 2,000-byte HTML excerpt.

## Companion layout graph: empty boxes

A direct child of a graph container that holds no control, no text beyond
whitespace or no-break spaces, and no content-painting element (image, SVG,
media, control, rule), yet declares a `height` or `min-height` in the base
cascade or a conditional variant, is a v3 node with `kind: "empty_box"` and id
`empty-N` (document order). A reserved validation slot is the typical case.
Its `parent` and `order` place it among the field rows; `layout`, `provenance`
and `variants` carry its `display`, `position`, `height` and `min-height` like
any node. It also carries:

- `content: "no_visible_content"`;
- `in_flow: bool`, false for a resolved base `display:none|contents`, the
  `hidden` attribute without an authored display, or `position:absolute|fixed`;
- `reserves_space: bool`, true only when in flow, not a non-replaced inline box,
  and the base `height` or `min-height` is a positive absolute or viewport length.

Empty boxes are leaves and never take `presentation`, `sizing` or
`in_flow_children`. Their separate coordinate leaves wrapper, control and
context ids unchanged. They count toward the 128-node limit only after all
other structure; a dropped box sets `truncated` with an `empty_box_limit`
diagnostic. Slots with visible text are not empty boxes.


`generic/computed-form-presentation/v2` is an optional `generic/forms/v1` payload member. It carries bounded source-CSS facts for controls, associated labels, and identified inline SVG descendants. It is not a provider recipe and never assigns visual semantics such as "globe" or "chevron".

Control metadata in `generic/forms/v1` also carries source presentation hooks outside this graph. `description` is the resolved field helper text. When exactly one description element resolves, `description_classes` is its ordered list of class tokens (bounded to 16), for example `["sub", "hidden-util"]`; a resolved element without classes yields `[]`. With no resolved description or ambiguous candidates, the key is absent. Consumers can use these tokens to preserve visually hidden helper copy instead of rendering it visibly. Existing label hooks (`label_class` and button `label_classes`) are unchanged; unlike those space-separated strings, `description_classes` is a token array. This additive metadata does not change block output.

```php
array{
  schema: 'generic/computed-form-presentation/v2',
  basis: 'source_css_cascade',
  controls: list<array{index: int, control?: Role, label?: Role, required_marker?: Role}>,
  visual_parts: list<array{
    id: 'control-{control order}-svg-{descendant order}', index: int,
    kind: 'inline_svg', label_side: 'before'|'after', source_selector: string, markup: safe-svg,
    intrinsic_size?: array{width: positive-int, height: positive-int},
    source_css: array{state: 'known', styles: array<string,string>, provenance: list<Provenance>}|array{state: 'unknown'}
  }>,
  variants: list<array{index: int, role: 'control'|'label'|'required_marker'|'visual_part', part_id?: string, condition: Condition, style_patch: array<string,string>, precedence: array<string,array>, provenance: list<Provenance>}>,
  truncated: bool, limits: array{controls: 128, rules_per_role: 32}, diagnostics: list<string>
}
```

`visual_parts` contains only drawable SVG descendants that pass `SourceDom::isSafeInlineSvgMarkup()`. `source_selector` identifies the source descendant and `index` links it to the source control order. `label_side` records whether the SVG precedes or follows the control's first non-whitespace text node in document order, defaulting to `'before'` when there is no text. `source_css.state: 'unknown'` means no unconditional source-CSS facts were matched. Responsive facts can still be present in `variants`; consumers evaluate those conditions rather than treating an unknown base as missing presentation. Consumers must not infer a role or layout from SVG shape or order.

`required_marker` is emitted only for the existing explicit required marker identity: an `aria-hidden="true"` span containing one to four asterisks on a required control's associated label. It records the marker's own matched CSS and variants; when it inherits the label unchanged, it safely has empty `styles` and `provenance` rather than invented source facts. Existing v1 graphs remain valid. Graphs without visual parts or an explicit required marker retain the v1 envelope; graphs with either emit v2. This contract describes source appearance, not interaction-state semantics or provider destinations.

The unshipped v2 envelope also includes `visual_groups`. Each group records the source selector of the SVG parts' lowest common ancestor, a stable `id`, ordered `part_ids`, and `source_css` using the same base-fact shape as visual parts. Group responsive variants use `role: visual_group` and `group_id` rather than a control index. Consumers preserve those authored container facts independently of each SVG's intrinsic dimensions and keep provider visibility state outside that layout container.
