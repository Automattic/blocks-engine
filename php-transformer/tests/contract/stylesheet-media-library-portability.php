<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
// One image is both content and a stylesheet background.
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><style>.band{position:relative;padding:80px 20px}.band .bg{position:absolute;inset:0;z-index:-1;background:url("media/photo.png") center/cover}</style></head><body><main><section class="band"><div class="bg"></div><h2>Stylesheet background</h2></section><figure><img src="media/photo.png" alt="Photo"></figure></main></body></html>',
    'media/photo.png' => array('content' => $png, 'mime_type' => 'image/png'),
)))->toArray()['source_reports']['wordpress_site_plan'];
$asset = current(array_filter($plan['assets'], static fn(array $asset): bool => 'media_library' === $asset['destination']));
$assert(is_array($asset) && array() !== array_filter($asset['consumers'], static fn(array $consumer): bool => 'stylesheet' === $consumer['kind']), 'The reduced page declares a Media Library image with a stylesheet consumer.');

$themeUri = 'http://build.example.test:8888/wp-content/themes/site';
$upload = 'http://build.example.test:8888/wp-content/uploads/2026/10/photo.png';
$resolved = (new WordPressSitePlanResolver())->resolve($plan, array('theme_uri' => $themeUri, 'attachment_resolutions' => array($asset['token'] => array('id' => 41, 'url' => $upload))));
$checked = 0;
foreach ($resolved['writes'] as $write) {
    if ('utf8' !== $write['payload']['encoding'] || !str_ends_with($write['target_path'], '.css')) continue;
    $css = $write['payload']['data'];
    $assert(!str_contains($css, 'build.example.test'), 'Theme stylesheets must not bake the build host: ' . $write['target_path']);
    if (!preg_match_all('~url\(\s*["\']?([^"\')]*uploads/2026/10/photo\.png)~', $css, $matches)) continue;
    foreach ($matches[1] as $url) {
        // Resolve the reference against the stylesheet, as the browser does on any host.
        $parts = explode('/', dirname('wp-content/themes/site/' . $write['target_path']));
        foreach (explode('/', $url) as $segment) '..' === $segment ? array_pop($parts) : $parts[] = $segment;
        $assert('wp-content/uploads/2026/10/photo.png' === implode('/', $parts), 'The stylesheet reference reaches the attachment file: ' . $url);
        ++$checked;
    }
}
$assert($checked >= 1, 'The stylesheet rule references the attachment.');
$assert(str_contains($resolved['pages'][0]['resolved_block_markup'], $upload), 'Post content keeps the absolute attachment URL, which WordPress rewrites on a move.');
WordPressSitePlan::assertValid($resolved);
echo "stylesheet-media-library-portability contract passed\n";
