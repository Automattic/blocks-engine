# Authored native current/default state

Known `data-dla-native-control-state` version-1 facts are adapted at the native
control conversion boundary. The producer's script is not the implementation.
`Support/NativeControlState` validates the closed input/textarea/select/file
union, checks native input type and option count, and lowers facts into existing
authored block attributes. Malformed/unknown facts do not drive properties.

## Editable model

- Authored input `value` and `checked` retain reset defaults. Optional
  `initialValue`, `initialChecked`, and `indeterminate` retain observed current
  properties. `valueDeclared` distinguishes an authored empty value attribute
  from the browser's absent-attribute default/on value. `form` retains external
  native form ownership. File blocks retain type `file` and never restore files.
- Authored textarea `value` retains the exact reset default; optional
  `initialValue` retains the exact current string, including leading LF and
  Unicode. Textarea serialization protects the HTML parser's initial-LF rule.
- Authored select's existing ordered `options[].selected` remains the default;
  optional `initialSelected` is current selection. `multiple`, optgroups,
  disabled group semantics and implicit option values are retained. Zero current
  selections remain representable independently of an authored selected default.

Registered editor previews use initial properties and preserve reset defaults.
Editing a preview value/selection or an Inspector default updates the editable
default and initial representation together. Other edits retain captured
disagreement. Save/reopen emits state from the edited model, without a stale
producer payload hidden in passive data attributes.

Explicit associated labels remain independent native hosts with their source
static/data attributes. A typed select's layout-transparent compatibility wrapper
does not duplicate the native select's ID. Form ownership and radio groups are
browser-native, including an externally associated `form` attribute and an
unowned checked peer sharing a group name.

## Owned view asset

`Generators/AuthoredControlState` is the shared codec/interpreter used by existing
authored-input/select/textarea block definitions. Their declared `viewScript`
is `file:./view.js` and their companion `view_js` is fixed first-party code. The
block registry, companion payload, namespace/provenance and package paths remain
the existing ones. There is no separate control registry or copied source JS.

The source-neutral `data-blocks-engine-control-state` payload is a closed,
version-1 native-property declaration. The view code accepts only known typed
facts and writes fixed native properties. It restores defaults before current
state, clears represented radio peers before selecting winners, and never clears
unowned inputs. A shared WeakSet prevents multiple declared scripts from resetting
an already mounted control. There is no resize/reset listener; native reset owns
subsequent behavior. Fresh transition nodes are initialized through the same
codec in the existing captured-choice-group view path. Its typed `selected`
booleans apply current checked properties, not default attributes.

Native forms carrying valid baseline facts reuse the authored-native-form
builder, preserving omitted/GET/POST/dialog methods and form IDs rather than
losing reset/radio ownership to a readable approximation. Explicit provider-owned
form contracts retain their owning boundary. This state contract does not
implement an arbitrary source application's submission behavior.

## Proof and consumer boundary

`tests/unit/native-control-state.php` verifies adaptation, invalid payloads,
associated-label topology, generated view declarations and companion packaging.
`tests/native-control-state-browser.mjs` measures an independent live source,
captures typed facts, shuts down the source, and registers first-party companion
blocks on an isolated actual WordPress site. It proves current/default properties,
`:checked`, `:default`, `:placeholder-shown`, CSS, reset, native labels, radio
ownership, option ordering/optgroups, readonly/disabled fields, file exclusion,
textarea LF/Unicode and captured-choice transitions. It then invokes the actual
registered editor callbacks, saves through Gutenberg's REST flow, reopens,
validates every block, and measures the edited frontend/reset. Declared asset
hashes remain unchanged during the entire proof.

Run with an isolated fixture site and an existing evidence parent:

```sh
CONTROL_STATE_EVIDENCE=/path/to/evidence \
CONTROL_STATE_WP_PATH=/path/to/disposable-wordpress \
CONTROL_STATE_WP_URL=http://localhost:port \
CONTROL_STATE_USER=fixture-admin CONTROL_STATE_PASSWORD=fixture-password \
node tests/native-control-state-browser.mjs
```

SSI's existing companion payload supports `viewScript`/`view_js`, passive block
attributes, script dependencies and generated provenance. The engine's typed
adapter consumes source facts before publishing editable controls; SSI need not
retain a raw captured script. An importer/runtime using an immutable-codebase
policy must admit these declared first-party block assets through its existing
owned-code path. The browser proof establishes unchanged declared code hashes;
it does not claim a new importer policy capability or a fresh UBC import.
