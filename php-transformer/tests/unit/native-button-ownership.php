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
echo "Native button ownership: passed\n";
