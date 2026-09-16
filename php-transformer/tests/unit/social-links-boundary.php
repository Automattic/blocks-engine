<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$source = '<div><section><video src="movie.mp4"></video></section><div><a href="https://x.com">X</a><a href="https://facebook.com">Facebook</a></div></div>';
$result = (new HtmlTransformer())->transform($source)->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');

if (! str_contains($markup, '<!-- wp:video')) {
    fwrite(STDERR, "FAIL: a social-links descendant must not consume sibling video content\n");
    exit(1);
}

$wrapped = (new HtmlTransformer())->transform(
    '<style>.social-region{max-width:40rem}.icon-row{display:inline-flex;gap:4px;margin:10px 0}@media(min-width:900px){.icon-row{gap:12px}}</style>'
    . '<div class="social-region"><div class="icon-row"><a href="https://facebook.com/example" aria-label="Facebook"><svg width="24" height="24"></svg></a>'
    . '<a href="https://instagram.com/example" aria-label="Instagram"><svg width="24" height="24"></svg></a></div></div>'
)->toArray();
$wrapper = $wrapped['blocks'][0] ?? array();
$social = $wrapper['innerBlocks'][0] ?? array();
if ('core/group' !== ($wrapper['blockName'] ?? '') || !str_contains((string) ($wrapper['attrs']['className'] ?? ''), 'social-region')) {
    throw new RuntimeException('An outer social region must retain its own presentation boundary instead of consuming the inner icon row.');
}
if ('core/social-links' !== ($social['blockName'] ?? '') || !str_contains((string) ($social['attrs']['className'] ?? ''), 'icon-row')) {
    throw new RuntimeException('The native Social Links block must carry the actual row classes that own responsive gap and margins.');
}
if ('normal' !== ($social['attrs']['size'] ?? '') || 2 !== count($social['innerBlocks'] ?? array())) {
    throw new RuntimeException('Preserving the row must retain native icon sizing and every social link.');
}
if ('pass' !== ($wrapped['source_reports']['wp_block_validity']['status'] ?? '')) {
    throw new RuntimeException('Wrapped native social links must retain valid Gutenberg save markup.');
}

$nestedPlaceholders = (new HtmlTransformer())->transform('<div class="social-region"><div class="icon-row"><a href="#" aria-label="Facebook"><span></span></a><a href="#" aria-label="Instagram"><span></span></a></div></div>')->toArray();
$placeholderRow = $nestedPlaceholders['blocks'][0]['innerBlocks'][0] ?? array();
if ('core/social-links' !== ($placeholderRow['blockName'] ?? '') || array('facebook', 'instagram') !== array_column(array_column($placeholderRow['innerBlocks'] ?? array(), 'attrs'), 'service')) {
    throw new RuntimeException('An explicit outer social region must still identify its nested labeled placeholders as social links.');
}

echo "Social-links boundary tests passed\n";
