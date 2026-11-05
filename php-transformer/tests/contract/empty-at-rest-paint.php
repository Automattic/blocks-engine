<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$compile = static fn (string $html): string => (string) (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray()['serialized_blocks'];

// Builder media chain: a flex column holding a clipped, rounded frame whose
// first child is an empty overlay and whose second wraps the image. The overlay
// drops only when it is out of flow, fully transparent, unpainted in every
// state, and nothing in WordPress can interact with it.
$overlay = 'position:absolute;inset:0;width:100%;height:100%;z-index:1;cursor:zoom-in;opacity:0';
foreach (array(
    'click-capture overlay' => array($overlay, '', '', false),
    'painted overlay' => array($overlay . ';background:#000', '', '', true),
    'hover-revealed overlay' => array($overlay, '.empty:hover{opacity:1;background:rgba(0,0,0,.3)}', '', true),
    'focusable overlay' => array($overlay, '', ' tabindex="0"', true),
) as $label => [$style, $stateRule, $attributes, $keep]) {
    $markup = $compile('<style>.column{display:flex;flex-direction:column;width:100%}.frame{width:480px;max-width:100%;position:relative;overflow:hidden;border-radius:8px}'
        . '.crop{max-width:100%;overflow:hidden;width:100%;display:block}.empty{' . $style . '}' . $stateRule . '</style>'
        . '<div class="column"><div class="frame"><div class="empty"' . $attributes . '></div><div class="crop"><img src="a.jpg" alt="Boat"></div></div></div>');
    if ($keep !== str_contains($markup, 'empty') || !str_contains($markup, 'wp:image')) {
        throw new RuntimeException($label . ': overlay ' . ($keep ? 'must stay' : 'must drop') . ' while the image stays. ' . $markup);
    }
}

// An in-flow empty grid item takes a cell and shapes its siblings' tracks.
$markup = $compile('<style>.track{display:grid;grid-template-columns:repeat(4,1fr)}.cell{grid-area:3/1/auto/span 1;cursor:default}</style>'
    . '<div class="track"><p>One</p><p>Two</p><div class="cell"></div></div>');
if (!str_contains($markup, 'cell')) {
    throw new RuntimeException('in-flow grid item must stay. ' . $markup);
}

echo "Empty at-rest paint contract passed.\n";
