<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

// #5018: a page menu repeats the header's links, so both reference one
// navigation entity, but each styles its labels differently. Label typography
// binds to markers inside the label markup, so each reference renders its own.
$menu = static fn (string $id): string => '<nav id="' . $id . '"><ul class="menu">'
    . implode('', array_map(static fn (string $href, string $text): string => '<li><a href="' . $href . '"><span class="box"><span class="lbl">' . $text . '</span></span></a></li>', array('/', '/about/', '/contact/'), array('Home', 'About', 'Contact')))
    . '</ul></nav>';
$page = static fn (string $title, string $main): string => '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . $title . '</title><style>.menu{display:flex;margin:0;padding:0;list-style:none}'
    . '#site .lbl{font-family:Georgia,serif;font-size:14px}#page-menu .lbl{font-family:"Courier New",monospace;font-size:18px}</style></head><body><header>' . $menu('site') . '</header><main>' . $main . '</main></body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $page('Home', '<p>Home body.</p>'),
    'about/index.html' => $page('About', $menu('page-menu') . '<p>About body.</p>'),
)))->toArray()['source_reports']['wordpress_site_plan'];

$menus = array_values(array_filter($plan['menus'], static fn (array $menu): bool => is_string($menu['token'] ?? null)));
$assert(1 === count($menus), 'The header and page menus reference one navigation entity.');
preg_match_all('/<!--\s*wp:navigation-link\s+(\{.*?\})\s*\/-->/s', $menus[0]['block_markup'], $matches);
$entityLabels = array_map(static fn (string $json): string => (string) (json_decode($json, true)['label'] ?? ''), $matches[1]);

// The labels a reference renders: its recorded presentation, else the entity's.
$rendered = array();
foreach (array_merge($plan['template_parts'], $plan['pages']) as $document) {
    preg_match_all('/<!--\s*wp:navigation\s+(\{.*?\})\s*\/-->/s', (string) $document['canonical_block_markup'], $references);
    foreach ($references[1] as $json) {
        $attrs = json_decode($json, true);
        $own = $attrs['metadata']['blocksEngineNavigationItems'] ?? null;
        $labels = array_map(static fn (string $label, ?array $presentation): string => (string) ($presentation['label'] ?? $label), $entityLabels, is_array($own) ? $own : array_fill(0, count($entityLabels), null));
        $rendered['page-menu' === ($attrs['anchor'] ?? '') ? 'page' : 'header'] = implode("\n", $labels);
    }
}
$assert(isset($rendered['header'], $rendered['page']), 'The header and the page menu each reference the entity.');

$css = implode("\n", array_map(static fn (array $write): string => (string) $write['payload']['data'], array_filter($plan['writes'], static fn (array $write): bool => str_ends_with($write['target_path'], '.css'))));
$markers = static function (string $family) use ($css): array {
    preg_match_all('/[^{}]*\{[^}]*' . preg_quote($family, '/') . '[^}]*\}/', $css, $rules);
    preg_match_all('/blocks-engine-(?:richtext|attribute)-[a-f0-9]{12}-\d+/', implode("\n", $rules[0]), $found);
    return array_values(array_unique($found[0]));
};
$binds = static fn (array $markers, string $labels): bool => array() !== array_filter($markers, static fn (string $marker): bool => str_contains($labels, $marker));
$assert($binds($markers('Georgia'), $rendered['header']), 'Header labels render the header label typography.');
$assert($binds($markers('Courier New'), $rendered['page']), 'Page menu labels render their own label typography.');
$assert(! $binds($markers('Georgia'), $rendered['page']), "Page menu labels do not take the header's label typography.");

echo "Shared navigation entity reference label presentation contract passed.\n";
