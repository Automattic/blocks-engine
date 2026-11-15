<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceDeclarations;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ImageLowering;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceElementVisibility;
use DOMElement;

use Closure;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\DisclosureControlPresentation;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\ElementPresentationResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;

final class PatternContext
{
    /**
     * @param PatternTreeConverter|null $recursiveConverter
     * @param NavigationPatternContext|null $navigationContext
     * @param MediaPatternContext|null $mediaContext
     * @param ColumnsPatternContext|null $columnsContext
     * @param MarkupPatternContext|null $markupContext
     * @param ButtonPatternContext|null $buttonContext
     * @param QuotePatternContext|null $quoteContext
     * @param CodeWindowPatternContext|null $codeWindowContext
     * @param LogoPatternContext|null $logoContext
     * @param ImageLowering|null $images
     * @param Closure(DOMElement): string|null $richTextContent
     */
    public function __construct(
        private readonly ElementPresentationResolver $presentation,
        private readonly ?PatternTreeConverter $recursiveConverter = null,
        private readonly ?NavigationPatternContext $navigationContext = null,
        private readonly ?MediaPatternContext $mediaContext = null,
        private readonly ?ColumnsPatternContext $columnsContext = null,
        private readonly ?MarkupPatternContext $markupContext = null,
        private readonly ?ButtonPatternContext $buttonContext = null,
        private readonly ?QuotePatternContext $quoteContext = null,
        private readonly ?CodeWindowPatternContext $codeWindowContext = null,
        private readonly ?LogoPatternContext $logoContext = null,
        private readonly ?ImageLowering $images = null,
        private readonly ?SourceElementVisibility $visibility = null,
        private readonly ?DisclosureControlPresentation $disclosure = null,
        private readonly ?StyleResolver $styles = null,
        private readonly ?HtmlTransformerSession $session = null,
        private readonly ?Closure $richTextContent = null
    ) {
    }

    /**
     * Marker for an accordion trigger whose presentation core cannot save.
     *
     * core/accordion-heading saves a bare `<button>`, so a source trigger's box —
     * most visibly the vertical padding that sets every row's height — has
     * nowhere to live on the block. The owning transformer registers the
     * resolved presentation and returns an opaque marker class for the heading
     * to carry, plus any source-proved SVG icon the theme renders into core's
     * icon slot.
     *
     * @return array{className: string, iconSvg: string}
     */
    public function accordionToggle(DOMElement $control, ?DOMElement $row = null): array
    {
        return $this->disclosure?->accordionToggle($control, $row) ?? array('className' => '', 'iconSvg' => '');
    }

    /**
     * The source item's resolved padding and border declarations, which
     * core/accordion-item carries as its own style attributes.
     *
     * @return array<string, string>
     */
    public function accordionItemBox(DOMElement $item): array
    {
        return $this->disclosure?->accordionItemBox($item) ?? array();
    }

    /**
     * Preserve source control typography when core renders it as a heading.
     *
     * @return array<string, mixed>
     */
    public function accordionHeadingTypography(DOMElement $control): array
    {
        if ( null === $this->styles ) {
            return array();
        }
        $declarations = array();
        foreach ( array('font-family', 'font-size', 'font-weight', 'line-height', 'font-style', 'letter-spacing', 'text-transform') as $property ) {
            $value = $this->styles->authoredInheritedPropertyWinner($control, $property);
            if ( '' !== $value ) {
                $declarations[$property] = $value;
            }
        }
        $mapped = $this->styles->styleAttributeMapper()->map($declarations);

        return $mapped['style'] ?? array();
    }

    /**
     * Marker for a disclosure toggle whose presentation core cannot save.
     *
     * core/details renders its own bare `<summary>`, so a source toggle's box —
     * its paint, radius, and typography — has nowhere to live on the block. The
     * owning transformer registers the resolved presentation and returns an
     * opaque marker class for the details block to carry.
     */
    public function disclosureSummaryMarker(DOMElement $summary): string
    {
        return $this->disclosure?->disclosureSummaryMarker($summary) ?? '';
    }

    /**
     * @param array<int, string> $excludedGeometryProperties
     * @return array<string, mixed>
     */
    public function presentationAttributes(DOMElement $element, array $excludedGeometryProperties = array()): array
    {
        return $this->presentation->presentationAttributes($element, $excludedGeometryProperties);
    }

    public function richTextContent(DOMElement $element): string
    {
        return null === $this->richTextContent ? SourceDom::innerHtml($element) : ($this->richTextContent)($element);
    }

    public function recursiveConverter(): ?PatternTreeConverter { return $this->recursiveConverter; }
    public function navigationContext(): ?NavigationPatternContext { return $this->navigationContext; }
    public function mediaContext(): ?MediaPatternContext { return $this->mediaContext; }
    public function columnsContext(): ?ColumnsPatternContext { return $this->columnsContext; }
    public function markupContext(): ?MarkupPatternContext { return $this->markupContext; }
    public function buttonContext(): ?ButtonPatternContext { return $this->buttonContext; }
    public function quoteContext(): ?QuotePatternContext { return $this->quoteContext; }
    public function codeWindowContext(): ?CodeWindowPatternContext { return $this->codeWindowContext; }
    public function logoContext(): ?LogoPatternContext { return $this->logoContext; }
    public function images(): ?ImageLowering { return $this->images; }
    public function sourceElementStartsHidden(DOMElement $element): bool
    {
        if ( null !== $this->visibility ) {
            return $element->hasAttribute('hidden')
                || SourceDom::isAriaHidden($element)
                || $this->visibility->startsHidden($element);
        }

        return $element->hasAttribute('hidden')
            || SourceDom::isAriaHidden($element)
            || CssValueInspector::hidesBox(SourceDeclarations::inline($element));
    }

    /** The source box is clipped to nothing, as a screen-reader-only label is. */
    public function sourceElementVisuallyClipped(DOMElement $element): bool
    {
        return $this->styles?->isVisuallyClippedBox($element) ?? false;
    }

    /** @return array<string, mixed> Resolved anchor paint mapped to native color supports. */
    public function socialAnchorColors(DOMElement $element): array
    {
        if ( null === $this->styles ) {
            return array();
        }
        $declarations = $this->styles->resolvedPresentationDeclarations($element);
        if ( '' === trim($declarations['color'] ?? '') ) {
            $declarations['color'] = $this->styles->authoredInheritedPropertyWinner($element, 'color');
        }
        $declarations['color'] = trim((string) preg_replace('/\s*!important\s*$/i', '', $declarations['color']));
        $mapped = (new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleAttributeMapper())->map(
            array_intersect_key($declarations, array_flip(array('color', 'background', 'background-color'))),
            fn (string $value): string => $this->styles->customProperties->resolve($value, $element)
        );
        return array_merge($mapped['attrs'] ?? array(), array('style' => $mapped['style'] ?? array()));
    }

    /** @return array<string, string> Resolved source CSS for social row layout and sizing. */
    public function socialPresentationDeclarations(DOMElement $element): array
    {
        return $this->styles?->resolvedPresentationDeclarations($element) ?? array();
    }

    public function authoredIconColor(DOMElement $element): string
    {
        return $this->styles?->authoredInheritedPropertyWinner($element, 'color') ?? '';
    }

    /** @return array<string, mixed> Materialized metadata for an asset URL (mime_type, path, glyph_color, ...). */
    public function assetMetadata(string $url): array
    {
        return $this->session?->assetMaterializationState()->metadataForUrl($url) ?? array();
    }

    /**
     * Report that an icon glyph's colour was lost because the host image
     * decoder cannot read its format (for example AVIF without libavif).
     */
    public function glyphColorUndecodable(string $mimeType, string $src): void
    {
        if ( null !== $this->session ) {
            $this->session->transformationEvidenceState()->recordGlyphColorUndecodable($mimeType, $src, $this->session->sourcePath());
        }
    }

    /**
     * Rendered colour capture recorded on a glyph element as
     * `data-blocks-engine-capture-glyph-color` (lowercase `#rrggbb`), or empty when there is none.
     */
    public function capturedGlyphColor(DOMElement $glyph): string
    {
        return $this->session?->capturedGlyphColor($glyph) ?? '';
    }
}
