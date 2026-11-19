<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Offset flex icons retain their outer geometry without projecting SVG paint
// or typography onto their RichText image replacements.
$html = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$html = str_replace('<p data-blocks-engine-node-id="n7">Evidence only.</p>',
    '<p data-blocks-engine-node-id="n7"><svg data-blocks-engine-node-id="n8" width="20" height="20" viewBox="0 0 24 24"><path d="M0 0h24v24z"/></svg>Address</p>', $html);
$tree['source_hash'] = hash('sha256', $html);
$tree['nodes']['n7']['children'] = array('n8');
$tree['nodes']['n8'] = $tree['nodes']['n7'];
$tree['nodes']['n8']['children'] = array();
foreach ($tree['nodes']['n7']['viewports'] as &$viewport) {
    $viewport['used_styles'] = array_replace($viewport['used_styles'], array('display' => 'flex', 'align-items' => 'flex-start', 'font-size' => '14px', 'line-height' => '20px'));
}
unset($viewport);
foreach ($tree['nodes']['n8']['viewports'] as &$viewport) {
    $viewport['used_styles'] = array_replace($viewport['used_styles'], array('display' => 'block', 'background-color' => 'rgb(255, 0, 0)', 'line-height' => '37px', 'width' => '20px', 'height' => '20px', 'margin-top' => '2px', 'margin-right' => '0px', 'margin-bottom' => '0px', 'margin-left' => '0px', 'flex-shrink' => '0'));
}
unset($viewport);
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray();
$markup = $result['serialized_blocks'];
$css = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content'));
if (!preg_match('/<img[^>]*class="([^"]+)"/', $markup, $image)) {
    throw new RuntimeException('SVG must remain a native RichText image: ' . $markup);
}
$preserved = false;
foreach (explode(' ', $image[1]) as $class) {
    if (preg_match('/\.' . preg_quote($class, '/') . '\.' . preg_quote($class, '/') . '\{([^}]*)\}/', $css, $rule)
        && (str_contains($rule[1], 'margin-top:2px') || str_contains($rule[1], 'margin:2px 0px 0px')) && str_contains($rule[1], 'flex-shrink:0')) {
        $preserved = true;
        if (preg_match('/(?:^|;)(?:background[^:]*|line-height|display|width|height):/', $rule[1])) {
            throw new RuntimeException('Outer geometry carrier must not replay SVG paint or line box styles: ' . $rule[1]);
        }
    }
}
if (!$preserved) throw new RuntimeException('RichText SVG must carry its observed margin and flex geometry.' . $markup . $css);
echo "RichText SVG box geometry passed\n";
