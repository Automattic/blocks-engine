# Social icon glyph colour

A row of icon-only social links lowers to `core/social-links`. When every icon in the row paints one colour, that colour is carried as `iconColorValue` (and `customIconColor`), so core renders the icons in the source colour instead of its per-service defaults. Icons that do not share one colour keep the core defaults.

## Where the colour comes from

For each icon, `SocialLinksPattern` takes the first colour found in this order:

1. **Captured rendered colour.** A `data-blocks-engine-capture-glyph-color` attribute on the glyph element inside the link: an `<img>` (for `<picture>`, the inner `<img>`), an inline `<svg>`, or an empty element whose `background-image` paints the icon. A capture tool measures this from the rendered page; the attribute is part of the [capture-evidence contract](contracts/capture-evidence.md). The value must be `#rrggbb`, in either case; it is normalized to lowercase.
2. **Decoded image colour.** For an `<img>` glyph, `MonochromeGlyphColor` decodes the image bytes with GD: an inline `data:image/...` URI, or the artifact file the `src` resolves to. A single-colour glyph yields its colour.
3. **Authored paint.** An SVG `fill`, an inline `fill` or `color` on the glyph or link, or the link's resolved authored `color`.
4. **Same-destination glyph.** A placeholder image borrows the decoded or captured colour of another image linking to the same URL on the page.

The captured colour is the evidence of what the reader saw, independent of the host's image decoders and of the image format, so it wins over decoding. When it is present:

- no image bytes are decoded for that icon, and no `glyph_color_undecodable` warning is recorded for it;
- `ArtifactCompiler` does not read or decode an image file for glyph colour when every `<img>` reference to it on the page carries a valid captured colour. A file with any uncaptured reference is still decoded.

Decoding is the fallback for input without capture evidence. Capture writes the attribute only for monochrome glyphs, so a missing attribute on a captured page also falls back.

## Invalid values

A value other than a six-digit hex colour (`1877f2`, `#1877f`, `#1877f2ff`, `rgb(...)`, surrounding whitespace) counts as absent and the icon falls back to decoding. No separate diagnostic is recorded: the fallback either recovers the colour or records the existing `glyph_color_undecodable` warning for the lost colour.

## The attribute is not output

`data-blocks-engine-capture-glyph-color` is capture evidence, not content. `HtmlTransformer` reads and removes it from the source DOM before conversion, so it never appears in block markup, fallback HTML, materialized SVG assets, template parts or source provenance fragments. Only the unmodified source document that `ArtifactCompiler` reports in `source_reports.compiled_site.pages[].html` still contains it.

## `glyph_color_undecodable`

A warning recorded when an icon has no captured colour and the host GD cannot decode its image (for example AVIF on a PHP built without libavif). It names the image `mime_type`, `src` and `source_path`; the icon keeps the core default colour.
