<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;


$compiler = new ArtifactCompiler();
$css = 'form{display:flex;gap:12px}label{font-size:14px}input{color:#123456}@media(min-width:600px){form{padding:8px}}';
$start = microtime(true);
$output = array();
for ( $page = 0; $page < 4; ++$page ) {
    $html = str_replace('{{page}}', (string) $page, file_get_contents(__DIR__ . '/../fixtures/contract/shared-stylesheet-structure/page.html'));
    $output[] = $compiler->compile(array('entrypoint' => 'index.html', 'files' => array(
        array('path' => 'index.html', 'kind' => 'html', 'content' => $html),
        array('path' => 'base.css', 'kind' => 'css', 'content' => $css),
        array('path' => 'page.css', 'kind' => 'css', 'content' => 'h1{color:rgb(' . $page . ',0,0)}'),
    )))->toArray();
}
// Runtime timing metrics are observational, not compilation facts.
$withoutTimings = static function (array $value) use (&$withoutTimings): array {
    unset($value['metrics'], $value['transform_duration_ms']);
    foreach ( $value as &$child ) {
        if ( is_array($child) ) $child = $withoutTimings($child);
    }
    return $value;
};
$output = $withoutTimings($output);
$hash = hash('sha256', serialize($output));
$parses = method_exists($compiler, 'stylesheetStructureCache') ? $compiler->stylesheetStructureCache()->parses($css) : 0;
for ( $page = 0; $page < 4; ++$page ) {
    if ( method_exists($compiler, 'stylesheetStructureCache') && 1 !== $compiler->stylesheetStructureCache()->parses('h1{color:rgb(' . $page . ',0,0)}') ) {
        fwrite(STDERR, "FAIL: each distinct page stylesheet must be parsed once\n");
        exit(1);
    }
}
if ( 1 !== $parses || 'd4e54e9432ee44e4202731af3cc022e04e06758fda40756384bfc27b742cdca3' !== $hash ) {
    fwrite(STDERR, "FAIL: shared stylesheet must be parsed once and complete compiled results must remain byte-identical: {$parses}, {$hash}\n");
    exit(1);
}
fwrite(STDOUT, "PASS: shared stylesheet structure; " . (microtime(true) - $start) . "s\n");
