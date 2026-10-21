<?php
declare(strict_types=1);

ini_set('memory_limit', '1G');
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeEntityManifest;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) { ++$failures; fwrite(STDERR, "FAIL: {$message}\n"); }
};
$collection = static fn (array $entities): array => array('kind' => 'entity_collection', 'type' => 'widgets', 'source_path' => 'index.html', 'payload' => array('schema' => 'example/widgets/v1', 'entities' => $entities));

// An oversized entity AFTER a small one must not bypass the chunk guard.
try {
    $factored = RuntimeDeclarations::factor(array($collection(array(array('id' => 'small'), array('id' => 'large', 'text' => str_repeat('x', RuntimeDeclarations::MAX_PAYLOAD_BYTES + 1))))));
    foreach ($factored['records'] as $record) $assert($record['bytes'] <= RuntimeDeclarations::MAX_PAYLOAD_BYTES, 'Every factored record fits the byte limit.');
    $assert(false, 'factor rejects an entity no record can carry.');
} catch (InvalidArgumentException $error) {
    $assert(str_contains($error->getMessage(), 'contains an entity that exceeds the byte limit'), 'factor checks the new chunk entity: ' . $error->getMessage());
}

// Collections can still span several bounded records without losing entities.
$bounded = array(array('id' => 'small'), array('id' => 'one', 'text' => str_repeat('x', 3000000)), array('id' => 'two', 'text' => str_repeat('y', 3000000)));
$factored = RuntimeDeclarations::factor(array($collection($bounded)));
$assert(2 === count($factored['records']), 'Representable entities split into bounded records.');
foreach ($factored['records'] as $record) $assert($record['bytes'] <= RuntimeDeclarations::MAX_PAYLOAD_BYTES && strlen(RuntimeDeclarations::canonicalJson($record['payload'])) <= RuntimeDeclarations::MAX_PAYLOAD_BYTES, 'Every emitted record fits the byte limit.');
$assert($bounded === RuntimeDeclarations::materialize($factored['declarations'], $factored['records'])[0]['payload']['entities'], 'Factoring preserves representable entities.');

// Recompose a real compiler envelope. The shared block is modest, but one
// entity references it many times, as shared collection bindings can do.
$envelope = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<main><p>' . str_repeat('Neutral content. ', 9000) . '</p></main>')))->toArray();
// Use the emitted native paragraph, not the surrounding document wrapper.
$plan = $envelope['source_reports']['wordpress_site_plan'];
$markup = $plan['pages'][0]['canonical_block_markup'];
$start = strpos($markup, '<!-- wp:paragraph');
$end = strpos($markup, '<!-- /wp:paragraph -->', $start);
$paragraph = substr($markup, $start, $end + strlen('<!-- /wp:paragraph -->') - $start);
$binding = array('schema' => 'generic/block-binding/v1', 'role' => 'commerce_collection', 'source_path' => 'index.html', 'search_block_markup' => $paragraph, 'occurrence' => 1);
$entities = array(array('id' => 'small', 'bindings' => array($binding)), array('id' => 'large', 'bindings' => array_fill(0, 40, $binding)));
$assert(strlen(RuntimeDeclarations::canonicalJson($entities[1]['bindings'])) > RuntimeDeclarations::MAX_PAYLOAD_BYTES, 'The second entity bindings exceed 5 MiB.');
$envelope['source_reports']['compiled_site']['runtime_declarations'] = array($collection($entities));
$envelope['source_reports']['compiled_site']['runtime_records'] = array();
$envelope['source_reports']['compiled_site']['runtime_entity_records'] = array();
try {
    $composed = (new WordPressSitePlan())->fromResult($envelope);
    WordPressSitePlan::assertValid($composed);
    $dropped = array_values(array_filter($composed['diagnostics'], static fn (array $row): bool => 'runtime_entity_dropped' === ($row['code'] ?? null)));
    $assert(1 === count($dropped), 'Exactly one oversized entity is reported.');
    $finding = $dropped[0] ?? array();
    $assert('runtime_entity_exceeds_byte_limit' === ($finding['reason_code'] ?? null) && 'warning' === ($finding['severity'] ?? null), 'The existing drop finding and severity are used.');
    $assert(($finding['metrics']['bytes'] ?? 0) > RuntimeDeclarations::MAX_PAYLOAD_BYTES && RuntimeDeclarations::MAX_PAYLOAD_BYTES === ($finding['metrics']['limit_bytes'] ?? null), 'The finding reports measured bytes and limit.');
    $kept = array();
    foreach (RuntimeDeclarations::materialize($composed['runtime_declarations'], $composed['runtime_records']) as $declaration) {
        if ('widgets' !== ($declaration['type'] ?? null)) continue;
        $kept = RuntimeEntityManifest::SCHEMA === $declaration['payload']['schema'] ? RuntimeEntityManifest::resolve($declaration['payload'], $composed['runtime_entity_records']) : $declaration['payload']['entities'];
    }
    $assert(array('small') === array_column($kept, 'id') && 1 === count($kept[0]['bindings'] ?? array()), 'Only the small entity and its binding survive.');
    $assert($markup === $composed['pages'][0]['canonical_block_markup'], 'Dropped entity blocks remain static and unchanged.');
    $assert('success_with_warnings' === $composed['quality']['status'] && in_array('runtime_entity_dropped', $composed['reporting']['diagnostic_codes'], true), 'The valid plan reports the warning.');
} catch (InvalidArgumentException $error) {
    $assert(false, 'Composition remains valid: ' . $error->getMessage());
}
// Factoring must still fail closed on aggregate metadata and declaration count.
foreach (array(
    'aggregate canonical byte limit' => array(array('kind' => 'dependency', 'capability' => 'widget', 'source_path' => 'index.html', 'payload' => array('schema' => 'example/metadata/v1', 'text' => str_repeat('x', RuntimeDeclarations::MAX_TOTAL_DECLARATION_BYTES - 128)))),
    'bounded ordered collection' => array_fill(0, 101, $collection(array(array('id' => 'small')))),
) as $message => $declarations) {
    try {
        RuntimeDeclarations::normalizeForComposition($declarations);
        $assert(false, 'Aggregate bounds fail closed: ' . $message);
    } catch (InvalidArgumentException $error) {
        $assert(str_contains($error->getMessage(), $message), 'Aggregate bounds fail closed: ' . $error->getMessage());
    }
}
if ($failures) exit(1);
echo "runtime entity composition byte limit passed\n";
