<?php
declare(strict_types=1);

// Proves in WordPress that a usage-derived design system is live: blocks bound
// to a palette entry restyle when that entry changes in Global Styles.
// Run with a standard WordPress test-suite installation, for example:
// WP_TESTS_DIR=/path/to/wordpress-tests-lib composer test:wordpress-integration
$testsDir = getenv('WP_TESTS_DIR');
if (!is_string($testsDir) || !is_file($testsDir . '/includes/bootstrap.php')) {
    if ('1' === getenv('REQUIRE_WP_TESTS')) {
        fwrite(STDERR, "WP_TESTS_DIR must point to a WordPress test-suite installation.\n");
        exit(1);
    }
    fwrite(STDOUT, "design-system palette WordPress integration skipped: WP_TESTS_DIR is unavailable.\n");
    exit(0);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require $testsDir . '/includes/bootstrap.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$theme = 'blocks-engine-design-system-' . substr(hash('sha256', __FILE__), 0, 8);
$themeDir = WP_CONTENT_DIR . '/themes/' . $theme;
$previousTheme = get_stylesheet();
$recolor = null;
try {
    $plan = (new ArtifactCompiler())->compile(require dirname(__DIR__) . '/fixtures/design-system/plain-css.php')->toArray()['source_reports']['wordpress_site_plan'] ?? array();
    $assert(array() !== $plan, 'The plain-CSS fixture produces a site plan.');
    $resolved = (new WordPressSitePlanResolver())->resolve($plan, array('theme_uri' => home_url('/wp-content/themes/' . $theme)));
    foreach ($resolved['writes'] as $write) {
        $path = $themeDir . '/' . $write['target_path'];
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0777, true) && !is_dir(dirname($path))) throw new RuntimeException('Could not create theme write directory.');
        if (false === file_put_contents($path, 'base64' === $write['payload']['encoding'] ? base64_decode($write['payload']['data'], true) : $write['payload']['data'])) throw new RuntimeException('Could not write materialized theme file.');
    }
    wp_clean_themes_cache();
    $assert(wp_get_theme($theme)->exists(), 'WordPress recognizes the materialized block theme.');
    switch_theme($theme);
    wp_clean_theme_json_cache();

    // The inline `style="color:#1d3557"` paragraph is bound to the palette
    // entry that owns #1d3557.
    $themeJson = json_decode((string) file_get_contents($themeDir . '/theme.json'), true);
    $palette = array_column($themeJson['settings']['color']['palette'] ?? array(), 'color', 'slug');
    $slug = (string) array_search('#1d3557', $palette, true);
    $assert('' !== $slug && isset($palette['base'], $palette['contrast']), 'theme.json carries a role-named palette with the bound colour.');
    $markup = (string) ($plan['pages'][0]['canonical_block_markup'] ?? '');
    $bound = null;
    $walk = static function (array $blocks) use (&$walk, &$bound, $slug): void {
        foreach ($blocks as $block) {
            if (null === $bound && $slug === ($block['attrs']['textColor'] ?? null)) $bound = $block;
            $walk($block['innerBlocks'] ?? array());
        }
    };
    $walk(parse_blocks($markup));
    $assert(is_array($bound) && 'core/paragraph' === $bound['blockName'] && !isset($bound['attrs']['style']['color']['text']), 'WordPress parses the bound paragraph with a textColor preset and no literal colour.');
    $assert($markup === serialize_blocks(parse_blocks($markup)), 'Bound block markup round-trips through the WordPress block parser and serializer unchanged.');
    $rendered = render_block($bound);
    $assert(str_contains($rendered, 'has-' . $slug . '-color') && !str_contains($rendered, 'color:#1d3557'), 'The rendered paragraph is styled by the preset class, not an inline colour.');

    $stylesheet = wp_get_global_stylesheet();
    $assert(str_contains($stylesheet, '--wp--preset--color--' . $slug . ': #1d3557'), 'Global Styles define the palette entry with the source colour.');
    $assert(1 === preg_match('/\.has-' . preg_quote($slug, '/') . '-color\s*\{\s*color:\s*var\(--wp--preset--color--' . preg_quote($slug, '/') . '\)\s*!important/', $stylesheet), 'Global Styles map the preset class to the palette variable.');

    // Changing the palette entry, as a site editor does in Global Styles,
    // changes the colour every bound block resolves to.
    $recolor = static function (WP_Theme_JSON_Data $data) use ($slug): WP_Theme_JSON_Data {
        return $data->update_with(array('version' => 3, 'settings' => array('color' => array('palette' => array(array('slug' => $slug, 'name' => 'Recoloured', 'color' => '#00a86b'))))));
    };
    add_filter('wp_theme_json_data_user', $recolor);
    wp_clean_theme_json_cache();
    $recoloured = wp_get_global_stylesheet();
    $assert(str_contains($recoloured, '--wp--preset--color--' . $slug . ': #00a86b') && !str_contains($recoloured, '--wp--preset--color--' . $slug . ': #1d3557'), 'Editing the palette entry restyles every block bound to it.');
    $assert(str_contains($recoloured, '.has-' . $slug . '-color'), 'Bound blocks keep resolving through the edited palette entry.');
    $assert(str_contains($recoloured, '--wp--preset--color--base: ' . $palette['base']), 'Other palette entries keep their source colours.');
    fwrite(STDOUT, "design-system palette WordPress integration passed\n");
} finally {
    if (null !== $recolor) remove_filter('wp_theme_json_data_user', $recolor);
    if ('' !== $previousTheme) switch_theme($previousTheme);
    wp_clean_themes_cache();
    if (is_dir($themeDir)) { $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($themeDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST); foreach ($items as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()); rmdir($themeDir); }
}
