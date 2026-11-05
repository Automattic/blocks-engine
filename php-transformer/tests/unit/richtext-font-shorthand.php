<?php
declare(strict_types=1);

/** Issue #4523: pending font shorthands survive every RichText projection. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$cases = array();
foreach (array('.caption span', '.detail', 'span[data-detail]') as $selector) {
    foreach (array(
        'font:300 var(--small)/1 var(--body)',
        'font:300 var(--small)/1 var(--body);line-height:1.5',
        'font-weight:700;font:300 var(--small)/1 var(--body)',
        'font:300 var(--small)/1 var(--body);font-weight:700',
        'font:italic 300 .72rem/1 Arial,sans-serif',
    ) as $font) {
        $css = ':root{--small:.72rem;--body:Arial,sans-serif}p{font:16px/1.7 serif}'
            . $selector . '{display:block;' . $font . ';letter-spacing:.2em;text-transform:uppercase;color:#456;margin-top:10px}';
        $source = '<style>' . $css . '</style><p><a href="/catalog"><span class="caption"><b>Collection</b>'
            . '<span class="detail" data-detail>31 items</span></span></a></p>';
        $result = (new HtmlTransformer())->transform($source)->toArray();
        $markup = $result['serialized_blocks'];
        $doc = new DOMDocument();
        @$doc->loadHTML($markup);
        $xpath = new DOMXPath($doc);
        $target = $xpath->query('//mark[text()="31 items"]')->item(0);
        if (!$target instanceof DOMElement || !str_contains($target->getAttribute('style'), 'font:') && !str_contains($target->getAttribute('style'), 'font-size:')) {
            fwrite(STDERR, "FAIL: {$selector} loses {$font}\n{$markup}\n");
            exit(1);
        }
        foreach (array('letter-spacing:.2em', 'text-transform:uppercase', 'color:#456', 'margin-top:10px') as $longhand) {
            if (!str_contains($target->getAttribute('style'), $longhand)) {
                fwrite(STDERR, "FAIL: missing {$longhand}\n");
                exit(1);
            }
        }
        // Isolate the inline projection from author CSS replay: it must carry
        // the complete font itself, with custom properties supplied by :root.
        $cases[] = array('source' => $source, 'output' => '<style>:root{--small:.72rem;--body:Arial,sans-serif}p{font:16px/1.7 serif}</style>' . $markup);
    }
}
if (in_array('--browser-fixture', $argv, true)) {
    echo json_encode($cases, JSON_THROW_ON_ERROR);
} else {
    echo "RichText font shorthand projection passed\n";
}
