<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

// A deterministic many-route fixture without unrelated HTML/CSS projection.
$pages = $assets = array();
for ($i = 0; $i < 100; ++$i) $pages[] = array('source_path' => "page-$i.html", 'post_type' => 'page', 'route' => array('path' => "/page-$i"), 'reconciliation_identity' => "page-$i", 'canonical_block_markup' => '', 'metadata' => array());
for ($i = 0; $i < 28; ++$i) {
    $instances = array();
    foreach ($pages as $page) $instances[] = array('source_path' => $page['source_path'], 'order' => $i, 'media' => 0 === $i % 2 ? '' : 'print');
    $assets[] = array('kind' => 'css', 'source_path' => "style-$i.css", 'target_path' => "assets/style-$i.css", 'content_hash' => hash('sha256', "style-$i"), 'scopes' => array(array('kind' => 'global')), 'stylesheet_instances' => $instances);
}
$bootstrap = (new ReflectionMethod(ThemeBootstrap::class, 'functionsPhp'))->invoke(null, $assets, array(), array(), array(), array(), $pages);
echo '100 routes × 28 styles: functions.php ' . strlen($bootstrap) . " bytes\n";
if (getenv('BOOTSTRAP_SIZE_ONLY')) return;

if (1 !== preg_match('/\$blocks_engine_style_sequences = (\[[^\n]*\]);/s', $bootstrap, $match)) throw new RuntimeException('Missing data-driven stylesheet sequences.');
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
$rows = (new ReflectionMethod(ThemeBootstrap::class, 'stylesheetEnqueues'))->invoke(null, $assets, $pages);
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

// Compile every emitted table initializer as a const: references or row-building
// closures here would turn immutable data into optimizer-heavy executable code.
$constants = array();
$tables = array('style_sequences', 'presentation_scopes', 'presentation_styles', 'presentation_replay', 'document_attribute_bodies');
$contractSource = $bootstrap . "\n" . \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\BootstrapData::table('blocks_engine_document_attribute_bodies', array(array('lang' => 'en'), array('lang' => 'en')));
foreach ($tables as $table) {
    if (!preg_match_all('/\\$blocks_engine_' . $table . '(?:_rows|_references)? = (.*?);\n/s', $contractSource, $matches)) throw new RuntimeException('Missing constant table: ' . $table);
    foreach ($matches[1] as $expression) $constants[] = 'const T' . count($constants) . ' = ' . $expression . ';';
}
$scratch = tempnam(sys_get_temp_dir(), 'bootstrap-const-');
try {
    file_put_contents($scratch, "<?php\n" . implode("\n", $constants));
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($scratch) . ' 2>&1', $output, $status);
    if (0 !== $status) throw new RuntimeException('Bootstrap tables must be constant expressions: ' . implode("\n", $output));

    // Exercise both literal replay and interned data at real-import scale. The
    // child isolates compiler peak memory from the transformer's own allocations.
    $many = array();
    for ($i = 0; $i < 13100; ++$i) $many[] = array($i % 97, $i % 406, 'blocks-engine-style-' . $i, '', false, array());
    $large = $bootstrap . "\n" . \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\BootstrapData::table('blocks_engine_large_rows', $many)
        . "\n\$blocks_engine_large_replay = " . \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\BootstrapData::literal($many) . ';';
    file_put_contents($scratch, $large);
    $probe = 'if (!function_exists("opcache_compile_file") || !ini_get("opcache.enable_cli")) { echo "skip"; exit; } '
        . 'if (!opcache_compile_file($argv[1])) exit(1); echo memory_get_peak_usage(true);';
    $output = array();
    exec(escapeshellarg(PHP_BINARY) . ' -d opcache.enable_cli=1 -d opcache.file_update_protection=0 -d memory_limit=128M -r ' . escapeshellarg($probe) . ' ' . escapeshellarg($scratch) . ' 2>&1', $output, $status);
    if (0 !== $status || ('skip' !== implode('', $output) && (int) implode('', $output) >= 128 * 1024 * 1024)) throw new RuntimeException('Bootstrap compilation exceeded 128 MB: ' . implode("\n", $output));
} finally {
    unlink($scratch);
}
