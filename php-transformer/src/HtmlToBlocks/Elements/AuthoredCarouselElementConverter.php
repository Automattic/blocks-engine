<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredCarouselBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternTreeConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use DOMElement;

/** Lowers authored carousels, keeping a static single-slide carousel's whole light DOM. */
final class AuthoredCarouselElementConverter implements ElementConverter
{
    public function __construct(
        private readonly AuthoredCarouselBlockGenerator $carousels,
        private readonly RuntimeIslandAnalyzer $runtimeIslands,
        private readonly StyleResolver $styleResolver,
        private readonly PatternTreeConverter $tree,
        private readonly SourceBlockCreator $createBlock
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
    {
        // Autoplay cannot change a single slide. Keep its complete light DOM
        // (including responsive images and links), not the image-only slide path.
        if ( $this->carousels->isStaticSingleSlideCarousel($element)
            && $this->runtimeIslands->isSafeTransparentCustomElement($element, true)
        ) {
            return ConversionOutcome::handled($this->createBlock->createBlock('core/group', $this->styleResolver->presentationAttributes($element), $this->tree->children($element, $fallbacks, $captureUnsupported), $element));
        }

        $carousel = $this->carousels->convert($element);

        return null === $carousel ? ConversionOutcome::unhandled() : ConversionOutcome::handled($carousel);
    }
}
