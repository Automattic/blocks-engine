<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A lowered standalone link needs the same box on both surfaces:
// Chromium cannot edit display:contents and editor-only boxes break parity.
$result = (new HtmlTransformer())->transform('<main><a class="eyebrow" href="/catalog/">Catalog</a></main>')->toArray();
$css = implode("\n", array_column($result['assets'], 'content'));
$carrier = 'p.blocks-engine-synthetic-paragraph:not(.blocks-engine-source-box-paragraph)';
$inline = ':where(' . $carrier . '){display:inline}';
$boxless = ':where(' . $carrier . ':has(>a)){display:contents}';
if (!str_contains($result['serialized_blocks'], 'blocks-engine-synthetic-paragraph')
    || !str_contains($css, $inline) || str_contains($css, $boxless)) {
    fwrite(STDERR, "FAIL: standalone link needs a matching, editable inline box on both surfaces\n");
    exit(1);
}
echo "synthetic anchor editor box passed\n";
