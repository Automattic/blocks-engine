<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A captured form's plain action publishes as its native button with the
// author classes kept. When a class rule paints the button's text, that colour
// must be owned by the editable Core label's colour support, so a colour set
// on a containing block is not silently overridden by the retained class
// (#7227). An inline source colour stays on the native box unchanged.
$html = '<html><head><link rel="stylesheet" href="s.css"></head><body><main><section class="wrap">'
    . '<p class="muted">Try an example</p>'
    . '<form><input name="q" data-blocks-engine-capture-native-control-state=\'{"version":1,"kind":"input","type":"text","value":"","defaultValue":""}\'>'
    . '<button type="button" class="action muted">Muted example</button>'
    . '<button type="button" class="action" style="color:#123456">Inline example</button>'
    . '<button type="button" class="action plain">Context example</button>'
    . '<button type="button" class="action narrow">Narrow example</button></form>'
    . '</section></main></body></html>';
$css = ':root{--muted:rgb(157 151 144)}body{color:#2a2a28}.muted{color:var(--muted)}.action{padding:8px;border:1px solid #ccc;text-align:left}'
    . '.wrap .plain{color:#654321}.narrow{color:#224466}@media (max-width:600px){.narrow{color:#884422}}';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    array('path' => 'index.html', 'content' => $html),
    array('path' => 's.css', 'content' => $css),
)))->toArray();
$markup = implode('', array_column($result['source_reports']['wordpress_site_plan']['pages'], 'canonical_block_markup'));

$labels = array();
preg_match_all('/<!-- wp:button (\{.*?\}) -->\s*<div class="wp-block-button"><button\b([^>]*)>([^<]*)<\/button>/', $markup, $matches, PREG_SET_ORDER);
foreach ($matches as $match) {
    $labels[$match[3]] = array(json_decode($match[1], true, 512, JSON_THROW_ON_ERROR), $match[2]);
}
[$attrs, $tag] = $labels['Muted example'] ?? throw new RuntimeException('Missing the muted control label: ' . $markup);
if ((!isset($attrs['textColor']) && !isset($attrs['style']['color']['text'])) || !str_contains($tag, 'has-text-color')) {
    throw new RuntimeException('A class-painted control label must own its colour natively: ' . $markup);
}
if (!str_contains($markup, '"blocksEngineControlBox":{"type":"button","class":"action muted"}')) {
    throw new RuntimeException('The native control box must keep its author classes: ' . $markup);
}
[$attrs] = $labels['Inline example'] ?? throw new RuntimeException('Missing the inline control label: ' . $markup);
if (isset($attrs['textColor']) || isset($attrs['style']['color'])) {
    throw new RuntimeException('An inline source colour stays on the native box, not the label: ' . $markup);
}
// Colour a class recipe does not own alone (a contextual selector, or a
// breakpoint) keeps its stylesheet ownership rather than freezing inline.
foreach (array('Context example', 'Narrow example') as $text) {
    [$attrs] = $labels[$text] ?? throw new RuntimeException('Missing the ' . $text . ' label: ' . $markup);
    if (isset($attrs['textColor']) || isset($attrs['style']['color'])) {
        throw new RuntimeException($text . ' must keep its stylesheet colour ownership: ' . $markup);
    }
}
echo "PASS: class-painted control labels own their text colour natively\n";
