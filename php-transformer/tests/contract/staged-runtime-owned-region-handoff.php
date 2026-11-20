<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$evidence = array(
    'schema' => 'blocks-engine-capture/runtime-owned-regions/v1',
    'regions' => array(array(
        'id' => 'runtime-contact',
        'script' => array('origin' => 'https://forms.example.org', 'path' => '/embed.js'),
        'roots' => array(array('tag' => 'form', 'controls' => 2)),
    )),
);
$artifact = array('entrypoint' => 'index.html', 'files' => array('index.html' =>
    '<!doctype html><html><head><script type="application/json" data-blocks-engine-capture-source-evidence="runtime-owned-regions">' . json_encode($evidence, JSON_THROW_ON_ERROR) . '</script></head><body><main><h1>Contact</h1><form data-blocks-engine-capture-runtime-owner="runtime-contact"><label>Name<input name="name"></label><button type="submit">Send</button></form></main></body></html>',
));
$compiler = new ArtifactCompiler();
$checkpoint = static fn(array $plan): array => json_decode(json_encode($plan, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
$shared = $checkpoint($compiler->prepareShared($artifact));
$pages = $checkpoint($compiler->preparePages($artifact, $shared));
$receipts = $checkpoint($compiler->compilePreparedPages($shared, $pages));
foreach (array(
    'inline' => $compiler->compile($artifact)->toArray(),
    'staged' => $compiler->compose($shared, $receipts)->toArray(),
) as $mode => $result) {
    $codes = array_column($result['diagnostics'] ?? array(), 'code');
    $assert(in_array('runtime_owned_region_handoff', $codes, true), $mode . ': runtime-owned handoff is reported');
    $assert(!in_array('html_form_fallback', $codes, true), $mode . ': no form fallback diagnostic');
    $assert(!in_array('html_form_fallback', array_column($result['fallbacks'] ?? array(), 'diagnostic_code'), true), $mode . ': no form fallback record');
    $markup = $result['source_reports']['wordpress_site_plan']['pages'][0]['canonical_block_markup'] ?? '';
    $assert(str_contains(html_entity_decode($markup, ENT_QUOTES | ENT_HTML5, 'UTF-8'), "This page embedded a form from forms.example.org. Re-add it with the provider's embed code."), $mode . ': provider handoff notice survives compilation');
    $assert(str_contains($markup, '<!-- wp:paragraph') && !str_contains($markup, '<input'), $mode . ': editable notice replaces runtime controls');
}

echo "staged-runtime-owned-region-handoff: ok\n";
