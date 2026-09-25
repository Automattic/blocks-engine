<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// The generated theme bootstrap only registers hooks at load time, so capture
// its render_block_data filter and exercise it the way core's renderer does.
function add_filter(string $hook, mixed $callback, int $priority = 10, int $args = 1): bool { $GLOBALS['blocks_engine_test_filters'][$hook][] = $callback; return true; }
function add_action(string $hook, mixed $callback, int $priority = 10, int $args = 1): bool { return true; }

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
};

$compiled = ( new ArtifactCompiler() )->compile(array( 'entrypoint' => 'index.html', 'files' => array( array( 'path' => 'index.html', 'kind' => 'html', 'content' => '<main><p>Home</p></main>' ) ) ))->toArray();
$bootstrap = '';
foreach ( $compiled['source_reports']['wordpress_site_plan']['writes'] ?? array() as $write ) {
    if ( 'functions.php' === ($write['target_path'] ?? '') ) {
        $bootstrap = (string) ($write['payload']['data'] ?? '');
    }
}
eval('?>' . $bootstrap);
$filters = $GLOBALS['blocks_engine_test_filters']['render_block_data'] ?? array();
$assert(1 === count($filters), 'the generated bootstrap registers one render_block_data filter');
$filter = $filters[0] ?? static fn (array $block): array => $block;

// Mirrors WP_Block::render(): the filter runs on every block before its inner
// content is concatenated.
$render = static function (array $block) use (&$render, $filter): string {
    $block = $filter($block);
    $html = '';
    $index = 0;
    foreach ( $block['innerContent'] as $chunk ) {
        $html .= is_string($chunk) ? $chunk : $render($block['innerBlocks'][$index++]);
    }
    return $html;
};
$leaf = static fn (string $name, string $html): array => array( 'blockName' => $name, 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => $html, 'innerContent' => array( $html ) );
$group = static fn (string $open, array $children, string $close): array => array(
    'blockName'    => 'core/group',
    'attrs'        => array(),
    'innerBlocks'  => $children,
    'innerHTML'    => $open . str_repeat("\n\n", max(0, count($children) - 1)) . $close,
    'innerContent' => array_merge(array( $open ), array_merge(...array_map(static fn (int $i): array => 0 === $i ? array( null ) : array( "\n\n", null ), array_keys($children))), array( $close )),
);

// Gutenberg's serialization of an imported list: newline padding around every
// block and between siblings, none of which the import emitted.
$saved = $group("\n<ul class=\"list\">", array(
    $group("\n<li class=\"item\">", array( $leaf('core/paragraph', "\n<p>BAC Water</p>\n") ), "</li>\n"),
    $group("\n<li class=\"item\">", array( $leaf('core/paragraph', "\n<p>Syringes</p>\n") ), "</li>\n"),
), "</ul>\n");
$assert('<ul class="list"><li class="item"><p>BAC Water</p></li><li class="item"><p>Syringes</p></li></ul>' === $render($saved), 'serializer newlines at block edges and between inner blocks do not render');

$assert("<p>line one\nline two</p>" === $render($leaf('core/paragraph', "\n<p>line one\nline two</p>\n")), 'newlines inside a block\'s own text are preserved');
$assert("<pre>code\n\n</pre>" === $render($leaf('core/preformatted', "\n<pre>code\n\n</pre>\n")), 'preformatted content keeps its trailing newlines');
$item = array( 'blockName' => 'core/list-item', 'attrs' => array(), 'innerBlocks' => array( $leaf('core/list', '<ul><li>Child</li></ul>') ), 'innerHTML' => '<li>Item text <ul><li>Child</li></ul></li>', 'innerContent' => array( '<li>Item text ', null, '</li>' ) );
$assert('<li>Item text <ul><li>Child</li></ul></li>' === $render($item), 'text touching an inner block without a serializer newline is unchanged');
$assert('' === $render(array( 'blockName' => null, 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => "\n\n", 'innerContent' => array( "\n\n" ) )), 'whitespace between top-level blocks does not render');
$assert("<p>Classic</p>\n" === $render(array( 'blockName' => null, 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => "<p>Classic</p>\n", 'innerContent' => array( "<p>Classic</p>\n" ) )), 'classic content outside blocks is left alone');

if ( $failures > 0 ) {
    exit(1);
}
fwrite(STDOUT, "Serialized block whitespace unit tests passed\n");
