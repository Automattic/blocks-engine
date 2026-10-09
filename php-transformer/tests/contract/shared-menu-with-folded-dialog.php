<?php
declare(strict_types=1);

/**
 * Pages that repeat one header whose captured mobile menu folds into its
 * navigation must still share one header part and one navigation entity.
 *
 * Folding removes the dialog that used to sit beside the header's contents, and
 * that sibling decided where the shell search stopped. Some pages also put empty
 * decoration blocks ahead of the header. Those pages must find the same header
 * as the pages that do not.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

$css = '.hidden{display:none}.flex{display:flex}.items-center{align-items:center}.justify-between{justify-content:space-between}'
    . '.gap-8{gap:2rem}.gap-3{gap:.75rem}.cta{display:inline-block;padding:8px 24px;border-radius:999px;background:#036;color:#fff}'
    . '@media (min-width:768px){.md\\:flex{display:flex}.md\\:hidden{display:none}}';
$item = static fn (string $id, string $label, string $links): string =>
    '<div class="relative"><button id="' . $id . '" type="button" aria-haspopup="menu" aria-controls="p-' . $id . '" aria-expanded="false" data-dla-dialog-trigger="p-' . $id . '">' . $label . '</button>'
    . '<div class="panel dla-dialog dla-dropdown" hidden id="p-' . $id . '" data-dla-dialog-panel="p-' . $id . '">' . $links . '</div></div>';
$switcher = '<div class="lang" aria-label="Switch language"><button type="button" aria-pressed="true">EN</button><span>|</span><button type="button" aria-pressed="false">ES</button></div>';
$accordion = static fn (string $label): string => '<div><button class="acc">' . $label . '</button></div>';
$decor = '<style>.deco{position:absolute;top:0;left:0;width:10px;height:10px;background:#eee}</style>';
$page = static fn (string $title, bool $decorated): string => '<style>' . $css . '</style>' . ($decorated ? $decor . '<div class="deco"></div><div class="deco"></div>' : '')
    . '<nav class="bar"><div class="wrap flex items-center justify-between"><a href="/" class="logo"><img src="logo.png" alt="Acme"></a>'
    . '<div class="hidden md:flex items-center gap-8">'
    . $item('shop', 'Shop', '<a href="/new">New in</a><a href="/sale">Sale</a>') . $item('help', 'Help', '<a href="/faq">FAQ</a><a href="/contact">Contact us</a>')
    . '<a href="/about">About</a>' . $switcher . '<a class="cta" href="/book">Book now</a></div>'
    . '<div class="md:hidden flex items-center gap-3">' . $switcher
    . '<button type="button" id="burger" aria-label="Menu" aria-haspopup="menu" aria-expanded="false" aria-controls="dlg" data-dla-dialog-trigger="dla-dialog-4"><svg viewBox="0 0 4 4"><path d="M0 0h4"/></svg></button></div></div>'
    . '<dialog id="dlg" data-blocks-engine-captured-dialog="true" data-blocks-engine-triggers="burger" class="md:hidden dla-dialog dla-dropdown" data-blocks-engine-presentation="dropdown" data-blocks-engine-placement="in-place" data-blocks-engine-captured-menu="true">'
    . $accordion('Shop') . $accordion('Help') . '<a href="/about">About</a><a class="cta" href="/book">Book now</a></dialog></nav>'
    . '<main><h1>' . $title . '</h1><p>' . $title . ' page.</p></main><footer><p>Acme</p></footer>';

$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $page('Home', false),
    'about.html' => $page('About', true),
    'contact.html' => $page('Contact', true),
)))->toArray()['source_reports']['wordpress_site_plan'];

$menus = array_values(array_filter($plan['menus'] ?? array(), static fn(array $menu): bool => is_string($menu['token'] ?? null) && is_string($menu['block_markup'] ?? null)));
$headers = array_values(array_filter($plan['template_parts'] ?? array(), static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($menus), 'Three pages with the same folded menu share one navigation entity, got ' . count($menus) . '.');
$assert(str_contains((string) $menus[0]['block_markup'], 'blocks-engine-menu-fold-overlay-only'), 'The entity carries the overlay-only call to action.');
$assert(1 === count($headers), 'The repeated header becomes one header part.');
$markup = (string) ($headers[0]['canonical_block_markup'] ?? '');
$assert(str_contains($markup, '"overlayMenu":"mobile"') && ! str_contains($markup, 'captured-dialog'), 'The header part holds the responsive navigation and no dialog block.');
foreach ($plan['pages'] as $planPage) {
    $content = (string) ($planPage['block_markup'] ?? $planPage['canonical_block_markup'] ?? '');
    $assert(! str_contains($content, 'wp:navigation '), 'A page does not keep its own copy of the menu: ' . ($planPage['source_path'] ?? '?'));
}
echo "Shared folded menu contract passed\n";
