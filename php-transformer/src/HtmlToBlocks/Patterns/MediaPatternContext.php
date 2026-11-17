<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\ImageDimensionResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\ImageUrlResolver;
use DOMElement;

final class MediaPatternContext
{
    public function __construct(
        private readonly StyleResolver $styleResolver,
        private readonly ImageUrlResolver $imageUrls,
        private readonly ImageDimensionResolver $imageDimensions,
        private readonly HtmlTransformerSession $session
    ) {
    }

    public function coverStyle(DOMElement $element): string { return $this->styleResolver->coverPresentationStyle($element); }
    /** Pattern gates need class-owned facts too, not only declarations materialized inline. */
    public function fullSourceStyle(DOMElement $element): string
    {
        $style = '';
        foreach ($this->styleResolver->resolvedPresentationDeclarations($element) as $property => $value) {
            $style .= $property . ':' . $value . ';';
        }
        return $style;
    }
    public function resolveImageUrl(string $url): string { return $this->imageUrls->resolvedAssetUrl($url); }
    /** @param array<int, string> $excludedGeometryProperties
     * @return array<string, mixed>
     */
    public function mediaTextAttributes(DOMElement $element, array $excludedGeometryProperties = array()): array
    {
        return array_merge(
            $this->styleResolver->mediaTextPresentationAttributes($element, $excludedGeometryProperties),
            array( 'mediaTextImageMarker' => '' === $this->session->authorStyleAnalysis()->combinedCss()
                ? ''
                : $this->session->authorSelectorProjectionState()->ensureMediaTextImageMarker($element->getNodePath() ?? '') )
        );
    }
    /** Media-text cannot retain authored grid gutters or conditional tracks. */
    public function hasSourceOwnedGridGeometry(DOMElement $element): bool
    {
        $values = $this->styleResolver->authorDeclaredValuesAtAnyViewport($element, array('display', 'grid-template-columns', 'gap', 'column-gap', 'row-gap'));
        $isGrid = isset($values['grid-template-columns'])
            || array() !== array_intersect(array('grid', 'inline-grid'), $values['display'] ?? array());
        if (! $isGrid) {
            return false;
        }
        // Leave breakpoint ownership with the source stylesheet rather than
        // substituting core/media-text's fixed 600px stacking rule.
        if (array() === $this->styleResolver->classOwnedResponsiveDeclarations($element, array('grid-template-columns' => 'none'))) {
            return true;
        }
        foreach (array('gap', 'column-gap', 'row-gap') as $property) {
            foreach ($values[$property] ?? array() as $value) {
                if (! in_array(strtolower(trim($value)), array('0', '0px', 'normal'), true)) {
                    return true;
                }
            }
        }
        return false;
    }
    public function mediaTextStyle(DOMElement $element): string { return $this->styleResolver->mediaTextPresentationStyle($element); }
    // Pattern gates must read the cascade even on otherwise low-value wrappers.
    public function mediaTextFullStyle(DOMElement $element): string { return $this->styleResolver->specificityResolvedPresentationStyle($element); }
    /** Whether a media/container-conditional rule varies the element's min-height. */
    public function hasConditionalMinHeight(DOMElement $element): bool { return array() === $this->styleResolver->classOwnedResponsiveDeclarations($element, array( 'min-height' => '0' )); }
    /** @return array<string, string> */
    public function presentationDeclarations(DOMElement $element): array { return $this->styleResolver->resolvedPresentationDeclarations($element); }
    public function documentRootFontSize(DOMElement $element): ?float { return $this->styleResolver->documentRootFontSize($element); }
    /** @return array{width: ?float, height: ?float} */
    public function imageFixedPixelSize(DOMElement $image): array { return $this->imageDimensions->fixedPixelSize($image); }
}
