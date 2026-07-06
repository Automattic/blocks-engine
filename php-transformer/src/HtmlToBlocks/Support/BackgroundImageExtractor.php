<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

final class BackgroundImageExtractor
{
    public function urlFromStyle(string $style): string
    {
        if ( ! preg_match('/(?:^|;)\s*background(?:-image)?\s*:\s*[^;]*url\(\s*(["\']?)([^"\')]+)\1\s*\)/i', $style, $matches) ) {
            return '';
        }

        $url = trim(html_entity_decode((string) $matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ( '' === $url || preg_match('/[\x00-\x1f\x7f]|javascript\s*:/i', $url) ) {
            return '';
        }

        return $url;
    }

    /**
     * @param array<string, string> $attributes
     */
    public function altFromAttributes(array $attributes): string
    {
        foreach ( array( 'aria-label', 'title' ) as $attribute ) {
            $value = trim($attributes[$attribute] ?? '');
            if ( '' !== $value ) {
                return $value;
            }
        }

        return '';
    }
}
