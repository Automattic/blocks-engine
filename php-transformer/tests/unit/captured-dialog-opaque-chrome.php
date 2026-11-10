<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A modal's navigation and trailing copy are not document header/footer
// candidates. Exercise whole-site planning, not only fragment conversion.
$files = array();
foreach (array('index.html' => 'Home', 'about/index.html' => 'About') as $path => $title) {
    $files[] = array('path' => 'website/' . $path, 'content' => '<html><body><main><h1>' . $title . '</h1><p>Page content</p></main>'
        . '<dialog id="notes" data-blocks-engine-captured-dialog="true" data-blocks-engine-triggers="opener">'
        . '<div><nav><a href="/">Home</a><a href="/about/">About</a></nav><div><p>Modal-only trailing copy</p></div></div></dialog></body></html>');
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
foreach ($plan['template_parts'] as $part) {
    if (str_contains($part['canonical_block_markup'], 'Modal-only trailing copy')) {
        throw new RuntimeException('Dialog content must not be extracted as document chrome: ' . $part['canonical_block_markup']);
    }
}
$markup = implode('', array_column($plan['pages'], 'canonical_block_markup'));
if (!str_contains($markup, 'blocksEngineDialog') || str_contains($markup, '/captured-dialog')) {
    throw new RuntimeException('Keep the editable modal Group in page content.');
}
echo "Captured dialog opaque chrome passed\n";
