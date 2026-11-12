<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

/**
 * Proof that a site plan kept the text its compiled documents carried.
 *
 * Each text run must reach its own page or a shared surface. Text that
 * reaches neither was dropped, and is reported as content loss on the plan.
 */
final class ContentConservation
{
    /**
     * Every text run a compiled document carries must survive into the plan:
     * its own page, or a shared surface (template part, template, pattern,
     * menu or runtime record). Text that reaches neither was dropped, so the
     * plan counts it as content loss instead of reporting a clean conversion.
     *
     * @param array<string,mixed> $plan @param array<int,mixed> $documents Compiled pages.
     * @return array<string,mixed>
     */
    public static function check(array $plan, array $documents): array
    {
        // Ordinary comments contribute no text or spacing, just as on the
        // source side. Keep block delimiters searchable for attribute text.
        $text = static fn(string $markup): string => preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace_callback('/<!--(.*?)-->/s', static fn(array $match): string => preg_match('/^\s*\/?wp:[a-z0-9-]+(?:\/[a-z0-9-]+)?(?:\s|$)/', $match[1]) ? ' ' . $match[1] . ' ' : '', $markup) ?? ''), ENT_QUOTES | ENT_HTML5)) ?? '';
        $leaves = static function (mixed $value) use (&$leaves): string {
            if (is_string($value)) return $value;
            return is_array($value) ? implode("\n", array_map($leaves, $value)) : '';
        };
        $shared = $text($leaves(array_intersect_key($plan, array_flip(array('template_parts', 'templates', 'synced_patterns', 'menus', 'runtime_records', 'runtime_entity_records')))));
        $pages = array();
        $byType = array();
        foreach ($plan['pages'] as $page) {
            $pages[$page['source_path']] = $text($leaves($page));
            $byType[(string) ($page['post_type'] ?? '')][] = $pages[$page['source_path']];
        }
        // A query loop renders the titles, excerpts and bound meta of the
        // entries it queries, so their text is carried by the listing page.
        foreach ($plan['pages'] as $page) {
            if (!preg_match_all('/<!-- wp:query (\{.*?\}) \/?-->/s', (string) ($page['canonical_block_markup'] ?? ''), $queries)) continue;
            foreach ($queries[1] as $json) {
                $type = json_decode($json, true)['query']['postType'] ?? null;
                if (is_string($type)) $pages[$page['source_path']] .= "\n" . implode("\n", $byType[$type] ?? array());
            }
        }
        // Queries in shared templates render the same post-scoped data as
        // queries in page bodies (including related-post titles).
        foreach ($plan['templates'] ?? array() as $template) {
            if (!preg_match_all('/<!-- wp:query (\{.*?\}) \/?-->/s', (string) ($template['canonical_block_markup'] ?? ''), $queries)) continue;
            foreach ($queries[1] as $json) {
                $type = json_decode($json, true)['query']['postType'] ?? null;
                if (is_string($type)) $shared .= "\n" . implode("\n", $byType[$type] ?? array());
            }
        }
        // A post-date block in a shared template renders each post's own
        // publication date in the authored format.
        foreach ($plan['templates'] ?? array() as $template) {
            if (!preg_match_all('/<!-- wp:post-date (\{.*?\}) \/?-->/s', (string) ($template['canonical_block_markup'] ?? ''), $dates)) continue;
            foreach ($dates[1] as $json) {
                $format = json_decode($json, true)['format'] ?? null;
                if (!is_string($format)) continue;
                foreach ($plan['pages'] as $page) {
                    $published = $page['publication_local_time'] ?? $page['publication_timestamp'] ?? null;
                    if (is_string($published) && false !== ($date = date_create_immutable($published))) $pages[$page['source_path']] .= "\n" . $date->format($format);
                }
            }
        }
        $findings = array();
        foreach ($documents as $document) {
            if (!is_array($document) || !is_string($document['block_markup'] ?? null) || !is_string($document['source_path'] ?? null)) continue;
            $source = preg_replace(array('/<!--.*?-->/s', '#<(script|style|template)\b[^>]*>.*?</\1\s*>#is'), '', $document['block_markup']) ?? '';
            $lost = array();
            foreach (preg_split('/<[^>]*>/', $source) ?: array() as $run) {
                $run = trim(preg_replace('/\s+/u', ' ', html_entity_decode($run, ENT_QUOTES | ENT_HTML5)) ?? '');
                if (!preg_match('/[\p{L}\p{N}].*[\p{L}\p{N}]/u', $run) || isset($lost[$run])) continue;
                if (!str_contains($pages[$document['source_path']] ?? '', $run) && !str_contains($shared, $run)) $lost[$run] = true;
            }
            if (array() === $lost) continue;
            $findings[] = array('code' => 'wordpress_site_plan_content_lost', 'severity' => 'error', 'loss_class' => 'content_loss', 'source_path' => $document['source_path'], 'lost_text_count' => count($lost), 'lost_text' => array_slice(array_keys($lost), 0, 5), 'message' => 'Compiled document text is missing from its page and from every shared surface in the plan.');
        }
        $plan['quality']['metrics']['content_loss_count'] = count($findings);
        return array() === $findings ? $plan : WordPressSitePlan::withFindings($plan, $findings);
    }
}
