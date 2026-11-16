<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Core lowering must not steal the native form or runtime-binding owners.
$cases = array(
    '<form><input name="email"><button type="submit" class="action">Send</button></form>' => '<button type="submit" class="action">Send</button>',
    '<button type="button" aria-controls="panel" data-action="open">Open</button>' => 'aria-controls="panel"',
);
foreach ($cases as $source => $nativeMarkup) {
    $result = (new HtmlTransformer())->transform('<style>.action{padding:12px;background:#123}</style>' . $source)->toArray();
    $markup = $result['serialized_blocks'];
    if (!str_contains($markup, $nativeMarkup) || str_contains($markup, 'blocksEngineControlLabel')) {
        throw new RuntimeException('Native control ownership was bypassed: ' . $markup);
    }
}
// Captured native forms own their controls too, but their plain actions
// must expose editable Core labels while keeping the original native box.
foreach (array('button', 'reset', 'submit') as $type) {
    $result = (new HtmlTransformer())->transform(
        '<style>.action{padding:8px;background:#123;color:white}</style>'
        . '<form><input name="email" data-blocks-engine-capture-native-control-state=\'{"version":1,"kind":"input","value":"","defaultValue":""}\'>'
        . '<button type="' . $type . '" class="action">Choose &amp; continue</button></form>'
    )->toArray();
    $markup = $result['serialized_blocks'];
    if (str_contains($markup, '/authored-button')
        || !str_contains($markup, '<!-- wp:button ')
        || !str_contains($markup, '"blocksEngineControlBox":{"type":"' . $type . '","class":"action"}')
        || !str_contains($markup, '"blocksEngineControlTagName":"button"')
        || !str_contains($markup, '<button type="' . $type . '"')
        || !str_contains($markup, 'Choose &amp; continue')
        || 'pass' !== ($result['source_reports']['wp_block_validity']['status'] ?? null)) {
        throw new RuntimeException('Captured form action must retain its native box and editable Core label: ' . $markup);
    }
}
echo "Native button ownership: passed\n";
