<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\BlockFactory;
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
        private readonly ImageUrlResolver $imageUrls
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
}
