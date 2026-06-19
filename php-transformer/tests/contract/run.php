<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message, string $detail = ''): void {
    if ( $condition ) {
        return;
    }

    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
    exit(1);
};

$result = ( new HtmlTransformer() )->transform('<main><h1>Hello</h1></main>')->toArray();

$assert(TransformerResult::SCHEMA === $result['schema'], 'result exposes schema');

foreach ( array( 'status', 'components', 'block_types', 'source_reports', 'legacy_mapping', 'blocks', 'serialized_blocks', 'documents', 'assets', 'diagnostics', 'fallbacks', 'provenance', 'coverage' ) as $key ) {
    $assert(array_key_exists($key, $result), "Missing result key: {$key}");
}

$compiler = new ArtifactCompiler();

$simple = $compiler->compile(
    array(
        'generated_html' => '<main><article data-component="Hero"><h1>Hello artifact</h1></article></main>',
    )
)->toArray();
$assert('success' === $simple['status'], 'simple artifact compiles successfully', (string) $simple['status']);
$assert('index.html' === ($simple['source_reports']['artifact']['entry_path'] ?? ''), 'generated HTML becomes an index entry');
$assert(str_contains((string) $simple['serialized_blocks'], '<!-- wp:html -->'), 'HTML is preserved as serialized block markup');
$assert('Hero' === ($simple['components'][0]['name'] ?? ''), 'component candidates are exposed');
$assert('serialized_blocks' === ($simple['legacy_mapping']['block-artifact-compiler/result/v1']['wordpress_artifacts.block_markup'] ?? ''), 'BAC block markup mapping is documented');

$missing = $compiler->compile(array('files' => array()))->toArray();
$assert('failed' === $missing['status'], 'missing HTML fails explicitly', (string) $missing['status']);
$assert('missing_entry_html' === ($missing['diagnostics'][0]['code'] ?? ''), 'missing entry diagnostic is exposed');

$unsafe = $compiler->compile(
    array(
        'entrypoints' => array('../unsafe.html'),
        'files'       => array(
            '../secret.html' => '<main>Nope</main>',
            'safe.html'     => '<main>Safe</main>',
        ),
    )
)->toArray();
$assert('success_with_warnings' === $unsafe['status'], 'unsafe paths produce warning status', (string) $unsafe['status']);
$assert(1 === ($unsafe['source_reports']['artifact']['rejected_count'] ?? null), 'unsafe paths are rejected');
$assert('unsafe_entrypoint_path' === ($unsafe['diagnostics'][0]['code'] ?? ''), 'unsafe entrypoints are diagnosed');

$binary = $compiler->compile(
    array(
        'entrypoint' => 'pages/home.html',
        'files'      => array(
            array(
                'path'           => 'pages/home.html',
                'content_base64' => base64_encode('<main><h1>Encoded</h1></main>'),
                'mime_type'      => 'text/html',
                'role'           => 'entry',
            ),
            array(
                'path'           => 'assets/logo.png',
                'content_base64' => base64_encode("\x89PNG\r\n\x1a\n"),
                'mime_type'      => 'image/png',
                'role'           => 'brand-asset',
            ),
            array(
                'path'           => 'assets/bad.bin',
                'content_base64' => 'not-valid-base64',
            ),
        ),
    )
)->toArray();
$assert('success_with_warnings' === $binary['status'], 'invalid base64 is a non-blocking warning', (string) $binary['status']);
$assert('pages/home.html' === ($binary['source_reports']['artifact']['entry_path'] ?? ''), 'base64 HTML entry is decoded and selected');
$assert(1 === ($binary['source_reports']['artifact']['files_by_mime']['image/png'] ?? 0), 'MIME counts include binary assets');
$assert(1 === ($binary['source_reports']['artifact']['files_by_role']['brand-asset'] ?? 0), 'role counts include binary assets');
$assert(1 === ($binary['source_reports']['artifact']['rejected_count'] ?? null), 'invalid base64 file is rejected');
$assert('assets/logo.png' === ($binary['assets'][0]['path'] ?? ''), 'binary asset appears in manifest');
$assert(true === ($binary['assets'][0]['binary'] ?? null), 'binary asset is marked binary');
$assert(! empty($binary['assets'][0]['content_base64'] ?? ''), 'binary asset keeps base64 payload');

$blocks = $compiler->compile(
    array(
        'files' => array(
            'index.html'             => '<main><h1>Block type</h1></main>',
            'blocks/hero/block.json' => json_encode(array('apiVersion' => 3, 'name' => 'acme/hero', 'title' => 'Hero'), JSON_UNESCAPED_SLASHES),
        ),
    )
)->toArray();
$assert(1 === count($blocks['block_types']), 'block.json roots are promoted into block type artifacts');
$assert('acme/hero' === ($blocks['block_types'][0]['name'] ?? ''), 'block type name is preserved');

fwrite(STDOUT, "Contract scaffold passed.\n");
