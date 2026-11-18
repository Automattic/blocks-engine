<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A preflight variable reset (`*,:before,:after{--tw-...}`) matches every
// emitted element exactly as it matched every source element, at zero
// specificity, and paints nothing. Projecting it per subject repeated every
// lowered subject's arm (images, captions, buttons, rich-text markers, each
// with the background guard) for each pseudo-element, which dominated the
// theme stylesheet's weight. It must ship as its authored selector list.
$source = '<!doctype html><html><head><style>'
    . '*,:before,:after{--tw-translate-x:0;--tw-ring-color:rgb(59 130 246 / .5)}'
    . '::backdrop{--tw-translate-x:0}'
    . ':before,:after{--tw-content:""}'
    . '*,:before,:after{box-sizing:border-box;border-width:0}'
    . '.bg{width:300px;height:200px;background-size:cover}'
    . '.translate-x-2{--tw-translate-x:.5rem}'
    . '.lead{transform:translate(var(--tw-translate-x),0);font-size:20px}'
    . '</style></head><body><main><div class="bg" style="background-image:url(x.jpg)"></div>'
    . '<p class="lead translate-x-2">Lead <mark>copy</mark></p>'
    . '<figure><img src="x.jpg" alt="Real"><figcaption>Caption</figcaption></figure>'
    . '<button type="button" class="lead">Book</button></main></body></html>';
$result = (new ArtifactCompiler())->compile(array('files' => array(
    array('path' => 'index.html', 'kind' => 'html', 'content' => $source),
    array('path' => 'x.jpg', 'kind' => 'image', 'content' => base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSFw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==')),
)))->toArray();
$css = '';
foreach ($result['assets'] as $asset) if ('css' === ($asset['kind'] ?? null)) $css .= $asset['content'];
if (!str_contains($result['serialized_blocks'], 'blocks-engine-background-image')) throw new RuntimeException('Fixture must lower a background image');
foreach (array(
    '*,:before,:after{--tw-translate-x:0;--tw-ring-color:rgb(59 130 246 / .5)}',
    '::backdrop{--tw-translate-x:0}',
    ':before,:after{--tw-content:""}',
) as $rule) {
    if (!str_contains($css, $rule)) throw new RuntimeException('Universal custom-property reset must keep its authored selectors: ' . $rule . "\n" . $css);
}
$resets = 0;
(new \Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer())->visitStyleRules($css, static function (string $prelude, string $body) use (&$resets): void {
    // The editor canvas root keeps its own restatement.
    if (str_contains($prelude, 'editor-styles-wrapper') || (!str_contains($body, '--tw-content') && !str_contains($body, '--tw-ring-color'))) return;
    ++$resets;
    if (str_contains($prelude, 'blocks-engine-')) {
        throw new RuntimeException('Universal custom-property reset must not be projected per subject: ' . $prelude);
    }
});
if (2 !== $resets) throw new RuntimeException('Each universal custom-property reset ships once, got ' . $resets . "\n" . $css);
// The class setter still owns its variable over the zero-specificity reset.
if (!str_contains($css, '--tw-translate-x:.5rem')) throw new RuntimeException('Class-set custom property must survive' . "\n" . $css);
echo "Universal custom-property resets keep their authored selector list\n";
