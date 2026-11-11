<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMElement;

/** In fallback-reduction mode, lowers labelled buttons and phrasing links directly to core/buttons. */
final class ReducedModeButtonConverter implements ElementConverter
{
    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly StyleResolver $styleResolver,
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly Runtime $runtime,
        private readonly SourceBlockCreator $createBlock
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
    {
        if ($this->session->usesFallbackReductionMode() && ( 'button' === $tagName || ( 'a' === $tagName && ! $this->sourceElementClassifier->hasBlockLevelDescendant($element) && '' === trim(SourceDom::attr($element, 'aria-label')) ) )) {
            $text = $this->styleResolver->innerHtml($element);
            if ($this->runtime->hasVisibleText($text)) {
                $attrs = array_merge($this->styleResolver->presentationAttributes($element), array('text' => $text));
                if ('a' === $tagName && '' !== trim(SourceDom::attr($element, 'href'))) {
                    $attrs['url'] = SourceDom::attr($element, 'href');
                }
                return ConversionOutcome::handled($this->createBlock->createBlock('core/buttons', array(), array($this->createBlock->createBlock('core/button', $attrs, array(), $element)), $element));
            }
        }

        return ConversionOutcome::unhandled();
    }
}
