<?php
declare(strict_types=1);

/**
 * A submenu parent keeps the same source item boxes as its plain-link peers,
 * even when one box also holds the source disclosure glyph Core's submenu icon
 * replaces. Without them the label loses the box's colour, font and padding.
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$item = static fn(string $label, string $href, string $glyph = '', string $submenu = ''): string =>
    '<li><div class="item-box"><div class="item-inner">' . $glyph
    . '<a class="item-link" href="' . $href . '"' . ('' === $submenu ? '' : ' aria-haspopup="true" aria-expanded="false"') . '>' . $label . '</a></div></div>' . $submenu . '</li>';
$html = '<style>.menu{display:block}.item-box{color:#f9f9f9;font-family:Lora;padding:16px 0}'
    . '.item-inner{position:relative}.item-link{display:block}.item-glyph{position:absolute;left:-36px;width:36px}</style>'
    . '<header><nav class="menu"><ul>'
    . $item('Home', '/home')
    . $item('Kings', '/kings', '<div class="item-glyph" role="presentation"><svg viewBox="0 0 24 24" width="24" height="24"><path d="M7 10l5 5 5-5"/></svg></div>',
        '<ul><li><div class="item-box"><div class="item-inner"><a class="item-link" href="/kings/retired">Retired</a></div></div></li></ul>')
    . '</ul></nav></header>';

$blocks = (new HtmlTransformer())->transform($html)->toArray()['serialized_blocks'];
$boxes = static function (string $block) use ($blocks): array {
    preg_match('/<!-- wp:' . $block . ' (\{.*?\}) \/?-->/s', $blocks, $match);
    $attrs = json_decode($match[1] ?? '', true) ?: array();
    return array_map(static fn(array $box): string => $box['tag'] . ':' . preg_replace('/ blocks-engine-\S+/', '', $box['className']), $attrs['metadata']['blocksEngineNavigationAnchor']['boxes'] ?? array());
};

$link = $boxes('navigation-link');
$assert(array('div:item-box', 'div:item-inner') === $link, 'A plain link keeps its source item boxes: ' . json_encode($link));
$assert($link === $boxes('navigation-submenu'), 'A submenu parent keeps the same item boxes as its plain-link peers: ' . json_encode($boxes('navigation-submenu')));
echo "Navigation submenu parent anchor boxes passed: 2 assertions\n";
