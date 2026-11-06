<?php
declare(strict_types=1);

/**
 * Source-derived block attributes never reach the serialized delimiter.
 *
 * Regression coverage for Automattic/blocks-engine#4743: a `rich-text` sourced
 * value such as core/paragraph `content` in the block comment makes WordPress'
 * WP_Block_Type::prepare_attributes_for_render() raise a
 * rest_validate_value_from_schema doing-it-wrong notice on every render.
 *
 * The first half runs without WordPress (bundled core metadata snapshot); the
 * second half defines a WordPress registry so metadata comes from the
 * runtime-registered block types.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\FormatBridge\FormatBridge;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\BlockFactory;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( $condition ) {
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

/**
 * Collect every serialized delimiter's attribute object, keyed by block name.
 *
 * @return array<int, array{name: string, attrs: array<string, mixed>}>
 */
$delimiters = static function (string $serialized): array {
    $found = array();
    preg_match_all('/<!--\s+wp:([a-z0-9\/-]+)(?:\s+(\{.*?\}))?\s+\/?-->/s', $serialized, $matches, PREG_SET_ORDER);
    foreach ( $matches as $match ) {
        $attrs = isset($match[2]) && '' !== $match[2] ? json_decode($match[2], true) : array();
        $found[] = array(
            'name'  => $match[1],
            'attrs' => is_array($attrs) ? $attrs : array(),
        );
    }

    return $found;
};

/** @var array<string, array<int, string>> $sourcedAttributes */
$sourcedAttributes = array(
    'paragraph'  => array( 'content' ),
    'heading'    => array( 'content' ),
    'list-item'  => array( 'content' ),
    'button'     => array( 'text', 'url' ),
    'quote'      => array( 'citation' ),
    'pullquote'  => array( 'value', 'citation' ),
    'code'       => array( 'content' ),
    'preformatted' => array( 'content' ),
    'image'      => array( 'url', 'alt', 'caption' ),
);

$assertNoSourcedAttributes = static function (string $serialized, string $context) use ($assert, $delimiters, $sourcedAttributes): void {
    foreach ( $delimiters($serialized) as $delimiter ) {
        foreach ( $sourcedAttributes[ $delimiter['name'] ] ?? array() as $attribute ) {
            $assert(
                ! array_key_exists($attribute, $delimiter['attrs']),
                sprintf('%s: core/%s delimiter omits source-derived "%s".', $context, $delimiter['name'], $attribute)
            );
        }
    }
};

$factory = new BlockFactory();
$buildBlocks = static function () use ($factory): array {
    return array(
        $factory->create('core/paragraph', array( 'content' => 'Plain <em>rich</em> text', 'align' => 'center', 'className' => 'lede' )),
        $factory->create('core/paragraph', array( 'content' => '<a href="https://example.test/">Leading link</a> paragraph' )),
        $factory->create('core/heading', array( 'content' => 'Section', 'level' => 3, 'textAlign' => 'center' )),
        $factory->create('core/list', array( 'ordered' => true ), array(
            $factory->create('core/list-item', array( 'content' => 'First <strong>item</strong>' )),
        )),
        $factory->create('core/buttons', array(), array(
            $factory->create('core/button', array( 'text' => 'Get started', 'url' => 'https://example.test/start', 'className' => 'is-style-outline' )),
        )),
        $factory->create('core/quote', array( 'citation' => 'A. Author' ), array(
            $factory->create('core/paragraph', array( 'content' => 'Quoted words' )),
        )),
        $factory->create('core/pullquote', array( 'value' => '<p>Pulled words</p>', 'citation' => 'B. Author' )),
        $factory->create('core/code', array( 'content' => 'echo 1;' )),
        $factory->create('core/preformatted', array( 'content' => 'pre text' )),
        $factory->create('core/image', array( 'id' => 5, 'url' => 'https://example.test/a.jpg', 'alt' => 'Alt', 'caption' => 'Caption' )),
    );
};

// --- Without WordPress: the bundled core metadata snapshot drives omission. ---

$runtime = new Runtime();
$assert(! $runtime->canSerializeBlocks(), 'The first section runs without WordPress serialize_blocks().');

$blocks = $buildBlocks();
$serialized = $runtime->serializeBlocks($blocks);
$assertNoSourcedAttributes($serialized, 'Snapshot serialization');

$assert(
    str_contains($serialized, '<!-- wp:paragraph {"align":"center","className":"lede"} --><p class="has-text-align-center lede">Plain <em>rich</em> text</p><!-- /wp:paragraph -->'),
    'Paragraph keeps align/className in the delimiter and its content in saved HTML.'
);
$assert(
    str_contains($serialized, '<!-- wp:paragraph --><p><a href="https://example.test/">Leading link</a> paragraph</p><!-- /wp:paragraph -->'),
    'A paragraph that starts with a link still serializes without content in the delimiter.'
);
$assert(
    str_contains($serialized, '<!-- wp:heading {"level":3,"textAlign":"center"} -->') && str_contains($serialized, '>Section</h3>'),
    'Heading keeps level/textAlign and its content in saved HTML.'
);
$assert(
    str_contains($serialized, '<!-- wp:list {"ordered":true} --><ol class="wp-block-list"><!-- wp:list-item --><li>First <strong>item</strong></li><!-- /wp:list-item --></ol>'),
    'List keeps ordered while the list item content lives only in saved HTML.'
);
$assert(
    str_contains($serialized, '<!-- wp:button {"className":"is-style-outline"} -->') && str_contains($serialized, 'href="https://example.test/start">Get started</a>'),
    'Button keeps className while text and url live only in saved HTML.'
);
$assert(
    str_contains($serialized, '<cite>A. Author</cite></blockquote><!-- /wp:quote -->') && str_contains($serialized, '<!-- wp:quote -->'),
    'Quote citation lives only in saved HTML.'
);
$assert(
    str_contains($serialized, '<!-- wp:pullquote --><figure class="wp-block-pullquote"><blockquote><p>Pulled words</p><cite>B. Author</cite></blockquote></figure>'),
    'Pullquote value and citation live only in saved HTML.'
);
$assert(
    str_contains($serialized, '<!-- wp:image {"id":5} -->'),
    'Image keeps its comment-sourced id while url/alt/caption live in saved HTML.'
);
$assert(
    'Plain <em>rich</em> text' === ($blocks[0]['attrs']['content'] ?? null)
        && 'Get started' === ($blocks[4]['innerBlocks'][0]['attrs']['text'] ?? null),
    'Canonical serialization leaves the transformer working blocks untouched.'
);

// The Markdown -> blocks conversion path used by markdown-database-integration.
$markdown = "# Docs title\n\nMy Shows separates **two** privacy decisions with a [link](https://example.test/).\n\n- one\n- two\n\n> quoted\n\n```php\necho 1;\n```\n";
$result = ( new FormatBridge() )->convertResult($markdown, 'markdown', 'blocks')->toArray();
$markdownSerialized = (string) ($result['serialized_blocks'] ?? '');
$assert('success' === ($result['status'] ?? null) && '' !== $markdownSerialized, 'Markdown converts to serialized blocks.');
$assertNoSourcedAttributes($markdownSerialized, 'Markdown conversion');
$assert(
    ! str_contains($markdownSerialized, '"content"'),
    'Markdown conversion emits no content attribute in any delimiter.'
);
$assert(
    str_contains($markdownSerialized, '<!-- wp:heading {"level":1} -->')
        && str_contains($markdownSerialized, '<!-- wp:code {"className":"language-php"} -->'),
    'Markdown conversion keeps non-source attributes (heading level, code className).'
);

// --- With WordPress: the runtime block registry drives omission. ---

/** @var array<int, array<string, mixed>> $capturedSerializeBlocks */
$capturedSerializeBlocks = array();

// Conditional declarations are not hoisted, so the section above really ran
// without these WordPress symbols.
if ( ! function_exists('serialize_blocks') ) {
    function serialize_blocks(array $blocks): string
    {
        $GLOBALS['capturedSerializeBlocks'] = $blocks;
        return 'stub serialized';
    }
}

if ( ! class_exists('WP_Block_Type_Registry') ) {
    final class WP_Block_Type_Registry
    {
        public static function get_instance(): self
        {
            return new self();
        }

        /** @return array<string, object> */
        public function get_all_registered(): array
        {
            return array(
                'core/paragraph' => (object) array(
                    'name'       => 'core/paragraph',
                    'attributes' => array(
                        'align'   => array( 'type' => 'string' ),
                        'content' => array( 'type' => 'rich-text', 'source' => 'rich-text', 'selector' => 'p', 'role' => 'content' ),
                    ),
                ),
                'core/button' => (object) array(
                    'name'       => 'core/button',
                    'attributes' => array(
                        'url'  => array( 'type' => 'string', 'source' => 'attribute', 'selector' => 'a', 'attribute' => 'href' ),
                        'text' => array( 'type' => 'rich-text', 'source' => 'rich-text', 'selector' => 'a' ),
                    ),
                ),
                // A runtime-registered block that declares rich-text without a
                // source still never belongs in the delimiter: WordPress validates
                // the delimiter value and rejects the non-JSON-Schema type.
                'plugin/callout' => (object) array(
                    'name'       => 'plugin/callout',
                    'attributes' => array(
                        'body'    => array( 'type' => 'rich-text' ),
                        'summary' => array( 'type' => array( 'rich-text', 'null' ) ),
                        'tone'    => array( 'type' => 'string' ),
                    ),
                ),
            );
        }
    }
}

$wpRuntime = new Runtime();
$assert($wpRuntime->canSerializeBlocks(), 'The second section runs with WordPress serialize_blocks().');
$wpRuntime->serializeBlocks(array(
    array( 'blockName' => 'core/paragraph', 'attrs' => array( 'content' => 'Registered paragraph', 'align' => 'wide' ), 'innerBlocks' => array(), 'innerHTML' => '<p>Registered paragraph</p>', 'innerContent' => array( '<p>Registered paragraph</p>' ) ),
    array( 'blockName' => 'core/buttons', 'attrs' => array(), 'innerBlocks' => array(
        array( 'blockName' => 'core/button', 'attrs' => array( 'text' => 'Go', 'url' => 'https://example.test/', 'className' => 'cta' ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array( '' ) ),
    ), 'innerHTML' => '', 'innerContent' => array( '', null, '' ) ),
    array( 'blockName' => 'plugin/callout', 'attrs' => array( 'body' => 'Body <em>text</em>', 'summary' => 'Sum', 'tone' => 'warning' ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array( '' ) ),
));
$captured = $GLOBALS['capturedSerializeBlocks'];
$assert(
    array( 'align' => 'wide' ) === ($captured[0]['attrs'] ?? null),
    'Registry metadata omits paragraph content and keeps align.'
);
$assert(
    array( 'className' => 'cta' ) === ($captured[1]['innerBlocks'][0]['attrs'] ?? null),
    'Registry metadata omits nested button text/url and keeps className.'
);
$assert(
    array( 'tone' => 'warning' ) === ($captured[2]['attrs'] ?? null),
    'Registry metadata omits rich-text typed attributes that declare no source.'
);

if ( 0 === $failures ) {
    echo "source-derived comment attributes ok\n";
}

exit(0 === $failures ? 0 : 1);
