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

    /**
     * SVGs whose text needs an embedded web font. The font is embedded into
     * the theme write, and an SVG served as an image cannot load fonts from
     * elsewhere, so these stay theme assets even when a block references them.
     *
     * @param array<string,mixed> $plan @return array<string,true>
     */
    public static function fontEmbeddingSvgs(array $plan): array
    {
        return array_fill_keys(array_column($plan['theme']['font_materialization']['webfont_contract']['svg_consumers'] ?? array(), 'source_path'), true);
    }

    /** @param list<array<string,mixed>> $consumers @param array<string,true>|null $unreferenced @param array<string,true> $fontEmbeddingSvgs */
    public static function destination(array $consumers, array $asset = array(), ?array $unreferenced = null, array $fontEmbeddingSvgs = array()): string
    {
        if (isset($fontEmbeddingSvgs[$asset['source_path'] ?? ''])) return 'theme';
        foreach ($consumers as $consumer) if ('block' === $consumer['kind']) return 'media_library';
        if (null !== $unreferenced && isset($unreferenced[$asset['token'] ?? ''])) return 'none';
        return 'theme';
    }

    /**
     * Why an asset bound for the media library still needs a theme write: a
     * stylesheet that consumes it has no portable relative URL to an upload,
     * so each stylesheet consumer is an explicit, counted fallback.
     *
     * @param list<array<string,mixed>> $consumers @return list<array<string,mixed>>
     */
    public static function themeWriteReasons(array $consumers, string $destination): array
    {
        if ('media_library' !== $destination) return array();
        $reasons = array();
        foreach ($consumers as $consumer) if ('stylesheet' === $consumer['kind']) $reasons[] = array('reason' => 'attachment_url_required', 'target_path' => $consumer['target_path'], 'count' => $consumer['count']);
        return $reasons;
    }

    /**
     * Image assets no plan member references. Static references are declared
     * tokens only, and plan metadata (featured images, reports) names assets
     * by target path, so an image whose token and target path are absent from
     * every page, template, part, pattern, menu, theme, runtime record and
     * other write is unreachable. Script references must be proven before
     * absence counts. An image's own write never references it; an SVG write
     * can reference another image, so pruned SVGs stop counting until the set
     * is stable.
     *
     * @param array<string,mixed> $plan
     * @return array<string,true>|null Null when unreferenced assets cannot be proven.
     */
    public static function unreferencedImages(array $plan): ?array
    {
        if ('proven' !== ($plan['reference_semantics']['dynamic_script_references'] ?? null)) return null;
        $images = array();
        foreach ($plan['assets'] as $asset) {
            if ('asset' === ($asset['kind'] ?? null) && str_starts_with((string) ($asset['mime_type'] ?? ''), 'image/')) $images[$asset['token']] = $asset['target_path'];
        }
        $imageTargets = array_flip($images);
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        $base = json_encode(array_diff_key($plan, array('assets' => true, 'reference_tokens' => true, 'writes' => true, 'plan_identity' => true)), $flags)
            . json_encode(array_values(array_filter($plan['writes'], static fn(array $write): bool => !isset($imageTargets[$write['target_path']]) && 'theme_bootstrap' !== ($write['kind'] ?? null))), $flags);
        $imagePayloads = array();
        foreach ($plan['writes'] as $write) if (isset($imageTargets[$write['target_path']]) && 'utf8' === ($write['payload']['encoding'] ?? null)) $imagePayloads[$write['target_path']] = (string) ($write['canonical_payload'] ?? $write['payload']['data'] ?? '');
        // Scan each text once for the references it makes rather than
        // rescanning the whole plan for every image. A reference the scan
        // cannot represent, or a scan PCRE refuses, is searched literally.
        // Only image tokens and targets are looked up, so each scan keeps just
        // those hits rather than every token and quoted string it encloses.
        $wanted = array('tokens' => array_fill_keys(array_map('strval', array_keys($images)), true), 'quoted' => $imageTargets);
        $baseReferences = self::references($base, $wanted);
        $payloadReferences = array_map(static fn (string $payload): ?array => self::references($payload, $wanted), $imagePayloads);
        $scannable = null !== $baseReferences && !in_array(null, $payloadReferences, true);
        $unreferenced = array();
        do {
            $haystack = $base;
            $tokens = $baseReferences['tokens'] ?? array();
            $quoted = $baseReferences['quoted'] ?? array();
            foreach ($imagePayloads as $target => $payload) {
                if (isset($unreferenced[$imageTargets[$target]])) continue;
                $haystack .= "\n" . $payload;
                if ($scannable) {
                    $tokens += $payloadReferences[$target]['tokens'];
                    $quoted += $payloadReferences[$target]['quoted'];
                }
            }
            $next = array();
            foreach ($images as $token => $target) {
                $referenced = $scannable && !str_contains((string) $token, '}') && !str_contains($target, '"')
                    ? isset($tokens[(string) $token]) || isset($quoted[$target])
                    : str_contains($haystack, WordPressSitePlan::TOKEN_PREFIX . $token . '}}') || str_contains($haystack, '"' . $target . '"');
                if (!$referenced) $next[$token] = true;
            }
            $stable = $next === $unreferenced;
            $unreferenced = $next;
        } while (!$stable);
        return $unreferenced;
    }

    /**
     * The wanted asset tokens a text references, and the wanted quote-free
     * strings it encloses in double quotes, overlapping enclosures included:
     * exactly the values `str_contains()` finds for a `}`-free token or
     * `"`-free target.
     *
     * @param array{tokens: array<string,mixed>, quoted: array<string,mixed>} $wanted
     * @return array{tokens: array<string,true>, quoted: array<string,true>}|null
     */
    private static function references(string $text, array $wanted): ?array
    {
        if (false === preg_match_all('/' . preg_quote(WordPressSitePlan::TOKEN_PREFIX, '/') . '(?=([^}]*)\}\})/', $text, $tokens)) return null;
        $tokens = array_intersect_key(array_fill_keys($tokens[1], true), $wanted['tokens']);
        if (false === preg_match_all('/"(?=([^"]*)")/', $text, $quoted)) return null;
        return array('tokens' => $tokens, 'quoted' => array_intersect_key(array_fill_keys($quoted[1], true), $wanted['quoted']));
    }

    /**
     * Records every asset's consumers, destination and theme-write reasons on
     * the finished plan. An image nothing references is not materialized; its
     * row and token remain so the plan still reports what the source carried.
     *
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    public static function assign(array $plan): array
    {
        $consumers = self::inventory($plan);
        $unreferenced = array_merge(self::unreferencedImages($plan) ?? array(), DocumentScripts::unreferencedInline($plan));
        $fontEmbeddingSvgs = self::fontEmbeddingSvgs($plan);
        $unwritten = array();
        foreach ($plan['assets'] as &$asset) {
            $asset['consumers'] = $consumers[$asset['token']] ?? array();
            $asset['destination'] = self::destination($asset['consumers'], $asset, $unreferenced, $fontEmbeddingSvgs);
            $asset['theme_write_reasons'] = self::themeWriteReasons($asset['consumers'], $asset['destination']);
            if ('none' === $asset['destination']) $unwritten[$asset['target_path']] = true;
        }
        unset($asset);
        if (array() !== $unwritten) $plan['writes'] = array_values(array_filter($plan['writes'], static fn(array $write): bool => !isset($unwritten[$write['target_path']])));
        return $plan;
    }
}
