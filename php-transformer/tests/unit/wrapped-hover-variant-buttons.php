<?php

declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$plan = (new ArtifactCompiler())->compile(require dirname(__DIR__) . '/fixtures/design-system/wrapped-hover-variant-buttons.php')->toArray()['source_reports']['wordpress_site_plan'];
$markup = implode('', array_column($plan['pages'], 'canonical_block_markup')) . implode('', array_column($plan['template_parts'], 'canonical_block_markup'));
$errors = array();
preg_match_all('/<!-- wp:button (\{.*?\}) -->/', $markup, $buttons);
if (2 !== count($buttons[1])) $errors[] = 'Expected two buttons';
foreach ($buttons[1] as $json) {
    $attributes = json_decode($json, true);
    foreach (array('btn', 'bg-blue-600', 'hover:bg-blue-700', 'text-white') as $class) {
        $marker = \Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker::subjectClassClass($class);
        if (!in_array($marker, explode(' ', $attributes['className'] ?? ''), true)) $errors[] = 'Missing source class carrier: ' . $class;
    }
    if (isset($attributes['style']['color']['background']) || isset($attributes['backgroundColor'])) $errors[] = 'Button must render its background through the class stylesheet';
}
$metrics = $plan['theme']['design_system']['metrics'];
if (0 !== $metrics['refusal_reasons']['state_variant']) $errors[] = 'Expected zero state_variant refusals';
if (0 !== $metrics['refusal_reasons']['alpha']) $errors[] = 'Opaque /1 and /var(--tw-bg-opacity,1) are not alpha refusals';
if (1.0 !== (float) $metrics['restyle_coverage']) $errors[] = 'Expected full restyle coverage';
echo json_encode(array('buttons' => count($buttons[1]), 'state_variant' => $metrics['refusal_reasons']['state_variant'], 'alpha' => $metrics['refusal_reasons']['alpha'], 'restyle_coverage' => $metrics['restyle_coverage'])) . "\n";
foreach ($errors as $error) fwrite(STDERR, "FAIL: $error\n");
if (array() !== $errors) exit(1);
echo "Wrapped hover-variant buttons passed\n";
