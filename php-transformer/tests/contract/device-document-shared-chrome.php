<?php
declare(strict_types=1);

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = (string) file_get_contents(__DIR__ . '/fixtures/device-document-shared-chrome/index.html');
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $html,
    'about/index.html' => $html,
)))->toArray()['source_reports']['wordpress_site_plan'];
$css = implode("\n", array_column(array_filter($plan['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content'));
$checked = array();
foreach ($plan['template_parts'] as $part) {
    if (!in_array($part['area'] ?? '', array('header', 'footer'), true)) continue;
    $markup = (string) ($part['canonical_block_markup'] ?? '');
    if (!str_contains($markup, 'data-blocks-engine-capture-')) continue;
    $checked[$part['area']] = true;
    preg_match_all('/\bblocks-engine-attribute-[a-f0-9]+-[0-9]+\b/', $markup, $classes);
    foreach (array_unique($classes[0]) as $class) {
        preg_match_all('/([^{}]+)\{([^{}]*display\s*:\s*none\s*!important[^{}]*)\}/i', $css, $rules, PREG_SET_ORDER);
        foreach ($rules as $rule) {
            if (!str_contains($rule[1], '.' . $class)) continue;
            throw new RuntimeException('Shared ' . $part['area'] . ' has an unmatched capture visibility gate: ' . trim($rule[0]));
        }
    }
    if (!str_contains($markup, 'wp:navigation') && !str_contains($markup, 'Example Co.')) {
        throw new RuntimeException('Shared chrome lost its visible source content.');
    }
}
if (!isset($checked['header'], $checked['footer'])) throw new RuntimeException('Fixture must extract both shared header and footer.');
fwrite(STDOUT, "device-document shared chrome visibility contract passed\n");
