<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A zero-width divider still occupies a flex slot, hence another item gap.
$html = '<style>ul,li,div,a,span{margin:0;padding:0}ul,li{display:flex;gap:7px}.divider{width:0}</style>'
    . '<header><nav><ul><li><div><a href="/">Home</a></div><span class="divider"></span></li>'
    . '<li><div><a href="/about">About</a></div><span class="divider"></span></li></ul></nav></header>';
$result = (new HtmlTransformer())->transform($html)->toArray();
preg_match_all('/<!-- wp:navigation-link (\{.*?\}) \/-->/s', $result['serialized_blocks'], $matches);
if (2 !== count($matches[1])) throw new RuntimeException('Expected editable native navigation links.');
foreach ($matches[1] as $json) {
    $attrs = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $siblings = $attrs['metadata']['blocksEngineNavigationAnchor']['siblings'] ?? array();
    if (1 !== count($siblings) || 'span' !== $siblings[0]['tag'] || !$siblings[0]['after'] || !str_contains($siblings[0]['className'], 'divider')) {
        throw new RuntimeException('The native item must retain its empty trailing flex slot.');
    }
}
echo "Navigation empty divider sibling passed\n";
