<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

final class LogoPattern
{
    /**
     * @param callable(DOMElement): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $innerHtml
     * @param callable(DOMElement): string $outerHtml
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null): array<string, mixed> $createBlock
     * @return array<string, mixed>|null
     */
    public function match(DOMElement $element, callable $presentationAttributes, callable $innerHtml, callable $outerHtml, callable $createBlock): ?array
    {
        if ( ! $this->hasLogoSignal($element) || '' === trim($element->textContent ?? '') ) {
            return null;
        }

        $tagName = strtolower($element->tagName);
        if ( 'a' !== $tagName && $this->containsBlockContent($element) ) {
            return null;
        }

        $content = 'a' === $tagName ? $outerHtml($element) : $innerHtml($element);
        if ( '' === trim($content) ) {
            return null;
        }

        return $createBlock('core/paragraph', array_merge($presentationAttributes($element), array( 'content' => $content )), array(), $element);
    }

    private function hasLogoSignal(DOMElement $element): bool
    {
        foreach ( array( 'class', 'id' ) as $attribute ) {
            $value = $element->hasAttribute($attribute) ? $element->getAttribute($attribute) : '';
            foreach ( preg_split('/[^a-z0-9]+/', strtolower($value)) ?: array() as $token ) {
                if ( in_array($token, array( 'logo', 'brand', 'branding' ), true) ) {
                    return true;
                }
            }

            if ( preg_match('/(?:^|[^a-z0-9])site-(?:logo|title)(?:[^a-z0-9]|$)/i', $value) ) {
                return true;
            }
        }

        return false;
    }

    private function containsBlockContent(DOMElement $element): bool
    {
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            if ( $this->isBlockContentTag(strtolower($child->tagName)) ) {
                return true;
            }

            if ( $this->containsBlockContent($child) ) {
                return true;
            }
        }

        return false;
    }

    private function isBlockContentTag(string $tagName): bool
    {
        return in_array($tagName, array(
            'address',
            'article',
            'aside',
            'blockquote',
            'details',
            'div',
            'dl',
            'fieldset',
            'figcaption',
            'figure',
            'footer',
            'form',
            'h1',
            'h2',
            'h3',
            'h4',
            'h5',
            'h6',
            'header',
            'hr',
            'li',
            'main',
            'nav',
            'ol',
            'p',
            'pre',
            'section',
            'table',
            'ul',
        ), true);
    }
}
