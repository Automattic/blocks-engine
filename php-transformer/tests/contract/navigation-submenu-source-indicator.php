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

// A popup-announcing label that leads nowhere is a hover target when the
// source reveals its submenu on the item's hover/focus; declared as a button,
// it stays a disclosure control and keeps core's indicator.
$folderPage = static fn (string $role): string => '<!doctype html><html><head><style>'
    . '#top nav ul li.folder .subnav{position:absolute;height:0;overflow:hidden}'
    . '#top nav ul li.folder:hover .subnav,#top nav ul li.folder:focus .subnav{height:auto !important}'
    . '</style></head><body><header><div id="top"><nav class="main-nav"><ul>'
    . '<li><a href="/">Home</a></li>'
    . '<li class="folder"><a aria-haspopup="true"' . $role . ' href="#">How we go</a><div class="subnav"><ul><li><a href="/about/">About</a></li><li><a href="/jobs/">Jobs</a></li></ul></div></li>'
    . '</ul></nav></div></header><main><h1>Home</h1></main></body></html>';
foreach ( array( 'hover-revealed label' => array( '', false ), 'button-role toggle' => array( ' role="button"', true ) ) as $case => [$role, $showsIcon] ) {
    $folderMarkup = (string) ((new HtmlTransformer())->transform($folderPage($role))->toArray()['serialized_blocks'] ?? '');
    preg_match('/<!-- wp:navigation (\{.*?\}) -->/s', $folderMarkup, $folderMatch);
    $folderAttrs = json_decode((string) ($folderMatch[1] ?? ''), true) ?: array();
    $assert(str_contains($folderMarkup, '<!-- wp:navigation-submenu '), "{$case}: lowers to a native submenu.");
    $assert($showsIcon === (false !== ($folderAttrs['showSubmenuIcon'] ?? true)), "{$case}: " . ($showsIcon ? 'keeps' : 'drops') . " core's submenu indicator.");
}

echo "navigation submenu source indicator contract passed\n";
