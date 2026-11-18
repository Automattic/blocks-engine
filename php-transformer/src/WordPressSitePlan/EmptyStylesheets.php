<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

/** Empty stylesheet accounting and metadata cleanup for retired resources. */
final class EmptyStylesheets
{
    /** @param array<string,mixed> $asset */
    public static function isEmpty(array $asset): bool
    {
        return 'css' === ($asset['kind'] ?? null) && is_string($asset['content'] ?? null) && '' === trim($asset['content']) && !isset($asset['payload_reference']);
    }

    /** @param array<int,array<string,mixed>> $assets */
    public static function count(array $assets): int
    {
        return count(array_filter($assets, self::isEmpty(...)));
    }

    /** Remove declarations naming retired resources and close their order gaps.
     * @param list<array<string,mixed>> $documents
     * @param array<string,true> $references
     * @return list<array<string,mixed>>
     */
    public static function withoutLinks(array $documents, array $references): array
    {
        foreach ($documents as &$document) {
            if (!is_array($document['document_metadata']['links'] ?? null)) continue;
            $links = array_values(array_filter($document['document_metadata']['links'], static fn(array $link): bool => !isset($references[$link['asset_reference'] ?? ''])));
            foreach ($links as $order => &$link) $link['order'] = $order;
            unset($link);
            $document['document_metadata']['links'] = $links;
        }
        unset($document);
        return $documents;
    }
}
