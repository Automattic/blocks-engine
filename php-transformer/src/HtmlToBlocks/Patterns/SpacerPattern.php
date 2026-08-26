<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

/** @internal Pattern recognizers are implementation details of HtmlTransformer. */
final class SpacerPattern implements PatternRecognizerInterface
{
    use PatternDomHelpersTrait;

    public function recognize(DOMElement $element, PatternContext $context): ?PatternRecognitionResult
    {
        if ( '' !== trim($element->textContent ?? '') || 0 !== $this->childElementCount($element) ) {
            return null;
        }

        $height = self::heightFromStyle($this->attr($element, 'style'));
        if ( '' === $height ) {
            return null;
        }

        if ( ! $this->hasClass($element, 'wp-block-spacer') && ! $this->hasClass($element, 'spacer') && ! $this->hasClass($element, 'wsite-spacer') ) {
            return null;
        }

        // core/spacer serializes height itself. Preserve all remaining geometry
        // through the generated stylesheet rather than removing the whole carrier.
        $attrs = $context->presentationAttributes($element, array( 'height' ));
        $attrs['height'] = $height;
        unset($attrs['style']);

        return new PatternRecognitionResult($context->createBlock('core/spacer', $attrs, array(), $element));
    }

    private function childElementCount(DOMElement $element): int
    {
        $count = 0;
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                ++$count;
            }
        }

        return $count;
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
