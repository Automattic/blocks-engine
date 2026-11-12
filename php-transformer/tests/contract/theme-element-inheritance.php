<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Section-owned colours must keep inheriting, while the site's element
// typography remains available to Global Styles even without a unanimous look.
$source = '<!doctype html><html><head><style>body{font-family:Arial,sans-serif;color:#222;background:#fff}.dark{color:#fff;background:#222}h1,h2,a{color:inherit}h1{font-size:32px}h2{font-size:24px}</style></head><body><main><h1>Title</h1><a href="/one">First</a><section class="dark"><h2>Other</h2><a href="/two">Second</a></section></main></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toArray();
$theme = null;
foreach ($result['source_reports']['wordpress_site_plan']['writes'] as $write) {
    if ('theme.json' === $write['target_path']) $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
}
foreach (array('link', 'heading') as $element) {
    $style = $theme['styles']['elements'][$element] ?? array();
    if (empty($style['typography']['fontFamily'])) throw new RuntimeException($element . ' must expose typography in Global Styles');
    if ('link' === $element && 'inherit' !== $style['typography']['fontFamily']) throw new RuntimeException('Links without a shared family must inherit');
    if (isset($style['color']['text']) && 'inherit' !== $style['color']['text']) throw new RuntimeException($element . ' must not flatten section-owned text colours');
}
echo "Theme element inheritance: passed\n";
