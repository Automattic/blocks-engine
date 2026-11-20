<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Support;

/** Source document selector identity shared by compilation and site planning. */
final class DocumentRootAttributes
{
    /** @return array<string,string> */
    public static function fromHtml(string $html, string $tagName = 'html'): array
    {
        return self::forDocument($html)[$tagName] ?? array();
    }

    /**
     * Re-parent a body parsed on its own under an `<html>` carrying the source root attributes, so `:root` and `html` rules match the root rather than the body.
     * The body regains its named selector attributes too, so rules scoped by them (`#site .page`) match as they do on the rendered body.
     * Its `data-*` and `aria-*` predicates stay with the projection that owns root data state.
     */
    public static function restoreRoot(\DOMElement $body, string $html): void
    {
        $attributes = self::forDocument($html);
        foreach ( array_intersect_key($attributes['body'], array_flip(SourceAttribute::SELECTOR_NAMES)) as $name => $value ) {
            if ( ! $body->hasAttribute($name) ) $body->setAttribute($name, $value);
        }
        $root = $body->ownerDocument->createElement('html');
        foreach ( $attributes['html'] as $name => $value ) {
            $root->setAttribute($name, $value);
        }
        $body->ownerDocument->replaceChild($root, $body);
        $root->appendChild($body);
    }

    /** @return array{html:array<string,string>,body:array<string,string>} */
    public static function forDocument(string $html): array
    {
        $attributes = array('html' => array(), 'body' => array());
        if ('' === trim($html)) return $attributes;
        $document = HtmlSerialization::parseDocument($html);
        foreach (array('html', 'body') as $tagName) {
            $root = $document->getElementsByTagName($tagName)->item(0);
            foreach ($root?->attributes ?? array() as $attribute) {
                if (!self::isSelectorAttribute($attribute->name)) continue;
                $attributes[$tagName][$attribute->name] = $attribute->value;
            }
        }
        return $attributes;
    }

    public static function isSelectorAttribute(string $name): bool
    {
        return SourceAttribute::isSelectorAttribute($name);
    }
}
