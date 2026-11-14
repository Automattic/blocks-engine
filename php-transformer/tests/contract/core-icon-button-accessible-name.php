<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// The name is not visible text: an icon-only link can use core/button's title.
// A genuinely different visible label still needs the typed companion.
$html = '<style>.control{display:inline-flex;padding:8px;background:#222;color:white;width:40px;height:40px}</style>'
    . '<div><a class="control" href="/profile" aria-label="Visit profile"><svg class="profile-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M0 0h24v24H0z"/></svg></a></div>'
    . '<div><a class="control" href="/empty" aria-label="Empty control"></a></div>'
    . '<div><a class="control" href="/account" aria-label="Open account">Go</a></div>';
$result = (new HtmlTransformer())->transform($html)->toArray();
$buttons = array();
$visit = static function (array $blocks) use (&$visit, &$buttons): void {
    foreach ($blocks as $block) {
        if ('core/button' === ($block['blockName'] ?? '')) $buttons[] = $block;
        $visit($block['innerBlocks'] ?? array());
    }
};
$visit($result['blocks']);
if (2 !== count($buttons)
    || 'Visit profile' !== ($buttons[0]['attrs']['title'] ?? '')
    || '/profile' !== ($buttons[0]['attrs']['url'] ?? '')
    || !str_contains($buttons[0]['attrs']['text'] ?? '', '<img')
    || 'Empty control' !== ($buttons[1]['attrs']['title'] ?? '')
    || !str_contains($buttons[1]['attrs']['text'] ?? '', 'Empty control')
    || 1 !== substr_count($result['serialized_blocks'], '<!-- wp:custom/accessible-link ')
    || 'pass' !== ($result['source_reports']['wp_block_validity']['status'] ?? '')) {
    throw new RuntimeException('Icon-only and empty named controls must use core buttons without losing their artwork or name: ' . json_encode($result));
}
// The source's fixed icon geometry must beat Core's two-class width:100%
// reset after the button wrapper is flattened.
$inline = (new HtmlTransformer())->transform('<style>.icon{display:inline-flex;padding:8px;width:40px;height:40px;background:#222}</style><div><a class="icon" href="/a" aria-label="First"><svg viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></svg></a> <a class="icon" href="/b" aria-label="Second"><svg viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></svg></a></div>')->toArray();
$css = implode("\n", array_column($inline['assets'], 'content'));
if (!str_contains($inline['serialized_blocks'], 'wp:button ')
    || !str_contains($css, '.blocks-engine-layout-child-button> :where(.wp-block-button__link)') || !str_contains($css, 'width:40px')) {
    throw new RuntimeException('Flattened icon button geometry must override Core link sizing.');
}
echo "Core icon button accessible name contract passed.\n";
