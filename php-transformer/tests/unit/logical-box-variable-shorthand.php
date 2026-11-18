<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$source = <<<'HTML'
<!doctype html><html><head><meta charset="utf-8"><title>Stack</title><style>
body{margin:0;font-family:sans-serif}
.bar{--item-margin-block:0px 17px;background:#5d1ff2;width:40px;padding:12px 8px}
.bar ul{list-style:none;margin:0;padding:0}
.bar li{width:24px;height:24px;margin-block:var(--item-margin-block);background:#fff}
.bar li:last-child{margin-block:0}
</style></head><body><main><div class="bar"><ul><li><a href="#a">A</a></li><li><a href="#b">B</a></li><li><a href="#c">C</a></li></ul></div></main></body></html>
HTML;

$result = (new HtmlTransformer())->transform($source)->toArray();
$markup = $result['serialized_blocks'];
if (str_contains($markup, 'margin-top:var(--item-margin-block)') || str_contains($markup, 'margin-bottom:var(--item-margin-block)')) {
    throw new RuntimeException('A two-value logical shorthand must not become invalid physical longhands: ' . $markup);
}
$output = $markup . implode("\n", array_column($result['assets'], 'content'));
if (!str_contains($output, 'margin-block:var(--item-margin-block)') || !str_contains($output, '--item-margin-block:0px 17px')) {
    throw new RuntimeException('The logical shorthand and its two-value custom property must survive for browser substitution.');
}
echo "Logical box variable shorthand passed.\n";
