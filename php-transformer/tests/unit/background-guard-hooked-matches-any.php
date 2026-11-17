<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A lowered background image is a figure and img carrying only engine
// classes. Author arms whose subject already requires a class or a non-image
// type, including through :where()/:is() lists of compounds, cannot reach
// them, so repeating the background guard on every such arm only adds weight.
$source = '<!doctype html><html><head><style>'
    . ':where(.card.featured){opacity:.8}'
    . ':is(em,strong){letter-spacing:1px}'
    . '.wrap :is(img,.photo){outline:1px solid red}'
    . '.bg{width:300px;height:200px;background-size:cover}'
    . '</style></head><body><main><div class="wrap"><div class="bg" style="background-image:url(x.jpg)"></div>'
    . '<div class="card featured"><em>Card</em></div><img src="x.jpg" alt="Real"></div></main></body></html>';
$result = (new ArtifactCompiler())->compile(array('files' => array(
    array('path' => 'index.html', 'kind' => 'html', 'content' => $source),
    array('path' => 'x.jpg', 'kind' => 'image', 'content' => base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSFw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==')),
)))->toArray();
$css = '';
foreach ($result['assets'] as $asset) if ('css' === ($asset['kind'] ?? null)) $css .= $asset['content'];
if (!str_contains($result['serialized_blocks'], 'blocks-engine-background-image')) throw new RuntimeException('Fixture must lower a background image');
$guard = ':where(:not(.blocks-engine-background-image,.blocks-engine-background-image *))';
foreach (array(':where(.card.featured){opacity:.8}', ':is(em,strong){letter-spacing:1px}') as $rule) {
    if (!str_contains($css, $rule)) throw new RuntimeException('Hooked matches-any subject must not repeat the background guard: ' . $rule . "\n" . $css);
}
if (!str_contains($css, '.wrap :is(img,.photo)' . $guard . '{outline:1px solid red}')) {
    throw new RuntimeException('A matches-any list that can still select an img keeps the background guard');
}
echo "Background guard skips subjects hooked through matches-any lists\n";
