<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Conditional paint stays in author CSS rather than native block supports, so
// outline detection must consult the winning background in the resolved cascade.
foreach (array(
    array('background:transparent', 'background-color:#fff', false),
    array('background-color:transparent', 'background:#fff', false),
    array('background:#fff', 'background-color:transparent', true),
) as [$reset, $paint, $outline]) {
    $result = (new ArtifactCompiler())->compile(array('files' => array(
        array(
            'path' => 'index.html',
            'kind' => 'html',
            'content' => '<!doctype html><html><head><style>a{' . $reset . ';font-size:100%}</style></head><body><main><p>Controls</p><link rel="stylesheet" href="/lib%401.0/controls.css"><div class="bar"><a href="#" role="button">+</a></div></main></body></html>',
        ),
        array(
            'path' => 'lib@1.0/controls.css',
            'kind' => 'css',
            'content' => '.bar a{' . $paint . ';border:1px solid #ccc;width:30px;height:30px;display:block}.bar a{background-position:50% 50%;background-repeat:no-repeat}@media(max-width:300px){.bar a{background-color:#eee}}',
        ),
    )))->toArray();
    $markup = $result['serialized_blocks'];
    if (1 !== preg_match('/<!-- wp:button (\{.*?\}) -->/', $markup, $matches)) {
        throw new RuntimeException('The control must lower to core/button.');
    }
    $attrs = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
    if ($outline !== str_contains($attrs['className'] ?? '', 'is-style-outline')) {
        throw new RuntimeException('Outline classification must follow the winning background: ' . $paint);
    }
    if ($outline !== ('transparent' === ($attrs['style']['color']['background'] ?? null))) {
        throw new RuntimeException('Only an outline may acquire forced transparent native paint.');
    }
}
echo "Button outline winning background contract passed.\n";
