<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$icon = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="glyph" data-dla-disclosure-closed-class="glyph" data-dla-disclosure-open-class="glyph expanded"><path d="m6 9 6 6 6-6"/></svg>';
$style = '<style>.glyph{color:rgb(37,56,85);transition-property:transform;transition-duration:150ms}main button .expanded{transform:rotate(180deg)}</style>';
$item = static fn (string $id): string => '<article><button aria-expanded="false" aria-controls="' . $id . '">Question ' . $id . $icon . '</button><div role="region" id="' . $id . '" hidden><p>Answer</p></div></article>';
$source = $style . '<main><section>' . $item('one') . $item('two') . '</section></main>';
$result = (new HtmlTransformer())->transform($source)->toArray();
$css = '';
foreach ($result['assets'] ?? array() as $asset) {
    if ('css' === ($asset['kind'] ?? '')) $css .= $asset['content'] . "\n";
}
echo json_encode(array('source' => $source, 'markup' => $result['serialized_blocks'], 'css' => $css, 'validity' => $result['source_reports']['wp_block_validity']), JSON_THROW_ON_ERROR);
