<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$menu = trim((string) file_get_contents(dirname(__DIR__) . '/fixtures/custom-element-navigation/menu.html'));
$files = array();
foreach (array('index.html', 'custom-orders/index.html') as $path) {
    $body = '';
    foreach (array('desktop', 'mobile') as $device) {
        $copy = 'desktop' === $device ? $menu : str_replace('hyu7izfs', 'hyu7izfs-mobile', $menu);
        $body .= '<div data-blocks-engine-capture-device-document="' . $device . '" data-blocks-engine-capture-document-scope=""><header>' . $copy . '</header><main><h1>' . $path . '</h1><p>Route content.</p></main></div>';
    }
    $files[$path] = '<!doctype html><html><head><title>Site</title></head><body>' . $body . '</body></html>';
}
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$headers = array_values(array_filter($plan['template_parts'] ?? array(), static fn(array $part): bool => 'header' === $part['area']));
$header = implode("\n", array_column($headers, 'canonical_block_markup'));
$navigationCount = substr_count($header, '<!-- wp:navigation ');
echo 'Custom-element navigation: ' . $navigationCount . ' native occurrences, ' . count($plan['menus']) . " shared menus.\n";
$assert(2 === $navigationCount, 'Both responsive menus must lower to native navigation.');
$assert(1 === count($plan['menus']), 'Responsive copies and routes must share one menu entity.');
$markup = $plan['menus'][0]['block_markup'];
$assert(1 === substr_count($markup, '<!-- wp:navigation-submenu '), 'Shop must remain a native submenu.');
preg_match_all('/<!-- wp:navigation-(?:link|submenu) (\{.*?\})\s*\/?-->/', $markup, $matches);
$items = array_map(static fn(string $json): array => json_decode($json, true, flags: JSON_THROW_ON_ERROR), $matches[1]);
$assert(array('Home', 'Shop', 'Charcuterie Boards', 'Coffee Tables', 'Brisket Boards', 'Hand Bags', 'Jewellery Boxes & Jewellery', 'Wall Art & Home Decorations', 'Custom Orders', 'About') === array_map(static fn(array $item): string => html_entity_decode(strip_tags($item['label'])), $items), 'All menu labels must survive in source order without support controls.');
$assert(array('/', '/shop-1/index.html', '/shop/index.html', '/coffee-tables/index.html', '/brisket-boards/index.html', '/hand-bags/index.html', '/jewellr/index.html', '/wall-art-home-decorations/index.html', '/custom-orders', '/#dataItem-iiswcuu1') === array_column($items, 'url'), 'All destinations, including the fragment link, must survive: ' . json_encode(array_column($items, 'url')));
$report = json_encode($result, JSON_THROW_ON_ERROR);
$assert(!str_contains($report, 'html_unsupported_element') && !str_contains($report, 'unsupported_html_fallback'), 'No unsupported fallback may be emitted.');
$assert(!str_contains($header, '<!-- wp:html'), 'The header must not contain an HTML fallback.');
// Unrelated hidden support content must still prevent transparent recognition.
$hinted = str_replace('</nav>', '<span hidden>Keyboard help</span></nav>', $menu);
$hintedMarkup = (new HtmlTransformer())->transform($hinted)->serializedBlocks;
$assert(!str_contains($hintedMarkup, '<!-- wp:navigation '), 'Unrelated hidden text must not be discarded by host recognition.');
echo "Custom-element navigation contract passed.\n";
