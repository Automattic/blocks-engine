## Fixes #4715

### Problem
Cover recognition rejected every background container containing native navigation, even when a heading outside the menu made it a hero. Its photo consequently remained CSS-owned rather than editable cover media.

### Fix
Reject navigation-bearing candidates only when no `core/heading` exists outside all `core/navigation` subtrees. Navigation blocks and their descendants remain unchanged inside the cover. Nav-only background bars retain group output and the existing shell-extraction contract; no shell-extraction code changes.

### Evidence
- Cover unit output: `cover pattern ok`. The reduced-repro cover assertion failed with the original gate and passes with the fix, including section tag, photo URL, overlay, native navigation, and heading assertions.
- K5 also covers nav-only groups, recursively nested navigation plus paragraph rejection, headings inside navigation being excluded, and unchanged navigation subtree preservation.
- Composer tests and changed-file lint passed.
- Full website topology sweep: every fixture matches baseline except the pre-existing `30-cursed-pangolin-fanwiki` mismatch (matched=3, divergences=1; weighted score 4 → 1). Inspection shows empty visual div -1, removed anchor -1, plaintext-to-pre retag +1. Restoring the original navigation gate produces exactly the same result; this is not a new cover or extraction change. Baseline left untouched.
- No fixture topology changes attributable to this fix. Studio editor validation and frontend comparison at 390/1440px were not available in this workspace.

_AI disclosure: implemented by cook-heavy via Homeboy cook, orchestrated by Franklin (Claude Opus via Pi/Roadie) for @chubes4._
