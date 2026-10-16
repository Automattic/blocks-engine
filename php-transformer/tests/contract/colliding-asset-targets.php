<?php
declare(strict_types=1);

/**
 * Two compiled assets that resolve to one theme target are an asset-local
 * limit, not a plan failure. Identical bytes are written once; different
 * bytes get a deterministic suffixed target with its own token. Either way
 * the plan records a `wordpress_site_plan_colliding_asset_target` finding,
 * and the strict validator still rejects a plan that declares a collision.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
$findings = static fn (array $plan): array => array_values(array_filter(
    $plan['diagnostics'] ?? array(),
    static fn (array $diagnostic): bool => 'wordpress_site_plan_colliding_asset_target' === ($diagnostic['code'] ?? null)
));

// The same run of inline styles on either side of a print-only style coalesces
// into two bundles with one content-addressed target.
$run = '<style>.a{color:#c00}</style><style>.b{color:#00c}</style>';
$artifact = array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head>' . $run . '<style media="print">.a{color:#000}</style>' . $run . '</head><body><main><h1 class="a">Kept heading</h1></main></body></html>',
));
$compiler = new ArtifactCompiler();
$result = $compiler->compile($artifact)->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? null;
$assert(is_array($plan) && 'failed' !== $result['status'], 'Two compiled assets with one target and identical bytes no longer fail the plan.');
WordPressSitePlan::assertValid($plan);
$bundles = array_values(array_filter($plan['assets'], static fn (array $asset): bool => 'stylesheet-bundle' === ($asset['source'] ?? null)));
$assert(1 === count($bundles) && 1 === preg_match('/\.a\{color:(?:#c00|var\(--wp--preset--color--[a-z0-9-]+,#c00\))\}/', (string) $bundles[0]['content']), 'The identical bundle is written once.');
$targets = array_column($plan['assets'], 'target_path');
$assert(array_search($bundles[0]['target_path'], $targets, true) > array_search('assets/index.inline-3.css', $targets, true), 'The kept bundle is the later occurrence, so it still follows the print-only style in the cascade.');
$found = $findings($plan);
$assert(1 === count($found) && 'warning' === $found[0]['severity'] && 'asset' === $found[0]['unit'] && 'identical_content' === $found[0]['reason_code'] && $bundles[0]['target_path'] === $found[0]['target_path'] && $found[0]['target_path'] === $found[0]['resolved_target_path'], 'The deduplicated target is reported once as an asset-unit finding.');
$assert(in_array('wordpress_site_plan_colliding_asset_target', $plan['reporting']['diagnostic_codes'], true), 'The finding is linked to plan reporting.');
$assert('success_with_warnings' === $plan['quality']['status'] && true === $plan['quality']['pass'], 'The finding raises the plan status to success_with_warnings.');
$shared = $compiler->prepareShared($artifact);
$staged = $compiler->compose($shared, $compiler->compilePreparedPages($shared, $compiler->preparePages($artifact, $shared)))->toArray();
$assert(($staged['source_reports']['wordpress_site_plan'] ?? null) === $plan, 'Staged compilation produces the same plan.');

// Different bytes on one target: the later asset is given the next free
// numbered target, and its token follows that target.
$source = static fn (string $path, string $content): array => array('path' => $path, 'target_path' => 'css/site.css', 'source' => 'files', 'kind' => 'css', 'role' => 'stylesheet', 'mime_type' => 'text/css', 'bytes' => strlen($content), 'hash' => hash('sha256', $content), 'content' => $content);
$builder = new WordPressSitePlan();
$rows = ( new ReflectionMethod(WordPressSitePlan::class, 'assets') )->invoke($builder, array($source('css/site.css', 'a{color:red}'), $source('css/Site.css', 'b{color:blue}'), $source('css/SITE.css', 'i{color:green}')));
$assert(array('assets/css/site.css', 'assets/css/site-2.css', 'assets/css/site-3.css') === array_column($rows, 'target_path'), 'Different bytes on one target get deterministic suffixed targets: ' . json_encode(array_column($rows, 'target_path')));
foreach ($rows as $row) $assert($row['token'] === 'asset-' . substr(hash('sha256', $row['target_path']), 0, 16) && $row['reconciliation_identity'] === WordPressSitePlan::identity('asset', $row['source_path'], $row['target_path']), 'Each suffixed asset has a token and identity derived from its own target.');
$suffixFindings = ( new ReflectionMethod(WordPressSitePlan::class, 'unitFindingDiagnostics') )->invoke($builder);
$assert(2 === count($suffixFindings) && array('colliding_asset_target', 'colliding_asset_target') === array_column($suffixFindings, 'reason_code') && array('assets/css/site-2.css', 'assets/css/site-3.css') === array_column($suffixFindings, 'resolved_target_path'), 'Each suffixed target is reported with the target it was given.');

// The validator is still the backstop for a plan that declares a collision.
$collidingPlan = $plan;
$collidingPlan['assets'][] = $bundles[0];
$rejected = false;
try {
    WordPressSitePlan::assertValid($collidingPlan);
} catch ( InvalidArgumentException $error ) {
    $rejected = str_contains($error->getMessage(), 'colliding asset targets');
}
$assert($rejected, 'The strict plan validator still rejects colliding asset targets.');

echo "colliding-asset-targets contract passed\n";
