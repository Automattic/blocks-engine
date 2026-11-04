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
    array('path' => 'style.css', 'content' => 'h1{color:red}'),
));
$compiler = new ArtifactCompiler();
$shared = $compiler->prepareShared($artifact);
$pages = $compiler->compilePreparedPages($shared, $compiler->preparePages($artifact, $shared));
$result = $compiler->compose($shared, $pages);
$assertTimings($result, array('compose.receipt_validation', 'compose.receipt_reduction',
    'compose.author_stylesheet_projections', 'compose.author_stylesheet_projections.route_documents',
    'compose.author_stylesheet_projections.route_variants', 'compose.author_stylesheet_projections.route_budget',
    'compose.compaction', 'compose.serialization', 'compose.from_compiler_input', 'compose.result_composition'));
$again = $compiler->compose($shared, $pages);
$check($result->serializedBlocks === $again->serializedBlocks, 'Compose markup changed');
$plan = new WordPressSitePlan();
$without = $result->toArray();
unset($without['metrics']['step_timings']);
$check($plan->fromResult($without)['plan_identity'] === $plan->fromResult($result)['plan_identity'], 'Timings changed canonical plan hash');
$check($plan->fromResult($again)['plan_identity'] === $plan->fromResult($result)['plan_identity'], 'Repeated composition changed identity');
echo "Step timing contract passed\n";
