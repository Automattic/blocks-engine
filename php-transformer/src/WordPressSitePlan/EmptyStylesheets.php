<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

/**
 * Stylesheets projection emptied. Dead selectors, absent carriers and the
 * @font-face rules theme.json now registers can consume a sheet's last rule;
 * writing and enqueueing it then costs a request that styles nothing.
 */
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

    /**
     * Drop each empty stylesheet whose removal leaves delivery unchanged,
     * together with the head link declarations naming it. A sheet stays when
     * anything else names its token (an @import, a runtime declaration), when
     * another asset delivers through it (a slice's parent, a part or replay
     * owner), or when dropping it would change which documents the bootstrap's
     * route table selects or where any other sheet is enqueued.
     *
     * @param array<int,array<string,mixed>> $assets
     * @param array<string,list<array<string,mixed>>> $documentSets Emitted documents by kind; their head links may name a sheet.
     * @param array<int,array<string,mixed>> $pages
     * @param array<int,array<string,mixed>> $rootDocuments
     * @param string $consumers Serialized declarations outside the documents that may name an asset token.
     * @return array{assets:array<int,array<string,mixed>>,document_sets:array<string,list<array<string,mixed>>>,tokens:array<string,true>}
     */
    public static function elide(array $assets, array $documentSets, array $pages, array $rootDocuments, string $consumers): array
    {
        $unchanged = array('assets' => $assets, 'document_sets' => $documentSets, 'tokens' => array());
        $delivering = array();
        foreach ($assets as $asset) {
            if (is_string($asset['stylesheet_parent'] ?? null)) $delivering['source:' . $asset['stylesheet_parent']] = true;
            foreach ($asset['stylesheet_parts'] ?? array() as $path) $delivering['target:' . $path] = true;
        }
        $candidates = array();
        foreach ($assets as $index => $asset) {
            // A source reference other than a link (an @import) resolves to the sheet when it is written.
            $linkedOnly = array() === array_filter(is_array($asset['references'] ?? null) ? $asset['references'] : array(), static fn(mixed $reference): bool => !is_array($reference) || 'link' !== ($reference['element'] ?? null));
            if (!self::isEmpty($asset) || !$linkedOnly || !is_string($asset['token'] ?? null) || isset($asset['stylesheet_parts']) || isset($asset['stylesheet_replays']) || isset($delivering['source:' . ($asset['source_path'] ?? '')]) || isset($delivering['target:' . ($asset['target_path'] ?? '')])) continue;
            $candidates[$index] = WordPressSitePlan::TOKEN_PREFIX . $asset['token'] . '}}';
        }
        if (array() === $candidates) return $unchanged;

        // Only a sheet's own head link declarations may name it.
        $links = array_fill_keys($candidates, 0);
        foreach ($documentSets as $documents) foreach ($documents as $document) foreach ($document['document_metadata']['links'] ?? array() as $link) {
            if (is_array($link) && isset($links[$link['asset_reference'] ?? ''])) ++$links[$link['asset_reference']];
        }
        $haystack = $consumers . "\n" . json_encode($documentSets, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n" . implode("\n", array_map(static fn(array $asset): string => is_string($asset['content'] ?? null) ? $asset['content'] : '', $assets));
        $candidates = array_filter($candidates, static fn(string $reference): bool => substr_count($haystack, $reference) === $links[$reference]);
        if (array() === $candidates) return $unchanged;

        $delivery = static function (array $kept) use ($pages, $rootDocuments): array {
            return ThemeBootstrap::stylesheetDelivery(array_values($kept), $pages, $rootDocuments);
        };
        $expected = static function (array $before, array $elided): array {
            $targets = array_fill_keys(array_column($elided, 'target_path'), true);
            $before['enqueues'] = array_values(array_filter($before['enqueues'], static fn(array $enqueue): bool => !isset($targets[$enqueue['target_path']])));
            return $before;
        };
        $before = $delivery($assets);
        $kept = $assets;
        $elided = array_intersect_key($assets, $candidates);
        if ($expected($before, $elided) === $delivery(array_diff_key($assets, $candidates))) {
            $kept = array_diff_key($assets, $candidates);
        } else {
            // Some sheet still carries route selection: decide one at a time.
            $elided = array();
            foreach (array_keys($candidates) as $index) {
                $trial = $kept;
                unset($trial[$index]);
                if ($expected($before, $elided + array($index => $assets[$index])) !== $delivery($trial)) continue;
                $kept = $trial;
                $elided[$index] = $assets[$index];
            }
        }
        if (array() === $elided) return $unchanged;

        $tokens = array();
        foreach ($elided as $index => $asset) $tokens[$asset['token']] = true;
        $references = array_fill_keys(array_intersect_key($candidates, $elided), true);
        foreach ($documentSets as &$documents) foreach ($documents as &$document) {
            if (!is_array($document['document_metadata']['links'] ?? null)) continue;
            $declarations = array_values(array_filter($document['document_metadata']['links'], static fn(mixed $link): bool => !is_array($link) || !isset($references[$link['asset_reference'] ?? ''])));
            // Declarations are numbered by position; the survivors close ranks.
            foreach ($declarations as $order => &$declaration) if (is_array($declaration)) $declaration['order'] = $order;
            unset($declaration);
            $document['document_metadata']['links'] = $declarations;
        }
        unset($documents, $document);
        return array('assets' => array_values($kept), 'document_sets' => $documentSets, 'tokens' => $tokens);
    }
}
