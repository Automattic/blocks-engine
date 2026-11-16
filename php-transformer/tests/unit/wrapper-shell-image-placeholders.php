<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// #6173: heading image unwrapping must preserve a generated shell's save slots.
$html = file_get_contents(__DIR__ . '/wrapper-shell-binding-identity.html');
$result = (new HtmlTransformer())->transform($html)->toArray();
$markup = $result['serialized_blocks'];
if (!str_contains($markup, 'src="/logo.png"') || !str_contains($markup, '/layout-shell')) {
    throw new RuntimeException('Logo shell must serialize its editable image.');
}
foreach ($result['diagnostics'] as $diagnostic) {
    if (str_starts_with($diagnostic['code'], 'wp_block_validity_')) throw new RuntimeException('Invalid logo shell: ' . $diagnostic['code']);
}
echo "wrapper-shell-image-placeholders: ok\n";
