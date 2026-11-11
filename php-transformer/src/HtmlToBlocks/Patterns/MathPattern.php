<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/** @internal Pattern recognizers are implementation details of HtmlTransformer. */
final class MathPattern implements PatternRecognizerInterface
{
    public function __construct(
        private readonly \Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime $runtime,
        private readonly \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator $createBlock
    ) {
    }

    use PatternDomHelpersTrait;

    public function recognize(DOMElement $element, PatternContext $context): ?PatternRecognitionResult
    {
        $markup = $context->markupContext();
        if ( null === $markup || ! $this->isMathElement($element) ) {
            return null;
        }

        $tagName = strtolower($element->tagName);
        $content = 'math' === $tagName ? $markup->safeFallbackHtml($element) : $this->mathExpressionContent($element, SourceDom::innerHtml(...), $this->runtime->escapeHtml(...));
        if ( '' === trim($content) ) {
            return null;
        }

        return new PatternRecognitionResult($this->createBlock->createBlock('core/math', array_merge($context->presentationAttributes($element), array( 'content' => $content )), array(), $element));
    }

    private function isMathElement(DOMElement $element): bool
    {
        if ( 'math' === strtolower($element->tagName) ) {
            return true;
        }

        // Headings retain their semantics, including when they contain inline math.
        if ( in_array(strtolower($element->tagName), array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true) ) {
            return false;
        }

        if ( $this->hasMathSignal($element) ) {
            return true;
        }

        return in_array(strtolower($element->tagName), array( 'div', 'p', 'span' ), true) && $this->isTeXDelimitedText(trim($element->textContent ?? ''));
    }

    private function hasMathSignal(DOMElement $element): bool
    {
        foreach ( array( 'data-math', 'data-latex', 'data-tex' ) as $attribute ) {
            if ( $element->hasAttribute($attribute) ) {
                return true;
            }
        }

        // IDs are authored anchors; only complete class tokens signal math.
        $classes = preg_split('/\s+/', strtolower(trim(SourceDom::attr($element, 'class')))) ?: array();
        return array() !== array_intersect($classes, array( 'math', 'katex', 'katex-display', 'katex-html', 'mathjax', 'mjx-container', 'latex', 'tex', 'arithmatex' ));
    }

    /**
     * @param callable(DOMElement): string $innerHtml
     * @param callable(string): string $escapeHtml
     */
    private function mathExpressionContent(DOMElement $element, callable $innerHtml, callable $escapeHtml): string
    {
        $html = $innerHtml($element);
        if ( '' !== trim($html) && ! preg_match('/<(?:script|style)\b/i', $html) ) {
            return $html;
        }

        return $escapeHtml(trim($element->textContent ?? ''));
    }

    private function isTeXDelimitedText(string $text): bool
    {
        if ( str_starts_with($text, '$$') && str_ends_with($text, '$$') && 4 < strlen($text) ) {
            return true;
        }
        if ( str_starts_with($text, '$') && str_ends_with($text, '$') && 2 < strlen($text) && ! str_starts_with($text, '$$') ) {
            return true;
        }

        return ( str_starts_with($text, '\\(') && str_ends_with($text, '\\)') && 4 < strlen($text) )
            || ( str_starts_with($text, '\\[') && str_ends_with($text, '\\]') && 4 < strlen($text) );
    }
}
