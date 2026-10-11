<?php
declare(strict_types=1);

/**
 * A single style value over the presentation budget (or empty after resolution)
 * must not fail the whole compile. That property is dropped and named; the form
 * and its other style facts stay.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormPresentationGraphBuilder;

$failures = 0;
$passes   = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$oversized = str_repeat('"Some Font Family", ', 120) . 'sans-serif';
$css       = 'input, textarea { font-family: ' . $oversized . '; color: #123456 }';
$html      = '<form method="post">'
    . '<label for="email">Email</label><input id="email" name="email" type="email">'
    . '<label for="message">Message</label><textarea id="message" name="message"></textarea>'
    . '<button type="submit">Send message</button>'
    . '</form>';

try {
    $result = ( new HtmlTransformer() )->transform($html, array( 'static_css' => $css ))->toArray();
} catch ( Throwable $error ) {
    $result = null;
    $assert(false, 'a form with one oversized style value compiles', get_class($error) . ': ' . $error->getMessage());
}

if ( null !== $result ) {
    $fallback = $result['fallbacks'][0] ?? array();
    $graph    = $fallback['presentation_graph'] ?? array();
    $assert('html_form_fallback' === ($fallback['diagnostic_code'] ?? null), 'the form stays provider-materializable', json_encode($fallback['diagnostic_code'] ?? null));
    $families = array();
    $colors   = array();
    foreach ( $graph['controls'] ?? array() as $row ) {
        if ( isset($row['control']['styles']['font_family']) ) $families[] = $row['control']['styles']['font_family'];
        if ( isset($row['control']['styles']['color']) ) $colors[] = $row['control']['styles']['color'];
    }
    $assert(array() === $families, 'the oversized property is dropped', json_encode($families));
    $assert(array() !== $colors, 'sibling style facts are kept', json_encode($graph['controls'] ?? null));
    $assert(in_array('style_value_unrepresentable:font-family', $graph['diagnostics'] ?? array(), true), 'the dropped property is reported', json_encode($graph['diagnostics'] ?? null));
    $assert(array() === array_filter(array($graph), static function (array $graph): bool { try { FormPresentationGraphBuilder::assertValid($graph); return false; } catch ( Throwable ) { return true; } }), 'the emitted graph passes its own validation');
}

// The same form inside a whole-site compile imports and carries the finding.
$page = '<!doctype html><html><head><style>' . $css . '</style></head><body><main><h1>Contact</h1>' . str_replace('<form method="post">', '<form method="post" action="/contact">', $html) . '</main></body></html>';
try {
    $compiled = ( new ArtifactCompiler() )->compile(array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => $page ) ))->toArray();
    $assert('failed' !== ($compiled['status'] ?? null), 'the site compile does not fail', json_encode($compiled['status'] ?? null));
    $assert(str_contains((string) json_encode($compiled), 'style_value_unrepresentable:font-family'), 'the compile result names the dropped property');
} catch ( Throwable $error ) {
    $assert(false, 'a site with one oversized form style value compiles', get_class($error) . ': ' . $error->getMessage());
}

fwrite(STDOUT, sprintf('form-presentation-unrepresentable-style-value: %d passed, %d failed%s', $passes, $failures, PHP_EOL));
exit( $failures > 0 ? 1 : 0 );
