<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceDeclarations;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\FallbackDiagnostic;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\RuntimeIslandAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\SourceElementDescription;
use Closure;
use DOMElement;
use LogicException;

/**
 * Lowers bounded external visual iframe surfaces to core/embed: provider URLs
 * through core's oEmbed, any other safe frame with its attributes in metadata
 * for the theme's frame renderer (WordPressSitePlan\IframeEmbedRuntime).
 */
final class VisualIframeBlockGenerator
{
    /** core/embed metadata key holding the source frame's bounded attributes. */
    public const FRAME_METADATA = 'blocksEngineIframe';

    /**
     * Mirrors core's `@wordpress/block-library` embed block aspect-ratio
     * table (`ASPECT_RATIOS` in `embed/constants.js`) so a source iframe's
     * own authored ratio can be expressed through the exact same
     * `wp-embed-aspect-*`/`wp-has-aspect-ratio` classes and CSS core already
     * ships. Keyed by exact fraction (not the UI's rounded 2-decimal
     * comparison values) since these are the precise ratios core's
     * `style.scss` bakes into each class's `padding-top` percentage.
     */
    private const EMBED_ASPECT_RATIO_CLASSES = array(
        'wp-embed-aspect-21-9' => 21 / 9,
        'wp-embed-aspect-18-9' => 18 / 9,
        'wp-embed-aspect-16-9' => 16 / 9,
        'wp-embed-aspect-4-3'  => 4 / 3,
        'wp-embed-aspect-1-1'  => 1.0,
        'wp-embed-aspect-9-16' => 9 / 16,
        'wp-embed-aspect-1-2'  => 1 / 2,
    );

    /**
     * @param Closure(DOMElement): bool $isInertRuntimeMediaPlaceholder
     * @param Closure(DOMElement): bool $sourceElementStartsHidden
     */
    public function __construct(
        private readonly SourceElementClassifier $sourceElementClassifier = new SourceElementClassifier(),
        private readonly ?StyleResolver $styleResolver = null,
        private readonly ?SourceBlockCreator $createBlock = null,
        private readonly ?RuntimeIslandAnalyzer $runtimeIslands = null,
        private readonly ?HtmlTransformerSession $session = null,
        private readonly ?Closure $isInertRuntimeMediaPlaceholder = null,
        private readonly ?Closure $sourceElementStartsHidden = null,
        private readonly ?SourceElementDescription $sourceElementDescription = null
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    public function convert(DOMElement $iframe, array &$fallbacks): ?array
    {
        $styleResolver = $this->styleResolver ?? throw new LogicException('VisualIframeBlockGenerator was not wired for conversion.');
        $createBlock = $this->createBlock ?? throw new LogicException('VisualIframeBlockGenerator was not wired for conversion.');
        $runtimeIslands = $this->runtimeIslands ?? throw new LogicException('VisualIframeBlockGenerator was not wired for conversion.');
        $session = $this->session ?? throw new LogicException('VisualIframeBlockGenerator was not wired for conversion.');
        $isInertRuntimeMediaPlaceholder = $this->isInertRuntimeMediaPlaceholder ?? throw new LogicException('VisualIframeBlockGenerator was not wired for conversion.');
        $sourceElementStartsHidden = $this->sourceElementStartsHidden ?? throw new LogicException('VisualIframeBlockGenerator was not wired for conversion.');
        $sourceElementDescription = $this->sourceElementDescription ?? throw new LogicException('VisualIframeBlockGenerator was not wired for conversion.');
        $customHost = 'iframe' !== strtolower($iframe->tagName) ? $iframe : null;
        $surface = $iframe;
        $url = '';
        if ( $customHost instanceof DOMElement ) {
            $classification = $this->classifyCustomIframeSurface($customHost, $runtimeIslands, $isInertRuntimeMediaPlaceholder);
            if ( 'inert' === $classification['disposition'] ) {
                return null;
            }
            if ( 'accepted' !== $classification['disposition'] || ! $classification['surface'] instanceof DOMElement || '' === $classification['url'] ) {
                $this->recordIframeSurfaceCapabilityGap($customHost, $classification, $fallbacks, $session, $sourceElementDescription);
                return null;
            }
            $surface = $classification['surface'];
            $url = $classification['url'];
        } else {
            $url = $this->customVisualIframeUrl($iframe, $surface);
        }
        $providerNameSlug = '' === $url ? '' : $this->embedProviderSlug($url);
        // A provider round-trip replaces the source iframe with a new box and
        // cannot preserve its conditional geometry. CSS-sized frames use the
        // existing editable iframe block so source selectors still own the box,
        // except an inline fill at a core embed ratio, which core/embed draws as is.
        // Hidden frames also keep their native element so source hiding selectors still apply.
        $fluidRatio = '' === $providerNameSlug ? null : $this->fluidAspectRatio($surface, $styleResolver);
        $sourceSized = null === $fluidRatio && $this->sourceOwnsIframeGeometry($surface, $styleResolver);
        if ( '' !== $providerNameSlug && ! $sourceSized && ! $sourceElementStartsHidden($surface) ) {
            $embedAttrs = array_merge($styleResolver->presentationAttributes($surface), array(
                'url'              => $this->canonicalEmbedUrl($url),
                'type'             => $this->embedTypeForSlug($providerNameSlug),
                'providerNameSlug' => $providerNameSlug,
            ));
            if ( $this->inheritsPreservedWhitespace($surface, $styleResolver) ) {
                // Core serializes newlines around the iframe; an inherited pre-wrap would paint them.
                $embedAttrs['className'] = SourceDom::mergeClassNames(
                    (string) ($embedAttrs['className'] ?? ''),
                    SourceBlockAttributeProjector::EMBED_NORMAL_WHITESPACE_CLASS
                );
            }
            $sizingClassName = null !== $fluidRatio
                ? $this->nearestEmbedAspectRatioClassName($fluidRatio)
                : $this->embedSizingClassName($surface, $styleResolver);
            if ( '' !== $sizingClassName ) {
                // Only once the source has committed to an exact sizing intent
                // (#1918's ratio or #1923's absolute height) does the figure's
                // own unauthored default margin become a measurable surplus
                // against that intent — see SourceBlockAttributeProjector::
                // SYNTHETIC_EMBED_FIGURE_CLASS. An unsized, unstyled embed
                // expressed no such opinion and keeps ordinary block spacing.
                $embedAttrs['className'] = SourceDom::mergeClassNames(
                    (string) ($embedAttrs['className'] ?? ''),
                    $sizingClassName,
                    SourceBlockAttributeProjector::SYNTHETIC_EMBED_FIGURE_CLASS
                );
                if (str_contains($sizingClassName, 'wp-has-aspect-ratio')) {
                    $embedAttrs['responsive'] = true;
                }
            }
            $block = $createBlock->createBlock('core/embed', array_filter($embedAttrs, static fn ($value): bool => '' !== $value), array(), $surface);
            return $customHost instanceof DOMElement ? $this->customVisualIframeHostBlock($customHost, $surface, $block, $styleResolver, $createBlock) : $block;
        }

        $visualIframeAttributes = $this->boundedVisualIframeAttributes($surface, $url, $styleResolver);
        if ( null !== $visualIframeAttributes ) {
            $runtimeIslands->recordRuntimeIsland($iframe, 'iframe', 'iframe_requires_embed_runtime', 'third_party_embed_runtime', array(
                'preservation_strategy' => 'typed_visual_iframe_companion',
                'attributes' => array_merge($this->safeEmbedAttributes($surface), array( 'src' => $url )),
            ));
            // A frame no oEmbed provider serves stays a core/embed of its URL.
            // Its frame attributes ride in block metadata and the theme renders
            // the source's bare iframe from them (ThemeBootstrap::IFRAME_EMBED_RENDERER),
            // so author rules keep sizing the frame itself, not a wrapper.
            $frame = $visualIframeAttributes;
            unset($frame['src'], $frame['className']);
            $block = $createBlock->createBlock(
                'core/embed',
                array_filter(array(
                    'url' => $url,
                    'type' => 'rich',
                    'className' => $visualIframeAttributes['className'] ?? '',
                    'metadata' => array( self::FRAME_METADATA => $frame ),
                ), static fn (mixed $value): bool => '' !== $value),
                array(),
                $surface
            );
            return $customHost instanceof DOMElement ? $this->customVisualIframeHostBlock($customHost, $surface, $block, $styleResolver, $createBlock) : $block;
        }

        $boundedHtml = SourceDom::boundedFallbackHtml(SourceDom::safeFallbackHtml($iframe, $this->session->authorSelectorProjectionState()->tagMarkers()));
        $runtimeIslands->recordRuntimeIsland($iframe, 'iframe', 'iframe_requires_embed_runtime', 'third_party_embed_runtime', array(
            'preservation_strategy' => 'sanitized_embed_markup',
            'attributes'            => $this->safeEmbedAttributes($iframe),
        ));
        $fallbacks[] = FallbackDiagnostic::build(array(
            'type'            => 'html',
            'reason'          => 'iframe_embed_fallback',
            'diagnostic_code' => 'html_iframe_embed_fallback',
            'message'         => 'Iframe embed HTML was preserved as sanitized bounded fallback metadata.',
            'source_format'   => 'html',
            'tag'             => 'iframe',
            'selector'        => SourceDom::elementSelector($iframe),
            'attributes'      => $this->safeEmbedAttributes($iframe),
            'context'         => $sourceElementDescription->sourceContext($iframe),
            'classification'  => $session->fallbackEmitter()->classifyFallbackSubtree($iframe),
            'events'          => SourceDom::eventMetadata($iframe),
            'html'            => $boundedHtml['html'],
            'html_bytes'      => $boundedHtml['bytes'],
            'html_truncated'  => $boundedHtml['truncated'],
        ), $session->transformationProvenanceState()->fallback());

        return null;
    }

    /**
     * @param Closure(DOMElement): bool $isInertRuntimeMediaPlaceholder
     * @return array{disposition: string, reason: string, url: string, surface: DOMElement|null}
     */
    private function classifyCustomIframeSurface(DOMElement $host, RuntimeIslandAnalyzer $runtimeIslands, Closure $isInertRuntimeMediaPlaceholder): array
    {
        $rejected = static fn (string $reason): array => array(
            'disposition' => 'rejected',
            'reason' => $reason,
            'url' => '',
            'surface' => null,
        );
        $rawValues = array();
        $unsafe = false;
        $srcdoc = false;
        foreach ( SourceDom::subtreeElements($host) as $element ) {
            if ( ! $element instanceof DOMElement ) {
                continue;
            }
            if ( '' !== trim(SourceDom::attr($element, 'srcdoc')) ) {
                $srcdoc = true;
            }
            foreach ( array( 'src', 'data-src', 'data-url', 'data-embed-url', 'data-iframe-src' ) as $attribute ) {
                foreach ( $this->iframeDestinationValues(trim(SourceDom::attr($element, $attribute))) as $destination ) {
                    $rawValues[] = $destination;
                }
            }
        }

        $urls = array();
        $credentials = false;
        foreach ( $rawValues as $value ) {
            if ( $this->sourceElementClassifier->isUnsafeIframeDestination($value) ) {
                $unsafe = true;
                continue;
            }
            if ( $this->iframeUrlHasCredentials($value) ) {
                $credentials = true;
                continue;
            }
            $safe = $this->safeEmbedUrl($value);
            if ( '' !== $safe ) {
                $urls[$safe] = true;
            }
        }

        if ( $srcdoc || $unsafe ) {
            return $rejected('unsafe_iframe_destination');
        }
        if ( $credentials ) {
            return $rejected('credential_bound_iframe');
        }
        if ( 1 < count($urls) ) {
            return $rejected('ambiguous_iframe_destination');
        }
        if ( $runtimeIslands->isRuntimeDomTarget($host)
            || array() !== SourceDom::eventMetadata($host)
            || $this->sourceElementClassifier->hasMotionStructureToken($host)
        ) {
            return $rejected('source_runtime_only_iframe');
        }

        $url = 1 === count($urls) ? (string) array_key_first($urls) : '';
        $surface = $this->customVisualIframeSurface($host, $runtimeIslands);
        if ( '' !== $url && $surface instanceof DOMElement ) {
            return array(
                'disposition' => 'accepted',
                'reason' => 'portable_iframe_destination',
                'url' => $url,
                'surface' => $surface,
            );
        }
        if ( $isInertRuntimeMediaPlaceholder($host) ) {
            return array(
                'disposition' => 'inert',
                'reason' => 'inert_iframe_placeholder',
                'url' => '',
                'surface' => null,
            );
        }

        return $rejected('source_runtime_only_iframe');
    }

    /**
     * @param array{disposition: string, reason: string, url: string, surface: DOMElement|null} $classification
     * @param array<int, array<string, mixed>> $fallbacks
     */
    private function recordIframeSurfaceCapabilityGap(DOMElement $host, array $classification, array &$fallbacks, HtmlTransformerSession $session, SourceElementDescription $sourceElementDescription): void
    {
        $fallbacks[] = FallbackDiagnostic::build(array(
            'type'            => 'capability_gap',
            'reason'          => $classification['reason'],
            'diagnostic_code' => 'html_iframe_surface_capability_gap',
            'message'         => 'Custom iframe media was classified as an explicit capability gap instead of raw HTML.',
            'source_format'   => 'html',
            'tag'             => strtolower($host->tagName),
            'selector'        => SourceDom::elementSelector($host),
            'context'         => $sourceElementDescription->sourceContext($host),
            'classification'  => $session->fallbackEmitter()->classifyFallbackSubtree($host),
            'events'          => SourceDom::eventMetadata($host),
        ), $session->transformationProvenanceState()->fallback());
    }

    private function customVisualIframeSurface(DOMElement $host, RuntimeIslandAnalyzer $runtimeIslands): ?DOMElement
    {
        $iframes = array();
        foreach ( $host->getElementsByTagName('iframe') as $iframe ) {
            if ( $iframe instanceof DOMElement ) {
                $iframes[] = $iframe;
            }
        }
        if ( 1 < count($iframes) ) {
            return null;
        }
        $iframe = $iframes[0] ?? null;
        foreach ( $host->getElementsByTagName('*') as $descendant ) {
            if ( ! $descendant instanceof DOMElement || $descendant === $iframe ) {
                continue;
            }
            if ( ! $this->sourceElementClassifier->isStructuralTransparentCustomWrapperChild($descendant)
                || $runtimeIslands->isRuntimeDomTarget($descendant)
                || array() !== SourceDom::eventMetadata($descendant)
                || ( ! $iframe instanceof DOMElement && '' !== trim($descendant->textContent ?? '') )
            ) {
                return null;
            }
        }

        return $iframe ?? $host;
    }

    private function customVisualIframeUrl(DOMElement $host, DOMElement $surface): string
    {
        $urls = array();
        foreach ( array( $host, $surface ) as $element ) {
            foreach ( array( 'src', 'data-src', 'data-url', 'data-embed-url', 'data-iframe-src' ) as $attribute ) {
                foreach ( $this->iframeDestinationValues(trim(SourceDom::attr($element, $attribute))) as $candidate ) {
                    if ( $this->sourceElementClassifier->isUnsafeIframeDestination($candidate) || $this->iframeUrlHasCredentials($candidate) ) {
                        return '';
                    }
                    $url = $this->safeEmbedUrl($candidate);
                    if ( '' !== $url ) {
                        $urls[$url] = true;
                    }
                }
            }
        }

        return 1 === count($urls) ? (string) array_key_first($urls) : '';
    }

    /** @return array<int, string> */
    private function iframeDestinationValues(string $value): array
    {
        if ( '' === $value ) {
            return array();
        }
        if ( str_starts_with($value, '{') || str_starts_with($value, '[') ) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $this->iframeDestinationsFromJson($decoded) : array();
        }

        return array( $value );
    }

    /** @param array<mixed> $data @return array<int, string> */
    private function iframeDestinationsFromJson(array $data): array
    {
        $found = array();
        $stack = array( $data );
        $depth = 0;
        while ( array() !== $stack && $depth < 8 && 8 > count($found) ) {
            $node = array_pop($stack);
            ++$depth;
            if ( ! is_array($node) ) {
                continue;
            }
            foreach ( $node as $key => $item ) {
                if ( is_string($item) && is_string($key) && 1 === preg_match('/(?:url|src)$/i', $key) ) {
                    $found[] = $item;
                } elseif ( is_array($item) ) {
                    $stack[] = $item;
                }
            }
        }

        return $found;
    }

    private function iframeUrlHasCredentials(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && ( isset($parts['user']) || isset($parts['pass']) );
    }

    /**
     * @param array<string, mixed> $mediaBlock
     * @return array<string, mixed>
     */
    private function customVisualIframeHostBlock(DOMElement $host, DOMElement $surface, array $mediaBlock, StyleResolver $styleResolver, SourceBlockCreator $createBlock): array
    {
        for ( $wrapper = $surface === $host ? null : $surface->parentNode; $wrapper instanceof DOMElement && $wrapper !== $host; $wrapper = $wrapper->parentNode ) {
            $mediaBlock = $createBlock->createBlock('core/group', $styleResolver->presentationAttributes($wrapper), array( $mediaBlock ), $wrapper);
        }
        return $createBlock->createBlock('core/group', $styleResolver->presentationAttributes($host), array( $mediaBlock ), $host);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function boundedVisualIframeAttributes(DOMElement $iframe, string $url, StyleResolver $styleResolver): ?array
    {
        if ( ! $this->sourceElementClassifier->isSafeVisualIframeUrl($url) ) {
            return null;
        }

        $attributes = $this->safeEmbedAttributes($iframe);
        $width = $this->boundedVisualIframeDimension($iframe, 'width', $styleResolver);
        $height = $this->boundedVisualIframeDimension($iframe, 'height', $styleResolver);
        $sourceSized = $this->sourceOwnsIframeGeometry($iframe, $styleResolver);
        $widthRuled = $this->sourceOwnsIframeGeometry($iframe, $styleResolver, array( 'width' ));
        $heightRuled = $this->sourceOwnsIframeGeometry($iframe, $styleResolver, array( 'height' ));
        if ( ! $sourceSized && ( null === $width || null === $height ) ) {
            return null;
        }

        return array_filter(array(
            'src' => $url,
            'title' => $attributes['title'] ?? '',
            // Only CSS for the same axis replaces a size attribute. Position and
            // aspect-ratio do not override an explicit width or height.
            'width' => $sourceSized ? ($widthRuled ? '' : SourceDom::attr($iframe, 'width')) : (SourceDom::attr($iframe, 'width') ?: $width),
            'height' => $sourceSized ? ($heightRuled ? '' : SourceDom::attr($iframe, 'height')) : (SourceDom::attr($iframe, 'height') ?: $height),
            'className' => $sourceSized ? ($styleResolver->presentationAttributes($iframe)['className'] ?? '') : ($attributes['class'] ?? ''),
            'allow' => $attributes['allow'] ?? '',
            'loading' => $attributes['loading'] ?? '',
            'sandbox' => $attributes['sandbox'] ?? '',
            'referrerPolicy' => $attributes['referrerpolicy'] ?? '',
            'allowFullScreen' => array_key_exists('allowfullscreen', $attributes),
        ), static fn (mixed $value): bool => '' !== $value && false !== $value);
    }

    /**
     * Any source sizing rule owns geometry, even outside the reference viewport.
     *
     * @param list<string> $properties
     */
    private function sourceOwnsIframeGeometry(DOMElement $iframe, StyleResolver $styleResolver, array $properties = array( 'width', 'height', 'aspect-ratio', 'position' )): bool
    {
        return array() !== $styleResolver->authorDeclaredPropertyValues($iframe, $properties)
            || array() !== CssRuleAnalyzer::declarations(SourceDom::attr($iframe, 'style'), $properties);
    }


    /** Whether the nearest ancestor declaring `white-space` preserves whitespace at the reference viewport. */
    private function inheritsPreservedWhitespace(DOMElement $surface, StyleResolver $styleResolver): bool
    {
        for ( $node = $surface->parentNode; $node instanceof DOMElement; $node = $node->parentNode ) {
            $value = strtolower(trim((string) ($styleResolver->authorViewport->reference($node, array( 'white-space' ))['white-space'] ?? '')));
            if ( '' !== $value && 'inherit' !== $value ) {
                return 1 === preg_match('/\b(?:pre|pre-wrap|pre-line|break-spaces|preserve)\b/', $value);
            }
        }
        return false;
    }
    /**
     * The ratio of a frame whose only sizing is an unconditional inline "fill
     * the width at a core embed ratio" (`width:100%;height:auto;aspect-ratio:16/9`).
     * A responsive core/embed draws exactly that box, so such a frame needs no
     * source-owned geometry and no pinned wrapper height. Null for any other
     * sizing: author rules (which may be conditional) and ratios no
     * `wp-embed-aspect-*` preset matches keep the visual-iframe block, so no
     * ratio is approximated.
     */
    private function fluidAspectRatio(DOMElement $iframe, StyleResolver $styleResolver): ?float
    {
        if ( array() !== $styleResolver->authorDeclaredPropertyValues($iframe, array( 'width', 'height', 'aspect-ratio', 'position' )) ) {
            return null;
        }
        $declarations = array_change_key_case(SourceDeclarations::parse(SourceDom::attr($iframe, 'style')));
        $value = static fn (string $property): string => strtolower(trim((string) ($declarations[ $property ] ?? '')));
        if ( '100%' !== $value('width') || ! in_array($value('height'), array( '', 'auto' ), true) || ! in_array($value('position'), array( '', 'static' ), true) ) {
            return null;
        }
        if ( 1 !== preg_match('/^(\d+(?:\.\d+)?)\s*(?:\/\s*(\d+(?:\.\d+)?))?$/', $value('aspect-ratio'), $match) ) {
            return null;
        }
        $ratio = (float) $match[1] / (float) ($match[2] ?? 1);
        foreach ( self::EMBED_ASPECT_RATIO_CLASSES as $presetRatio ) {
            if ( abs($ratio - $presetRatio) <= $presetRatio * 0.005 ) {
                return $presetRatio;
            }
        }

        return null;
    }

    /** Only attribute-sized, CSS-free provider embeds reach this path. */
    private function embedSizingClassName(DOMElement $iframe, StyleResolver $styleResolver): string
    {
        $height = trim(SourceDom::attr($iframe, 'height'));
        if (! $this->sourceElementClassifier->isPositiveIframeDimension($height)) return '';
        $carrier = $styleResolver->embedWrapperHeightClassName($iframe, $this->cssPixelValue((float) $height));
        if ('' === $carrier) return '';
        $width = trim(SourceDom::attr($iframe, 'width'));
        $ratio = $this->sourceElementClassifier->isPositiveIframeDimension($width) ? (float) $width / (float) $height : 16 / 9;
        return SourceDom::mergeClassNames($carrier, $this->nearestEmbedAspectRatioClassName($ratio));
    }

    /**
     * Core's `@wordpress/block-library` embed editor picks its own nearest
     * preset with a directional `>=` tolerance check (`embed/util.js`),
     * which would reject a real-world ratio like 1022:520. Picking by
     * absolute distance instead lets a source ratio that falls between two
     * presets still resolve to its nearest visual match instead of losing
     * the author's sizing intent entirely.
     */
    private function nearestEmbedAspectRatioClassName(float $ratio): string
    {
        $closestClassName = '';
        $closestDiff = INF;
        foreach ( self::EMBED_ASPECT_RATIO_CLASSES as $className => $presetRatio ) {
            $diff = abs($ratio - $presetRatio);
            if ( $diff < $closestDiff ) {
                $closestDiff = $diff;
                $closestClassName = $className;
            }
        }

        return '' === $closestClassName ? '' : $closestClassName . ' wp-has-aspect-ratio';
    }

    /** A trimmed CSS pixel length for a positive value, e.g. `520` -> `'520px'`. */
    private function cssPixelValue(float $value): string
    {
        $formatted = rtrim(rtrim(sprintf('%.3f', $value), '0'), '.');

        return $formatted . 'px';
    }

    private function boundedVisualIframeDimension(DOMElement $iframe, string $dimension, StyleResolver $styleResolver): ?string
    {
        $attribute = trim(SourceDom::attr($iframe, $dimension));
        if ( $this->sourceElementClassifier->isPositiveIframeDimension($attribute) ) {
            return $attribute;
        }

        if ( $this->sourceElementClassifier->isRelativeIframeDimension($attribute) && $this->relativeIframeDimensionIsBounded($iframe, $dimension, $styleResolver) ) {
            return $attribute;
        }

        $declaration = trim((string) ($styleResolver->presentationDeclarations($iframe)[$dimension] ?? ''));
        if ( $this->sourceElementClassifier->isPositiveIframeDimension($declaration) ) {
            return $declaration;
        }

        return $this->sourceElementClassifier->isRelativeIframeDimension($declaration) && $this->relativeIframeDimensionIsBounded($iframe, $dimension, $styleResolver)
            ? $declaration
            : null;
    }

    private function relativeIframeDimensionIsBounded(DOMElement $iframe, string $dimension, StyleResolver $styleResolver): bool
    {
        // Container-relative width needs only an explicit height; relative height still needs a bounded ancestor.
        if ( 'width' === $dimension ) {
            $height = trim(SourceDom::attr($iframe, 'height'));
            $declaredHeight = trim((string) ($styleResolver->presentationDeclarations($iframe)['height'] ?? ''));
            if ( $this->sourceElementClassifier->isPositiveIframeDimension($height) || $this->sourceElementClassifier->isPositiveIframeDimension($declaredHeight) ) {
                return true;
            }
        }

        return $this->iframeHasBoundedAncestor($iframe, $styleResolver);
    }

    private function iframeHasBoundedAncestor(DOMElement $iframe, StyleResolver $styleResolver): bool
    {
        for ( $ancestor = $iframe->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode ) {
            $declarations = $styleResolver->presentationDeclarations($ancestor);
            $width = trim(SourceDom::attr($ancestor, 'width')) ?: trim((string) ($declarations['width'] ?? ''));
            $height = trim(SourceDom::attr($ancestor, 'height')) ?: trim((string) ($declarations['height'] ?? ''));
            if ( $this->sourceElementClassifier->isPositiveIframeDimension($width) && $this->sourceElementClassifier->isPositiveIframeDimension($height) ) {
                return true;
            }
        }

        return false;
    }

    private function safeEmbedUrl(string $url): string
    {
        $url = trim($url);
        if ( '' === $url || ! preg_match('#^https?://#i', $url) ) {
            return '';
        }

        return preg_match('/[\x00-\x1f\x7f]|javascript\s*:/i', $url) ? '' : $url;
    }

    private function canonicalEmbedUrl(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        $facebookVideoUrl = $this->facebookPluginVideoUrl($url);
        if ( '' !== $facebookVideoUrl ) {
            return $facebookVideoUrl;
        }

        if ( ( str_ends_with($host, 'youtube.com') || str_ends_with($host, 'youtube-nocookie.com') ) && preg_match('~^/embed/([^/?#]+)~', $path, $matches) ) {
            return 'https://www.youtube.com/watch?v=' . $matches[1];
        }

        if ( 'youtu.be' === $host && '' !== trim($path, '/') ) {
            return 'https://www.youtube.com/watch?v=' . trim($path, '/');
        }

        if ( str_ends_with($host, 'vimeo.com') && preg_match('#/(?:video/)?(\d+)#', $path, $matches) ) {
            return 'https://vimeo.com/' . $matches[1];
        }

        if ( str_ends_with($host, 'dailymotion.com') && preg_match('~^/embed/video/([^/?#]+)~', $path, $matches) ) {
            return 'https://www.dailymotion.com/video/' . $matches[1];
        }

        if ( 'open.spotify.com' === $host && preg_match('~^/embed/((?:track|album|playlist|episode|show|artist)/[^/?#]+)~', $path, $matches) ) {
            return 'https://open.spotify.com/' . $matches[1];
        }

        return $url;
    }

    private function facebookPluginVideoUrl(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ( ! str_ends_with($host, 'facebook.com') || '/plugins/video.php' !== $path ) {
            return '';
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $videoUrl = $this->safeEmbedUrl(ArrayField::string($query, 'href'));
        $videoHost = strtolower((string) parse_url($videoUrl, PHP_URL_HOST));

        return str_ends_with($videoHost, 'facebook.com') ? $videoUrl : '';
    }

    private function embedProviderSlug(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ( str_ends_with($host, 'youtube.com') || str_ends_with($host, 'youtube-nocookie.com') || 'youtu.be' === $host ) {
            return 'youtube';
        }
        if ( str_ends_with($host, 'vimeo.com') ) {
            return 'vimeo';
        }
        if ( str_ends_with($host, 'dailymotion.com') && preg_match('~^/embed/video/[^/?#]+~', $path) ) {
            return 'dailymotion';
        }
        if ( 'open.spotify.com' === $host && preg_match('~^/embed/(?:track|album|playlist|episode|show|artist)/[^/?#]+~', $path) ) {
            return 'spotify';
        }
        if ( '' !== $this->facebookPluginVideoUrl($url) ) {
            return 'facebook';
        }

        return '';
    }

    private function embedTypeForSlug(string $slug): string
    {
        return 'spotify' === $slug ? 'rich' : 'video';
    }

    /** @return array<string, string> */
    private function safeEmbedAttributes(DOMElement $element): array
    {
        $safe = array();
        $allowed = array_flip(array( 'allow', 'allowfullscreen', 'class', 'height', 'loading', 'referrerpolicy', 'sandbox', 'src', 'title', 'width' ));
        foreach ( SourceDom::htmlAttributes($element) as $name => $value ) {
            if ( isset($allowed[$name]) && ! preg_match('/javascript\s*:/i', $value) ) {
                $safe[$name] = strlen($value) > 300 ? mb_strcut($value, 0, 300, 'UTF-8') . '...' : $value;
            }
        }

        return $safe;
    }
}
