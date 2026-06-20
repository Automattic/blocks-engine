<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/php-transformer.php';

assertSame('0.1.0', blocks_engine_php_transformer_version(), 'Plugin helper exposes version.');
assertSame(dirname(__DIR__, 2), blocks_engine_php_transformer_path(), 'Plugin helper exposes package path.');

$htmlResult = blocks_engine_php_transformer_transform_html(
    '<main><h1>Plugin bootstrap</h1><p>Ready.</p></main>',
    array(
        'source' => 'fixture:plugin-bootstrap',
        'scope'  => 'contract-test',
    )
);

assertSame('success', $htmlResult['status'] ?? '', 'HTML helper returns a successful canonical result.');
assertSame('fixture:plugin-bootstrap', $htmlResult['provenance'][0]['source'] ?? '', 'HTML helper preserves provenance source.');

$formatResult = blocks_engine_php_transformer_convert_format('# Plugin bootstrap', 'markdown', 'blocks');
assertSame('success', $formatResult['status'] ?? '', 'Format helper returns a successful canonical result.');

$artifactResult = blocks_engine_php_transformer_compile_artifact(
    array(
        'generated_html' => '<main><h1>Plugin artifact</h1></main>',
    )
);

assertSame('success', $artifactResult['status'] ?? '', 'Artifact helper returns a successful canonical result.');
assertSame('index.html', $artifactResult['source_reports']['artifact']['entry_path'] ?? '', 'Artifact helper exposes canonical source reports.');

fwrite(STDOUT, "Plugin bootstrap contract passed.\n");

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ( $expected !== $actual ) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}
