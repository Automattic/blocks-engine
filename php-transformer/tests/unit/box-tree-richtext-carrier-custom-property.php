<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

// #6264: an evidence route retires the author stylesheet, and with it the
// `:root` custom properties. A FAQ question's `<span class="font-heading">`
// lowered to a RichText mark must not carry `font-family:var(--font-heading)`
// inline: the reference is invalid on the route, so the question renders in
// the inherited body font and outranks the box-tree typography carrier.
$html = '<!doctype html><html data-blocks-engine-node-id="n1"><head data-blocks-engine-node-id="n2"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'
    . ':root{--font-heading:"Plus Jakarta Sans",sans-serif;--font-body:"Inter",sans-serif;--primary:155 32% 16%}'
    . 'body{margin:0;font-family:var(--font-body)}button{font-family:inherit;background:none;border:0;padding:0}'
    . '.q{display:flex;width:100%;justify-content:space-between}.font-heading{font-family:var(--font-heading)}.font-bold{font-weight:700}.text-primary{color:hsl(var(--primary))}'
    . '</style></head><body data-blocks-engine-node-id="n4"><main data-blocks-engine-node-id="m"><div data-blocks-engine-node-id="d">'
    . '<button class="q" data-blocks-engine-node-id="b"><span class="font-heading font-bold text-primary" data-blocks-engine-node-id="s">What types of events do you cater for?</span><span data-blocks-engine-node-id="i">+</span></button>'
    . '</div></main></body></html>';
$template = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR)['nodes']['n7']['viewports']['desktop']['used_styles'];
$body = array('font-family' => 'Inter, sans-serif', 'font-weight' => '400', 'color' => 'rgb(0, 0, 0)');
$node = static function (array $children, array $box, array $styles) use ($template, $body): array {
    return array('children' => $children, 'viewports' => array('desktop' => array(
        'border_box' => array_combine(array('x', 'y', 'width', 'height'), $box),
        'used_styles' => array_replace($template, $body, $styles),
    )));
};
$tree = array(
    'schema' => 'blocks-engine/box-tree/v2',
    'source_path' => 'index.html',
    'source_hash' => hash('sha256', $html),
    'viewports' => array('desktop' => array('width' => 1440, 'height' => 900)),
    'nodes' => array(
        'n1' => $node(array('n2', 'n4'), array(0, 0, 1440, 900), array('width' => '1440px', 'height' => '900px')),
        'n2' => $node(array(), array(0, 0, 0, 0), array('display' => 'none')),
        'n4' => $node(array('m'), array(0, 0, 1440, 28), array('width' => '1440px')),
        'm' => $node(array('d'), array(0, 0, 1440, 28), array('width' => '1440px')),
        'd' => $node(array('b'), array(0, 0, 1440, 28), array('width' => '1440px')),
        'b' => $node(array('s', 'i'), array(0, 0, 1440, 28), array('display' => 'flex', 'justify-content' => 'space-between', 'width' => '1440px', 'height' => '28px')),
        's' => $node(array(), array(0, 0, 336, 28), array('display' => 'block', 'font-family' => '"Plus Jakarta Sans", sans-serif', 'font-weight' => '700', 'color' => 'rgb(28, 54, 42)', 'width' => '336px', 'height' => '28px')),
        'i' => $node(array(), array(1430, 0, 10, 28), array('display' => 'block', 'width' => '10px', 'height' => '28px')),
    ),
);

$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))));
$result = $compiled->toArray();
$fail = static function (string $message) use ($result): never {
    fwrite(STDERR, "FAIL: {$message}\n" . $result['serialized_blocks'] . "\n");
    exit(1);
};
$plan = $compiled->toWordPressSitePlanView()['wordpress_site_plan'];
$assets = array_column($plan['assets'], null, 'target_path');
$css = '';
foreach (ThemeBootstrap::documentStylesheets($plan, 'index.html') as $row) $css .= ($assets[$row['target_path']]['content'] ?? '') . "\n";
if (str_contains($css, '--font-heading:')) $fail('the evidence route retires the author custom properties');

if (!preg_match('/<(mark|span)\b[^>]*>What types of events do you cater for\?/', (string) $result['serialized_blocks'], $match)) $fail('the question text survives');
if (!preg_match('/\bstyle="([^"]*)"/', $match[0], $style)) $fail('the question keeps its RichText carrier style');
$style = html_entity_decode($style[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
if (preg_match('/var\(--(?:font-heading|primary)\b/', $style)) $fail('the carrier style reads no author custom property the route does not define: ' . $style);
if (!preg_match('/font-family:\s*"?Plus Jakarta Sans/', $style)) $fail('the question keeps its heading font family: ' . $style);
if (!str_contains(str_replace(' ', '', $style), 'color:hsl(15532%16%)')) $fail('the question keeps its primary colour: ' . $style);

fwrite(STDOUT, "box-tree richtext carrier custom property: passed\n");
