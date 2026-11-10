<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A captured close hook need not resolve to a panel in this fragment. Keep
// its native runtime owner rather than lowering it to an unbound core box.
$html = '<button type="button" hidden data-blocks-engine-capture-dialog-close="external-panel">Close</button>'
    . '<script>document.querySelectorAll("[data-blocks-engine-capture-dialog-close]").forEach(function(close){close.addEventListener("click",function(){close.hidden=true;});});</script>';
$result = (new HtmlTransformer())->transform($html)->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
if (!str_contains($markup, 'data-blocks-engine-capture-dialog-close="external-panel"')
    || !preg_match('/<button\b[^>]*>Close<\/button>/', $markup)) {
    throw new RuntimeException('An unresolved captured close control must retain its native runtime binding.');
}
echo "captured-unresolved-close-binding: ok\n";
