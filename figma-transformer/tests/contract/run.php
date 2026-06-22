<?php

declare(strict_types=1);

require_once __DIR__ . '/../../figma-transformer.php';

use Automattic\BlocksEngine\FigmaTransformer\Compression\ZstdCapability;

$failures = array();

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$result = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Fixture Site',
    'nodes' => array(
        array('id' => '1:2', 'type' => 'TEXT', 'name' => 'Hero title', 'text' => 'Hello Figma'),
        array('id' => '1:3', 'type' => 'FRAME', 'name' => 'Hero section'),
    ),
));

$assert('blocks-engine/figma-transformer/result/v1' === ($result['schema'] ?? null), 'result-schema');
$assert('success' === ($result['status'] ?? null), 'scenegraph-transform-success');
$assert(2 === ($result['metrics']['node_count'] ?? null), 'node-count');
$assert(str_contains((string) ($result['files'][0]['content'] ?? ''), 'Hello Figma'), 'html-contains-text');
$assert('blocks-engine/figma-transformer/parity-report/v1' === ($result['parity']['schema'] ?? null), 'parity-schema');

$fixture = blocks_engine_figma_transformer_create_fig_wrapper_fixture();
$fileResult = blocks_engine_figma_transformer_transform_file($fixture);
@unlink($fixture);

$canvas = $fileResult['source_reports']['figma']['archive']['canvas'] ?? array();
$chunks = $canvas['chunks'] ?? array();
$diagnosticCodes = array_map(
    static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
    $fileResult['diagnostics'] ?? array()
);
$zstdCapabilityCode = ( new ZstdCapability() )->isAvailable()
    ? 'figma_transformer_zstd_available'
    : 'figma_transformer_zstd_extension_missing';

$assert('success_with_warnings' === ($fileResult['status'] ?? null), 'file-transform-status');
$assert('fig-kiwi' === ($canvas['prelude'] ?? null), 'fig-kiwi-prelude');
$assert(106 === ($canvas['version'] ?? null), 'fig-kiwi-version');
$assert('inner.fig' === ($fileResult['source_reports']['figma']['input']['nested_fig'] ?? null), 'wrapper-nested-fig');
$assert(2 === count($chunks), 'fig-kiwi-chunk-count');
$assert('zlib' === ($chunks[0]['compression'] ?? null), 'fig-kiwi-first-chunk-zlib');
$assert(strlen('synthetic kiwi dictionary') === ($chunks[0]['inflated_bytes'] ?? null), 'fig-kiwi-first-chunk-inflated');
$assert('zstd' === ($chunks[1]['compression'] ?? null), 'fig-kiwi-second-chunk-zstd');
$assert(in_array($zstdCapabilityCode, $diagnosticCodes, true), 'fig-kiwi-zstd-capability-diagnostic');

if ( ! empty($failures) ) {
    fwrite(STDERR, "Figma Transformer contract failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "Figma Transformer contract tests passed.\n");

function blocks_engine_figma_transformer_create_fig_wrapper_fixture(): string
{
    $inner = tempnam(sys_get_temp_dir(), 'blocks-engine-inner-fig-');
    $outer = tempnam(sys_get_temp_dir(), 'blocks-engine-wrapper-fig-');
    if ( false === $inner || false === $outer ) {
        throw new RuntimeException('Could not create temporary fig fixture paths.');
    }

    $canvas = 'fig-kiwi'
        . pack('V', 106)
        . blocks_engine_figma_transformer_kiwi_chunk(gzdeflate('synthetic kiwi dictionary'))
        . blocks_engine_figma_transformer_kiwi_chunk("\x28\xb5\x2f\xfd" . 'synthetic-zstd-frame');

    $innerZip = new ZipArchive();
    if ( true !== $innerZip->open($inner, ZipArchive::OVERWRITE) ) {
        throw new RuntimeException('Could not open inner fig ZIP.');
    }
    $innerZip->addFromString('canvas.fig', $canvas);
    $innerZip->addFromString('meta.json', json_encode(array('name' => 'Synthetic Fixture'), JSON_THROW_ON_ERROR));
    $innerZip->addFromString('images/synthetic', 'asset');
    $innerZip->close();

    $outerZip = new ZipArchive();
    if ( true !== $outerZip->open($outer, ZipArchive::OVERWRITE) ) {
        throw new RuntimeException('Could not open wrapper fig ZIP.');
    }
    $outerZip->addFromString('inner.fig', (string) file_get_contents($inner));
    $outerZip->close();

    @unlink($inner);

    return $outer;
}

function blocks_engine_figma_transformer_kiwi_chunk(string $payload): string
{
    return pack('V', strlen($payload)) . $payload;
}
