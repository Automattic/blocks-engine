<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\AssetReferenceCanonicalizer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ValidationException;

$canonicalizer = new AssetReferenceCanonicalizer(array(array(
    'token' => 'asset-0000000000000001',
    'source_path' => 'website/assets/icon.svg',
    'target_path' => 'assets/website/assets/icon.svg',
)), 'website');
$token = '{{wordpress-site-plan:asset:asset-0000000000000001}}';
$markup = '<!-- wp:image --><figure class="wp-block-image"><img src="assets/icon.svg" alt=""/></figure><!-- /wp:image -->'
    . '<!-- wp:html --><svg xml:space="preserve x=" 0"="" y="0" viewBox="0 0 10 10"><path d="' . str_repeat('M1 2 ', 4000) . '"></path></svg><!-- /wp:html -->';
if (str_replace('assets/icon.svg', $token, $markup) !== $canonicalizer->content($markup, 'website/index.html')) {
    throw new RuntimeException('The reduced JIT-stack reproduction must resolve the image reference without altering SVG markup.');
}
$ordinary = '<img src="assets/icon.svg" alt=""><a href="assets/icon.svg#view">icon</a>';
if (str_replace('assets/icon.svg', $token, $ordinary) !== $canonicalizer->content($ordinary, 'website/index.html')) {
    throw new RuntimeException('Ordinary well-formed markup must resolve identically.');
}
// A quote in an attribute name or unquoted value does not open a quoted
// value. Conversely, > and < inside a quoted value do not end the tag.
foreach (array(
    '<div odd"name="value" data-value=odd\'value><img src="assets/icon.svg">',
    '<div data-value="before > < after"><img src="assets/icon.svg">',
    '<div data-value="unterminated > <img src=\'assets/icon.svg\'>',
) as $index => $document) {
    $expected = 2 === $index ? $document : str_replace('assets/icon.svg', $token, $document);
    if ($expected !== $canonicalizer->content($document, 'website/index.html')) {
        throw new RuntimeException('Tag boundaries must follow HTML attribute value states.');
    }
}

$limit = ini_get('pcre.backtrack_limit');
try {
    ini_set('pcre.backtrack_limit', '1');
    try {
        $canonicalizer->content('<img src="assets/icon.svg">', 'website/index.html');
        throw new RuntimeException('A PCRE failure must not silently retain the input.');
    } catch (ValidationException $error) {
        $diagnostic = $error->diagnostic();
        if ('asset_reference_canonicalization_failed' !== $diagnostic['reason'] || 'website/index.html' !== $diagnostic['source_path'] || !str_contains($error->getMessage(), 'Backtrack limit exhausted')) {
            throw new RuntimeException('PCRE failure must report its name, source_path and underlying cause.');
        }
    }
} finally {
    ini_set('pcre.backtrack_limit', (string) $limit);
}

fwrite(STDOUT, "Asset reference canonicalizer PCRE failure contract passed\n");
