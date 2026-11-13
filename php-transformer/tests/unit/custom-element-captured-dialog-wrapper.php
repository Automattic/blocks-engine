<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$cases = array(
    'close control without panel' => 'Your cart is empty<button data-blocks-engine-capture-dialog-close="d0"></button>',
    'header with bounded panel' => '<header><a href="/">Logo</a><div role="dialog" hidden id="d0" data-blocks-engine-capture-dialog-panel="d0"><h2>Your cart is empty</h2><button type="button" data-blocks-engine-capture-dialog-close="d0">×</button></div></header>',
);
$failures = array();
foreach ($cases as $name => $content) {
    $result = (new HtmlTransformer())->transform('<sticky-header>' . $content . '</sticky-header>')->toArray();
    $reference = (new HtmlTransformer())->transform('<div>' . $content . '</div>')->toArray();
    $markup = $result['serialized_blocks'];
    if (array() !== $result['fallbacks']) $failures[] = $name . ': wrapper must not record fallbacks';
    if (array() !== $reference['fallbacks']) $failures[] = $name . ': div reference must not record fallbacks';
    preg_match_all('/<!-- wp:([^\s]+)(?:\s|-->)/', $markup, $blocks);
    preg_match_all('/<!-- wp:([^\s]+)(?:\s|-->)/', $reference['serialized_blocks'], $referenceBlocks);
    if ($referenceBlocks[1] !== $blocks[1]) $failures[] = $name . ': wrapper must use the same native and bounded runtime blocks as div';
    if (!str_contains($markup, '<!-- wp:paragraph ')
        || !str_contains($markup, '<!-- wp:group')
        || str_contains($markup, '<!-- wp:html')
        || str_contains($markup, '<!-- wp:custom/panel-')
    ) {
        $failures[] = $name . ': wrapper and sibling content must remain native blocks';
    }
    if (str_contains($content, '<header>')) {
        $panelStart = strpos($markup, '<!-- wp:custom/layout-shell ');
        $logo = strpos($markup, '<a href="/">Logo</a>');
        if (false === $panelStart || false === $logo || $logo >= $panelStart
            || !str_contains($markup, 'data-blocks-engine-capture-dialog-panel="d0" hidden="" id="d0" role="dialog"')
            || !str_contains($markup, '<!-- wp:heading ')
        ) {
            $failures[] = $name . ': dialog must retain its bounded panel, outside the native header link';
        }
    } elseif (!str_contains($markup, '<!-- wp:custom/authored-button ')) {
        $failures[] = $name . ': dialog control must retain its own runtime owner';
    }
}
foreach ($failures as $failure) fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
exit(array() === $failures ? 0 : 1);
