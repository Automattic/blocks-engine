# Root inline scroll regression (Refs #3735)

Neutral reduced document: a stylesheet locks both document elements, and inline
styles unlock their captured runtime state. No platform-specific behavior is
inferred.

## Carrier choice

The compiler emits full root/body declarations through the existing
`stylesheet_target: frontend`, `after-author` asset channel. WordPressSitePlan
excludes that channel from editor presentation styles. Selectors use the same
route class that DocumentRootContext installs via `language_attributes`; no
new root attributes, canvas template, or editor reconciliation behavior is
needed. A matching class arm in `:is()` supplies ID weight, as InlineGeometry
does, without changing normal declarations into important declarations. The
conservative ID bound exceeds the combined authored selector weight, including
nested rules. Source `!important` stays intact.

InlineBodyStyle shares its decoded-style breakout guard with this carrier;
its editor-facing rule still carries only spacing and custom properties.
NavigationStyleProjector's unconditional static-state scroll repair is unchanged.
The existing spacing test now measures editor-loaded assets, including its
asset-count contract; its assertions are unchanged. A separate front-end asset
is intentionally outside that editor contract.

## Evidence collected in this workspace

The regression fails against trunk's compiler (missing route-scoped root
carrier) and passes with this change.

An isolated Chromium render of the compiled CSS and fixture markup (not a
WordPress import), with a 1440x900 requested window, measured:

| State | scrollY | html position / overflow / height | body position / overflow / height |
| --- | --- | --- | --- |
| Before (editor-compatible CSS only) | 0 | fixed / hidden / 813px | fixed / auto / 813px |
| After (including front-end carrier) | 2000 | static / hidden auto / 3145.88px | static / auto / 3129.88px |

The compiler regression confirms editor-loaded CSS excludes the full inline
mechanics and retains the unconditional scroll repair. This is **not** evidence
of scrolling in a live editor: that verification remains outstanding.

The paired static-site-importer development-package build was attempted using
its `--blocks-engine-path` option. The builder refused the uncommitted checkout
because it packages only committed transformer bytes. Commit finalization is
owned by Homeboy, so no package or Studio import was produced. A paired live
front-end/editor browser run is still needed before claiming full acceptance.
