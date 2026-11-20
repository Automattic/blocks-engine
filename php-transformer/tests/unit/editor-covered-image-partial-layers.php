<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

// A photo card fills its box with an image, tints it with a full inset layer,
// and floats a centered device frame and a corner badge over it. To keep the
// image selectable, the editor moves the layer covering the image into flow
// after it. The device frame and the badge cover only part of the image: they
// must keep their positioned geometry in the editor canvas, as on the front
// end, instead of falling below the image where the card's overflow clips them.
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};

$html = (string) file_get_contents(dirname(__DIR__) . '/fixtures/editor-covered-image-layers/page.html');
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)));
$blocks = $result->toArray()['serialized_blocks'];
$plan = $result->toWordPressSitePlanView()['wordpress_site_plan'];

$classesOf = static function (string $sourceClass) use ($blocks): array {
    preg_match_all('/class="([^"]*)"/', $blocks, $matches);
    foreach ($matches[1] as $value) {
        $classes = preg_split('/\s+/', trim($value)) ?: array();
        if (in_array($sourceClass, $classes, true)) return $classes;
    }
    return array();
};
$assert(in_array('blocks-engine-covered-fill-image', $classesOf('visual-img'), true), 'The fill image under the tint is marked as covered.');
$assert(in_array('blocks-engine-image-covering-layer', $classesOf('visual-tint'), true), 'The full inset tint is marked as the layer covering the image.');
foreach (array('device', 'badge') as $partial) {
    $assert(array() !== $classesOf($partial), "The $partial layer converts to a block.");
    $assert(!in_array('blocks-engine-image-covering-layer', $classesOf($partial), true), "The $partial layer covers only part of the image and is not a covering layer.");
}

$assets = array_column($plan['assets'], null, 'target_path');
$editor = '';
$frontend = '';
foreach (ThemeBootstrap::documentStylesheets($plan, 'index.html') as $row) {
    $content = (string) ($assets[$row['target_path']]['content'] ?? '');
    if ('frontend' !== $row['stylesheet_target']) $editor .= $content;
    if ('editor' !== $row['stylesheet_target']) $frontend .= $content;
}
// Every editor rule that moves a sibling of the covered image into flow must
// select only the covering layer, never every later sibling block.
preg_match_all('/([^{}]*figure\.blocks-engine-covered-fill-image[^{}]*)\{([^}]*)\}/', $editor, $rules, PREG_SET_ORDER);
$assert(array() !== $rules, 'The editor stylesheet moves the covering layer after the covered image.');
foreach ($rules as $rule) {
    foreach (explode(',', $rule[1]) as $selector) {
        $subject = (string) preg_replace('/^.*[~+>\s]/', '', trim($selector));
        $assert(str_contains($subject, '.blocks-engine-image-covering-layer'), 'The in-flow rule selects only the covering layer: ' . trim($selector));
    }
}
$assert(!str_contains($frontend, 'blocks-engine-covered-fill-image'), 'The front end keeps every layer over the image.');
