<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => '<!doctype html><html><head><title>Text</title><style>.preserved{white-space:pre-wrap}</style></head><body><main>'
        . "<p>First<br>\n<br>\nSecond <em>third\nfourth</em></p>"
        . "<p>Before \n<!-- author note -->\n  after</p>"
        . "<div class=\"preserved\"><p>Keep\nlines</p></div>"
        . '</main></body></html>'),
))->toWordPressSitePlanView()['wordpress_site_plan'];

$serialized = json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
if (!str_contains($serialized, 'First<br> <br> Second') || !str_contains($serialized, 'third fourth')) {
    throw new RuntimeException('Collapsed line endings must match the editable RichText value, including inline text.');
}
if (!str_contains(strip_tags($serialized), 'Before after')) {
    throw new RuntimeException('Comments must not split a collapsed whitespace run.');
}
if (!str_contains($serialized, 'Keep\\nlines')) {
    throw new RuntimeException('Inherited pre-wrap line endings must remain meaningful.');
}
echo "richtext-collapsed-newlines: ok\n";
