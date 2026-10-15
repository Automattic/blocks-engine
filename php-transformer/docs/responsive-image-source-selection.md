# Authored image source selection

A plain `img` `srcset` outside `<picture>` describes one image at several
resolutions. When its rendered width is known, it lowers to native `core/image`
so the photo stays editable Media Library content. Mixed, unparseable or
descriptorless families, `sizes` without `srcset`, and families whose rendered
width is unknown keep the existing `responsive-media` block and
`blocks-engine/responsive-media/v1` renderer. In that last case the intrinsic
size depends on the chosen candidate's density. Art direction (`<picture>`
sources) also keeps that boundary.

WordPress `core/image` serializes a fallback URL and attachment identity, but
not authored `srcset`/`sizes`. Attachment-generated candidates describe file
widths rather than authored descriptors, so the browser would decode different
bytes. The lowered block therefore records
`metadata.blocksEngineImageSelection` = `{src, srcset, sizes, loading}`.
The site plan installs a `render_block_core/image` filter that restores those
attributes only while the rendered `<img src>` still equals the selection's
`src`. Authored `loading` belongs to the selection: when candidates share a URL,
the first fetch fixes the image's density.

## Producer and consumer responsibilities

- The producer retains the source family, order, descriptors (including absent
  descriptors), `sizes`, authored dimensions, presentation, and URL identity in
  the responsive-media content attribute or the `core/image` selection
  metadata. Existing source sanitization applies.
- Artifact asset analysis and plan canonicalization bind each URL through the
  existing asset reference/token mechanism. Fallback attachment metadata alone
  does not replace the family's independent source references.
- The consumer binds each authored raster candidate to its exact captured bytes,
  including the candidates in `core/image` selection metadata, and rebinds the
  selection's `src` together with the image.
  Its shared `SrcsetParser` distinguishes separator commas from URL commas;
  source-selection descriptors cannot be applied to a promoted larger rendition.
  Core's original-image URL carries the authored bytes when upload processing
  replaces attachment "full" with a scaled image.
- The consumer's existing `wp-image-{id}` editor control owns replacement. A new
  Media Library selection retires the old image/picture source family and `sizes`,
  preserves the authored presentation, and saves the replacement URL and ID. For
  `core/image`, a replaced `src` no longer matches the selection, so core's own
  candidates apply.
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
