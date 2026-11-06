<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$css = static function (array $result): string {
    return implode("\n", array_map(
        static fn(array $asset): string => (string) ($asset['content'] ?? ''),
        array_filter($result['assets'] ?? array(), static fn(array $asset): bool => 'text/css' === ($asset['mime_type'] ?? ''))
    ));
};

$source = file_get_contents(dirname(__DIR__) . '/fixtures/social-links-native-svg-artwork.html');
$result = (new HtmlTransformer())->transform($source)->toArray();
$blocks = $result['serialized_blocks'];
$styles = $css($result);

// The owner gets one Social Icons block, not a paragraph of linked images.
$assert(str_contains($blocks, 'wp:social-links'), 'A single-colour icon row must become core/social-links.');
$assert(2 === substr_count($blocks, 'wp:social-link '), 'Every icon must be one core/social-link.');
$assert(str_contains($blocks, '"service":"facebook"') && str_contains($blocks, '"service":"instagram"'), 'Services come from the link URLs.');
$assert(str_contains($blocks, 'https://www.facebook.com/example'), 'Link targets survive.');
$assert(str_contains($blocks, '"label":"Facebook"'), 'Accessible names survive.');
$assert(!preg_match('/<img|<svg|wp:paragraph[^>]*-->\s*<p[^>]*><a /', $blocks), 'No inline image or SVG links remain.');
$assert(!str_contains($blocks, 'wp:html'), 'No Custom HTML.');
$assert('pass' === $result['source_reports']['wp_block_validity']['status'], 'The blocks stay WordPress-valid.');

// Icon colour stays a block setting and drives the source artwork.
$assert(str_contains($blocks, '"iconColorValue":"#f7f7f7"'), 'Captured paint is the block icon colour.');
$assert(1 === preg_match('/:is\(\.[-a-z0-9]+>a,button\.[-a-z0-9]+\)>svg\{[^}]*background-color:currentColor/', $styles), 'Artwork follows the icon colour on the frontend and in the editor.');
$assert(1 === preg_match('/source-item-[0-9a-f]+>a\{color:inherit!important\}/', $styles), 'A link colour the source glyph overrode must not replace the icon colour.');

$assert(0 === preg_match('/source-item-[0-9a-f]+>a>svg\)[^{]*\{[^}]*(?<![-a-z])color:/', $styles), 'A copied source colour must not outrank the Icon Color setting.');

// The source artwork survives, with its filled shapes intact.
$assert(str_contains($styles, 'data:image/svg+xml'), 'Source artwork is carried as a mask.');
$assert(str_contains(rawurldecode($styles), 'M2 2h20v20H2zM8 8h8v8H8z'), 'Facebook geometry is identical.');
$assert(str_contains(rawurldecode($styles), 'fill-rule="evenodd"'), 'Fill rule survives.');
$assert(str_contains(rawurldecode($styles), 'r="10"'), 'Circle artwork survives.');
$assert(!str_contains(rawurldecode($styles), 'data-blocks-engine-capture'), 'Capture evidence must not leak.');

// width="40px" means 40 pixels, and choosing a preset size must still work.
$assert(1 === preg_match('/:not\(\.has-small-icon-size,\.has-normal-icon-size,\.has-large-icon-size,\.has-huge-icon-size\)[^{]*\{[^}]*width:40px[^}]*height:40px/', $styles), 'Source size applies until the owner picks a preset.');
$assert(!str_contains($blocks, '"size"'), 'No preset is forced over the source size.');

// Paint or shapes the mask cannot express keep the source-artwork group.
$row = static fn(string $svg, string $anchorStyle = ''): string => '<div class="social"><a href="https://facebook.com/x" aria-label="Facebook"' . $anchorStyle . '>' . $svg . '</a></div>';
$captured = 'data-blocks-engine-capture-glyph-color="#ffffff"';
$declined = array(
    'two paints' => $row('<svg viewBox="0 0 24 24" ' . $captured . '><path fill="#ff0000" d="M0 0h9v9z"/><path fill="#00ff00" d="M9 9h9v9z"/></svg>'),
    'stroke' => $row('<svg viewBox="0 0 24 24" ' . $captured . '><path stroke="#fff" fill="none" d="M0 0h9v9z"/></svg>'),
    'gradient' => $row('<svg viewBox="0 0 24 24" ' . $captured . '><linearGradient id="g"/><path fill="url(#g)" d="M0 0h9v9z"/></svg>'),
    'image' => $row('<svg viewBox="0 0 24 24" ' . $captured . '><image href="x.png"/></svg>'),
    'painted tile' => $row('<svg viewBox="0 0 24 24" ' . $captured . '><path d="M0 0h9v9z"/></svg>', ' style="background:#0b84c5;padding:8px"'),
    'visible text' => $row('<svg viewBox="0 0 24 24" ' . $captured . '><path d="M0 0h9v9z"/></svg>Follow'),
    'no capture evidence' => $row('<svg viewBox="0 0 24 24"><path d="M0 0h9v9z"/></svg>'),
);
foreach ($declined as $name => $html) {
    $declinedBlocks = (new HtmlTransformer())->transform($html)->toArray()['serialized_blocks'];
    $assert(!str_contains($declinedBlocks, 'wp:social-link '), 'Must keep source artwork group: ' . $name);
    $assert(str_contains($declinedBlocks, 'https://facebook.com/x'), 'Link survives: ' . $name);
}

// A fill="none" root with filled shapes is still visible artwork.
$noneRoot = $css((new HtmlTransformer())->transform($row('<svg viewBox="0 0 24 24" fill="none" ' . $captured . '><path fill="#ffffff" d="M0 0h9v9z"/><path style="fill:#ffffff" d="M9 9h9v9z"/></svg>'))->toArray());
$assert(str_contains(rawurldecode($noneRoot), 'd="M0 0h9v9z" fill="#000"') && str_contains(rawurldecode($noneRoot), 'd="M9 9h9v9z" fill="#000"'), 'Shape fills must survive a fill="none" root, or the mask paints nothing.');

// Unsafe source markup never reaches the stylesheet.
$unsafe = (new HtmlTransformer())->transform($row('<svg viewBox="0 0 24 24" onload="alert(1)" ' . $captured . '><path onclick="alert(1)" d="M0 0h9v9z"/></svg>'))->toArray();
$assert(!preg_match('/onload|onclick|alert/i', rawurldecode($css($unsafe))), 'Event attributes must not be carried into the mask.');

if (in_array('--json', $argv, true)) {
    echo json_encode(array('source' => $source, 'result' => $result), JSON_THROW_ON_ERROR);
    exit;
}
echo "Native social SVG artwork passed.\n";
