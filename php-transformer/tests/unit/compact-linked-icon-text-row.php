<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$source = '<style>.flex{display:flex}.items-center{align-items:center}.gap-1\\.5{gap:6px}.row:hover{color:#123456}'
    . '.row-icon{width:18px;height:18px}</style>'
    . '<p class="blocks-engine-inline-layout-carrier"><a class="row" href="/projects">'
    . '<span class="flex items-center gap-1.5"><svg class="row-icon" aria-hidden="true" width="18" height="18" viewBox="0 0 18 18"><path d="M0 0h18v18z"/></svg>247</span>'
    . '</a></p>';
$result = (new HtmlTransformer())->transform($source)->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$css = implode("\n", array_map(
    static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '',
    $result['assets'] ?? array()
));
$blocks = $result['blocks'] ?? array();
$names = array();
$visit = static function (array $items) use (&$visit, &$names): void {
    foreach ($items as $block) {
        $names[] = $block['blockName'] ?? '';
        $visit($block['innerBlocks'] ?? array());
    }
};
$visit($blocks);

$assertions = array(
    'editable image block' => in_array('core/image', $names, true),
    'editable text block' => in_array('core/paragraph', $names, true),
    'number and link retained' => str_contains($markup, 'href="/projects"') && str_contains($markup, '>247</a>'),
    'layout classes retained' => str_contains($markup, ' row flex items-center gap-1.5"'),
    'source spacing and hover selectors retained' => str_contains($css, 'gap:6px') && str_contains($css, '.row:hover{color:#123456}'),
    'source icon dimensions retained' => str_contains($css, '.row-icon{width:18px;height:18px}'),
    'accessible decorative icon' => str_contains($markup, 'alt=""'),
    'no raw HTML fallback' => !str_contains($markup, '<!-- wp:html'),
    'validity' => 'pass' === ($result['source_reports']['wp_block_validity']['status'] ?? ''),
);
foreach ($assertions as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, 'FAIL: ' . $name . "\n" . $markup . "\n");
        exit(1);
    }
}

$imageResult = (new HtmlTransformer())->transform(
    '<p class="blocks-engine-inline-layout-carrier"><a class="row" href="/projects"><span class="items-center gap-1.5">'
    . '<img src="icon.png" width="18" height="18" alt="" />247</span></a></p>',
    array( 'context' => array( 'asset_metadata' => array( 'icon.png' => array( 'id' => 73, 'url' => '/media/icon.png' ) ) ) )
)->toArray();
$imageMarkup = (string) ($imageResult['serialized_blocks'] ?? '');
if (!str_contains($imageMarkup, '"id":73') || !str_contains($imageMarkup, 'href="/projects"') || !str_contains($imageMarkup, '>247</a>')) {
    fwrite(STDERR, "FAIL: materialized-image linked row remains natively editable\n" . $imageMarkup . "\n");
    exit(1);
}
$formatted = (new HtmlTransformer())->transform(
    '<p><a href="/projects"><span><svg aria-hidden="true" width="18" height="18" viewBox="0 0 18 18"><path d="M0 0h18v18z"/></svg><strong>247</strong></span></a></p>'
)->toArray();
if (!str_contains((string) ($formatted['serialized_blocks'] ?? ''), '<strong>247</strong>')) {
    fwrite(STDERR, "FAIL: genuine inline formatting remains RichText\n" . ($formatted['serialized_blocks'] ?? '') . "\n");
    exit(1);
}
fwrite(STDOUT, 'Compact linked icon/text row passed: ' . (count($assertions) + 2) . " assertions\n");
