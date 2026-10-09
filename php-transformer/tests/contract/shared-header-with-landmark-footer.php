<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

// A site whose header is an unlabelled bar (a `nav` holding the logo, a menu row with dropdown
// buttons, a lone route link and a language switcher) and whose footer is a real `footer`
// landmark. Every page also starts with empty decorative blocks, and each page's own link is
// the "current" one. All four pages share one header.
$item = static fn (string $id, string $label, string $links): string =>
    '<div class="item"><button id="' . $id . '" type="button" aria-haspopup="menu" aria-controls="p-' . $id . '" aria-expanded="false" data-dla-dialog-trigger="p-' . $id . '">' . $label . '</button>'
    . '<div class="panel dla-dialog dla-dropdown" hidden id="p-' . $id . '" data-dla-dialog-panel="p-' . $id . '">' . $links . '</div></div>';
$page = static fn (string $title): string => '<!doctype html><html><head><style>.glow{position:absolute;top:0;width:200px;height:200px}.row{display:flex;gap:24px}.inner{display:flex;justify-content:space-between}.bar{position:fixed;top:0;left:0;right:0}.mobile{display:none}.menu-link{text-decoration:none}.panel a{display:block;padding:8px 16px;font-size:14px;color:#333}.lone{font-size:14px;font-weight:500;letter-spacing:.02em;color:#333}</style></head><body>'
    . '<div id="root"><div class="shell"><div class="fade"><div class="glow"></div><div class="glow"></div>'
    . '<nav class="bar"><div class="inner"><a href="/index.html" class="logo"><span>Acme</span></a><div class="row">'
    . $item('shop', 'Shop', '<a class="menu-link" href="/index.html">Start</a><a class="menu-link" href="/about/index.html">Team</a><a class="menu-link" href="/pricing/index.html">Pricing</a>')
    . '<a href="/contact/index.html" class="lone">Contact</a>'
    . '<div class="lang"><button type="button" aria-pressed="true">EN</button><span>|</span><button type="button" aria-pressed="false">ES</button></div></div>'
    . '<div class="mobile"><button type="button" aria-label="Menu" aria-haspopup="menu" aria-controls="m-menu" aria-expanded="false" data-dla-dialog-trigger="m-menu">Menu</button></div></div>'
    . '<div class="mpanel dla-dialog" hidden id="m-menu" data-dla-dialog-panel="m-menu"><div><button type="button">Shop</button></div><a href="/contact/index.html">Contact</a></div></nav>'
    . '<main><h1>' . $title . '</h1><p>Body of ' . $title . '</p></main>'
    . '<footer class="foot"><div class="foot-in"><p>Acme footer</p><a href="/contact/index.html">Write to us</a></div></footer></div></div></div></body></html>';
$artifact = array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $page('Home'),
    'about/index.html' => $page('About'),
    'contact/index.html' => $page('Contact'),
    'pricing/index.html' => $page('Pricing'),
));
$result = (new ArtifactCompiler())->compile($artifact)->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? null;
$assert(is_array($plan), 'The fixture compiles to a site plan: ' . json_encode(array_column($result['diagnostics'] ?? array(), 'code')));

$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$diagnostics = array();
foreach ($plan['diagnostics'] as $diagnostic) if ('header' === ($diagnostic['area'] ?? null)) $diagnostics[] = $diagnostic['code'] . ':' . ($diagnostic['provenance']['reason'] ?? '');
$assert(1 === count($headers), 'The shared header becomes one template part: ' . implode(',', $diagnostics));
$menus = array_values(array_filter($plan['menus'], static fn(array $menu): bool => 'navigation_entity' === ($menu['source_relation'] ?? null)));
$assert(1 === count($menus), 'The four pages share one navigation entity, got ' . count($menus));
foreach ($plan['pages'] as $document) {
    $markup = (string) ($document['canonical_block_markup'] ?? '');
    $assert(! str_contains($markup, '<!-- wp:navigation'), $document['source_path'] . ' no longer carries its own navigation');
    $assert(1 === substr_count($markup, '"slug":"header"'), $document['source_path'] . ' binds the shared header once');
    $assert(str_contains($markup, 'Body of'), $document['source_path'] . ' keeps its own content');
}
$assert(str_contains($headers[0]['canonical_block_markup'], '>EN<') && str_contains($menus[0]['block_markup'], '"label":"Contact"'), 'The part keeps the switcher and the menu keeps the lone link');

echo "Shared header with landmark footer contract passed\n";
