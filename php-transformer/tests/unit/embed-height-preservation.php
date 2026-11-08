<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$src = 'src="https://www.youtube.com/embed/demo"';
// HTML size attributes still size the axis without a CSS width/height.
// A preferred aspect-ratio does not replace either explicit dimension.
foreach (array(
    array('.frame{width:100%;height:auto;aspect-ratio:16/9}', '', ''),
    array('@media(min-width:1401px){.frame{height:400px}}@media(max-width:1400px){.frame{height:auto;aspect-ratio:16/9}}', '901', ''),
    array('.frame{width:100%;aspect-ratio:4/3}', '', '507'),
    array('.frame{height:400px}', '901', ''),
    array('.frame{aspect-ratio:4/3}', '901', '507'),
) as [$css, $width, $height]) {
    $result = (new HtmlTransformer())->transform('<style>' . $css . '</style><iframe class="frame" ' . $src . ' width="901" height="507"></iframe>')->toArray();
    $block = $result['blocks'][0];
    $assert(str_ends_with($block['blockName'], '/visual-iframe'), 'CSS-sized provider frames retain their source selector surface in an editable iframe block.');
    $assert(($block['attrs']['width'] ?? '') === $width && ($block['attrs']['height'] ?? '') === $height, 'Only dimensions replaced by CSS on that axis are omitted: ' . $css);
    $sheet = implode("\n", array_column($result['assets'], 'content'));
    $assert(!str_contains($sheet, 'height:507') && !str_contains($sheet, '.wp-block-embed__wrapper{height:'), 'No reference-viewport wrapper height replaces the author cascade.');
}
// Width-only donation form regression: the source height must not become 150px.
$form = (new HtmlTransformer())->transform('<!doctype html><html><head><meta charset="utf-8"><title>Iframe b</title><style>.embed-form iframe{width:100%}</style></head><body><main><h1>Form</h1><div class="embed-form"><iframe src="https://example.org/embed/form" width="1000" height="1208"></iframe></div></main></body></html>')->toArray();
$assert(str_contains($form['serialized_blocks'], 'height="1208"'), 'Width-only author sizing preserves the form height in saved markup.');
$assert(!str_contains($form['serialized_blocks'], 'width="1000"'), 'Author CSS continues to own the form width.');
$inline = (new HtmlTransformer())->transform('<iframe ' . $src . ' style="width:100%;aspect-ratio:1.6"></iframe>')->toArray();
$sheet = implode("\n", array_column($inline['assets'], 'content'));
$assert(str_ends_with($inline['blocks'][0]['blockName'], '/visual-iframe') && !preg_match('/(?:width|aspect-ratio):[^;}]*!important/', $sheet), 'Inline ratio keeps normal priority and is not approximated to a core preset.');
// An inline fill at a core embed ratio is the box a responsive core/embed draws,
// even when capture also measured one viewport's width and height attributes.
foreach (array('style="width:100%;aspect-ratio:16/9"', 'style="display:block;border:0;width:100%;height:auto;aspect-ratio:1.77778 / 1" width="1035" height="582"') as $sizing) {
    $fluid = (new HtmlTransformer())->transform('<iframe ' . $src . ' ' . $sizing . '></iframe>')->toArray();
    $block = $fluid['blocks'][0];
    $sheet = implode("\n", array_column($fluid['assets'], 'content'));
    $assert('core/embed' === $block['blockName'], 'A fluid frame at a core embed ratio stays core/embed: ' . $sizing);
    $assert(str_contains((string) ($block['attrs']['className'] ?? ''), 'wp-embed-aspect-16-9') && true === ($block['attrs']['responsive'] ?? null), 'The fluid frame keeps its ratio through the core aspect class: ' . $sizing);
    $assert(!str_contains($sheet, '.wp-block-embed__wrapper{height:'), 'No measured wrapper height pins a fluid frame: ' . $sizing);
}
$unsized = (new HtmlTransformer())->transform('<iframe ' . $src . '></iframe>')->toArray();
$assert('core/embed' === $unsized['blocks'][0]['blockName'] && empty($unsized['assets']), 'Unsized providers retain ordinary core oEmbed behavior.');
$fixed = (new HtmlTransformer())->transform('<iframe ' . $src . ' width="560" height="315"></iframe>')->toArray();
$assert('core/embed' === $fixed['blocks'][0]['blockName'], 'Attribute-only fixed embeds remain compatible.');
echo "Embed source sizing passed\n";
