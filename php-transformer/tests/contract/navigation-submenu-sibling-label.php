<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$cases = array(
    'Team' => array('Team', null),
    'Sell' => array('<a href="https://x.test/seller">Sell</a>', 'https://x.test/seller'),
    'Communities' => array('<a href="/empty"></a><a href="/empty"></a><a href="/c">Communities</a>', '/c'),
);
foreach ($cases as $label => [$toggle, $url]) {
    $source = '<!doctype html><html><head><link rel="stylesheet" href="s.css"></head><body><header><nav>'
        . '<div class="collection"><a href="/buy">Buy</a></div>'
        . '<div class="folder"><div class="folder-toggle">' . $toggle . '</div><div class="subnav">'
        . '<div class="collection"><a href="/first">First</a></div><div class="external"><a href="/second">Second</a></div>'
        . '</div></div><div class="collection"><a href="/blog">Blog</a></div>'
        . '</nav></header><main><p>Hi</p></main></body></html>';
    $result = (new ArtifactCompiler())->compile(array('files' => array(
        array('path' => 'index.html', 'kind' => 'html', 'content' => $source),
        array('path' => 's.css', 'kind' => 'css', 'content' => 'nav .collection,nav .folder{display:inline-block}nav .folder{position:relative}nav .subnav{position:absolute;top:100%;left:0;opacity:0;visibility:hidden}nav .folder:hover .subnav{opacity:1;visibility:visible}'),
    )))->toArray();
    $markup = $result['serialized_blocks'];
    if (1 !== preg_match('/<!-- wp:navigation-submenu (\{.*?\}) -->(.*?)<!-- \/wp:navigation-submenu -->/s', $markup, $match)) {
        throw new RuntimeException($label . ': expected a submenu, not flattened links.');
    }
    $attrs = json_decode($match[1], true);
    if ($label !== ($attrs['label'] ?? null) || $url !== ($attrs['url'] ?? null)) {
        throw new RuntimeException($label . ': submenu must keep its label and destination.');
    }
    preg_match_all('/<!-- wp:navigation-link (\{.*?\}) \/-->/s', $match[2], $children);
    $children = array_map(static fn (string $json): array => json_decode($json, true), $children[1]);
    if (array('First', 'Second') !== array_column($children, 'label') || array('/first', '/second') !== array_column($children, 'url')) {
        throw new RuntimeException($label . ': submenu must own the child links in source order.');
    }
    preg_match_all('/<!-- wp:(navigation-link|navigation-submenu) (\{.*?\}) /s', str_replace($match[2], '', $markup), $items, PREG_SET_ORDER);
    if (array('navigation-link', 'navigation-submenu', 'navigation-link') !== array_column($items, 1)) {
        throw new RuntimeException($label . ': expected one submenu between the peer links.');
    }
}

echo "navigation submenu sibling label contract passed\n";
