<?php
declare(strict_types=1);

// Exercises box-tree decisions, which are opt-in (#7352).
putenv('BLOCKS_ENGINE_BOX_TREE_INTAKE=1');

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

// An evidence route retires the author stylesheet (ADR 0005), and with it the
// author's custom-property definitions. Squarespace paints the body through
// scheme variables redefined per section (`.dark{--bg:…}`), so they do not
// resolve to one root value. Global Styles must not keep `var(--bg)` "live":
// nothing defines it on the route, the body background computes transparent
// and the text black. The rendered body paint becomes the preset instead.
$html = '<!doctype html><html data-blocks-engine-node-id="n1"><head data-blocks-engine-node-id="n2"><style>'
    . ':root{--bg:#ece4da;--fg:#36302a;--large:1.2}.dark{--bg:#111111;--fg:#ffffff}'
    . 'body{margin:0;background-color:var(--bg);color:var(--fg);font-family:Georgia,serif}'
    . '.large{font-size:calc(var(--large) * 1rem)}'
    . '@media (min-width:768px){.large{font-size:calc((var(--large) - 1) * 1.2vw + 1rem)}}'
    . '</style></head><body data-blocks-engine-node-id="n4"><main data-blocks-engine-node-id="m">'
    . '<p class="large" data-blocks-engine-node-id="p">Hair Color</p>'
    . '<section class="dark" data-blocks-engine-node-id="d"><p data-blocks-engine-node-id="q">Dark band</p></section>'
    . '</main></body></html>';
$template = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR)['nodes']['n7']['viewports']['desktop']['used_styles'];
$base = array('font-family' => 'Georgia, serif', 'color' => 'rgb(54, 48, 42)');
$node = static function (array $children, array $box, array $styles) use ($template, $base): array {
    return array('children' => $children, 'viewports' => array('desktop' => array(
        'border_box' => array_combine(array('x', 'y', 'width', 'height'), $box),
        'used_styles' => array_replace($template, $base, $styles),
    )));
};
$tree = array(
    'schema' => 'blocks-engine/box-tree/v2',
    'source_path' => 'index.html',
    'source_hash' => hash('sha256', $html),
    'viewports' => array('desktop' => array('width' => 1440, 'height' => 900)),
    'nodes' => array(
        'n1' => $node(array('n2', 'n4'), array(0, 0, 1440, 900), array('width' => '1440px', 'height' => '900px', 'color' => 'rgb(0, 0, 0)', 'font-family' => 'Times')),
        'n2' => $node(array(), array(0, 0, 0, 0), array('display' => 'none')),
        'n4' => $node(array('m'), array(0, 0, 1440, 120), array('width' => '1440px', 'background-color' => 'rgb(236, 228, 218)')),
        'm' => $node(array('p', 'd'), array(0, 0, 1440, 120), array('width' => '1440px')),
        'p' => $node(array(), array(0, 0, 1440, 29), array('width' => '1440px', 'height' => '29px', 'font-size' => '19.456px')),
        'd' => $node(array('q'), array(0, 29, 1440, 60), array('width' => '1440px', 'color' => 'rgb(255, 255, 255)', 'background-color' => 'rgb(17, 17, 17)')),
        'q' => $node(array(), array(0, 29, 1440, 24), array('width' => '1440px', 'height' => '24px', 'color' => 'rgb(255, 255, 255)')),
    ),
);
$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))));
$plan = $compiled->toWordPressSitePlanView()['wordpress_site_plan'];
$fail = static function (string $message, string $detail): never {
    fwrite(STDERR, "FAIL: {$message}\n{$detail}\n");
    exit(1);
};
if (empty($plan['pages'][0]['document_metadata']['engine_owned_styles'])) $fail('the route is engine-owned', '');
$theme = array();
foreach ($plan['writes'] as $write) if ('theme.json' === $write['target_path']) $theme = json_decode((string) $write['payload']['data'], true);
$styles = json_encode($theme['styles'] ?? array(), JSON_UNESCAPED_SLASHES);
if (preg_match('/var\\(--(?!wp--)[A-Za-z0-9_-]+/', $styles, $leak)) $fail('Global Styles reference no author custom property the route does not define: ' . $leak[0], $styles);
$palette = array_column($theme['settings']['color']['palette'] ?? array(), 'color', 'slug');
$resolve = static function (?string $value) use ($palette): string {
    if (is_string($value) && preg_match('/^var:preset\\|color\\|(.+)$/', $value, $m)) return strtolower((string) ($palette[$m[1]] ?? ''));
    return strtolower((string) $value);
};
if ('#ece4da' !== $resolve($theme['styles']['color']['background'] ?? null)) $fail('the body background is the rendered #ece4da', $styles);
if ('#36302a' !== $resolve($theme['styles']['color']['text'] ?? null)) $fail('the body text is the rendered #36302a', $styles);
echo "PASS: evidence-route Global Styles carry the rendered body paint\n";
