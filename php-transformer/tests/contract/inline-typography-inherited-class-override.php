<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// An inline font-size equal to the inherited value is not redundant when a
// class rule on the same element sets a different size: dropping it lets the
// class rule win.
$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Inline size test</title>
<style>
body { font-size: 10px; }
.text { font-size: 20px; }
</style></head><body>
<p class="text" style="font-size:10px">Small paragraph text that should render at ten pixels.</p>
<p class="text" style="font-size:14px">Control paragraph text that should render at fourteen pixels.</p>
</body></html>
HTML;
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => $html),
))->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
if (! str_contains($markup, '"fontSize":"10px"') || ! str_contains($markup, '"fontSize":"14px"')) {
    fwrite(STDERR, "Inline typography must retain both source sizes through conversion.\n" . $markup . "\n");
    exit(1);
}
fwrite(STDOUT, "Inline inherited typography override contract passed\n");
