<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex;

/** Derive the new-page canvas without changing captured pages' content ownership. */
final class PageAuthoringTemplate
{
    public static function project(array &$pages, array &$templates): void
    {
        $bySlug = array_column($templates, null, 'slug');
        if (!isset($bySlug['page']) || isset($bySlug['page']['source_path'])) return;
        $candidates = array_filter($pages, static fn(array $page): bool => 'page' === ($page['post_type'] ?? '') && empty($page['entrypoint']) && empty($page['synthetic']));
        // Prefer the layout used by the most inner pages, not the landing page.
        $groups = array();
        foreach ($candidates as $page) {
            $canvas = self::canvas($page['canonical_block_markup']);
            if (null !== $canvas) $groups[$canvas] = ($groups[$canvas] ?? 0) + 1;
        }
        if (array() === $groups) return;
        arsort($groups);
        $canvas = (string) array_key_first($groups);
        $original = $bySlug['page'];
        if (!str_contains($original['canonical_block_markup'], '<!-- wp:post-content')) return;
        // Preserve the original fallback for imported routes that still own
        // their title/frame in post content. A route template is also editable.
        $slug = 'page-captured';
        while (isset($bySlug[$slug])) $slug .= '-captured';
        $owned = false;
        foreach ($pages as &$page) {
            $route = TemplateSlugs::forPage($page);
            if ('page' !== ($page['post_type'] ?? '') || !empty($page['entrypoint']) || ('page' !== $route && isset($bySlug[$route]))) continue;
            $page['route_template'] = $slug;
            $page['metadata']['post_meta']['_wp_page_template'] = $slug;
            $owned = true;
        }
        unset($page);
        if ($owned) {
            $copy = $original;
            $copy['slug'] = $slug;
            $copy['target_path'] = 'templates/' . $slug . '.html';
            $copy['reconciliation_identity'] = WordPressSitePlan::identity('template', 'wordpress-site-plan/' . $copy['target_path'], $copy['target_path']);
            $templates[] = $copy;
        }
        foreach ($templates as &$template) {
            if ('page' !== $template['slug']) continue;
            // Replace only the content slot; shared header/footer remain owned
            // by the same lifecycle and continue to surround the canvas.
            $template['canonical_block_markup'] = preg_replace('/<!-- wp:post-content(?:\s+\{.*?\})?\s*\/-->/', $canvas, $original['canonical_block_markup'], 1);
            $template['content_hash'] = WordPressSitePlan::contentHash($template['canonical_block_markup']);
        }
        unset($template);
    }

    private static function canvas(string $markup): ?string
    {
        $index = new BlockMarkupIndex($markup);
        $title = null; $body = null; $longest = 0;
        foreach ($index->ranges() as $range) {
            $slice = substr($markup, $range['offset'], $range['length']);
            if (null === $title && preg_match('/^<!-- wp:heading\b.*?<h1\b/s', $slice)) $title = PostBlocks::title($slice);
            if (!preg_match('/^<!-- wp:paragraph(?:\s|-->)/', $slice)) continue;
            $text = trim(strip_tags($slice));
            if (strlen($text) <= $longest) continue;
            // Sidebars, menus and forms cannot supply the authoring body.
            $parent = $index->parent($range); $outside = true;
            while (null !== $parent) {
                $ancestor = substr($markup, $parent['offset'], $parent['length']);
                if (preg_match('/^<!-- wp:(?:navigation|query|comments)\b|^<!-- wp:group[^>]*"tagName":"(?:aside|header|footer)"/', $ancestor)) $outside = false;
                $parent = $index->parent($parent);
            }
            if (!$outside) continue;
            $body = $range; $longest = strlen($text);
        }
        if (null === $title || null === $body) return null;
        $canvas = $title . '<!-- wp:post-content /-->';
        // Copy only the ancestor boxes, never sibling content, menus or widgets.
        while (null !== ($parent = $index->parent($body))) {
            $children = $index->children($parent);
            $first = $children[0]; $last = $children[count($children) - 1];
            $canvas = substr($markup, $parent['offset'], $first['offset'] - $parent['offset']) . $canvas
                . substr($markup, $last['offset'] + $last['length'], $parent['offset'] + $parent['length'] - $last['offset'] - $last['length']);
            $body = $parent;
        }
        return $canvas;
    }
}
