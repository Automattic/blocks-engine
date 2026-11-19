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

    /** Re-parent a body parsed on its own under an `<html>` carrying the source root attributes, so `:root` and `html` rules match the root rather than the body. */
    public static function restoreRoot(\DOMElement $body, string $html): void
    {
        $root = $body->ownerDocument->createElement('html');
        foreach ( self::fromHtml($html) as $name => $value ) {
            $root->setAttribute($name, $value);
        }
        $body->ownerDocument->replaceChild($root, $body);
        $root->appendChild($body);
    }

    /**
     * Give a body parsed on its own (and the `<html>` {@see self::restoreRoot()}
     * put above it) the source roots' box-tree node identity. A landmark that
     * is a direct child of the body (a header or footer template part) has
     * the body as its containing block, so its `%` lengths and author
     * variants are proven against the body's captured box like any nested
     * block's, rather than freezing at the captured pixels.
     */
    public static function restoreEvidenceIdentity(\DOMElement $body, string $html): void
    {
        if ('' === trim($html)) return;
        $document = HtmlSerialization::parseDocument($html);
        foreach (array('body' => $body, 'html' => $body->parentNode) as $tagName => $target) {
            $source = $document->getElementsByTagName($tagName)->item(0);
            if (!$source instanceof \DOMElement || !$target instanceof \DOMElement) continue;
            $id = $source->getAttribute(\Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree::NODE_ATTRIBUTE);
            if ('' !== $id) $target->setAttribute(\Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree::NODE_ATTRIBUTE, $id);
        }
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
