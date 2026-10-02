<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Support;

use DOMDocument;

/** Source html-root selector identity shared by compilation and site planning. */
final class DocumentRootAttributes
{
    /** @return array<string,string> */
    public static function fromHtml(string $html): array
    {
        if ('' === trim($html)) return array();
        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $attributes = array();
        foreach (array('class', 'id') as $name) {
            $value = trim($document->documentElement?->getAttribute($name) ?? '');
            if ('' !== $value) $attributes[$name] = $value;
        }
        return $attributes;
    }
}
