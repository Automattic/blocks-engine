<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Single-document plans must retain the direct attribute table path: class
// reconstruction is only needed when multiple documents share class tokens.
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => '<!doctype html><html lang="en" class="site"><head><title>Note</title></head><body class="page"><main><h1>Note</h1><p>Content.</p></main></body></html>'),
))->toArray();
$plan = $result['site_plan'] ?? $result['source_reports']['wordpress_site_plan'];
$bootstrap = '';
foreach ($plan['writes'] as $write) {
    $data = $write['payload']['data'] ?? null;
    if (is_string($data) && str_contains($data, '$blocks_engine_document_rows')) $bootstrap .= $data;
}
if ('' === $bootstrap) throw new RuntimeException('Missing document bootstrap.');
if (str_contains($bootstrap, '$blocks_engine_document_with_classes') || str_contains($bootstrap, '$blocks_engine_document_shared_classes')) {
    throw new RuntimeException('A single document must not emit shared-class reconstruction.');
}
if (!str_contains($bootstrap, "'attributes' => \$blocks_engine_document_attribute_bodies[ \$attributes ]")) {
    throw new RuntimeException('Single-document attributes must use the direct table path.');
}
echo "Single document bootstrap classes passed\n";
