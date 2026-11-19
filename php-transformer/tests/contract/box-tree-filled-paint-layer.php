<?php
declare(strict_types=1);

// Site builders paint a section's background with an unpainted layer that
// covers the section and one empty child that fills the layer with a colour:
// `<div class="bg-layer"><div id="…" class="bg"></div></div>`. When the
// capture proves the fill covers the layer exactly and paints only a solid
// colour, the layer is that coloured box: one Group, not a Group around an
// empty Group. A fill the capture shows inset keeps both boxes, and a fill
// painting an image stays its own box.
require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};

$styles = static function (string $display, string $position, array $overrides = array()): array {
    $used = array_fill_keys(BoxTree::PROPERTIES, 'none');
    foreach (array('padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'border-radius') as $box) $used[$box] = '0px';
    foreach (array('border-top', 'border-right', 'border-bottom', 'border-left') as $border) $used[$border] = '0px none rgb(0, 0, 0)';
    $used = array_merge($used, array(
        'display' => $display, 'position' => $position, 'overflow-x' => 'visible', 'overflow-y' => 'visible',
        'top' => 'auto', 'right' => 'auto', 'bottom' => 'auto', 'left' => 'auto', 'z-index' => 'auto', 'visibility' => 'visible',
        'outline' => '0px none rgb(0, 0, 0)', 'transform' => 'none', 'filter' => 'none', 'box-shadow' => 'none',
        'background-color' => 'rgba(0, 0, 0, 0)', 'background-image' => 'none', 'opacity' => '1', 'mix-blend-mode' => 'normal',
        'color' => 'rgb(0, 0, 0)', 'font-family' => 'Arial', 'font-size' => '16px', 'line-height' => '24px',
    ));
    return array_merge($used, $overrides);
};

$compile = static function (int $fillInset = 0, string $fillImage = 'none') use ($styles): string {
    $html = '<!doctype html><html data-blocks-engine-node-id="n1"><head data-blocks-engine-node-id="n2"><title data-blocks-engine-node-id="n3">Studio</title></head>'
        . '<body data-blocks-engine-node-id="n4"><main data-blocks-engine-node-id="n5"><section class="band" data-blocks-engine-node-id="band">'
        . '<div class="bg-layer" data-blocks-engine-node-id="layer"><div id="band-bg" class="bg" data-motion-part="BG_LAYER band-bg" data-blocks-engine-node-id="fill"></div></div>'
        . '<div class="content" data-blocks-engine-node-id="content"><h2 data-blocks-engine-node-id="heading">Brand strategy</h2><p data-blocks-engine-node-id="copy">We help studios find their voice.</p></div>'
        . '</section></main></body></html>';
    $observe = static fn (array $box, array $used, array $extra = array()): array => array(
        'desktop' => array('border_box' => $box, 'used_styles' => $used) + $extra,
        'mobile' => array('border_box' => $box, 'used_styles' => $used) + $extra,
    );
    $band = array('x' => 0, 'y' => 0, 'width' => 1200, 'height' => 400);
    $inset = array('x' => $fillInset, 'y' => $fillInset, 'width' => 1200 - 2 * $fillInset, 'height' => 400 - 2 * $fillInset);
    $neutralBefore = array('pseudo' => array('before' => array('content' => '" "', 'border_box' => $inset, 'used_styles' => $styles('block', 'absolute'))));
    $nodes = array(
        'n1' => array('children' => array('n4'), 'viewports' => $observe($band, $styles('block', 'static'))),
        'n4' => array('children' => array('n5'), 'viewports' => $observe($band, $styles('block', 'static'))),
        'n5' => array('children' => array('band'), 'viewports' => $observe($band, $styles('block', 'static'))),
        'band' => array('children' => array('layer', 'content'), 'viewports' => $observe($band, $styles('grid', 'relative'))),
        'layer' => array('children' => array('fill'), 'viewports' => $observe($band, $styles('grid', 'absolute', array('top' => '0px', 'right' => '0px', 'bottom' => '0px', 'left' => '0px')))),
        'fill' => array('children' => array(), 'viewports' => $observe($inset, $styles('block', 'relative', array('background-color' => 'color(srgb 1 0.5 0)', 'background-image' => $fillImage)), $neutralBefore)),
        'content' => array('children' => array('heading', 'copy'), 'viewports' => $observe(array('x' => 40, 'y' => 40, 'width' => 1120, 'height' => 120), $styles('block', 'relative'))),
        'heading' => array('children' => array(), 'viewports' => $observe(array('x' => 40, 'y' => 40, 'width' => 1120, 'height' => 48), $styles('block', 'static'))),
        'copy' => array('children' => array(), 'viewports' => $observe(array('x' => 40, 'y' => 100, 'width' => 1120, 'height' => 24), $styles('block', 'static'))),
    );
    $tree = array('schema' => BoxTree::SCHEMA, 'viewports' => array('desktop' => array('width' => 1440, 'height' => 900), 'mobile' => array('width' => 390, 'height' => 844)), 'nodes' => $nodes, 'source_path' => 'index.html');
    $css = '.band{display:grid;position:relative;min-height:400px}.bg-layer{display:grid;position:absolute;inset:0;overflow:clip}'
        . '.bg{position:relative;width:100%;height:100%;background-color:#ff8000' . ('none' === $fillImage ? '' : ';background-image:' . $fillImage) . '}.bg:before{content:" ";position:absolute;inset:0}'
        . '.content{position:relative;padding:40px}';
    $html = str_replace('</title>', '</title><style>' . $css . '</style>', $html);
    $tree['source_hash'] = hash('sha256', $html);
    return (string) (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray()['serialized_blocks'];
};

$groups = static fn (string $markup): int => preg_match_all('/<!-- wp:group /', $markup);

$merged = $compile();
$assert(!str_contains($merged, 'band-bg') && 0 === preg_match('/"className":"bg[ "]/', $merged), 'The empty colour fill is gone as its own Group.');
$assert(1 === preg_match('/<!-- wp:group \{[^\n]*?"className":"[^"]*\bbg-layer\b[^\n]*?--><div[^>]*(?:rgb\(255, 128, 0\)|#ff8000|has-[a-z0-9-]+-background-color)[^>]*><\/div>/i', $merged), 'The layer paints the fill\'s colour as an empty Group.');

$inset = $compile(12);
$assert($groups($inset) === $groups($merged) + 1 && str_contains($inset, 'band-bg'), 'A fill inset within its layer keeps both boxes.');

$image = $compile(0, 'url(https://example.com/texture.png)');
$assert(str_contains($image, 'band-bg'), 'A fill that paints an image stays its own box.');
