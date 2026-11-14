# Background-painted carousel regression (#6027)

The reduced captured-shape fixture is `tests/unit/carousel-background-slides.php`:
two empty Webflow slides painted with stylesheet gradient/image layers, a source
status live region, and previous/next arrows. No capture-only attributes are needed.

## Before

Running the fixture against the original implementation fails `one authored
carousel` and `source status is not visible content`. The background-only items
are rejected as content, and `Slide 1 of 2.` survives as ordinary visible text.

## After

The same fixture produces one authored slideshow with exactly two native group
slides containing editable image blocks. Both source media URLs survive, the
stage and painted slides retain their 900px height, and the runtime slide count
is two. The source status text is absent from serialized content; the carousel's
replacement accessible status remains visually hidden.

The existing script suite and parity fixture corpus pass unchanged. This is
compiler-output regression evidence, not a browser screenshot comparison.
