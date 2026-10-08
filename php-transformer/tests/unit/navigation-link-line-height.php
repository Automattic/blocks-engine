<?php
declare(strict_types=1);

/** Core puts navigation-link block typography on the item, not its authored anchor. */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$result = (new HtmlTransformer())->transform(
    '<style>:root{--nav-leading:1.5em}</style>'
    . '<nav aria-label="Main">'
    . '<a href="/authored" style="font-size:22px;line-height:var(--nav-leading)">Authored</a>'
    . '<a href="/plain">Plain</a>'
    . '</nav>',
    array()
)->toArray();

$serialized = (string) ($result['serialized_blocks'] ?? '');
$links = array();
if (preg_match_all('/<!--\s*wp:navigation-link\s*(\{.*?\})\s*\/-->/s', $serialized, $matches)) {
    foreach ($matches[1] as $json) {
        $attrs = json_decode($json, true);
        if (is_array($attrs)) $links[$attrs['url'] ?? ''] = $attrs;
    }
}
$css = implode("\n", array_map(
    static fn(array $asset): string => (string) ($asset['content'] ?? ''),
    $result['assets'] ?? array()
));
$marker = static function (array $attrs): string {
    foreach (preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array() as $class) {
        if (preg_match('/^blocks-engine-navigation-anchor-line-height-[a-f0-9]{64}$/D', $class)) return $class;
    }
    return '';
};
$assert = static function (bool $ok, string $message) use ($serialized, $css): void {
    if ($ok) return;
    fwrite(STDERR, "FAIL: {$message}\nSerialized: {$serialized}\nCSS: {$css}\n");
    exit(1);
};

$assert(isset($links['/authored'], $links['/plain']), 'links remain native navigation links');
$authored = $links['/authored'];
$assert('var(--nav-leading)' === ($authored['style']['typography']['lineHeight'] ?? null), 'source token remains editable block typography');
$class = $marker($authored);
$assert('' !== $class, 'authored anchor receives a deterministic marker on its Core item');
$assert(
    str_contains($css, '.wp-block-navigation .wp-block-navigation-item.' . $class . '>.wp-block-navigation-item__content{line-height:var(--nav-leading)}'),
    'only the Core-rendered anchor receives the authored relative line-height'
);
$assert('' === $marker($links['/plain']), 'unstyled link gets no anchor line-height rule');
$listResult = (new HtmlTransformer())->transform(
    '<nav aria-label="Secondary"><ul>'
    . '<li><a href="/nested" style="line-height:1.5em">Nested anchor</a></li>'
    . '<li style="line-height:2"><a href="/item">Item owned</a></li>'
    . '<li style="font-size:16px;line-height:1.5em"><a href="/different" style="font-size:22px">Different font size</a></li>'
    . '<li style="line-height:1.5em!important"><a href="/important">Important item</a></li>'
    . '</ul></nav>',
    array()
)->toArray();
$listLinks = array();
if (preg_match_all('/<!--\s*wp:navigation-link\s*(\{.*?\})\s*\/-->/s', (string) ($listResult['serialized_blocks'] ?? ''), $listMatches)) {
    foreach ($listMatches[1] as $json) {
        $attrs = json_decode($json, true);
        if (is_array($attrs)) $listLinks[$attrs['url'] ?? ''] = $attrs;
    }
}
$listCss = implode("\n", array_map(
    static fn(array $asset): string => (string) ($asset['content'] ?? ''),
    $listResult['assets'] ?? array()
));
$nestedClass = $marker($listLinks['/nested'] ?? array());
$assert('line-height:1.5em' === ($listLinks['/nested']['metadata']['blocksEngineNavigationAnchor']['style'] ?? '') && !isset($listLinks['/nested']['style']['typography']['lineHeight']), 'source nested anchor still owns its line-height without reinterpreting it on the Core item');
$assert('line-height:2' === ($listLinks['/item']['metadata']['blocksEngineNavigationAnchor']['itemStyle'] ?? ''), 'safely inherited line-height stays on its independent native item');
$assert('' === $marker($listLinks['/different'] ?? array()), 'a relative line-height inherited from an item with a different font size is not reinterpreted on the anchor');
$assert('' === $marker($listLinks['/important'] ?? array()), 'an important inherited declaration is not restated with stronger selector scope');

$wrappedResult = (new HtmlTransformer())->transform(
    '<style>:root{--nav-leading:1.5em}</style><nav aria-label="Header">'
    . '<div class="header-nav-item" style="font-size:22px;line-height:var(--nav-leading)"><a href="/wrapped">Wrapped</a></div>'
    . '</nav>',
    array()
)->toArray();
$wrappedSerialized = (string) ($wrappedResult['serialized_blocks'] ?? '');
$assert(
    1 === preg_match('/<!--\s*wp:navigation-link\s*(\{.*?\})\s*\/-->/s', $wrappedSerialized, $wrappedMatch)
        && str_contains((string) ((json_decode($wrappedMatch[1], true) ?: array())['metadata']['blocksEngineNavigationAnchor']['itemStyle'] ?? ''), 'line-height:var(--nav-leading)'),
    'header-like list item retains the actual inherited line-height on its independent native item'
);
$wrappedCss = implode("\n", array_map(static fn(array $asset): string => (string) ($asset['content'] ?? ''), $wrappedResult['assets'] ?? array()));
$assert(str_contains($wrappedCss, '--nav-leading:1.5em') && !str_contains($wrappedCss, '>.wp-block-navigation-item__content{line-height:33px}'), 'inherited header token stays live rather than freezing a used line-height on the anchor');

fwrite(STDOUT, "navigation link line-height passed: 11 assertions\n");
