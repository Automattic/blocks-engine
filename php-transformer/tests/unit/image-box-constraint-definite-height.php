<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Only a containing-block-independent height can safely lift Core's image clamp.
$cases = array(
    'percentage height in an absolute ratio box' => array(
        'css' => '.img-absolute{position:absolute;left:0;width:100%;height:auto}.item-image img{height:100%;max-height:1200px;width:auto}',
        'html' => '<div class="item-image"><div style="padding-bottom:100%;position:relative"><a href="/p/"><img src="a.webp" width="1024" height="1024" class="img-absolute" alt="x"></a></div></div>',
        'unclamped' => false,
    ),
    'fixed height overflowing a narrower container' => array(
        'css' => '.box{width:69px;height:82px}.box img{height:82px;width:auto;aspect-ratio:82/82;object-fit:cover;display:block}',
        'html' => '<main><div class="box"><img src="i.png" alt="icon"></div><p>Text</p></main>',
        'unclamped' => true,
    ),
);

foreach ($cases as $name => $case) {
    $result = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array(
            'index.html' => '<!doctype html><html><head><title>t</title><style>' . $case['css']
                . '</style></head><body>' . $case['html'] . '</body></html>',
        ),
    ))->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    if (! str_contains($markup, '<img')) {
        throw new RuntimeException($name . ': compilation must emit an image.');
    }
    $css = '';
    foreach ($result['assets'] ?? array() as $asset) {
        $css .= (string) ($asset['content'] ?? '');
    }
    preg_match_all('/\.be-inline-geometry-[0-9a-f]+ img\{([^}]*)\}/', $css, $matches);
    $rules = implode("\n", $matches[0]);
    if ($case['unclamped'] !== str_contains($rules, 'max-width:none')) {
        throw new RuntimeException($name . ': unexpected image clamp override: ' . $rules);
    }
    echo $name . ": ok\n";
}
