## Fixes #4540

### Root cause
Native image lowering calls `HtmlCompilation::imageSourceUrl()`, but lowered controls and preserved markup serialize their source images without that call. Lazy images in those paths therefore retained a transparent data-URI placeholder even when a real lazy source was available.

### Before / after
Before: a gallery thumbnail button could emit the placeholder `src` alongside an unused `data-src`.
After: source normalization resolves every source `<img>` before dispatch using the existing image-source policy, including its lazy-attribute priority and real-source precedence. Lazy candidates must also pass the safe image URL policy. This is generic: no site-specific selectors or classes.

The normalization scan uses a static XPath node set rather than indexed live DOMNodeList iteration, avoiding repeated document traversal as galleries grow. The gallery scaling threshold is unchanged.

### Tests
- One table-driven unit test covers standalone images, lowered buttons, and preserved tables; placeholder/empty sources, each lazy attribute, priority, real-source preservation, unsafe candidates, and missing alternatives. Registered in Composer's unit list.
- Confirmed the test fails on trunk at the lowered-button placeholder case and passes here.
- Gallery compilation scaling passed three consecutive runs.
- 87 media/responsive unit tests passed.
- Contract suite and Composer canonical suite passed.

### Topology
No PR topology deltas; baseline unchanged. The full local PHP 8.5 run reports only `30-cursed-pangolin-fanwiki`: added empty visual div -1, removed anchor -1, plaintext-to-pre retag +1 (weighted score 4 to 1). Running that fixture on trunk produces exactly the same counts. These are malformed-HTML parser/runtime differences, not this PR's deltas; the topology documentation requires PHP 8.4 for baseline regeneration.

AI disclosure: implemented and tested with model `cook-heavy` (Roadie provider) using the Pi coding tool.
