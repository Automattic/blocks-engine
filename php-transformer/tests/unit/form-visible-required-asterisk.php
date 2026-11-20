<?php
declare(strict_types=1);

/**
 * A required control whose label ends in a visible, styled asterisk element
 * (no aria-hidden) reports that asterisk as its required marker: the label
 * keeps only its own words, the asterisk travels as `required_text`, and the
 * marker's own presentation reaches the `required_marker` role. An optional
 * control keeps a visible asterisk as label text.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$css = '.field label .req{color:#c0392b}';
$html = '<!doctype html><html><head><style>' . $css . '</style></head><body><main><form action="/c" method="post">'
    . '<div class="field"><label for="n">Name <span class="req">*</span></label><input id="n" name="n" required></div>'
    . '<div class="field"><label for="e">Email<abbr class="req" title="required">*</abbr></label><input id="e" name="e" type="email" aria-required="true"></div>'
    . '<div class="field"><label for="c">Company <span class="req">*</span></label><input id="c" name="c"></div>'
    . '<button type="submit">Send</button></form></main></body></html>';
$result = (new ArtifactCompiler())->compile(array( 'files' => array( array( 'path' => 'index.html', 'kind' => 'html', 'content' => $html ) ) ))->toArray();
$controls = array();
$rows = array();
foreach ( $result['fallbacks'] as $fallback ) {
    if ( isset($fallback['presentation_graph']) ) {
        $controls = $fallback['controls'];
        $rows = array_column($fallback['presentation_graph']['controls'], null, 'index');
        break;
    }
}
$checks = array(
    'required asterisk leaves the label text, keeping the separator' => 'Name ' === ($controls[0]['label'] ?? null),
    'required asterisk becomes the required text' => '*' === ($controls[0]['required_text'] ?? null) && ! isset($controls[0]['required_indicator']),
    'required asterisk keeps its own colour on the marker role' => '#c0392b' === ($rows[0]['required_marker']['styles']['color'] ?? null),
    'aria-required asterisk without a separator is a marker too' => 'Email' === ($controls[1]['label'] ?? null) && '*' === ($controls[1]['required_text'] ?? null),
    'optional control keeps a visible asterisk as label text' => 'Company *' === ($controls[2]['label'] ?? null) && ! isset($controls[2]['required_text']),
);
foreach ( $checks as $message => $ok ) {
    if ( ! $ok ) {
        fwrite(STDERR, 'FAIL: ' . $message . ' ' . json_encode(array_slice($controls, 0, 3)) . PHP_EOL);
    }
}
exit(in_array(false, $checks, true) ? 1 : 0);
