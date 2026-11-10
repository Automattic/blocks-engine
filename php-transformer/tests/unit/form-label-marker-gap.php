<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$css = 'label{font-size:18px}.cap{display:flex;column-gap:1ex}.description{font-size:12px}.ck{display:inline-flex;align-items:center;column-gap:6px;font-size:13px}';
$html = '<!doctype html><html><head><style>' . $css . '</style></head><body><main><form action="/c" method="post">'
    . '<div><label for="e"><div class="cap"><span>Email Address</span><span class="description required">(required)</span></div></label><input id="e" name="e" type="email" required></div>'
    . '<div><label class="ck"><input type="checkbox" name="news" value="1"><span>Sign up for news</span></label></div>'
    . '<div><label for="n">Name<span aria-hidden="true">*</span></label><input id="n" name="n"></div>'
    . '<button type="submit">Send</button></form></main></body></html>';
$result = (new ArtifactCompiler())->compile(array( 'files' => array( array( 'path' => 'index.html', 'kind' => 'html', 'content' => $html ) ) ))->toArray();
$rows = array();
foreach ( $result['fallbacks'] as $fallback ) {
    if ( isset($fallback['presentation_graph']) ) {
        $rows = array_column($fallback['presentation_graph']['controls'], null, 'index');
        break;
    }
}
$marker = $rows[0]['required_marker']['styles'] ?? array();
$choice = $rows[1]['label']['styles'] ?? array();
$checks = array(
    'text marker retains authored typography' => '12px' === ($marker['font_size'] ?? null),
    // Keep ex tied to the 18px caption container, not the 12px marker.
    'marker gap retains its source font context' => 'calc(1ex * 1.5)' === ($marker['margin_left'] ?? null),
    'wrapping choice label retains column gap' => 'normal 6px' === ($choice['gap'] ?? null),
    'decorative star still has a marker role' => isset($rows[2]['required_marker']),
);
foreach ( $checks as $message => $ok ) {
    if ( ! $ok ) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    }
}
exit(in_array(false, $checks, true) ? 1 : 0);
