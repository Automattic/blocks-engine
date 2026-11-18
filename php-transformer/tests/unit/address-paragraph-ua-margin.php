<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// <address> has no user-agent block margins. The core/paragraph it becomes
// must not gain the <p> 1em margin on a side the author left unset, while
// author margins (including a `p` type rule, which never matched it) behave
// as in the source.
$html = '<style>p{margin:0 0 1em}.visit address{font-size:1.5rem;margin-bottom:26px}</style>'
    . '<div class="visit"><p class="eyebrow">Dirección</p><address>Tienda orgánica Kuru</address></div>';
$result = (new HtmlTransformer())->transform($html, array())->toArray();
$markup = (string) $result['serialized_blocks'];
$css = stripslashes((string) json_encode($result['assets'] ?? array()));

if ( 1 !== preg_match('/<p class="[^"]*blocks-engine-lowered-paragraph[^"]*">Tienda orgánica Kuru<\/p>/u', $markup) ) {
    throw new RuntimeException('The address paragraph is marked as lowered from a margin-free source box.');
}
if ( ! str_contains($css, ':root :where(p.blocks-engine-lowered-paragraph){margin-block-start:0;margin-block-end:0}') ) {
    throw new RuntimeException('The lowered-paragraph reset ships with the address paragraph.');
}
if ( 1 !== preg_match('/<p class="eyebrow[^"]*">Dirección<\/p>/u', $markup) || str_contains((string) strstr($markup, 'Tienda', true), 'eyebrow blocks-engine-lowered-paragraph') ) {
    throw new RuntimeException('A source <p> keeps its own margins.');
}
if ( 'pass' !== ($result['source_reports']['wp_block_validity']['status'] ?? null) ) {
    throw new RuntimeException('The address paragraph stays editor-valid.');
}
echo "Address paragraphs drop the paragraph UA margin.\n";
