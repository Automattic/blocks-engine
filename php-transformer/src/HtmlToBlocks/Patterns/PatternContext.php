<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
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
        private readonly ?Closure $disclosureSummaryMarker = null
    ) {
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
}
