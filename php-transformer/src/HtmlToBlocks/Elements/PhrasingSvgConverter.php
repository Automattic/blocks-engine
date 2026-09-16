<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SvgMaterializer;
use DOMElement;

/** Hosts a phrasing-boundary SVG in a synthetic paragraph before generic preservation. */
final class PhrasingSvgConverter implements ElementConverter
{
    public function __construct(
        private readonly SvgMaterializer $svgMaterializer,
        private readonly SourceBlockCreator $createBlock
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks): ConversionOutcome
    {
        if ( 'svg' !== $tagName || ! $this->svgMaterializer->svgNeedsPhrasingHost($element) ) {
            return ConversionOutcome::unhandled();
        }

        $imageMarkup = $this->svgMaterializer->inlineSvgRichTextImageMarkup($element);
        if ( null === $imageMarkup ) {
            return ConversionOutcome::unhandled();
        }

        return ConversionOutcome::handled($this->createBlock->createBlock('core/paragraph', array(
            'content' => $imageMarkup,
            'className' => SourceBlockAttributeProjector::SYNTHETIC_SVG_PARAGRAPH_CLASS,
        ), array(), $element));
    }
}
