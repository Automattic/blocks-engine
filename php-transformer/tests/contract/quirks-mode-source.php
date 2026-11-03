<?php
declare(strict_types=1);

// A quirks-mode capture keeps its tight small-font lines, start-aligned table text and edge margins, and lines no taller than their runs (#4026, #4051, #4110).
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$body = '<p><font size="-2">One<br>Two</font></p><p>Keep <font size="-2">small</font></p>'
    . '<div align="right"><table><tr><td><p align="right">　<font size="-2">Poem</font></p></td></tr><tr><td><font size="-2">Caption<br>Note</font></td></tr></table></div>';
$transform = static fn (string $root, string $body): string => (string) ((new HtmlTransformer())->transform("<!DOCTYPE html><html{$root}><head><title>Q</title><style>.k{margin-top:30px}</style></head><body>{$body}</body></html>")->toArray()['serialized_blocks'] ?? '');
$quirksRoot = ' data-blocks-engine-capture-compat-mode="BackCompat"';
$quirks = $transform($quirksRoot, $body);
$standards = $transform('', $body);
$runs = '<table width="300"><tr><td><font size="+4">Big</font><font size="-1"><br>small<br><font size="-2">tiny</font></font>　<br></td></tr><tr><td style="line-height:20px"><font size="-1">One</font><br>two</td></tr></table>';
$lines = $transform($quirksRoot, $runs);
$margins = $transform($quirksRoot, '<p>First</p><p>Second</p><table><tr><td> <p>Cell</p> </td><td><p class="k">Authored</p>tail</td></tr></table>');

$checks = array(
    'sole run sizes its paragraph'  => array($quirks, '<p style="margin-top:0;font-size:10px">One<br>Two</p>', true),
    'mixed run stays an inline mark' => array($quirks, 'Keep <mark style="font-size:10px', true),
    'aligned paragraph in a cell'   => array($quirks, '<p class="has-text-align-right" style="margin-top:0;margin-bottom:0;font-size:10px"><mark style="font-size:16px;background-color:transparent;color:inherit" role="none">　</mark>Poem</p>', true),
    'cell run starts at the start edge' => array($quirks, '<p class="blocks-engine-synthetic-paragraph blocks-engine-source-box-paragraph" style="font-size:10px">Caption<br>Note</p>', true),
    'standards source is unchanged' => array($standards, '<p><mark style="font-size:10px;background-color:transparent;color:inherit" role="none">One<br>Two</mark></p>', true),
    'evidence stays out of content' => array($quirks, 'compat-mode', false),
    'body edge drops the top margin' => array($margins, '<p style="margin-top:0">First</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Second</p>', true),
    'sole cell paragraph drops both' => array($margins, '<p style="margin-bottom:0;margin-top:0">Cell</p>', true),
    'cell strut is dropped'          => array($lines, '<p class="blocks-engine-synthetic-paragraph blocks-engine-source-box-paragraph" style="line-height:0"><mark style="line-height:normal;font-size:48px', true),
    'smaller run keeps its lines'    => array($lines, '<mark style="line-height:normal;font-size:13px;background-color:transparent;color:inherit" role="none"><br>small<br><mark style="font-size:10px', true),
    'own text keeps its strut'       => array($lines, '<mark style="line-height:normal;background-color:transparent;color:inherit" role="none">　</mark>', true),
    'author line height wins'        => array($lines, '<p class="blocks-engine-synthetic-paragraph"><mark style="font-size:13px;background-color:transparent;color:inherit" role="none">One</mark>', true),
    'standards lines are unchanged'  => array($transform('', $runs), 'line-height:0', false),
    'author margin wins'             => array($margins, '<p class="k">Authored</p>', true),
);
foreach ($checks as $label => [$markup, $needle, $present]) {
    if (str_contains($markup, $needle) !== $present) {
        fwrite(STDERR, "FAIL: {$label}\n{$markup}\n");
        exit(1);
    }
}
echo "quirks-mode-source: passed\n";
