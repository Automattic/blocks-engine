<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

/** Source-declared excerpt ownership, independent of full article content. */
final class ListingExcerptProjection
{
    /** @param array<string,mixed> $post */
    public static function description(array $post): ?string
    {
        if (is_string($post['metadata']['excerpt'] ?? null) && '' !== trim($post['metadata']['excerpt'])) return $post['metadata']['excerpt'];
        foreach (array('description', 'og:description', 'twitter:description') as $key) {
            $values = array();
            foreach ($post['document_metadata']['meta'] ?? array() as $meta) {
                if ($key === strtolower((string) ($meta['name'] ?? $meta['property'] ?? '')) && is_string($meta['content'] ?? null) && '' !== trim($meta['content'])) $values[$meta['content']] = true;
            }
            if (1 === count($values)) return (string) array_key_first($values);
            if (count($values) > 1) return null;
        }
        return null;
    }

    public static function text(string $markup): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace("\xc2\xa0", ' ', html_entity_decode(strip_tags($markup), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?? '');
    }

    /** @param array<string,mixed> $post */
    public static function matches(string $markup, array $post): bool
    {
        $description = self::description($post);
        return null !== $description && self::text($markup) === trim(preg_replace('/\s+/u', ' ', $description) ?? $description);
    }

    /** @param array<int,array<string,mixed>> $cards */
    public static function length(array $cards): int
    {
        $length = 0;
        foreach ($cards as $card) {
            $description = self::description($card['post']);
            if (null !== $description) $length = max($length, count(preg_split('/\s+/u', trim($description), -1, PREG_SPLIT_NO_EMPTY) ?: array()));
        }
        return $length;
    }
}
