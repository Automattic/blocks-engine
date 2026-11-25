<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><p class="sample">Hello</p></main></body></html>',
    'site.css' => '.sample{ opacity : 0.75 ; letter-spacing : -0.125em ; transition : opacity 0.25s ; transform : translate(0.5px,+0.25px) ; --literal: 0.125 ; word-spacing:var(--literal);animation-name:step0.5;content:"0.125; : ";background-image:url(image0.5.png);}',
)))->toArray();
$css = '';
foreach ($result['source_reports']['wordpress_site_plan']['assets'] as $asset) {
    if ('css' === ($asset['kind'] ?? null) && is_string($asset['content'] ?? null)) $css .= $asset['content'] . "\n";
}
foreach (array('opacity:0.75', 'letter-spacing:-0.125em', 'transition:opacity 0.25s', 'transform:translate(0.5px,+0.25px)') as $expected) {
    if ( ! str_contains($css, $expected) ) throw new RuntimeException('Declaration framing must be compact without changing number tokens: ' . $expected . "\n" . $css);
}
foreach (array('--literal:0.125', 'step0.5', '"0.125; : "', 'image0.5.png') as $expected) {
    if ( ! str_contains($css, $expected) ) throw new RuntimeException('Value token streams must survive: ' . $expected . "\n" . $css);
}
if (str_contains($css, 'image0.5.png);}')) throw new RuntimeException('The final declaration separator must not ship.');
echo "Declaration delivery compacts framing but retains value token streams\n";
