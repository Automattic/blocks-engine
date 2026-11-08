<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A flex-item button link whose base width:100% lands on its core/buttons
// wrapper chain must release that same wrapper at the breakpoint; releasing
// only the link leaves the wrapper stretched across the row.
$source = file_get_contents(dirname(__DIR__) . '/fixtures/buttons-wrapper-responsive-width-release.html');
$result = (new HtmlTransformer())->transform($source)->toArray();
$css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
if ( 1 !== preg_match('/@media \(min-width:768px\)\{[^{}]*:where\(\.wp-block-buttons\)\{width:auto\}/', $css) ) {
    throw new RuntimeException('The breakpoint width release reaches the core/buttons wrapper.');
}
echo "Buttons wrapper responsive width release contract passed.\n";
