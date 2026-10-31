<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Closure;
use DOMElement;

final class PatternContext
{
    /**
     * @param Closure(DOMElement, array<int, string>): array<string, mixed> $presentationAttributes
     * @param PatternTreeConverter|null $recursiveConverter
     * @param NavigationPatternContext|null $navigationContext
     * @param MediaPatternContext|null $mediaContext
     * @param ColumnsPatternContext|null $columnsContext
     * @param MarkupPatternContext|null $markupContext
     * @param ButtonPatternContext|null $buttonContext
     * @param QuotePatternContext|null $quoteContext
     * @param CodeWindowPatternContext|null $codeWindowContext
     * @param LogoPatternContext|null $logoContext
     * @param GalleryPatternContext|null $galleryContext
     * @param Closure(DOMElement): bool|null $sourceElementStartsHidden
     * @param Closure(DOMElement): string|null $disclosureSummaryMarker
     * @param Closure(DOMElement): string|null $authoredIconColor
     * @param Closure(string): array<string, mixed>|null $assetMetadata
     * @param Closure(string, string): void|null $glyphColorUndecodable
     * @param Closure(DOMElement): string|null $capturedGlyphColor
     * @param Closure(DOMElement): array<string, string>|null $accordionItemBox
     * @param Closure(DOMElement): bool|null $sourceElementVisuallyClipped
     * @param Closure(DOMElement): string|null $richTextContent
     */
    public function __construct(
        private readonly Closure $presentationAttributes,
        private readonly SourceBlockCreator $createBlock,
        private readonly ?PatternTreeConverter $recursiveConverter = null,
        private readonly ?NavigationPatternContext $navigationContext = null,
        private readonly ?MediaPatternContext $mediaContext = null,
        private readonly ?ColumnsPatternContext $columnsContext = null,
        private readonly ?MarkupPatternContext $markupContext = null,
        private readonly ?ButtonPatternContext $buttonContext = null,
        private readonly ?QuotePatternContext $quoteContext = null,
        private readonly ?CodeWindowPatternContext $codeWindowContext = null,
        private readonly ?LogoPatternContext $logoContext = null,
        private readonly ?GalleryPatternContext $galleryContext = null,
        private readonly ?Closure $sourceElementStartsHidden = null,
        private readonly ?Closure $disclosureSummaryMarker = null,
        private readonly ?Closure $accordionToggle = null,
        private readonly ?Closure $authoredIconColor = null,
        private readonly ?Closure $assetMetadata = null,
        private readonly ?Closure $glyphColorUndecodable = null,
        private readonly ?Closure $capturedGlyphColor = null,
        private readonly ?Closure $accordionItemBox = null,
        private readonly ?Closure $sourceElementVisuallyClipped = null,
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
        return null === $this->accordionToggle ? array('className' => '', 'iconSvg' => '') : ($this->accordionToggle)($control, $row);
    }

    /**
     * The source item's resolved padding and border declarations, which
     * core/accordion-item carries as its own style attributes.
     *
     * @return array<string, string>
     */
    public function accordionItemBox(DOMElement $item): array
    {
        return null === $this->accordionItemBox ? array() : ($this->accordionItemBox)($item);
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
        return null === $this->disclosureSummaryMarker ? '' : ($this->disclosureSummaryMarker)($summary);
    }

    /**
     * @param array<int, string> $excludedGeometryProperties
     * @return array<string, mixed>
     */
    public function presentationAttributes(DOMElement $element, array $excludedGeometryProperties = array()): array
    {
        return ($this->presentationAttributes)($element, $excludedGeometryProperties);
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<int, array<string, mixed>> $innerBlocks
     * @return array<string, mixed>
     */
    public function createBlock(string $name, array $attributes = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null, ?DOMElement $logicalSourceElement = null): array
    {
        return $this->createBlock->createBlock($name, $attributes, $innerBlocks, $sourceElement, $logicalSourceElement);
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
    public function galleryContext(): ?GalleryPatternContext { return $this->galleryContext; }
    public function sourceElementStartsHidden(DOMElement $element): bool
    {
        if ( null !== $this->sourceElementStartsHidden ) {
            return ($this->sourceElementStartsHidden)($element);
        }

        return $element->hasAttribute('hidden')
            || 'true' === strtolower(trim($element->getAttribute('aria-hidden')))
            || 1 === preg_match('/(?:^|;)\s*(?:display\s*:\s*none|visibility\s*:\s*hidden)(?:\s*!important)?\s*(?:;|$)/i', $element->getAttribute('style'));
    }

    /** The source box is clipped to nothing, as a screen-reader-only label is. */
    public function sourceElementVisuallyClipped(DOMElement $element): bool
    {
        return null !== $this->sourceElementVisuallyClipped && ($this->sourceElementVisuallyClipped)($element);
    }

    public function authoredIconColor(DOMElement $element): string
    {
        return null === $this->authoredIconColor ? '' : (string) ($this->authoredIconColor)($element);
    }

    /** @return array<string, mixed> Materialized metadata for an asset URL (mime_type, path, glyph_color, ...). */
    public function assetMetadata(string $url): array
    {
        return null === $this->assetMetadata ? array() : ($this->assetMetadata)($url);
    }

    /**
     * Report that an icon glyph's colour was lost because the host image
     * decoder cannot read its format (for example AVIF without libavif).
     */
    public function glyphColorUndecodable(string $mimeType, string $src): void
    {
        if ( null !== $this->glyphColorUndecodable ) {
            ($this->glyphColorUndecodable)($mimeType, $src);
        }
    }

    /**
     * Rendered colour capture recorded on a glyph element as
     * `data-blocks-engine-capture-glyph-color` (lowercase `#rrggbb`), or empty when there is none.
     */
    public function capturedGlyphColor(DOMElement $glyph): string
    {
        return null === $this->capturedGlyphColor ? '' : (string) ($this->capturedGlyphColor)($glyph);
    }
}
