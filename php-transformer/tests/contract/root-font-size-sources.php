<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$rows = array(
    'html' => array('<style>html{font-size:10px}</style>', array()),
    'root inline' => array('<style>:root{font-size:10px}</style>', array()),
    'root linked' => array('<link rel="stylesheet" href="root.css">', array('root.css' => ':root{font-size:10px}')),
    'media pair' => array('<style media="(min-width:768px)">html{font-size:10px}</style><style media="(max-width:767px)">html{font-size:10px}</style>', array()),
    'media import' => array('<style media="(min-width:768px)">html{font-size:10px}</style><style>@import url("root.css") (max-width:767px);</style>', array('root.css' => 'html{font-size:10px}')),
);
foreach ($rows as $label => [$head, $files]) {
    $files['index.html'] = '<!doctype html><html><head>' . $head . '<style>.lead{font-size:1.6rem}</style></head><body><p class="lead">Example text</p></body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
    $root = $result['source_reports']['compiled_site']['pages'][0]['design_usage']['root_font_size_px'] ?? null;
    $sizes = array();
    foreach ($result['source_reports']['wordpress_site_plan']['writes'] ?? array() as $write) {
        if ('theme.json' !== $write['target_path']) continue;
        $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
        $sizes = array_column($theme['settings']['typography']['fontSizes'] ?? array(), 'size');
    }
    if (10.0 !== (float) $root || !in_array('1.6rem', $sizes, true)) {
        fwrite(STDERR, "FAIL: {$label}: " . json_encode(array('root' => $root, 'presets' => $sizes)) . "\n");
        exit(1);
    }
}
foreach (array(
    'conflicting media' => array('<style>@media(min-width:768px){:root{font-size:10px}}@media(max-width:767px){:root{font-size:12px}}</style>', array()),
    'unknown media' => array('<style media="(min-width:768px)">html{font-size:larger}</style>', array()),
    'conflicting import' => array('<style media="(min-width:768px)">html{font-size:10px}</style><style>@import url("root.css") (max-width:767px);</style>', array('root.css' => 'html{font-size:12px}')),
) as $label => [$head, $files]) {
    $files['index.html'] = '<html><head>' . $head . '</head><body><p>Example text</p></body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
    $usage = $result['source_reports']['compiled_site']['pages'][0]['design_usage'];
    if (null !== $usage['root_font_size_px'] || true !== $usage['root_font_size_authored']) {
        fwrite(STDERR, "FAIL: {$label}: conditional root must stay unknown with author evidence\n");
        exit(1);
    }
}
fwrite(STDOUT, "Root font size sources contract passed\n");
