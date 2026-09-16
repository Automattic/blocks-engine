<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CompanionPluginPayload;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

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

$payload = new CompanionPluginPayload();

// A declared namespace is honored verbatim when it is a valid WordPress block
// namespace, so the consumer owns every generated block name in one place.
$assert('acme' === $payload->blockNamespace(array('block_namespace' => 'acme', 'site' => array('slug' => 'ignored-site'))), 'valid declared namespace wins over the site-derived fallback');
$assert('my-site-2' === $payload->blockNamespace(array('block_namespace' => ' my-site-2 ')), 'declared namespace is trimmed before validation');

// The reserved core namespace and non-namespace shapes are rejected and fall
// back to today's derivation, so no consumer can collide with core.
$assert('ssi-shop' === $payload->blockNamespace(array('block_namespace' => 'core', 'site' => array('slug' => 'shop'))), 'the reserved core namespace falls back to the site derivation');
$assert('ssi-shop' === $payload->blockNamespace(array('block_namespace' => 'Acme', 'site' => array('slug' => 'shop'))), 'an uppercase namespace falls back to the site derivation');
$assert('ssi-shop' === $payload->blockNamespace(array('block_namespace' => 'acme_site', 'site' => array('slug' => 'shop'))), 'an underscored namespace falls back to the site derivation');
$assert('ssi-shop' === $payload->blockNamespace(array('block_namespace' => '2acme', 'site' => array('slug' => 'shop'))), 'a namespace without a leading letter falls back to the site derivation');
$assert('ssi-shop' === $payload->blockNamespace(array('block_namespace' => '', 'site' => array('slug' => 'shop'))), 'an empty declared namespace falls back to the site derivation');

// The fallback itself is byte-identical to the previous derivation.
$assert('ssi-north-hall' === $payload->blockNamespace(array('site' => array('slug' => 'north-hall'))), 'an artifact without a declared namespace keeps the ssi site-slug derivation');
$assert('ssi-foundation' === $payload->blockNamespace(array('name' => 'Foundation')), 'site identity still resolves through the artifact name');
$assert('' === $payload->blockNamespace(array()), 'an artifact without identity or namespace still resolves to an empty namespace');

// The declared namespace reaches the generated blocks of a full compile: the
// artifact's own generated blocks stop being globally named.
$compiled = ( new ArtifactCompiler() )->compile(array(
    'block_namespace' => 'acme',
    'files' => array(
        'index.html' => '<link rel="stylesheet" href="site.css"><main><input class="field" type="text"></main>',
        'site.css' => '.field{border:1px solid;padding:1rem}',
    ),
))->toArray();
$compiledGenerated = $compiled['source_reports']['companion_plugin_payload']['blocks'] ?? array();
$assert('custom' !== ($compiledGenerated[0]['block_json']['name'] ?? '') && 'acme/authored-input' === ($compiledGenerated[0]['block_json']['name'] ?? null), 'a compiled artifact honors its declared block namespace for generated blocks');
$assert(str_contains((string) ($compiled['serialized_blocks'] ?? ''), 'wp:acme/authored-input'), 'compiled markup references the consumer-owned generated block name');

// The transform option resolves the same way for standalone transforms, and
// the fallback namespace for callers that supply none stays 'custom'.
$namespaced = ( new HtmlTransformer() )->transform('<main><dl><dt>Term</dt><dd>Definition</dd></dl></main>', array('generated_block_namespace' => 'acme'))->toArray();
$assert('acme/description-list' === ($namespaced['blocks'][0]['blockName'] ?? null), 'the generated_block_namespace option resolves the description-list companion name');
$defaulted = ( new HtmlTransformer() )->transform('<main><dl><dt>Term</dt><dd>Definition</dd></dl></main>')->toArray();
$assert('custom/description-list' === ($defaulted['blocks'][0]['blockName'] ?? null), 'a standalone transform without a namespace keeps the custom default');

if ( 0 < $failures ) {
    fwrite(STDERR, "Companion plugin namespace tests: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "Companion plugin namespace tests: {$passes} passed" . PHP_EOL);
