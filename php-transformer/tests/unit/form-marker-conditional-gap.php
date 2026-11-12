<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Exercise the public compiler: capture can put all imported rules in media.
// `.f .description{margin-left:2%}` loses to the more specific `margin:0` reset, as Squarespace forms author it.
$rules = 'label{font-size:18px}.cap{display:flex;column-gap:1ex}.f .description{margin-left:2%}form .f .description{display:block;font-size:12px;margin:0}.ck{display:inline-flex;column-gap:6px}';
$failed = false;
foreach ( array( 'plain' => $rules, 'media' => '@media (min-width:0px){' . $rules . '}', 'grid' => str_replace('display:flex', 'display:grid', $rules) ) as $case => $css ) {
    $html = '<!doctype html><html><head><style>' . $css . '</style></head><body><main><form action="/c" method="post">'
        . '<div class="f"><label for="e"><div class="cap"><span>Email</span><span class="description required">(required)</span></div></label><input id="e" name="e" type="email" required></div>'
        . '<div class="f"><label for="n"><div class="cap">Name<span class="description required">(required)</span></div></label><input id="n" name="n" required></div>'
        . '<div><label class="ck"><input type="checkbox" name="news"><span>News</span></label></div>'
        . '<button type="submit">Send</button></form></main></body></html>';
    $result = (new ArtifactCompiler())->compile(array( 'files' => array( array( 'path' => 'index.html', 'kind' => 'html', 'content' => $html ) ) ))->toArray();
    $graph = array();
    foreach ( $result['fallbacks'] as $fallback ) {
        if ( isset($fallback['presentation_graph']) ) {
            $graph = $fallback['presentation_graph'];
            break;
        }
    }
    $rows = array_column($graph['controls'] ?? array(), null, 'index');
    $marker = $rows[0]['required_marker']['styles'] ?? array();
    $textMarker = $rows[1]['required_marker']['styles'] ?? array();
    $label = $rows[2]['label']['styles'] ?? array();
    $condition = array( 'kind' => 'media', 'query' => '(min-width:0px)' );
    foreach ( $graph['variants'] ?? array() as $variant ) {
        if ( 'media' !== $case || $variant['condition'] !== $condition ) continue;
        if ( 0 === $variant['index'] && 'required_marker' === $variant['role'] ) $marker = array_merge($marker, $variant['style_patch']);
        if ( 1 === $variant['index'] && 'required_marker' === $variant['role'] ) $textMarker = array_merge($textMarker, $variant['style_patch']);
        if ( 2 === $variant['index'] && 'label' === $variant['role'] ) $label = array_merge($label, $variant['style_patch']);
    }
    $checks = array(
        'marker retains typography' => '12px' === ($marker['font_size'] ?? null),
        'flattened marker is inline' => ! in_array($marker['display'] ?? '', array( 'block', 'flex', 'grid', 'table', 'list-item' ), true),
        'choice retains column gap' => 'normal 6px' === ($label['gap'] ?? null),
    );
    if ( 'grid' !== $case ) {
        $checks['marker gap uses caption font in the same condition'] = 'calc(1ex * 1.5)' === ($marker['margin_left'] ?? null);
        $checks['bare caption text keeps the gap, without the overridden 2%'] = 'calc(1ex * 1.5)' === ($textMarker['margin_left'] ?? null);
    }
    foreach ( $checks as $message => $ok ) {
        if ( ! $ok ) {
            fwrite(STDERR, "FAIL [$case]: $message\n");
            $failed = true;
        }
    }
}
exit($failed ? 1 : 0);
