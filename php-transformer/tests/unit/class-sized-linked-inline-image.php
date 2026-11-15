<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

require_once __DIR__ . '/../../vendor/autoload.php';

$html = require __DIR__ . '/../fixtures/class-sized-linked-inline-image.php';
$result = (new HtmlTransformer())->transform($html)->toArray();
$markup = $result['serialized_blocks'];
$css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === $asset['kind']), 'content'));
if (!str_contains($markup, 'blocks-engine-synthetic-image-figure-inline') || str_contains($markup, 'is-resized')) {
    throw new RuntimeException('The intrinsic linked image must use an unsized inline synthetic figure.');
}
$leaf = '.icon.wp-block-image:where(.blocks-engine-synthetic-image-figure-inline) > a > img';
if (!str_contains($css, $leaf . '{border-radius:15px;width:70px;height:70px}')
    || !str_contains($css, $leaf . '{width:60px;height:60px}')) {
    throw new RuntimeException('Class-owned desktop and responsive box sizes must reach the image leaf: ' . $css);
}
echo "OK: class-sized linked inline image\n";
