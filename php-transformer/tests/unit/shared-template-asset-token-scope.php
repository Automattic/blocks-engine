<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$header = '<header><img src="/logo.svg" alt="Shared logo"><nav><a href="/">Home</a><a href="/about/">About</a></nav></header>';
$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"><rect width="20" height="20"/></svg>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><body>' . $header . '<main><h1>Home</h1></main></body></html>',
    'about/index.html' => '<!doctype html><html><body>' . $header . '<main><h1>About</h1></main></body></html>',
    'logo.svg' => $svg,
    'unused.svg' => str_replace('20', '30', $svg),
)))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
$bootstrap = '';
foreach ($plan['writes'] ?? array() as $write) if ('functions.php' === $write['target_path']) $bootstrap = $write['payload']['data'];
$sharedMarkup = implode('', array_column(array_merge($plan['templates'] ?? array(), $plan['template_parts'] ?? array()), 'canonical_block_markup'));
if (!str_contains($sharedMarkup, WordPressSitePlan::TOKEN_PREFIX) || '' === $bootstrap) throw new RuntimeException('Fixture must exercise asset-bearing shared templates.');
foreach ($plan['assets'] as $asset) {
    $reference = WordPressSitePlan::TOKEN_PREFIX . $asset['token'] . '}}';
    if ('none' === $asset['destination'] && str_contains($bootstrap, $reference)) throw new RuntimeException('Bootstrap must not reference an unmaterialized asset.');
}
echo "shared template asset token scope passed\n";
