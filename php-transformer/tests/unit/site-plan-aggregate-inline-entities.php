<?php
declare(strict_types=1);

ini_set('memory_limit', '1G');
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeEntityManifest;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$envelope = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<main><p>Store</p></main>')))->toArray();
$declarations = array();
foreach (array('products' => 0, 'forms' => 350316) as $type => $bytes) {
    $declarations[] = array('kind' => 'entity_collection', 'type' => $type, 'source_path' => 'index.html', 'payload' => array('schema' => 'generic/' . $type . '/v1', 'entities' => array(array('id' => $type, 'text' => str_repeat('x', $bytes)))));
}
// The limit counts derived identities but not payload_hash/content_hash. Land
// just over it: the former factor() estimate incorrectly considered this fit.
$normalized = RuntimeDeclarations::normalizeForComposition($declarations);
$total = 0;
foreach ($normalized as $declaration) {
    unset($declaration['payload_hash'], $declaration['content_hash']);
    $total += strlen(RuntimeDeclarations::canonicalJson($declaration));
}
$declarations[0]['payload']['entities'][0]['text'] = str_repeat('p', RuntimeDeclarations::MAX_TOTAL_DECLARATION_BYTES - $total + 1);
$envelope['source_reports']['compiled_site']['runtime_declarations'] = $declarations;
$envelope['source_reports']['compiled_site']['runtime_records'] = array();
$envelope['source_reports']['compiled_site']['runtime_entity_records'] = array();
$plan = (new WordPressSitePlan())->fromResult($envelope);
RuntimeDeclarations::normalizeList($plan['runtime_declarations']);
WordPressSitePlan::assertValid($plan);
$found = array();
foreach ($plan['runtime_declarations'] as $declaration) {
    if ('entity_collection' !== $declaration['kind']) continue;
    $payload = $declaration['payload'];
    $entities = RuntimeEntityManifest::SCHEMA === $payload['schema'] ? RuntimeEntityManifest::resolve($payload, $plan['runtime_entity_records']) : $payload['entities'];
    $found[$declaration['type']] = $entities;
    if ('products' === $declaration['type'] && RuntimeEntityManifest::SCHEMA !== $payload['schema']) throw new RuntimeException('Products must be factored, not left inline.');
}
foreach ($declarations as $declaration) {
    if (($found[$declaration['type']] ?? null) !== $declaration['payload']['entities']) throw new RuntimeException('Composition must preserve both collections without dropping entities.');
}
echo "site plan aggregate inline entities passed\n";
