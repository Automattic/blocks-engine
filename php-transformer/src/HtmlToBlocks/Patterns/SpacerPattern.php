<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

final class SpacerPattern implements PatternRecognizerInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function match(DOMElement $element, PatternContext $context): ?array
    {
        if ( '' !== trim($element->textContent ?? '') || 0 !== $this->childElementCount($element) ) {
            return null;
        }

        $height = $this->spacerHeightFromStyle($this->attr($element, 'style'));
        if ( '' === $height ) {
            return null;
        }

        if ( ! $this->hasClass($element, 'wp-block-spacer') && ! $this->hasClass($element, 'spacer') ) {
            return null;
        }

        $presentationAttributes = $context->presentationAttributesCallback();
        $createBlock = $context->createBlockCallback();

        $attrs = $presentationAttributes($element);
        $attrs['height'] = $height;
        unset($attrs['style']);

        return $createBlock('core/spacer', $attrs, array(), $element);
    }

    private function spacerHeightFromStyle(string $style): string
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

    private function attr(DOMElement $element, string $name): string
    {
        return $element->hasAttribute($name) ? $element->getAttribute($name) : '';
    }

    private function hasClass(DOMElement $element, string $className): bool
    {
        return in_array($className, preg_split('/\s+/', trim($this->attr($element, 'class'))) ?: array(), true);
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
}
