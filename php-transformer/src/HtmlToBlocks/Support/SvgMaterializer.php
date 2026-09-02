<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\SvgElementMaterializer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\Support\StyleTagScanner;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Materializes source SVG into emitted blocks: inline SVG hosts, decorative
 * classification, casing restoration, box styling and asset extraction.
 *
 * Extracted from HtmlTransformer as a collaborator rather than a mixin: every
 * dependency it needs from the transformer is declared on
 * {@see SvgMaterializationContext}, so this class has no $this access to the
 * transformer and can be exercised without constructing one.
 */
final class SvgMaterializer implements SvgElementMaterializer
{
    public function __construct(
        private readonly SvgMaterializationContext $context,
        private readonly StyleResolver $styleResolver,
        private readonly Runtime $runtime
    ) {
    }
    /**
     * @return array<string, mixed>|null
     */
    public function inlineSvgBlockFromElement(DOMElement $element): ?array
    {
        // Only preserve when there is actual artwork to keep. An SVG whose only
        // content is unsafe (e.g. a lone <script>) has nothing left to render
        // once sanitized, so it defers to the bounded fallback diagnostic.
        if ( ! SourceDom::svgHasDrawableContent($element) ) {
            return null;
        }

        // Preserve the artwork: sanitize the SVG in place — stripping only the
        // genuinely-unsafe parts (script/style/foreignObject elements, event
        // handlers, javascript: URLs) — instead of dropping the entire graphic
        // the moment one unsafe attribute or element appears. The shape and
        // structure markup (svg/path/circle/rect/g/text/...) is kept so the
        // image renders, rather than collapsing to an empty block.
        $html = $this->context->sanitizeInlineSvgMarkup($element);

        // Safety gate: only emit raw inline SVG once the sanitized markup is
        // provably free of script/event-handler/javascript: vectors and still
        // contains an <svg>. If sanitization could not fully clean it, defer to
        // the bounded fallback metadata path rather than emit unsafe markup.
        if ( ! SourceDom::isSafeSvgContent($html) ) {
            return null;
        }

        $html = $this->cssOwnsMediaBox($element)
            ? $this->ensureInlineSvgBoxStyle($html, $element)
            : $this->ensureInlineSvgSizing($html, $element);
        $html = $this->restoreSvgCasing($this->inlineSvgMaterializationMarkup($this->resolveMaterializedSvgColors($html, $element), $element));
        $imageBlock = $this->inlineSvgImageBlockFromMarkup($element, $html);
        if ( null !== $imageBlock ) {
            return $imageBlock;
        }

        if ( $this->hasPageCssAnimatedDescendant($element) ) {
            return $this->context->svgArtworkBlock($element, $html);
        }

        // Honest floor: keep SVGs that need inline document context as sanitized
        // core/html, with viewBox-derived dimensions to avoid unbounded rendering.
        $this->recordGutenbergIncompatibility($element, 'svg_requires_inline_document_context', 'SVG uses behavior or external document features that cannot be represented as a static editable core/image asset.');
        return $this->context->createBlock('core/html', array( 'content' => $html ), array(), $element);
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

        return $this->context->createBlock('core/image', $attrs, array(), $element);
    }

    /**
     * Materialize a passive SVG as the native image object accepted by RichText.
     *
     * @return array<string, mixed>|null
     */
    private function inlineSvgImageAttributesFromMarkup(DOMElement $element, string $html, bool $richTextImage = false): ?array
    {
        if ( ! $this->isNativeImageCompatibleSvg($element, $html) ) {
            return null;
        }

        $html = $this->ensureSvgImageNamespace($this->minifyInlineSvgForImage($html));
        $assetHtml = $html;
        $visualPayload = $this->svgImageAssetIdentity($html);
        $path = $this->materializedInlineSvgPath($element, $visualPayload);
        $url = $this->sourceRelativeMaterializedSvgPath($path);
        $occurrence = array_filter(array(
            'source_path' => $this->transformSourcePath(),
            'selector' => SourceDom::elementSelector($element),
            'fingerprint' => $this->context->reusableComponentFingerprintFor($element),
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
        $this->context->materializedAssets()->registerInlineSvg($path, $asset, $occurrence, $visualPayload);

        $dimensions = $this->cssOwnsMediaBox($element) ? array() : $this->svgImageDimensions($element, $html);
        $presentation = $this->styleResolver->presentationDeclarations($element);
        $sourceDisplay = strtolower(trim((string) ($presentation['display'] ?? '')));
        $parent = $element->parentNode;
        $parentPresentation = $parent instanceof DOMElement ? $this->styleResolver->structuralPresentationDeclarations($parent) : array();
        $parentDisplay = strtolower(trim((string) ($parentPresentation['display'] ?? '')));
        $isFlexOrGridItem = in_array($parentDisplay, array( 'flex', 'inline-flex', 'grid', 'inline-grid' ), true);
        $sourceObjectFit = strtolower(trim((string) ($presentation['object-fit'] ?? '')));
        $isPositionedMediaBox = $parent instanceof DOMElement
            && in_array(strtolower(trim((string) ($parentPresentation['position'] ?? ''))), array( 'relative', 'absolute', 'fixed', 'sticky' ), true)
            && $this->declarationsOwnMediaBox($parentPresentation);
        // A responsive SVG (width/height="100%") fills a sized flex/grid wrapper,
        // or a positioned media wrapper when object-fit makes that intent explicit.
        // Make the generated core/image figure fill that wrapper and drop its
        // default margin instead of collapsing to intrinsic viewBox geometry.
        $isResponsiveFillSvg = (
            ($isFlexOrGridItem && $parent instanceof DOMElement && $this->declarationsOwnMediaBox($parentPresentation))
            || ($isPositionedMediaBox && in_array($sourceObjectFit, array( 'contain', 'cover', 'fill', 'none', 'scale-down' ), true))
        )
            && null !== $this->svgPercentageWidth(trim(SourceDom::attr($element, 'width')))
            && null !== $this->svgPercentageWidth(trim(SourceDom::attr($element, 'height')));
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
            $imgRule = '>img{width:100%;height:100%;-o-object-fit:' . $objectFit . ';object-fit:' . $objectFit . '}';
            $fillClass = $this->context->layoutGeometry()->allocateCarrier($this->styleResolver->geometryStructuralPath($element) . "\n" . $figureRule . $imgRule);
            $this->context->layoutGeometry()->registerRule($fillClass, '.' . $fillClass . $figureRule . '.wp-block-image.' . $fillClass . $imgRule);
            $attrs = array(
                'url'       => $url,
                'alt'       => $this->svgImageAlt($element),
                'className'  => $this->styleResolver->mergePresentationClassNames(SourceDom::attr($element, 'class'), $fillClass),
            );

            return array_filter($attrs, static fn ($value): bool => null !== $value && '' !== $value);
        }
        $preserveInlineGeometry = ! $isFlexOrGridItem && ( '' === $sourceDisplay || in_array($sourceDisplay, array( 'inline', 'inline-block' ), true) );
        $preserveBlockDisplay = ! $richTextImage && 'block' === $sourceDisplay;
        $geometryClass = '';
        if ( $preserveInlineGeometry || $preserveBlockDisplay ) {
            $imageDisplay = $preserveBlockDisplay ? 'block' : ('' === $sourceDisplay ? 'inline' : $sourceDisplay);
            $mediaBox = '';
            foreach ( array( 'width', 'height', 'min-width', 'max-width', 'min-height', 'max-height', 'aspect-ratio' ) as $property ) {
                if ( isset($presentation[$property]) && '' !== trim((string) $presentation[$property]) ) {
                    $mediaBox .= ';' . $property . ':' . trim((string) $presentation[$property]);
                }
            }
            $rule = ($richTextImage ? '' : '>img') . '{display:' . $imageDisplay . ($preserveInlineGeometry ? ';vertical-align:baseline' : '') . $mediaBox . '}';
            $geometryClass = $this->context->layoutGeometry()->allocateCarrier($this->styleResolver->geometryStructuralPath($element) . "\n" . $rule);
            $this->context->layoutGeometry()->registerRule($geometryClass, ($preserveBlockDisplay ? '.' . $geometryClass . '{line-height:0}' : '') . '.' . $geometryClass . $rule);
        } elseif ( ! $richTextImage ) {
            // Core/image rejects typography.lineHeight. A standalone SVG still
            // needs its source line box removed when it becomes a figure.
            $rule = '{line-height:0}';
            $geometryClass = $this->context->layoutGeometry()->allocateCarrier($this->styleResolver->geometryStructuralPath($element) . "\n" . $rule);
            $this->context->layoutGeometry()->registerRule($geometryClass, '.' . $geometryClass . $rule);
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
        if ( ! SourceDom::svgHasDrawableContent($element) ) {
            return null;
        }

        $html = $this->context->sanitizeInlineSvgMarkup($element);
        if ( ! SourceDom::isSafeSvgContent($html) ) {
            return null;
        }

        $html = $this->cssOwnsMediaBox($element)
            ? $this->ensureInlineSvgBoxStyle($html, $element)
            : $this->ensureInlineSvgSizing($html, $element);
        $html = $this->restoreSvgCasing($this->inlineSvgMaterializationMarkup($this->resolveMaterializedSvgColors($html, $element), $element));
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
            $resolved = $this->richTextSvgDimensions($element, $this->styleResolver->presentationDeclarations($element));
            foreach ( array( 'width', 'height', 'min-width', 'max-width', 'min-height', 'max-height', 'aspect-ratio' ) as $dimension ) {
                if ( ! isset($resolved[$dimension]) || preg_match('/(?:^|;)\s*' . preg_quote($dimension, '/') . '\s*:/i', $style) ) {
                    continue;
                }
                $style = trim($style, ';') . ( '' === trim($style, ';') ? '' : ';' ) . $dimension . ':' . $resolved[$dimension];
            }
        }
        foreach ( array( 'width', 'height' ) as $dimension ) {
            if ( empty($attrs[$dimension]) || preg_match('/(?:^|;)\s*' . $dimension . '\s*:/i', $style) ) {
                continue;
            }
            $style = trim($style, ';') . ( '' === trim($style, ';') ? '' : ';' ) . $dimension . ':' . $attrs[$dimension];
        }

        $imageAttributes = array(
            'src' => (string) $attrs['url'],
            'alt' => (string) ($attrs['alt'] ?? ''),
            'class' => (string) ($attrs['className'] ?? ''),
            'style' => $style,
        );
        $markup = '<img' . $this->svgRichTextHtmlAttributes($imageAttributes, array( 'alt' )) . ' />';

        if ( $includeLink ) {
            $link = $this->svgImageLinkAttributes($element);
            if ( array() !== $link ) {
                $markup = '<a' . $this->svgRichTextHtmlAttributes($link) . '>' . $markup . '</a>';
            }
        }

        return $markup;
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
                $parentDimensions = $this->styleResolver->cssDeclarations($this->styleResolver->specificityResolvedPresentationStyle($parent));
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

                $sourceVisualDimension = trim(SourceDom::attr($parent, 'data-source-visual-' . $dimension));
                if ( '' === $fallback && is_numeric($sourceVisualDimension) && (float) $sourceVisualDimension > 0 ) {
                    $fallback = $this->normalizedSvgDimension((float) $sourceVisualDimension * $scale) . 'px';
                }
            }
            if ( '' !== $fallback ) {
                $dimensions[$dimension] = $fallback;
            }
        }

        return $dimensions;
    }

    /** @param array<string, string> $attributes */
    private function svgRichTextHtmlAttributes(array $attributes, array $alwaysInclude = array()): string
    {
        $html = '';
        foreach ( $attributes as $name => $value ) {
            if ( '' === $value && ! in_array($name, $alwaysInclude, true) ) {
                continue;
            }
            $html .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        return $html;
    }

    /** @return array<string, string> */
    private function svgImageLinkAttributes(DOMElement $element): array
    {
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

    public function svgNeedsPhrasingHost(DOMElement $element): bool
    {
        if ( $this->context->isVisualLayerElement($element) || 'none' === strtolower(trim(SourceDom::attr($element, 'preserveaspectratio'))) ) {
            return false;
        }

        $parent = $element->parentNode;
        if ( $parent instanceof DOMElement ) {
            $parentTag = strtolower($parent->tagName);
            if ( 'p' === $parentTag ) {
                return true;
            }
            if ( 'article' === $parentTag && 'img' === strtolower(trim(SourceDom::attr($element, 'role'))) ) {
                return false;
            }
            if ( ( $this->context->isInlineContentElement($parentTag) || 'a' === $parentTag ) && '' !== trim($this->runtime->stripAllTags(SourceDom::innerHtmlWithoutTags($parent, array( 'svg' )))) ) {
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
                return 'svg' !== $tag && ! $this->context->isInlineContentElement($tag);
            }
        }
        for ( $sibling = $element->nextSibling; null !== $sibling; $sibling = $sibling->nextSibling ) {
            if ( $sibling instanceof DOMElement ) {
                $tag = strtolower($sibling->tagName);
                return 'svg' !== $tag && ! $this->context->isInlineContentElement($tag);
            }
        }

        return false;
    }

    private function cssOwnsMediaBox(DOMElement $element): bool
    {
        return $this->declarationsOwnMediaBox($this->styleResolver->presentationDeclarations($element));
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

        $definitions = array();
        foreach ( $element->ownerDocument->getElementsByTagName('defs') as $defs ) {
            if ( ! $defs instanceof DOMElement || $this->svgDefinitionBelongsTo($defs, $element) ) {
                continue;
            }
            foreach ( $defs->getElementsByTagName('*') as $definition ) {
                if ( $definition instanceof DOMElement && isset($references[trim(SourceDom::attr($definition, 'id'))]) ) {
                    $definitions[] = $this->context->safeFallbackHtml($defs);
                    break;
                }
            }
        }
        if ( array() === $definitions ) {
            return $html;
        }

        return preg_replace('/<\/svg>\s*$/i', implode('', array_unique($definitions)) . '</svg>', $html, 1) ?? $html;
    }

    private function svgDefinitionBelongsTo(DOMElement $definition, DOMElement $svg): bool
    {
        for ( $node = $definition; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( $node->isSameNode($svg) ) {
                return true;
            }
        }

        return false;
    }

    private function svgImageAssetIdentity(string $html): string
    {
        // core/image owns the accessible name through its per-instance alt
        // attribute. Accessibility metadata therefore does not participate in
        // visual asset identity, while the first materialized payload retains
        // its original metadata for compatibility with direct SVG consumers.
        $html = preg_replace('/\s(?:aria-[a-z-]+|title)\s*=\s*(["\']).*?\1/i', '', $html) ?? $html;
        return preg_replace('/<(?:title|desc)\b[^>]*>.*?<\/(?:title|desc)>/is', '', $html) ?? $html;
    }

    private function ensureSvgImageNamespace(string $html): string
    {
        if ( preg_match('/<svg\b[^>]*\sxmlns\s*=/i', $html) ) {
            return $html;
        }

        return preg_replace('/<svg\b/i', '<svg xmlns="http://www.w3.org/2000/svg"', $html, 1) ?? $html;
    }

    private function resolveMaterializedSvgColors(string $html, DOMElement $element): string
    {
        $html = $this->styleResolver->resolveCssVariablesInValue($html);
        if ( false !== stripos($html, 'currentColor') ) {
            $html = preg_replace('/\bcurrentColor\b/i', $this->inheritedSvgColor($element), $html) ?? $html;
        }

        return $this->bakeCascadedSvgPaint($html, $element);
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

    private function resolvedCascadedSvgPaint(DOMElement $element, string $property): ?string
    {
        for ( $current = $element; $current instanceof DOMElement; $current = $current->parentNode instanceof DOMElement ? $current->parentNode : null ) {
            $resolved = $this->styleResolver->resolvedSvgCascadeValue($current, $property);
            if ( null === $resolved ) {
                if ( $current === $element && '' !== trim(SourceDom::attr($current, $property)) ) {
                    // The element's own presentation attribute already carries
                    // this paint into the materialized markup verbatim.
                    return null;
                }
                continue;
            }

            $resolved = trim($resolved);
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
        $merged = array_merge($declarations, $this->styleResolver->cssDeclarations($existingStyle));
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


    /**
     * @return array<string, string>
     */
    private function cssCustomProperties(string $html, string $linkedCss): array
    {
        $css = trim($linkedCss);
        $styles = StyleTagScanner::scan($html);
        if ( array() !== $styles ) {
            $css .= ( '' === $css ? '' : "\n" ) . implode("\n", array_map(static fn (array $style): string => trim($style['content']), $styles));
        }
        if ( '' === trim($css) ) {
            return array();
        }

        $rootProperties = array();
        ( new CssStylesheetTransformer() )->transform($css, static function (string $prelude, string $body) use (&$rootProperties): string {
            $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
            if ( null === $selectors || ! array_filter($selectors, static function (string $selector): bool {
                $selector = preg_replace('/\/\*.*?\*\//s', '', $selector) ?? $selector;
                return in_array(strtolower(trim($selector)), array( ':root', 'html' ), true);
            }) ) {
                return $prelude;
            }
            if ( preg_match_all('/(--[A-Za-z0-9_-]+)\s*:\s*([^;{}]+)/', $body, $matches, PREG_SET_ORDER) ) {
                foreach ( $matches as $match ) {
                    $rootProperties[(string) $match[1]] = trim((string) $match[2]);
                }
            }
            return $prelude;
        });
        if ( array() !== $rootProperties ) {
            return $rootProperties;
        }

        if ( ! preg_match_all('/(--[A-Za-z0-9_-]+)\s*:\s*([^;{}]+)/', $css, $matches, PREG_SET_ORDER) ) {
            return array();
        }

        $properties = array();
        foreach ( $matches as $match ) {
            $properties[(string) $match[1]] = trim((string) $match[2]);
        }

        return $properties;
    }

    private function materializedInlineSvgPath(DOMElement $element, string $html): string
    {
        unset($element);
        // The asset is the sanitized vector payload, not its generated source
        // selector. A content address lets every compatible instance share one
        // core/image asset while retaining its own alt text and presentation.
        $filename = 'inline-svg-' . substr(hash('sha256', $html), 0, 16) . '.svg';
        return $this->context->materializedAssets()->rootedPath('assets/materialized-svg/' . $filename);
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
            $value = $this->context->transformationProvenance()->fallback()[$key] ?? '';
            if ( '' !== trim((string) $value) ) {
                return trim((string) $value);
            }
        }

        return '';
    }

    private function recordGutenbergIncompatibility(DOMElement $element, string $reason, string $message): void
    {
        $this->context->transformationEvidence()->recordGutenbergIncompatibility(array(
            'type'     => 'svg_materialization_incompatibility',
            'element'  => 'svg',
            'selector' => SourceDom::elementSelector($element),
            'reason'   => $reason,
            'message'  => $message,
        ));
    }

    private function isNativeImageCompatibleSvg(DOMElement $element, string $html): bool
    {
        if ( ! $this->isPassiveSvgMarkup($element) ) {
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
        foreach ( $this->styleResolver->cssDeclarations(SourceDom::attr($element, 'style')) as $property => $value ) {
            if ( preg_match('/var\s*\(/i', $value) && ! isset($boxProperties[strtolower($property)]) ) {
                return false;
            }
        }
        if ( preg_match('/\s(?:href|xlink:href)\s*=\s*(["\'])(?!#)[^"\']+\1/i', $html) ) {
            return false;
        }

        return true;
    }

    private function hasPageCssAnimatedDescendant(DOMElement $element): bool
    {
        // A materialized image is a separate document, so page CSS cannot reach
        // animated descendants such as steam paths or illustrated particles.
        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if ( ! $descendant instanceof DOMElement ) {
                continue;
            }
            $declarations = $this->styleResolver->matchedCascadedDeclarations($descendant);
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
        $value = trim($value);
        if ( '' === $value || ! preg_match('/^\d+(?:\.\d+)?$/', $value) ) {
            return '';
        }

        return $value . 'px';
    }

    private function svgImageAlt(DOMElement $element): string
    {
        if ( 'true' === strtolower(trim(SourceDom::attr($element, 'aria-hidden'))) ) {
            return '';
        }

        foreach ( array( 'aria-label', 'title' ) as $attribute ) {
            $value = trim(SourceDom::attr($element, $attribute));
            if ( '' !== $value ) {
                return $value;
            }
        }

        $title = $element->getElementsByTagName('title')->item(0);
        return $title instanceof DOMElement ? trim((string) $title->textContent) : '';
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
        if ( preg_match('/\s(?:width|height)\s*=/i', $attrs) || preg_match('/\sstyle\s*=\s*(["\'])(?:(?!\1).)*(?:width|height)\s*:/i', $attrs) ) {
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

        $existingDeclarations = $this->styleResolver->cssDeclarations(SourceDom::attr($element, 'style'));
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

        $role = strtolower(trim(SourceDom::attr($element, 'role')));
        if ( 'true' === strtolower(trim(SourceDom::attr($element, 'aria-hidden'))) || in_array($role, array( 'presentation', 'none' ), true) ) {
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

    private function isPassiveSvgMarkup(DOMElement $element): bool
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
            'amplitude', 'aria-hidden', 'aria-label', 'azimuth', 'basefrequency', 'bias',
            'class', 'clip-path', 'clip-rule', 'color-interpolation-filters', 'cx', 'cy', 'd',
            'data-bbox', 'data-color', 'data-testid', 'data-type',
            'diffuseconstant', 'divisor', 'dominant-baseline', 'dx', 'dy', 'edgemode',
            'elevation', 'enable-background', 'exponent', 'fill', 'fill-opacity', 'fill-rule', 'flood-color',
            'flood-opacity', 'font-family',
            'filter', 'filterunits', 'focusable', 'font-size', 'font-style', 'font-weight', 'gradienttransform', 'gradientunits',
            'height', 'id', 'letter-spacing', 'marker-end', 'marker-mid', 'marker-start',
            'intercept', 'k1', 'k2', 'k3', 'k4', 'kernelmatrix', 'kernelunitlength',
            'lighting-color', 'markerheight', 'markerunits', 'markerwidth', 'mask', 'mode',
            'numoctaves', 'offset', 'opacity', 'operator', 'order', 'orient',
            'href', 'patterncontentunits', 'patterntransform', 'patternunits', 'points',
            'preservealpha', 'preserveaspectratio', 'primitiveunits', 'r', 'radius', 'refx', 'refy',
            'result', 'role', 'rotate', 'rx', 'ry', 'scale', 'seed', 'slope',
            'specularconstant', 'specularexponent',
            'spreadmethod', 'stop-color', 'stop-opacity', 'stroke', 'stroke-dasharray',
            'stroke-dashoffset', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit',
            'stitchtiles', 'stroke-opacity', 'stroke-width', 'stddeviation', 'style', 'surfacescale',
            'tablevalues', 'targetx', 'targety', 'text-anchor', 'transform', 'type',
            'values', 'vector-effect', 'version', 'viewbox', 'width', 'x', 'x1', 'x2',
            'xchannelselector', 'xlink:href', 'xml:space', 'y', 'y1', 'y2', 'ychannelselector', 'in', 'in2', 'title',
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
            if ( ! $child instanceof DOMElement || ! $this->isPassiveSvgElement($child, $allowedTags, $allowedAttributes) ) {
                return false;
            }
        }

        return $this->isPassiveSvgElement($element, $allowedTags, $allowedAttributes);
    }

    /**
     * @param array<string, int> $allowedTags
     * @param array<string, int> $allowedAttributes
     */
    private function isPassiveSvgElement(DOMElement $element, array $allowedTags, array $allowedAttributes): bool
    {
        if ( ! isset($allowedTags[strtolower($element->tagName)]) ) {
            return false;
        }

        foreach ( SourceDom::htmlAttributes($element) as $name => $value ) {
            $name = strtolower($name);
            $isInertDataAttribute = str_starts_with($name, 'data-') && 'data-dom-store' !== $name;
            if ( (! isset($allowedAttributes[$name]) && ! $isInertDataAttribute) || preg_match('/^on[a-z]+$/i', $name) || preg_match('/javascript\s*:|\b(?:expression|behavior)\s*:/i', $value) ) {
                return false;
            }
            if ( preg_match('/(?:^|:)href$/i', $name) && ! str_starts_with(trim($value), '#') ) {
                return false;
            }
            if ( preg_match('/\burl\s*\((?!\s*["\']?#[-_a-z0-9]+["\']?\s*\))/i', $value) ) {
                return false;
            }
        }

        return true;
    }
}
