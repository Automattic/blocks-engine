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

        if ( 'a' === $tagName && $this->hasStructuredAnchorChrome($element) ) {
            return $createBlock('core/html', array( 'content' => $outerHtml($element) ), array(), $element);
        }

        $content = 'a' === $tagName ? $this->anchorLogoContent($element, $innerHtml($element)) : $this->logoLabelHtml($innerHtml($element));
        if ( '' === trim($content) ) {
            return null;
        }

        return $createBlock('core/paragraph', array_merge($presentationAttributes($element), array( 'content' => $content )), array(), $element);
    }

    private function anchorLogoContent(DOMElement $anchor, string $html): string
    {
        $label = $this->logoLabelHtml($html);
        if ( '' === trim($this->plainText($label)) ) {
            $label = $this->accessibleFallbackLabel($anchor);
        }

        if ( '' === trim($label) ) {
            return '';
        }

        $href = $this->safeNavigationUrl($anchor->hasAttribute('href') ? $anchor->getAttribute('href') : '');
        if ( '' === $href ) {
            return $label;
        }

        $attrs = array( 'href' => $href );
        foreach ( array( 'target', 'rel', 'title' ) as $name ) {
            if ( $anchor->hasAttribute($name) && '' !== trim($anchor->getAttribute($name)) ) {
                $attrs[$name] = $anchor->getAttribute($name);
            }
        }

        return '<a' . $this->htmlAttributeString($attrs) . '>' . $label . '</a>';
    }

    private function logoLabelHtml(string $html): string
    {
        $html = preg_replace('/<svg\b[^>]*>.*?<\/svg>/is', '', $html) ?? $html;
        $html = preg_replace('/<img\b[^>]*\balt\s*=\s*(["\'])(.*?)\1[^>]*>/is', '$2', $html) ?? $html;
        $html = preg_replace('/<img\b[^>]*>/is', '', $html) ?? $html;
        $html = preg_replace('/<([a-z][a-z0-9]*)\b[^>]*\baria-hidden\s*=\s*(["\'])?true\2[^>]*>\s*<\/\1>/i', '', $html) ?? $html;
        $html = trim($html);
        $text = $this->plainText($html);
        if ( '' === $text ) {
            return '';
        }

        if ( preg_match('/<\/?(?!em\b|i\b|strong\b|b\b|mark\b|small\b|sub\b|sup\b|br\b)[a-z][a-z0-9]*\b[^>]*>/i', $html) ) {
            $flattened = $this->plainText(preg_replace('/<\/?[a-z][a-z0-9]*\b[^>]*>/i', ' ', $html) ?? $html);
            return htmlspecialchars('' !== $flattened ? $flattened : $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return $html;
    }

    private function unwrapPresentationalSpan(string $html): string
    {
        while ( preg_match('/^<span\b[^>]*>(.*)<\/span>$/is', $html, $matches) === 1 && $this->spanWrapsEntireContent($matches[1]) ) {
            $html = trim($matches[1]);
        }

        return $html;
    }

    private function spanWrapsEntireContent(string $inner): bool
    {
        $depth = 0;
        if ( preg_match_all('/<(\/?)span\b[^>]*>/i', $inner, $matches) ) {
            foreach ( $matches[1] as $slash ) {
                $depth += '' === $slash ? 1 : -1;
                if ( $depth < 0 ) {
                    return false;
                }
            }
        }

        return 0 === $depth;
    }

    private function plainText(string $html): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    private function accessibleFallbackLabel(DOMElement $element): string
    {
        foreach ( array( 'aria-label', 'title' ) as $attribute ) {
            $label = trim($element->hasAttribute($attribute) ? $element->getAttribute($attribute) : '');
            if ( '' !== $label ) {
                return htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }

        $image = $element->getElementsByTagName('img')->item(0);
        if ( $image instanceof DOMElement ) {
            $alt = trim($image->hasAttribute('alt') ? $image->getAttribute('alt') : '');
            if ( '' !== $alt ) {
                return htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }

        $title = $element->getElementsByTagName('title')->item(0);
        if ( $title instanceof DOMElement && '' !== trim($title->textContent ?? '') ) {
            return htmlspecialchars(trim($title->textContent ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return '';
    }

    private function safeNavigationUrl(string $url): string
    {
        $url = trim($url);
        if ( '' === $url || preg_match('/[\x00-\x1f\x7f]|javascript\s*:/i', $url) ) {
            return '';
        }

        return $url;
    }

    /**
     * @param array<string, string> $attrs
     */
    private function htmlAttributeString(array $attrs): string
    {
        $html = '';
        foreach ( $attrs as $name => $value ) {
            $html .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        return $html;
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

    private function hasStructuredAnchorChrome(DOMElement $anchor): bool
    {
        if ( '' === trim($anchor->getAttribute('class')) ) {
            return false;
        }

        foreach ( $anchor->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            if ( '' !== trim($child->getAttribute('class')) || $this->descendantHasClassedInlineChrome($child) ) {
                return true;
            }
        }

        return false;
    }

    private function descendantHasClassedInlineChrome(DOMElement $element): bool
    {
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            if ( '' !== trim($child->getAttribute('class')) ) {
                return true;
            }

            if ( $this->descendantHasClassedInlineChrome($child) ) {
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
