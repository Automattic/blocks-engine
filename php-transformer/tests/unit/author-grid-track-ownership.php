<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$cards = '<div><p>First post</p></div><div><p>Second post</p></div><div><p>Third post</p></div><div><p>Fourth post</p></div>';
$pluginCss = '.bde-loop{--per-row:1;display:grid;grid-template-columns:repeat(var(--per-row),minmax(0,1fr));gap:80px;max-width:1040px}';
$pageCss = '.breakdance .post-list>.bde-loop{--per-row:2}'
    . '@media(max-width:1023px){.breakdance .post-list>.bde-loop{--per-row:1}}';
$source = '<link rel="stylesheet" href="posts.css"><link rel="stylesheet" href="page.css">'
    . '<main class="breakdance"><div class="post-list"><section class="posts-grid bde-loop bde-loop-grid ee-posts-grid">' . $cards . '</section></div></main>';
$transform = static fn (string $html): array => (new HtmlTransformer())->transform($html, array())->toArray();
$result = (new HtmlTransformer())->transform($source, array(
    'author_stylesheet_assets' => array(
        array('path' => 'posts.css', 'content' => $pluginCss),
        array('path' => 'page.css', 'content' => $pageCss),
    ),
))->toArray();
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
// Linked conditional rules must also protect otherwise native literal tracks.
$linkedResponsive = (new HtmlTransformer())->transform($source, array(
    'author_stylesheet_assets' => array(
        array('path' => 'posts.css', 'content' => '.bde-loop{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}'),
        array('path' => 'page.css', 'content' => '@media(max-width:1023px){.breakdance .post-list>.bde-loop{grid-template-columns:1fr}}'),
    ),
))->toArray();
if (str_contains($linkedResponsive['serialized_blocks'], '"type":"grid"')) {
    throw new RuntimeException('Linked responsive tracks must not become unconditional native tracks.');
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
        'linkedStylesheets' => array('posts.css' => $pluginCss, 'page.css' => $pageCss),
        'markup' => $markup,
        'css' => implode("\n", array_column($result['assets'], 'content')),
    ), JSON_THROW_ON_ERROR));
}
echo "Author grid track ownership passed.\n";
