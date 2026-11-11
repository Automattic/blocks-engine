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

    /** Re-parent a body parsed on its own under an `<html>` carrying the source root attributes. */
    public static function restoreRoot(\DOMElement $body, string $html): void
    {
        $attributes = self::fromHtml($html);
        if ( array() === $attributes ) {
            return;
        }
        $root = $body->ownerDocument->createElement('html');
        foreach ( $attributes as $name => $value ) {
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
