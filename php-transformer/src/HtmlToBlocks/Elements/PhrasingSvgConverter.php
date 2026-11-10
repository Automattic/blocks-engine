<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SvgMaterializer;
use Closure;
use DOMElement;

/** Hosts a phrasing-boundary SVG in a synthetic paragraph before generic preservation. */
final class PhrasingSvgConverter implements ElementConverter
{
    public function __construct(
        private readonly SvgMaterializer $svgMaterializer,
        private readonly SourceBlockCreator $createBlock,
        /** @var Closure(DOMElement): bool Inert hidden storage belongs to the SVG converter, which drops it. */
        private readonly Closure $isInertHiddenStorage
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
    {
        if ( 'svg' !== $tagName
            || ($this->isInertHiddenStorage)($element)
            || ! $this->svgMaterializer->svgNeedsPhrasingHost($element) ) {
            return ConversionOutcome::unhandled();
        }

        $imageMarkup = $this->svgMaterializer->inlineSvgRichTextImageMarkup($element);
        if ( null === $imageMarkup || '' === $imageMarkup ) {
            return ConversionOutcome::unhandled();
        }

        return ConversionOutcome::handled($this->createBlock->createBlock('core/paragraph', array(
            'content' => $imageMarkup,
            'className' => SourceBlockAttributeProjector::SYNTHETIC_SVG_PARAGRAPH_CLASS,
        ), array(), $element));
    }
}
