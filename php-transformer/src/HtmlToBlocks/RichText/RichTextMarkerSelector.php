<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText;

/** Builds declaration-boundary selectors for RichText marker carriers. */
final class RichTextMarkerSelector
{
    /** @return list<string> */
    public static function markSelectors(string $marker): array
    {
        $declaration = '--blocks-engine-richtext-marker:' . $marker;
        return array(
            'mark[style*="' . $declaration . ';"]',
            'mark[style$="' . $declaration . '"]',
        );
    }

    public static function carrierSelectorList(string $marker): string
    {
        return implode(',', array_merge(
            self::markSelectors($marker),
            array('span[data-blocks-engine-richtext-marker="' . $marker . '"]')
        ));
    }
}
