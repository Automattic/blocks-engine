<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/** Capture identity is evidence, not an authored wrapper requirement. */
final class FormNativeContainerPolicy
{
    /** @param array<string, mixed> $attributes */
    public static function isClassOnly(array $attributes): bool
    {
        unset($attributes[BoxTree::NODE_ATTRIBUTE]);
        if (isset($attributes['style']) && '' === trim((string) $attributes['style'])) {
            unset($attributes['style']);
        }
        unset($attributes['class']);
        return array() === $attributes;
    }

    public static function accepts(DOMElement $element): bool
    {
        return 'div' === strtolower($element->tagName) && self::isClassOnly(SourceDom::htmlAttributes($element));
    }
}
