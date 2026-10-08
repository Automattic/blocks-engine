<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Contract;

/**
 * The one definition of which inline tags block RichText content may carry.
 *
 * `EditabilityReport` counts any other tag inside a RichText attribute as
 * structural HTML, and the HTML-to-blocks lowering normalizes unknown inline
 * elements so its own output always passes that gate.
 */
final class RichTextInlineTags
{
    /** Inline tags a RichText attribute may carry. */
    public const ALLOWED = array('a', 'abbr', 'b', 'br', 'cite', 'code', 'del', 'em', 'i', 'img', 'ins', 'kbd', 'mark', 's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var');

    /**
     * Standard HTML element names, including obsolete ones parsers still
     * recognize. Anything else is an unknown or custom element.
     */
    private const HTML_ELEMENTS = array(
        'a', 'abbr', 'acronym', 'address', 'applet', 'area', 'article', 'aside', 'audio', 'b', 'base', 'basefont', 'bdi', 'bdo', 'big', 'blink', 'blockquote', 'body', 'br', 'button',
        'canvas', 'caption', 'center', 'cite', 'code', 'col', 'colgroup', 'data', 'datalist', 'dd', 'del', 'details', 'dfn', 'dialog', 'dir', 'div', 'dl', 'dt', 'em', 'embed',
        'fieldset', 'figcaption', 'figure', 'font', 'footer', 'form', 'frame', 'frameset', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'head', 'header', 'hgroup', 'hr', 'html',
        'i', 'iframe', 'img', 'input', 'ins', 'isindex', 'kbd', 'keygen', 'label', 'legend', 'li', 'link', 'listing', 'main', 'map', 'mark', 'marquee', 'math', 'menu', 'menuitem', 'meta', 'meter',
        'nav', 'nobr', 'noembed', 'noframes', 'noscript', 'object', 'ol', 'optgroup', 'option', 'output', 'p', 'param', 'picture', 'plaintext', 'pre', 'progress', 'q', 'rb', 'rp', 'rt', 'rtc', 'ruby',
        's', 'samp', 'script', 'search', 'section', 'select', 'slot', 'small', 'source', 'span', 'strike', 'strong', 'style', 'sub', 'summary', 'sup', 'svg', 'table', 'tbody', 'td', 'template', 'textarea',
        'tfoot', 'th', 'thead', 'time', 'title', 'tr', 'track', 'tt', 'u', 'ul', 'var', 'video', 'wbr', 'xmp',
    );

    public static function isAllowed(string $tagName): bool
    {
        return in_array(strtolower($tagName), self::ALLOWED, true);
    }

    /**
     * Whether a tag is not a standard HTML element: a custom element
     * (`x-note`), a namespaced one, or an unknown name (`bdt`). Browsers lay
     * such an element out inline and give it no default rendering.
     */
    public static function isUnknownElement(string $tagName): bool
    {
        return '' !== $tagName && ! in_array(strtolower($tagName), self::HTML_ELEMENTS, true);
    }

    /**
     * Whether an element is an unknown or custom HTML element. SVG and MathML
     * children carry their own vocabulary and are never unknown here.
     */
    public static function isUnknownHtmlElement(\DOMElement $element): bool
    {
        if ( ! self::isUnknownElement($element->tagName) ) {
            return false;
        }
        for ( $parent = $element->parentNode; $parent instanceof \DOMElement; $parent = $parent->parentNode ) {
            if ( in_array(strtolower($parent->tagName), array( 'svg', 'math' ), true) ) {
                return false;
            }
        }

        return true;
    }

    /** Whether an HTML fragment contains an unknown or custom element tag. */
    public static function containsUnknownElement(string $html): bool
    {
        if ( ! preg_match_all('/<([a-z][a-z0-9:-]*)\b/i', $html, $matches) ) {
            return false;
        }
        foreach ( $matches[1] as $tagName ) {
            if ( self::isUnknownElement((string) $tagName) ) {
                return true;
            }
        }

        return false;
    }
}
