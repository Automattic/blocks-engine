<?php
declare(strict_types=1);

// A quirks-mode capture keeps its tight small-font lines and start-aligned table text (#4026).
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$body = '<p><font size="-2">One<br>Two</font></p><p>Keep <font size="-2">small</font></p>'
    . '<div align="right"><table><tr><td><p align="right">　<font size="-2">Poem</font></p></td></tr><tr><td><font size="-2">Caption<br>Note</font></td></tr></table></div>';
$transform = static fn (string $root): string => (string) ((new HtmlTransformer())->transform("<!DOCTYPE html><html{$root}><head><title>Q</title></head><body>{$body}</body></html>")->toArray()['serialized_blocks'] ?? '');
$quirks = $transform(' data-blocks-engine-capture-compat-mode="BackCompat"');
$standards = $transform('');

$checks = array(
    'sole run sizes its paragraph'  => array($quirks, '<p style="font-size:10px">One<br>Two</p>', true),
    'mixed run stays an inline mark' => array($quirks, 'Keep <mark style="font-size:10px', true),
    'aligned paragraph in a cell'   => array($quirks, '<p class="has-text-align-right" style="font-size:10px">　Poem</p>', true),
    'cell run starts at the start edge' => array($quirks, '<p class="blocks-engine-synthetic-paragraph blocks-engine-source-box-paragraph" style="font-size:10px">Caption<br>Note</p>', true),
    'standards source is unchanged' => array($standards, '<p><mark style="font-size:10px;background-color:transparent;color:inherit" role="none">One<br>Two</mark></p>', true),
    'evidence stays out of content' => array($quirks, 'compat-mode', false),
);
foreach ($checks as $label => [$markup, $needle, $present]) {
    if (str_contains($markup, $needle) !== $present) {
        fwrite(STDERR, "FAIL: {$label}\n{$markup}\n");
        exit(1);
    }
}
echo "quirks-mode-source: passed\n";
