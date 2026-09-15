# Modern Nest Responsive Navigation Candidate

Candidate commit: `790d5b4c0766`
Package: `static-site-importer-dev-8218374ce2e0-blocks-engine-790d5b4c0766.zip`
Runtime: `http://localhost:8939/`

The candidate replaces the framework-specific `@layer utilities` visibility
override with a layer-aware engine support rule. Existing stylesheet analysis
detects authored layers. When present, an engine support layer is reserved
before author CSS and the important responsive-host repair is emitted there;
unlayered sources retain an ordinary after-author rule.

LAB verification: `composer test` passed all navigation and support-style
contracts, including the arbitrary-layer regression. The suite then failed at
the unrelated existing `tests/unit/text-leaf-element-converter.php`
`empty-plaintext-drops` assertion.

CTA status: not accepted. The immutable capture contains a hidden desktop CTA
and an external WhatsApp action, but no captured opened-menu CTA or explicit
interaction contract associating either action with native navigation. The
candidate deliberately does not infer ownership from adjacency. Fresh runtime
therefore shows five links, while the live source is known to show six.

AI disclosure: GPT-5.6 Terra via OpenCode. Tools used: repository inspection,
PHP/Composer tests over SSH, Studio CLI, and Playwright browser verification.
