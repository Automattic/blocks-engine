<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$runtime = new Runtime();

assertSame(false, $runtime->hasWordPress(), 'No WordPress runtime should be detected in the standalone PHP test process.');
$availableCoreBlocks = $runtime->availableCoreBlockNames();
assertSame(true, in_array('core/accordion', $availableCoreBlocks, true), 'Fallback native target metadata should include core/accordion.');
assertSame(true, in_array('core/icon', $availableCoreBlocks, true), 'Fallback native target metadata should include core/icon.');
assertSame(true, in_array('core/math', $availableCoreBlocks, true), 'Fallback native target metadata should include core/math.');

$blocks = $runtime->parseBlocks('<!-- wp:paragraph {"content":"Hello"} --><p>Hello</p><!-- /wp:paragraph -->');
assertSame('core/paragraph', $blocks[0]['blockName'] ?? null, 'Fallback parser should parse serialized block comments.');
assertSame('wordpress_parse_blocks_unavailable', $runtime->diagnostics()[0]['code'] ?? null, 'Fallback parser should expose a diagnostic.');

$serialized = $runtime->serializeBlocks($blocks);
assertSame('<!-- wp:paragraph {"content":"Hello"} --><p>Hello</p><!-- /wp:paragraph -->', $serialized, 'Fallback serializer should preserve block comments and inner HTML.');
assertSame('wordpress_serialize_blocks_unavailable', $runtime->diagnostics()[0]['code'] ?? null, 'Fallback serializer should expose a diagnostic.');

$html = $runtime->renderBlocks($blocks);
assertSame('<p>Hello</p>', $html, 'Fallback renderer should render static block HTML.');
assertSame('wordpress_render_block_unavailable', $runtime->diagnostics()[0]['code'] ?? null, 'Fallback renderer should expose a diagnostic.');

$validityFixture = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/contract/wp-block-validity.json'), true);
assertSame('blocks-engine/php-transformer/wp-block-validity-fixture/v1', $validityFixture['schema'] ?? null, 'Block validity fixture should expose its schema.');
foreach ( $validityFixture['cases'] as $case ) {
    $report = $runtime->validateBlockSerialization($case['input'] ?? $case['blocks']);
    assertSame('blocks-engine/php-transformer/wp-block-validity-report/v1', $report['schema'] ?? null, 'Block validity report should expose its schema.');
    assertSame($case['expected_status'], $report['status'] ?? null, 'Block validity report status should match fixture case ' . $case['name']);
    $codes = array_map(static fn (array $finding): string => (string) ($finding['code'] ?? ''), $report['findings'] ?? array());
    sort($codes);
    $expectedCodes = $case['expected_codes'];
    sort($expectedCodes);
    assertSame($expectedCodes, $codes, 'Block validity report finding codes should match fixture case ' . $case['name']);
}

assertSame('Safe text', $runtime->stripAllTags('<script>alert(1)</script><p>Safe <em>text</em></p>'), 'Fallback tag stripping should remove scripts and tags.');
assertSame('wordpress_strip_all_tags_unavailable', $runtime->diagnostics()[0]['code'] ?? null, 'Fallback tag stripping should expose a diagnostic.');

assertSame(true, $runtime->containsShortcode('Before [gallery ids="1,2"] after'), 'Runtime should detect shortcode-like content without WordPress.');
assertSame(true, $runtime->isShortcodeOnly('[gallery ids="1,2"]'), 'Runtime should identify standalone shortcodes without WordPress.');
assertSame(false, $runtime->isShortcodeOnly('Before [gallery ids="1,2"]'), 'Runtime should not treat mixed text as a standalone shortcode.');
assertSame(array('ids' => '1,2'), $runtime->parseShortcodes('[gallery ids="1,2"]')[0]['attrs'] ?? null, 'Fallback shortcode parser should parse named attributes.');
assertSame('[gallery ids="1,2"]', $runtime->preserveShortcodeText(' [gallery ids="1,2"] '), 'Shortcode preservation should trim wrapper whitespace only.');
assertSame('&lt;tag&gt;', $runtime->escapeHtml('<tag>'), 'Fallback HTML escaping should be deterministic.');
assertSame('{"url":"https://example.com/path"}', $runtime->encodeJson(array('url' => 'https://example.com/path')), 'Fallback JSON encoding should preserve unescaped slashes.');

fwrite(STDOUT, "WordPress runtime no-WP contract passed.\n");

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ( $expected !== $actual ) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}
