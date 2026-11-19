<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

/** @return array<int, array<string, mixed>> */
$flatten = static function (array $blocks) use (&$flatten): array {
    $result = array();
    foreach ( $blocks as $block ) {
        $result[] = $block;
        array_push($result, ...$flatten($block['innerBlocks'] ?? array()));
    }

    return $result;
};

$cases = array(
    'column wrapper' => '<div class="column"><h1>Title</h1><p>Body</p></div>',
    'columns wrapper' => '<div class="columns"><h1>Title</h1><p>Body</p></div>',
    'Drupal content wrapper' => '<div id="content" class="column" role="main"><nav><a href="/">Home</a></nav><h1>Title</h1><article><p>Body</p></article></div>',
    'col control' => '<div class="col"><h1>Title</h1><p>Body</p></div>',
    'unstyled row' => '<div class="row"><div class="column"><p>A</p></div><div class="column"><p>B</p></div></div>',
);

foreach ( array( '', '.column{float:left;width:100%}' ) as $css ) {
    foreach ( $cases as $label => $html ) {
        $label .= '' === $css ? ' without CSS' : ' with float CSS';
        $result = ( new HtmlTransformer() )->transform($html, array( 'static_css' => $css ))->toArray();
        $blocks = $result['blocks'] ?? array();
        if ( 1 !== count($blocks) || 'core/group' !== ($blocks[0]['blockName'] ?? null) ) {
            throw new RuntimeException($label . ': expected one containing flow group');
        }
        $flat = $flatten($blocks);
        $names = array_column($flat, 'blockName');
        if ( in_array('core/columns', $names, true) || in_array('core/column', $names, true) ) {
            throw new RuntimeException($label . ': class tokens must not create columns');
        }
        foreach ( $flat as $block ) {
            if ( 'core/group' === $block['blockName'] && 'default' !== ($block['attrs']['layout']['type'] ?? 'default') ) {
                throw new RuntimeException($label . ': wrappers must retain flow layout');
            }
        }
        $content = array();
        foreach ( $flat as $block ) {
            if ( in_array($block['blockName'], array( 'core/heading', 'core/paragraph' ), true) ) {
                $content[] = array( $block['blockName'], $block['attrs']['content'] ?? '' );
            }
        }
        $expected = str_contains($html, 'class="row"')
            ? array( array( 'core/paragraph', 'A' ), array( 'core/paragraph', 'B' ) )
            : array( array( 'core/heading', 'Title' ), array( 'core/paragraph', 'Body' ) );
        if ( $expected !== $content ) {
            throw new RuntimeException($label . ': heading and body must remain in source order within the group');
        }
    }
}

echo "Column class names retain normal flow\n";
