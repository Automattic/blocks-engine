<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\FallbackDiagnostic;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\RuntimeIslandAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Closure;
use DOMElement;
use LogicException;

/** Builds the typed companion block for bounded external visual iframe surfaces. */
final class VisualIframeBlockGenerator
{
    public const LOCAL_NAME = 'visual-iframe';

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
     * @param Closure(DOMElement): array{html: string, bytes: int, truncated: bool} $boundedFallbackHtml
     * @param Closure(DOMElement): array<string, mixed> $sourceContext
     */
    public function __construct(
        private readonly SourceElementClassifier $sourceElementClassifier = new SourceElementClassifier(),
        private readonly ?StyleResolver $styleResolver = null,
        private readonly ?SourceBlockCreator $createBlock = null,
        private readonly ?RuntimeIslandAnalyzer $runtimeIslands = null,
        private readonly ?HtmlTransformerSession $session = null,
        private readonly ?Closure $isInertRuntimeMediaPlaceholder = null,
        private readonly ?Closure $sourceElementStartsHidden = null,
        private readonly ?Closure $boundedFallbackHtml = null,
        private readonly ?Closure $sourceContext = null
    ) {
    }

    /** @return array<string, mixed> */
    public function blockJson(string $namespace): array
    {
        return array(
            'apiVersion' => 3,
            'name' => $namespace . '/' . self::LOCAL_NAME,
            'title' => 'Embedded Content',
            'category' => 'embed',
            'description' => 'A bounded external visual surface.',
            'editorScript' => 'file:./index.js',
            'attributes' => array(
                'src' => array( 'type' => 'string', 'default' => '' ),
                'title' => array( 'type' => 'string', 'default' => '' ),
                'width' => array( 'type' => 'string', 'default' => '' ),
                'height' => array( 'type' => 'string', 'default' => '' ),
                'className' => array( 'type' => 'string', 'default' => '' ),
                'allow' => array( 'type' => 'string', 'default' => '' ),
                'loading' => array( 'type' => 'string', 'default' => '' ),
                'sandbox' => array( 'type' => 'string', 'default' => '' ),
                'referrerPolicy' => array( 'type' => 'string', 'default' => '' ),
                'allowFullScreen' => array( 'type' => 'boolean', 'default' => false ),
            ),
            'supports' => array( 'html' => false ),
        );
    }

    /** @return array<string, string> */
    public function assets(string $blockName): array
    {
        $namespace = strstr($blockName, '/', true) ?: '';
        $script = <<<'JS'
( function( blocks, blockEditor, components, element ) {
    var createElement = element.createElement;
    var attributes = __BLOCK_ATTRIBUTES__;
    function isSafeVisualIframeUrl( value ) {
        try {
            var url = new URL( value );
            return url.protocol === 'https:' && !! url.hostname && ! url.username && ! url.password;
        } catch ( error ) {
            return false;
        }
    }
    function iframeProps( attributes ) {
        var props = {};
        [ 'src', 'title', 'width', 'height', 'allow', 'loading', 'sandbox', 'referrerPolicy' ].forEach( function( name ) { if ( attributes[ name ] ) { props[ name ] = attributes[ name ]; } } );
        if ( attributes.className ) { props.className = attributes.className; }
        if ( attributes.allowFullScreen ) { props.allowFullScreen = true; }
        return props;
    }
    function edit( props ) {
        var useState = element.useState;
        var useEffect = element.useEffect;
        var state = useState( props.attributes.src || '' );
        var draftSrc = state[ 0 ];
        var setDraftSrc = state[ 1 ];
        var overlayState = useState( ! props.isSelected );
        var keepClickOverlay = overlayState[ 0 ];
        var setKeepClickOverlay = overlayState[ 1 ];
        useEffect( function() {
            if ( ! props.isSelected ) {
                setKeepClickOverlay( true );
            }
        }, [ props.isSelected ] );
        var setAttribute = function( name ) { return function( value ) { props.setAttributes( { [ name ]: value } ); }; };
        var inspector = props.isSelected ? createElement( blockEditor.InspectorControls, {},
            createElement( components.PanelBody, { title: 'Embedded content' },
                createElement( components.TextControl, {
                    label: 'URL', value: draftSrc,
                    help: draftSrc && ! isSafeVisualIframeUrl( draftSrc ) ? 'Enter an HTTPS URL without credentials.' : undefined,
                    onChange: setDraftSrc,
                    onBlur: function() { var src = draftSrc.trim(); if ( isSafeVisualIframeUrl( src ) ) { props.setAttributes( { src: src } ); setDraftSrc( src ); } else { setDraftSrc( props.attributes.src || '' ); } }
                } ),
                createElement( components.TextControl, { label: 'Title', value: props.attributes.title || '', onChange: setAttribute( 'title' ) } ),
                createElement( components.TextControl, { label: 'Width', value: props.attributes.width || '', onChange: setAttribute( 'width' ) } ),
                createElement( components.TextControl, { label: 'Height', value: props.attributes.height || '', onChange: setAttribute( 'height' ) } ),
                createElement( components.TextControl, { label: 'Allow permissions', value: props.attributes.allow || '', onChange: setAttribute( 'allow' ) } ),
                createElement( components.SelectControl, { label: 'Loading', value: props.attributes.loading || '', options: [ { label: 'Default', value: '' }, { label: 'Lazy', value: 'lazy' }, { label: 'Eager', value: 'eager' } ], onChange: setAttribute( 'loading' ) } ),
                createElement( components.TextControl, { label: 'Sandbox', value: props.attributes.sandbox || '', onChange: setAttribute( 'sandbox' ) } ),
                createElement( components.TextControl, { label: 'Referrer policy', value: props.attributes.referrerPolicy || '', onChange: setAttribute( 'referrerPolicy' ) } ),
                createElement( components.ToggleControl, { label: 'Allow fullscreen', checked: !! props.attributes.allowFullScreen, onChange: setAttribute( 'allowFullScreen' ) } )
            )
        ) : null;
        return createElement( element.Fragment, {}, inspector,
                createElement( 'div', blockEditor.useBlockProps( { style: { position: 'relative' } } ),
                    createElement( 'iframe', iframeProps( props.attributes ) ),
                    keepClickOverlay && createElement( 'div', {
                        'aria-hidden': true,
                        onMouseUp: function() { setKeepClickOverlay( false ); },
                        style: { position: 'absolute', inset: 0, cursor: 'pointer' }
                    } )
            )
        );
    }
    function save( props ) { return createElement( 'iframe', iframeProps( props.attributes ) ); }
    blocks.registerBlockType( '__BLOCK_NAME__', { attributes: attributes, supports: { html: false }, edit: edit, save: save } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element );
JS;

        return array( 'index.js' => str_replace(
            array( '__BLOCK_NAME__', '__BLOCK_ATTRIBUTES__' ),
            array( $blockName, json_encode($this->blockJson($namespace)['attributes'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) ),
            $script
        ) );
    }

    /** @param array<string, mixed> $attributes */
    public function markup(array $attributes): string
    {
        $names = array(
            'className' => 'class', 'src' => 'src', 'title' => 'title', 'width' => 'width', 'height' => 'height',
            'allow' => 'allow', 'loading' => 'loading', 'sandbox' => 'sandbox', 'referrerPolicy' => 'referrerpolicy',
        );
        $markup = '<iframe';
        foreach ( $names as $attribute => $htmlName ) {
            $value = trim((string) ($attributes[$attribute] ?? ''));
            if ( '' !== $value ) {
                $markup .= ' ' . $htmlName . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            }
        }
        if ( true === ($attributes['allowFullScreen'] ?? false) ) {
            $markup .= ' allowfullscreen=""';
        }

        return $markup . '></iframe>';
    }

    /** @return array<string, mixed> */
    public function definition(string $namespace): array
    {
        return array(
            'name' => self::LOCAL_NAME,
            'block_json' => $this->blockJson($namespace),
            'assets' => $this->assets($namespace . '/' . self::LOCAL_NAME),
            'script_dependencies' => array( 'index.js' => array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element' ) ),
        );
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
        $boundedFallbackHtml = $this->boundedFallbackHtml ?? throw new LogicException('VisualIframeBlockGenerator was not wired for conversion.');
        $sourceContext = $this->sourceContext ?? throw new LogicException('VisualIframeBlockGenerator was not wired for conversion.');
        $registry = $session->generatedBlockRegistry()
            ?? throw new LogicException('Generated block registry has not been prepared for this transform.');

        $customHost = 'iframe' !== strtolower($iframe->tagName) ? $iframe : null;
        $surface = $iframe;
        $url = '';
        if ( $customHost instanceof DOMElement ) {
            $classification = $this->classifyCustomIframeSurface($customHost, $runtimeIslands, $isInertRuntimeMediaPlaceholder);
            if ( 'inert' === $classification['disposition'] ) {
                return null;
            }
            if ( 'accepted' !== $classification['disposition'] || ! $classification['surface'] instanceof DOMElement || '' === $classification['url'] ) {
                $this->recordIframeSurfaceCapabilityGap($customHost, $classification, $fallbacks, $session, $sourceContext);
                return null;
            }
            $surface = $classification['surface'];
            $url = $classification['url'];
        } else {
            $url = $this->customVisualIframeUrl($iframe, $surface);
        }
        $providerNameSlug = '' === $url ? '' : $this->embedProviderSlug($url);
        if ( '' !== $providerNameSlug ) {
            $embedAttrs = array_merge($styleResolver->presentationAttributes($surface), array(
                'url'              => $this->canonicalEmbedUrl($url),
                'type'             => $this->embedTypeForSlug($providerNameSlug),
                'providerNameSlug' => $providerNameSlug,
            ));
            $sizingClassName = $this->embedSizingClassName($surface, $styleResolver);
            if ( '' !== $sizingClassName ) {
                $embedAttrs['className'] = SourceDom::mergeClassNames((string) ($embedAttrs['className'] ?? ''), $sizingClassName);
            }
            $block = $createBlock->createBlock('core/embed', array_filter($embedAttrs, static fn ($value): bool => '' !== $value), array(), $surface);
            return $customHost instanceof DOMElement ? $this->customVisualIframeHostBlock($customHost, $surface, $block, $styleResolver, $createBlock) : $block;
        }

        $visualIframeAttributes = $this->boundedVisualIframeAttributes($surface, $url, $styleResolver, $sourceElementStartsHidden);
        if ( null !== $visualIframeAttributes ) {
            $runtimeIslands->recordRuntimeIsland($iframe, 'iframe', 'iframe_requires_embed_runtime', 'third_party_embed_runtime', array(
                'preservation_strategy' => 'typed_visual_iframe_companion',
                'attributes' => array_merge($this->safeEmbedAttributes($surface), array( 'src' => $url )),
            ));
            $registry->register(self::class, $this->definition($registry->namespace()));
            $block = $createBlock->createBlock(
                $registry->blockName(self::LOCAL_NAME),
                $visualIframeAttributes,
                array(),
                $surface
            );
            $block['innerHTML'] = $this->markup($visualIframeAttributes);
            $block['innerContent'] = array( $block['innerHTML'] );
            return $customHost instanceof DOMElement ? $this->customVisualIframeHostBlock($customHost, $surface, $block, $styleResolver, $createBlock) : $block;
        }

        $boundedHtml = $boundedFallbackHtml($iframe);
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
            'context'         => $sourceContext($iframe),
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
        foreach ( array_merge(array( $host ), iterator_to_array($host->getElementsByTagName('*'))) as $element ) {
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
     * @param Closure(DOMElement): array<string, mixed> $sourceContext
     */
    private function recordIframeSurfaceCapabilityGap(DOMElement $host, array $classification, array &$fallbacks, HtmlTransformerSession $session, Closure $sourceContext): void
    {
        $fallbacks[] = FallbackDiagnostic::build(array(
            'type'            => 'capability_gap',
            'reason'          => $classification['reason'],
            'diagnostic_code' => 'html_iframe_surface_capability_gap',
            'message'         => 'Custom iframe media was classified as an explicit capability gap instead of raw HTML.',
            'source_format'   => 'html',
            'tag'             => strtolower($host->tagName),
            'selector'        => SourceDom::elementSelector($host),
            'context'         => $sourceContext($host),
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
     * @param Closure(DOMElement): bool $sourceElementStartsHidden
     * @return array<string, mixed>|null
     */
    private function boundedVisualIframeAttributes(DOMElement $iframe, string $url, StyleResolver $styleResolver, Closure $sourceElementStartsHidden): ?array
    {
        if ( ! $this->sourceElementClassifier->isSafeVisualIframeUrl($url) || $sourceElementStartsHidden($iframe) ) {
            return null;
        }

        $attributes = $this->safeEmbedAttributes($iframe);
        $width = $this->boundedVisualIframeDimension($iframe, 'width', $styleResolver);
        $height = $this->boundedVisualIframeDimension($iframe, 'height', $styleResolver);
        if ( null === $width || null === $height ) {
            return null;
        }

        return array_filter(array(
            'src' => $url,
            'title' => $attributes['title'] ?? '',
            'width' => SourceDom::attr($iframe, 'width') ?: $width,
            'height' => SourceDom::attr($iframe, 'height') ?: $height,
            'className' => $attributes['class'] ?? '',
            'allow' => $attributes['allow'] ?? '',
            'loading' => $attributes['loading'] ?? '',
            'sandbox' => $attributes['sandbox'] ?? '',
            'referrerPolicy' => $attributes['referrerpolicy'] ?? '',
            'allowFullScreen' => array_key_exists('allowfullscreen', $attributes),
        ), static fn (mixed $value): bool => '' !== $value && false !== $value);
    }

    /**
     * A source iframe's own authored sizing expresses the visual intent it
     * was designed at (e.g. a Spotify playlist embed sized tall enough to
     * show several tracks). oEmbed's default render for the same URL
     * routinely ignores that intent and substitutes the provider's own
     * default height, since core/embed's autoembed path re-fetches the
     * embed HTML from the provider with no way to request our attributes
     * back (`WP_Embed::autoembed_callback()` calls `shortcode()` with an
     * empty attribute array, so nothing stored on the block can influence
     * the provider request).
     *
     * An ABSOLUTE authored height (see {@see StyleResolver::
     * embedWrapperHeightClassName()}) wins first and is carried exactly,
     * since it's the author's literal, unambiguous intent. A bare RATIO
     * (an authored CSS `aspect-ratio` with no concrete height at all) is
     * only a proportional approximation of that intent and is kept as the
     * fallback #1918 already established, via core's own seven
     * `wp-embed-aspect-*` presets. Returns '' when the source expressed no
     * sizing signal at all, so the provider default remains untouched.
     */
    private function embedSizingClassName(DOMElement $iframe, StyleResolver $styleResolver): string
    {
        $height = $this->explicitPixelIframeDimension($iframe, 'height', $styleResolver);
        if ( null !== $height && 0.0 < $height ) {
            $carrier = $styleResolver->embedWrapperHeightClassName($iframe, $this->cssPixelValue($height));

            return '' === $carrier ? '' : $carrier . ' wp-has-aspect-ratio';
        }

        $ratio = $this->embedAspectRatioCssValue($iframe, $styleResolver);

        return null === $ratio ? '' : $this->nearestEmbedAspectRatioClassName($ratio);
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

    /**
     * A bare ratio, expressed the same way a source stylesheet would
     * express "I know my proportions but not my absolute size": the CSS
     * `aspect-ratio` shorthand (`16/9`, `16 / 9`, or a bare number). Unlike
     * an explicit pixel `height`, this never resolves to an ABSOLUTE box —
     * only ever to the closest of core's seven presets.
     */
    private function embedAspectRatioCssValue(DOMElement $iframe, StyleResolver $styleResolver): ?float
    {
        $value = trim((string) ($styleResolver->presentationDeclarations($iframe)['aspect-ratio'] ?? ''));
        if ( '' === $value || 1 !== preg_match('/^(\d+(?:\.\d+)?)\s*(?:\/\s*(\d+(?:\.\d+)?))?$/', $value, $matches) ) {
            return null;
        }

        $width = (float) $matches[1];
        $height = isset($matches[2]) && '' !== $matches[2] ? (float) $matches[2] : 1.0;

        return 0.0 < $width && 0.0 < $height ? $width / $height : null;
    }

    /** A trimmed CSS pixel length for a positive value, e.g. `520` -> `'520px'`. */
    private function cssPixelValue(float $value): string
    {
        $formatted = rtrim(rtrim(sprintf('%.3f', $value), '0'), '.');

        return $formatted . 'px';
    }

    /**
     * A concrete pixel value for `$dimension`, checked as an explicit HTML
     * attribute first and then as a resolved CSS declaration. Percentage or
     * otherwise non-absolute values return null: they cannot anchor a real
     * aspect ratio, and the provider default should win in that case.
     */
    private function explicitPixelIframeDimension(DOMElement $iframe, string $dimension, StyleResolver $styleResolver): ?float
    {
        $attribute = trim(SourceDom::attr($iframe, $dimension));
        if ( $this->sourceElementClassifier->isPositiveIframeDimension($attribute) ) {
            return (float) $attribute;
        }

        $declaration = trim((string) ($styleResolver->presentationDeclarations($iframe)[$dimension] ?? ''));
        if ( $this->sourceElementClassifier->isPositiveIframeDimension($declaration) ) {
            return (float) $declaration;
        }

        return null;
    }

    private function boundedVisualIframeDimension(DOMElement $iframe, string $dimension, StyleResolver $styleResolver): ?string
    {
        $attribute = trim(SourceDom::attr($iframe, $dimension));
        if ( $this->sourceElementClassifier->isPositiveIframeDimension($attribute) ) {
            return $attribute;
        }

        if ( $this->sourceElementClassifier->isRelativeIframeDimension($attribute) && $this->iframeHasBoundedAncestor($iframe, $styleResolver) ) {
            return $attribute;
        }

        $declaration = trim((string) ($styleResolver->presentationDeclarations($iframe)[$dimension] ?? ''));
        if ( $this->sourceElementClassifier->isPositiveIframeDimension($declaration) ) {
            return $declaration;
        }

        return $this->sourceElementClassifier->isRelativeIframeDimension($declaration) && $this->iframeHasBoundedAncestor($iframe, $styleResolver)
            ? $declaration
            : null;
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
        $videoUrl = $this->safeEmbedUrl(is_string($query['href'] ?? null) ? $query['href'] : '');
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
                $safe[$name] = strlen($value) > 300 ? substr($value, 0, 300) . '...' : $value;
            }
        }

        return $safe;
    }
}
