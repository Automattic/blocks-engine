<?php
// Uses the same disposable theme/page installer as the restyle corpus.
putenv('RESTYLE_DISPOSABLE=1');
putenv('RESTYLE_PHASE=install');
putenv('RESTYLE_FIXTURE=' . __DIR__ . '/fixtures/design-system/palette-alpha.php');
putenv('RESTYLE_ROUTES=' . sys_get_temp_dir() . '/palette-alpha-routes.json');
ob_start();
require __DIR__ . '/integration/design-system-restyle-install.php';
ob_end_clean();
$id = (int) get_option('page_on_front');
$markup = get_post_field('post_content', $id);
$walk = static function (array $blocks) use (&$walk): void {
    foreach ($blocks as $block) {
        if ('base-alpha-70' === ($block['attrs']['textColor'] ?? null)) {
            $support = wp_apply_colors_support(WP_Block_Type_Registry::get_instance()->get_registered('core/paragraph'), $block['attrs']);
            if (!str_contains($support['class'] ?? '', 'has-base-alpha-70-color')) throw new RuntimeException('Core color support rejected the preset.');
            if (!str_contains(render_block($block), 'has-base-alpha-70-color')) throw new RuntimeException('Rendered block lost the preset.');
        }
        $walk($block['innerBlocks']);
    }
};
$walk(parse_blocks($markup));
if ($markup !== serialize_blocks(parse_blocks($markup))) throw new RuntimeException('Block parser round trip changed markup.');
echo wp_json_encode(array('post_id' => $id, 'global_styles_id' => (int) WP_Theme_JSON_Resolver::get_user_global_styles_post_id()));
