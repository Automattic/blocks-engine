<?php
declare(strict_types=1);

/**
 * The staged compilation flow re-normalizes already-normalized file lists
 * (prepareShared/preparePage envelopes, compose digest verification, the
 * final compile), so normalize() must be a fixed point on its own output:
 * re-normalizing must not re-expand inline assets into duplicate generated
 * files, rename paths, or change hashes.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactNormalizer;

$failures = 0;
$passes   = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$artifact = array(
    'entrypoints' => array( 'index.html' ),
    'files'       => array(
        array(
            'path'     => 'index.html',
            'content'  => '<style>main{color:#123}</style><main><h1>Home</h1></main><script>document.title="Home";</script><script>console.log("second");</script>',
            'metadata' => array( 'compilation' => array( 'scope' => 'page', 'id' => 'index.html' ) ),
        ),
        array(
            'path'     => 'assets/site.css',
            'content'  => 'body{margin:0}',
            'metadata' => array( 'compilation' => array( 'scope' => 'shared' ) ),
        ),
    ),
);

$first = (new ArtifactNormalizer())->normalize($artifact);
$again = (new ArtifactNormalizer())->normalize(array( 'entrypoints' => $first['entrypoints'], 'files' => $first['files'] ));

$paths = array_column($first['files'], 'path');
$assert(count($paths) > count($artifact['files']), 'Inline styles and scripts are expanded into generated files.', implode(', ', $paths));
$assert($again['files'] === $first['files'], 'Re-normalizing normalized files does not re-expand, rename, or reorder them.', implode(', ', array_column($again['files'], 'path')));
$assert($again['entrypoints'] === $first['entrypoints'], 'Entrypoints are stable across re-normalization.');
$assert($again['bytes'] === $first['bytes'], 'Byte accounting is stable across re-normalization.');
$assert($again['hash_payload'] === $first['hash_payload'], 'The source-identity hash payload is stable across re-normalization.');
$assert(64 === strlen($first['hash_payload']), 'The retained source-identity payload is bounded to one SHA-256 digest.');
$legacyPayload = '';
$hashFiles = $first['files'];
usort($hashFiles, static fn(array $left, array $right): int => strcmp((string) $left['path'], (string) $right['path']));
foreach ( $hashFiles as $file ) {
    $content = isset($file['content_base64']) ? (string) $file['content_base64'] : (string) $file['content'];
    $legacyPayload .= $file['path'] . "\0" . $file['kind'] . "\0" . ($file['mime_type'] ?? '') . "\0" . $content . "\0";
}
$legacyPayload .= "\n" . \Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations::canonicalJson($first['runtime_declarations']);
$assert(hash('sha256', $legacyPayload) === $first['source_hash'], 'Streaming source identity preserves the legacy canonical hash.');

foreach ( $first['files'] as $file ) {
    if ( ! str_starts_with((string) $file['path'], 'index.inline') ) {
        continue;
    }
    $assert(
        array( 'compilation' => array( 'scope' => 'page', 'id' => 'index.html' ) ) === ($file['metadata'] ?? null),
        'Inline-expanded files inherit the parent file\'s compilation ownership.',
        (string) $file['path']
    );
}

if ( $failures > 0 ) {
    fwrite(STDERR, sprintf('artifact-normalizer-idempotence: %d passed, %d failed%s', $passes, $failures, PHP_EOL));
    exit(1);
}

fwrite(STDOUT, sprintf('artifact-normalizer-idempotence: %d passed%s', $passes, PHP_EOL));
