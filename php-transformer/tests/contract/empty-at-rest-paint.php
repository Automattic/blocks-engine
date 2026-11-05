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
    'unpainted box' => array('height:30px;pointer-events:none', '', false),
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
echo "Empty at-rest paint contract passed.\n";
