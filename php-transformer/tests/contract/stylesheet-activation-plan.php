<?php
declare(strict_types=1);

require_once (getenv('BLOCKS_ENGINE_TEST_AUTOLOAD') ?: __DIR__ . '/../../vendor/autoload.php');

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Support\StylesheetActivation;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
};
$states = array_values(StylesheetActivation::links('<link rel="stylesheet" href="persistent.css"><link rel="stylesheet" title="dark"><link rel="alternate stylesheet" title="dark"><link rel="alternate stylesheet" title="light"><link rel="stylesheet" title="other"><link rel="stylesheet" disabled><link rel="stylesheet" disabled="false"><link rel="stylesheet" type="text/plain" title="ignored">'));
$assert(array(true, true, true, false, false, false, false) === array_column($states, 'active'), 'persistent, preferred, same-set alternate, other sets, boolean disabled and non-CSS semantics');
$artifact = array('entrypoint' => 'index.html', 'files' => array(
    array('path' => 'index.html', 'content' => '<html><head><link rel="stylesheet" href="dark.css" title="dark"><link rel="alternate STYLESHEET" href="light.css" title="light" disabled="false"><link rel="stylesheet" href="other.css" title="other"></head><body><main><h1>Neutral title</h1><p>Neutral content</p></main></body></html>'),
    array('path' => 'second.html', 'content' => '<html><head><link rel="stylesheet" href="light.css"></head><body><main><h1>Second route</h1></main></body></html>'),
    array('path' => 'dark.css', 'content' => ':root{--type:Times}body{background:#56575d;font-family:var(--type)}h1{font-weight:700}'),
    array('path' => 'light.css', 'content' => ':root{--type:Georgia}body{background:white;font-family:var(--type)}h1{font-weight:300}'),
    array('path' => 'other.css', 'content' => 'body{background:red}'),
));
$plan = (new ArtifactCompiler())->compile($artifact)->toWordPressSitePlanView()['wordpress_site_plan'];
$inactive = array_values(array_filter($plan['assets'], static fn(array $asset): bool => false === ($asset['stylesheet_activation']['active'] ?? null)));
$assert(count($inactive) === 2, 'disabled alternate and unselected preferred set remain inactive assets: ' . json_encode(array_map(static fn(array $asset): array => array($asset['source_path'], $asset['stylesheet_activation'] ?? null), $plan['assets'])));
foreach ($inactive as $asset) {
    $assert(array('index.html') === array_column($asset['scopes'], 'source_path'), 'inactive occurrence is scoped to its declaring route');
    $assert('' !== $asset['content'], 'inactive authored declarations remain materialized');
    $assert(!array_filter($plan['theme']['design_token_provenance'], static fn(array $row): bool => $row['source_path'] === $asset['source_path']), 'inactive occurrence supplies no default tokens');
}
$light = array_values(array_filter($plan['assets'], static fn(array $asset): bool => str_contains($asset['source_path'], 'light') && true === ($asset['stylesheet_activation']['active'] ?? null)));
$assert(1 === count($light), 'same payload gets an independent active occurrence on the second route');
$assert(array('second.html') === array_column($light[0]['scopes'], 'source_path'), 'active occurrence does not leak onto first route');
$writes = array_column($plan['writes'], null, 'target_path');
$bootstrap = $writes['functions.php']['payload']['data'];
$assert(str_contains($bootstrap, 'style_loader_tag') && str_contains($bootstrap, "set_attribute( 'disabled', true )"), 'delivery retains native switchable link semantics');
$compiler = new ArtifactCompiler();
$shared = $compiler->prepareShared($artifact);
$staged = $compiler->compose($shared, $compiler->compilePreparedPages($shared, $compiler->preparePages($artifact, $shared)))->toWordPressSitePlanView()['wordpress_site_plan'];
$activationRows = static fn(array $plan): array => array_map(static fn(array $asset): array => array($asset['source_path'], $asset['stylesheet_activation'] ?? null, $asset['scopes']), array_values(array_filter($plan['assets'], static fn(array $asset): bool => isset($asset['stylesheet_activation']))));
$assert($activationRows($plan) === $activationRows($staged), 'staged and whole compilation preserve the same activation variants and declaring routes');
echo "stylesheet activation plan passed\n";
