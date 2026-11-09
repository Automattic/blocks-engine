<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\BlockFactory;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\ImageDimensionResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\NativeImageLeafPresentation;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\FallbackDiagnostic;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\SourceElementDescription;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\BackgroundImageExtractor;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\ImageUrlResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\LinkUrlSanitizer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CoverStyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\ResponsiveMediaBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\SrcsetParser;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
use DOMElement;

final class ImageLowering
{
    public const BACKGROUND_IMAGE_CLASS = 'blocks-engine-background-image';
    public const BACKGROUND_IMAGE_SCALE_CLASS_PREFIX = 'blocks-engine-background-image-';

    public function __construct(
        private readonly SourceBlockCreator $blocks,
        private readonly BlockFactory $blockFactory,
        private readonly StyleResolver $styleResolver,
        private readonly BackgroundImageExtractor $backgroundImageExtractor,
        private readonly SourceElementDescription $sourceElementDescription,
        private readonly HtmlTransformerSession $session,
        private readonly ImageUrlResolver $imageUrls,
        private readonly ImageDimensionResolver $imageDimensions,
        private readonly SourceElementClassifier $sourceElementClassifier
    ) {
    }

    /** @return array<string, mixed>|null */
    public function backgroundImageBlock(DOMElement $element): ?array
    {
        $declarations = $this->styleResolver->presentationDeclarations($element);
        $url = $this->backgroundImageExtractor->urlFromStyle($this->styleResolver->mergedPresentationStyle($element));
        if ( '' === $url ) {
            return null;
        }

        if ( (new CoverStyleResolver())->hasRepeatingBackground($this->styleResolver->mergedPresentationStyle($element)) ) {
            $attrs = $this->styleResolver->presentationAttributes($element);
            $attrs['style']['background'] = array_filter(array(
                'backgroundImage'    => array( 'url' => $this->imageUrls->resolvedAssetUrl($url) ),
                'backgroundRepeat'   => $declarations['background-repeat'] ?? 'repeat',
                // Core defaults a missing size to cover, not CSS's initial auto.
                'backgroundSize'     => $declarations['background-size'] ?? 'auto',
                'backgroundPosition' => $declarations['background-position'] ?? '',
            ), static fn ($value): bool => '' !== $value);

            return $this->blocks->createBlock('core/group', $attrs, array(), $element);
        }

        $width = trim((string) ($declarations['width'] ?? ''));
        $height = trim((string) ($declarations['height'] ?? ''));
        $scale = strtolower(trim((string) ($declarations['background-size'] ?? '')));

        $sized = in_array($scale, array( 'cover', 'contain' ), true);
        $className = self::BACKGROUND_IMAGE_CLASS;
        if ( $sized ) {
            $className .= ' ' . self::BACKGROUND_IMAGE_SCALE_CLASS_PREFIX . $scale;
            $position = CssValueInspector::carriableDeclarationValue((string) ($declarations['background-position'] ?? ''));
            if ( '' !== $position ) {
                // core/image cannot save object-position itself. The figure
                // carries the focal point and its img inherits it, agreeing with
                // the image projection bridge's own `object-position:inherit`
                // (same specificity) whichever stylesheet loads last.
                $carrier = $this->session->layoutGeometryState()->allocateCarrier('background-image-position' . "\n" . $position);
                $this->session->layoutGeometryState()->registerRule($carrier, '.' . $carrier . '{object-position:' . $position . '}.' . $carrier . '>img{object-position:inherit}');
                $className .= ' ' . $carrier;
            }
        }

        $image = $this->blocks->createBlock('core/image', array_filter(array(
            'url'       => $this->imageUrls->resolvedAssetUrl($url),
            'alt'       => $this->backgroundImageExtractor->altFromAttributes(SourceDom::htmlAttributes($element)),
            // A painted background never contributes intrinsic size, so the
            // materialized image resolves its box from the source element.
            'className' => $className,
            'width'     => ! in_array(strtolower($width), array( '', 'auto' ), true) ? $width : '',
            'height'    => ! in_array(strtolower($height), array( '', 'auto' ), true) ? $height : '',
            'scale'     => $sized ? $scale : '',
        ), static fn (string $value): bool => '' !== $value), array(), $element);

        if ( ! $sized ) {
            return $image;
        }

        // The image is now a child of the original painted box. Keep its
        // colour and other layers, but replace only the handed-off URL layer
        // with none (rather than shifting the remaining layers' geometry).
        $background = (string) ($declarations['background-image'] ?? $declarations['background'] ?? 'url(' . $url . ')');
        $background = preg_replace('/\s*!\s*important\s*$/i', '', $background) ?? $background;
        $layers = CssValueSplitter::splitTopLevel($background, array( ',' ));
        // Shorthand layers also contain position, repeat and colour tokens;
        // only their image token belongs in the background-image override.
        foreach ( $layers as &$layer ) {
            $tokens = CssValueSplitter::splitTopLevelWhitespace($layer);
            $layer = 'none';
            foreach ( $tokens as $token ) {
                if ( preg_match('/^(?:url|[a-z-]*gradient|image|image-set|cross-fade|var)\(/i', $token) ) {
                    $layer = $token;
                    break;
                }
            }
        }
        unset($layer);
        foreach ( $layers as &$layer ) {
            if ( $url === $this->backgroundImageExtractor->urlFromStyle('background-image:' . $layer) ) {
                $layer = 'none';
                break;
            }
        }
        unset($layer);
        $rule = '{background-image:' . implode(',', $layers) . ' !important}';
        $carrier = $this->session->layoutGeometryState()->allocateCarrier('materialized-background' . "\n" . $rule);
        $this->session->layoutGeometryState()->registerRule($carrier, ':root .' . $carrier . $rule);
        $attrs = $this->styleResolver->presentationAttributes($element);
        $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), $carrier);
        return $this->blocks->createBlock('core/group', $attrs, array( $image ), $element);
    }

    /** @return array<string, mixed> */
    public function responsiveMediaBlock(DOMElement $element): array
    {
        if ( $this->hasUnsafeResponsiveImageSources($element) ) {
            return $this->responsiveImageFallbackBlock($element);
        }

        $this->session->requireGeneratedBlockRegistry()->register(ResponsiveMediaBlockGenerator::class, ( new ResponsiveMediaBlockGenerator() )->definition($this->session->requireGeneratedBlockRegistry()->namespace()));

        $overflowClass = $this->styleResolver->responsiveImageOverflowClassName($element);
        $contentElement = $element;
        if ( '' !== $overflowClass ) {
            $contentElement = clone $element;
            $contentElement->setAttribute('class', SourceDom::mergeClassNames($contentElement->getAttribute('class'), $overflowClass));
        }

        return $this->blocks->createBlock(
            $this->session->requireGeneratedBlockRegistry()->blockName(ResponsiveMediaBlockGenerator::LOCAL_NAME),
            array( 'content' => SourceDom::safeFallbackHtml($contentElement, $this->session->authorSelectorProjectionState()->tagMarkers()), 'kind' => 'media' ),
            array(),
            $element
        );
    }

    /** @return array<string, mixed> */
    private function responsiveImageFallbackBlock(DOMElement $element): array
    {
        if ( ! $this->hasUnsafeResponsiveImageSources($element) ) {
            $generated = $this->session->fallbackEmitter()->maybeGenerateCustomBlock(
                $element,
                $this->session->requireGeneratedBlockRegistry(),
                true
            );
            if ( null !== $generated ) {
                return $this->blocks->createBlock($generated['blockName'], $generated['attrs'], array(), $element);
            }
        }

        $boundedHtml = SourceDom::boundedFallbackHtml(SourceDom::safeFallbackHtml($element, $this->session->authorSelectorProjectionState()->tagMarkers()));
        $selector = SourceDom::elementSelector($element);
        if ( ! $this->session->transformationEvidenceState()->hasResponsiveImageFallback($selector) ) {
            $this->session->transformationEvidenceState()->recordResponsiveImageFallback($selector, FallbackDiagnostic::build(array(
                'type'            => 'html',
                'reason'          => 'responsive_image_fallback',
                'diagnostic_code' => 'html_responsive_image_fallback',
                'message'         => 'Responsive image sources were preserved as sanitized core/html because core/image cannot serialize srcset, sizes, or picture source selection.',
                'source_format'   => 'html',
                'tag'             => strtolower($element->tagName),
                'selector'        => $selector,
                'attributes'      => SourceDom::htmlAttributes($element),
                'context'         => $this->sourceElementDescription->sourceContext($element),
                'classification'  => $this->session->fallbackEmitter()->classifyFallbackSubtree($element),
                'html'            => $boundedHtml['html'],
                'html_bytes'      => $boundedHtml['bytes'],
                'html_truncated'  => $boundedHtml['truncated'],
            ), $this->session->transformationProvenanceState()->fallback()));
        }

        return $this->blocks->createBlock('core/html', array( 'content' => SourceDom::safeFallbackHtml($element, $this->session->authorSelectorProjectionState()->tagMarkers()) ), array(), $element);
    }

    public function hasUnsafeResponsiveImageSources(DOMElement $element): bool
    {
        foreach ( array_merge(array($element), SourceDom::descendantElements($element)) as $candidate ) {
            if ( ! $candidate instanceof DOMElement || ! in_array(strtolower($candidate->tagName), array( 'img', 'source' ), true) ) {
                continue;
            }

            foreach ( SrcsetParser::parse(SourceDom::attr($candidate, 'srcset')) as $source ) {
                if ( '' === $this->imageUrls->safeUrl($source['url'])
                    || preg_match('/^(?:javascript|blob)\s*:/i', trim($source['url'])) ) {
                    return true;
                }
            }
        }

        return false;
    }

    public function imageLinkOpensNativeLightbox(DOMElement $link): bool
    {
        $href = LinkUrlSanitizer::sanitize(SourceDom::attr($link, 'href'));
        if ( '' === $href ) {
            return false;
        }

        $path = (string) parse_url($href, PHP_URL_PATH);
        if ( 1 !== preg_match('/\.(?:jpe?g|png|gif|webp|avif|bmp)$/i', $path) ) {
            return false;
        }

        foreach ( array( 'data-lightbox', 'data-fancybox', 'data-featherlight', 'data-glightbox' ) as $attribute ) {
            if ( '' !== trim(SourceDom::attr($link, $attribute)) ) {
                return true;
            }
        }

        $identity = strtolower(SourceDom::attr($link, 'rel') . ' ' . SourceDom::attr($link, 'class'));

        return 1 === preg_match('/(?:^|[^a-z0-9])(?:lightbox|fancybox|colorbox|magnific|photoswipe|prettyphoto)(?:[^a-z0-9]|$)/', $identity);
    }

    /** @param array<int, array<string, mixed>> $blocks */
    public function enableNativeLightboxOnImages(array &$blocks): bool
    {
        $enabled = false;
        foreach ( $blocks as $index => $block ) {
            if ( ! is_array($block) ) {
                continue;
            }

            if ( 'core/image' === (string) ($block['blockName'] ?? '') ) {
                $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
                if ( '' === (string) ($attrs['href'] ?? '') ) {
                    $attrs['lightbox'] = array( 'enabled' => true );
                    $blocks[ $index ] = $this->blockFactory->rebuild($block, $attrs);
                    $enabled = true;
                }
                continue;
            }

            if ( is_array($block['innerBlocks'] ?? null) && $this->enableNativeLightboxOnImages($blocks[ $index ]['innerBlocks']) ) {
                $enabled = true;
            }
        }

        return $enabled;
    }

    public function isHiddenImageLinkLabel(DOMElement $element): bool
    {
        if ( 0 < $element->getElementsByTagName('img')->length
            || 0 < $element->getElementsByTagName('svg')->length
            || in_array(strtolower($element->tagName), array('img', 'picture', 'svg', 'video', 'audio', 'button', 'input'), true) ) {
            return false;
        }
        $style = $this->styleResolver->resolvedPresentationDeclarations($element);
        return $element->hasAttribute('hidden')
            || 1 === preg_match('/(?:^|[\s_-])(?:visually-hidden|sr-only|screen-reader-only|screen-reader-text)(?:$|[\s_-])/', strtolower(SourceDom::attr($element, 'class')))
            || 'none' === strtolower(trim($style['display'] ?? ''))
            || 1 === preg_match('/^rect\(\s*0(?:px)?[\s,]+0(?:px)?[\s,]+0(?:px)?[\s,]+0(?:px)?\s*\)$/i', trim($style['clip'] ?? ''))
            || 'inset(50%)' === strtolower(str_replace(' ', '', $style['clip-path'] ?? ''))
            || in_array(strtolower(trim($style['visibility'] ?? '')), array('hidden', 'collapse'), true);
    }

    /**
     * core/image keeps only the image inside its link, so the image is the
     * link's whole content and its alt is the link's accessible name. When the
     * source named the link with something else (`aria-label`, text such as a
     * screen-reader-only label, or a title) and left the image unnamed, carry
     * that name on the image rather than leave the link nameless.
     */
    private function linkedImageAlt(DOMElement $image, ?DOMElement $link): string
    {
        $alt = SourceDom::attr($image, 'alt');
        if ( ! $link instanceof DOMElement || '' !== trim($alt) ) {
            return $alt;
        }
        return $this->imageLinkAccessibleName($link, $image) ?: $alt;
    }

    private function imageLinkAccessibleName(DOMElement $link, DOMElement $image): string
    {
        $text = '';
        foreach ( $this->textNodesOutside($link, $image) as $node ) {
            $text .= ' ' . $node->textContent;
        }
        foreach ( array( trim(SourceDom::attr($link, 'aria-label')), trim(preg_replace('/\s+/u', ' ', $text) ?? ''), trim(SourceDom::attr($link, 'title')) ) as $name ) {
            if ( '' !== $name ) {
                return $name;
            }
        }
        return '';
    }

    /** @return list<\DOMText> */
    private function textNodesOutside(DOMElement $element, DOMElement $excluded): array
    {
        $nodes = array();
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof \DOMText ) {
                $nodes[] = $child;
            } elseif ( $child instanceof DOMElement && ! $child->isSameNode($excluded)
                && ! $child->hasAttribute('hidden') && 'true' !== strtolower(trim(SourceDom::attr($child, 'aria-hidden')))
                && ! in_array(strtolower($child->tagName), array( 'script', 'style', 'template', 'noscript', 'svg' ), true) ) {
                array_push($nodes, ...$this->textNodesOutside($child, $excluded));
            }
        }
        return $nodes;
    }

    /** @return array<string, mixed>|null */
    public function convertImage(DOMElement $image, ?DOMElement $figure = null, ?DOMElement $picture = null, ?DOMElement $link = null, bool $nativeImage = false): ?array
    {
        $this->imageDimensions->fillParentImageViewportPair($image);
        if ( $picture instanceof DOMElement && $this->sourceElementClassifier->hasPictureSourceSelection($picture) ) {
            return $this->responsiveMediaBlock($link ?? $figure ?? $picture ?? $image);
        }
        // Outside <picture>, HTML defines a w-descriptor srcset as one image at
        // several resolutions, so core/image may regenerate candidates from its
        // attachment. Preserve x families (whose rendered width depends on
        // density without a known display width) and other non-lowerable sources.
        $hasDisplayWidth = fn (): bool => $this->imageDimensions->hasAuthorDisplayWidth($image);
        // Independent gallery figures are editable images, not responsive
        // alternatives. Their authored selection travels in image metadata,
        // just as it does for native images without a figure wrapper.
        $mediaParent = ($figure ?? $image)->parentNode;
        $independentGalleryImage = $mediaParent instanceof DOMElement
            && $this->sourceElementClassifier->hasGalleryMediaItems($mediaParent)
            && ! $this->sourceElementClassifier->hasResponsiveMediaAlternatives($mediaParent);
        if ( ! $nativeImage && ! $independentGalleryImage && $this->sourceElementClassifier->requiresResponsiveImageCarrier($image, $hasDisplayWidth) ) {
            return $this->responsiveMediaBlock($link ?? $figure ?? $picture ?? $image);
        }

        $originalUrl = $this->imageUrls->sourceUrl($image);
        $url = $this->imageUrls->resolvedAssetUrl($originalUrl);
        if ( '' === $url ) {
            return null;
        }

        $attrs = $this->imagePresentationAttributes($image, $figure);
        // Site-logo's rounded style belongs to its wrapper, not the source
        // image. Keep the native style, but also give the replacement figure
        // the wrapper's radius: projected logo rules make a/img inherit it and
        // outrank core/image's zero-specificity rounded-image rule.
        $logo = $image->parentNode;
        if ( $logo instanceof DOMElement && 'a' === strtolower($logo->tagName) ) {
            $logo = $logo->parentNode;
        }
        if ( $logo instanceof DOMElement
            && SourceDom::hasClass($logo, 'wp-block-site-logo')
            && SourceDom::hasClass($logo, 'is-style-rounded') ) {
            $radius = array_intersect_key(
                $this->styleResolver->resolvedPresentationDeclarations($logo),
                array_flip(array('border-radius', 'border-top-left-radius', 'border-top-right-radius', 'border-bottom-right-radius', 'border-bottom-left-radius'))
            );
            $attrs['className'] = SourceDom::mergeClassNames(
                (string) ($attrs['className'] ?? ''),
                'is-style-rounded',
                $this->styleResolver->inlineGeometryClassName($logo, array(), array_keys($radius), $radius)
            );
        }
        if ( null !== $picture && ! $figure instanceof DOMElement ) {
            $attrs = array_merge($this->styleResolver->presentationAttributes($picture), $attrs);
        }
        // Boxes flattened between the figure and the image take their inline
        // custom properties with them, yet the image's `var()` readers inherit
        // them (`<div style="--width:118px"><img>` under `.logo img{width:
        // var(--width)}`). Restate them on the figure, innermost box last.
        if ( $figure instanceof DOMElement ) {
            $flattened = array();
            for ( $node = $image->parentNode; $node instanceof DOMElement && ! $node->isSameNode($figure); $node = $node->parentNode ) {
                if ( $node !== $picture && $node !== $link ) {
                    array_unshift($flattened, $node);
                }
            }
            foreach ( $node instanceof DOMElement ? $flattened : array() as $node ) {
                $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), $this->styleResolver->inlineCustomPropertyCarrierClassName($node));
            }
        }
        $width = $this->imageDimensions->imageDisplayDimension($image, 'width');
        $height = $this->imageDimensions->imageDisplayDimension($image, 'height');
        if ( '' !== $this->imageDimensions->parentSizedDimension($image, 'width')
            || '' !== $this->imageDimensions->parentSizedDimension($image, 'height') ) {
            // An image that fills a fixed-size sizing wrapper on both axes (an
            // avatar `.av{width:36px;height:36px} .av img{width:100%;height:100%}`,
            // or `width:inherit;height:inherit` under the same wrapper)
            // has a fixed used size. Wrappers can be flattened or restyled by
            // core, so carry that size itself rather than a percentage of a
            // box that may no longer exist.
            $fixedSize = $this->imageDimensions->fixedPixelSize($image);
            if ( null !== $fixedSize['width'] && null !== $fixedSize['height'] ) {
                $width = $this->cssPixelValue($fixedSize['width']);
                $height = $this->cssPixelValue($fixedSize['height']);
            }
        }
        if ( array() !== $this->styleResolver->flattenedImageFrameBox($image, $figure) ) {
            // The folded frame derives height from its ratio. The source's
            // percentage height referred to the wrapper we no longer emit.
            $height = '';
        }
        if ( '' !== $width || '' !== $height ) {
            $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), 'is-resized');
        }

        $attrs = array_filter(array_merge($attrs, array(
            'url'    => $url,
            'alt'    => $this->linkedImageAlt($image, $link),
            'title'  => SourceDom::attr($image, 'title'),
            'width'  => $width,
            'height' => $height,
        )), static fn ($value): bool => '' !== $value);

        $attrs = array_filter(array_merge($attrs, $this->imageIdentityAttributes($image, $figure)), static fn ($value): bool => '' !== $value);
        $attrs = array_filter(array_merge($attrs, $this->assetMetadataImageAttributes($originalUrl)), static fn ($value): bool => '' !== $value);

        // A shape constraint (aspect-ratio + object-fit) applied to the <img> via
        // a wrapper the transform flattens (e.g. `.hero-frame img`) is lost once
        // the carrier element is gone. Promote it to native aspectRatio/scale
        // attributes so WordPress reproduces the authored crop. `+` never
        // overrides an attribute already resolved above.
        $shape = $this->imageDimensions->shapeConstraintAttributes($image, $width, $height);
        $attrs += $shape;
        // A fixed width AND height that already state the promoted ratio are the
        // image's used size (a 42px x 42px avatar with `aspect-ratio:42/42`),
        // not file metadata competing with a stylesheet-owned crop. Keep them
        // beside aspectRatio/scale; dropping them would let the image render
        // at the uploaded file's natural size.
        if (isset($shape['aspectRatio'])
            && ! $this->imageDimensions->statesAspectRatio((string) ($attrs['width'] ?? ''), (string) ($attrs['height'] ?? ''), (string) $shape['aspectRatio'])
        ) {
            foreach (array( 'width', 'height' ) as $property) {
                if ($this->imageDimensions->imageDimensionIsIntrinsicAttribute($image, $property)) {
                    // HTML dimensions describe the file, not a stylesheet-owned
                    // crop. Keeping either as a core/image style overrides it.
                    unset($attrs[$property]);
                }
            }
        }

        // core/image's save() puts className on the <figure>, never the
        // descendant <img>. A box the author established with min-width/
        // max-width/min-height/max-height — or a width/height the native
        // attribute resolution above could not carry — needs to reach the
        // <img> itself or nothing renders it. Carry it through the same
        // be-inline-geometry primitive materialized SVG artwork already uses
        // for its own box (see SvgMaterializer), targeting a descendant `img`
        // selector so a source anchor still wrapping the image does not
        // matter.
        //
        // A free axis the author resolved to `auto` (Tailwind's `w-auto`/
        // `h-auto`, or a plain `width:auto`) is the same condition that
        // already suppressed this axis's native width/height attribute above
        // — reuse that exact detection rather than re-deriving it, so the
        // carrier restates the author's `auto` instead of leaving the axis
        // to whichever `width`/`height` core's own block-library stylesheet
        // declares for `.wp-block-image img` in the current rendering
        // context (frontend `width:auto` vs. editor-canvas `width:100%`).
        $boxCarrier = $this->styleResolver->imageBoxConstraintClassName(
            $image,
            (string) ($attrs['width'] ?? ''),
            (string) ($attrs['height'] ?? ''),
            isset($shape['aspectRatio']),
            '' === (string) ($attrs['width'] ?? '') && $this->imageDimensions->authorResolvesDimensionToAuto($image, 'width'),
            '' === (string) ($attrs['height'] ?? '') && $this->imageDimensions->authorResolvesDimensionToAuto($image, 'height'),
            $figure
        );
        if ( '' !== $boxCarrier ) {
            $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), $boxCarrier);
        }

        if ( $figure instanceof DOMElement ) {
            $caption = SourceDom::firstChildElement($figure, 'figcaption');
            if ( $caption instanceof DOMElement ) {
                $attrs['caption'] = SourceDom::innerHtml($caption);
                // Core saves only wp-element-caption on this inner part. Bind
                // author rules to a figure carrier, including rich-text subjects
                // whose selectors depended on the source caption's classes.
                $projections = $this->session->authorSelectorProjectionState();
                $captionCarrier = $projections->ensureSemanticMarker($caption->getNodePath() ?? '');
                $subjects = array(array($caption, '.' . $captionCarrier . ' > figcaption.wp-element-caption'));
                while ( array() !== $subjects ) {
                    [ $subject, $selector ] = array_pop($subjects);
                    $projections->installInnerSubject($subject->getNodePath() ?? '', $selector);
                    $index = 0;
                    foreach ( $subject->childNodes as $child ) {
                        if ( $child instanceof DOMElement ) {
                            ++$index;
                            $subjects[] = array($child, $selector . ' > ' . strtolower($child->tagName) . ':nth-child(' . $index . ')');
                        }
                    }
                }
                $captionMarginClassName = $this->styleResolver->imageCaptionMarginClassName($caption);
                if ( '' !== $captionMarginClassName ) {
                    $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), $captionMarginClassName);
                }
                $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), $captionCarrier);
            }
        }

        $linkAnchor = '';
        if ( $link instanceof DOMElement ) {
            $linkAnchor = SourceDom::anchorAttributeValue(SourceDom::attr($link, 'id'));
            if ( '' !== $linkAnchor ) {
                $selectorAnchor = SourceDom::safeAnchor($linkAnchor);
                if ( '' !== $selectorAnchor ) {
                    $projections = $this->session->authorSelectorProjectionState();
                    $projections->installImageLinkMarker($selectorAnchor, $link->getNodePath() ?? '');
                    $attrs['className'] = SourceDom::mergeClassNames(
                        (string) ($attrs['className'] ?? ''),
                        ...$projections->semanticCarrierClasses($projections->ensureImageWrapperMarker($image->getNodePath() ?? ''), $image->getNodePath() ?? '')
                    );
                }
                if ( empty($attrs['anchor']) || $linkAnchor === $attrs['anchor'] ) {
                    $attrs['anchor'] = $linkAnchor;
                    $linkAnchor = '';
                }
            }
            // A source link whose only job is to open the same image larger is
            // a lightbox trigger, not a destination. Core owns that behavior
            // natively, so project it onto the image instead of leaving a link
            // that navigates away from the page. Core ignores its lightbox when
            // the image also carries a link, so the link attributes are dropped.
            $hasHiddenLabel = false;
            foreach ( $link->childNodes as $child ) {
                if ( $child instanceof DOMElement && $this->isHiddenImageLinkLabel($child) ) {
                    $hasHiddenLabel = true;
                }
            }
            if ( ! $hasHiddenLabel && $this->imageLinkOpensNativeLightbox($link) ) {
                $attrs['lightbox'] = array( 'enabled' => true );
            } elseif ( '' !== LinkUrlSanitizer::sanitize(SourceDom::attr($link, 'href')) ) {
                if ('' !== $this->session->authorStyleAnalysis()->combinedCss()) {
                    $projections = $this->session->authorSelectorProjectionState();
                    $path = $link->getNodePath() ?? '';
                    $marker = $projections->ensureSemanticMarker($path);
                    $projections->installInnerSubject($path, ':where(.' . $marker . ')', '', true);
                }
                $attrs = array_filter(array_merge($attrs, $this->imageLinkAttributes($link)), static fn ($value): bool => '' !== $value);
            }
        }

        $selection = $this->authoredImageSelection($image, (string) $attrs['url']);
        if ( array() !== $selection ) {
            $attrs['metadata'] = array_merge(is_array($attrs['metadata'] ?? null) ? $attrs['metadata'] : array(), array( 'blocksEngineImageSelection' => $selection ));
        }

        if ($figure instanceof DOMElement && $link instanceof DOMElement) {
            $imagePresentation = $this->styleResolver->cssDeclarations(SourceDom::attr($image, 'style'));
            foreach (array('display', 'vertical-align') as $property) {
                if (! isset($imagePresentation[$property])) {
                    $value = $this->styleResolver->declaredPresentation($image, $property)->resolvedValue();
                    if ('' !== $value) $imagePresentation[$property] = $value;
                }
            }
            $display = $imagePresentation['display'] ?? $this->styleResolver->defaultTagDisplay($image);
            if ('inline' === CssValueInspector::comparable($display)) {
                // Retain the authored figure box; only the replaced leaf was inline.
                $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), SourceBlockAttributeProjector::INLINE_IMAGE_LEAF_CLASS);
                if ('baseline' === CssValueInspector::comparable($imagePresentation['vertical-align'] ?? 'baseline')) {
                    // Core's bottom alignment would remove the source line-box strut.
                    $attrs['className'] = SourceDom::mergeClassNames((string) $attrs['className'], SourceBlockAttributeProjector::BASELINE_IMAGE_LEAF_CLASS);
                    if ($link instanceof DOMElement) {
                        $linkPresentation = $this->styleResolver->cssDeclarations(SourceDom::attr($link, 'style'));
                        foreach (array('display', 'vertical-align') as $property) {
                            if (! isset($linkPresentation[$property])) {
                                $value = $this->styleResolver->declaredPresentation($link, $property)->resolvedValue();
                                if ('' !== $value) $linkPresentation[$property] = $value;
                            }
                        }
                        if ('inline' === CssValueInspector::comparable($linkPresentation['display'] ?? $this->styleResolver->defaultTagDisplay($link))
                            && 'baseline' === CssValueInspector::comparable($linkPresentation['vertical-align'] ?? 'baseline')) {
                            $attrs['className'] = SourceDom::mergeClassNames((string) $attrs['className'], SourceBlockAttributeProjector::INLINE_IMAGE_LINK_CLASS);
                        }
                    }
                }
            }
        }
        $block = $this->blocks->createBlock('core/image', $attrs, array(), $figure ?? $image);
        // core/image saves the class list and anchor on the <figure>; an author
        // subject naming the <img> by class or id must find them there.
        $this->session->authorSelectorProjectionState()->recordImageFigure(
            $image->getNodePath() ?? '',
            (string) ( $block['attrs']['className'] ?? '' ),
            (string) ( $block['attrs']['anchor'] ?? '' ),
            $link instanceof DOMElement,
            $this->imageNeedsSeparateFigureBox($image, $figure)
                ? $this->session->authorSelectorProjectionState()->imageWrapperMarker($image->getNodePath() ?? '') : ''
        );
        if ( '' !== $linkAnchor ) {
            // A native image has only one anchor. Keep an existing figure/image
            // identity and the link's fragment target on separate native hosts.
            return $this->blocks->createBlock('core/group', array(
                'anchor' => $linkAnchor,
                'layout' => array('type' => 'default'),
            ), array($block));
        }

        return $block;
    }

    /**
     * The authored candidates, slot sizes and loading of a lowered family.
     *
     * core/image saves no srcset or sizes, and attachment-generated candidates
     * describe file widths rather than authored descriptors, so the browser
     * would select different bytes. The selection rides in block metadata and
     * is restored at render while the image still shows the source `src`.
     * Authored `loading` belongs to it: when candidates share a URL, the first
     * fetch fixes the image's density, so eager and lazy copies render apart.
     *
     * @return array{src?:string,srcset?:string,sizes?:string,loading?:string}
     */
    private function authoredImageSelection(DOMElement $image, string $src): array
    {
        if ( array() === SrcsetParser::parse(SourceDom::attr($image, 'srcset')) || $this->hasUnsafeResponsiveImageSources($image) ) {
            return array();
        }
        $loading = strtolower(trim(SourceDom::attr($image, 'loading')));
        $sizes   = trim(SourceDom::attr($image, 'sizes'));

        // The HTML spec applies the `auto` keyword only to lazy-loaded images;
        // any other image ignores a leading `auto` and uses the rest of the
        // list (`auto, 50vw` is `50vw`, a bare `auto` is no sizes at all).
        if ( 'lazy' !== $loading ) {
            $sizes = (string) preg_replace('/^auto(?:\s*,\s*|$)/i', '', $sizes, 1);
        }

        return array_filter(array(
            'src'     => $src,
            'srcset'  => SrcsetParser::rewrite(SourceDom::attr($image, 'srcset'), fn (string $url): string => $this->imageUrls->resolvedAssetUrl($url)),
            'sizes'   => $sizes,
            'loading' => in_array($loading, array( 'lazy', 'eager' ), true) ? $loading : '',
        ), static fn (string $value): bool => '' !== $value);
    }

    /**
     * @return array<string, string>
     */
    private function imageLinkAttributes(DOMElement $link): array
    {
        $attrs = array(
            'href'            => LinkUrlSanitizer::sanitize(SourceDom::attr($link, 'href')),
            'linkDestination' => 'custom',
            'linkTarget'      => SourceDom::attr($link, 'target'),
            'rel'             => SourceDom::attr($link, 'rel'),
            'linkClass'       => SourceDom::mergeClassNames(
                SourceDom::attr($link, 'class'),
                $this->styleResolver->inlineCustomPropertyCarrierClassName($link),
                $this->styleResolver->inlineGeometryClassName($link, array_values(array_diff(
                    array_keys($this->styleResolver->cssDeclarations(SourceDom::attr($link, 'style'))),
                    array('display')
                ))),
                ...$this->session->authorSelectorProjectionState()->semanticMarkersForPath($link->getNodePath() ?? '')
            ),
        );

        return array_filter($attrs, static fn (string $value): bool => '' !== trim($value));
    }

    /**
     * @return array<string, int|string>
     */
    public function assetMetadataImageAttributes(string $url): array
    {
        $asset = $this->imageUrls->assetMetadata($url);
        if ( null === $asset ) {
            return array();
        }

        $attrs = array();
        if ( isset($asset['id']) && ( is_int($asset['id']) || ( is_string($asset['id']) && ctype_digit($asset['id']) ) ) ) {
            $attrs['id'] = (int) $asset['id'];
        }

        if ( isset($asset['url']) && is_string($asset['url']) ) {
            $resolvedUrl = $this->imageUrls->resolvedAssetUrl($url);
            if ( '' !== $resolvedUrl ) {
                $attrs['url'] = $resolvedUrl;
            }
        }

        return $attrs;
    }

    /** Keep independently styled source boxes distinct without changing inert class transport. */
    private function imageNeedsSeparateFigureBox(DOMElement $image, ?DOMElement $figure): bool
    {
        return $figure instanceof DOMElement
            && '' !== $this->nonCoreImageClassName($image)
            && array() !== $this->styleResolver->authoredPresentationDeclarations($figure)
            && array() !== $this->styleResolver->authoredPresentationDeclarations($image);
    }

    /** @return array<string, string> */
    private function imagePresentationAttributes(DOMElement $image, ?DOMElement $figure): array
    {
        $inlineImagePlacement = array_keys(array_filter($this->styleResolver->cssDeclarations(SourceDom::attr($image, 'style')), NativeImageLeafPresentation::isPlacementProperty(...), ARRAY_FILTER_USE_KEY));
        $link = $image->parentNode;
        $syntheticLinkedFigure = ! $figure instanceof DOMElement && $link instanceof DOMElement && 'a' === strtolower($link->tagName);
        $imageBoxProperties = $syntheticLinkedFigure
            ? array_keys(array_filter($this->styleResolver->cssDeclarations(SourceDom::attr($image, 'style')), NativeImageLeafPresentation::isBoxProperty(...), ARRAY_FILTER_USE_KEY))
            : array();
        // A synthetic figure must not inherit the image's preferred ratio:
        // imageBoxConstraintClassName() carries it on the painting leaf instead.
        $attrs = $this->styleResolver->presentationAttributes($figure ?? $image, $figure instanceof DOMElement ? array() : array_merge($inlineImagePlacement, $imageBoxProperties, array('aspect-ratio')));
        if ( ! $figure instanceof DOMElement ) {
            // Native spacing is serialized on the figure even when geometry
            // excludes it. Source image margins are carried on the leaf instead.
            unset($attrs['style']['spacing']['margin']);
        }
        if ( $syntheticLinkedFigure ) {
            // The figure replaces the link in its parent's layout. The image's
            // percentage box and crop still belong to the leaf inside that link.
            $attrs['className'] = SourceDom::mergeClassNames(
                (string) ($attrs['className'] ?? ''),
                $this->styleResolver->inlineGeometryClassName($link),
                $this->styleResolver->syntheticFlexImageBoxClassName($image)
            );
        }
        $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), $this->styleResolver->imageLeafPlacementClassName($image));
        if ( $figure instanceof DOMElement ) {
            // Separate source boxes must keep separate selector ownership. Only
            // inert identities retain the historical class transport.
            $styledLeaf = $this->imageNeedsSeparateFigureBox($image, $figure);
            $marker = $styledLeaf ? $this->session->authorSelectorProjectionState()->ensureImageWrapperMarker($image->getNodePath() ?? '') : '';
            $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), $this->nonCoreImageFigureClassName($figure), $styledLeaf ? $marker : $this->nonCoreImageClassName($image), ...$this->authorSemanticMarkersForElement($image));
        } else {
            $attrs['className'] = $this->styleResolver->mergePresentationClassNames((string) ($attrs['className'] ?? ''), $this->styleResolver->injectedFigureHeightClassName($image));
        }

        return array_filter($attrs, static fn ($value): bool => is_array($value) ? array() !== $value : '' !== trim((string) $value));
    }

    private function cssPixelValue(float $pixels): string
    {
        $formatted = rtrim(rtrim(number_format($pixels, 4, '.', ''), '0'), '.');

        return ('' === $formatted ? '0' : $formatted) . 'px';
    }

    /**
     * @return array<string, int|string>
     */
    private function imageIdentityAttributes(DOMElement $image, ?DOMElement $figure = null): array
    {
        $attrs = array();
        $className = trim(SourceDom::attr($image, 'class') . ' ' . ( $figure instanceof DOMElement ? SourceDom::attr($figure, 'class') : '' ));
        if ( preg_match('/(?:^|\s)wp-image-(\d+)(?:\s|$)/', $className, $matches) ) {
            $attrs['id'] = (int) $matches[1];
        }
        if ( preg_match('/(?:^|\s)size-([a-z0-9_-]+)(?:\s|$)/i', $className, $matches) ) {
            $attrs['sizeSlug'] = strtolower($matches[1]);
        }

        return $attrs;
    }

    private function nonCoreImageClassName(DOMElement $image): string
    {
        $classes = array_filter(preg_split('/\s+/', trim(SourceDom::attr($image, 'class'))) ?: array(), static function (string $className): bool {
            return ! preg_match('/^(?:wp-image-\d+|size-[a-z0-9_-]+)$/i', $className);
        });

        return implode(' ', $classes);
    }

    /** @return list<string> */
    private function authorSemanticMarkersForElement(DOMElement $element): array
    {
        $path = SourceDom::sourceElementIdentity($element);
        return $this->session->authorSelectorProjectionState()->semanticMarkersForPath($path);
    }

    private function nonCoreImageFigureClassName(DOMElement $figure): string
    {
        $classes = array_filter(preg_split('/\s+/', trim(SourceDom::attr($figure, 'class'))) ?: array(), static function (string $className): bool {
            return ! preg_match('/^(?:wp-block-image|size-[a-z0-9_-]+)$/i', $className);
        });

        return implode(' ', $classes);
    }

    public function isImageOnlyAnchor(DOMElement $anchor): bool
    {
        $imageChildren = 0;
        foreach ( $anchor->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                if ( in_array(strtolower($child->tagName), array( 'img', 'picture' ), true) || SourceDom::imageOnlyCarrierElement($child) instanceof DOMElement ) {
                    ++$imageChildren;
                    continue;
                }
                if ( $this->isHiddenImageLinkLabel($child) ) {
                    continue;
                }
                // Lightbox links commonly append empty overlay elements beside
                // their image. They are decoration, not additional link content.
                if ( '' !== trim($child->textContent ?? '') ) {
                    return false;
                }
                continue;
            }

            if ( '' !== trim($child->textContent ?? '') ) {
                return false;
            }
        }

        return 1 === $imageChildren;
    }
}
