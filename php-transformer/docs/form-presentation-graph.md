# Form Presentation Graph

`generic/computed-form-presentation/v2` is an optional `generic/forms/v1` payload member. It carries bounded source-CSS facts for controls, associated labels, and identified inline SVG descendants. It is not a provider recipe and never assigns visual semantics such as "globe" or "chevron".

```php
array{
  schema: 'generic/computed-form-presentation/v2',
  basis: 'source_css_cascade',
  controls: list<array{index: int, control?: Role, label?: Role}>,
  visual_parts: list<array{
    id: 'control-{control order}-svg-{descendant order}', index: int,
    kind: 'inline_svg', source_selector: string, markup: safe-svg,
    intrinsic_size?: array{width: positive-int, height: positive-int},
    source_css: array{state: 'known', styles: array<string,string>, provenance: list<Provenance>}|array{state: 'unknown'}
  }>,
  variants: list<array{index: int, role: 'control'|'label'|'visual_part', part_id?: string, condition: Condition, style_patch: array<string,string>, precedence: array<string,array>, provenance: list<Provenance>}>,
  truncated: bool, limits: array{controls: 128, rules_per_role: 32}, diagnostics: list<string>
}
```

`visual_parts` contains only drawable SVG descendants that pass `SourceDom::isSafeInlineSvgMarkup()`. `source_selector` identifies the source descendant and `index` links it to the source control order. `source_css.state: 'unknown'` means no unconditional source-CSS facts were matched. Responsive facts can still be present in `variants`; consumers evaluate those conditions rather than treating an unknown base as missing presentation. Consumers must not infer a role or layout from SVG shape or order.

Existing v1 graphs remain valid. Graphs without visual parts retain the v1 envelope; graphs with visual parts emit v2. This contract describes source appearance, not interaction-state semantics or provider destinations.
