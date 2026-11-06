## Slice 1 of #4550: deduplicate theme bootstrap data (incomplete)

### Root cause
The bootstrap repeats identical stylesheet/document attribute bodies per handle and presentation file metadata per route occurrence. Shared assets therefore multiply generated PHP size by route count.

The candidate interns attribute bodies, omits default stylesheet attributes, and separates presentation files/scopes from occurrence replay so enqueue handles, order, media and scopes remain intact.

### Before / after bytes
The integration fixture reports modeled trunk registry size of 94,713 bytes versus 57,427 bytes after compaction (rerun the integration test for authoritative measurements). This comparison models trunk registries in the emitted bootstrap shell, rather than compiling a separate trunk checkout.

### Blocking verification
The unchanged staged-shared-inline-projection contract expects 10/19 literal presentation entries for 3/6 routes; the candidate emits 8/14 unique-file entries. An attempted serialization compatibility adjustment passed that assertion but exposed another unchanged contract, shared-chrome-stylesheet-scope, which directly evaluates the literal registry and expects separate route occurrence rows. That adjustment was reverted. The deterministic gate remains unresolved; this PR is not ready to merge.

Existing contract/canonical fixtures and topology baseline remain unchanged. No topology baseline regeneration was performed.

AI disclosure: model `cook-heavy`, using the Pi coding tool.
