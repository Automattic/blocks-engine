# Palette alpha regression

Fixes https://github.com/Automattic/blocks-engine/issues/3941.

The collector discarded translucent observations as `!alpha` before preset
binding could resolve them. It now retains the color; the existing design
system tint matcher derives observed palette entries, and ordinary preset
binding assigns them to block color support. Unmatched colors and unsafe
state/important/media variants retain the existing refusal paths. The optional
hover behavior is unchanged.

Derived presets put `color-mix()` in theme.json, not inline style, avoiding
inline sanitization. For the reduced fixture, the added entry is:

```json
{"slug":"base-alpha-70","name":"Base 70%","color":"color-mix(in srgb, var(--wp--preset--color--base) 70%, transparent)"}
```

## Evidence

Reduced fixture: `tests/fixtures/design-system/palette-alpha.php`.

Before (trunk):

```html
<!-- wp:paragraph {"className":"text-bg-70"} --><p class="text-bg-70">Muted body copy on dark.</p><!-- /wp:paragraph -->
```

After:

```html
<!-- wp:paragraph {"className":"text-bg-70","textColor":"base-alpha-70"} --><p class="text-bg-70 has-text-color has-base-alpha-70-color">Muted body copy on dark.</p><!-- /wp:paragraph -->
```

`refusal_reasons.alpha`: **1 → 0**. The new contract fails against trunk at
“The paragraph owns a named preset tint, not Default.”

WordPress 7.0.4 / Chromium disposable acceptance:

- Reviewed `wp-includes/block-supports/colors.php`: `textColor` maps to a
  preset reference and core's style engine emits preset classes. The builder
  calls `wp_apply_colors_support()` and `render_block()` to verify this.
- Editor palette resolves the paragraph's textColor to `Base 70%`, retaining
  the color-mix value. All blocks validate before and after save/reload.
- Original and converted rendered pixels both `[239,244,245,179]`.
- Before: `color(srgb 0.941177 0.952941 0.960784 / 0.7)`.
- After changing only the base entry to `#00a86b` in user Global Styles:
  `color(srgb 0 0.658824 0.419608 / 0.7)`.

Commands run from `php-transformer`:

```sh
composer install --no-interaction --prefer-dist --no-progress
composer test
composer lint:types
BE_EDITOR_EVIDENCE_DIR=/tmp/palette-alpha-evidence-3 composer test:palette-alpha-browser
```

The browser command also works with the equivalent builder/browser environment
variables passed directly to `tools/run-editor-image-acceptance.sh`.

Compatibility: Additive; observed tints expand the palette. The existing exact
opaque-color and whole-percentage alpha matching policy is retained, including
its 0.2 percentage-point tolerance for 8-bit alpha quantization.

AI disclosure: OpenAI model `cook-heavy` (session model identifier) via Pi assisted
with implementation, regression tests, WordPress/browser verification and review.
