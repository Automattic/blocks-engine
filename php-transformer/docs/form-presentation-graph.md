# Form Presentation Graph

`generic/computed-form-presentation/v2` is an optional `generic/forms/v1` payload member. It carries bounded source-CSS facts for controls, associated labels, and identified inline SVG descendants. It is not a provider recipe and never assigns visual semantics such as "globe" or "chevron".

```php
array{
  schema: 'generic/computed-form-presentation/v2',
  basis: 'source_css_cascade',
  controls: list<array{index: int, control?: Role, label?: Role, required_marker?: Role}>,
  visual_parts: list<array{
    id: 'control-{control order}-svg-{descendant order}', index: int,
    kind: 'inline_svg', source_selector: string, markup: safe-svg,
    intrinsic_size?: array{width: positive-int, height: positive-int},
    source_css: array{state: 'known', styles: array<string,string>, provenance: list<Provenance>}|array{state: 'unknown'}
  }>,
  variants: list<array{index: int, role: 'control'|'label'|'required_marker'|'visual_part', part_id?: string, condition: Condition, style_patch: array<string,string>, precedence: array<string,array>, provenance: list<Provenance>}>,
  truncated: bool, limits: array{controls: 128, rules_per_role: 32}, diagnostics: list<string>
}
```

`visual_parts` contains only drawable SVG descendants that pass `SourceDom::isSafeInlineSvgMarkup()`. `source_selector` identifies the source descendant and `index` links it to the source control order. `source_css.state: 'unknown'` means no unconditional source-CSS facts were matched. Responsive facts can still be present in `variants`; consumers evaluate those conditions rather than treating an unknown base as missing presentation. Consumers must not infer a role or layout from SVG shape or order.

`required_marker` is emitted only for the existing explicit required marker identity: an `aria-hidden="true"` span containing one to four asterisks on a required control's associated label. It records the marker's own matched CSS and variants; when it inherits the label unchanged, it safely has empty `styles` and `provenance` rather than invented source facts. Existing v1 graphs remain valid. Graphs without visual parts or an explicit required marker retain the v1 envelope; graphs with either emit v2. This contract describes source appearance, not interaction-state semantics or provider destinations.

The unshipped v2 envelope also includes `visual_groups`. Each group records the source selector of the SVG parts' lowest common ancestor, a stable `id`, ordered `part_ids`, and `source_css` using the same base-fact shape as visual parts. Group responsive variants use `role: visual_group` and `group_id` rather than a control index. Consumers preserve those authored container facts independently of each SVG's intrinsic dimensions and keep provider visibility state outside that layout container.
