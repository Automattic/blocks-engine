<?php
declare(strict_types=1);

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Most routes keep one copy of the header and footer. Two routes keep one
// copy per device document. Every route template must still render a header
// and a footer part, and no route may keep its chrome in post content.
$oneCopy = (string) file_get_contents(__DIR__ . '/fixtures/mixed-device-document-shared-chrome/one-copy.html');
$deviceCopies = (string) file_get_contents(__DIR__ . '/fixtures/mixed-device-document-shared-chrome/device-copies.html');
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $oneCopy,
    'cafe/index.html' => $oneCopy,
    'about/index.html' => $oneCopy,
    'menu/index.html' => $deviceCopies,
    'events/index.html' => $deviceCopies,
)))->toArray()['source_reports']['wordpress_site_plan'];

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$areas = array_column($plan['template_parts'], 'area', 'slug');
foreach ($plan['pages'] as $page) {
    $markup = (string) $page['canonical_block_markup'];
    $assert(!str_contains($markup, '<header') && !str_contains($markup, '<footer'), $page['source_path'] . ' keeps header or footer copies in post content.');
}
foreach ($plan['templates'] as $template) {
    $markup = (string) $template['canonical_block_markup'];
    if (!str_contains($markup, 'wp:post-content')) continue;
    preg_match_all('/<!-- wp:template-part \{"slug":"([^"]+)"/', $markup, $references);
    $bound = array_map(static fn(string $slug): ?string => $areas[$slug] ?? null, $references[1]);
    $assert(in_array('header', $bound, true) && in_array('footer', $bound, true), 'Template ' . $template['slug'] . ' renders post content without a header and footer part.');
}
// The two routes with device copies share one header part that keeps both copies.
$deviceParts = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area'] && str_contains((string) $part['canonical_block_markup'], 'SITE_HEADER--data-blocks-engine-capture-mobile')));
$assert(1 === count($deviceParts), 'Routes with the same device header copies share one header part.');
$assert(str_contains((string) $deviceParts[0]['canonical_block_markup'], 'data-blocks-engine-capture-device-document="desktop"'), 'The device header part keeps the desktop copy.');
fwrite(STDOUT, "mixed device-document shared chrome contract passed\n");
