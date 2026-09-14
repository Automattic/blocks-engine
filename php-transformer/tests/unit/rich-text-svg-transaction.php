<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$result = ( new HtmlTransformer() )->transform(
    '<p>One <svg viewBox="0 0 1 1"><path d="M0 0h1v1z"/></svg> Two <svg><script>alert(1)</script></svg></p>'
)->toArray();

$failures = array();
$label = (new HtmlTransformer())->transform('<div><b><svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><path d="M0 0h16v16z"/></svg> Assistant</b><p>Message</p></div>')->toArray();
$labelMarkup = (string) ($label['serialized_blocks'] ?? '');
if (str_contains($labelMarkup, '<svg') || !str_contains($labelMarkup, '<b><img') || !str_contains($labelMarkup, ' Assistant</b>') || array() === ($label['assets'] ?? array()) || 0 !== ($label['source_reports']['editability_report']['metrics']['structural_rich_text_attribute_count'] ?? -1) || 'pass' !== ($label['source_reports']['wp_block_validity']['status'] ?? '')) {
    $failures[] = 'Standalone formatted SVG labels retain their icon and editable formatting as valid native RichText.';
}
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
