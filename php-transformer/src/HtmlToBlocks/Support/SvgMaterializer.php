<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use Automattic\BlocksEngine\PhpTransformer\Css\CssFontFamily;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceDeclarations;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\SvgArtworkBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\SvgElementMaterializer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssVariableExpander;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMElement;
use DOMNode;

/**
 * Materializes source SVG into emitted blocks: inline SVG hosts, decorative
 * classification, casing restoration, box styling and asset extraction.
 *
 * Blocks are created through
 * {@see \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator},
 * and per-transform state is read from the session.
 */
final class SvgMaterializer implements SvgElementMaterializer
{
    use DomHelpersTrait {
        sanitizeInlineSvgMarkup as public;
    }

    public function __construct(
        private readonly \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator $createBlock,
        private readonly \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession $session,
        private readonly \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier $sourceElementClassifier,
        private readonly StyleResolver $styleResolver,
        private readonly Runtime $runtime
    ) {
    }
    /** Product evidence uses the same sanitized payload and asset identity as blocks. */
    public function inlineSvgImageReference(DOMElement $element): ?array
    {
        if (!SourceDom::svgHasDrawableContent($element) || $this->paintsNothing($element)) {
            return null;
        }
        $html = $this->sanitizeInlineSvgMarkup($element);
        if (!SourceDom::isSafeSvgContent($html)) {
            return null;
        }
        $html = $this->materializeSanitizedSvgMarkup($html, $element);
        $path = null;
        $attrs = $this->inlineSvgImageAttributesFromMarkup($element, $html, false, $path);
        return null === $attrs ? null : array('src' => $path, 'alt' => $attrs['alt'] ?? '');
    }

    public function paintsNothing(DOMElement $element): bool
    {
        return (new SvgPaintEmptiness($this->styleResolver))->paintsNothing($element);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function inlineSvgBlockFromElement(DOMElement $element): ?array
    {
        // Childless passive SVGs render an empty graphic: direct character data
        // is not painted without a <text> element. Preserve their box through the
        // ordinary image path, but keep rejecting non-drawable active content
        // (e.g. a lone <script> or a root event handler) before sanitization.
        $definitionsOnly = SourceDom::isDefinitionsOnlySvg($element);
        $childlessPassive = 0 === $element->getElementsByTagName('*')->length && self::isPassiveSvgMarkup($element);
        if ( ! $definitionsOnly && ! $childlessPassive && ! SourceDom::svgHasDrawableContent($element) ) {
            return null;
        }

        // Preserve the artwork: sanitize the SVG in place — stripping only the
        // genuinely-unsafe parts (script/style/foreignObject elements, event
        // handlers, javascript: URLs) — instead of dropping the entire graphic
        // the moment one unsafe attribute or element appears. The shape and
        // structure markup (svg/path/circle/rect/g/text/...) is kept so the
        // image renders, rather than collapsing to an empty block.
        $html = $this->sanitizeInlineSvgMarkup($element);

        // Safety gate: only emit raw inline SVG once the sanitized markup is
        // provably free of script/event-handler/javascript: vectors and still
        // contains an <svg>. If sanitization could not fully clean it, defer to
        // the bounded fallback metadata path rather than emit unsafe markup.
        if ( ! SourceDom::isSafeSvgContent($html) ) {
            return null;
        }

        // Definitions have no independent image to render. Externalizing them
        // severs page-local fragment consumers, even when CSS supplies the URL.
        // Keep their authored box (including zero dimensions) in the document.
        if ($definitionsOnly && self::isPassiveSvgMarkup($element)) {
            return $this->svgArtworkBlock($element, $this->restoreSvgCasing($html));
        }
        if ($definitionsOnly) {
            // Non-passive document stores retain their existing inline fallback;
            // they must not enter any standalone image materialization path.
            $this->recordGutenbergIncompatibility($element, 'svg_requires_inline_document_context', 'SVG definition store requires inline document context.');
            return $this->createBlock->createBlock('core/html', array('content' => $this->restoreSvgCasing($html)), array(), $element);
        }

        // Paint addressed through a retained interaction host must stay in
        // the page cascade, rather than being frozen in an isolated image.
        for ( $host = $element->parentNode; $host instanceof DOMElement; $host = $host->parentNode ) {
            if ( ! str_contains($host->tagName, '-') || 0 === $host->getElementsByTagName('*')->length ) {
                continue;
            }
            foreach ( $host->getElementsByTagName('*') as $descendant ) {
                if ( $descendant instanceof DOMElement && $descendant->hasAttribute('data-motion-part') ) {
                    $image = $this->inlineSvgImageBlockFromMarkup($element, $this->materializeSanitizedSvgMarkup($html, $element));
                    if (null !== $image) {
                        // The attachment is Core's editable image. Keep its original
                        // document-context rendering only until that image is replaced.
                        $image['attrs']['metadata']['blocksEngineInlineSvg'] = array(
                            'svg' => $this->restoreSvgCasing($html),
                            'src' => $image['attrs']['url'],
                        );
                        return $image;
                    }
                    return $this->svgArtworkBlock($element, $this->restoreSvgCasing($html));
                }
            }
        }

        // Standalone images need intrinsic dimensions; inline artwork must
        // keep the authored box, with the viewBox supplying only its ratio.
        $inlineHtml = $this->restoreSvgCasing($html);
        $html = $this->materializeSanitizedSvgMarkup($html, $element);
        $imageBlock = $this->inlineSvgImageBlockFromMarkup($element, $html);
        if ( null !== $imageBlock ) {
            return $imageBlock;
        }

        // Links only work in the parent document, not an image document. Reuse
        // the typed artwork companion, but only when links are the sole reason
        // this SVG fails the native image compatibility checks.
        if ( $this->hasSvgRegionLink($element) && $this->isNativeImageCompatibleSvg($element, $html, true) ) {
            return $this->svgArtworkBlock($element, $inlineHtml);
        }

        if ( $this->hasPageCssAnimatedDescendant($element) ) {
            return $this->svgArtworkBlock($element, $inlineHtml);
        }

        // Honest floor: keep SVGs that need inline document context as sanitized
        // core/html, with viewBox-derived dimensions to avoid unbounded rendering.
        $this->recordGutenbergIncompatibility($element, 'svg_requires_inline_document_context', 'SVG uses behavior or external document features that cannot be represented as a static editable core/image asset.');
        return $this->createBlock->createBlock('core/html', array( 'content' => $html ), array(), $element);
    }

    /**
     * Size, paint and case sanitized SVG markup for emission.
     */
    private function materializeSanitizedSvgMarkup(string $html, DOMElement $element): string
    {
        $html = $this->cssOwnsMediaBox($element)
            ? $this->ensureInlineSvgBoxStyle($html, $element)
            : $this->ensureInlineSvgSizing($html, $element);
        $html = $this->bakeCascadedSvgTextLayout($this->resolveMaterializedSvgColors($html, $element), $element);

        return $this->restoreSvgCasing($this->inlineSvgMaterializationMarkup($html, $element));
    }

    /** Sanitized SVG markup that renders as an isolated image, author paint included. */
    public function standaloneSvgMarkup(DOMElement $element): string
    {
        $html = $this->sanitizeInlineSvgMarkup($element);
        if ( '' === $html || ! SourceDom::isSafeSvgContent($html) ) {
            return '';
        }

        return $this->withStandaloneDescendantPaint($element, $this->materializeSanitizedSvgMarkup($html, $element));
    }

    /**
     * A standalone SVG image cannot see the page stylesheet, and sanitization
     * drops the SVG's own `<style>` (which the page cascade already owns). Bake
     * each descendant's resolved paint, including inheritance from the root
     * and page ancestors, onto its inline style so the image keeps its colors.
     * Only the standalone asset gets this; inline SVG still inherits page CSS,
     * including state rules an inline style would override.
     */
    private function withStandaloneDescendantPaint(DOMElement $element, string $html): string
    {
        $clone = $element->cloneNode(true);
        if ( ! $clone instanceof DOMElement ) {
            return $html;
        }

        $sources = $element->getElementsByTagName('*');
        $targets = $clone->getElementsByTagName('*');
        $baked = false;
        for ( $index = 0, $count = $sources->length; $index < $count; ++$index ) {
            $source = $sources->item($index);
            $target = $targets->item($index);
            if ( ! $source instanceof DOMElement || ! $target instanceof DOMElement ) {
                continue;
            }

            $declarations = array();
            foreach ( array( 'fill', 'fill-opacity', 'stroke', 'stroke-opacity', 'stroke-width' ) as $property ) {
                $value = $this->styleResolver->resolvedInheritedSvgPaintValue($source, $property);
                // Keep standalone paint literal: no page-dependent variables,
                // URL references, expressions, markup or escape characters.
                // An identical presentation attribute already carries the value.
                if ( '' === $value || $value === trim(SourceDom::attr($source, $property)) || preg_match('/(?:var|url|expression)\s*\(|[<>@\\\\]/i', $value) ) {
                    continue;
                }
                $declarations[$property] = $value;
            }
            if ( array() === $declarations ) {
                continue;
            }

            $style = $this->styleResolver->cssDeclarationString(array_merge(SourceDeclarations::inline($target), $declarations));
            $target->setAttribute('style', $style);
            $baked = true;
        }
        if ( ! $baked ) {
            return $html;
        }

        $bakedHtml = $this->sanitizeInlineSvgMarkup($clone);

        return SourceDom::isSafeSvgContent($bakedHtml) ? $this->materializeSanitizedSvgMarkup($bakedHtml, $element) : $html;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function inlineSvgImageBlockFromMarkup(DOMElement $element, string $html): ?array
    {
        $attrs = $this->inlineSvgImageAttributesFromMarkup($element, $html);
        if ( null === $attrs ) {
            return null;
        }

        return $this->createBlock->createBlock('core/image', $attrs, array(), $element);
    }

    /**
     * Materialize a passive SVG as the native image object accepted by RichText.
     *
     * @return array<string, mixed>|null
     */
    private function inlineSvgImageAttributesFromMarkup(DOMElement $element, string $html, bool $richTextImage = false, ?string &$assetPath = null): ?array
    {
        if ( ! $this->isNativeImageCompatibleSvg($element, $html) ) {
            return null;
        }
        $html = $this->withStandaloneDescendantPaint($element, $html);

        $html = self::ensureSvgImageNamespace($this->minifyInlineSvgForImage($html));
        if ( $this->cssOwnsMediaBox($element) && ! $this->cssDefinesMediaSize($element) ) {
            // CSS that only limits the box (max-height, min-width, aspect-ratio)
            // still sizes from the image's natural dimensions. A standalone SVG
            // document without width/height has none, so a shrink-to-fit figure
            // collapses it to 0x0. Give the asset its viewBox size; the carried
            // CSS limits then scale it exactly as they scaled the inline SVG.
            $html = $this->ensureInlineSvgSizing($html);
        }
        $html = $this->withViewBoxIntrinsicSizeForRelativeSvg($html);
        $overflow = $richTextImage ? null : $this->visibleOverflowGeometry($element);
        if (null !== $overflow) {
            $html = preg_replace_callback('/<svg\b[^>]*>/i', static fn (array $match): string => preg_replace('/\s(?:viewBox|width|height)\s*=\s*(["\']).*?\1/i', '', $match[0]) ?? $match[0], $html, 1) ?? $html;
            $html = preg_replace('/<svg\b/i', '<svg viewBox="' . $overflow['viewBox'] . '" width="' . $overflow['width'] . '" height="' . $overflow['height'] . '"', $html, 1) ?? $html;
        }
        $assetHtml = $html;
        $visualPayload = $this->svgImageAssetIdentity($html);
        $path = $this->materializedInlineSvgPath($element, $visualPayload);
        $assetPath = $path;
        $url = $this->sourceRelativeMaterializedSvgPath($path);
        $occurrence = array_filter(array(
            'source_path' => $this->transformSourcePath(),
            'selector' => SourceDom::elementSelector($element),
            'fingerprint' => $this->session->reusableComponentState()->fingerprintForPath((string) $element->getNodePath()),
        ), static fn (mixed $value): bool => is_string($value) && '' !== $value);
        $asset = array(
            'source'      => 'inline-svg',
            'source_path' => $this->transformSourcePath(),
            'selector'    => SourceDom::elementSelector($element),
            'path'        => $path,
            'target_path' => $path,
            'source_url'  => $url,
            'kind'        => 'svg',
            'role'        => 'image',
            'mime_type'   => 'image/svg+xml',
            'media_type'  => 'image/svg+xml',
            'content'     => $assetHtml . "\n",
            'bytes'       => strlen($assetHtml) + 1,
            'encoding'    => 'utf-8',
            'binary'      => false,
            'source_role' => 'importer_owned',
            'keep_source' => false,
            'hash'        => hash('sha256', $assetHtml),
            'source_hash' => hash('sha256', $assetHtml),
            'pipeline_sanitized' => true,
            'visual_payload' => $visualPayload . "\n",
        );
        if (array() !== $occurrence) $asset['component_occurrences'] = array($occurrence);
        $this->session->assetMaterializationState()->registerInlineSvg($path, $asset, $occurrence, $visualPayload);

        if (null !== $overflow) {
            // Keep the original layout box. Only the image's painted surface
            // grows; absolute positioning prevents overflow from moving siblings.
            $figure = '{position:relative;margin:0;width:' . $overflow['boxWidth'] . 'px;height:' . $overflow['boxHeight'] . 'px;overflow:visible;line-height:0}';
            $image = '{position:absolute;display:block;max-width:none;max-height:none;width:' . $overflow['width'] . 'px;height:' . $overflow['height'] . 'px;left:' . $overflow['left'] . 'px;top:' . $overflow['top'] . 'px;margin:0}';
            $carrier = $this->session->layoutGeometryState()->allocateCarrier($this->styleResolver->geometryStructuralPath($element) . "\n" . $figure . $image);
            $selector = '.wp-block-image.' . $carrier;
            // The author SVG-to-image bridge comes later and sizes the original
            // box (e.g. .pill.wp-block-image > img { width:100% }). Repeat
            // the carrier on the painted leaf to outrank that bridge without
            // raising the specificity of the original figure's layout box.
            $paintSelector = $selector . '.' . $carrier;
            $this->session->layoutGeometryState()->registerRule($carrier, $selector . $figure . $paintSelector . '>img,' . $paintSelector . '>a>img' . $image);
            return array('url' => $url, 'alt' => $this->svgImageAlt($element), 'className' => $this->styleResolver->mergePresentationClassNames(SourceDom::attr($element, 'class'), $carrier));
        }

        $dimensions = $this->cssOwnsMediaBox($element) ? array() : $this->svgImageDimensions($element, $html);
        $presentation = $this->styleResolver->presentationDeclarations($element);
        $sourceDisplay = CssValueInspector::declared($presentation, 'display');
        $parent = $element->parentNode;
        $parentPresentation = $parent instanceof DOMElement ? $this->styleResolver->structuralPresentationDeclarations($parent) : array();
        // display:contents is not a percentage containing block. The effective
        // parent is the nearest ancestor that actually establishes a box.
        while ( $parent instanceof DOMElement && 'contents' === CssValueInspector::declared($parentPresentation, 'display') ) {
            $parent = $parent->parentNode;
            $parentPresentation = $parent instanceof DOMElement ? $this->styleResolver->structuralPresentationDeclarations($parent) : array();
        }
        $parentDisplay = CssValueInspector::declared($parentPresentation, 'display');
        $isFlexOrGridItem = in_array($parentDisplay, array( 'flex', 'inline-flex', 'grid', 'inline-grid' ), true);
        $sourceObjectFit = strtolower(trim((string) ($presentation['object-fit'] ?? '')));
        $isPositionedMediaBox = $parent instanceof DOMElement
            && in_array(strtolower(trim((string) ($parentPresentation['position'] ?? ''))), array( 'relative', 'absolute', 'fixed', 'sticky' ), true)
            && $this->declarationsOwnMediaBox($parentPresentation);
        // A responsive SVG (width/height="100%") fills a sized flex/grid wrapper,
        // or a positioned media wrapper when object-fit makes that intent explicit.
        // Make the generated core/image figure fill that wrapper and drop its
        // default margin instead of collapsing to intrinsic viewBox geometry.
        $sourceWidth = trim((string) ($presentation['width'] ?? SourceDom::attr($element, 'width')));
        $sourceHeight = trim((string) ($presentation['height'] ?? SourceDom::attr($element, 'height')));
        $fillsSizedParent = ! $richTextImage
            && $parent instanceof DOMElement
            && (
                (
                    CssValueInspector::hasDefiniteWidth($this->styleResolver->cssDeclarationString($parentPresentation))
                    && CssValueInspector::hasDefiniteHeight($this->styleResolver->cssDeclarationString($parentPresentation))
                    && '100%' === $sourceWidth && '100%' === $sourceHeight
                )
                || $this->fillsInsetPinnedParent($element, $sourceWidth, $sourceHeight, $parentPresentation)
            );
        $containerSize = $this->containerResolvedSvgSize($element, $html, $presentation, $parentPresentation);
        $isResponsiveFillSvg = (! $richTextImage && '100%' === ($containerSize['height'] ?? '')) || $fillsSizedParent || (
            (
            ($isFlexOrGridItem && $parent instanceof DOMElement && $this->declarationsOwnMediaBox($parentPresentation))
            || ($isPositionedMediaBox && in_array($sourceObjectFit, array( 'contain', 'cover', 'fill', 'none', 'scale-down' ), true))
            )
            && null !== $this->svgPercentageWidth(trim(SourceDom::attr($element, 'width')))
            && null !== $this->svgPercentageWidth(trim(SourceDom::attr($element, 'height')))
        );
        if ( $isResponsiveFillSvg ) {
            $dimensions = array();
            // This is generated fill geometry, not an authored image support.
            // Keep the line-box reset in the same carrier because core/image
            // metadata does not serialize typography.lineHeight.
            $figureRule = '{margin:0;width:100%;height:100%;line-height:0}';
            $objectFit = '' === $sourceObjectFit ? 'contain' : $sourceObjectFit;
            // WordPress core's `.wp-block-image img { height:auto }` is loaded
            // after theme styles. Include the native wrapper class so the fill
            // rule wins without forcing intrinsic media outside this explicit
            // parent-fill path.
            $resolvedSize = array() === $containerSize ? 'width:100%;height:100%' : 'width:' . $containerSize['width'] . ';height:' . $containerSize['height'] . ';max-width:100%;max-height:100%';
            $imgRule = '>img{' . $resolvedSize . ';-o-object-fit:' . $objectFit . ';object-fit:' . $objectFit . '}';
            // A media query that gives the SVG another size must still win
            // under its condition, at the fill rule's specificity, instead of
            // the fill forcing 100% at every viewport.
            $conditionedSizes = '';
            if ( $parent instanceof DOMElement ) {
                foreach ( $this->mediaConditionedFillStacks($element, $parent, $presentation) as $stack ) {
                    // A 100% restatement is what the fill already renders.
                    if ( ! $stack['full'] ) {
                        $conditionedSizes .= $this->wrapInConditions('.wp-block-image.{carrier}>img,.wp-block-image.{carrier}>a>img{' . implode(';', $stack['declarations']) . '}', $stack['conditions']);
                    }
                }
            }
            $fillClass = $this->session->layoutGeometryState()->allocateCarrier($this->styleResolver->geometryStructuralPath($element) . "\n" . $figureRule . $imgRule . $conditionedSizes);
            $this->session->layoutGeometryState()->registerRule($fillClass, $this->parentFillCss($fillClass, $figureRule, $imgRule) . str_replace('.{carrier}', '.' . $fillClass, $conditionedSizes));
            $attrs = array(
                'url'       => $url,
                'alt'       => $this->svgImageAlt($element),
                'className'  => $this->styleResolver->mergePresentationClassNames(SourceDom::attr($element, 'class'), $fillClass),
            );

            return array_filter($attrs, static fn ($value): bool => null !== $value && '' !== $value);
        }
        $preserveInlineGeometry = ! $isFlexOrGridItem && ( '' === $sourceDisplay || in_array($sourceDisplay, array( 'inline', 'inline-block' ), true) );
        $preserveBlockDisplay = ! $richTextImage && 'block' === $sourceDisplay;
        // An SVG with a viewBox but no size of its own fills its containing
        // block, even when its display is inline (including RichText icons).
        // The viewBox supplies an aspect ratio, not a used pixel size.
        $fillsContainingBlockWidth = array() !== $containerSize;
        if ( $fillsContainingBlockWidth ) {
            $dimensions = array();
        }
        $geometryClass = '';
        if ( $richTextImage || $preserveInlineGeometry || $preserveBlockDisplay || $fillsContainingBlockWidth ) {
            $imageDisplay = $preserveBlockDisplay ? 'block' : ('' === $sourceDisplay ? 'inline' : $sourceDisplay);
            $mediaBox = '';
            foreach ( array( 'width', 'height', 'min-width', 'max-width', 'min-height', 'max-height', 'aspect-ratio' ) as $property ) {
                if ( isset($presentation[$property]) && '' !== trim((string) $presentation[$property]) ) {
                    $mediaBox .= ';' . $property . ':' . trim((string) $presentation[$property]);
                }
            }
            if ( $fillsContainingBlockWidth ) {
                $mediaBox .= ';width:' . $containerSize['width'] . ';height:' . $containerSize['height'];
                if ( $isFlexOrGridItem ) {
                    $mediaBox .= ';max-width:100%;max-height:100%';
                }
            } elseif ( array() === $dimensions ) {
                $mediaBox .= $this->unsizedMediaAxisDeclarations($presentation);
            }
            // A RichText icon image must size like the <svg> it replaces. An
            // inline SVG has no `img{max-width:100%}` reset, which would make
            // the image a compressible box that contributes no min-content
            // width; and as a flex item it has no content-based minimum size,
            // while an image keeps its specified size as the automatic minimum.
            // Conditioned box rules share this rule's specificity and follow it.
            if ( $richTextImage && ! $fillsContainingBlockWidth ) {
                $restated = array( 'max-width' => 'none' );
                if ( in_array($parentDisplay, array( 'flex', 'inline-flex' ), true) ) {
                    $restated += array( 'min-width' => '0', 'min-height' => '0' );
                }
                foreach ( $restated as $property => $value ) {
                    if ( ! isset($presentation[$property]) || '' === trim((string) $presentation[$property]) ) {
                        $mediaBox .= ';' . $property . ':' . $value;
                    }
                }
            }
            $carriedProperties = $this->carriedCustomPropertyDeclarations($element, $mediaBox);
            $mediaBox = ( '' === $carriedProperties ? '' : ';' . $carriedProperties ) . $mediaBox;
            $rule = ($richTextImage ? '' : '>img') . '{display:' . $imageDisplay . ($preserveInlineGeometry ? ';vertical-align:' . $this->sourceVerticalAlign($element) : '') . $mediaBox . '}';
            $conditionedBox = array() === $dimensions ? $this->mediaConditionedBoxRules($element, $richTextImage ? '' : '>img') : array();
            $conditionedItemDisplay = $preserveInlineGeometry && ! $richTextImage && $parent instanceof DOMElement
                ? $this->mediaConditionedItemDisplay($parent, $imageDisplay)
                : array();
            // The parent fill the resting cascade could not see, under each
            // media condition that states it. Other viewports keep the
            // inline geometry above.
            $conditionedFills = array();
            if ( ! $richTextImage && $parent instanceof DOMElement && array() === $dimensions ) {
                foreach ( $this->mediaConditionedFillStacks($element, $parent, $presentation) as $stack ) {
                    if ( $stack['fills'] ) {
                        $conditionedFills[] = $stack['conditions'];
                    }
                }
            }
            $fillFigureRule = '{margin:0;width:100%;height:100%;line-height:0}';
            $fillObjectFit = '' === $sourceObjectFit ? 'contain' : $sourceObjectFit;
            $fillImgRule = '>img{width:100%;height:100%;-o-object-fit:' . $fillObjectFit . ';object-fit:' . $fillObjectFit . '}';
            // A percentage SVG width resolved against the SVG's definite
            // containing block. Give the figure that box so the image's
            // percentage resolves against it too. A shrink-to-fit figure makes
            // the percentage cyclic: an image that has not loaded yet (lazy)
            // contributes 0 width, so the figure and image stay 0x0.
            $figureFillsContainer = ! $richTextImage && (
                $fillsContainingBlockWidth
                || null !== $this->svgPercentageWidth((string) ($dimensions['width'] ?? ''))
                || null !== $this->svgPercentageWidth(trim((string) ($presentation['width'] ?? '')))
            );
            $figureWidthRule = $figureFillsContainer ? '{width:100%}' : '';
            $geometryClass = $this->session->layoutGeometryState()->allocateCarrier($this->styleResolver->geometryStructuralPath($element) . "\n" . $rule . $figureWidthRule . implode("\n", $conditionedBox) . implode("\n", $conditionedItemDisplay)
                . (array() === $conditionedFills ? '' : "\nfill:" . implode("\n", array_map(static fn (array $conditions): string => implode('{', $conditions), $conditionedFills))));
            // The image rule must outrank the generic author image bridge
            // (`:where(figure):not(<type>).wp-block-image > img{display:block}`),
            // and core/image wraps linked media in an anchor, so it carries the
            // native wrapper class and reaches the linked image too, as the
            // parent fill rules do.
            $geometryCss = ($preserveBlockDisplay ? '.' . $geometryClass . '{line-height:0}' : '')
                . ('' === $figureWidthRule ? '' : '.wp-block-image.' . $geometryClass . $figureWidthRule)
                . ($richTextImage
                    ? '.' . $geometryClass . $rule
                    : '.wp-block-image.' . $geometryClass . '>img,.wp-block-image.' . $geometryClass . '>a' . $rule);
            foreach ( array_merge($conditionedItemDisplay, $conditionedBox) as $conditionedRule ) {
                $geometryCss .= str_replace('.{carrier}', '.' . $geometryClass, $conditionedRule);
            }
            foreach ( $conditionedFills as $conditions ) {
                $geometryCss .= $this->wrapInConditions($this->parentFillCss($geometryClass, $fillFigureRule, $fillImgRule), $conditions);
            }
            if ( ! $richTextImage && null !== $this->svgPercentageWidth(trim(SourceDom::attr($element, 'width'))) ) {
                // Core/image wraps linked media in an inline anchor. Let a responsive
                // SVG resolve its percentage width against the sized figure, not its
                // shrink-to-fit link wrapper.
                $geometryCss .= '.' . $geometryClass . '>a{display:block;width:100%}';
            }
            $this->session->layoutGeometryState()->registerRule($geometryClass, $geometryCss);
        } elseif ( ! $richTextImage ) {
            // Core/image rejects typography.lineHeight. A standalone SVG still
            // needs its source line box removed when it becomes a figure.
            $rule = '{line-height:0}';
            $geometryClass = $this->session->layoutGeometryState()->allocateCarrier($this->styleResolver->geometryStructuralPath($element) . "\n" . $rule);
            $this->session->layoutGeometryState()->registerRule($geometryClass, '.' . $geometryClass . $rule);
        }
        $attrs = array_filter(array_merge(array(
            'url'          => $url,
            'alt'          => $this->svgImageAlt($element),
            'className'    => $this->styleResolver->mergePresentationClassNames(SourceDom::attr($element, 'class'), $geometryClass),
        ), $dimensions), static fn ($value): bool => null !== $value && '' !== $value);

        return $attrs;
    }

    /**
     * Return Gutenberg RichText's native image object markup for a passive SVG.
     * The image is deliberately an object inside the surrounding RichText rather
     * than a core/image block figure, which would break phrasing flow.
     */
    public function inlineSvgRichTextImageMarkup(DOMElement $element, bool $includeLink = true): ?string
    {
        // RichText lowering must obey the same document-context rule as blocks.
        if ( SourceDom::isDefinitionsOnlySvg($element) ) {
            return null;
        }
        if ( ! SourceDom::svgHasDrawableContent($element) ) {
            return null;
        }
        if ( $this->collapsedRestingRichTextPresentation($element) ) {
            return '';
        }

        $html = $this->sanitizeInlineSvgMarkup($element);
        if ( ! SourceDom::isSafeSvgContent($html) ) {
            return null;
        }

        $html = $this->materializeSanitizedSvgMarkup($html, $element);
        $attrs = $this->inlineSvgImageAttributesFromMarkup($element, $html, true);
        if ( null === $attrs ) {
            return null;
        }

        $style = trim(SourceDom::attr($element, 'style'));
        $sourceDimensions = array_filter(array(
            'width' => trim(SourceDom::attr($element, 'width')),
            'height' => trim(SourceDom::attr($element, 'height')),
        ), static fn (string $value): bool => '' !== $value);
        $resolvedSourceDimensions = $this->richTextSvgDimensions($element, $sourceDimensions);
        foreach ( array( 'width', 'height' ) as $dimension ) {
            if ( empty($resolvedSourceDimensions[$dimension]) || ($sourceDimensions[$dimension] ?? '') === $resolvedSourceDimensions[$dimension] ) {
                continue;
            }
            $style = trim($style, ';') . ( '' === trim($style, ';') ? '' : ';' ) . $dimension . ':' . $resolvedSourceDimensions[$dimension];
        }
        if ( $this->cssOwnsMediaBox($element) ) {
            $presentation = $this->styleResolver->presentationDeclarations($element);
            $resolved = $this->richTextSvgDimensions($element, $presentation);
            $declarations = SourceDeclarations::parse($style);
            foreach ( array( 'width', 'height', 'min-width', 'max-width', 'min-height', 'max-height', 'aspect-ratio' ) as $dimension ) {
                // Unchanged stylesheet values already live on the geometry
                // carrier, where conditioned rules can still override them.
                if ( ! isset($resolved[$dimension]) || ($presentation[$dimension] ?? '') === $resolved[$dimension] ) {
                    continue;
                }
                $declarations[$dimension] = $resolved[$dimension];
            }
            $style = $this->styleResolver->cssDeclarationString($declarations);
        }
        foreach ( array( 'width', 'height' ) as $dimension ) {
            if ( empty($attrs[$dimension]) || preg_match('/(?:^|;)\s*' . $dimension . '\s*:/i', $style) ) {
                continue;
            }
            $style = trim($style, ';') . ( '' === trim($style, ';') ? '' : ';' ) . $dimension . ':' . $attrs[$dimension];
        }

        $carriedProperties = $this->carriedCustomPropertyDeclarations($element, $style);
        if ( '' !== $carriedProperties ) {
            $style = $carriedProperties . ';' . $style;
        }

        $imageAttributes = array(
            'src' => (string) $attrs['url'],
            'alt' => (string) ($attrs['alt'] ?? ''),
            'class' => (string) ($attrs['className'] ?? ''),
            'style' => $style,
        );
        // RichText serializes an inline image object as a bare void tag, the
        // same spelling the editor saves and the DOM re-serializes on ingest.
        $markup = '<img' . HtmlSerialization::attributes($imageAttributes, array( 'alt' )) . '>';

        if ( $includeLink ) {
            $link = $this->svgImageLinkAttributes($element);
            if ( array() !== $link ) {
                $markup = '<a' . HtmlSerialization::attributes($link) . '>' . $markup . '</a>';
            }
        }

        return $markup;
    }

    private function collapsedRestingRichTextPresentation(DOMElement $element): bool
    {
        $container = null;
        for ( $node = $element->parentNode; $node instanceof DOMElement; $node = $node->parentNode ) {
            $tagName = strtolower($node->tagName);
            // Stop at the text block (or control), not a hidden outer section.
            if ( in_array($tagName, array( 'a', 'button', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li' ), true) ) {
                $container = $node;
                break;
            }
            if ( in_array($tagName, array( 'body', 'html' ), true) ) {
                return false;
            }
        }
        if ( ! $container instanceof DOMElement ) {
            return false;
        }

        for ( $node = $element; $node instanceof DOMElement && ! $node->isSameNode($container); $node = $node->parentNode instanceof DOMElement ? $node->parentNode : null ) {
            if ( $this->restingBoxIsCollapsed($node) ) {
                return true;
            }
        }

        return false;
    }

    private function restingBoxIsCollapsed(DOMElement $element): bool
    {
        if ( $element->hasAttribute('hidden') ) {
            return true;
        }
        foreach ( array( 'width', 'height' ) as $dimension ) {
            $captured = $this->capturedVisualLength($element, $dimension);
            if ( null !== $captured && $captured <= 0.0 ) {
                return true;
            }
        }

        $declarations = SourceDeclarations::parse($this->styleResolver->specificityResolvedPresentationStyle($element));
        $author = $this->styleResolver->authorStructuralDeclarations($element);
        if ( ! isset($declarations['visibility']) && isset($author['visibility']) ) {
            $declarations['visibility'] = $author['visibility'];
        }
        $display = CssValueInspector::declared($declarations, 'display');
        if ( 'none' === $display ) {
            return true;
        }
        $visibility = CssValueInspector::declared($declarations, 'visibility');
        if ( in_array($visibility, array( 'hidden', 'collapse' ), true) ) {
            return true;
        }
        foreach ( array( 'width', 'height', 'max-width', 'max-height' ) as $property ) {
            if ( $this->isCollapsedLength((string) ($declarations[$property] ?? '')) ) {
                return true;
            }
        }

        return false;
    }

    private function capturedVisualLength(DOMElement $element, string $dimension): ?float
    {
        $value = trim(SourceDom::attr($element, 'data-source-visual-' . $dimension));
        if ( 1 !== preg_match('/^(-?(?:\d+(?:\.\d+)?|\.\d+))(?:px)?$/', $value, $match) ) {
            return null;
        }

        return (float) $match[1];
    }

    private function isCollapsedLength(string $value): bool
    {
        return 1 === preg_match('/^0(?:\.0+)?(?:px|em|rem|%|vh|vw|vmin|vmax)?$/', CssValueInspector::comparable($value));
    }

    /**
     * Resolve percentage-sized RichText artwork before its structural wrapper is flattened.
     *
     * @param array<string, string> $dimensions
     * @return array<string, string>
     */
    private function richTextSvgDimensions(DOMElement $element, array $dimensions): array
    {
        foreach ( array( 'width', 'height' ) as $dimension ) {
            if ( ! preg_match('/^\s*((?:\d+(?:\.\d+)?|\.\d+))%\s*$/', (string) ($dimensions[$dimension] ?? ''), $match) ) {
                continue;
            }

            $scale = (float) $match[1] / 100.0;
            $fallback = '';
            for ( $parent = $element->parentNode; $parent instanceof DOMElement && in_array(strtolower($parent->tagName), array( 'div', 'span', 'i', 'b' ), true); $parent = $parent->parentNode ) {
                $parentDimensions = SourceDeclarations::parse($this->styleResolver->specificityResolvedPresentationStyle($parent));
                $parentDimension = trim((string) ($parentDimensions[$dimension] ?? ''));
                if ( preg_match('/^\s*((?:\d+(?:\.\d+)?|\.\d+))%\s*$/', $parentDimension, $parentMatch) ) {
                    $scale *= (float) $parentMatch[1] / 100.0;
                } elseif ( preg_match('/^\s*((?:\d+(?:\.\d+)?|\.\d+))px\s*$/i', $parentDimension, $parentMatch) ) {
                    $dimensions[$dimension] = $this->normalizedSvgDimension((float) $parentMatch[1] * $scale) . 'px';
                    continue 2;
                } elseif ( '' !== $parentDimension && abs($scale - 1.0) < 0.0001 ) {
                    $dimensions[$dimension] = $parentDimension;
                    continue 2;
                }

                $sourceVisualDimension = $this->capturedVisualLength($parent, $dimension);
                if ( '' === $fallback && null !== $sourceVisualDimension && $sourceVisualDimension > 0 ) {
                    $fallback = $this->normalizedSvgDimension($sourceVisualDimension * $scale) . 'px';
                }
            }
            if ( '' !== $fallback ) {
                $dimensions[$dimension] = $fallback;
            }
        }

        return $dimensions;
    }

    /** @return array<string, string> */
    private function svgImageLinkAttributes(DOMElement $element): array
    {
        if ( $this->ancestorProjectsAsAnchor($element) ) {
            return array();
        }

        $parent = $element->parentNode;
        if ( ! $parent instanceof DOMElement || 'a' !== strtolower($parent->tagName) ) {
            return array();
        }

        $href = trim(SourceDom::attr($parent, 'href'));
        if ( '' === $href || preg_match('/^\s*javascript\s*:/i', $href) ) {
            return array();
        }

        return array_filter(array(
            'href' => $href,
            'target' => trim(SourceDom::attr($parent, 'target')),
            'rel' => trim(SourceDom::attr($parent, 'rel')),
            'aria-label' => trim(SourceDom::attr($parent, 'aria-label')),
        ), static fn (string $value): bool => '' !== $value);
    }

    /**
     * Whether an ancestor already projects as the anchor around the
     * materialized image. A flow-content anchor stays the layout shell's
     * clickable container (tagName `a`) with every block it holds inside it,
     * so its link already covers the image; wrapping the image in its own
     * `<a href>` again nests anchor inside anchor, and the HTML parser closes
     * the outer anchor at the inner one, dropping the wrapped button's label.
     */
    private function ancestorProjectsAsAnchor(DOMElement $element): bool
    {
        for ( $node = $element->parentNode; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( $this->sourceElementClassifier->isFlowContentAnchor($node) ) {
                return true;
            }
        }

        return false;
    }

    public function svgNeedsPhrasingHost(DOMElement $element): bool
    {
        if ( $this->sourceElementClassifier->isVisualLayerElement($element) || 'none' === strtolower(trim(SourceDom::attr($element, 'preserveaspectratio'))) ) {
            return false;
        }

        $parent = $element->parentNode;
        if ( $parent instanceof DOMElement ) {
            $parentTag = strtolower($parent->tagName);
            if ( 'p' === $parentTag ) {
                return true;
            }
            if ( 'article' === $parentTag && 'img' === SourceDom::role($element) ) {
                return false;
            }
            if ( ( $this->sourceElementClassifier->isInlineContentElement($parentTag) || 'a' === $parentTag ) && $this->runtime->hasVisibleText(SourceDom::innerHtmlWithoutTags($parent, array( 'svg' ))) ) {
                return true;
            }
        }

        // A flex/grid child is a standalone layout item, even where its next
        // sibling is a block. Keep its native image figure as the media column.
        if ( $parent instanceof DOMElement && in_array(strtolower((string) ($this->styleResolver->structuralPresentationDeclarations($parent)['display'] ?? '')), array( 'flex', 'inline-flex', 'grid', 'inline-grid' ), true) ) {
            return false;
        }

        // An SVG directly beside a block starts a phrasing-to-block transition.
        // A paragraph is the editor-valid native host for the image object.
        for ( $sibling = $element->previousSibling; null !== $sibling; $sibling = $sibling->previousSibling ) {
            if ( $sibling instanceof DOMElement ) {
                $tag = strtolower($sibling->tagName);
                return 'svg' !== $tag && ! $this->sourceElementClassifier->isInlineContentElement($tag);
            }
        }
        for ( $sibling = $element->nextSibling; null !== $sibling; $sibling = $sibling->nextSibling ) {
            if ( $sibling instanceof DOMElement ) {
                $tag = strtolower($sibling->tagName);
                return 'svg' !== $tag && ! $this->sourceElementClassifier->isInlineContentElement($tag);
            }
        }

        return false;
    }

    /**
     * Author rules that matched the source subtree keep working on the
     * materialized image, but the custom properties they read were declared on
     * the wrapper elements the materialization collapses. A Wix button icon is
     * the common shape: `._animatedIcon svg{width:var(--size)}` survives onto
     * the image while `._animatedIcon{--size:16px}` has nothing left to match,
     * so the width is invalid at computed-value time and the artwork falls back
     * to its intrinsic viewBox geometry. Re-root the definitions the carried
     * declarations depend on.
     */
    private function carriedCustomPropertyDeclarations(DOMElement $element, string $value): string
    {
        $declarations = array();
        foreach ( $this->styleResolver->carriedCustomProperties($value, $element) as $name => $declared ) {
            $declarations[] = $name . ':' . $declared;
        }

        return implode(';', $declarations);
    }

    private function cssOwnsMediaBox(DOMElement $element): bool
    {
        if ( $this->declarationsOwnMediaBox($this->styleResolver->structuralPresentationDeclarations($element)) ) {
            return true;
        }

        // Responsive document variants retain author selectors that are scoped
        // to their composed document root. Those SVG leaves sit below the native
        // presentation boundary, but their author CSS still owns the viewport.
        return $this->declarationsOwnMediaBox($this->styleResolver->authorStructuralDeclarations($element));
    }

    /**
     * Whether source CSS gives the SVG a definite width or height, as opposed
     * to only min-/max- limits or an aspect ratio that still need an intrinsic
     * size to resolve against.
     */
    private function cssDefinesMediaSize(DOMElement $element): bool
    {
        foreach ( array( $this->styleResolver->structuralPresentationDeclarations($element), $this->styleResolver->authorStructuralDeclarations($element) ) as $declarations ) {
            foreach ( array( 'width', 'height' ) as $property ) {
                $value = strtolower(trim((string) ($declarations[$property] ?? '')));
                if ( '' !== $value && 'auto' !== $value ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Resolve container-owned sizing for a viewBox-only SVG. The viewBox
     * contributes a ratio, never fixed CSS pixels. A sole flex item in a
     * definite box shrinks on the main axis and stretches on the cross axis;
     * normal block flow instead derives its height from the intrinsic ratio.
     *
     * @param array<string, string> $presentation
     * @param array<string, string> $parentPresentation
     * @return array{width?: string, height?: string}
     */
    private function containerResolvedSvgSize(DOMElement $element, string $html, array $presentation, array $parentPresentation): array
    {
        if ( '' !== trim(SourceDom::attr($element, 'width')) || '' !== trim(SourceDom::attr($element, 'height')) ) {
            return array();
        }
        if ( $this->cssDefinesMediaSize($element) ) {
            return array();
        }
        // A height limit transfers through the aspect ratio only to an auto
        // width, so a limited SVG keeps the auto width.
        foreach ( array( 'width', 'height', 'aspect-ratio', 'min-height', 'max-height' ) as $property ) {
            $value = strtolower(trim((string) ($presentation[$property] ?? '')));
            if ( '' !== $value && 'auto' !== $value ) {
                return array();
            }
        }
        if ( 1 !== preg_match('/\sviewBox\s*=\s*(["\'])\s*[-\d.]+[\s,]+[-\d.]+[\s,]+[\d.]*[1-9][\d.]*[\s,]+[\d.]*[1-9][\d.]*\s*\1/i', $html) ) {
            return array();
        }
        $parentDisplay = CssValueInspector::declared($parentPresentation, 'display');
        $parentPosition = strtolower(trim((string) ($parentPresentation['position'] ?? '')));
        $parentFloat = strtolower(trim((string) ($parentPresentation['float'] ?? '')));

        if ( in_array($parentDisplay, array( 'flex', 'inline-flex' ), true) ) {
            $parent = $element->parentNode;
            $parentStyle = $this->styleResolver->cssDeclarationString($parentPresentation);
            $alignment = CssValueInspector::comparable((string) ($presentation['align-self'] ?? 'auto'));
            if ( 'auto' === $alignment ) {
                $alignment = CssValueInspector::comparable((string) ($parentPresentation['align-items'] ?? 'stretch'));
            }
            if ( $parent instanceof DOMElement && 1 === SourceDom::childElementCount($parent)
                && CssValueInspector::hasDefiniteWidth($parentStyle) && CssValueInspector::hasDefiniteHeight($parentStyle)
                && in_array($alignment, array( 'stretch', 'normal' ), true)
                && ! isset($presentation['flex']) && ! isset($presentation['flex-shrink'])
            ) {
                $direction = CssValueInspector::comparable((string) ($parentPresentation['flex-direction'] ?? 'row'));
                return in_array($direction, array( 'column', 'column-reverse' ), true)
                    ? array( 'width' => '100%', 'height' => 'auto' )
                    : array( 'width' => 'auto', 'height' => '100%' );
            }

            return array( 'width' => 'auto', 'height' => 'auto' );
        }

        return in_array($parentDisplay, array( '', 'block', 'flow-root', 'list-item' ), true)
            && ! in_array($parentPosition, array( 'absolute', 'fixed' ), true)
            && in_array($parentFloat, array( '', 'none' ), true)
            ? array( 'width' => '100%', 'height' => 'auto' ) : array();
    }

    /** Expand only proven overflow, leaving ordinary SVG asset identity untouched. */
    private function visibleOverflowGeometry(DOMElement $element): ?array
    {
        $declarations = $this->styleResolver->authorViewport->reference($element, array('overflow'));
        if ('visible' !== $this->plainDeclarationValue($declarations['overflow'] ?? SourceDom::attr($element, 'overflow'))) return null;
        $size = array();
        foreach (array('width', 'height') as $axis) {
            $node = $element;
            $scale = 1.0;
            while ($node instanceof DOMElement) {
                $style = array_merge(
                    $this->styleResolver->authorStructuralDeclarations($node),
                    $this->styleResolver->structuralPresentationDeclarations($node),
                    $this->styleResolver->presentationDeclarations($node),
                    $this->styleResolver->authorViewport->reference($node, array('width', 'height', 'display', 'position', 'float'))
                );
                $value = $this->plainDeclarationValue($style[$axis] ?? SourceDom::attr($node, $axis));
                if (preg_match('/^([\d.]+)(?:px)?$/', $value, $m)) { $size[$axis] = (float) $m[1] * $scale; break; }
                if (preg_match('/^([\d.]+)%$/', $value, $m)) $scale *= (float) $m[1] / 100;
                elseif ($node === $element || (!$this->declarationsPinInsetBox($style) && !$this->isUnsizedSvgWrapper($node, $style, $value))) return null;
                $node = $node->parentNode;
            }
            if (empty($size[$axis])) return null;
        }
        $bounds = SvgGeometryBounds::of($element);
        if (null === $bounds) return null;
        $vb = preg_split('/[\s,]+/', trim(SourceDom::attr($element, 'viewBox') ?: SourceDom::attr($element, 'viewbox')));
        $w = $size['width']; $h = $size['height'];
        $sx = $sy = 1.0; $tx = $ty = 0.0;
        if (count($vb) === 4 && count(array_filter($vb, 'is_numeric')) === 4 && (float) $vb[2] > 0 && (float) $vb[3] > 0) {
            $sx = $w / (float) $vb[2]; $sy = $h / (float) $vb[3];
            $par = trim(SourceDom::attr($element, 'preserveaspectratio'));
            if ('none' !== $par) {
                $sx = $sy = str_contains($par, 'slice') ? max($sx, $sy) : min($sx, $sy);
                $tx = (str_contains($par, 'xMin') ? 0 : (str_contains($par, 'xMax') ? 1 : 0.5)) * ($w - (float) $vb[2] * $sx);
                $ty = (str_contains($par, 'YMin') ? 0 : (str_contains($par, 'YMax') ? 1 : 0.5)) * ($h - (float) $vb[3] * $sy);
            }
            $tx -= (float) $vb[0] * $sx; $ty -= (float) $vb[1] * $sy;
        }
        $left = min(0, $bounds[0] * $sx + $tx); $top = min(0, $bounds[1] * $sy + $ty);
        $right = max($w, $bounds[2] * $sx + $tx); $bottom = max($h, $bounds[3] * $sy + $ty);
        if ($left >= 0 && $top >= 0 && $right <= $w && $bottom <= $h) return null;
        $format = static fn (float $v): string => rtrim(rtrim(sprintf('%.4F', $v), '0'), '.');
        return array('viewBox' => implode(' ', array_map($format, array(($left - $tx) / $sx, ($top - $ty) / $sy, ($right - $left) / $sx, ($bottom - $top) / $sy))),
            'boxWidth' => $format($w), 'boxHeight' => $format($h), 'width' => $format($right - $left), 'height' => $format($bottom - $top), 'left' => $format($left), 'top' => $format($top));
    }

    /** Plain wrapper divs pass the percentage SVG's containing box through. */
    private function isUnsizedSvgWrapper(DOMElement $node, array $style, string $axisValue): bool
    {
        return 'div' === strtolower($node->tagName)
            && in_array($axisValue, array('', 'auto'), true)
            && in_array($this->plainDeclarationValue($style['display'] ?? ''), array('', 'block'), true)
            && in_array($this->plainDeclarationValue($style['position'] ?? ''), array('', 'static', 'relative'), true)
            && in_array($this->plainDeclarationValue($style['float'] ?? ''), array('', 'none'), true);
    }

    private function unsizedMediaAxisDeclarations(array $presentation): string
    {
        $declarations = '';
        foreach ( array( 'width', 'height' ) as $axis ) {
            if ( '' === trim((string) ($presentation[$axis] ?? '')) ) {
                $declarations .= ';' . $axis . ':auto';
            }
        }

        return $declarations;
    }

    /**
     * Whether the SVG fills, on both axes, a wrapper whose box is pinned to its
     * containing block on all four sides (`position:absolute; inset:0`).
     *
     * Such a wrapper has a definite size without declaring width or height.
     * Wix vector images (logos) are built this way, with the SVG sized
     * `var(--svg-calculated-width,100%)` and the custom property left unset,
     * so the used size is the 100% fallback. Without the fill, the
     * materialized viewBox-only image has no intrinsic width and collapses to
     * 0x0 inside core/image's shrink-to-fit link.
     *
     * @param array<string, string> $parentPresentation
     */
    private function fillsInsetPinnedParent(DOMElement $element, string $sourceWidth, string $sourceHeight, array $parentPresentation): bool
    {
        if ( ! $this->declarationsPinInsetBox($parentPresentation) ) {
            return false;
        }
        $resolve = fn (string $value): string => $this->styleResolver->resolveStructuralCssVariablesInValue($value, $element);

        return $this->resolvesToFullAxis($sourceWidth, $resolve) && $this->resolvesToFullAxis($sourceHeight, $resolve);
    }

    /**
     * Whether `position:absolute|fixed` plus a non-auto inset on all four
     * sides gives this box a definite size from its containing block.
     *
     * @param array<string, string> $declarations
     */
    private function declarationsPinInsetBox(array $declarations): bool
    {
        if ( ! in_array($this->plainDeclarationValue($declarations['position'] ?? ''), array( 'absolute', 'fixed' ), true) ) {
            return false;
        }

        $shorthand = preg_split('/\s+/', $this->plainDeclarationValue($declarations['inset'] ?? '')) ?: array();
        $shorthand = array_values(array_filter($shorthand, static fn (string $part): bool => '' !== $part));
        $count = count($shorthand);
        $fromShorthand = static fn (int $side): string => match ($count) {
            1 => $shorthand[0],
            2 => $shorthand[$side % 2],
            3 => $shorthand[3 === $side ? 1 : $side],
            4 => $shorthand[$side],
            default => '',
        };
        foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side => $property ) {
            $value = $this->plainDeclarationValue($declarations[$property] ?? '');
            $value = '' === $value ? $fromShorthand($side) : $value;
            if ( '' === $value || 'auto' === $value || str_contains($value, 'var(') ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param callable(string): string $resolveVariables
     */
    private function resolvesToFullAxis(string $value, callable $resolveVariables): bool
    {
        $value = $this->plainDeclarationValue($value);
        if ( str_contains($value, 'var(') ) {
            $value = $this->plainDeclarationValue($resolveVariables($value));
        }

        return '100%' === $value;
    }

    /**
     * The inset-pinned parent fill, evaluated per media condition.
     *
     * A responsive capture links its desktop stylesheet under
     * `media="(min-width:768px)"`, so the SVG's 100% size and its wrapper's
     * `position:absolute; inset:0` reach the materializer only as media rules
     * that the resting cascade leaves out. Each condition stack that states a
     * width or height for the SVG (see
     * {@see StyleResolver::mediaConditionedBoxDeclarations()}) is checked on
     * its own: the SVG must resolve both axes to 100% (custom properties
     * resolved under the same conditions first) and its parent must be pinned
     * by declarations that hold under exactly those conditions or at rest.
     *
     * @param array<string, string> $presentation The SVG's resting declarations.
     * @return list<array{conditions: list<string>, full: bool, fills: bool, declarations: array<string, string>}>
     */
    private function mediaConditionedFillStacks(DOMElement $element, DOMElement $parent, array $presentation): array
    {
        $groups = array();
        foreach ( $this->styleResolver->mediaConditionedBoxDeclarations($element, array( 'width', 'height' )) as $entry ) {
            $key = implode("\n", $entry['conditions']);
            $groups[$key] ??= array( 'conditions' => $entry['conditions'], 'values' => array(), 'customProperties' => array() );
            $groups[$key]['values'][$entry['property']] = $entry['value'];
            $groups[$key]['customProperties'] += $entry['customProperties'];
        }
        if ( array() === $groups ) {
            return array();
        }

        $parentDeclared = array();
        foreach ( array( 'position', 'inset', 'top', 'right', 'bottom', 'left' ) as $property ) {
            $parentDeclared[$property] = $this->styleResolver->declaredPresentation($parent, $property);
        }

        $stacks = array();
        foreach ( $groups as $group ) {
            $stack = $group['conditions'];
            $holds = static function (array $conditions) use ($stack): bool {
                return $stack === array_values(array_filter(
                    array_map('trim', $conditions),
                    static fn (string $condition): bool => 1 !== preg_match('/^@layer\b/i', $condition)
                ));
            };
            $parentDeclarations = array();
            foreach ( $parentDeclared as $property => $declared ) {
                $parentDeclarations[$property] = $declared->resolvedValueWhere($holds);
            }
            $customProperties = $group['customProperties'];
            $resolve = fn (string $value): string => CssVariableExpander::expand(
                $value,
                static fn (string $name): ?string => isset($customProperties[$name]) && '' !== trim($customProperties[$name]) ? trim($customProperties[$name]) : null
            ) ?? $value;
            $resolveAll = fn (string $value): string => $this->styleResolver->resolveStructuralCssVariablesInValue($resolve($value), $element);
            $width = (string) ($group['values']['width'] ?? ($presentation['width'] ?? ''));
            $height = (string) ($group['values']['height'] ?? ($presentation['height'] ?? ''));
            $declarations = array();
            foreach ( $customProperties as $name => $declared ) {
                $declarations[$name] = $name . ':' . $declared;
            }
            foreach ( $group['values'] as $property => $value ) {
                $declarations[$property] = $property . ':' . $value;
            }
            $full = $this->resolvesToFullAxis($width, $resolveAll) && $this->resolvesToFullAxis($height, $resolveAll);
            $stacks[] = array(
                'conditions' => $stack,
                'full' => $full,
                'fills' => $full && $this->declarationsPinInsetBox($parentDeclarations),
                'declarations' => $declarations,
            );
        }

        return $stacks;
    }

    /**
     * The vertical alignment the inline source SVG takes in its line box: the
     * author cascade winner at the desktop reference viewport, or `baseline`.
     * An author `img,svg{vertical-align:middle}` centers the icon on the
     * line; core/image's own `vertical-align:bottom` must not replace it.
     */
    private function sourceVerticalAlign(DOMElement $element): string
    {
        $declared = CssValueInspector::withoutImportant((string) ($this->styleResolver->authorViewport->reference($element, array( 'vertical-align' ))['vertical-align'] ?? ''));
        $declared = strtolower(trim($declared));

        return 1 === preg_match('/^(?:baseline|sub|super|text-top|text-bottom|middle|top|bottom|-?\d*\.?\d+(?:px|em|rem|%))$/', $declared) ? $declared : 'baseline';
    }

    /** The figure, link and img rules that make a core/image fill its parent. */
    private function parentFillCss(string $class, string $figureRule, string $imgRule): string
    {
        // WordPress core's `.wp-block-image img { height:auto }` is loaded
        // after theme styles; the native wrapper class lets the fill win.
        return '.' . $class . $figureRule . '.wp-block-image.' . $class . $imgRule
            . '.wp-block-image.' . $class . str_replace('>img', '>a>img', $imgRule) . '.' . $class . '>a{display:block;width:100%;height:100%}';
    }

    /**
     * Restate the inline image display under each media condition that
     * changes the source parent's display.
     *
     * A source SVG sits in a line box only while its parent is a block
     * container. Under a condition where the parent is a flex or grid
     * container, the SVG is blockified into a flex/grid item with no line box,
     * so the carrier's inline geometry (and the strut it brings) must yield to
     * `display:block` there. Entries are emitted in source cascade order, so
     * overlapping conditions resolve as they do in the source.
     *
     * Returns rule strings with a `.{carrier}` placeholder for the class.
     *
     * @return list<string>
     */
    private function mediaConditionedItemDisplay(DOMElement $parent, string $inlineDisplay): array
    {
        $rules = array();
        $selector = '.wp-block-image.{carrier}>img,.wp-block-image.{carrier}>a>img';
        foreach ( $this->styleResolver->conditionalDisplayRules($parent) as $conditions => $display ) {
            $display = $this->plainDeclarationValue((string) $display);
            if ( 'contents' === $display ) {
                continue;
            }
            // The resting rule still carries vertical-align and the box; a
            // condition only decides whether the image stays inline.
            $blockified = in_array($display, array( 'flex', 'inline-flex', 'grid', 'inline-grid' ), true);
            $rules[] = $this->wrapInConditions($selector . '{display:' . ($blockified ? 'block' : $inlineDisplay) . '}', explode('{', (string) $conditions));
        }

        return $rules;
    }

    /** @param list<string> $conditions */
    private function wrapInConditions(string $css, array $conditions): string
    {
        foreach ( array_reverse($conditions) as $condition ) {
            $css = $condition . '{' . $css . '}';
        }

        return $css;
    }

    private function plainDeclarationValue(string $value): string
    {
        return strtolower(trim(preg_replace('/\s*!\s*important\s*$/i', '', trim($value)) ?? $value));
    }

    /**
     * Restate media-scoped author sizing on the materialized image carrier.
     *
     * Source rules that size an inline SVG from a wrapper (`.icon svg{width:24px}`)
     * never reach the generated `<img>`: no `svg` selector matches it, and the
     * wrapper is often flattened. Resting sizes already move onto the carrier
     * through {@see StyleResolver::presentationDeclarations()}. Carry their
     * overrides and sizes stated only by media queries under the same conditions
     * so other viewports keep the source's own behaviour.
     *
     * Returns rule strings with a `.{carrier}` placeholder for the class.
     *
     * @return list<string>
     */
    private function mediaConditionedBoxRules(DOMElement $element, string $selectorSuffix): array
    {
        // Both resting sizes and unsized-axis defaults belong to this
        // carrier. Project their conditioned replacements at the same tier.
        $properties = array( 'width', 'height', 'min-width', 'max-width', 'min-height', 'max-height', 'aspect-ratio' );

        $rules = array();
        $current = null;
        foreach ( $this->styleResolver->mediaConditionedBoxDeclarations($element, $properties) as $entry ) {
            if ( null === $current || $current['conditions'] !== $entry['conditions'] ) {
                if ( null !== $current ) {
                    $rules[] = $current;
                }
                $current = array( 'conditions' => $entry['conditions'], 'declarations' => array() );
            }
            foreach ( $entry['customProperties'] as $name => $declared ) {
                $current['declarations'][$name] ??= $name . ':' . $declared;
            }
            unset($current['declarations'][$entry['property']]);
            $current['declarations'][$entry['property']] = $entry['property'] . ':' . $entry['value'];
        }
        if ( null !== $current ) {
            $rules[] = $current;
        }

        return array_map(static function (array $rule) use ($selectorSuffix): string {
            $css = '.{carrier}' . $selectorSuffix . '{' . implode(';', $rule['declarations']) . '}';
            foreach ( array_reverse($rule['conditions']) as $condition ) {
                $css = $condition . '{' . $css . '}';
            }
            return $css;
        }, $rules);
    }

    /**
     * @param array<string, string> $declarations
     */
    private function declarationsOwnMediaBox(array $declarations): bool
    {
        foreach ( array( 'width', 'height', 'min-width', 'max-width', 'min-height', 'max-height', 'aspect-ratio' ) as $property ) {
            if ( isset($declarations[$property]) && '' !== trim((string) $declarations[$property]) ) {
                return true;
            }
        }

        return false;
    }

    private function minifyInlineSvgForImage(string $html): string
    {
        $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
        $html = preg_replace('/>\s+</', '><', $html) ?? $html;
        return trim($html);
    }

    /**
     * SVG image assets are standalone documents. Copy local definitions used by
     * the image into its payload so a document-level capture store is not needed
     * at render time.
     */
    private function inlineSvgMaterializationMarkup(string $html, DOMElement $element): string
    {
        if ( ! $element->ownerDocument instanceof \DOMDocument ) {
            return $html;
        }

        $references = array();
        if ( preg_match_all('/\burl\(\s*["\']?#([^\s"\')]+)["\']?\s*\)|\b(?:href|xlink:href)\s*=\s*["\']#([^"\']+)["\']/i', $html, $matches, PREG_SET_ORDER) ) {
            foreach ( $matches as $match ) {
                $id = trim((string) ('' !== ($match[1] ?? '') ? $match[1] : ($match[2] ?? '')));
                if ( '' !== $id ) {
                    $references[$id] = true;
                }
            }
        }
        if ( array() === $references ) {
            return $html;
        }
        foreach ( $element->getElementsByTagName('*') as $localDefinition ) {
            if ( $localDefinition instanceof DOMElement ) {
                unset($references[trim(SourceDom::attr($localDefinition, 'id'))]);
            }
        }
        if ( array() === $references ) {
            return $html;
        }

        // The first element with a referenced id is its definition, as in the
        // browser. Copy its <defs>, or a bare sprite <symbol> or <svg> on its own.
        $definitions = array();
        foreach ( $element->ownerDocument->getElementsByTagName('*') as $definition ) {
            $id = $definition instanceof DOMElement ? trim(SourceDom::attr($definition, 'id')) : '';
            if ( '' === $id || ! isset($references[$id]) || SourceDom::elementContains($element, $definition) ) {
                continue;
            }
            unset($references[$id]);
            $defs = $definition;
            while ( $defs instanceof DOMElement && 'defs' !== strtolower($defs->tagName) ) {
                $defs = $defs->parentNode;
            }
            $copy = $defs instanceof DOMElement ? SourceDom::safeFallbackHtml($defs, $this->session->authorSelectorProjectionState()->tagMarkers())
                : ( in_array(strtolower($definition->tagName), array( 'symbol', 'svg' ), true) ? '<defs>' . SourceDom::safeFallbackHtml($definition, $this->session->authorSelectorProjectionState()->tagMarkers()) . '</defs>' : '' );
            // A <use> instance inherits custom properties from the <use>, so
            // its copied paint resolves var() in that scope.
            $definitions[] = str_contains($copy, 'var(') ? $this->resolveCssVariablesInSvgMarkup($copy, $this->svgReferrer($element, $id)) : $copy;
        }
        if ( array() === $definitions ) {
            return $html;
        }

        // Copied definitions paint through the referencing <use>, which
        // inherits the SVG's color.
        $copied = implode('', array_unique($definitions));
        if ( false !== stripos($copied, 'currentColor') ) {
            $copied = preg_replace('/\bcurrentColor\b/i', $this->inheritedSvgColor($element), $copied) ?? $copied;
        }

        return preg_replace('/<\/svg>\s*$/i', $copied . '</svg>', $html, 1) ?? $html;
    }

    /** The element inside $svg that references #$id, or $svg itself. */
    private function svgReferrer(DOMElement $svg, string $id): DOMElement
    {
        foreach ( $svg->getElementsByTagName('*') as $candidate ) {
            if ( '#' . $id === trim(SourceDom::attr($candidate, 'href')) || '#' . $id === trim(SourceDom::attr($candidate, 'xlink:href')) ) {
                return $candidate;
            }
        }

        return $svg;
    }

    private function svgImageAssetIdentity(string $html): string
    {
        // core/image owns the accessible name through its per-instance alt
        // attribute. Accessibility metadata therefore does not participate in
        // visual asset identity, while the first materialized payload retains
        // its original metadata for compatibility with direct SVG consumers.
        $html = preg_replace('/\s(?:aria-[a-z-]+|title)\s*=\s*(["\']).*?\1/i', '', $html) ?? $html;
        // Engine class tokens are scoped to the document that compiled the SVG
        // and style nothing inside a standalone image document, so the same
        // icon compiled on two routes is one asset, not two.
        $html = preg_replace_callback('/\sclass\s*=\s*(["\'])(.*?)\1/is', static function (array $match): string {
            $classes = array_filter(SourceDom::tokens($match[2]), static fn (string $class): bool => '' !== $class && !EngineMarker::isSpecificityShim($class) && 1 !== preg_match('/^blocks-engine-(?:source-[a-z0-9_-]+|attribute(?:-state)?|richtext|control)-[a-f0-9]{6,}(?:-\d+)?$/', $class));
            return array() === $classes ? '' : ' class=' . $match[1] . implode(' ', $classes) . $match[1];
        }, $html) ?? $html;
        $html = preg_replace('/<(?:title|desc)\b[^>]*>.*?<\/(?:title|desc)>/is', '', $html) ?? $html;
        return self::withDocumentOrderLocalIds($html);
    }

    /**
     * A standalone SVG document's ids name only its own definitions, so their
     * spelling is not part of the picture: a page author who gives each
     * route's copy of a logo its own gradient id still draws one logo. Name
     * each local id by its document order. A payload that spells an id
     * anywhere other than a definition or a `url(#)`/`href="#"` reference
     * keeps the authored names, since that other use may depend on them.
     */
    private static function withDocumentOrderLocalIds(string $html): string
    {
        $reference = '\sid\s*=\s*(["\'])([^"\']+)\1|\burl\(\s*(["\']?)#([^\s"\')]+)\3\s*\)|\b((?:xlink:)?href)\s*=\s*(["\'])#([^"\']+)\6';
        if (!preg_match_all('/\sid\s*=\s*(["\'])([^"\']+)\1/i', $html, $definitions)) return $html;
        $names = array();
        foreach ($definitions[2] as $id) if (!isset($names[$id])) $names[$id] = 'svg-local-' . count($names);
        $recognized = array();
        preg_match_all('/' . $reference . '/i', $html, $references, PREG_SET_ORDER);
        foreach ($references as $match) {
            $id = (string) (($match[2] ?? '') ?: (($match[4] ?? '') ?: ($match[7] ?? '')));
            $recognized[$id] = ($recognized[$id] ?? 0) + 1;
        }
        foreach (array_keys($names) as $id) {
            if (preg_match_all('/(?<![\w-])' . preg_quote((string) $id, '/') . '(?![\w-])/', $html) !== ($recognized[$id] ?? 0)) return $html;
        }
        return preg_replace_callback('/' . $reference . '/i', static function (array $match) use ($names): string {
            if ('' !== ($match[2] ?? '')) return isset($names[$match[2]]) ? ' id=' . $match[1] . $names[$match[2]] . $match[1] : $match[0];
            if ('' !== ($match[4] ?? '')) return isset($names[$match[4]]) ? 'url(' . $match[3] . '#' . $names[$match[4]] . $match[3] . ')' : $match[0];
            return isset($names[$match[7]]) ? $match[5] . '=' . $match[6] . '#' . $names[$match[7]] . $match[6] : $match[0];
        }, $html) ?? $html;
    }

    /**
     * A standalone SVG document (an image file or a data URI) must declare
     * every namespace it uses. Inline SVG gets them from the HTML parser; on
     * its own, an undeclared `xlink:` prefix makes the browser reject the
     * whole image, so a sprite icon (`<use xlink:href="#id">`) shows as broken.
     */
    public static function ensureSvgImageNamespace(string $html): string
    {
        // Only the root element counts: a nested `<svg xmlns>` (as Wix emits
        // inside its shape wrapper) leaves the document root unnamespaced.
        if ( ! preg_match('/^\s*<svg\b[^>]*\sxmlns\s*=/i', $html) ) {
            $html = preg_replace('/<svg\b/i', '<svg xmlns="http://www.w3.org/2000/svg"', $html, 1) ?? $html;
        }
        if ( preg_match('/<[a-z][^>]*\sxlink:[a-z]+\s*=/i', $html) && ! preg_match('/^\s*<svg\b[^>]*\sxmlns:xlink\s*=/i', $html) ) {
            $html = preg_replace('/<svg\b/i', '<svg xmlns:xlink="http://www.w3.org/1999/xlink"', $html, 1) ?? $html;
        }

        return $html;
    }

    private function resolveMaterializedSvgColors(string $html, DOMElement $element): string
    {
        if ( str_contains($html, 'var(') || false !== stripos($html, 'currentColor') ) {
            $sources = SourceDom::subtreeElements($element);
            $index = 0;
            $html = preg_replace_callback('/<([a-z][a-z0-9:-]*)\b[^>]*>/i', function (array $match) use ($sources, &$index): string {
                // Sanitization can remove source tags. Walk surviving tags in
                // source order so each descendant retains its own paint scope.
                while ( isset($sources[$index]) && strtolower($sources[$index]->tagName) !== strtolower($match[1]) ) {
                    ++$index;
                }
                $source = $sources[$index++] ?? null;
                if ( ! $source instanceof DOMElement ) {
                    return $match[0];
                }
                $tag = $this->resolveCssVariablesInSvgMarkup($match[0], $source);
                return false !== stripos($tag, 'currentColor')
                    ? (preg_replace('/\bcurrentColor\b/i', $this->inheritedSvgColor($source), $tag) ?? $tag)
                    : $tag;
            }, $html) ?? $html;
        }

        return $this->bakeCascadedSvgPaint($html, $element);
    }

    /**
     * Expand `var()` inside SVG markup attributes. Resolved values such as a
     * quoted font stack must be escaped for the attribute they land in;
     * substituting them into the raw HTML string would terminate the attribute
     * at the first `"`.
     */
    private function resolveCssVariablesInSvgMarkup(string $html, DOMElement $element): string
    {
        if ( false === strpos($html, 'var(') ) {
            return $html;
        }

        $html = preg_replace_callback(
            '/(\s[^\s=<>\/]+)\s*=\s*(["\'])(.*?)\2/s',
            function (array $match) use ($element): string {
                $decoded = html_entity_decode($match[3], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                if ( false === strpos($decoded, 'var(') ) {
                    return $match[0];
                }

                $resolved = $this->styleResolver->resolveCssVariablesInValue($decoded, $element);
                if ( $resolved === $decoded ) {
                    return $match[0];
                }

                return $match[1] . '=' . $match[2] . htmlspecialchars($resolved, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . $match[2];
            },
            $html
        ) ?? $html;

        if ( false !== strpos($html, 'var(') ) {
            $html = $this->styleResolver->resolveCssVariablesInValue($html, $element);
        }

        return $html;
    }

    private function inheritedSvgColor(DOMElement $element): string
    {
        $color = $this->resolvedCascadedSvgPaint($element, 'color');

        return null !== $color ? $color : '#000000';
    }

    /**
     * The materialized SVG becomes an isolated document: an `<img src>` (or a
     * standalone core/html payload) cannot inherit `fill`/`stroke` from the
     * host page's stylesheet the way the inline source SVG could. Resolve the
     * effective cascade value for each inheritable paint property — from the
     * element's own matched CSS/inline style, or the nearest ancestor that
     * declares one — and serialize it onto the root `<svg>` so the standalone
     * asset renders identically without depending on any external CSS.
     *
     * An element's own literal presentation attribute (e.g. `fill="#fff"`)
     * already travels with the markup verbatim and is left untouched unless a
     * matched CSS declaration overrides it, mirroring browser cascade order.
     */
    private function bakeCascadedSvgPaint(string $html, DOMElement $element): string
    {
        $paint = array();
        foreach ( array( 'fill', 'stroke' ) as $property ) {
            $value = $this->resolvedCascadedSvgPaint($element, $property);
            if ( null !== $value ) {
                $paint[$property] = $value;
            }
        }

        return array() === $paint ? $html : $this->mergeSvgRootStyleDeclarations($html, $paint);
    }

    /**
     * Text layout and presentation rules from the host stylesheet cannot cross
     * the standalone SVG image boundary. Preserve the resolved per-node values
     * in source order. `<textPath>` only carries its own values: it already
     * inherits from its `<text>`, and re-baking inherited relative sizes would
     * compound them.
     */
    private function bakeCascadedSvgTextLayout(string $html, DOMElement $element): string
    {
        $sourceTexts = array_values(array_filter(
            iterator_to_array($element->getElementsByTagName('*')),
            static fn (DOMNode $node): bool => $node instanceof DOMElement && in_array(strtolower($node->localName), array('text', 'textpath'), true)
        ));
        if ( array() === $sourceTexts || false === stripos($html, '<text') ) {
            return $html;
        }

        $index = 0;
        return preg_replace_callback('/<text(?:path)?\b[^>]*>/i', function (array $match) use ($sourceTexts, &$index): string {
            $source = $sourceTexts[$index++] ?? null;
            if ( ! $source instanceof DOMElement ) {
                return $match[0];
            }

            $inherit = 'text' === strtolower($source->localName);
            $declarations = array();
            foreach ( StyleResolver::SVG_TEXT_CASCADE_PROPERTIES as $property ) {
                $value = $this->resolvedCascadedSvgTextLayout($source, $property, $inherit);
                if ( '' !== $value && 1 !== preg_match('/(?:var\s*\(|url\s*\(|[<>])/i', $value) ) {
                    $declarations[$property] = $value;
                }
            }

            return array() === $declarations ? $match[0] : $this->mergeSvgTagStyleDeclarations($match[0], $declarations);
        }, $html) ?? $html;
    }

    private function resolvedCascadedSvgTextLayout(DOMElement $element, string $property, bool $inherit = true): string
    {
        for ( $current = $element; $current instanceof DOMElement; $current = $inherit && $current->parentNode instanceof DOMElement ? $current->parentNode : null ) {
            $value = trim((string) ($this->styleResolver->specificityResolvedSvgCascadeValue($current, $property) ?? ''));
            if ( '' !== $value ) {
                if ( false !== strpos($value, 'var(') ) {
                    return '';
                }
                $keyword = strtolower($value);
                if ( in_array($keyword, array('inherit', 'unset'), true) ) {
                    continue;
                }
                return in_array($keyword, array('initial', 'revert', 'revert-layer'), true) ? '' : $value;
            }
            $attribute = trim(SourceDom::attr($current, $property));
            if ( '' !== $attribute ) {
                if ( $current->isSameNode($element) ) {
                    return '';
                }
                $keyword = strtolower($attribute);
                if ( in_array($keyword, array('inherit', 'unset'), true) ) {
                    continue;
                }
                return in_array($keyword, array('initial', 'revert', 'revert-layer'), true) ? '' : $attribute;
            }
        }

        return '';
    }

    /** @param array<string, string> $declarations */
    private function mergeSvgTagStyleDeclarations(string $tag, array $declarations): string
    {
        $existing = '';
        if ( preg_match('/\sstyle\s*=\s*(["\'])(.*?)\1/i', $tag, $styleMatch) ) {
            $existing = html_entity_decode($styleMatch[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $style = $this->styleResolver->cssDeclarationString(array_merge(SourceDeclarations::parse($existing), $declarations));
        $escaped = htmlspecialchars($style, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return preg_match('/\sstyle\s*=\s*(["\'])(.*?)\1/i', $tag)
            ? (preg_replace('/(\sstyle\s*=\s*)(["\'])(.*?)\2/i', '$1$2' . $escaped . '$2', $tag, 1) ?? $tag)
            : (preg_replace('/>$/', ' style="' . $escaped . '">', $tag, 1) ?? $tag);
    }

    private function resolvedCascadedSvgPaint(DOMElement $element, string $property): ?string
    {
        for ( $current = $element; $current instanceof DOMElement; $current = $current->parentNode instanceof DOMElement ? $current->parentNode : null ) {
            $resolved = $this->styleResolver->resolvedSvgCascadeValue($current, $property);
            if ( null === $resolved ) {
                $attribute = trim(SourceDom::attr($current, $property));
                if ( 'color' === $property && '' !== $attribute ) {
                    // color is itself inherited by currentColor artwork. Unlike
                    // fill/stroke, leaving its attribute in markup does not tell
                    // the materializer which color to bake into that artwork.
                    $resolved = $this->styleResolver->resolveCssVariablesInValue($attribute, $current);
                } elseif ( $current === $element && '' !== $attribute ) {
                    // The element's own presentation attribute already carries
                    // this paint into the materialized markup verbatim.
                    return null;
                } else {
                    continue;
                }
            }

            $resolved = trim($resolved);
            if ( 'color' === $property && in_array(strtolower($resolved), array('inherit', 'unset'), true) ) {
                continue;
            }
            if ( '' === $resolved || preg_match('/var\s*\(|[<>]/i', $resolved) ) {
                // The declared value could not be fully resolved. Stop rather
                // than risk baking the wrong ancestor's paint.
                return null;
            }

            if ( ! preg_match('/^currentColor$/i', $resolved) ) {
                return $resolved;
            }
            if ( 'color' === $property ) {
                // `color: currentColor` computes to the inherited color; keep
                // walking ancestors for `color` rather than resolving itself.
                continue;
            }

            return $this->inheritedSvgColor($current);
        }

        return null;
    }

    /**
     * Merge resolved declarations onto the root `<svg>` tag's style attribute
     * within the (already partially transformed) markup string, preserving
     * any style already present there — including box-sizing declarations a
     * prior materialization step may have already written.
     *
     * @param array<string, string> $declarations
     */
    private function mergeSvgRootStyleDeclarations(string $html, array $declarations): string
    {
        if ( 1 !== preg_match('/<svg\b[^>]*>/i', $html, $tagMatch, PREG_OFFSET_CAPTURE) ) {
            return $html;
        }

        $tag = $tagMatch[0][0];
        $existingStyle = '';
        if ( preg_match('/\sstyle\s*=\s*(["\'])(.*?)\1/i', $tag, $styleMatch) ) {
            $existingStyle = html_entity_decode($styleMatch[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        // A style declaration already present on the tag (author-authored or
        // written by an earlier materialization step) always outranks a value
        // resolved from the lost external cascade.
        $merged = array_merge($declarations, SourceDeclarations::parse($existingStyle));
        $style = $this->styleResolver->cssDeclarationString($merged);
        if ( '' === $style ) {
            return $html;
        }

        $escapedStyle = htmlspecialchars($style, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $newTag = preg_match('/\sstyle\s*=\s*(["\'])(.*?)\1/i', $tag)
            ? ( preg_replace('/(\sstyle\s*=\s*)(["\'])(.*?)\2/i', '$1$2' . $escapedStyle . '$2', $tag, 1) ?? $tag )
            : ( preg_replace('/<svg\b/i', '<svg style="' . $escapedStyle . '"', $tag, 1) ?? $tag );

        return substr($html, 0, $tagMatch[0][1]) . $newTag . substr($html, $tagMatch[0][1] + strlen($tag));
    }


    private function materializedInlineSvgPath(DOMElement $element, string $html): string
    {
        unset($element);
        // The asset is the sanitized vector payload, not its generated source
        // selector. A content address lets every compatible instance share one
        // core/image asset while retaining its own alt text and presentation.
        return $this->session->assetMaterializationState()->inlineSvgPath($html);
    }

    private function sourceRelativeMaterializedSvgPath(string $path): string
    {
        $from = array_values(array_filter(explode('/', trim(dirname($this->transformSourcePath()), '/')), static fn(string $segment): bool => '' !== $segment && '.' !== $segment));
        $to = array_values(array_filter(explode('/', trim($path, '/')), static fn(string $segment): bool => '' !== $segment && '.' !== $segment));
        while (array() !== $from && array() !== $to && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }
        return str_repeat('../', count($from)) . implode('/', $to);
    }

    private function transformSourcePath(): string
    {
        foreach ( array( 'source', 'path' ) as $key ) {
            $value = $this->session->transformationProvenanceState()->fallback()[$key] ?? '';
            if ( '' !== trim((string) $value) ) {
                return trim((string) $value);
            }
        }

        return '';
    }

    private function recordGutenbergIncompatibility(DOMElement $element, string $reason, string $message): void
    {
        $this->session->transformationEvidenceState()->recordGutenbergIncompatibility(array(
            'type'     => 'svg_materialization_incompatibility',
            'element'  => 'svg',
            'selector' => SourceDom::elementSelector($element),
            'reason'   => $reason,
            'message'  => $message,
        ));
    }

    private function hasSvgRegionLink(DOMElement $element): bool
    {
        foreach ( $element->getElementsByTagName('a') as $anchor ) {
            $href = trim(SourceDom::attr($anchor, 'href') ?: SourceDom::attr($anchor, 'xlink:href'));
            if ( '' !== $href && ! str_starts_with($href, '#') ) {
                return true;
            }
        }
        return false;
    }

    private function isNativeImageCompatibleSvg(DOMElement $element, string $html, bool $allowRegionLinks = false): bool
    {
        if ( ! $this->isPassiveSvgMarkup($element, $allowRegionLinks) ) {
            return false;
        }

        if ( $this->hasPageCssAnimatedDescendant($element) ) {
            return false;
        }

        // The materialized SVG renders in a separate image document. Paint and
        // content custom properties must be resolved, but root box geometry is
        // transferred to the native image carrier and may retain author vars.
        if ( preg_match('/\bcurrentColor\b/i', $html) ) {
            return false;
        }
        $htmlWithoutRootStyle = preg_replace('/(<svg\b[^>]*?)\sstyle\s*=\s*(["\'])(.*?)\2/i', '$1', $html, 1) ?? $html;
        if ( preg_match('/var\s*\(/i', $htmlWithoutRootStyle) ) {
            return false;
        }
        $boxProperties = array_flip(array( 'width', 'height', 'min-width', 'max-width', 'min-height', 'max-height', 'aspect-ratio' ));
        foreach ( SourceDeclarations::inline($element) as $property => $value ) {
            $resolved = $this->styleResolver->resolveCssVariablesInValue($value, $element);
            if ( preg_match('/var\s*\(/i', $resolved) && ! isset($boxProperties[strtolower($property)]) ) {
                return false;
            }
        }
        // Only anchor destinations are exempt; external use/image references
        // must continue to fail the ordinary image compatibility checks.
        $referenceMarkup = $allowRegionLinks ? (preg_replace('/<a\b[^>]*>/i', '', $html) ?? $html) : $html;
        if ( preg_match('/\s(?:href|xlink:href)\s*=\s*(["\'])(?!#)[^"\']+\1/i', $referenceMarkup) ) {
            return false;
        }
        if ( $this->usesAuthorFontFace($html) ) {
            return false;
        }

        return true;
    }

    /**
     * An SVG image document cannot load page web fonts, so text set in a family
     * that only exists through an author `@font-face` needs the inline document.
     */
    private function usesAuthorFontFace(string $html): bool
    {
        if ( false === stripos($html, 'font-family') ) {
            return false;
        }
        $faces = $this->styleResolver->authorFontFaceFamilies();
        if ( array() === $faces || ! preg_match_all('/font-family\s*(?::|=\s*["\'])\s*((?:&[a-z0-9#]+;|[^;"\'<>])*)/i', $html, $matches) ) {
            return false;
        }
        foreach ( $matches[1] as $stack ) {
            foreach ( CssFontFamily::names(html_entity_decode($stack, ENT_QUOTES | ENT_HTML5, 'UTF-8')) as $family ) {
                if ( isset($faces[strtolower($family)]) ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasPageCssAnimatedDescendant(DOMElement $element): bool
    {
        // A materialized image is a separate document, so page CSS cannot reach
        // animated descendants such as steam paths or illustrated particles.
        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if ( ! $descendant instanceof DOMElement ) {
                continue;
            }
            $declarations = $this->styleResolver->cascade->declarations($descendant);
            foreach ( array( 'animation', 'animation-name' ) as $property ) {
                $value = strtolower(trim((string) ($declarations[$property] ?? '')));
                $value = trim((string) preg_replace('/\s*!important\s*$/i', '', $value));
                if ( '' !== $value && 1 !== preg_match('/^(?:none|initial|inherit|unset|revert)(?:\s|$)/', $value) ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @return array<string, string>
     */
    private function svgImageDimensions(DOMElement $element, string $html): array
    {
        $sourceWidth = trim(SourceDom::attr($element, 'width'));
        // A percentage SVG width has a used size from its containing block. Keep
        // that responsive width on the native image and let its viewBox provide
        // the intrinsic aspect ratio instead of pinning a viewBox-height value.
        if ( null !== $this->svgPercentageWidth($sourceWidth) ) {
            return array( 'width' => $sourceWidth );
        }

        $width = $this->svgLengthAttributeForImage($sourceWidth);
        $height = $this->svgLengthAttributeForImage(SourceDom::attr($element, 'height'));
        if ( '' !== $width && '' !== $height ) {
            return array( 'width' => $width, 'height' => $height );
        }

        if ( 1 !== preg_match('/\sviewBox\s*=\s*(["\'])([^"\']+)\1/i', $html, $viewBoxMatch) ) {
            return array_filter(array( 'width' => $width, 'height' => $height ), static fn (string $value): bool => '' !== $value);
        }

        $parts = preg_split('/[\s,]+/', trim($viewBoxMatch[2])) ?: array();
        if ( count($parts) >= 4 ) {
            $width = '' !== $width ? $width : ( is_numeric($parts[2]) ? $this->svgLengthAttributeForImage($this->normalizedSvgDimension((float) $parts[2])) : '' );
            $height = '' !== $height ? $height : ( is_numeric($parts[3]) ? $this->svgLengthAttributeForImage($this->normalizedSvgDimension((float) $parts[3])) : '' );
        }

        return array_filter(array( 'width' => $width, 'height' => $height ), static fn (string $value): bool => '' !== $value);
    }

    private function svgPercentageWidth(string $value): ?float
    {
        if ( 1 !== preg_match('/^[+-]?(?:(?:\d+(?:\.\d*)?)|(?:\.\d+))(?:[eE][+-]?\d+)?%$/', $value) ) {
            return null;
        }

        $number = (float) substr($value, 0, -1);
        // SVG width is a non-negative length. Keep valid signed/exponent CSS
        // numbers when usable, and fall back to intrinsic dimensions for a
        // negative used width rather than emitting invalid image geometry.
        return is_finite($number) && $number >= 0 ? $number : null;
    }

    private function svgLengthAttributeForImage(string $value): string
    {
        // An SVG width/height attribute is a CSS length: `40` and `40px` both
        // size the box to 40 CSS pixels. Rejecting the explicit unit would
        // drop the authored size and fall back to the viewBox dimensions.
        if ( 1 !== preg_match('/^(\d+(?:\.\d+)?)(?:px)?$/i', trim($value), $match) ) {
            return '';
        }

        return $match[1] . 'px';
    }

    private function svgImageAlt(DOMElement $element): string
    {
        if ( SourceDom::isAriaHidden($element) ) {
            return '';
        }

        // aria-labelledby wins over aria-label, as in the accessible-name
        // computation. A reference to an id the document does not contain
        // names nothing, so the next source applies.
        $labelledBy = array();
        foreach ( SourceDom::tokens(SourceDom::attr($element, 'aria-labelledby')) as $id ) {
            $label = '' !== $id ? $element->ownerDocument?->getElementById($id) : null;
            $text = $label instanceof DOMElement ? trim(preg_replace('/\s+/', ' ', (string) $label->textContent) ?? '') : '';
            if ( '' !== $text ) {
                $labelledBy[] = $text;
            }
        }
        if ( array() !== $labelledBy ) {
            return implode(' ', $labelledBy);
        }

        foreach ( array( 'aria-label', 'title' ) as $attribute ) {
            $value = trim(SourceDom::attr($element, $attribute));
            if ( '' !== $value ) {
                return $value;
            }
        }

        $title = $element->getElementsByTagName('title')->item(0);
        $titleText = $title instanceof DOMElement ? trim((string) $title->textContent) : '';
        if ( '' !== $titleText ) {
            return $titleText;
        }
        $alt = trim(SourceDom::attr($element, 'alt'));
        $host = $element->parentNode;
        if ( '' === $alt && $host instanceof DOMElement && SourceDom::staticGraphicOnlyCustomElement($host) ) {
            $alt = trim(SourceDom::attr($host, 'alt'));
        }
        return $alt;
    }

    private function ensureInlineSvgSizing(string $html, ?DOMElement $element = null): string
    {
        if ( 1 !== preg_match('/<svg\b([^>]*)>/i', $html, $match, PREG_OFFSET_CAPTURE) ) {
            return $html;
        }

        if ( null !== $element ) {
            $html = $this->ensureInlineSvgBoxStyle($html, $element);
            if ( 1 !== preg_match('/<svg\b([^>]*)>/i', $html, $match, PREG_OFFSET_CAPTURE) ) {
                return $html;
            }
        }

        $attrs = $match[1][0];
        if ( preg_match('/\s(?:width|height)\s*=/i', $attrs) || preg_match('/\sstyle\s*=\s*(["\'])(?:(?!\1).)*(?<![\w-])(?:width|height)\s*:/i', $attrs) ) {
            return $html;
        }

        if ( 1 !== preg_match('/\sviewbox\s*=\s*(["\'])([^"\']+)\1/i', $attrs, $viewBoxMatch) ) {
            return $html;
        }

        $parts = preg_split('/[\s,]+/', trim($viewBoxMatch[2])) ?: array();
        if ( count($parts) < 4 || ! is_numeric($parts[2]) || ! is_numeric($parts[3]) ) {
            return $html;
        }

        $width = $this->normalizedSvgDimension((float) $parts[2]);
        $height = $this->normalizedSvgDimension((float) $parts[3]);
        if ( '' === $width || '' === $height ) {
            return $html;
        }

        $insertAt = $match[0][1] + strlen($match[0][0]) - 1;
        return substr($html, 0, $insertAt) . ' width="' . $width . '" height="' . $height . '"' . substr($html, $insertAt);
    }

    /**
     * A percentage or auto width (attribute or root style) sizes an inline SVG
     * from its containing block. In a standalone image document it leaves the
     * image with no intrinsic size, so a shrink-to-fit figure around a
     * percentage-width image collapses to 0x0. Give the asset its viewBox size
     * as numeric intrinsic dimensions; the page CSS keeps the rendered width.
     */
    private function withViewBoxIntrinsicSizeForRelativeSvg(string $html): string
    {
        if ( 1 !== preg_match('/<svg\b([^>]*)>/i', $html, $match, PREG_OFFSET_CAPTURE) ) {
            return $html;
        }
        $attrs = $match[1][0];
        $attribute = static fn (string $name): ?string => preg_match('/\s' . $name . '\s*=\s*(["\'])(.*?)\1/is', $attrs, $m) ? trim(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')) : null;
        $style = (string) $attribute('style');
        $declarations = '' === $style ? array() : SourceDeclarations::parse($style);
        $isRelative = static fn (?string $value): bool => null === $value || '' === $value || 'auto' === strtolower($value) || str_ends_with($value, '%');
        $width = isset($declarations['width']) ? trim((string) $declarations['width']) : $attribute('width');
        $height = isset($declarations['height']) ? trim((string) $declarations['height']) : $attribute('height');
        // Only an explicitly percentage/auto-sized SVG. A viewBox-only SVG keeps
        // its existing sizing path, and absolute dimensions stay as authored.
        if ( null === $width || '' === $width || ! $isRelative($width) || ! $isRelative($height) ) {
            return $html;
        }
        if ( 'auto' !== strtolower($width) && null === $this->svgPercentageWidth($width) ) {
            return $html;
        }
        $parts = preg_split('/[\s,]+/', (string) $attribute('viewBox')) ?: array();
        if ( count($parts) < 4 || ! is_numeric($parts[2]) || ! is_numeric($parts[3]) ) {
            return $html;
        }
        $intrinsicWidth = $this->normalizedSvgDimension((float) $parts[2]);
        $intrinsicHeight = $this->normalizedSvgDimension((float) $parts[3]);
        if ( '' === $intrinsicWidth || '' === $intrinsicHeight ) {
            return $html;
        }

        $attrs = preg_replace('/\s(?:width|height)\s*=\s*(["\']).*?\1/is', '', $attrs) ?? $attrs;
        if ( isset($declarations['width']) || isset($declarations['height']) ) {
            unset($declarations['width'], $declarations['height']);
            $rootStyle = $this->styleResolver->cssDeclarationString($declarations);
            $attrs = preg_replace('/\sstyle\s*=\s*(["\']).*?\1/is', '' === $rootStyle ? '' : ' style="' . htmlspecialchars($rootStyle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"', $attrs, 1) ?? $attrs;
        }
        $tag = '<svg' . $attrs . ' width="' . $intrinsicWidth . '" height="' . $intrinsicHeight . '">';

        return substr($html, 0, $match[0][1]) . $tag . substr($html, $match[0][1] + strlen($match[0][0]));
    }

    public function ensureInlineSvgBoxStyle(string $html, DOMElement $element): string
    {
        $boxProperties = array_flip(array(
            'aspect-ratio',
            'display',
            'height',
            'max-height',
            'max-width',
            'min-height',
            'min-width',
            'width',
        ));
        $boxDeclarations = array_intersect_key($this->styleResolver->presentationDeclarations($element), $boxProperties);
        if ( array() === $boxDeclarations ) {
            return $html;
        }

        // Start from the markup's own root style: it may already carry paint
        // baked for the standalone image, which the source attribute lacks.
        $rootStyle = preg_match('/<svg\b[^>]*\sstyle\s*=\s*(["\'])(.*?)\1/i', $html, $rootMatch)
            ? html_entity_decode($rootMatch[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : SourceDom::attr($element, 'style');
        $existingDeclarations = SourceDeclarations::parse($rootStyle);
        foreach ( array_keys($existingDeclarations) as $name ) {
            unset($boxDeclarations[$name]);
        }
        if ( array() === $boxDeclarations ) {
            return $html;
        }

        $style = $this->styleResolver->cssDeclarationString(array_merge($existingDeclarations, $boxDeclarations));
        if ( '' === $style ) {
            return $html;
        }

        $escapedStyle = htmlspecialchars($style, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ( preg_match('/<svg\b([^>]*)\sstyle\s*=\s*(["\'])(.*?)\2([^>]*)>/i', $html) ) {
            return preg_replace('/(<svg\b[^>]*\sstyle\s*=\s*)(["\'])(.*?)\2/i', '$1$2' . $escapedStyle . '$2', $html, 1) ?? $html;
        }

        return preg_replace('/<svg\b([^>]*)>/i', '<svg$1 style="' . $escapedStyle . '">', $html, 1) ?? $html;
    }

    private function normalizedSvgDimension(float $value): string
    {
        if ( $value <= 0 ) {
            return '';
        }

        $formatted = rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
        return '' === $formatted ? '' : $formatted;
    }

    /**
     * Whether an SVG decoration paints nothing in the captured state; such a
     * decoration is omitted, with an info diagnostic recording the omission.
     */
    public function omitsEmptyPaintDecoration(DOMElement $element): bool
    {
        if (!$this->paintsNothing($element)) return false;
        $this->session->transformationEvidenceState()->recordConversionDiagnostic(array(
            'code' => 'html_empty_svg_decoration_omitted',
            'severity' => 'info',
            'loss_class' => 'native_conversion',
            'selector' => SourceDom::elementSelector($element),
            'message' => 'Omitted SVG decoration proven to paint nothing in the captured state.',
        ));

        return true;
    }

    /** Sanitized inline SVG markup with its parser-lowercased names restored. */
    public function inlineSvgMarkup(DOMElement $element): string
    {
        return $this->restoreSvgCasing($this->sanitizeInlineSvgMarkup($element));
    }

    /**
     * Restore the canonical camelCase casing of SVG element and attribute names
     * that the HTML parser lowercases (e.g. `viewbox` -> `viewBox`,
     * `<lineargradient>` -> `<linearGradient>`). SVG element and attribute names
     * are case-sensitive, so a lowercased `viewbox` is ignored (the SVG would not
     * scale to its viewport) and a lowercased `<lineargradient>` is an unknown
     * element the browser does not render (the gradient fill disappears).
     */
    public function restoreSvgCasing(string $html): string
    {
        static $camelCaseAttributes = array(
            'viewBox', 'preserveAspectRatio', 'baseProfile', 'attributeName', 'attributeType',
            'repeatCount', 'repeatDur', 'calcMode', 'keyPoints', 'keySplines', 'keyTimes',
            'gradientUnits', 'gradientTransform', 'spreadMethod', 'patternUnits',
            'patternContentUnits', 'patternTransform', 'clipPath', 'clipPathUnits',
            'maskUnits', 'maskContentUnits', 'markerWidth', 'markerHeight', 'markerUnits',
            'refX', 'refY', 'stdDeviation', 'stitchTiles', 'surfaceScale', 'specularConstant',
            'specularExponent', 'diffuseConstant', 'kernelMatrix', 'kernelUnitLength',
            'numOctaves', 'baseFrequency', 'tableValues', 'targetX', 'targetY',
            'lengthAdjust', 'textLength', 'startOffset', 'pathLength', 'filterUnits',
            'primitiveUnits', 'edgeMode', 'limitingConeAngle', 'pointsAtX', 'pointsAtY',
            'pointsAtZ', 'systemLanguage',
        );

        // Case-sensitive SVG element names. A lowercased tag is an unknown element
        // to the browser, so the gradient/clip/filter it defines never applies.
        static $camelCaseElements = array(
            'linearGradient', 'radialGradient', 'clipPath', 'textPath', 'foreignObject',
            'feBlend', 'feColorMatrix', 'feComponentTransfer', 'feComposite',
            'feConvolveMatrix', 'feDiffuseLighting', 'feDisplacementMap', 'feDistantLight',
            'feDropShadow', 'feFlood', 'feFuncA', 'feFuncB', 'feFuncG', 'feFuncR',
            'feGaussianBlur', 'feImage', 'feMerge', 'feMergeNode', 'feMorphology',
            'feOffset', 'fePointLight', 'feSpecularLighting', 'feSpotLight', 'feTile',
            'feTurbulence', 'animateMotion', 'animateTransform',
        );

        foreach ( $camelCaseAttributes as $attribute ) {
            $html = preg_replace('/(\s)' . preg_quote($attribute, '/') . '(\s*=)/i', '$1' . $attribute . '$2', $html) ?? $html;
        }

        foreach ( $camelCaseElements as $tag ) {
            $html = preg_replace('/<(\/?)' . preg_quote($tag, '/') . '(?=[\s\/>])/i', '<$1' . $tag, $html) ?? $html;
        }

        return $html;
    }

    public function isSafeDecorativeSvgElement(DOMElement $element): bool
    {
        if ( ! SourceDom::isSafeSvgContent(SourceDom::outerHtml($element)) || ! $this->isPassiveSvgMarkup($element) ) {
            return false;
        }

        $role = SourceDom::role($element);
        if ( SourceDom::isAriaHidden($element) || in_array($role, array( 'presentation', 'none' ), true) ) {
            return true;
        }

        return $this->hasIconLikeContext($element);
    }

    private function hasIconLikeContext(DOMElement $element): bool
    {
        for ( $current = $element; $current instanceof DOMElement; $current = $current->parentNode instanceof DOMElement ? $current->parentNode : null ) {
            $context = strtolower(trim(implode(' ', array(
                SourceDom::attr($current, 'class'),
                SourceDom::attr($current, 'id'),
                SourceDom::attr($current, 'aria-label'),
                SourceDom::attr($current, 'title'),
            ))));

            if ( preg_match('/(?:^|[\s_-])(?:icon|logo)(?:$|[\s_-])/', $context) ) {
                return true;
            }

            if ( in_array(strtolower($current->tagName), array( 'body', 'main', 'article', 'section' ), true) ) {
                return false;
            }
        }

        return false;
    }

    /**
     * Foreign namespace metadata has no SVG rendering semantics. HTML parsers
     * may leave prefixes unresolved, so also consult declarations in scope.
     */
    public static function isForeignSvgAttribute(DOMElement $element, string $name, string $value): bool
    {
        $namespaces = array(
            'http://www.w3.org/2000/svg',
            'http://www.w3.org/1999/xlink',
            'http://www.w3.org/XML/1998/namespace',
        );
        if ( 'xmlns' === $name || str_starts_with($name, 'xmlns:') ) {
            return ! in_array($value, $namespaces, true);
        }
        if ( ! str_contains($name, ':') ) {
            return false;
        }
        $prefix = explode(':', $name, 2)[0];
        $attribute = $element->getAttributeNode($name);
        $namespace = false !== $attribute ? $attribute->namespaceURI : null;
        if ( null === $namespace || '' === $namespace ) {
            $namespace = $element->lookupNamespaceURI($prefix);
        }
        if ( null === $namespace || '' === $namespace ) {
            for ( $current = $element; $current instanceof DOMElement; $current = $current->parentNode ) {
                if ( $current->hasAttribute('xmlns:' . $prefix) ) {
                    $namespace = $current->getAttribute('xmlns:' . $prefix);
                    break;
                }
            }
        }
        // HTML SVG commonly omits the standard prefix declarations.
        if ( null === $namespace || '' === $namespace ) {
            $namespace = array('xlink' => $namespaces[1], 'xml' => $namespaces[2])[$prefix] ?? '';
        }
        return ! in_array($namespace, $namespaces, true);
    }

    public static function isPassiveSvgMarkup(DOMElement $element, bool $allowRegionLinks = false): bool
    {
        // Full set of safe SVG structure/presentation/text/filter elements. These carry
        // only geometry, gradients, filters, and text — no scripting or external embedding
        // (script/style/foreignObject/image are deliberately excluded and are
        // stripped by the sanitizer). <use>/<symbol> reference handling is gated
        // separately: a <use> carries href/xlink:href which isPassiveSvgElement()
        // always rejects, so use-bearing SVGs route through the faithful
        // inline-preservation path (local refs) or the fallback diagnostic
        // (external sprite refs) rather than this decorative classification.
        $allowedTags = array_flip(array(
            'circle', 'clippath', 'defs', 'desc', 'ellipse', 'feblend', 'fecolormatrix',
            'fecomponenttransfer', 'fecomposite', 'feconvolvematrix', 'fediffuselighting',
            'fedisplacementmap', 'fedistantlight', 'fedropshadow', 'feflood', 'fefunca',
            'fefuncb', 'fefuncg', 'fefuncr', 'fegaussianblur', 'feimage', 'femerge',
            'femergenode', 'femorphology', 'feoffset', 'fepointlight', 'fespecularlighting',
            'fespotlight', 'fetile', 'feturbulence', 'filter', 'g', 'line', 'lineargradient',
            'marker', 'mask', 'path', 'pattern', 'polygon', 'polyline', 'radialgradient',
            'rect', 'stop', 'svg', 'symbol', 'text', 'textpath', 'title', 'tspan', 'use',
        ));
        $allowedAttributes = array_flip(array(
            'alt', 'amplitude', 'aria-describedby', 'aria-hidden', 'aria-label', 'aria-labelledby', 'azimuth', 'basefrequency', 'baseprofile', 'bias',
            'class', 'clip-path', 'clip-rule', 'clippathunits', 'cliprule', 'color-interpolation', 'color-interpolation-filters',
            'color', 'color-rendering', 'cx', 'cy', 'd',
            'data-bbox', 'data-color', 'data-testid', 'data-type',
            'diffuseconstant', 'divisor', 'alignment-baseline', 'dominant-baseline', 'dx', 'dy', 'edgemode',
            'elevation', 'enable-background', 'exponent', 'fill', 'fill-opacity', 'fill-rule', 'fillrule', 'flood-color',
            'flood-opacity', 'font-family',
            'filter', 'filterunits', 'focusable', 'font-size', 'font-style', 'font-weight', 'fr', 'fx', 'fy', 'gradienttransform', 'gradientunits',
            'height', 'id', 'image-rendering', 'lengthadjust', 'letter-spacing', 'marker-end', 'marker-mid', 'marker-start',
            'intercept', 'k1', 'k2', 'k3', 'k4', 'kernelmatrix', 'kernelunitlength',
            'lighting-color', 'limitingconeangle', 'markerheight', 'markerunits', 'markerwidth', 'mask',
            'maskcontentunits', 'maskunits', 'mode',
            'numoctaves', 'offset', 'opacity', 'operator', 'order', 'orient', 'overflow', 'paint-order',
            'href', 'patterncontentunits', 'patterntransform', 'patternunits', 'pathlength', 'points',
            'pointsatx', 'pointsaty', 'pointsatz',
            'preservealpha', 'preserveaspectratio', 'primitiveunits', 'r', 'radius', 'refx', 'refy',
            'result', 'role', 'rotate', 'rx', 'ry', 'scale', 'seed', 'shape-rendering', 'slope',
            'specularconstant', 'specularexponent',
            'spreadmethod', 'stop-color', 'stop-opacity', 'stroke', 'stroke-dasharray',
            'stroke-dashoffset', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit',
            'stitchtiles', 'stroke-opacity', 'stroke-width', 'stddeviation', 'style', 'surfacescale',
            'tablevalues', 'targetx', 'targety', 'text-anchor', 'text-rendering', 'textlength', 'startoffset',
            'transform', 'type',
            'values', 'vector-effect', 'version', 'viewbox', 'width', 'x', 'x1', 'x2',
            'xchannelselector', 'xlink:href', 'xml:space', 'y', 'y1', 'y2', 'ychannelselector', 'zoomandpan', 'in', 'in2', 'title',
            'xmlns', 'xmlns:xlink',
        ));

        foreach ( $element->getElementsByTagName('*') as $child ) {
            // Inline styles are removed before either core/html preservation or
            // SVG asset materialization. They cannot survive into the generated
            // image document, so they must not disqualify otherwise passive,
            // self-contained artwork from the native image path.
            if ( in_array(strtolower($child->tagName), array('style', 'link'), true) ) {
                continue;
            }
            if ( ! $child instanceof DOMElement || ! self::isPassiveSvgElement($child, $allowedTags, $allowedAttributes, $allowRegionLinks) ) {
                return false;
            }
        }

        return self::isPassiveSvgElement($element, $allowedTags, $allowedAttributes, $allowRegionLinks);
    }

    /**
     * @param array<string, int> $allowedTags
     * @param array<string, int> $allowedAttributes
     */
    private static function isPassiveSvgElement(DOMElement $element, array $allowedTags, array $allowedAttributes, bool $allowRegionLinks = false): bool
    {
        $regionLink = $allowRegionLinks && 'a' === strtolower($element->tagName);
        if ( $regionLink ) {
            $allowedTags['a'] = 1;
            foreach ( array( 'target', 'rel', 'download', 'hreflang', 'type' ) as $attribute ) {
                $allowedAttributes[$attribute] = 1;
            }
        }

        // SMIL transform animation is self-contained in an SVG image document.
        // Keep the target implicit and restrict it to geometry: general SMIL
        // attribute mutation (notably href) is not passive artwork.
        if ( 'animatetransform' === strtolower($element->tagName) ) {
            if ( 'transform' !== strtolower(trim(SourceDom::attr($element, 'attributename')))
                || ! in_array(strtolower(trim(SourceDom::attr($element, 'type'))), array( 'translate', 'scale', 'rotate', 'skewx', 'skewy' ), true) ) {
                return false;
            }
            $allowedTags['animatetransform'] = 1;
            $allowedAttributes = array_flip(array(
                'attributename', 'attributetype', 'type', 'from', 'to', 'by', 'values',
                'dur', 'repeatcount', 'repeatdur', 'calcmode', 'keytimes', 'keysplines',
                'additive', 'accumulate', 'fill',
            ));
        }

        // A raster embedded as a data: URI is part of this image document: it
        // fetches nothing and runs nothing. Any other image reference is not.
        $embeddedRaster = false;
        if ( 'image' === strtolower($element->tagName) ) {
            $href = $element->hasAttribute('href') ? $element->getAttribute('href') : $element->getAttributeNS('http://www.w3.org/1999/xlink', 'href');
            if ( 1 !== preg_match('~^data:image/(?:png|jpeg|gif|webp);base64,[a-z0-9+/=\s]+$~iD', trim($href)) ) {
                return false;
            }
            $embeddedRaster = true;
            $allowedTags['image'] = 1;
        }

        if ( ! isset($allowedTags[strtolower($element->tagName)]) ) {
            return false;
        }

        foreach ( SourceDom::htmlAttributes($element) as $name => $value ) {
            if ( self::isForeignSvgAttribute($element, $name, $value) ) {
                continue;
            }
            $name = strtolower($name);
            $isInertDataAttribute = str_starts_with($name, 'data-') && 'data-dom-store' !== $name;
            // Icon exporters annotate groups with a variant name. It is not an
            // SVG behavior or paint property; matched descendant CSS is still
            // baked and the original metadata travels with the asset.
            $isInertGroupWeight = 'weight' === $name && 'g' === strtolower($element->tagName) && 1 === preg_match('/^[a-z][a-z-]*$/iD', $value);
            // An empty attribute that SVG does not define (authoring residue such
            // as `<path path="">`) carries no paint, geometry, or behavior.
            // Presence-only HTML globals and data-* markers (decided above) are
            // excluded because presence is their value.
            $isInertEmptyAttribute = '' === $value && ! str_starts_with($name, 'data-') && ! in_array($name, array( 'hidden', 'inert', 'autofocus', 'tabindex', 'contenteditable', 'draggable', 'popover' ), true);
            if ( (! isset($allowedAttributes[$name]) && ! $isInertDataAttribute && ! $isInertGroupWeight && ! $isInertEmptyAttribute) || preg_match('/^on[a-z]+$/i', $name) || preg_match('/javascript\s*:|\b(?:expression|behavior)\s*:/i', $value) ) {
                return false;
            }
            if ( preg_match('/(?:^|:)href$/i', $name) && ! str_starts_with(trim($value), '#') && ! $embeddedRaster && ! $regionLink ) {
                return false;
            }
            if ( preg_match('/\burl\s*\((?!\s*["\']?#[-_a-z0-9]+["\']?\s*\))/i', $value) ) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function svgArtworkBlock(DOMElement $element, string $svg): array
    {
        $generator = new SvgArtworkBlockGenerator();
        $this->session->requireGeneratedBlockRegistry()->register(SvgArtworkBlockGenerator::class, $generator->definition($this->session->requireGeneratedBlockRegistry()->namespace()));

        return $this->createBlock->createBlock(
            $this->session->requireGeneratedBlockRegistry()->blockName(SvgArtworkBlockGenerator::LOCAL_NAME),
            array( 'svg' => $svg ),
            array(),
            $element
        );
    }

    /** @return array<string, mixed>|null */
    public function decorativeTextSvgBlock(DOMElement $element): ?array
    {
        if (!SourceDom::isSafeSvgContent(SourceDom::outerHtml($element)) || !self::isPassiveSvgMarkup($element)) {
            return null;
        }
        foreach ($element->attributes as $attribute) {
            if (str_starts_with(strtolower($attribute->name), 'aria-') || in_array(strtolower($attribute->name), array('role', 'tabindex'), true)) {
                return null;
            }
        }
        $declarations = $this->styleResolver->presentationDeclarations($element);
        if ('absolute' !== strtolower(trim((string) ($declarations['position'] ?? '')))) {
            return null;
        }
        return $this->svgArtworkBlock($element, $this->inlineSvgMarkup($element));
    }

    /** Fallback markup carries the session's author-selector tag markers. */
    protected function fallbackSourceTagMarker(string $tagName): string
    {
        return $this->session->authorSelectorProjectionState()->tagMarker($tagName);
    }
}
