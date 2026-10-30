<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;

$header = '<header class="site-header"><h1>Shared site</h1><nav><li><div><a href="/">Home</a></div></li><li><div><a href="/about/">About</a></div></li></nav></header>';
$page = static fn(string $title, bool $extra): string => '<html><head><title>' . $title . '</title>' . ($extra ? '<link rel="stylesheet" href="/extra.css">' : '') . '</head><body>' . $header . '<main><h2>' . $title . '</h2><p>Page content</p></main></body></html>';
$failures = 0;
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) { ++$failures; fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); }
};
// Also exercise the route-only stylesheet on the compiler's entrypoint.
foreach (array(false, true) as $responsive) {
foreach (array(false, true) as $includes) {
foreach (array('index.html', 'special.html') as $entrypoint) {
$files = array(
    'index.html' => '<html><head><title>Front</title></head><body><header class="front-header"><h1>Different front page</h1><p>Unique front-page introduction</p></header><main><p>Front content</p></main></body></html>',
    'shared.html' => $page('Shared', false),
    'about/index.html' => $page('About', false),
    'special.html' => $page('Special', true),
    'extra.css' => 'li{margin-bottom:6px}',
);
if ($includes) {
    foreach (array('shared.html', 'about/index.html', 'special.html') as $path) {
        $files[$path] = str_replace($header, '<!--#include virtual="/parts/shared-header.html" -->', $files[$path]);
    }
    $files['parts/shared-header.html'] = $header;
}
if ($responsive) {
    foreach (array('shared.html', 'about/index.html', 'special.html') as $path) {
        $files[$path] = preg_replace_callback('/<body>(.*)<\/body>/s', static function (array $match): string {
            $documents = '';
            foreach (array('desktop' => CaptureEvidence::DESKTOP_DOCUMENT_CLASS, 'mobile' => CaptureEvidence::MOBILE_DOCUMENT_CLASS) as $device => $class) {
                $documents .= '<div class="' . $class . '" ' . CaptureEvidence::DEVICE_DOCUMENT . '="' . $device . '" ' . CaptureEvidence::DOCUMENT_SCOPE . '="">' . $match[1] . '</div>';
            }
            return '<body>' . $documents . '</body>';
        }, $files[$path]);
    }
}
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => $entrypoint,
    'files' => $files,
))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$parts = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($parts), 'One shared header is extracted.');
$assert(3 === count($parts[0]['provenance']['sources'] ?? array()), 'The shared header covers all three sharing pages.');
$assert(!isset($parts[0]['provenance']['sources']['index.html']), 'The differing front page is excluded.');
foreach ($plan['pages'] as $pageRow) {
    if ('index.html' === $pageRow['source_path']) {
        $assert(str_contains($pageRow['canonical_block_markup'], 'Different front page'), 'The front page keeps its own header.');
        continue;
    }
    $assert(!str_contains($pageRow['canonical_block_markup'], 'site-header'), 'Sharing pages relinquish their header: ' . $pageRow['source_path']);
    $assert(!str_contains($pageRow['canonical_block_markup'], '<header'), 'No header landmark remains in content: ' . $pageRow['source_path']);
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
}
}
exit($failures ? 1 : 0);
