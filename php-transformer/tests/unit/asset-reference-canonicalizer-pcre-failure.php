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

$large = '<img src="data:image/png;base64,' . str_repeat('A', 1024 * 1024) . '" src = assets/icon.svg data-note="untouched">';
if (str_replace('assets/icon.svg', $token, $large) !== $canonicalizer->content($large, 'website/index.html')) {
    throw new RuntimeException('A large inline data URI must remain unchanged while a sibling local src resolves.');
}

foreach (array(1, 25000) as $count) {
    $attr = json_encode(array('content' => str_repeat('<div class="cell"><img src="assets/icon.svg"></div>', $count)), JSON_HEX_TAG | JSON_HEX_QUOT);
    $markup = '<!-- wp:group --><div class="wp-block-group"><!-- wp:example/big ' . $attr . ' /--></div><!-- /wp:group -->';
    if (str_replace('assets\\/icon.svg', $token, $markup) !== $canonicalizer->content($markup, 'website/index.html')) {
        throw new RuntimeException('Small and oversized block comments must resolve every JSON attribute reference without changing other bytes.');
    }
}

// Escaped attributes use the same bounded opener / linear closer scan.
foreach (array(
    'src=\\u0022data:image/png;base64,' . str_repeat('A', 1100000) . '\\u0022 src=\\u0022assets/icon.svg\\u0022',
    'style=\\u0022' . str_repeat(' ', 1100000) . 'background:url(assets/icon.svg)\\u0022',
    '\\u003cdiv style="' . str_repeat(' ', 1100000) . 'background:url(assets/icon.svg)"',
) as $document) {
    if (str_replace('assets/icon.svg', $token, $document) !== $canonicalizer->content($document, 'website/index.html')) {
        throw new RuntimeException('Oversized escaped attribute values must resolve without changing other bytes.');
    }
}

$limit = ini_get('pcre.backtrack_limit');
try {
    ini_set('pcre.backtrack_limit', '1');
    try {
        $canonicalizer->content('<style>body { color: red; }</style>', 'website/index.html');
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
