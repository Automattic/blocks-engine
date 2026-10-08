# Declared document scopes and authored controls

A source document scope is an explicit body-equivalent boundary, rather than a
fixed desktop/mobile class convention. `data-dla-document-scope` together with a
bounded `data-dla-device-document` identifier declares that boundary. Profile
identifiers use `[a-z][a-z0-9_-]{0,63}`; layout dispatch, ancestry, root state,
navigation duplicate suppression and counterpart editor context use the declared
boundary independently of class cardinality. Existing source conventions remain
supported.

The existing layout-shell primitive retains each root's source attributes,
classes and explicit state while its content remains independently editable
blocks. An unrelated iframe does not make a generic content ancestor a runtime
application: a workspace surface requires its own runtime-target evidence. Real
application roots and runtime-addressed surfaces retain their existing ownership.

The existing authored-button primitive now supports bounded source `div` and
`span` roots with `role="button"`. Its optional `tagName` defaults to `button`;
role and tabindex travel in `sourceAttributes`. Native button save shapes stay
unchanged. Source control roots, label/icon descendants, bindings and accessible
names round-trip through the real companion save function. Inline handlers and
nested interactive content remain outside this compact control contract.

A modal is a distinct interaction contract. Details dispatch declines a dialog
target, dialog popup declaration, or modal panel; it retains the existing source
target and close/focus behavior instead of converting the modal into disclosure.

## Authored media and activation

`data-dla-source-media` declares the authored media condition. A serialized
`media="not all"` can instead be the inactive document's initial activation state.
Analysis uses the authored condition; the canonical asset carries optional
`source_media` independently of `media`. Declared source media remains owned by
the ordered head element, so the asset payload does not permanently bake inactive
activation into an `@media not all` wrapper. Editor stylesheet descriptors use
the authored condition. The head declaration, viewport selection, script order,
and strict dynamic-client-asset policy remain authoritative.

## Gates and evidence

```sh
php tests/unit/declared-document-role-button.php
composer test
```

The neutral contract has four arbitrarily named profiles, source host classes,
three-bar role controls, hidden panels, independent frames and authored media.

From `tools/visual-parity/`:

```sh
npm ci
npm run test:declared-body
```

The browser gate compares source and canonical frontend output, executes click,
Enter, Space, close, Escape and focus return, and repeats after the packaged
companion's actual `save()` output is edited and reloaded. It compares root state,
bar geometry, profile-specific spacing and media activation across all profiles.
This gate does not replace the consumer's full destination WordPress/editor gate.

The activation-free evidence builder accepts a frozen compiler artifact or a
portable source directory:

```sh
php tools/benchmarks/body-scope-evidence.php --source=<source-root> --output=<fresh-before>
php tools/benchmarks/body-scope-evidence.php --input=<frozen-input.json> --output=<fresh-after> --wp-parser=<read-only-wp-includes>
```

Use the real WordPress parser for recursive Core HTML acceptance. The lightweight
fallback parser can miss large nested source islands. Builds save input/results,
canonical/resolved plans, source files, theme writes and parser-audited island
evidence. The frontend evidence harness expands declared template parts and
static block markup; the parent validates full WordPress rendering, dynamic
Core interactivity and the actual editor.
