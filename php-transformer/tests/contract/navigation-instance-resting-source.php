<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\NavigationInstanceDiagnostics;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
// Page identity tokens are state only on the element that carries a current signal.
$projection = new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorSelectorProjectionState();
$document = new DOMDocument();
foreach (array('', 'current-menu-item', 'current_page_item', 'current-menu-ancestor', 'current-menu-parent', 'current_page_parent', 'current_page_ancestor', 'aria') as $signal) {
    $element = $document->createElement('li');
    $element->setAttribute('class', 'page_item page-item-12 ' . $signal);
    if ('aria' === $signal) $element->setAttribute('aria-current', 'page');
    foreach (array('page_item', 'page-item-12') as $class) {
        $assert(('' !== $signal) === $projection->isCurrentNavigationClass($class, $element), 'WordPress page identity needs a same-element current signal.');
        $assert(('' !== $signal) === $projection->selectsCurrentNavigationState('.' . $class, $element), 'Page identity selectors use the same contextual state.');
    }
}
$projection->markCurrentNavigationClasses(array('page_item', 'page-item-12'));
$assert(!$projection->isCurrentNavigationClass('page_item') && !$projection->isCurrentNavigationClass('page-item-12'), 'A previously current page must not strip resting wp_list_pages identity.');

$css = '.menu-toggle{display:none}.c{display:flex}.wrap{width:100%}.menu-wrap{display:flex}.menu-list{display:flex;list-style:none}.menu .menu-link{display:flex;padding:20px;font-size:14px}.menu .current-menu-item .menu-link{color:red}.menu [data-status=active] .menu-link{color:red}';
$page = static fn(bool $current): string => '<html><head><link rel="stylesheet" href="/menu.css"></head><body><section class="a">Logo</section><section class="c"><div class="wrap"><div class="menu-wrap m-141"><nav class="menu"><button class="menu-toggle" type="button" aria-label="Open Menu" aria-expanded="false" aria-controls="menu-141"><span class="menu-toggle-icon"><span class="menu-toggle-lines"></span></span></button><ul id="menu-141" class="menu-list"><li class="' . (!$current ? 'current-menu-item ' : '') . 'menu-item"><a class="menu-link" href="/a"' . (!$current ? ' aria-current="page"' : '') . '>A</a></li><li class="' . ($current ? 'menu-item menu-item-type-post_type menu-item-object-page current-menu-item page_item page-item-12 current_page_item menu-item-34' : 'menu-item menu-item-type-post_type menu-item-object-page menu-item-34') . '"' . ($current ? ' data-status="active"' : '') . '><a class="menu-link" href="/b"' . ($current ? ' aria-current="page"' : '') . '>B</a></li></ul></nav></div></div></section><main><h1>' . ($current ? 'B' : 'A') . '</h1></main></body></html>';

// A site builder's ARIA menu marks the served item `selected current-item`,
// discloses it (`expanded`) and marks its link `aria-current="true"`; that
// state does not rename the resting menu.
$builderPage = static function (bool $current): string {
    // The builder renders the served item in place, laid out differently
    // from the items it includes.
    $item = static fn(string $href, string $label, bool $served): string => '<li role="none"' . ($served ? ' class="selected current-item expanded">' . "\n  " : '>') . '<a href="' . $href . '" class="menu-link" role="menuitem"' . ($served ? ' aria-current="true"' : '') . '>' . $label . '</a></li>';
    return '<html><head><link rel="stylesheet" href="/menu.css"></head><body><header><div class="menu" id="menu" aria-label="Main"><ul role="menu">' . "\n" . $item('/a', 'A', !$current) . "\n" . $item('/b', 'B', $current) . "\n" . '</ul></div></header><main><h1>' . ($current ? 'B' : 'A') . '</h1></main></body></html>';
};
// Compile together (shared CSS) and independently (no cross-route baseline).
foreach (array('WordPress' => $page, 'site builder' => $builderPage) as $flavor => $route) {
$css .= 'site builder' === $flavor ? '@media (min-width:841px){#menu > ul{display:block;text-align:end}#menu > ul > li{display:inline-block}}' : '';
$artifacts = array(
    array('a.html' => $route(false), 'b.html' => $route(true)),
    array('a.html' => $route(false)),
    array('b.html' => $route(true)),
);
$assert(2 === substr_count($route(false) . $route(true), 'aria-current'), "The $flavor pages mark one served item each.");
$hosts = array();
foreach ($artifacts as $files) {
    $plan = (new ArtifactCompiler())->compile(array(
        'entrypoint' => array_key_first($files),
        'files' => $files + array('menu.css' => $css),
    ))->toArray()['source_reports']['wordpress_site_plan'];
    $markup = implode('', array_column(array_merge($plan['pages'], $plan['templates'], $plan['template_parts']), 'canonical_block_markup'));
    $projected = implode("\n", array_column($plan['assets'], 'content'));
    preg_match_all('/blocks-engine-navigation-instance-[a-f0-9]{12}-[0-9]+/', $markup, $instances);
    $assert(1 === count(array_unique($instances[0])), 'Both routes carry one resting navigation instance.');
    preg_match_all('/blocks-engine-navigation-list-box-[a-f0-9]{12}-[0-9]+/', $markup . $projected, $boxes);
    $assert('site builder' !== $flavor || 1 === count(array_unique($boxes[0])), 'The served item does not rename the list box.');
    $instance = $instances[0][0];
    $assert(1 === preg_match('/[^{}]*' . preg_quote($instance, '/') . '[^{}]*wp-block-navigation-item__content[^{}]*\{[^{}]*display:flex[^{}]*padding:20px/', $projected), 'Author link box targets the emitted instance.');
    $assert(array() === NavigationInstanceDiagnostics::collect($plan['assets'], $markup), 'No projection targets an absent instance.');
    $hosts[] = $instance;
    if ('WordPress' === $flavor) $assert(1 === preg_match('/[^{}]*' . preg_quote($instance, '/') . '[^{}]*(?:current-menu-item|aria-current)[^{}]*\{[^{}]*color:(?:red|var\([^{}]*,red\))/', $projected), 'Current item still receives red through the runtime current-item selector.');
}
$assert(1 === count(array_unique($hosts)), "Both $flavor routes use the same instance marker, together and independently.");
}
echo "Navigation instance resting source passed.\n";
