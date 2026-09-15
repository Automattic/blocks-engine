# Legacy Pseudo Selector Candidate

Implementation commit: `4075eda8baa9e727786f3f0a12fda01e747970d2`
Baseline: `e1c86ee2b2f435a01b511ac2702c5bef34967c67`

Scope is limited to `AuthorStylesheetProjector` and its regression fixture.
The projection keeps the specificity shim before legacy `:before`, `:after`,
`:first-line`, and `:first-letter` pseudo-elements, while preserving all
double-colon pseudo-elements and conditional wrappers unchanged.

Verification: focused unit and contract tests pass; browser CSSOM accepts the
projected selector list and computes the fixture heading margin as `0px`.
The complete Composer suite passed in the isolated combined gate worktree after
its independently corrected malformed `<plaintext>` empty-fixture setup.

AI disclosure: GPT-5.6 Terra via OpenCode, orchestrated by GPT-6 Astra via
OpenCode.
