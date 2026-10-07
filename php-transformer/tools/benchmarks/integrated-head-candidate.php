<?php
declare(strict_types=1);

$environment = getenv('BLOCKS_ENGINE_INTEGRATED_OPTIONS');
$options = $environment ? json_decode($environment, true, flags: JSON_THROW_ON_ERROR) : getopt('', array('input:', 'receipt:', 'output:', 'theme-uri:', 'autoload:'));
require $options['autoload'] ?? dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentHeadContext;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$input = json_decode(file_get_contents($options['input']), true, flags: JSON_THROW_ON_ERROR);
$receipt = json_decode(file_get_contents($options['receipt']), true, flags: JSON_THROW_ON_ERROR);
$servedPlan = $receipt['blocks_engine']['wordpress_site_plan'];
$input['schema'] = ArtifactCompiler::INPUT_SCHEMA;
$input['reports'] = array();
$input['block_namespace'] = $receipt['companion_plugin_materialization']['block_namespace'] ?? 'custom';
$input['compiler_limits'] = array('max_files' => 5000, 'max_file_bytes' => 33554432, 'max_total_bytes' => 268435456, 'max_media_file_bytes' => 33554432, 'max_media_total_bytes' => 268435456);
$compiler = new ArtifactCompiler();
$shared = $compiler->prepareShared($input);
$result = $compiler->compose($shared, $compiler->compilePreparedPages($shared, $compiler->preparePages($input, $shared)))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$resolved = (new WordPressSitePlanResolver())->resolve($plan, array('theme_uri' => $options['theme-uri'], 'require_proven_dynamic_client_assets' => true));
$output = $options['output'];
if (file_exists($output)) throw new InvalidArgumentException('Use a fresh evidence output.');
mkdir($output, 0777, true);
$json = static fn(mixed $value): string => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
file_put_contents($output . '/input.json', $json($input));
file_put_contents($output . '/result.json', $json($result));
file_put_contents($output . '/plan.json', $json($resolved));
foreach ($resolved['writes'] as $write) {
    if ('utf8' !== $write['payload']['encoding']) continue;
    $target = $output . '/theme/' . $write['target_path'];
    if (!is_dir(dirname($target))) mkdir(dirname($target), 0777, true);
    file_put_contents($target, $write['payload']['data']);
}
$head = WordPressSitePlanResolver::resolvePayload(DocumentHeadContext::fromPlan($plan, $plan['pages'][0]['source_path']), WordPressSitePlanResolver::references($plan['reference_tokens'], $options['theme-uri']));
file_put_contents($output . '/head.html', $head);
$pattern = '/blocks-engine-source-[a-z][a-z0-9-]*-([a-f0-9]{12})-[0-9]+/';
preg_match_all($pattern, $plan['pages'][0]['canonical_block_markup'], $candidate);
preg_match_all($pattern, $servedPlan['pages'][0]['canonical_block_markup'], $served);
$summary = array('input_hash' => hash('sha256', $json($input)), 'source_document_hashes' => $plan['source']['source_documents'], 'candidate_seeds' => array_values(array_unique($candidate[1])), 'served_seeds' => array_values(array_unique($served[1])), 'runtime_parity' => $result['source_reports']['runtime_dependency_parity'], 'errors' => array_values(array_filter($result['diagnostics'], static fn(array $row): bool => 'error' === ($row['severity'] ?? ''))));
file_put_contents($output . '/summary.json', $json($summary));
print $json($summary);
