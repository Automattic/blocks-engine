## Summary

Keep authored button height constraints on the padded `core/button` link so synthesized wrappers size to the control's used height.

Closes https://github.com/Automattic/blocks-engine/issues/3173

## Root cause and fix

Verified the issue's pointer in `AuthorStylesheetProjector`: both declaration splitters treated numeric height constraints as wrapper layout. A border-box control with 36px height and 48px vertical padding has a used height of 48px; padding-free wrappers instead stayed at 36px.

Extend the existing `isButtonControlBoxSize()` primitive to include height, min-height, and max-height for all values. Both splitters now keep these constraints with padding on the link. Width and placement ownership remain unchanged. No new production path or public API is introduced.

## Regression evidence

The exact reduced HTML is in `php-transformer/tests/fixtures/button-padding-height.html`. The canonical PHP regression covers height, minimum/maximum height, and custom-property height. The browser regression is registered as `composer visual:button-padding-height` (requires the visual-parity npm dependencies and Playwright Chromium).

Before the fix, running `node tools/visual-parity/tests/button-padding-height.mjs` from `php-transformer` exited 1:

```text
Padded button geometry: source={"heights":[48,48,48],"trustTop":202.4375} transformed={"heights":[36,36,36,48,36,36,48],"trustTop":190.4375}
AssertionError [ERR_ASSERTION]: row and all button carriers must contain the padded 48px control
```

After the fix, the same command exited 0:

```text
Padded button geometry: source={"heights":[48,48,48],"trustTop":202.4375} transformed={"heights":[48,48,48,48,48,48,48],"trustTop":202.4375}
Padded button height geometry passed
```

## Verification and self-review

- `composer test`: passed, including canonical/unit tests, all 350 parity fixtures, and packaging/install proof.
- `composer lint`: passed (PHPCS and PHPStan).
- Existing `button-height-ownership.mjs`: passed, desktop 52.203125px and mobile 46px across all carriers.
- Updated existing output assertions to reflect link-owned block sizes, retaining width/positioning and flex-stretch checks.
- Self-review: production change is six lines in the existing sizing predicate, reused by both splitters; no site-specific logic or parallel implementation.

## AI disclosure

Model: `cook-heavy` (provider-reported model identifier). Coding tool: Pi coding agent. AI read the issue, traced ownership, implemented the primitive change and regression tests, ran verification, and drafted this description.
