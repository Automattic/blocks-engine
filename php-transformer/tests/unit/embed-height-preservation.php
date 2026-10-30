<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$src = 'src="https://www.youtube.com/embed/demo"';
foreach (array(
    '.frame{width:100%;height:auto;aspect-ratio:16/9}',
    '@media(min-width:1401px){.frame{height:400px}}@media(max-width:1400px){.frame{height:auto;aspect-ratio:16/9}}',
    '.frame{width:100%;aspect-ratio:4/3}',
    '.frame{height:400px}',
) as $css) {
    $result = (new HtmlTransformer())->transform('<style>' . $css . '</style><iframe class="frame" ' . $src . ' width="901" height="507"></iframe>')->toArray();
    $block = $result['blocks'][0];
    $assert(str_ends_with($block['blockName'], '/visual-iframe'), 'CSS-sized provider frames retain their source selector surface in an editable iframe block.');
    $assert(empty($block['attrs']['height']) && empty($block['attrs']['width']), 'Captured dimensions are not saved as block attributes.');
    $sheet = implode("\n", array_column($result['assets'], 'content'));
    $assert(!str_contains($sheet, 'height:507') && !str_contains($sheet, '.wp-block-embed__wrapper{height:'), 'No reference-viewport wrapper height replaces the author cascade.');
}
$inline = (new HtmlTransformer())->transform('<iframe ' . $src . ' style="width:100%;aspect-ratio:16/9"></iframe>')->toArray();
$sheet = implode("\n", array_column($inline['assets'], 'content'));
$assert(str_ends_with($inline['blocks'][0]['blockName'], '/visual-iframe') && !preg_match('/(?:width|aspect-ratio):[^;}]*!important/', $sheet), 'Inline ratio keeps normal priority and is not approximated to a core preset.');
$unsized = (new HtmlTransformer())->transform('<iframe ' . $src . '></iframe>')->toArray();
$assert('core/embed' === $unsized['blocks'][0]['blockName'] && empty($unsized['assets']), 'Unsized providers retain ordinary core oEmbed behavior.');
$fixed = (new HtmlTransformer())->transform('<iframe ' . $src . ' width="560" height="315"></iframe>')->toArray();
$assert('core/embed' === $fixed['blocks'][0]['blockName'], 'Attribute-only fixed embeds remain compatible.');
echo "Embed source sizing passed\n";
