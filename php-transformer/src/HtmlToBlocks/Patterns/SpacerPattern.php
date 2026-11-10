<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/** @internal Pattern recognizers are implementation details of HtmlTransformer. */
final class SpacerPattern implements PatternRecognizerInterface
{
    public function __construct(
        private readonly \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator $createBlock
    ) {
    }

    use PatternDomHelpersTrait;

    public function recognize(DOMElement $element, PatternContext $context): ?PatternRecognitionResult
    {
        if ( '' !== trim($element->textContent ?? '') || 0 !== SourceDom::childElementCount($element) ) {
            return null;
        }

        $height = self::heightFromStyle(SourceDom::attr($element, 'style'));
        if ( '' === $height ) {
            return null;
        }

        if ( ! SourceDom::hasClass($element, 'wp-block-spacer') && ! SourceDom::hasClass($element, 'spacer') && ! SourceDom::hasClass($element, 'wsite-spacer') ) {
            return null;
        }

        // core/spacer serializes height itself. Preserve all remaining geometry
        // through the generated stylesheet rather than removing the whole carrier.
        $attrs = $context->presentationAttributes($element, array( 'height' ));
        $attrs['height'] = $height;
        unset($attrs['style']);

        return new PatternRecognitionResult($this->createBlock->createBlock('core/spacer', $attrs, array(), $element));
    }
    public static function heightFromStyle(string $style): string
    {
        if ( ! preg_match('/(?:^|;)\s*height\s*:\s*([^;]+)/i', $style, $matches) ) {
            return '';
        }

        $height = trim($matches[1]);
        if ( '' === $height || preg_match('/[{}]/', $height) || strlen($height) > 80 ) {
            return '';
        }

        return $height;
    }
}
