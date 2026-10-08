<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Support;

use DOMDocument;

/** Source document selector identity shared by compilation and site planning. */
final class DocumentRootAttributes
{
    /** @return array<string,string> */
    public static function fromHtml(string $html, string $tagName = 'html'): array
    {
        return self::forDocument($html)[$tagName] ?? array();
    }

    /** @return array{html:array<string,string>,body:array<string,string>} */
    public static function forDocument(string $html): array
    {
        $attributes = array('html' => array(), 'body' => array());
        if ('' === trim($html)) return $attributes;
        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
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
