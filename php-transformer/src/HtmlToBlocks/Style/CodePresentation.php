<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

final class CodePresentation
{
    public function __construct(private readonly ElementPresentationResolver $presentation)
    {
    }

    /** @return array<string, mixed> */
    public function attributes(DOMElement $pre, DOMElement $code): array
    {
        $attrs = $this->presentation->presentationAttributes($pre);
        $codeClassName = SourceDom::attr($code, 'class');
        if ( '' !== trim($codeClassName) ) {
            $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), $codeClassName);
        }

        return array_filter($attrs, static fn ($value): bool => is_array($value) ? array() !== $value : '' !== trim((string) $value));
    }

    /**
     * core/code content is a RichText value: always HTML. Plain code text is
     * escaped here, so text that mentions markup (`&lt;span&gt;`) stays text.
     */
    public function content(DOMElement $code): string
    {
        foreach ( $code->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                return $this->sanitizedSyntaxHtml($code);
            }
        }

        return htmlspecialchars($code->textContent ?? '', ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function sanitizedSyntaxHtml(DOMElement $element): string
    {
        $html = '';
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                $html .= htmlspecialchars($child->textContent ?? '', ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
                continue;
            }

            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $tagName = strtolower($child->tagName);
            if ( in_array($tagName, array( 'span', 'mark', 'b', 'strong', 'i', 'em' ), true) ) {
                $attrs = array_intersect_key(SourceDom::htmlAttributes($child), array_flip(array( 'class', 'data-token', 'title' )));
                $attrs = array_filter($attrs, static fn (string $value): bool => '' !== $value && strlen($value) <= 200 && ! preg_match('/javascript\s*:/i', $value));
                $html .= '<' . $tagName . SourceDom::htmlAttributeString($attrs) . '>' . $this->sanitizedSyntaxHtml($child) . '</' . $tagName . '>';
                continue;
            }

            $html .= htmlspecialchars($child->textContent ?? '', ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return $html;
    }
}
