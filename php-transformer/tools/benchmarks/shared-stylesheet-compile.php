<?php
declare(strict_types=1);
require_once __DIR__ . '/../../vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$sheets = require __DIR__ . '/../../tests/fixtures/contract/shared-stylesheet-structure/shared-css.php';
$compiler = new ArtifactCompiler();
$start = microtime(true);
$output = array();
for ($page = 0; $page < 8; ++$page) {
    $links = '';
    $files = array();
    foreach ($sheets as $index => $css) {
        $links .= '<link rel="stylesheet" href="shared-' . $index . '.css">';
        $files[] = array('path' => 'shared-' . $index . '.css', 'kind' => 'css', 'content' => $css);
    }
    $files[] = array('path' => 'page.css', 'kind' => 'css', 'content' => 'h1{color:rgb(' . $page . ',0,0)}');
    $files[] = array('path' => 'index.html', 'kind' => 'html', 'content' => '<html><head>' . $links . '<link rel="stylesheet" href="page.css"></head><body><h1>Page ' . $page . '</h1><p class="shared-0">Shared text</p><form><label>Name<input name="name"></label><button>Send</button></form></body></html>');
    $worker = in_array('--fresh', $argv, true) ? new ArtifactCompiler() : $compiler;
    $output[] = $worker->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
}
$strip = static function (array $value) use (&$strip): array {
    unset($value['metrics'], $value['transform_duration_ms']);
    foreach ($value as &$child) if (is_array($child)) $child = $strip($child);
    return $value;
};
// Encode before replacing variable-length random markers (serialize stores lengths).
$normalized = preg_replace('/blocks-engine-(richtext|control)-[a-f0-9]+/', 'blocks-engine-$1-MARKER', json_encode($strip($output), JSON_UNESCAPED_SLASHES));
if (isset($argv[1]) && '--fresh' !== $argv[1]) file_put_contents($argv[1], $normalized);
printf("8 pages, %d shared bytes: %.4fs, peak %.1f MB\n", array_sum(array_map('strlen', $sheets)), microtime(true) - $start, memory_get_peak_usage(true) / 1048576);
