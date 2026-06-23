<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/php-transformer.php';

assertSame('0.1.2', blocks_engine_php_transformer_version(), 'Plugin helper exposes version.');
assertSame(dirname(__DIR__, 2), blocks_engine_php_transformer_path(), 'Plugin helper exposes package path.');

$htmlInput   = '<main><h1>Plugin bootstrap</h1><p>Ready.</p></main>';
$htmlOptions = array(
    'source' => 'fixture:plugin-bootstrap',
    'scope'  => 'contract-test',
);
$htmlResult  = blocks_engine_php_transformer_transform_html($htmlInput, $htmlOptions);

$canonicalHtmlResult = ( new Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer() )
    ->transform($htmlInput, $htmlOptions)
    ->toArray();

assertSame('success', $htmlResult['status'] ?? '', 'HTML helper returns a successful canonical result.');
assertSame('fixture:plugin-bootstrap', $htmlResult['provenance'][0]['source'] ?? '', 'HTML helper preserves provenance source.');
assertSame(normalizeTransformerResult($canonicalHtmlResult), normalizeTransformerResult($htmlResult), 'HTML helper matches the canonical class result envelope.');

$formatInput  = '# Plugin bootstrap';
$formatResult = blocks_engine_php_transformer_convert_format($formatInput, 'markdown', 'blocks');

$canonicalFormatResult = ( new Automattic\BlocksEngine\PhpTransformer\FormatBridge\FormatBridge() )
    ->convertResult($formatInput, 'markdown', 'blocks')
    ->toArray();

assertSame('success', $formatResult['status'] ?? '', 'Format helper returns a successful canonical result.');
assertSame(normalizeTransformerResult($canonicalFormatResult), normalizeTransformerResult($formatResult), 'Format helper matches the canonical class result envelope.');

$artifactInput  = array(
    'generated_html' => '<main><h1>Plugin artifact</h1></main>',
);
$artifactResult = blocks_engine_php_transformer_compile_artifact($artifactInput);

$canonicalArtifactResult = ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )
    ->compile($artifactInput)
    ->toArray();

assertSame('success', $artifactResult['status'] ?? '', 'Artifact helper returns a successful canonical result.');
assertSame('index.html', $artifactResult['source_reports']['artifact']['entry_path'] ?? '', 'Artifact helper exposes canonical source reports.');
assertSame(normalizeTransformerResult($canonicalArtifactResult), normalizeTransformerResult($artifactResult), 'Artifact helper matches the canonical class result envelope.');

fwrite(STDOUT, "Plugin bootstrap contract passed.\n");

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ( $expected !== $actual ) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

/**
 * Remove per-call timing before comparing otherwise identical result envelopes.
 *
 * @param array<string, mixed> $result Transformer result envelope.
 * @return array<string, mixed>
 */
function normalizeTransformerResult(array $result): array
{
    unset($result['metrics']['transform_duration_ms']);
    unset($result['source_reports']['conversion_report']['metrics']['transform_duration_ms']);

    return $result;
}
