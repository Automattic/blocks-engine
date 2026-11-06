<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// A deterministic many-route fixture without unrelated HTML/CSS projection.
$pages = $assets = array();
for ($i = 0; $i < 100; ++$i) $pages[] = array('source_path' => "page-$i.html", 'post_type' => 'page', 'route' => array('path' => "/page-$i"), 'reconciliation_identity' => "page-$i", 'canonical_block_markup' => '', 'metadata' => array());
for ($i = 0; $i < 28; ++$i) {
    $instances = array();
    foreach ($pages as $page) $instances[] = array('source_path' => $page['source_path'], 'order' => $i, 'media' => 0 === $i % 2 ? '' : 'print');
    $assets[] = array('kind' => 'css', 'source_path' => "style-$i.css", 'target_path' => "assets/style-$i.css", 'content_hash' => hash('sha256', "style-$i"), 'scopes' => array(array('kind' => 'global')), 'stylesheet_instances' => $instances);
}
$bootstrap = (new ReflectionMethod(WordPressSitePlan::class, 'bootstrap'))->invoke(null, $assets, array(), array(), array(), array(), $pages);
echo '100 routes × 28 styles: functions.php ' . strlen($bootstrap) . " bytes\n";
if (getenv('BOOTSTRAP_SIZE_ONLY')) return;

if (1 !== preg_match('/\$blocks_engine_style_sequences = (\(static function .*?\}\)\(\));/s', $bootstrap, $match)) throw new RuntimeException('Missing data-driven stylesheet sequences.');
$sequences = eval('return ' . $match[1] . ';');
if (1 !== count($sequences) || 28 !== count($sequences[0])) throw new RuntimeException('Shared ordered sequence must be listed exactly once.');
if (1 !== preg_match("/add_action\( 'wp_enqueue_scripts', static function \(\): void \{(.*?)\n\}, 1 \);/s", $bootstrap, $match)) throw new RuntimeException('Missing enqueue closure.');
$enqueue = eval('return static function (): void {' . $match[1] . '};');
function is_singular(): bool { return true; }
function get_queried_object_id(): int { return $GLOBALS['route_id']; }
function apply_filters(string $name, mixed $value): mixed { return $value; }
function get_post_meta(int $id, string $key, bool $single): string { return $GLOBALS['legacy_route'] ? '' : "page-$id"; }
function get_post_type(int $id): string { return 'page'; }
function get_page_uri(int $id): string { return "page-$id"; }
function get_theme_file_uri(string $path): string { return $path; }
function wp_enqueue_style(string $handle, string $uri, array $deps, mixed $version, string $media = 'all'): void { $GLOBALS['enqueued'][] = array($handle, $uri, $media); }
$rows = (new ReflectionMethod(WordPressSitePlan::class, 'stylesheetEnqueues'))->invoke(null, $assets, $pages);
foreach (array(false, true) as $legacy) foreach (range(1, 100) as $route) {
    $GLOBALS['route_id'] = $route;
    $GLOBALS['legacy_route'] = $legacy;
    $GLOBALS['enqueued'] = array();
    $enqueue();
    $expected = array();
    foreach ($rows as $row) if (($row['route'] ?? null) === "page-$route.html" || (100 === $route && !empty($row['fallback']))) $expected[] = array($row['handle'], $row['asset']['target_path'], '' === $row['media'] ? 'all' : $row['media']);
    if ($expected !== $GLOBALS['enqueued']) throw new RuntimeException('Enqueued occurrence handles, URI, media or order changed.');
}
// Indexed static tables reconstruct exact PHP values, including hostile text.
$value = array(array('0' => "quote'\\\n", 'unicode' => 'é', 'disabled' => false, 'empty' => array()), array('0' => "quote'\\\n", 'unicode' => 'é', 'disabled' => false, 'empty' => array()));
$source = \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\BootstrapData::table('roundtrip', $value);
eval($source);
if ($roundtrip !== $value || 1 !== substr_count($source, "'unicode'")) throw new RuntimeException('Static rows must deduplicate and round-trip losslessly.');
