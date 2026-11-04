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

echo "navigation submenu source indicator contract passed\n";
