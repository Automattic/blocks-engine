<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$findNavigation = static function (array $blocks) use (&$findNavigation): array {
    foreach ( $blocks as $block ) {
        if ( 'core/navigation' === ($block['blockName'] ?? '') ) return $block;
        $found = $findNavigation($block['innerBlocks'] ?? array());
        if ( array() !== $found ) return $found;
    }
    return array();
};

foreach ( array( 'right' => 'right', 'center' => 'center', 'left' => null ) as $alignment => $expected ) {
    $html = '<!doctype html><html><head><meta charset="utf-8"><title>Right nav</title>'
        . '<style>#header{display:table;width:100%}#header-left,#header-right{display:table-cell;vertical-align:middle}'
        . '#header-right{text-align:' . $alignment . '}.menu{margin:0;padding:0;list-style:none}.menu li{display:inline-block;margin-left:20px}</style></head>'
        . '<body><header><div id="header"><div id="header-left"><a href="/">Logo</a></div><div id="header-right"><div id="nav">'
        . '<ul class="menu"><li><a href="/a">Sanctuary</a></li><li><a href="/b">Donate</a></li><li><a href="/c">Shop</a></li></ul>'
        . '</div></div></div></header><main><p>Body</p></main></body></html>';
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $navigation = $findNavigation($result['blocks']);
    if ( array() === $navigation || $expected !== ($navigation['attrs']['layout']['justifyContent'] ?? null) ) {
        fwrite(STDERR, 'FAIL: inherited ' . $alignment . ' navigation packing: ' . json_encode($navigation['attrs'] ?? array()) . PHP_EOL);
        exit(1);
    }

    $responsive = (new HtmlTransformer())->transform(str_replace(
        '#header-right{text-align:' . $alignment . '}',
        '@media(min-width:800px){#header-right{text-align:' . $alignment . '}}',
        $html
    ))->toArray();
    $navigation = $findNavigation($responsive['blocks']);
    $css = implode("\n", array_column($responsive['assets'] ?? array(), 'content'));
    $justify = match ( $alignment ) {
        'right' => 'flex-end',
        'center' => 'center',
        default => 'flex-start',
    };
    if ( isset($navigation['attrs']['layout']['justifyContent'])
        || ! preg_match('/@media\s*\(min-width:800px\)\{[^{}]+\{justify-content:' . $justify . '!important\}\}/', $css) ) {
        fwrite(STDERR, 'FAIL: media-scoped inherited ' . $alignment . ' navigation packing' . PHP_EOL);
        exit(1);
    }
}

echo "Inherited navigation list alignment passed\n";
