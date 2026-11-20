<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;

/** Supplies the uncaptured page lifecycle without changing captured template slots. */
final class AuthoredPageRuntime
{
    /** @param list<array<string,mixed>> $pages */
    public static function source(array $pages): string
    {
        // Reuse the recurring padded content region, not a hero's full-bleed
        // frame. Its projected classes retain responsive width and gutters.
        $regions = array();
        foreach ($pages as $page) {
            if ('page' !== ($page['post_type'] ?? null)) continue;
            $dom = HtmlSerialization::parseDocument('<html><body>' . $page['canonical_block_markup'] . '</body></html>');
            foreach ($dom->getElementsByTagName('section') as $section) {
                $style = array_column(\Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer::declarations($section->getAttribute('style'), array('padding-left', 'padding-right')), 'value', 'name');
                if (empty($style['padding-left']) || empty($style['padding-right'])) continue;
                $copy = $section->cloneNode(false);
                $opening = $dom->saveHTML($copy);
                $regions[$opening] = ($regions[$opening] ?? 0) + 1;
            }
        }
        // With no reusable content region there is no column lifecycle to fill.
        if (array() === $regions) return '';
        arsort($regions);
        $region = (string) array_key_first($regions);
        $opening = substr($region, 0, (int) strpos($region, '>') + 1);
        return "add_filter( 'render_block_core/post-content', static function ( string \$content, array \$block, WP_Block \$instance ): string {\n"
            . "    \$id = (int) ( \$instance->context['postId'] ?? 0 );\n"
            . "    if ( ! \$id || 'page' !== get_post_type( \$id ) || '' !== get_post_meta( \$id, '_blocks_engine_reconciliation_identity', true ) ) return \$content;\n"
            . "    \$post = get_post( \$id );\n"
            . "    if ( ! \$post || has_block( 'core/post-title', \$post->post_content ) || preg_match( '/<h[12]\\b/i', \$post->post_content ) ) return \$content;\n"
            . "    \$title = new WP_Block( array( 'blockName' => 'core/post-title', 'attrs' => array( 'level' => 1 ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ), array( 'postId' => \$id, 'postType' => 'page' ) );\n"
            . "    return " . var_export($opening, true) . " . \$title->render() . \$content . '</section>';\n"
            . "}, 10, 3 );";
    }
}
