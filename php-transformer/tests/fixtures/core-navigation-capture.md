# Real core Navigation capture

`core-navigation-capture.html` and `core-navigation-states.json` were generated
by running DLA's `src/lib/core-navigation.test.ts` at
`67860a5b7de24ca7e871fff4ea5da38ca9d6d2f0`, saving the `portable` output of
`wireCapturedDialogs()` and the report from `captureTriggeredDialogs()`.
The only test changes were those two writes. The source browser viewport was
390×900. DLA's test also verified inline link visibility and geometry at 768 and
1440px, repeated resizing, and phone open/Escape-close behavior.

Source: DLA `test/fixtures/core-navigation/index.html`, the actual output of
WordPress 6.8.3 `do_blocks()` for `core/navigation` with `overlayMenu: "mobile"`
and four custom links (About, Menu, Contact, Journal), rendered using WP-CLI on
stock WordPress, without plugins. The navigation markup is not hand-written.
`core-navigation-style.css` is the accompanying WordPress core navigation CSS
(copied verbatim from DLA's `style.css`, originally
`wp-includes/blocks/navigation/style.css` in wordpress-6.8.3.zip).
WordPress is licensed GPL-2.0-or-later.

To regenerate, run that upstream browser test with writes immediately after
`const portable = wireCapturedDialogs( withoutRuntime, report.states );`.
Preserve the captured HTML and report without editing their evidence. The
report's ephemeral loopback URL is deliberately retained; the contract wraps
its states in a local artifact route mapping.

The fixture has `dialog.restingInline: true`, a reused existing-panel snapshot
with resting and opened presentations, and no capture-dropdown class. The
panel is hidden at capture width: absence of an active resting-panel marker
does not prove it will stay hidden on desktop. Blocks Engine must retain the
producer runtime and bindings rather than treating it as a native dropdown.
