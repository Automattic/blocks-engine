<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};

// Reduction of the issue's source page: a hover-opened dropdown whose parent
// paints no indicator of its own — no generated caret, no icon element — so
// every submenu parent in the source is indicator-free.
$source = (string) file_get_contents(dirname(__DIR__) . '/fixtures/navigation-submenu-source-indicator.html');
$result = (new HtmlTransformer())->transform($source)->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');

$assert(str_contains($markup, '<!-- wp:navigation '), 'The landmark lowers to core navigation.');
$assert(str_contains($markup, '<!-- wp:navigation-submenu '), 'The hover-opened dropdown lowers to a native submenu.');
preg_match('/<!-- wp:navigation (\{.*?\}) -->/s', $markup, $match);
$attrs = json_decode((string) ($match[1] ?? ''), true) ?: array();
$assert(false === ($attrs['showSubmenuIcon'] ?? true), 'An indicator-free source submenu leaves core its no-indicator projection.');
preg_match('/<!-- wp:navigation-submenu (\{.*?\}) /s', $markup, $submenuMatch);
$submenuAttrs = json_decode((string) ($submenuMatch[1] ?? ''), true) ?: array();
$assert('Info' === ($submenuAttrs['label'] ?? ''), 'The submenu keeps the source label.');
$assert(str_contains($markup, '/info/dogs/'), 'The submenu destinations survive.');

// A popup-announcing link label is a hover target when the source reveals
// its submenu on the item's hover/focus. A label that is itself the toggle
// (a button, or a link declared one) lowers to core's click mode: the label
// renders as a focusable toggle button, core's icon is hidden and the hover
// reveal restated. A toggle label the source opens only on click keeps
// core's indicator in hover mode.
$folderPage = static fn (string $label, string $reveal): string => '<!doctype html><html><head><style>'
    . '#top nav ul li.folder .subnav{position:absolute;height:0;overflow:hidden}' . $reveal
    . '</style></head><body><header><div id="top"><nav class="main-nav"><ul>'
    . '<li><a href="/">Home</a></li>'
    . '<li class="folder">' . $label . '<div class="subnav"><ul><li><a href="/about/">About</a></li><li><a href="/jobs/">Jobs</a></li></ul></div></li>'
    . '</ul></nav></div></header><main><h1>Home</h1></main></body></html>';
$hover = '#top nav ul li.folder:hover .subnav,#top nav ul li.folder:focus .subnav{height:auto !important}';
$cases = array(
    'hover-revealed link label' => array( '<a aria-haspopup="true" href="#">How we go</a>', $hover, 'no-icon' ),
    'button-role link label' => array( '<a aria-haspopup="true" role="button" href="#">How we go</a>', $hover, 'click' ),
    'button label' => array( '<button aria-expanded="false" aria-controls="sub">How we go</button>', $hover, 'click' ),
    'click-only button label' => array( '<button aria-expanded="false" aria-controls="sub">How we go</button>', '', 'icon' ),
);
foreach ( $cases as $case => [$label, $reveal, $mode] ) {
    $folderMarkup = (string) ((new HtmlTransformer())->transform($folderPage($label, $reveal))->toArray()['serialized_blocks'] ?? '');
    preg_match('/<!-- wp:navigation (\{.*?\}) -->/s', $folderMarkup, $folderMatch);
    $folderAttrs = json_decode((string) ($folderMatch[1] ?? ''), true) ?: array();
    $actual = true === ($folderAttrs['openSubmenusOnClick'] ?? false) && str_contains((string) ($folderAttrs['className'] ?? ''), 'blocks-engine-label-toggle-submenus')
        ? 'click' : ( false === ($folderAttrs['showSubmenuIcon'] ?? true) ? 'no-icon' : 'icon' );
    $assert(str_contains($folderMarkup, '<!-- wp:navigation-submenu '), "{$case}: lowers to a native submenu.");
    $assert($mode === $actual, "{$case}: expected {$mode}, got {$actual}.");
}

echo "navigation submenu source indicator contract passed\n";
