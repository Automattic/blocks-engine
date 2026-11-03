<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$cards = '<div><p>First post</p></div><div><p>Second post</p></div><div><p>Third post</p></div><div><p>Fourth post</p></div>';
$css = '.posts-grid{display:grid;grid-template-columns:repeat(var(--per-row),minmax(0,1fr));--per-row:2;gap:80px;max-width:1040px}'
    . '@media(max-width:1023px){.posts-grid{--per-row:1}}';
$source = '<style>' . $css . '</style><section class="posts-grid">' . $cards . '</section>';
$transform = static fn (string $html): array => (new HtmlTransformer())->transform($html, array())->toArray();
$result = $transform($source);
$markup = $result['serialized_blocks'];
if (!str_contains($markup, 'blocks-engine-css-owned-grid') || str_contains($markup, '"type":"grid"')) {
    throw new RuntimeException('Variable-driven responsive tracks must be CSS-owned: ' . $markup);
}

foreach (array(
    'repeat(2,minmax(0,1fr))',
    'repeat(auto-fill,minmax(240px,1fr))',
) as $tracks) {
    $responsive = $transform('<style>.posts-grid{display:grid;grid-template-columns:' . $tracks . '}'
        . '@media(max-width:1023px){.posts-grid{grid-template-columns:1fr}}</style>'
        . '<section class="posts-grid">' . $cards . '</section>');
    if (str_contains($responsive['serialized_blocks'], '"type":"grid"') || !str_contains($responsive['serialized_blocks'], 'blocks-engine-css-owned-grid')) {
        throw new RuntimeException('Responsive track overrides cannot become an unconditional core grid: ' . $tracks);
    }
}
$literal = $transform('<section style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr))">' . $cards . '</section>');
if (!str_contains($literal['serialized_blocks'], '"columnCount":2')) {
    throw new RuntimeException('Exactly representable literal tracks retain native columnCount.');
}
$inline = $transform('<section style="display:grid;grid-template-columns:repeat(var(--per-row),minmax(0,1fr));--per-row:2">' . $cards . '</section>');
if (str_contains($inline['serialized_blocks'], '"type":"grid"') || !str_contains($inline['serialized_blocks'], 'blocks-engine-css-owned-grid')) {
    throw new RuntimeException('Inline variable tracks must also remain CSS-owned.');
}

if (isset($argv[1])) {
    file_put_contents($argv[1], json_encode(array(
        'source' => $source,
        'markup' => $markup,
        'css' => implode("\n", array_column($result['assets'], 'content')),
    ), JSON_THROW_ON_ERROR));
}
echo "Author grid track ownership passed.\n";
