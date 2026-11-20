<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex;

/** The title and body column a source page lends to subsequently authored pages. */
final class PageAuthoringLayout
{
    public const CONTENT = '<!-- blocks-engine-authored-page-content -->';

    /** Project only the ancestor paths of the title and first body paragraph, never source copy. */
    public static function project(string $markup, string $title): ?string
    {
        $index = new BlockMarkupIndex($markup);
        $slots = array();
        foreach ($index->ranges() as $range) {
            $slice = substr($markup, $range['offset'], $range['length']);
            if (!isset($slots['title']) && preg_match('~^<!-- wp:heading\b.*?<h([12])\b[^>]*>(.*?)</h\1>~s', $slice, $match)
                && html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8') === $title) {
                $slots['title'] = $range + array('replacement' => PostBlocks::title($slice));
            }
            if (!isset($slots['body']) && preg_match('~^<!-- wp:paragraph\b~', $slice) && '' !== trim(strip_tags(preg_replace('/<!--.*?-->/s', '', $slice) ?? ''))) {
                $chrome = false;
                for ($parent = $index->parent($range); null !== $parent; $parent = $index->parent($parent)) {
                    if (preg_match('~^<!-- wp:[^>]*-->\s*<(?:header|footer|aside)\b~', substr($markup, $parent['offset'], $parent['length']))) $chrome = true;
                }
                if (!$chrome) $slots['body'] = $range + array('replacement' => self::CONTENT);
            }
        }
        if (count($slots) !== 2) return null;
        // A flat document has no separate column design to project. Do not
        // install a layout adapter for an ordinary heading/paragraph stream.
        if ($index->parent($slots['title']) === $index->parent($slots['body'])) return null;
        $keep = $replace = array();
        foreach ($slots as $slot) {
            $replace[$slot['offset']] = $slot['replacement'];
            for ($parent = $index->parent($slot); null !== $parent; $parent = $index->parent($parent)) $keep[$parent['offset']] = true;
        }
        $project = static function (array $range) use (&$project, $index, $markup, $keep, $replace): string {
            if (isset($replace[$range['offset']])) return $replace[$range['offset']];
            if (!isset($keep[$range['offset']])) return '';
            $result = ''; $cursor = $range['offset'];
            foreach ($index->children($range) as $child) {
                $result .= substr($markup, $cursor, $child['offset'] - $cursor) . $project($child);
                $cursor = $child['offset'] + $child['length'];
            }
            return $result . substr($markup, $cursor, $range['offset'] + $range['length'] - $cursor);
        };
        $layout = implode('', array_map($project, $index->topLevel()));
        // A retained content header or query is not a page authoring column.
        if (str_contains($layout, 'wp:query') || str_contains($layout, 'wp:template-part')) return null;
        return $layout;
    }
}
