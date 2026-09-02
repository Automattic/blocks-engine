<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$result = ( new HtmlTransformer() )->transform(
    '<p>One <svg viewBox="0 0 1 1"><path d="M0 0h1v1z"/></svg> Two <svg><script>alert(1)</script></svg></p>'
)->toArray();

$failures = array();
if ( 'core/html' !== ($result['blocks'][0]['blockName'] ?? '') ) {
    $failures[] = 'RichText with an unsafe later SVG falls back to core/html.';
}
if ( array() !== ($result['assets'] ?? array()) ) {
    $failures[] = 'A failed later SVG restores assets materialized for earlier RichText SVGs.';
}
if ( ! str_contains((string) ($result['blocks'][0]['attrs']['content'] ?? ''), '<svg') ) {
    $failures[] = 'Fallback content retains the source SVG markup.';
}

if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "RichText SVG transaction contract passed.\n";
