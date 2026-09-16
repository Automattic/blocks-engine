<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CompanionPluginPayload;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\GeneratedArtifactProvenance;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

$generator = static fn (string $suffix): array => array(
    'name' => 'authored-input',
    'block_json' => array( 'apiVersion' => 3, 'name' => 'acme/authored-input', 'title' => 'Input Field ' . $suffix ),
    'assets' => array( 'index.js' => 'console.log(' . strlen($suffix) . ');' ),
    'script_dependencies' => array( 'index.js' => array( 'wp-blocks', 'wp-block-editor', 'wp-element' ) ),
);
$files = static fn (string $content): array => array(
    array( 'path' => 'index.html', 'kind' => 'html', 'content' => $content ),
);
$artifact = array( 'site' => array( 'slug' => 'north-hall' ), 'block_namespace' => 'acme' );

// Compiling the same artifact twice produces the identical provenance record:
// determinism is the whole point of the stamp.
$first = ( new CompanionPluginPayload() )->fromBlockTypes(array(), $files('<main>One</main>'), $artifact, array( $generator('a') ));
$second = ( new CompanionPluginPayload() )->fromBlockTypes(array(), $files('<main>One</main>'), $artifact, array( $generator('a') ));
$assert(isset($first['provenance'], $second['provenance']), 'a non-empty companion payload carries a provenance record');
$assert($first['provenance'] === $second['provenance'], 'compiling the same artifact twice yields an identical provenance record');
$assert(($first['provenance']['artifact_hash'] ?? '') === ($second['provenance']['artifact_hash'] ?? ''), 'the artifact hash is stable across runs of the same input');

// The hash covers the normalized artifact input, so any change to it changes
// the hash and a fleet can select on the difference.
$modifiedFiles = ( new CompanionPluginPayload() )->fromBlockTypes(array(), $files('<main>Two</main>'), $artifact, array( $generator('a') ));
$modifiedBlocks = ( new CompanionPluginPayload() )->fromBlockTypes(array(), $files('<main>One</main>'), $artifact, array( $generator('b') ));
$modifiedArtifact = ( new CompanionPluginPayload() )->fromBlockTypes(array(), $files('<main>One</main>'), array_merge($artifact, array( 'site' => array( 'slug' => 'west-hall' ) )), array( $generator('a') ));
$assert(($first['provenance']['artifact_hash'] ?? '') !== ($modifiedFiles['provenance']['artifact_hash'] ?? ''), 'changed artifact files produce a different artifact hash');
$assert(($first['provenance']['artifact_hash'] ?? '') !== ($modifiedBlocks['provenance']['artifact_hash'] ?? ''), 'changed generated blocks produce a different artifact hash');
$assert(($first['provenance']['artifact_hash'] ?? '') !== ($modifiedArtifact['provenance']['artifact_hash'] ?? ''), 'a changed artifact envelope produces a different artifact hash');

// Input key order is not part of the artifact: the same values reached
// through a differently ordered envelope produce the same digest.
$ordered = ( new GeneratedArtifactProvenance() )->fromArtifactInputs(array(), $files('<main>One</main>'), array('block_namespace' => 'acme', 'site' => array('slug' => 'north-hall')), array( $generator('a') ));
$assert(($first['provenance']['artifact_hash'] ?? '') === ($ordered['artifact_hash'] ?? ''), 'hashing canonicalizes input key order');

// The record identifies its schema, generator, and engine version.
$provenance = $first['provenance'];
$assert(GeneratedArtifactProvenance::SCHEMA === ($provenance['schema'] ?? null), 'the provenance record carries the versioned schema identifier');
$assert('blocks-engine/generated-artifact-provenance/v1' === ($provenance['schema'] ?? null), 'the provenance schema identifier is product-neutral and versioned');
$assert('automattic/blocks-engine-php-transformer' === ($provenance['generator'] ?? null), 'the provenance record names its generating engine');
$assert(GeneratedArtifactProvenance::engineVersion() === ($provenance['engine_version'] ?? null), 'the provenance record carries the engine version');
$assert(trim((string) file_get_contents(dirname(__DIR__, 2) . '/VERSION')) === ($provenance['engine_version'] ?? null), 'the engine version is read from the committed VERSION file without the plugin bootstrap');
$assert(1 === preg_match('/^[0-9a-f]{64}$/', (string) ($provenance['artifact_hash'] ?? '')), 'the artifact hash is a sha256 hex digest');
$assert(array( 'schema', 'generator', 'engine_version', 'artifact_hash' ) === array_keys($provenance), 'the provenance record exposes exactly its four contract fields');

// A payload with no blocks, no preserved JS, and no editor scripts stays empty:
// provenance alone never turns an empty payload into a materialized plugin.
$empty = ( new CompanionPluginPayload() )->fromBlockTypes(array(), $files('<main>Empty</main>'), $artifact);
$assert(array() === $empty, 'an empty companion payload stays empty despite provenance existing');

if ( 0 < $failures ) {
    fwrite(STDERR, "Generated artifact provenance tests: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "Generated artifact provenance tests: {$passes} passed" . PHP_EOL);
