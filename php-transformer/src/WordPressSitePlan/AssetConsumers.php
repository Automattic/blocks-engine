<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

/** Inventories materialized references, never source selectors. */
final class AssetConsumers
{
    /** @param array<string,mixed> $plan @return array<string,list<array<string,mixed>>> */
    public static function inventory(array $plan): array
    {
        $consumers = array();
        $runtime = new Runtime();
        foreach (array('pages', 'templates', 'template_parts', 'synced_patterns') as $kind) foreach ($plan[$kind] ?? array() as $document) {
            $visit = static function (array $blocks, array $path = array()) use (&$visit, &$consumers, $kind, $document): void {
                foreach ($blocks as $index => $block) {
                    $position = array_merge($path, array($index));
                    $record = array('kind' => 'block', 'document_kind' => $kind, 'document' => $document['source_path'] ?? $document['slug'], 'block_name' => $block['blockName'], 'block_path' => $position);
                    $walk = static function (mixed $value, string $attribute) use (&$walk, &$consumers, $record): void {
                        if (is_array($value)) { foreach ($value as $key => $item) $walk($item, '' === $attribute ? (string) $key : $attribute . '.' . $key); return; }
                        if (!is_string($value)) return;
                        preg_match_all('/\{\{wordpress-site-plan:asset:([^}]+)\}\}/', $value, $matches);
                        foreach (array_unique($matches[1]) as $token) $consumers[$token][] = $record + array('attribute_path' => $attribute, 'count' => substr_count($value, WordPressSitePlan::TOKEN_PREFIX . $token . '}}'));
                    };
                    $walk($block['attrs'], '');
                    $html = implode('', array_filter($block['innerContent'], 'is_string'));
                    // Core/image URL is sourced from its saved img, not comment JSON.
                    $html = (string) preg_replace_callback('/<img\b[^>]*\bsrc=["\']([^"\']*)["\'][^>]*>/i', static function (array $match) use ($walk, $block): string {
                        if ('core/image' !== $block['blockName']) return $match[0];
                        $walk(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5), 'url');
                        return '';
                    }, $html);
                    $walk($html, 'innerHTML');
                    $visit($block['innerBlocks'], $position);
                }
            };
            $visit($runtime->parseBlocks($document['canonical_block_markup']));
        }
        foreach ($plan['writes'] as $write) {
            if (!str_ends_with(strtolower($write['target_path']), '.css') || 'utf8' !== $write['payload']['encoding']) continue;
            $content = $write['canonical_payload'] ?? $write['payload']['data'];
            preg_match_all('/\{\{wordpress-site-plan:asset:([^}]+)\}\}/', $content, $matches);
            foreach (array_unique($matches[1]) as $token) $consumers[$token][] = array('kind' => 'stylesheet', 'target_path' => $write['target_path'], 'count' => substr_count($content, WordPressSitePlan::TOKEN_PREFIX . $token . '}}'));
        }
        return $consumers;
    }

    /** @param list<array<string,mixed>> $consumers */
    public static function destination(array $consumers): string
    {
        foreach ($consumers as $consumer) if ('block' === $consumer['kind']) return 'media_library';
        return 'theme';
    }
}
