<?php
declare(strict_types=1);

/**
 * Keep class-bound authored rules when projecting selectors onto RichText
 * markers: a responsive representation may emit the same class on a block
 * wrapper without a RichText marker.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$html = '<style>.av{display:inline-block;height:32px;position:relative;width:32px}'
    . '.ic,.fav{height:100%;width:100%}</style>'
    . '<a href="/item"><span class="av" ><span class="fav">'
    . '<svg class="ic" width="1000" height="1000" viewBox="0 0 10 10">'
    . '<circle cx="5" cy="5" r="5"/></svg></span></span><span>Item 1</span></a>';

$result = ( new HtmlTransformer() )->transform($html)->toArray();
$css    = '';
foreach ( $result['assets'] ?? array() as $asset ) {
    if ( 'author-css' === ($asset['source'] ?? '') ) {
        $css .= (string) ($asset['content'] ?? '');
    }
}

$hasMarkerProjection = str_contains($css, 'data-blocks-engine-richtext-marker="blocks-engine-richtext-');
$hasClassProjection  = str_contains($css, '.av:not(:where(');

if ( ! $hasMarkerProjection || ! $hasClassProjection ) {
    fwrite(STDERR, 'FAIL: class-bound avatar rule accompanies its RichText marker projection' . PHP_EOL);
    exit(1);
}

// A variant-scoped rule (ancestor compound + class subject) keeps its class
// form too: the mobile variant emits the element as a classed block wrapper.
$scoped = ( new HtmlTransformer() )->transform('<style>.scope .av{display:inline-block;height:32px;width:32px}.ic,.fav{height:100%;width:100%}</style>'
    . '<div class="scope"><a href="/item"><span class="av"><span class="fav"><svg class="ic" width="1000" height="1000" viewBox="0 0 10 10"><circle cx="5" cy="5" r="5"/></svg></span></span><span>Item 1</span></a></div>')->toArray();
$scopedCss = '';
foreach ( $scoped['assets'] ?? array() as $asset ) {
    if ( 'author-css' === ($asset['source'] ?? '') ) {
        $scopedCss .= (string) ($asset['content'] ?? '');
    }
}
if ( str_contains($scopedCss, 'data-blocks-engine-richtext-marker="blocks-engine-richtext-') && ! preg_match('/\\.scope \\.av:not\\(:where\\(/', $scopedCss) ) {
    fwrite(STDERR, 'FAIL: scoped class-bound subject keeps its class form beside the RichText marker projection' . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'RichText class selector projection: marker and class-bound rules present' . PHP_EOL);
