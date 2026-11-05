<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };
$root = dirname(__DIR__) . '/fixtures/fixed-viewport-scroller-shared-header-site';
$artifact = array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => file_get_contents($root . '/index.html'),
    'services/index.html' => file_get_contents($root . '/services/index.html'),
));
$result = (new ArtifactCompiler())->compile($artifact)->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? null;
$assert(is_array($plan), 'The reduced fixed viewport scroller produces a site plan.');
$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers), 'Repeated fixed-scroller header is extracted once.');
$assert(str_contains($headers[0]['canonical_block_markup'], '<!-- wp:navigation'), 'Extracted header contains a Navigation block.');
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
foreach ($plan['pages'] as $page) {
    $markup = (string) $page['canonical_block_markup'];
    $assert(! str_contains($markup, 'Get in touch'), 'Page content does not retain header copies.');
    $template = !empty($page['entrypoint']) ? 'front-page' : 'page';
    $assert(str_contains($markup . ($templates[$template] ?? ''), '"slug":"header"'), 'Every route references the shared header.');
}
echo "Fixed viewport scroller shared header contract passed\n";
