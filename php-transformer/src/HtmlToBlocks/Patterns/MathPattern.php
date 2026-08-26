<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

/** @internal Pattern recognizers are implementation details of HtmlTransformer. */
final class MathPattern implements PatternRecognizerInterface
{
    use PatternDomHelpersTrait;

    public function recognize(DOMElement $element, PatternContext $context): ?PatternRecognitionResult
    {
        $safeFallbackHtml = $context->safeFallbackHtmlCallback();
        $escapeHtml = $context->escapeHtmlCallback();
        if ( null === $safeFallbackHtml || null === $escapeHtml || ! $this->isMathElement($element) ) {
            return null;
        }

        $tagName = strtolower($element->tagName);
        $content = 'math' === $tagName ? $safeFallbackHtml($element) : $this->mathExpressionContent($element, $context->innerHtml(...), $escapeHtml);
        if ( '' === trim($content) ) {
            return null;
        }

        return new PatternRecognitionResult($context->createBlock('core/math', array_merge($context->presentationAttributes($element), array( 'content' => $content )), array(), $element));
    }

    private function isMathElement(DOMElement $element): bool
    {
        if ( 'math' === strtolower($element->tagName) ) {
            return true;
        }

        if ( $this->hasMathSignal($element) ) {
            return true;
        }

        return in_array(strtolower($element->tagName), array( 'div', 'p', 'span' ), true) && $this->isTeXDelimitedText(trim($element->textContent ?? ''));
    }

    private function hasMathSignal(DOMElement $element): bool
    {
        $signals = strtolower(trim(implode(' ', array(
            $this->attr($element, 'class'),
            $this->attr($element, 'id'),
            $this->attr($element, 'data-math'),
            $this->attr($element, 'data-latex'),
            $this->attr($element, 'data-tex'),
        ))));

        return (bool) preg_match('/(?:^|[\s_-])(?:math|latex|tex|katex|mathjax)(?:$|[\s_-])/', $signals);
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
