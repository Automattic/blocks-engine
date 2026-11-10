<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$html = '<!doctype html><html><head><title>Timing</title><link rel="stylesheet" href="style.css"></head><body><main><h1>Timing</h1><p>Hello world.</p></main></body></html>';
$compile = (new HtmlTransformer())->transform($html);
foreach (array(HtmlTransformer::class, \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlCompilation::class) as $class) {
    $check(2 === (new ReflectionMethod($class, 'transform'))->getNumberOfParameters(), 'Public transform signature must match trunk');
}
$replacementMetrics = array('replacement' => 1, 'step_timings' => array());
$copy = $compile->withMetrics($replacementMetrics);
$check($copy !== $compile && $copy->metrics === $replacementMetrics, 'withMetrics must return a copy with replacement metrics');
foreach (get_object_vars($compile) as $field => $value) {
    if ('metrics' !== $field) {
        $check($copy->$field === $value, 'withMetrics dropped result field: ' . $field);
    }
}
$check(isset($compile->metrics['transform_duration_ms']), 'withMetrics mutated the original');
$timedCopy = (new \Automattic\BlocksEngine\PhpTransformer\Support\StepTimer('test.copy'))->result($compile);
foreach (get_object_vars($compile) as $field => $value) {
    if ('metrics' !== $field) {
        $check($timedCopy->$field === $value, 'StepTimer dropped result field: ' . $field);
    }
}
$assertTimings = static function ($result, array $names) use ($check): void {
    $steps = $result->metrics['step_timings'];
    $check(array() === array_diff($names, array_column($steps, 'step')), 'Missing timing steps');
    foreach ($steps as $step) {
        $check($step['ms'] >= 0 && $step['calls'] > 0, 'Invalid timing observation');
    }
    $check(array_sum(array_column($steps, 'ms')) >= $result->metrics['transform_duration_ms'] * .95, 'Timings must cover the phase');
};
$assertTimings($compile, array(
    'transform.source_preparation', 'transform.source_parse', 'transform.source_normalization',
    'transform.author_selector_preparation', 'transform.conversion', 'transform.fallback_reduction',
    'transform.class_identity_rewrite', 'transform.validation.block_validity',
    'transform.validation.semantic_parity', 'transform.validation.content_round_trip',
    'transform.validation.media_retention', 'transform.validation.dead_selectors',
    'transform.validation.editability', 'transform.validation.design_usage', 'transform.result_composition',
));
$check($compile->serializedBlocks === (new HtmlTransformer())->transform($html)->serializedBlocks, 'Compile markup changed');
$artifact = array('entrypoint' => 'index.html', 'files' => array(
    array('path' => 'index.html', 'content' => $html),
    array('path' => 'about.html', 'content' => str_replace('Timing', 'About', $html)),
    array('path' => 'style.css', 'content' => 'h1{color:red}'),
));
$compiler = new ArtifactCompiler();
$shared = $compiler->prepareShared($artifact);
$pagePlans = $compiler->preparePages($artifact, $shared);
$pages = $compiler->compilePreparedPages($shared, $pagePlans);
$check(2 === count($pages), 'Fixture must compile multiple page receipts');
$withoutTimings = $pages;
$digestInput = new ReflectionMethod(ArtifactCompiler::class, 'pagePlanDigestInput');
foreach ($pages as $pageId => $receipt) {
    $steps = $receipt['work']['step_timings'];
    $check(array_column($steps, 'step') === array_column($compile->metrics['step_timings'], 'step'), 'Receipt must retain ordered transform steps without batch accumulation');
    foreach ($steps as $step) {
        $check($step['ms'] >= 0 && $step['calls'] > 0, 'Invalid receipt timing observation');
    }
    foreach ($receipt['compiled_documents'] as $document) {
        $check(!isset($document['step_timings']), 'Timings leaked into digest-bound documents');
    }
    unset($withoutTimings[$pageId]['work']['step_timings']);
    $check($digestInput->invoke($compiler, $receipt) === $digestInput->invoke($compiler, $withoutTimings[$pageId]), 'Timings changed receipt digest input');
}
$repeatedPages = $compiler->compilePreparedPages($shared, $pagePlans);
foreach ($pages as $pageId => $receipt) {
    $check($receipt['digest'] === $repeatedPages[$pageId]['digest'], 'Repeated page timings changed receipt digest');
    unset($receipt['work'], $repeatedPages[$pageId]['work']);
    $check($receipt === $repeatedPages[$pageId], 'Repeated page timings changed canonical receipt');
}
$result = $compiler->compose($shared, $pages);
$untimedResult = $compiler->compose($shared, $withoutTimings);
$check($result->serializedBlocks === $untimedResult->serializedBlocks, 'Receipt timings changed composition');
$assertTimings($result, array('compose.receipt_validation', 'compose.receipt_reduction',
    'compose.author_stylesheet_projections', 'compose.author_stylesheet_projections.route_documents',
    'compose.author_stylesheet_projections.route_variants', 'compose.author_stylesheet_projections.route_budget',
    'compose.compaction', 'compose.serialization', 'compose.from_compiler_input', 'compose.result_composition',
    // Site-plan composition reports each stage, so the largest compose step is never opaque.
    'compose.site_plan.documents', 'compose.site_plan.design_system', 'compose.site_plan.routes',
    'compose.site_plan.pages', 'compose.site_plan.entity_bindings', 'compose.site_plan.theme_json',
    'compose.site_plan.shell_extraction', 'compose.site_plan.stylesheets', 'compose.site_plan.templates',
    'compose.site_plan.asset_writes', 'compose.site_plan.plan_assembly', 'compose.site_plan.asset_consumers'));
$again = $compiler->compose($shared, $pages);
$check($result->serializedBlocks === $again->serializedBlocks, 'Compose markup changed');
$plan = new WordPressSitePlan();
$check($plan->fromResult($untimedResult)['plan_identity'] === $plan->fromResult($result)['plan_identity'], 'Receipt timings changed canonical plan identity');
$without = $result->toArray();
unset($without['metrics']['step_timings']);
$check($plan->fromResult($without)['plan_identity'] === $plan->fromResult($result)['plan_identity'], 'Timings changed canonical plan hash');
$check($plan->fromResult($again)['plan_identity'] === $plan->fromResult($result)['plan_identity'], 'Repeated composition changed identity');
echo "Step timing contract passed\n";
