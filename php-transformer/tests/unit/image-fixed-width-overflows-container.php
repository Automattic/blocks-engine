<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A source image whose author width never refers to its container
// (`width:1440px`, `width:max(674px,100vw)`) and whose computed max-width is
// the initial `none` overflows a narrower container in the source. Core's
// `.wp-block-image img{max-width:100%}` must not shrink it to the viewport.
// A print-only clamp does not vary the axis across screen viewports.
$print = '@media print{img{max-width:100%!important}}';
$cases = array(
    'fixed pixel width' => array(
        'css' => $print . '.frame{overflow:hidden;width:100%}',
        'html' => '<div class="frame"><figure style="overflow:hidden"><img src="a.webp" alt="a" style="position:relative;width:1440px;height:959px"></figure></div>',
        'unclamped' => true,
    ),
    'viewport-bounded math width' => array(
        'css' => $print . '.frame{overflow:hidden;width:100%}',
        'html' => '<div class="frame"><figure><img src="a.webp" alt="a" style="width:max(674px, 100vw);height:max(449px, 66.64vw)"></figure></div>',
        'unclamped' => true,
    ),
    'container-relative width' => array(
        'css' => $print . '.frame{width:100%}',
        'html' => '<div class="frame"><figure><img src="a.webp" alt="a" style="width:100%;height:959px"></figure></div>',
        'unclamped' => false,
    ),
    'source clamps the image' => array(
        'css' => $print . '.frame img{max-width:100%}',
        'html' => '<div class="frame"><figure><img src="a.webp" alt="a" style="width:1440px;height:959px"></figure></div>',
        'unclamped' => false,
    ),
    'screen media clamps the image' => array(
        'css' => '@media (max-width:600px){.frame img{max-width:100%}}',
        'html' => '<div class="frame"><figure><img src="a.webp" alt="a" style="width:1440px;height:959px"></figure></div>',
        'unclamped' => false,
    ),
);

foreach ($cases as $name => $case) {
    $result = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array(
            'index.html' => '<!doctype html><html><head><title>t</title><style>' . $case['css']
                . '</style></head><body><main>' . $case['html'] . '<p>Text</p></main></body></html>',
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
    preg_match_all('/\.(be-inline-geometry-[0-9a-f]+) img\{([^}]*)\}/', $css, $matches, PREG_SET_ORDER);
    $unclamped = false;
    foreach ($matches as $match) {
        if (str_contains($match[2], 'max-width:none') && str_contains($markup, $match[1])) {
            $unclamped = true;
        }
    }
    if ($case['unclamped'] !== $unclamped) {
        throw new RuntimeException($name . ': unexpected image clamp override: ' . implode("\n", array_column($matches, 0)));
    }
    echo $name . ": ok\n";
}
