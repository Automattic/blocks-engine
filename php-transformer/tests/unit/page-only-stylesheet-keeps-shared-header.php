<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$header = '<header class="site-header"><h1>Shared site</h1><nav><li><div><a href="/">Home</a></div></li><li><div><a href="/about/">About</a></div></li></nav></header>';
$page = static fn(string $title, bool $extra): string => '<html><head><title>' . $title . '</title>' . ($extra ? '<link rel="stylesheet" href="/extra.css">' : '') . '</head><body>' . $header . '<main><h2>' . $title . '</h2><p>Page content</p></main></body></html>';
$failures = 0;
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) { ++$failures; fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); }
};
// Also exercise the route-only stylesheet on the compiler's entrypoint.
foreach (array('index.html', 'special.html') as $entrypoint) {
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => $entrypoint,
    'files' => array(
        'index.html' => $page('Home', false),
        'about/index.html' => $page('About', false),
        'special.html' => $page('Special', true),
        'extra.css' => 'li{margin-bottom:6px}',
    ),
))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$parts = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($parts), 'One shared header is extracted.');
$assert(3 === count($parts[0]['provenance']['sources'] ?? array()), 'The shared header covers all three source pages.');
foreach ($plan['pages'] as $pageRow) {
    $assert(!str_contains($pageRow['canonical_block_markup'], 'site-header'), 'Every page relinquishes its header: ' . $pageRow['source_path']);
}
$assert(1 === count($plan['menus']), 'Page-only presentation does not split navigation entities.');
$assert(!str_contains(json_encode($parts), 'navigation-band-t0-b6'), 'Native list-item margins are not duplicated as host padding.');
// The stylesheet stays attached only to its source page, and still targets
// Core's li.wp-block-navigation-item; no generated padding is needed.
$special = array_values(array_filter($plan['pages'], static fn(array $row): bool => 'special.html' === $row['source_path']))[0];
$assert(1 === count($special['document_metadata']['links']), 'The special page retains its stylesheet.');
foreach ($plan['pages'] as $row) {
    if ('special.html' !== $row['source_path']) $assert(array() === $row['document_metadata']['links'], 'Other pages do not load the page-only stylesheet.');
}
$token = $special['document_metadata']['links'][0]['asset_reference'] ?? '';
$styles = array_values(array_filter($plan['assets'], static fn(array $asset): bool => str_contains($token, $asset['token'])));
$assert(1 === count($styles) && str_contains($styles[0]['content'], '.wp-block-navigation-item') && str_contains($styles[0]['content'], 'margin-bottom:6px'), 'The page-carried stylesheet directly reproduces the margin on native navigation items.');
}
exit($failures ? 1 : 0);
