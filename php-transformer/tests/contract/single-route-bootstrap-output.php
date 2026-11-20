<?php
declare(strict_types=1);

require (getenv('BLOCKS_ENGINE_PHP_TRANSFORMER_ROOT') ?: dirname(__DIR__, 2)) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Shared-root factoring must not change a lone route's generated bootstrap.
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => '<!doctype html><html class="site"><head><title>Home</title></head><body class="home"><p>Content</p></body></html>'),
))->toArray();
$functions = '';
$plan = $result['site_plan'] ?? $result['source_reports']['wordpress_site_plan'];
foreach ($plan['writes'] as $write) {
    if ('functions.php' === ($write['target_path'] ?? '')) $functions = $write['payload']['data'];
}
if ('' === $functions
    || str_contains($functions, '$blocks_engine_document_with_classes')
    || !str_contains($functions, "'attributes' => \$blocks_engine_document_attribute_bodies[ \$attributes ]")
    || !str_contains($functions, "'body_attributes' => \$blocks_engine_document_attribute_bodies[ \$body_attributes ]")
) {
    throw new RuntimeException('A single route must retain direct attribute-table lookups without shared-class reconstruction');
}
echo "Single route bootstrap output passed\n";
