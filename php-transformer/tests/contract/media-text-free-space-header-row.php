<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

// A header bar spreads a linked logo and an action button to opposite ends
// of a flex row (`justify-content: space-between`, neither item grows).
// core/media-text cannot keep that: its two grid tracks always fill the row,
// so on the frontend the button lands inside a stretched content pane, and in
// the editor the media column takes the full row width and pushes the button
// out of view. The row must stay an authored group of an editable image and
// buttons, with the author flex distribution kept.
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
};

$result = (new HtmlTransformer())->transform((string) file_get_contents(__DIR__ . '/../fixtures/media-text-free-space-header-row/index.html'))->toArray();
$blocks = $result['blocks'];
$markup = (string) $result['serialized_blocks'];
$css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));

$assert(!str_contains($markup, '<!-- wp:media-text'), 'The header row is not lowered to core/media-text.');
$row = $blocks[0]['innerBlocks'][0] ?? array();
$assert('core/group' === ($row['blockName'] ?? null) && str_contains((string) ($row['attrs']['className'] ?? ''), 'bar-inner'), 'The row stays a group carrying its author class.');
$assert(array('core/image', 'core/buttons') === array_column($row['innerBlocks'] ?? array(), 'blockName'), 'The logo and the action are an editable image and buttons inside the row.');
$assert(str_contains((string) ($row['innerBlocks'][0]['innerHTML'] ?? ''), 'href="/index.html"'), 'The logo keeps its link.');
$assert(1 === preg_match('/\.bar-inner\s*\{[^}]*display:flex[^}]*justify-content:space-between/', $css), 'The row keeps its author flex distribution.');
$assert(array() === $result['fallbacks'], 'No HTML fallback.');
$assert('pass' === ((new Runtime())->validateBlockSerialization($blocks)['status'] ?? null), 'The output passes block serialization validity.');

// The media-text gate is specific to free-space distribution: the same row
// whose panes split the width still lowers to core/media-text.
$split = str_replace(
    array('justify-content: space-between; }', '.logo-link { display: inline-flex; }'),
    array('}', '.logo-link { display: inline-flex; flex: 1; } .cta { flex: 1; }'),
    (string) file_get_contents(__DIR__ . '/../fixtures/media-text-free-space-header-row/index.html')
);
$splitMarkup = (string) (new HtmlTransformer())->transform($split)->toArray()['serialized_blocks'];
$assert(str_contains($splitMarkup, '<!-- wp:media-text'), 'A row whose panes grow to split the width still lowers to core/media-text.');
