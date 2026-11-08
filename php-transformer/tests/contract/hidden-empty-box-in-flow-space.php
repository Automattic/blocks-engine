<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$compile = static fn (string $html): string => (string) (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray()['serialized_blocks'];

// `opacity:0` and `visibility:hidden` stop a box from painting, not from taking
// space. An empty in-flow box whose padding sizes a painted card keeps its
// geometry; one that takes no space (not rendered, out of flow, or a zero box)
// still drops.
$card = static fn (string $inner): string => '<style>.card{background:rgba(5,5,5,.87);border-radius:14px;width:451px;margin:0 auto}'
    . '.card > .wrapper > .inner{' . $inner . 'border-radius:14px}</style>'
    . '<main><div class="card" id="spacer"><div class="wrapper"><div class="inner" style="%s"></div></div></div><footer><p>Footer</p></footer></main>';
$kept = '/<div id="spacer" class="wp-block-group card[^"]*"[^>]*><!-- wp:group \{"className":"wrapper"\} --><div class="wp-block-group wrapper"><!-- wp:group \{"className":"inner[ "]/';

foreach (array(
    'opacity:0 padded box' => array('padding:3rem;', 'opacity: 0', true),
    'visibility:hidden padded box' => array('padding:3rem;', 'visibility: hidden', true),
    'opacity:0 bordered box' => array('border:48px solid transparent;', 'opacity: 0', true),
    'opacity:0 sized box' => array('height:96px;', 'opacity: 0', true),
    'opacity:0 zero box' => array('', 'opacity: 0', false),
    'display:none padded box' => array('padding:3rem;', 'display: none', false),
    'opacity:0 out-of-flow padded box' => array('padding:3rem;position:absolute;', 'opacity: 0', false),
) as $label => [$inner, $style, $keep]) {
    $markup = $compile(sprintf($card($inner), $style));
    if ($keep !== (1 === preg_match($kept, $markup)) || !str_contains($markup, 'Footer')) {
        throw new RuntimeException($label . ': .inner ' . ($keep ? 'must stay under #spacer > .wrapper' : 'must drop') . '. ' . $markup);
    }
}

echo "Hidden empty box in-flow space contract passed.\n";
