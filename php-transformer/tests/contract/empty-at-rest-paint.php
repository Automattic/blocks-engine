<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

foreach (array(
    'inert overlay' => array('position:absolute;inset:0;opacity:0;background:transparent;border:0;box-shadow:none;pointer-events:none', '', false),
    'hover overlay' => array('position:absolute;inset:0;opacity:0;background:transparent;border:0;box-shadow:none;pointer-events:none', '.empty:hover{opacity:1}', true),
    'background' => array('background-color:red', '', true),
    'border rule' => array('border-top:1px solid black', '', true),
    'focus overlay' => array('opacity:0;background:red;pointer-events:none', '.empty:focus{opacity:1}', true),
    'resolved opacity' => array('opacity:var(--hidden);--hidden:0;background:red;pointer-events:none', '', false),
    'flow spacer' => array('height:30px', '', true),
    'click-capture overlay' => array('position:absolute;inset:0;width:100%;height:100%;z-index:1;cursor:zoom-in;opacity:0', '', false),
    'zero-height rule' => array('height:0;pointer-events:none', '', false),
) as $label => [$style, $stateRule, $keep]) {
    $html = '<style>.empty{' . $style . '}' . $stateRule . '</style><div class="empty"></div>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $markup = (string) $result['serialized_blocks'];
    if ($keep !== str_contains($markup, 'blocks-engine-empty-visual-group')) {
        throw new RuntimeException($label . ': empty visual group preservation must follow resting paint and interaction. ' . $markup);
    }
    if (!$keep && str_contains($markup, '<!-- wp:group')) {
        throw new RuntimeException($label . ': inert empty element must not emit a group.');
    }
}
// Inside a CSS-owned layout the empty child reaches the layout-item path, which
// must apply the same proof instead of lowering it to an empty group.
$html = '<style>.frame{display:flex;position:relative;width:300px;border-radius:8px;overflow:hidden}.zoom{position:absolute;inset:0;width:100%;height:100%;z-index:1;cursor:zoom-in;opacity:0}</style>'
    . '<div class="frame"><div class="zoom"></div><img src="a.jpg" alt="Boat"></div>';
$markup = (string) (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray()['serialized_blocks'];
if (str_contains($markup, 'zoom') || !str_contains($markup, 'wp:image')) {
    throw new RuntimeException('layout-item click-capture overlay must drop while its sibling image stays. ' . $markup);
}
$html = '<style>.grid{display:grid;grid-template-columns:repeat(4,1fr)}.cell{grid-area:3/1/auto/span 1;cursor:default}</style>'
    . '<div class="grid"><p>One</p><p>Two</p><div class="cell"></div></div>';
$markup = (string) (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray()['serialized_blocks'];
if (!str_contains($markup, 'cell')) {
    throw new RuntimeException('an empty in-flow grid item shapes its tracks and must stay. ' . $markup);
}
echo "Empty at-rest paint contract passed.\n";
