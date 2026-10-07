# Authored image source selection

Plain `img` elements with authored `srcset` or `sizes` use the existing
`responsive-media` block and `blocks-engine/responsive-media/v1` renderer. The
same boundary preserves image-only links and figures. `core/media-text`
recognition declines these images so ordinary image lowering can preserve their
selection inside the authored layout.

WordPress 7.1 `core/image` and `core/media-text` serialize a fallback URL and
attachment identity, but do not serialize arbitrary authored `srcset`/`sizes`.
Attachment-generated thumbnails do not reproduce separately authored pixels or
density-corrected intrinsic dimensions. Non-responsive images retain native
lowering.

## Producer and consumer responsibilities

- The producer retains the source family, order, descriptors (including absent
  descriptors), `sizes`, authored dimensions, presentation, and URL identity in
  the existing content attribute. Existing source sanitization applies.
- Artifact asset analysis and plan canonicalization bind each URL through the
  existing asset reference/token mechanism. Fallback attachment metadata alone
  does not replace the family's independent source references.
- The consumer binds each authored raster candidate to its exact captured bytes.
  Its shared `SrcsetParser` distinguishes separator commas from URL commas;
  source-selection descriptors cannot be applied to a promoted larger rendition.
  Core's original-image URL carries the authored bytes when upload processing
  replaces attachment "full" with a scaled image.
- The consumer's existing `wp-image-{id}` editor control owns replacement. A new
  Media Library selection retires the old image/picture source family and `sizes`,
  preserves the authored presentation, and saves the replacement URL and ID.
- Source asset content hashes and the existing attachment source-asset metadata
  establish exact byte provenance. Occurrence correspondence and independent
  pixel/geometry validation remain downstream responsibilities.

## WordPress/browser acceptance

The disposable Docker runner accepts read-only producer baseline and SSI source
paths. With a checksum-pinned WordPress 7.1 archive:

```sh
BE_EDITOR_WORDPRESS_CORE_ARCHIVE=/path/wordpress71-core.tar.gz \
BE_EDITOR_BASELINE_SOURCE=/path/baseline/php-transformer \
BE_EDITOR_SSI_SOURCE=/path/paired-ssi \
BE_EDITOR_EVIDENCE_DIR=/path/evidence \
bash tools/run-editor-image-acceptance.sh
```

The archive SHA-256 is
`a874a9c66927ba4e21f30dd88b31c1df12f5a25049e81efb4ceab856da43c27b`.
The baseline needs Composer dependencies identical to the candidate's lockfile.
The supplemental test compares source/baseline/candidate at 390, 768 and 1440px,
each at DPR 1 and 2. It records `currentSrc`, exact selected file hashes, decoded
pixel hashes, rendered image screenshots, natural dimensions and geometry for
width, density, descriptorless/comma-URL, image-service and 3840px original-image
families. It also
checks save/reopen, actual Media Library replacement, subsequent frontend
selection, and registered-block validity. Source-URL-to-attachment evidence is
derived from exact existing asset/attachment identities, without changing an
image oracle or perceptual threshold.

`BE_EDITOR_PLUGIN_SOURCE` optionally mounts a read-only baseline plugin while
using the same acceptance harness, to reproduce native editor failures against
the parent source independently of the candidate.
