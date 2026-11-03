<?php
declare(strict_types=1);

// A <br> between loose inline runs ends the line inside the inline paragraph standing for the run (#4070).
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$cases = array(
    'body runs'          => array('A<br>B<br><font size="-1">C</font><br><br>D', '>A<br></p>|>B<br></p>|</mark><br><br></p>|>D</p>'),
    'blank lines'        => array('<table><tr><td width="561">A<br>　　<br>　　</td></tr></table>', '>A<br></p>|>　　<br></p>|>　　</p>'),
    'anchor run'         => array('x<a href="/a">L</a><br>y', '<a href="/a">L</a><br></p>'),
    'flex items'         => array('<div style="display:flex">A<br>B</div>', '>A</p>|>B</p>'),
);
foreach ($cases as $label => [$html, $expected]) {
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $markup = (string) preg_replace('/<!-- [^>]*-->/', '', (string) $result['serialized_blocks']);
    foreach (explode('|', $expected) as $needle) {
        if (!str_contains($markup, $needle)) {
            fwrite(STDERR, "FAIL: {$label}: missing {$needle}\n{$markup}\n");
            exit(1);
        }
    }
    if ('pass' !== ($result['source_reports']['wp_block_validity']['status'] ?? '')) {
        fwrite(STDERR, "FAIL: {$label}: invalid blocks\n");
        exit(1);
    }
}
echo "inline-run-line-breaks: passed\n";
