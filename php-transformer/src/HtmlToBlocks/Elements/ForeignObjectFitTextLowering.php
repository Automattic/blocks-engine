<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\BlockFactory;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use DOMElement;

/** Lowers an SVG whose foreignObject holds one fit-to-box text element. */
final class ForeignObjectFitTextLowering
{
    public function __construct(
        private readonly SourceBlockCreator $blocks,
        private readonly RichTextElementConverter $richTextConverter,
        private readonly StyleResolver $styleResolver,
        private readonly BlockFactory $blockFactory
    ) {
    }

    /**
     * Lower a foreignObject-only "fit text" SVG to the native text block its
     * inner element renders as (`<svg viewBox="..."><foreignObject width="100%"
     * height="100%"><p>Label</p></foreignObject></svg>`). The SVG is a sizing
     * box that paints nothing and survives sanitization as an empty graphic, so
     * it used to fall back as metadata that rendered nothing. The inner p,
     * heading, or phrasing-only div/span converts through the ordinary
     * rich-text path inside a core/group that carries the SVG's own class and
     * presentation: author rules target the text as a descendant of that class
     * (`.fit p`), so merging the class onto the text block itself would sever
     * every one of them. The group keeps the source element relationship — the
     * authored `.fit` sizing applies to the box fit text measures and `.fit p`
     * still reaches the text. Core's fit-text support (`fitText: true`) re-fits
     * the line to that container. The emitted markup must reproduce core's
     * canonical serialization exactly: the `className` attribute stays clean and
     * `has-fit-text` lands only in the saved element's class list — core derives
     * it in `save()` whenever `fitText` is true (BlockFactory's supports-derived
     * class path); a saved markup without it fails the editor's validation and a
     * paragraph deprecation silently migrates the block, dropping `fitText`.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    public function block(DOMElement $svg, array &$fallbacks): ?array
    {
        $text = SourceDom::foreignObjectSoleTextElement($svg);
        if ( null === $text ) {
            return null;
        }
        $tag = strtolower($text->tagName);
        $outcome = $this->richTextConverter->convert($text, in_array($tag, array( 'p', 'div', 'span' ), true) ? 'p' : $tag, $fallbacks);
        $block = $outcome->block;
        if ( null === $block || ! in_array($block['blockName'] ?? '', array( 'core/paragraph', 'core/heading' ), true) ) {
            return null;
        }

        $attrs = $block['attrs'];
        $attrs['fitText'] = true;

        return $this->blocks->createBlock('core/group', $this->styleResolver->presentationAttributes($svg), array($this->blockFactory->rebuild($block, $attrs)), $svg);
    }
}
