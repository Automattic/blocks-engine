<?php
declare(strict_types=1);

// Exercises box-tree decisions, which are opt-in (#7352).
putenv('BLOCKS_ENGINE_BOX_TREE_INTAKE=1');

// ADR 0005 §4 slice 3b on the filled image frame (#7209). An image that fills
// its grid cell (`.photo img{width:100%}`) lowers to a core/image whose
// figure is display:contents, and its box-tree carrier targets the `img`.
// The carrier must lift the author's `width:100%` when it reproduces every
// observation (720/384/402 against the img's real containing block, the
// `.photo` grid item), not freeze per-viewport pixels: a 402px image keeps
// the 1fr track 402px wide at a 390px viewport, so the page scrolls.
require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;

$assert = static function (bool $ok, string $message, string $context = ''): void {
    if (!$ok) throw new RuntimeException($message . "\n" . $context);
    echo "PASS: $message\n";
};

$styles = static function (string $display, array $overrides = array()): array {
    $used = array_fill_keys(BoxTree::PROPERTIES, 'none');
    foreach (array('padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'border-radius') as $box) $used[$box] = '0px';
    foreach (array('border-top', 'border-right', 'border-bottom', 'border-left') as $border) $used[$border] = '0px none rgb(0, 0, 0)';
    $used = array_merge($used, array(
        'display' => $display, 'position' => 'static', 'box-sizing' => 'content-box', 'overflow-x' => 'visible', 'overflow-y' => 'visible',
        'top' => 'auto', 'right' => 'auto', 'bottom' => 'auto', 'left' => 'auto',
        'background-color' => 'rgba(0, 0, 0, 0)', 'opacity' => '1', 'mix-blend-mode' => 'normal',
        'color' => 'rgb(0, 0, 0)', 'font-family' => 'Arial', 'font-size' => '16px', 'line-height' => '24px',
    ));
    return array_merge($used, $overrides);
};

$viewports = array('desktop' => array('width' => 1440, 'height' => 900), 'tablet' => array('width' => 768, 'height' => 1024), 'mobile' => array('width' => 402, 'height' => 874));
// Per viewport: the grid's tracks, one column's width, the image's height and minimum, and the text column's offset.
$layout = array(
    'desktop' => array('720px 720px', 720, 650, '650px', 720, 0),
    'tablet' => array('384px 384px', 384, 650, '650px', 384, 0),
    'mobile' => array('402px', 402, 420, '0px', 0, 420),
);
$observe = static function (callable $box, callable $used) use ($layout): array {
    $out = array();
    foreach ($layout as $viewport => $facts) $out[$viewport] = array('border_box' => $box(...$facts), 'used_styles' => $used(...$facts));
    return $out;
};
$width = static fn (string $tracks, int $column): float => array_sum(array_map('floatval', explode(' ', $tracks)));

$html = '<!doctype html><html data-blocks-engine-node-id="n1"><head data-blocks-engine-node-id="n2"><title data-blocks-engine-node-id="n3">Menu</title></head>'
    . '<body data-blocks-engine-node-id="n4"><section class="m" data-blocks-engine-node-id="m">'
    . '<div class="photo" data-blocks-engine-node-id="photo"><img src="https://example.com/a.jpg" alt="" data-blocks-engine-node-id="image"></div>'
    . '<div class="t" data-blocks-engine-node-id="t"><p data-blocks-engine-node-id="copy">Copy</p></div></section></body></html>';
$css = '.m{display:grid;grid-template-columns:1fr 1fr}'
    . '.photo img{display:block;width:100%;height:100%;min-height:650px;object-fit:cover}'
    . '@media(max-width:700px){.m{grid-template-columns:1fr}.photo img{min-height:0;height:420px}}';
$html = str_replace('</title>', '</title><style>' . $css . '</style>', $html);

$page = static fn (string $tracks, int $column, int $height, string $min, int $x, int $y): array => array('x' => 0, 'y' => 0, 'width' => $width($tracks, $column), 'height' => $y + $height + 24);
$block = static fn (string $tracks, int $column, int $height, string $min, int $x, int $y): array => $styles('block', array('width' => $width($tracks, $column) . 'px', 'height' => ($y + $height + 24) . 'px'));
$nodes = array(
    'n1' => array('children' => array('n4'), 'viewports' => $observe($page, $block)),
    'n4' => array('children' => array('m'), 'viewports' => $observe($page, $block)),
    'm' => array('children' => array('photo', 't'), 'viewports' => $observe($page, static fn (string $tracks, int $column, int $height, string $min, int $x, int $y): array => $styles('grid', array(
        'width' => $width($tracks, $column) . 'px', 'height' => ($y + $height + 24) . 'px', 'grid-template-columns' => $tracks, 'grid-template-rows' => $y > 0 ? $height . 'px 24px' : $height . 'px',
    )))),
    'photo' => array('children' => array('image'), 'viewports' => $observe(
        static fn (string $tracks, int $column, int $height): array => array('x' => 0, 'y' => 0, 'width' => $column, 'height' => $height),
        static fn (string $tracks, int $column, int $height): array => $styles('block', array('width' => $column . 'px', 'height' => $height . 'px')),
    )),
    'image' => array('children' => array(), 'viewports' => $observe(
        static fn (string $tracks, int $column, int $height): array => array('x' => 0, 'y' => 0, 'width' => $column, 'height' => $height),
        static fn (string $tracks, int $column, int $height, string $min): array => $styles('block', array('width' => $column . 'px', 'height' => $height . 'px', 'min-height' => $min, 'object-fit' => 'cover', 'object-position' => '50% 50%')),
    )),
    't' => array('children' => array('copy'), 'viewports' => $observe(
        static fn (string $tracks, int $column, int $height, string $min, int $x, int $y): array => array('x' => $x, 'y' => $y, 'width' => $column, 'height' => $y > 0 ? 24 : $height),
        static fn (string $tracks, int $column, int $height, string $min, int $x, int $y): array => $styles('block', array('width' => $column . 'px', 'height' => ($y > 0 ? 24 : $height) . 'px')),
    )),
    'copy' => array('children' => array(), 'viewports' => $observe(
        static fn (string $tracks, int $column, int $height, string $min, int $x, int $y): array => array('x' => $x, 'y' => $y, 'width' => $column, 'height' => 24),
        static fn (string $tracks, int $column): array => $styles('block', array('width' => $column . 'px', 'height' => '24px')),
    )),
);
$tree = array('schema' => BoxTree::SCHEMA, 'viewports' => $viewports, 'nodes' => $nodes, 'source_path' => 'index.html', 'source_hash' => hash('sha256', $html));
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray();
$markup = (string) $result['serialized_blocks'];
$sheet = implode("\n", array_column($result['assets'], 'content'));
$context = $markup . "\n" . $sheet;

$assert(str_contains($markup, 'blocks-engine-synthetic-image-figure-fill'), 'The filled image lowers to a display:contents fill figure.', $context);
$assert(1 === preg_match('/<figure class="[^"]*\b(be-inline-geometry-[0-9a-f]+)/', $markup, $carrier), 'The fill figure carries a box-tree geometry class.', $context);
preg_match_all('/@media[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}|[^{}@]*\{[^{}]*\}/', $sheet, $rules);
$imageRules = implode("\n", array_filter($rules[0], static fn (string $rule): bool => str_contains($rule, '.' . $carrier[1]) && 1 === preg_match('/\bimg\s*\{/', $rule)));
$assert('' !== $imageRules, 'The carrier has rules on the image.', $context);
$assert(1 === preg_match('/[{;]width:100%/', $imageRules), 'The image carrier states the author\'s width:100%.', $imageRules);
$assert(0 === preg_match('/[{;]width:(?:720|384|402)px/', $imageRules), 'The image carrier states no per-viewport pixel width.', $imageRules);

if (getenv('BLOCKS_ENGINE_DUMP')) echo $context, "\n";
