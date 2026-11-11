<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

foreach (array('without titles' => '', 'with titles' => ' title="Sell (opens in new tab)"') as $case => $title) {
    $anchor = '<a href="https://s.example.com/seller" target="_blank"' . $title . '>';
    $source = '<!doctype html><html><body><header><nav><div class="folder"><div class="folder-toggle">'
        . $anchor . '</a>' . $anchor . 'Sell</a></div><div class="subnav">'
        . '<div class="external"><a href="https://s.example.com/value" target="_blank" title="Home Valuation (opens in new tab)">Home Valuation</a></div>'
        . '<div class="collection"><a href="/why/">Why List With Us</a></div></div></div>'
        . '<div class="collection"><a href="/blog/">Blog</a></div></nav></header><main><p>x</p></main></body></html>';
    $result = (new ArtifactCompiler())->compile(array('files' => array(
        array('path' => 'index.html', 'kind' => 'html', 'content' => $source),
    )))->toArray();
    $markup = $result['serialized_blocks'];
    if (1 !== preg_match('/<!-- wp:navigation-submenu (\{.*?\}) -->(.*?)<!-- \/wp:navigation-submenu -->/s', $markup, $submenu)) {
        throw new RuntimeException($case . ': expected Sell submenu, not flattened links.');
    }
    $attrs = json_decode($submenu[1], true);
    if ('Sell' !== ($attrs['label'] ?? null) || 'https://s.example.com/seller' !== ($attrs['url'] ?? null)) {
        throw new RuntimeException($case . ': submenu must keep the labelled anchor and its destination.');
    }
    preg_match_all('/<!-- wp:navigation-link (\{.*?\}) \/-->/s', $submenu[2], $children);
    $children = array_map(static fn (string $json): array => json_decode($json, true), $children[1]);
    if (array('Home Valuation', 'Why List With Us') !== array_column($children, 'label')
        || array('https://s.example.com/value', '/why/') !== array_column($children, 'url')) {
        throw new RuntimeException($case . ': submenu must own its children in source order.');
    }
    preg_match_all('/<!-- wp:(navigation-link|navigation-submenu) (\{.*?\}) /s', str_replace($submenu[2], '', $markup), $peers, PREG_SET_ORDER);
    $peerLabels = array_map(static fn (array $item): string => json_decode($item[2], true)['label'], $peers);
    if (array('navigation-submenu', 'navigation-link') !== array_column($peers, 1) || array('Sell', 'Blog') !== $peerLabels) {
        throw new RuntimeException($case . ': only Sell and Blog belong at the top level.');
    }
}

echo "navigation submenu titled counterpart contract passed\n";
