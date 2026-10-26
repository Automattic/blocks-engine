<?php
declare(strict_types=1);

/**
 * WordPress 7.1 wraps an empty Group in an anonymous transport element in the
 * editor. Inside a layout shell that transport sits under the shell's
 * inner-blocks layer, not directly under a block element. Left as a block box
 * it has no height, so a painted source layer sized `height:100%` (a blurred
 * hero backdrop) resolves against zero and disappears from the canvas. The
 * transport must be flattened wherever the layer can host it.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\EngineSupportCss;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$result = ( new HtmlTransformer() )->transform(
    '<style>.hero{position:relative;height:400px}.backdrop{position:absolute;inset:0;filter:blur(10px)}.fill{width:100%;height:100%}.paint{width:100%;height:100%;background:linear-gradient(#fff9,#fff9),url(/a.jpg) center/cover}</style>'
    . '<main><section class="hero"><div class="backdrop"><div class="fill"><div class="paint"></div></div></div><h1>Hero</h1></section></main>'
)->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$editorCss = '';
foreach ( $result['assets'] ?? array() as $asset ) {
    if ( is_array($asset) && 'editor-static-state' === ($asset['source'] ?? null) ) {
        $editorCss .= (string) ($asset['content'] ?? '');
    }
}

$assert(str_contains($markup, 'blocks-engine-empty-visual-group'), 'the painted layer lowers to an empty visual group', $markup);
$assert(str_contains($markup, 'wp:ssi-imported-site/layout-shell') || str_contains($markup, 'layout-shell'), 'the painted layer sits inside a layout shell', $markup);

$transport = '>div:not([class]):not([id]):not([style]):has(>.' . HtmlTransformer::EMPTY_VISUAL_GROUP_CLASS . '.wp-block-group__placeholder){display:contents}';
$assert(str_contains($editorCss, '.block-editor-block-list__block' . $transport), 'the transport under a block element is flattened', $editorCss);

if ( 0 < $failures ) {
    fwrite(STDERR, "empty visual group shell transport FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "empty visual group shell transport passed: {$passes} assertions\n";
