<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

function remove_action(...$args): void {}
function add_action(...$args): void {}
function add_filter(...$args): void {}

$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$files = array('base.css' => '.box{color:red}', 'second.css' => '.box{padding:3px}');
for ($i = 0; $i < 18; ++$i) {
    $files[0 === $i ? 'index.html' : "route-$i.html"] = '<html data-site="shared"><head><link rel="stylesheet" href="base.css"><link rel="stylesheet" href="second.css" media="print"><link rel="stylesheet" href="base.css" media="screen"><link rel="alternate stylesheet" title="Night" href="second.css"></head><body class="shared classes"><main><div class="box">Route ' . $i . '</div></main></body></html>';
}
$plan = (new WordPressSitePlan())->fromCompilerResult((new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray());
$bootstrap = '';
foreach ($plan['writes'] as $write) if ('functions.php' === $write['target_path']) $bootstrap = $write['payload']['data'];

// Evaluate the emitted data and registrations, without a WordPress installation.
eval(substr($bootstrap, 5));
$enqueues = (new ReflectionMethod(ThemeBootstrap::class, 'stylesheetEnqueues'))->invoke(null, $plan['assets'], $plan['pages']);
$legacy = $legacyAttributes = array();
foreach ($enqueues as $enqueue) {
    $asset = $enqueue['asset'];
    if (isset($asset['stylesheet_activation'])) {
        $state = $asset['stylesheet_activation'];
        $attributes = array('rel' => $state['rel']);
        if ('' !== $state['title']) $attributes['title'] = $state['title'];
        if ($state['disabled']) $attributes['disabled'] = true;
        $legacyAttributes[$enqueue['handle']] = $attributes;
    }
    if ('frontend' === ($asset['stylesheet_target'] ?? 'both') || !\Automattic\BlocksEngine\PhpTransformer\Support\StylesheetActivation::active($asset)) continue;
    // Trunk's one-row-per-occurrence presentation registry.
    $legacy[] = array_filter(array('target_path' => $asset['target_path'], 'handle' => $enqueue['handle'], 'content_hash' => $asset['content_hash'], 'scopes' => array($enqueue['scope']), 'template_part_slugs' => array(), 'media' => $asset['source_media'] ?? $enqueue['media'], 'author_css' => 'engine-support' !== ($asset['source'] ?? ''), 'editor_only' => 'editor' === ($asset['stylesheet_target'] ?? 'both'), 'fallback' => empty($enqueue['fallback']) ? null : true), static fn($value): bool => null !== $value);
}
$normalize = static function (array $rows): array {
    foreach ($rows as &$row) { $row['fallback'] = !empty($row['fallback']); ksort($row); }
    unset($row);
    return $rows;
};
$check($normalize($legacy) === $normalize($blocks_engine_presentation_sequence), 'Presentation replay must equal trunk, including handles, media, scope and ordering');
foreach ($plan['pages'] as $page) {
    $select = static fn(array $row): bool => 'global' === $row['scopes'][0]['kind'] || $page['source_path'] === ($row['scopes'][0]['source_path'] ?? null);
    $check($normalize(array_values(array_filter($legacy, $select))) === $normalize(array_values(array_filter($blocks_engine_presentation_sequence, $select))), 'Per-route editor enqueue result changed');
}
foreach ($legacyAttributes as $handle => $attributes) {
    $actual = isset($blocks_engine_stylesheet_attributes[$handle]) ? $blocks_engine_stylesheet_attribute_bodies[$blocks_engine_stylesheet_attributes[$handle]] : array('rel' => 'stylesheet');
    $check($actual === $attributes, 'Stylesheet attributes changed');
    if (array('rel' => 'stylesheet') === $attributes) $check(!isset($blocks_engine_stylesheet_attributes[$handle]), 'Default handles must be omitted');
}
$check(count($blocks_engine_presentation_styles) === count(array_unique(array_column($legacy, 'target_path'))), 'One presentation entry per file');
$check(count($blocks_engine_stylesheet_attribute_bodies) === count(array_unique($blocks_engine_stylesheet_attribute_bodies, SORT_REGULAR)), 'Attribute bodies must be unique');
$check(count($blocks_engine_document_attribute_bodies) === count(array_unique($blocks_engine_document_attribute_bodies, SORT_REGULAR)), 'Document attribute bodies must be unique');
foreach ($blocks_engine_document_rows as $row) $check($blocks_engine_document_roots[$row['identity']] === $row, 'Document registry replay changed');

// Model trunk's literal registries in the same bootstrap shell, excluding
// the compact representation's auxiliary data. Decoder overhead is retained
// on both sides, so this measures emitted data savings, not runtime code.
$before = $bootstrap;
foreach (array('blocks_engine_presentation_scopes', 'blocks_engine_presentation_replay', 'blocks_engine_stylesheet_attribute_bodies', 'blocks_engine_document_attribute_bodies') as $name) {
    $before = preg_replace('/\\$' . $name . ' = array \\(.*?\\n\\);\\n/s', '', $before, 1);
}
foreach (array('blocks_engine_presentation_styles' => $legacy, 'blocks_engine_stylesheet_attributes' => $legacyAttributes, 'blocks_engine_document_rows' => $blocks_engine_document_rows) as $name => $data) {
    $before = preg_replace_callback('/\\$' . $name . ' = array \\(.*?\\n\\);/s', static fn(): string => '$' . $name . ' = ' . var_export($data, true) . ';', $before, 1);
}
$check(strlen($bootstrap) < strlen($before), 'Bootstrap bytes must drop');
printf("Shared bootstrap data: modeled before %d bytes, after %d bytes\n", strlen($before), strlen($bootstrap));
