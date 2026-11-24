<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// Sharing a template does not make a captured absolute-card container's
// height interchangeable across routes (#7393).
$page = static fn(string $height): string => '<!doctype html><html><head><style>.cards{position:relative;overflow:hidden}.card{position:absolute;top:0;left:0}</style><style>[data-list]{height:min(' . $height . 'px,83.58vw)!important}</style></head><body><main><div class="cards" data-list=""><article class="card"><h2>First card</h2><p>Card text</p></article><article class="card" style="top:300px"><h2>Second card</h2><p>More card text</p></article></div></main></body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    array('path' => 'index.html', 'content' => $page('266.55'), 'metadata' => array('post_type' => 'post')),
    array('path' => 'archive.html', 'content' => $page('764.47'), 'metadata' => array('post_type' => 'post')),
)))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$pages = array_column($plan['pages'], null, 'source_path');
if (($pages['index.html']['route_template'] ?? 'single') !== ($pages['archive.html']['route_template'] ?? 'single')) throw new RuntimeException('The reduced routes must share a template.');
$assets = array_column($plan['assets'], null, 'target_path');
$delivery = ThemeBootstrap::stylesheetDelivery($plan['assets'], $plan['pages'], $plan['theme']['document_roots'] ?? array());
$fixtures = array();
foreach (array('index.html' => '266.55', 'archive.html' => '764.47') as $source => $height) {
    $css = '';
    foreach (ThemeBootstrap::documentStylesheets($plan, $source) as $sheet) $css .= ($assets[$sheet['target_path']]['content'] ?? '') . "\n";
    $heightRows = array_filter($delivery['enqueues'], static fn(array $row): bool => str_contains($assets[$row['target_path']]['content'] ?? '', 'min(' . $height . 'px,83.58vw)'));
    if (!array_filter($heightRows, static fn(array $row): bool => $source === $row['route'] && ('index.html' === $source ? 'is_front_page()' === $row['condition'] : str_contains($row['condition'], $pages[$source]['reconciliation_identity'])))) throw new RuntimeException('The bootstrap must deliver the frozen height by route identity, not template.');
    $markup = $pages[$source]['canonical_block_markup'];
    $dom = new DOMDocument();
    @$dom->loadHTML('<html><body>' . $markup . '</body></html>');
    $container = (new DOMXPath($dom))->query('//*[contains(concat(" ",normalize-space(@class)," ")," cards ")]')->item(0);
    if (!$container instanceof DOMElement) throw new RuntimeException('The positioned container must survive.');
    $style = (new StaticCssCascade($dom, $css))->resolve($container, array('height', 'overflow', 'position'), array());
    if ('min(' . $height . 'px,83.58vw)' !== ($style['height'] ?? '') || 'hidden' !== ($style['overflow'] ?? '') || 'relative' !== ($style['position'] ?? '')) throw new RuntimeException($source . ' lost its route-local container geometry: ' . json_encode($style));
    $fixtures[$source] = array('height' => (float) $height, 'native' => '<!doctype html><style>' . $css . '</style>' . $markup);
}
if ($path = getenv('FROZEN_HEIGHT_ARTIFACT')) file_put_contents($path, json_encode($fixtures, JSON_THROW_ON_ERROR));
echo "Route-local frozen height passed\n";
