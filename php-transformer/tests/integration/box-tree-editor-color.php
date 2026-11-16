<?php
declare(strict_types=1);

// Run via wp eval-file, after design-system-restyle-install.php installs artifact.php.
if ('1' !== getenv('RESTYLE_DISPOSABLE')) throw new RuntimeException('An explicit disposable runtime is required.');
$page = get_post((int) get_option('page_on_front'));
$blocks = parse_blocks($page->post_content);
$changed = false;
$edit = static function (array &$blocks) use (&$edit, &$changed): void {
    foreach ($blocks as &$block) {
        if ('core/heading' === $block['blockName']) {
            $block['attrs']['style']['color']['text'] = '#00aa55';
            // Static blocks save support styles into their HTML as well as attributes.
            $tags = new WP_HTML_Tag_Processor($block['innerHTML']);
            $tags->next_tag();
            $style = preg_replace('/(?:^|;)color:[^;]*/', '', (string) $tags->get_attribute('style'));
            $tags->set_attribute('style', $style . ';color:#00aa55');
            $block['innerHTML'] = $tags->get_updated_html();
            $block['innerContent'] = array($block['innerHTML']);
            $rendered = render_block($block);
            if (!str_contains($rendered, 'color:#00aa55')) throw new RuntimeException('WordPress must render the editor-set text color.');
            $changed = true;
        }
        $edit($block['innerBlocks']);
    }
};
$edit($blocks);
if (!$changed) throw new RuntimeException('Fixture must contain an editable heading.');
wp_update_post(array('ID' => $page->ID, 'post_content' => wp_slash(serialize_blocks($blocks))));
echo "PASS: WordPress renders the user-set color attribute on an engine-restyled block.\n";
