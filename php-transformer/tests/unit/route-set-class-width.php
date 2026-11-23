<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentRootContext;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};

// A route gate names its route-set class in every arm it scopes, so every
// stylesheet pays for the class width once per gated arm. Sets are as short
// as route classes and grow only to avoid a collision.
$css = '';
foreach (array('listing', 'detail') as $variant) {
    for ($i = 0; $i < 4; ++$i) $css .= 'body.tweak-' . $variant . ' .foo{--sample-' . $variant . '-' . $i . ':' . $i . ';padding-bottom:' . ($i + 1) . 'px}';
}
$files = array('site.css' => $css);
for ($i = 0; $i < 6; ++$i) {
    $files['page-' . $i . '.html'] = '<html><head><title>Page ' . $i . '</title><link rel="stylesheet" href="/site.css"></head><body class="tweak-' . ($i % 2 ? 'detail' : 'listing') . '"><main><nav><ul><li class="foo"><a href="/page-0.html">Page ' . $i . '</a></li></ul></nav></main></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'page-0.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
$compiled = implode('', array_map(static fn(array $asset): string => is_string($asset['content'] ?? null) ? $asset['content'] : '', $plan['assets']));
preg_match_all('/be-rs-[0-9a-f]+/', $compiled, $gated);
$assert(array() !== $gated[0], 'Fixture must exercise route-set gated CSS: ' . $compiled);
$roots = '';
foreach ($plan['pages'] as $page) $roots .= ' ' . ($page['document_metadata']['root_attributes']['class'] ?? '');
foreach (array_unique($gated[0]) as $class) {
    $assert(1 === preg_match('/^be-rs-[0-9a-f]{8}$/D', $class), 'Route-set classes ship at route-class width: ' . $class);
    $assert(str_contains($roots . ' ', ' ' . $class . ' '), 'Gated set class is stamped on its member roots: ' . $class);
}

// A colliding short name extends only the newcomer; registration stays stable.
$sets = array();
$first = DocumentRootContext::routeSetClass(array('be-r-b', 'be-r-a'), $sets);
$assert($first === DocumentRootContext::routeSetClass(array('be-r-a', 'be-r-b'), $sets), 'A set registers once, independent of member order');
$sets = array($first => array('be-r-z'));
$extended = DocumentRootContext::routeSetClass(array('be-r-a', 'be-r-b'), $sets);
$assert(str_starts_with($extended, $first) && strlen($extended) === strlen($first) + 1 && $sets[$first] === array('be-r-z'), 'A collision extends the newcomer: ' . $extended);
echo "Route-set classes ship at route-class width\n";
