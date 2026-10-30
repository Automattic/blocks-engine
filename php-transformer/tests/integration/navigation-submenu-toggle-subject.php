<?php
declare(strict_types=1);

// Run in an existing WordPress process (wp eval-file), or with WP_TESTS_DIR.
if (!function_exists('render_block')) {
    $testsDir = getenv('WP_TESTS_DIR');
    if (!is_string($testsDir) || !is_file($testsDir . '/includes/bootstrap.php')) {
        throw new RuntimeException('WordPress or WP_TESTS_DIR is required for this render proof.');
    }
    require $testsDir . '/includes/bootstrap.php';
}
require_once dirname(__DIR__, 2) . '/src/WordPressSitePlan/NavigationAnchorRuntime.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\NavigationAnchorRuntime;

eval(NavigationAnchorRuntime::source());
foreach (array('core/navigation-link' => '/contact', 'core/navigation-submenu' => '') as $name => $url) {
    foreach (array('', '/club') as $submenuUrl) {
        $attrs = array(
            'label' => 'Club', 'kind' => 'custom', 'anchor' => 'm1', 'className' => 'nav-item dropdown',
            'metadata' => array('blocksEngineNavigationAnchor' => array('className' => 'nav-link dropdown-toggle', 'id' => 'club-toggle', 'style' => 'padding:0')),
        );
        if ('' !== ($url ?: $submenuUrl)) $attrs['url'] = $url ?: $submenuUrl;
        $block = array('blockName' => $name, 'attrs' => $attrs, 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array());
        $html = render_block($block);
        $processor = new WP_HTML_Tag_Processor($html);
        if (!$processor->next_tag(array('tag_name' => 'LI')) || $processor->has_class('nav-link') || 'm1' !== $processor->get_attribute('id')) {
            throw new RuntimeException('The item must retain only its own identity: ' . $html);
        }
        if (!$processor->next_tag(array('class_name' => 'wp-block-navigation-item__content'))
            || !$processor->has_class('nav-link') || !$processor->has_class('dropdown-toggle')
            || 'club-toggle' !== $processor->get_attribute('id') || !str_contains((string) $processor->get_attribute('style'), 'padding:0')) {
            throw new RuntimeException('Core content must carry the toggle subject: ' . $html);
        }
        echo $name . ' ' . ($attrs['url'] ?? '(URL-less button)') . ': rendered subject passed' . PHP_EOL;
    }
}
