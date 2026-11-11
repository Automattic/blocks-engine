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
    public function mediaTextStyle(DOMElement $element): string { return $this->styleResolver->mediaTextPresentationStyle($element); }
    /** Whether a media/container-conditional rule varies the element's min-height. */
    public function hasConditionalMinHeight(DOMElement $element): bool { return array() === $this->styleResolver->classOwnedResponsiveDeclarations($element, array( 'min-height' => '0' )); }
    /** @return array<string, string> */
    public function presentationDeclarations(DOMElement $element): array { return $this->styleResolver->resolvedPresentationDeclarations($element); }
    public function documentRootFontSize(DOMElement $element): ?float { return $this->styleResolver->documentRootFontSize($element); }
    /** @return array{width: ?float, height: ?float} */
    public function imageFixedPixelSize(DOMElement $image): array { return $this->imageDimensions->fixedPixelSize($image); }
}
