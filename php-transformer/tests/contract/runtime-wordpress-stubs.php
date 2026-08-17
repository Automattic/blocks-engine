<?php
declare(strict_types=1);

if ( ! function_exists('parse_blocks') ) {
    /**
     * @return array<int, array<string, mixed>>
     */
    function parse_blocks(string $content): array
    {
        return array(
            array(
                'blockName'    => 'stub/parsed',
                'attrs'        => array('content' => $content),
                'innerBlocks'  => array(),
                'innerHTML'    => '',
                'innerContent' => array(''),
            ),
        );
    }
}

if ( ! function_exists('serialize_blocks') ) {
    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    function serialize_blocks(array $blocks): string
    {
        return 'stub serialized ' . count($blocks);
    }
}

if ( ! function_exists('render_block') ) {
    /**
     * @param array<string, mixed> $block
     */
    function render_block(array $block): string
    {
        return '<stub-rendered>' . ($block['blockName'] ?? '') . '</stub-rendered>';
    }
}

if ( ! function_exists('wp_strip_all_tags') ) {
    function wp_strip_all_tags(string $text, bool $remove_breaks = false): string
    {
        unset($remove_breaks);
        return 'stub stripped ' . strip_tags($text);
    }
}

if ( ! function_exists('shortcode_parse_atts') ) {
    /**
     * @return array<string, string>
     */
    function shortcode_parse_atts(string $text): array
    {
        return array('stub' => $text);
    }
}

if ( ! function_exists('wp_json_encode') ) {
    /**
     * @param mixed $data
     */
    function wp_json_encode(mixed $data, int $flags = 0): string|false
    {
        return json_encode(array('stub' => $data), $flags);
    }
}

if ( ! function_exists('esc_html') ) {
    function esc_html(string $text): string
    {
        return 'stub html ' . $text;
    }
}

if ( ! function_exists('esc_attr') ) {
    function esc_attr(string $text): string
    {
        return 'stub attr ' . $text;
    }
}

if ( ! class_exists('WP_Block_Type_Registry') ) {
    final class WP_Block_Type_Registry
    {
        public static function get_instance(): self
        {
            return new self();
        }

        /**
         * @return array<string|int, object>
         */
        public function get_all_registered(): array
        {
            return array(
                'core/icon' => (object) array('name' => 'core/icon'),
                'plugin/card' => (object) array('name' => 'plugin/card'),
                (object) array('name' => 'core/math'),
                'core/accordion' => (object) array('name' => 'core/accordion'),
                'core/group' => (object) array(
                    'name' => 'core/group',
                    'supports' => array(
                        '__experimentalBorder' => array(
                            'color' => true,
                            'style' => false,
                            'width' => true,
                        ),
                    ),
                ),
                'core/quote' => (object) array('name' => 'core/quote', 'supports' => array()),
            );
        }
    }
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$runtime = new Runtime();

assertSame(true, $runtime->hasWordPress(), 'Stubbed WordPress functions should make the runtime available.');
assertSame('stub/parsed', $runtime->parseBlocks('content')[0]['blockName'] ?? null, 'Runtime should delegate parsing to parse_blocks().');
assertSame(array(), $runtime->diagnostics(), 'WordPress parser delegation should not emit fallback diagnostics.');
assertSame('stub serialized 1', $runtime->serializeBlocks(array(array('blockName' => 'core/paragraph'))), 'Runtime should delegate serialization to serialize_blocks().');
assertSame('<stub-rendered>core/paragraph</stub-rendered>', $runtime->renderBlock(array('blockName' => 'core/paragraph')), 'Runtime should delegate rendering to render_block().');
assertSame('stub stripped Bold', $runtime->stripAllTags('<strong>Bold</strong>'), 'Runtime should delegate tag stripping to wp_strip_all_tags().');
assertSame(array('stub' => 'ids="1,2"'), $runtime->parseShortcodeAttributes('ids="1,2"'), 'Runtime should delegate shortcode attributes to shortcode_parse_atts().');
assertSame('{"stub":{"path":"/demo"}}', $runtime->encodeJson(array('path' => '/demo')), 'Runtime should delegate JSON encoding to wp_json_encode().');
assertSame('stub html <tag>', $runtime->escapeHtml('<tag>'), 'Runtime should delegate HTML escaping to esc_html().');
assertSame('stub attr "value"', $runtime->escapeAttribute('"value"'), 'Runtime should delegate attribute escaping to esc_attr().');
assertSame(array('core/accordion', 'core/group', 'core/icon', 'core/math', 'core/quote'), $runtime->availableCoreBlockNames(), 'Runtime should expose registered core block names as native targets.');
assertSame(true, $runtime->blockSupportsBorder('core/group', 'width'), 'Runtime should resolve border width from the registered Group declaration.');
assertSame(false, $runtime->blockSupportsBorder('core/group', 'style'), 'Registered Group metadata should override the standalone snapshot component by component.');
assertSame(false, $runtime->blockSupportsBorder('core/quote', 'width'), 'A registered Quote declaration without border support should fail closed.');

fwrite(STDOUT, "WordPress runtime stub contract passed.\n");

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ( $expected !== $actual ) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}
