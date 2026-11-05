<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A device marker directly on an element that lowers to its own native block
// survives as a class with a generated visibility rule, without a wrapper.
$html = (string) file_get_contents(dirname(__DIR__) . '/fixtures/capture-device-marker-native-blocks.html');
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$markup = (string) $result['serialized_blocks'];
$css = implode("\n", array_column(array_filter($result['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
$assert = static function (bool $condition, string $message) use ($markup): void {
    if (! $condition) throw new RuntimeException($message . "\nGot: " . $markup);
};
$assert(! str_contains($markup, '<!-- wp:html'), 'Marked parts lower natively, not to core/html.');
$assert(1 === substr_count($markup, '<!-- wp:custom/layout-shell'), 'Only the document root is a layout shell: marked native blocks gain no wrapper.');
foreach (array(
    '/<!-- wp:heading \{[^}]*blocks-engine-device-only-mobile[^}]*\} -->\s*<h2[^>]*>Phone heading/' => 'heading',
    '/<!-- wp:image \{[^}]*blocks-engine-device-only-mobile[^}]*\} -->/' => 'image',
    '/<!-- wp:paragraph \{[^}]*blocks-engine-device-hide-mobile[^}]*\} -->\s*<p[^>]*>Not on phones/' => 'paragraph',
    '/<!-- wp:buttons \{[^}]*blocks-engine-device-only-mobile[^}]*\} -->/' => 'button',
    '/<!-- wp:heading \{[^}]*blocks-engine-device-only-tablet[^}]*\} -->\s*<h3[^>]*>Tablet heading/' => 'tablet heading',
    '/<!-- wp:tabs \{[^}]*blocks-engine-device-only-mobile[^}]*\} -->/' => 'tabs',
) as $pattern => $part) {
    $assert(1 === preg_match($pattern, $markup), 'The ' . $part . ' keeps its native block and carries its device class.');
}
foreach (array(
    'html[data-blocks-engine-capture-selected-document]:not([data-blocks-engine-capture-selected-document="mobile"]) .blocks-engine-device-only-mobile',
    'html:not([data-blocks-engine-capture-selected-document]) .blocks-engine-device-only-mobile',
    'html[data-blocks-engine-capture-selected-document="mobile"] .blocks-engine-device-hide-mobile',
) as $selector) {
    $assert(str_contains($css, $selector), 'Generated rule covers ' . $selector . '. CSS: ' . $css);
}

// Identical sibling menus for phones and for everything else become one menu
// that collapses on phones. A phone menu with its own links stays separate, and
// so does a pair whose other copy is limited to another device, because merging
// it would leave phones with no menu.
preg_match_all('/<!-- wp:navigation (\{.*?\}) (?:\/)?-->/s', $markup, $navigations);
$assert(4 === count($navigations[1]), 'Only the unrestricted identical pair merges, leaving four menus.');
$attrs = array_map(static fn (string $json): array => (array) json_decode($json, true), $navigations[1]);
$byClass = static fn (string $class): array => array_values(array_filter($attrs, static fn (array $block): bool => str_contains((string) ($block['className'] ?? ''), $class)));
$phone = $byClass('blocks-engine-device-only-mobile');
$assert(2 === count($phone) && str_contains($markup, 'Phone A'), 'The phone menus that stay separate keep their device class.');
$assert(1 === count($byClass('blocks-engine-device-only-desktop')) && 2 === substr_count($markup, '/four/'), 'The desktop-limited copy keeps its restriction and the phone copy survives.');
$merged = array_values(array_filter($attrs, static fn (array $block): bool => ! str_contains((string) ($block['className'] ?? ''), 'blocks-engine-device-')));
$assert(1 === count($merged) && 'mobile' === ($merged[0]['overlayMenu'] ?? null), 'The merged menu has no device class and collapses on phones.');
$assert(1 === substr_count($markup, '/two/'), 'The merged menu keeps one copy of its links.');
echo "Capture device markers survive on native blocks.\n";
