<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use DOMElement;

/** The simple span identity that a native paragraph can retain through RichText edits. */
final class AddressableInlineLayoutLeaf
{
    /** @return array{id: string, class_name: string, display: string}|null */
    public static function identity(DOMElement $element, StyleResolver $styles): ?array
    {
        if ('span' !== strtolower($element->tagName)
            || 0 !== SourceDom::childElementCount($element)
            || '' !== SourceDom::attr($element, 'style')
        ) return null;
        $id = SourceDom::attr($element, 'id');
        if (1 !== preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,199}$/', $id)) return null;
        foreach ($element->attributes as $attribute) {
            if (!in_array(strtolower($attribute->name), array('id', 'class'), true)) return null;
        }
        $display = $styles->declaredPresentation($element, 'display');
        if ($display->isConditional()) return null;
        return array('id' => $id, 'class_name' => SourceDom::attr($element, 'class'), 'display' => $display->base());
    }
}
