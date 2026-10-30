<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$source = file_get_contents(dirname(__DIR__) . '/fixtures/empty-css-sized-paint.html');
$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        throw new RuntimeException($message);
    }
};

// CSS block boxes on phrasing tags must use the existing empty group path,
// including boxes sized by a ratio rather than an explicit height.
foreach ( array( 'aspect-ratio:3/2', 'height:80px' ) as $geometry ) {
    $result = ( new HtmlTransformer() )->transform(str_replace('aspect-ratio:3/2', $geometry, $source))->toArray();
    $swatches = $result['blocks'][0]['innerBlocks'] ?? array();
    $assert(3 === count($swatches), 'all swatches survive');
    foreach ( array( '#C9B79C', '#4A3B2A', '#A9792B' ) as $index => $color ) {
        $children = $swatches[$index]['innerBlocks'] ?? array();
        $chip = $children[0] ?? array();
        $assert(2 === count($children), 'each swatch retains paint and label');
        $assert('core/group' === ($chip['blockName'] ?? ''), 'CSS-sized paint is a native group');
        $assert(str_contains($chip['attrs']['className'] ?? '', 'blocks-engine-empty-visual-group'), 'existing empty visual classification is reused');
        $assert($color === ($chip['attrs']['style']['color']['background'] ?? ''), 'paint uses editable native background support');
        $assert('8px' === ($chip['attrs']['style']['border']['radius'] ?? ''), 'radius survives');
        $assert('1px' === ($chip['attrs']['style']['border']['width'] ?? ''), 'border survives');
    }
    $assert('pass' === ($result['source_reports']['wp_block_validity']['status'] ?? ''), 'groups remain editor-valid');
    $css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === $asset['kind']), 'content'));
    $assert(! str_contains($css, 'background:#C9B79C !important'), 'paint carrier does not override native recolouring');
    $assert(str_contains($css, $geometry), 'source geometry remains stylesheet-owned');
}

echo "Empty CSS-sized paint passed.\n";
