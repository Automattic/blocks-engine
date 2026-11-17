<?php
declare(strict_types=1);

/**
 * A row navigation in a page with desktop and mobile device documents holds
 * items with nested submenu lists (hidden at rest) and a text-labelled captured
 * dialog trigger wired to an in-place panel that its own runtime still owns.
 * Explicit `aria-controls` ownership outranks proximity: the trigger must not
 * borrow the burger's hidden drawer navigation as a vertical navigation in the
 * row's own slot. That borrowed menu turned a one-line row into a tall block.
 */

require getenv('BLOCKS_ENGINE_TEST_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$document = static function (string $suffix, string $kind): string {
    $menu = '<ul class="menu-row">'
        . '<li><a href="/">Home</a></li>'
        . '<li aria-haspopup="true"><a href="/about">About</a><ul class="menu-sub"><li><a href="/team">Team</a></li><li><a href="/faq">FAQ</a></li></ul></li>'
        . '<li><button type="button" class="menu-item" aria-expanded="false" aria-controls="panel' . $suffix . '" data-blocks-engine-capture-dialog-trigger="panel' . $suffix . '" data-blocks-engine-capture-dialog-ancestor-unverified="ancestor-limit">Services</button></li>'
        . '<li aria-haspopup="true"><a href="/more">More</a><ul class="menu-sub"><li><a href="/gallery">Gallery</a></li><li><a href="/policy">Policy</a></li></ul></li></ul>';

    return '<div class="data-blocks-engine-capture-' . $kind . '-document" data-blocks-engine-capture-document-scope="">'
        . '<header class="site-header"><div class="header-row"><a class="brand" href="/">Brand</a>'
        . '<nav class="row-nav">' . $menu . '</nav>'
        . '<button type="button" class="burger" aria-label="Open menu" aria-controls="drawer' . $suffix . '" aria-expanded="false"></button>'
        . '<a class="phone" href="tel:5550100">555-0100</a></div>'
        . '<div id="drawer' . $suffix . '" class="drawer" aria-hidden="true"><nav class="drawer-nav"><ul><li><a href="/">Home</a></li><li><a href="/about">About</a></li><li><a href="/contact">Contact</a></li></ul></nav></div>'
        . '</header>'
        . '<div class="panel-host"><div id="panel' . $suffix . '" role="menu" class="dropdown data-blocks-engine-capture-dropdown" data-blocks-engine-capture-dialog-panel="panel' . $suffix . '"><section><h2>Services</h2><nav class="panel-nav"><a href="/cuts">Cuts</a><a href="/color">Color</a></nav></section></div></div>'
        . '<main><h1>Welcome</h1><p>Body copy.</p></main></div>';
};
$css = '.row-nav .menu-row{display:flex;gap:16px;list-style:none;margin:0;padding:0}.row-nav li{position:relative}'
    . '.menu-sub{opacity:0;position:absolute;top:100%;left:0;max-height:0}'
    . '.drawer{display:none;position:fixed;inset:0}.burger{display:none;width:30px;height:30px}.dropdown{position:absolute;top:100%}'
    . '@media(max-width:767px){.row-nav{display:none}.burger{display:block}}';
$runtime = trim((string) file_get_contents(dirname(__DIR__) . '/fixtures/capture-disclosure-runtime.js'));
$html = '<!doctype html><html><head><style>' . $css . '</style><script data-blocks-engine-capture-disclosure-runtime="true">' . $runtime . '</script></head><body>'
    . $document('', 'desktop') . $document('--data-blocks-engine-capture-mobile', 'mobile') . '</body></html>';

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(array('path' => 'index.html', 'content' => $html))))->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');

$failures = array();
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) $failures[] = $message;
};

$rows = array();
$items = static function (array $blocks) use (&$items, &$rows): void {
    foreach ($blocks as $block) {
        if ('core/group' === ($block['blockName'] ?? '') && str_contains(' ' . ($block['attrs']['className'] ?? '') . ' ', ' menu-row ')) {
            $rows[] = $block['innerBlocks'] ?? array();
            continue;
        }
        $items($block['innerBlocks'] ?? array());
    }
};
$items((new Runtime())->parseBlocks($markup));
$assert(2 === count($rows), 'Each device document keeps its own row navigation.');

$serialize = static fn (array $block): string => (new Runtime())->serializeBlocks(array($block));
foreach ($rows as $index => $row) {
    $assert(4 === count($row), "Row {$index} keeps all four items.");
    $about = isset($row[1]) ? $serialize($row[1]) : '';
    $trigger = isset($row[2]) ? $serialize($row[2]) : '';
    $assert(str_contains($about, '"url":"/team"') && str_contains($about, '"url":"/faq"'), "Row {$index}: the submenu stays with its own item.");
    $assert(1 === preg_match('/wp:navigation \{"className":"[^"]*menu-sub[^"]*"[^\n]*?"overlayMenu":"never"/', $about), "Row {$index}: the nested submenu stays a non-responsive list navigation.");
    $assert(!str_contains($trigger, '<!-- wp:navigation '), "Row {$index}: the trigger slot holds no borrowed navigation.");
    $assert(str_contains($trigger, 'Services'), "Row {$index}: the trigger keeps its own control.");
}

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "Row nav trigger keeps its own panel in device documents passed\n");
