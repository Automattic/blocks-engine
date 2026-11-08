<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

// #4983: a header menu sizes its items inline, and a page menu repeats the same
// links inside boxed labels. Both reference one navigation entity, which holds
// one copy of each item, so each reference renders the items it was captured with.
$menu = static fn (string $class, string $item, string $label): string => '<nav class="' . $class . '"><ul class="menu">'
    . implode('', array_map(static fn (string $href, string $text): string => '<li' . $item . '><a class="link" href="' . $href . '">' . sprintf($label, $text) . '</a></li>', array('/', '/about/', '/contact/'), array('Home', 'About', 'Contact')))
    . '</ul></nav>';
$page = static fn (string $title, string $main): string => '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . $title . '</title><style>'
    . '.menu{display:flex;margin:0;padding:0;list-style:none}#page-menu .box{width:100px;height:50px}</style></head><body><header>'
    . $menu('site', ' style="width:75px;height:67px"', '%s') . '</header><main>' . $main . '</main></body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $page('Home', '<p>Home body.</p>'),
    'about/index.html' => $page('About', '<div id="page-menu">' . $menu('page', '', '<div class="box"><span>%s</span></div>') . '</div><p>About body.</p>'),
)))->toArray()['source_reports']['wordpress_site_plan'];

$menus = array_values(array_filter($plan['menus'], static fn (array $menu): bool => is_string($menu['token'] ?? null)));
$assert(1 === count($menus), 'The header and page menus reference one navigation entity.');
preg_match_all('/<!--\s*wp:navigation-link\s+(\{.*?\})\s*\/-->/s', $menus[0]['block_markup'], $matches);
$entityItems = array_map(static fn (string $json): array => json_decode($json, true), $matches[1]);

// The items a reference renders: its recorded presentation, else the entity's.
$rendered = array();
foreach (array_merge($plan['template_parts'], $plan['pages']) as $document) {
    preg_match_all('/<!--\s*wp:navigation\s+(\{.*?\})\s*\/-->/s', (string) $document['canonical_block_markup'], $references);
    foreach ($references[1] as $json) {
        $attrs = json_decode($json, true);
        $own = $attrs['metadata']['blocksEngineNavigationItems'] ?? null;
        $rendered[str_contains($attrs['className'] ?? '', 'site') ? 'header' : 'page'] = array_map(static fn (array $item, ?array $presentation): array => array(
            'class' => (string) (null === $presentation ? ($item['className'] ?? '') : ($presentation['className'] ?? '')),
            'itemStyle' => (string) (null === $presentation ? ($item['metadata']['blocksEngineNavigationAnchor']['itemStyle'] ?? '') : ($presentation['blocksEngineNavigationAnchor']['itemStyle'] ?? '')),
        ), $entityItems, is_array($own) ? $own : array_fill(0, count($entityItems), null));
    }
}
$assert(array('header', 'page') === array_keys($rendered) || array('page', 'header') === array_keys($rendered), 'The header and the page each reference the entity.');
foreach ($rendered['header'] as $item) $assert(str_contains($item['itemStyle'], 'width:75px') && str_contains($item['itemStyle'], 'height:67px'), 'Header items keep their inline 75x67 size.');
foreach ($rendered['page'] as $item) {
    $assert('' === $item['itemStyle'], "Page menu items do not take the header's inline item size.");
    $assert(in_array('box', explode(' ', $item['class']), true), 'Page menu items keep their boxed label presentation.');
}

$bootstrap = (string) (array_column($plan['writes'], null, 'target_path')['functions.php']['payload']['data'] ?? '');
$assert(str_contains($bootstrap, "'block_core_navigation_render_inner_blocks'"), 'The theme bootstrap applies reference item presentation.');

echo "Shared navigation entity reference item presentation contract passed.\n";
