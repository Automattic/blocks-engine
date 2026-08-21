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
assertSame(true, $runtime->blockSupportsBorder('core/group', 'width'), 'Standalone support resolution should load Group width support from the generated WordPress declaration snapshot.');
assertSame(true, $runtime->blockSupportsBorder('core/group', 'style'), 'Standalone support resolution should load Group style support from the generated WordPress declaration snapshot.');
assertSame(true, $runtime->blockSupportsBorder('core/group', 'color'), 'Standalone support resolution should load Group color support from the generated WordPress declaration snapshot.');
assertSame(false, $runtime->blockSupportsBorder('core/quote', 'width'), 'Standalone support resolution should treat WordPress 6.6 Quote as lacking border support.');
assertSame(true, $runtime->blockSupportsBorder('core/image', 'width'), 'Standalone support resolution should load Image width support from its declaration.');
assertSame(false, $runtime->blockSupportsBorder('core/image', 'style'), 'Standalone support resolution should honor Image declarations that omit border style support.');

$blocks = $runtime->parseBlocks('<!-- wp:paragraph {"content":"Hello"} --><p>Hello</p><!-- /wp:paragraph -->');
assertSame('core/paragraph', $blocks[0]['blockName'] ?? null, 'Fallback parser should parse serialized block comments.');
assertSame('wordpress_parse_blocks_unavailable', $runtime->diagnostics()[0]['code'] ?? null, 'Fallback parser should expose a diagnostic.');

$serialized = $runtime->serializeBlocks($blocks);
assertSame('<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->', $serialized, 'Standalone canonicalization should omit Paragraph rich-text content from block comments.');
assertSame('wordpress_serialize_blocks_unavailable', $runtime->diagnostics()[0]['code'] ?? null, 'Fallback serializer should expose a diagnostic.');

$image = array(
    'blockName'    => 'core/image',
    'attrs'        => array('url' => '/image.jpg', 'alt' => 'Image', 'caption' => 'Caption', 'id' => 42, 'unknown' => 'preserved'),
    'innerBlocks'  => array(),
    'innerHTML'    => '<figure><img src="/image.jpg" alt="Image"><figcaption>Caption</figcaption></figure>',
    'innerContent' => array('<figure><img src="/image.jpg" alt="Image"><figcaption>Caption</figcaption></figure>'),
);
assertSame(
    '<!-- wp:image {"id":42,"unknown":"preserved"} --><figure><img src="/image.jpg" alt="Image"><figcaption>Caption</figcaption></figure><!-- /wp:image -->',
    $runtime->serializeBlocks(array($image)),
    'Standalone canonicalization should omit generated rich-text and attribute-derived Image attrs while retaining unsourced and unknown attrs.'
);

$customPropertyMarkup = $runtime->serializeBlocks(array(array(
    'blockName'    => 'core/paragraph',
    'attrs'        => array('style' => array('typography' => array('fontSize' => 'var(--responsive-font-size,20px)'))),
    'innerBlocks'  => array(),
    'innerHTML'    => '<p style="font-size:var(--responsive-font-size,20px)">Text</p>',
    'innerContent' => array('<p style="font-size:var(--responsive-font-size,20px)">Text</p>'),
)));
$escapedHyphen = chr(92) . 'u002d';
assertSame(true, str_contains($customPropertyMarkup, 'var(' . $escapedHyphen . $escapedHyphen . 'responsive-font-size,20px)'), 'Fallback serializer should retain escaped CSS custom-property hyphens.');
assertSame(false, str_contains($customPropertyMarkup, 'var(u002du002dresponsive-font-size,20px)'), 'Fallback serializer should not drop custom-property escape backslashes.');

// Dynamic/nested blocks (core/navigation et al.) save() to null inner HTML, so
// the WordPress-free fallback serializer must emit canonical comment-delimited
// markup recursively rather than rendering static HTML. A standalone navigation
// block keeps its navigation-link children, and a navigation block nested inside
// another block survives with its delimiters and children intact.
$navigationLink = static fn (string $label, string $url): array => array(
    'blockName'    => 'core/navigation-link',
    'attrs'        => array( 'label' => $label, 'url' => $url ),
    'innerBlocks'  => array(),
    'innerHTML'    => '',
    'innerContent' => array( '' ),
);
$navigationBlock = array(
    'blockName'    => 'core/navigation',
    'attrs'        => array(),
    'innerBlocks'  => array( $navigationLink('Home', '/'), $navigationLink('About', '/about') ),
    'innerHTML'    => '',
    'innerContent' => array( '', null, null, '' ),
);

$serializedNav = $runtime->serializeBlocks(array( $navigationBlock ));
assertSame(
    '<!-- wp:navigation --><!-- wp:navigation-link {"label":"Home","url":"/"} /--><!-- wp:navigation-link {"label":"About","url":"/about"} /--><!-- /wp:navigation -->',
    $serializedNav,
    'Fallback serializer should keep a standalone navigation block\'s navigation-link children instead of collapsing to a self-closing comment.'
);
assertSame($serializedNav, $runtime->serializeBlocks(array( $navigationBlock )), 'Fallback serializer must be deterministic (byte-identical across runs).');

$groupWithNav = array(
    'blockName'    => 'core/group',
    'attrs'        => array(),
    'innerBlocks'  => array( $navigationBlock ),
    'innerHTML'    => '<div class="wp-block-group"></div>',
    'innerContent' => array( '<div class="wp-block-group">', null, '</div>' ),
);
$serializedNested = $runtime->serializeBlocks(array( $groupWithNav ));
assertSame(
    '<!-- wp:group --><div class="wp-block-group"><!-- wp:navigation --><!-- wp:navigation-link {"label":"Home","url":"/"} /--><!-- wp:navigation-link {"label":"About","url":"/about"} /--><!-- /wp:navigation --></div><!-- /wp:group -->',
    $serializedNested,
    'Fallback serializer should keep a nested navigation block (and its children) instead of dropping it from the group output.'
);
assertSame($serializedNested, $runtime->serializeBlocks(array( $groupWithNav )), 'Nested-navigation fallback serialization must be deterministic (byte-identical across runs).');

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
