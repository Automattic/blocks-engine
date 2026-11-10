<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

// A site builder's header: a bar of links plus a "More" overflow submenu
// that lists every link again, tracking attributes and ids numbered per
// route, the served route's link marked only by a class swap, and the More
// trigger underlined (extra anchor classes) when the served route is one of
// the overflowed links. The front page has a header of its own design whose
// links rest differently. The More label is a stylesheet-styled span, so its
// projected label carries a generated semantic marker that differs by route.
// The bar's served link also swaps atomic utility classes (no state word in
// them): the linked stylesheet underlines it through a class.
// The header also keeps the compact menu a capture recorded: a trigger the
// producer numbers per route, bound to a modal dialog that rests hidden and
// holds the links again, the served one again marked only by a class swap and
// a tracking value. The producer ships the panel's resting and opened HTML as
// snapshot attributes on the panel and trigger.
// The capture swept the viewport too: the builder's overflow script toggles a
// class on the bar items with the width (no rule names it), and the capture
// carried each toggle as a width-scoped alias whose name encodes the
// breakpoint it measured on that route, so the names differ by route. One
// alias the stylesheet does name (the More item's spacing) reads the same on
// every route.
// The three routes share one header design, so they share one header part.
$widthAlias = static fn (string $route, string $href): string => 'data-blocks-engine-capture-width-class-' . substr(hash('sha256', $route . "\n" . $href), 0, 12);
$link = static fn (string $href, string $label, string $route, string $copy): string => '<li' . ('bar' === $copy ? ' class="nav-item ' . $widthAlias($route, $href) . '"' : '') . '><a href="' . $href . '" class="' . ($href === $route ? "{$copy}-on" : "{$copy}-off") . ('bar' === $copy ? ($href === $route ? ' x-a x-u' : ' x-a x-r') : '') . '">' . $label . '</a></li>';
$modal = static function (string $route, int $number) use ($link): string {
    $key = 'data-blocks-engine-capture-dialog-0';
    $items = '';
    foreach (array('/' => 'Home', '/a/' => 'Route A', '/b/' => 'Route B', '/c/' => 'Route C') as $href => $label) {
        $items .= str_replace(array('<li><a ', '">' . $label . '<'), array('<li role="menuitem"><a role="link" ', '" data-track="Modal.Link.' . ($href === $route ? 'Active' : 'Default') . '.' . $number . '.click"><span>' . $label . '</span><'), $link($href, $label, $route, 'modal'));
    }
    $sheet = '<div role="navigation" id="menu-' . $number . '" class="sheet"><div class="sheet-top"><svg viewBox="0 0 24 24" width="40" height="40" class="sheet-close" data-blocks-engine-capture-dialog-close="' . $key . '" role="button" tabindex="0"><path d="M4 4l16 16M20 4L4 20"></path></svg></div>'
        . '<div class="sheet-body"><div class="sheet-links"><ul role="menu" id="menu-list-' . $number . '" class="sheet-list">' . $items . '</ul><div class="sheet-foot"></div></div></div></div>';
    $resting = htmlspecialchars((string) json_encode(array('resting' => array('html' => $sheet, 'style' => null, 'class' => 'data-blocks-engine-capture-dialog', 'hidden' => true))), ENT_QUOTES);
    $opened = htmlspecialchars((string) json_encode(array('html' => str_replace('class="sheet"', 'class="sheet" style="transform: translateX(0px);"', $sheet), 'style' => null, 'class' => 'data-blocks-engine-capture-dialog')), ENT_QUOTES);
    return '<div class="burger-row"><span class="burger-host"><a role="button" aria-haspopup="menu" aria-expanded="false" aria-label="Open menu" href="#" id="' . ($number % 10) . '" class="burger" data-blocks-engine-capture-dialog-panel-state="' . $opened . '" data-blocks-engine-capture-dialog-trigger="' . $key . '" aria-controls="' . $key . '"><svg viewBox="0 0 24 24" width="24" height="24"><path d="M4 8h16V6H4z"></path></svg></a></span>'
        . '<div class="data-blocks-engine-capture-dialog" role="dialog" aria-modal="true" hidden id="' . $key . '" data-blocks-engine-capture-dialog-panel="' . $key . '" data-blocks-engine-capture-existing-panel="' . $resting . '">' . $sheet . '</div></div>';
};
$runtime = trim((string) file_get_contents(dirname(__DIR__) . '/fixtures/capture-disclosure-runtime.js'));
$page = static function (string $route, string $title, int $number, bool $overflowed) use ($link, $modal, $runtime): string {
    $links = array('/' => 'Home', '/a/' => 'Route A', '/b/' => 'Route B', '/c/' => 'Route C');
    $bar = ''; $drawer = '';
    foreach ($links as $href => $label) { $bar .= $link($href, $label, $route, '/' === $route ? 'hero' : 'bar'); $drawer .= $link($href, $label, $route, 'drawer'); }
    $header = '/' === $route
        ? '<header class="hero"><div class="hero-inner"><a href="/" class="brand">Acme</a><nav><ul>' . $bar . '</ul></nav></div></header>'
        : '<header class="bar"><a href="/" id="logo-' . $number . '" data-track="Logo.Link.' . $number . '.click" class="brand"><p>Acme</p></a>'
            . '<nav><ul>' . $bar . '<li class="more data-blocks-engine-capture-width-class-0123456789ab"><a href="#" class="more-link' . ($overflowed ? ' u-line u-pad' : '') . '"><span class="more-label">More</span></a><ul>' . $drawer . '</ul></li></ul></nav>' . $modal($route, $number) . '</header>';
    return '<!doctype html><html><head><meta charset="utf-8"><title>' . $title . '</title><link rel="stylesheet" href="/style.css"><script data-blocks-engine-capture-disclosure-runtime="true">' . $runtime . '</script></head><body>'
        . $header . '<main class="content"><h1>' . $title . '</h1><p>' . $title . ' body text.</p></main>'
        . '<footer class="foot"><p>Copyright 2026 Acme. All rights reserved.</p></footer></body></html>';
};
// The capture probed each route's trigger: clicking it opened the drawer as a modal.
$routes = array('/' => 'index.html', '/a/' => 'a/index.html', '/b/' => 'b/index.html', '/c/' => 'c/index.html');
$probes = array();
foreach (array('/a/' => 202, '/b/' => 303, '/c/' => 404) as $route => $number) {
    $id = (string) ($number % 10);
    $probes[] = array('sourceUrl' => 'https://example.test' . $route, 'components' => array(array('selector' => '#\\3' . $id . ' ', 'kind' => 'menu', 'initial' => 'closed', 'status' => 'captured',
        'states' => array(array('id' => 'closed', 'html' => '', 'evidence' => array('status' => 'captured',
            'trigger' => array('selector' => '#\\3' . $id . ' ', 'tag' => 'a', 'id' => $id, 'role' => 'button', 'ariaHaspopup' => 'menu', 'label' => 'Open menu', 'dataBindings' => array()),
            'dialog' => array('selector' => '.sheet', 'tag' => 'div', 'role' => 'navigation', 'ariaModal' => false, 'presentation' => 'modal')))),
        'transitions' => array(array('from' => 'closed', 'to' => 'open', 'trigger' => array('selector' => '#\\3' . $id . ' ', 'action' => 'click'))))));
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'capture-receipt.json' => json_encode(array('schema' => 'blocks-engine-capture/capture-receipt/v1', 'routes' => array_map(static fn(string $route, string $path): array => array('url' => 'https://example.test' . $route, 'path' => $path), array_keys($routes), $routes))),
    'interaction-states.json' => json_encode(array('schema' => 'blocks-engine-capture/captured-interactions/v3', 'pages' => $probes)),
    'index.html' => $page('/', 'Welcome home', 101, false),
    'a/index.html' => $page('/a/', 'Route A', 202, false),
    'b/index.html' => $page('/b/', 'Route B', 303, true),
    'c/index.html' => $page('/c/', 'Route C', 404, true),
    'style.css' => '.bar,.hero{display:flex;padding:16px;background:#222}.hero-inner{display:flex;gap:8px}.brand{color:#fff}'
        . '.hero-off{color:#eee}.hero-on{color:#0ff}.bar-off{color:#fff}.bar-on{color:#ff0}.drawer-off{color:#ccc}.drawer-on{color:#f0f}.more-link{color:#fff}.u-line{border-bottom:1px solid}.u-pad{padding-bottom:4px}.more-label{margin-right:4px}.x-a{line-height:1.5}.x-r{padding-bottom:6px}.x-u{border-bottom:2px solid #f00}'
        . '.burger{display:none}.modal-off{color:#ddd}.modal-on{color:#0f0}@media (max-width:767px){.burger{display:block;width:24px;height:24px}}'
        . '@media (min-width:1200px){.data-blocks-engine-capture-width-class-0123456789ab{margin-left:8px}}',
)))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);

$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers), 'Routes sharing one header design share one header part, got ' . count($headers));
$adopted = $headers[0]['placement']['source_paths'] ?? array_keys($headers[0]['provenance']['sources'] ?? array());
sort($adopted);
$assert(array('a/index.html', 'b/index.html', 'c/index.html') === $adopted, 'The header part serves the routes of its design: ' . json_encode($adopted));
foreach ($plan['pages'] as $routePage) {
    if ('index.html' === $routePage['source_path']) continue;
    $assert(!str_contains($routePage['canonical_block_markup'], 'wp:navigation'), "{$routePage['source_path']} keeps no inline header.");
}

// No route's state is baked into the shared menu: links and the overflow trigger rest.
$menus = array_values(array_filter(array_column($plan['menus'], 'block_markup'), static fn(string $menu): bool => str_contains($menu, 'wp:navigation-submenu')));
$assert(1 === count($menus), 'One overflow navigation entity serves its routes, got ' . count($menus));
$assert(!str_contains($menus[0], '-on') && !str_contains($menus[0], 'u-line') && !str_contains($menus[0], 'u-pad'), 'Every menu item carries its resting presentation: ' . $menus[0]);
$assert(str_contains($menus[0], 'more-link') && str_contains($menus[0], 'drawer-off'), 'The overflow trigger and its links keep their own resting classes.');

// The part was taken from one route, yet its stylesheet underlines no item by
// position: the served link's class-borne underline follows Core's current item.
$underlines = array();
foreach ($plan['assets'] as $asset) {
    if ('css' !== ($asset['kind'] ?? null)) continue;
    preg_match_all('/([^{}]+)\{[^{}]*border-bottom:\s*2px solid #f00[^{}]*\}/i', (string) ($asset['content'] ?? ''), $matches);
    array_push($underlines, ...array_filter($matches[1], static fn(string $selector): bool => str_contains($selector, "wp-block-navigation")));
}
$assert(array() !== $underlines, 'The served link keeps its class-borne underline.');
foreach ($underlines as $selector) {
    $assert(!str_contains($selector, ':nth-child(') && (str_contains($selector, 'aria-current') || str_contains($selector, 'current-menu-item')), 'The underline follows the served route, not one captured item: ' . trim($selector));
}
// The compact menu travels with the part: its trigger opens the dialog, and
// neither the producer's panel snapshot nor a served link's look stays behind.
$part = (string) $headers[0]['canonical_block_markup'];
$assert(1 === preg_match('/<!-- wp:group (\{"metadata":\{"blocksEngineDialog".*?\}) -->/', $part, $dialog), 'The header part keeps the captured dialog.');
$dialogAttrs = json_decode($dialog[1], true);
$assert(1 === preg_match('/<a\b[^>]*data-blocks-engine-capture-dialog-trigger[^>]*>/', $part, $trigger), 'The header part keeps the dialog trigger.');
$assert(str_contains($trigger[0], 'aria-controls="' . $dialogAttrs['metadata']['blocksEngineDialog']['attributes']['id'] . '"'), 'The trigger controls the dialog: ' . $trigger[0]);
$assert(!str_contains($part, 'data-blocks-engine-capture-dialog-panel-state'), 'No captured panel snapshot stays on the trigger.');
$document = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$document->loadHTML('<?xml encoding="UTF-8"><html><body>' . $part . '</body></html>');
libxml_clear_errors(); libxml_use_internal_errors($previous);
$bindings = array();
foreach ($document->getElementsByTagName('a') as $anchor) {
    if (!$anchor->hasAttribute('data-blocks-engine-route-state')) continue;
    $bindings[$anchor->getAttribute('href')] = json_decode($anchor->getAttribute('data-blocks-engine-route-state'), true);
    $anchor->removeAttribute('data-blocks-engine-route-state');
}
foreach (array('/a', '/b', '/c') as $href) {
    $assert('modal-on' === ($bindings[$href]['current']['class'] ?? null) && 'modal-off' === ($bindings[$href]['rest']['class'] ?? null), "The dialog link to {$href} follows the served route.");
}
$resting = (string) $document->saveHTML();
$assert(!str_contains($resting, 'modal-on') && !str_contains($resting, 'Modal.Link.Active'), 'No route bakes its served dialog link into the part.');
// Width aliases no rule names carry no presentation; the alias a rule names stays.
$assert(!preg_match('/data-blocks-engine-capture-width-class-(?!0123456789ab)[a-f0-9]{12}/', $part . $menus[0]), 'No unstyled per-route width alias reaches the shared header.');
$assert(str_contains($part . $menus[0], 'data-blocks-engine-capture-width-class-0123456789ab'), 'A width alias a rule names keeps its class.');
echo "overflow-menu-route-state-shared-header: ok\n";
