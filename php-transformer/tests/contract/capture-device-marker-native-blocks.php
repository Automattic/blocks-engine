<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A device marker directly on an element that lowers to its own native block
// becomes core block visibility for width selection, without a wrapper.
$html = (string) file_get_contents(dirname(__DIR__) . '/fixtures/capture-device-marker-native-blocks.html');
$identityHtml = $html;
// Width-driven captures still use core viewports; the fixture's original
// selected-document stylesheet is exercised separately below.
$html = (string) preg_replace('/<style data-blocks-engine-capture-device-visibility="">.*?<\/style>/s', '<style data-blocks-engine-capture-device-visibility="">@media(max-width:480px){[data-blocks-engine-capture-device-hide~="mobile"]{display:none!important}}</style>', $html);
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$markup = (string) $result['serialized_blocks'];
$css = implode("\n", array_column(array_filter($result['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
$assert = static function (bool $condition, string $message) use ($markup): void {
    if (! $condition) throw new RuntimeException($message . "\nGot: " . $markup);
};
$assert(! str_contains($markup, '<!-- wp:html'), 'Marked parts lower natively, not to core/html.');
$assert(1 === substr_count($markup, '<!-- wp:custom/layout-shell'), 'Only the document root is a layout shell: marked native blocks gain no wrapper.');
$phoneOnly = '"metadata":{"blockVisibility":{"viewport":{"tablet":false,"desktop":false}}}';
foreach (array(
    '/<!-- wp:heading \{[^\n]*?"level":2[^\n]*?' . preg_quote($phoneOnly, '/') . '/' => 'heading',
    '/<!-- wp:image \{[^\n]*?' . preg_quote($phoneOnly, '/') . '/' => 'image',
    '/<!-- wp:paragraph \{[^\n]*?"metadata":\{"blockVisibility":\{"viewport":\{"mobile":false\}\}\}/' => 'paragraph',
    '/<!-- wp:buttons \{[^\n]*?' . preg_quote($phoneOnly, '/') . '/' => 'buttons',
    '/<!-- wp:heading \{[^\n]*?"level":3[^\n]*?"metadata":\{"blockVisibility":\{"viewport":\{"mobile":false,"desktop":false\}\}\}/' => 'tablet heading',
    '/<!-- wp:tabs \{[^\n]*?' . preg_quote($phoneOnly, '/') . '/' => 'tabs',
) as $pattern => $part) {
    $assert(1 === preg_match($pattern, str_replace('-->', "-->\n", $markup)), 'The ' . $part . ' keeps its native block and carries core block visibility.');
}

// Profiles outside core's three viewports fall back to a class and a generated
// rule. Core's own viewports add neither.
$assert(str_contains($markup, 'blocks-engine-device-only-kiosk') && str_contains($markup, 'blocks-engine-device-hide-kiosk'), 'Custom profiles keep a fallback class.');
$assert(! preg_match('/blocks-engine-device-(?:only|hide)-(?:mobile|tablet|desktop)/', $markup . $css), 'Core viewports do not use the fallback class.');
foreach (array(
    'html[data-blocks-engine-capture-selected-document]:not([data-blocks-engine-capture-selected-document="kiosk"]) .blocks-engine-device-only-kiosk',
    'html:not([data-blocks-engine-capture-selected-document]) .blocks-engine-device-only-kiosk',
    'html[data-blocks-engine-capture-selected-document="kiosk"] .blocks-engine-device-hide-kiosk',
) as $selector) {
    $assert(str_contains($css, $selector), 'Generated rule covers ' . $selector . '. CSS: ' . $css);
}

// Identical sibling menus for phones and for everything else become one menu
// that collapses on phones. A phone menu with its own links stays separate. A
// merged menu stays hidden only where both copies were hidden. A pair that uses
// a custom profile stays separate, because its class follows the device choice.
preg_match_all('/<!-- wp:navigation (\{.*?\}) (?:\/)?-->/s', $markup, $navigations);
$assert(5 === count($navigations[1]), 'Pairs limited to core viewports merge, leaving five menus.');
$attrs = array_map(static fn (string $json): array => (array) json_decode($json, true), $navigations[1]);
$viewportOf = static fn (array $block): array => (array) ($block['metadata']['blockVisibility']['viewport'] ?? array());
$withViewport = static fn (array $viewport): array => array_values(array_filter($attrs, static fn (array $block): bool => $viewport == $viewportOf($block)));
$phone = $withViewport(array('tablet' => false, 'desktop' => false));
$assert(2 === count($phone) && str_contains($markup, 'Phone A') && 'mobile' !== ($phone[0]['overlayMenu'] ?? null), 'The phone menu with different links stays phone only.');
$assert(2 === substr_count($markup, '/six/'), 'A pair using a custom profile stays separate.');
$merged = $withViewport(array());
$assert(1 === count($merged) && 'mobile' === ($merged[0]['overlayMenu'] ?? null) && ! isset($merged[0]['metadata']), 'The merged menu shows everywhere and collapses on phones.');
$limited = $withViewport(array('tablet' => false));
$assert(1 === count($limited) && 'mobile' === ($limited[0]['overlayMenu'] ?? null), 'A pair limited to phone and desktop stays hidden on tablets.');
$assert(1 === substr_count($markup, '/two/') && 1 === substr_count($markup, '/four/'), 'Merged menus keep one copy of their links.');
$identity = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $identityHtml)))->toArray();
$identityMarkup = (string) $identity['serialized_blocks'];
$assert(!str_contains($identityMarkup, 'blockVisibility'), 'Identity-selected native blocks never gain viewport visibility.');
foreach (array('only-mobile', 'only-tablet', 'only-desktop', 'hide-mobile', 'only-kiosk', 'hide-kiosk') as $marker) {
    $assert(str_contains($identityMarkup, 'blocks-engine-device-' . $marker), 'Identity selection retains ' . $marker . ' as a class.');
}
$assert(7 === substr_count($identityMarkup, '<!-- wp:navigation '), 'Selected-document menu pairs stay separate, including core-named profiles.');
$assert(2 === substr_count($identityMarkup, '/two/') && 2 === substr_count($identityMarkup, '/four/'), 'Identity-selected menus retain both copies of their links.');
echo "Capture device markers survive on native blocks.\n";
