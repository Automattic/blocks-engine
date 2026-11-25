# Position and selector restatement

Refs #7471. Implements the conservative relative-position and selector cases of #7521.

A relative wrapper with inert offsets can move onto an unpositioned child when
expanding the child's border box by the wrapper padding exactly reproduces the
wrapper box at every viewport. Descendant position evidence is required. Absolute,
fixed and sticky wrapper positioning remains unproven. Child position declarations
still contest the transfer. Missing evidence and nonzero offsets retain wrappers.

Trailing pseudo-elements are matched on their originating element; declarations
style the generated box, not the host. A generated box already styled separately
on the child refuses transfer to avoid merging two generated boxes.

Dynamic predicates are checked satisfied and unsatisfied. This supports one dynamic
predicate per selector, including an ancestor predicate. Multiple independent
predicates remain unverified rather than assuming their states are correlated.
Vendor placeholder pseudo-elements use the same originating-element proof.

Inset geometry refusals now distinguish child paint, child margins, child padding,
formatting contexts, missing evidence, and unexplained expanded-box differences.
Percentage declarations on the child still refuse in the declaration proof: their
containing block changes. No new painted-child inset subclass is claimed safe.

## Corpus evidence available in this environment

The existing `~/cal/wd3` logs are **baseline**, not post-change imports. Their
sdnunca top three refusal groups are geometry_not_neutral (101),
unrestatable_wrapper_attribute (62), and wrapper_is_not_div (52). They must not
be presented as post-change results. Real post-change imports and visual A/Bs
were not run here; no sdnunca reduction or eight-site visual parity is claimed.
The new relative-position and pseudo-element rules are generic, but their
sdnunca applicability remains unmeasured.

Targeted regression: `php tests/contract/restated-wrapper-fold.php`.
Full Composer verification is delegated to Homeboy.
