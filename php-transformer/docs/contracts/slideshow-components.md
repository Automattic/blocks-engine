# Captured slideshow materialization

`CapturedDialogProjector` consumes `carousel` components whose function is
`slideshow` before generic component/dialog projection. The source subtree is
replaced, not augmented: capture transforms, hidden states, selectors and
translated track geometry do not enter ordinary layout conversion. These
components never contribute generic dialog evidence.

Ordered frame slots become editable children of the existing authored-carousel.
Repeated asset references are retained. Explicit loop clones are discarded.
Media references must resolve to an included asset-evidence record whose path
exists in the package. Missing media slots are omitted with a
`captured_slideshow_partial` warning naming the source slots (or frame IDs).
Sanitized frame text/captions remain editable; source media URLs and inline
geometry are not copied.

Initial selection is read from `slideshow.initial`, never exploration `active`.
Recorded navigation enables arrows, dots, keyboard, swipe and timed autoplay.
An empty navigation inventory retains default arrows. Captured view counts are
width steps: the widest captured width not wider than the viewport applies, and
the narrowest capture covers smaller viewports. No uncaptured counts are invented.
The added block attributes have defaults preserving existing carousel output.

`test:slideshow-browser` uses the existing disposable WordPress editor harness
with the synthetic capture fixture, native image children, desktop next and
390px swipe assertions, and editor save/reopen validation. Docker is required.
