<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceDeclarations;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/** @internal Pattern recognizers are implementation details of HtmlTransformer. */
final class PlaceholderMediaPattern implements PatternRecognizerInterface
{
    public function __construct(
        private readonly \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator $createBlock,
        private readonly \Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime $runtime
    ) {
    }

    use PatternDomHelpersTrait;

    public function recognize(DOMElement $element, PatternContext $context): ?PatternRecognitionResult
    {
        if ( ! $this->isPlaceholderMediaElement($element) ) {
            return null;
        }

        $markup = $context->markupContext();
        if ( null === $markup ) {
            return null;
        }

        $attrs = $context->presentationAttributes($element);
        // The aspect ratio rides on the preserved placeholder/ratio classNames and
        // companion-plugin CSS; a raw inline `style` string would invalidate the
        // core/group block, so it is intentionally not emitted here (#261).
        $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), 'blocks-engine-placeholder-media');
        unset($attrs['style']);

        $label = $this->placeholderLabel($element);
        $children = '' !== $label ? array( $this->createBlock->createBlock('core/paragraph', array( 'content' => $this->runtime->escapeHtml($label) )) ) : array();

        return new PatternRecognitionResult($this->createBlock->createBlock('core/group', array_filter($attrs, static fn ($value): bool => is_array($value) ? array() !== $value : '' !== trim((string) $value)), $children, $element));
    }

    private function isPlaceholderMediaElement(DOMElement $element): bool
    {
        $className = strtolower(SourceDom::attr($element, 'class'));
        if ( ! preg_match('/(?:^|\s)(?:ph|placeholder|media-placeholder|image-placeholder|video-placeholder)(?:\s|$)/', $className) && ! preg_match('/(?:^|\s)ratio-[0-9]+(?:x|:|-)[0-9]+(?:\s|$)/', $className) ) {
            return false;
        }

        return '' !== $this->placeholderAspectRatio($element)
            || isset(SourceDeclarations::inline($element)['aspect-ratio'])
            || preg_match('/(?:^|\s)(?:media|image|video|thumb|thumbnail|poster|avatar)(?:\s|$)/', $className);
    }

    private function placeholderAspectRatio(DOMElement $element): string
    {
        $aspectRatio = SourceDeclarations::inline($element)['aspect-ratio'] ?? '';
        if ( preg_match('/^(?:[0-9.]+\s*\/\s*[0-9.]+|[0-9.]+)$/D', $aspectRatio) ) {
            return preg_replace('/\s+/', '', $aspectRatio) ?? '';
        }

        $className = strtolower(SourceDom::attr($element, 'class'));
        if ( preg_match('/(?:^|\s)ratio-([0-9]+)(?:x|:|-)([0-9]+)(?:\s|$)/', $className, $classMatch) ) {
            return $classMatch[1] . '/' . $classMatch[2];
        }

        return '';
    }

    private function placeholderLabel(DOMElement $element): string
    {
        foreach ( $element->getElementsByTagName('span') as $span ) {
            if ( ! $span instanceof DOMElement ) {
                continue;
            }

            $className = strtolower(SourceDom::attr($span, 'class'));
            if ( preg_match('/(?:^|\s)(?:label|caption|placeholder-label)(?:\s|$)/', $className) ) {
                return trim(preg_replace('/\s+/', ' ', $span->textContent ?? '') ?? '');
            }
        }

        $directText = trim(preg_replace('/\s+/', ' ', $element->textContent ?? '') ?? '');
        return strlen($directText) <= 80 ? $directText : '';
    }
}
