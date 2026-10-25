# Compact projected CSS tokens

`Support/EngineMarker.php` owns the `be-t-`, `be-c-` and `be-i-` shim
prefixes and the `data-be-d` device gate attribute. Shim allocation still
searches the HTML and CSS for collisions and advances its numeric suffix.
The `:not()` arguments remain a type, class and id respectively; device gates
remain inside `:where()`. No selector arms, declaration bodies, conditions or
source positions are changed.

The published `data-blocks-engine-capture-device-document` capture input stays
supported and is retained on serialized roots for authored scripts and
selectors. Roots also carry its compact alias. Only projected weightless CSS
gates use the alias; source files, runtime scripts and authored content are not
renamed. New token consumers must use `EngineMarker` rather than private copies
of its spellings or grammar.

## Measurements

Measured standalone `ArtifactCompiler::compile()` calls on the same PHP runtime,
in separate processes loading trunk or the changed source tree, with identical
fixture inputs. CSS bytes are the sum of `strlen(content)` over result `assets`
whose `kind` is `css`, before compression (not input bytes or transport JSON).

| Fixture | Trunk | Compact | Reduction |
| --- | ---: | ---: | ---: |
| `tests/fixtures/compact-engine-tokens.php` (160-card responsive editorial catalogue) | 244,621 | 197,482 | 19.3% |
| `tests/fixtures/design-system/tailwind-shell.php` (six-route marketing/product site) | 27,733 | 25,263 | 8.9% |

For **both** fixtures, replacing just the three shim prefixes and device gate
spelling in trunk's CSS yields byte-identical ordered CSS asset contents. This
checks declaration bytes, selector arm order, conditions and rule order, not
just aggregate size. The matcher regression additionally checks the exact
specificity vectors `(0,0,1)`, `(0,1,0)`, `(1,0,0)` and `(0,0,0)`; existing
navigation specificity, authored cascade, shared-chrome, site-plan and parity
coverage exercises their consumers.

Five alternating cold-process catalogue compilations had median compile times
of **0.877 s trunk / 0.851 s compact** (timed around `compile()`, excluding fixture
loading and JSON serialization). There is no observed compile-time regression.
The device rewrite has a substring fast path for sheets without device gates;
only sheets containing them need a selector-only traversal.
