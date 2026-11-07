<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// An overflow menu lists every route and hides, inline, the ones the bar
// already shows. The capture replays the opened panel with its own disclosure
// stylesheet, which forces the panel's first child visible with `!important`.
// That rule is the producer's replay state, not author presentation: the first
// item keeps its authored `display:none`, so it is not read as displayed in the
// submenu (which would make the overflow parent current on that item's route).
$panel = '<ul class="panel" data-blocks-engine-capture-dialog-panel="data-blocks-engine-capture-dialog-0">'
    . '<li class="sub-item" style="display: none;"><a class="sub-link" href="/">Home</a></li>'
    . '<li class="sub-item" style="display: none;"><a class="sub-link" href="/a/">Route A</a></li>'
    . '<li class="sub-item"><a class="sub-link" href="/c/">Route C</a></li></ul>';
$source = '<!doctype html><html><head><style>.bar{display:flex;gap:24px}.item{list-style:none}.link{color:rgb(20, 20, 20);text-decoration:none}.sub-link{color:rgb(90, 90, 90)}</style>'
    . '<style data-blocks-engine-capture-disclosure="true">[data-blocks-engine-capture-dialog-panel]:not([data-blocks-engine-capture-resting-panel]):not([data-blocks-engine-capture-observed-placement]):not([hidden])>:first-child{display:block!important;visibility:visible!important;opacity:1!important}</style>'
    . '</head><body><header><nav><ul class="bar">'
    . '<li class="item"><a class="link" href="/">Home</a></li><li class="item"><a class="link" href="/a/">Route A</a></li>'
    . '<li class="item"><span class="more-box"><a class="link" href="#" aria-haspopup="menu">More</a></span>' . $panel . '</li>'
    . '</ul></nav></header><main><p>Body.</p></main></body></html>';
$markup = (new HtmlTransformer())->transform($source)->toArray()['serialized_blocks'];

$submenu = strpos($markup, '<!-- wp:navigation-submenu');
if ( false === $submenu ) throw new RuntimeException("The overflow menu converts to a submenu:\n" . $markup);
$children = substr($markup, $submenu, (int) strpos($markup, '<!-- /wp:navigation-submenu', $submenu) - $submenu);
$hidden = static function (string $label) use ($children): bool {
    foreach ( preg_match_all('/<!-- wp:navigation-link (\{.*?\}) \/-->/', $children, $m) ? $m[1] : array() as $json ) {
        $attrs = json_decode($json, true);
        if ( $label !== trim(strip_tags((string) ($attrs['label'] ?? ''))) ) continue;
        $style = (string) ($attrs['metadata']['blocksEngineNavigationAnchor']['itemStyle'] ?? '') . ';' . (string) ($attrs['style']['display'] ?? '') . ';' . json_encode($attrs);
        return 1 === preg_match('/display\s*:\s*none/i', $style);
    }
    throw new RuntimeException("Submenu item {$label} converts:\n" . $children);
};
if ( ! $hidden('Home') ) throw new RuntimeException("The capture replay rule erased the first submenu item's authored display:none:\n" . $children);
if ( ! $hidden('Route A') ) throw new RuntimeException("A hidden submenu item keeps display:none:\n" . $children);
if ( $hidden('Route C') ) throw new RuntimeException("A displayed submenu item stays displayed:\n" . $children);
echo "Capture replay !important keeps authored inline contract passed.\n";
