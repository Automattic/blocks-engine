<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

// Issue #5920: a captured `<img>` with no source figure keeps its own layout
// box on the front end because the box-tree carrier makes core/image's
// synthetic figure `display:contents`. That figure is the block root Gutenberg
// focuses to select the image, and a boxless element cannot take focus: a
// click on the image selected the enclosing group, so the image block never
// showed its Replace control. The editor must give that figure a box, and the
// front end must keep the source's boxless figure.
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};

$html = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$html = str_replace('</main>', '<img data-blocks-engine-node-id="n8" class="product-shot" src="https://example.com/wheel.jpg" alt="Forged wheel"></main>', $html);
$tree['source_hash'] = hash('sha256', $html);
$tree['nodes']['n5']['children'][] = 'n8';
$image = $tree['nodes']['n7']['viewports'];
foreach ($image as &$viewport) {
    $viewport['used_styles'] = array_replace($viewport['used_styles'], array('display' => 'block', 'width' => '320px', 'height' => '320px', 'object-fit' => 'cover'));
    $viewport['border_box'] = array('x' => 0, 'y' => 20, 'width' => 320, 'height' => 320);
}
unset($viewport);
$tree['nodes']['n8'] = array('children' => array(), 'viewports' => $image);

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $html,
    'box-tree/index.json' => json_encode($tree),
)));
$compiled = $result->toArray();
$plan = $result->toWordPressSitePlanView()['wordpress_site_plan'];

$assert(1 === preg_match('/<figure class="([^"]*\bblocks-engine-synthetic-image-figure\b[^"]*)"/', $compiled['serialized_blocks'], $figure), 'The source image is a native image block in a synthetic figure.');
$assets = array_column($plan['assets'], null, 'target_path');
$editor = '';
$frontend = '';
foreach (ThemeBootstrap::documentStylesheets($plan, 'index.html') as $row) {
    $content = (string) ($assets[$row['target_path']]['content'] ?? '');
    if ('frontend' !== $row['stylesheet_target']) $editor .= $content;
    if ('editor' !== $row['stylesheet_target']) $frontend .= $content;
}

$boxless = array_values(array_filter(preg_split('/\s+/', $figure[1]) ?: array(), static fn(string $class): bool => str_contains($frontend, '.' . $class . '.' . $class . '{display:contents}')));
$assert(1 === count($boxless), 'The front end keeps the source structure: the figure carrier is display:contents.');
$carrier = $boxless[0];

$editorBox = false;
foreach (explode('}', $editor) as $rule) {
    [$selector, $body] = array_pad(explode('{', $rule, 2), 2, '');
    if (str_contains($selector, '.editor-styles-wrapper') && str_contains($selector, 'figure.wp-block-image') && str_contains($selector, '.' . $carrier) && str_contains($body, 'display:block')) {
        $editorBox = true;
    }
}
$assert($editorBox, 'The editor gives the boxless figure a block box, so Gutenberg can focus and select the image.');
$assert(!str_contains($frontend, '.editor-styles-wrapper figure.wp-block-image:is(.' . $carrier), 'The editor box never reaches the front end.');
