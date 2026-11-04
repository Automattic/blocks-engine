<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ShellExtraction;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$routes = array('index' => 'Home', 'about' => 'About', 'contact' => 'Contact');
$files = array();
foreach ($routes as $current => $title) {
    $items = '';
    foreach ($routes as $slug => $label) {
        $items .= '<li' . ($slug === $current ? ' class="selected current-item expanded"' : '') . '><a href="/' . ('index' === $slug ? '' : $slug . '/') . '"' . ($slug === $current ? ' aria-current="true"' : '') . '>' . $label . '</a></li>';
    }
    $files[$current . '.html'] = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Site - ' . $title . '</title></head><body>'
        . '<div id="wrap"><header id="top"><div id="header"><div id="title"><a href="/">Example site</a></div><div id="menu"><ul>' . $items . '</ul></div></div></header>'
        . '<div id="main"><main><p>Body of ' . $title . '</p></main></div></div><footer id="bottom"><p>Footer text for every page.</p></footer></body></html>';
}

$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers), 'The route-specific expanded class does not split the shared header: ' . json_encode($plan['diagnostics']));
$assert(1 === count($plan['menus']), 'The routes share one wp_navigation entity.');
$menu = $plan['menus'][0]['block_markup'];
$assert(!str_contains($menu, 'expanded') && !str_contains($menu, 'blocks-engine-current-navigation-item'), 'The shared navigation does not freeze disclosure or current-item state.');
foreach ($plan['pages'] as $page) {
    $markup = $page['canonical_block_markup'];
    $assert(str_contains($markup, 'wp:template-part {"slug":"' . $headers[0]['slug'] . '"'), $page['source_path'] . ' binds the shared header part.');
    $assert(!str_contains($markup, '<!-- wp:navigation '), $page['source_path'] . ' does not keep an inline navigation copy.');
}

// A menu converted to li groups carries the disclosure class on the served
// route's item, and in a submenu on its parent item too. A class every
// sibling carries is presentation.
$listMenu = static function (array $disclosed): string {
    $item = static function (string $label, array $disclosed, string $inner = ''): string {
        $class = trim((in_array($label, $disclosed, true) ? 'expanded ' : '') . 'menu-entry blocks-engine-source-li-fa45d51ecca6-0');
        return '<!-- wp:group {"className":"' . $class . '","tagName":"li"} --><li class="wp-block-group ' . $class . '"><!-- wp:paragraph --><p>' . $label . '</p><!-- /wp:paragraph -->' . $inner . '</li><!-- /wp:group -->';
    };
    $submenu = '<!-- wp:group {"tagName":"ul"} --><ul class="wp-block-group">' . $item('Fiat', $disclosed) . $item('Ford', $disclosed) . '</ul><!-- /wp:group -->';
    return '<!-- wp:group {"tagName":"ul"} --><ul class="wp-block-group">' . $item('Home', $disclosed) . $item('About', $disclosed) . $item('Models', $disclosed, $submenu) . '</ul><!-- /wp:group -->';
};
$resting = static fn(string $markup): string => ShellExtraction::withoutCurrentNavigationState($markup, true);
foreach (array(array('About'), array('Models', 'Fiat'), array('Models', 'Ford')) as $served) {
    $assert($resting($listMenu(array('Home'))) === $resting($listMenu($served)), 'The served item\'s disclosure classes do not split li-group menu chrome: ' . implode(', ', $served));
}
$assert(!str_contains($resting($listMenu(array('Models', 'Fiat'))), 'expanded'), 'The shared li-group menu does not freeze disclosure state.');
$assert(5 === substr_count($resting($listMenu(array('Home', 'About', 'Models', 'Fiat', 'Ford'))), 'class="wp-block-group expanded '), 'A disclosure class every sibling carries stays.');

echo "navigation-disclosure-current-item-shared-header contract passed\n";
