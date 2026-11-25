<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A ratio frame its lone image fills from the inside (`relative aspect-square`
// around an `absolute inset-0 w-full h-full` image) is that image's figure,
// not a Group the editor has to click through to reach the image.
$css = '*,:before,:after{box-sizing:border-box;border:0 solid #e5e7eb}'
    . '.tile{overflow:hidden;border-radius:12px;border-width:1px}'
    . '.inline-block{display:inline-block}.relative{position:relative}.absolute{position:absolute}'
    . '.aspect-square{aspect-ratio:1/1}.w-full{width:100%}.h-full{height:100%}.inset-0{inset:0}'
    . '.aspect-video{aspect-ratio:16/9}.object-cover{object-fit:cover}.p-2{padding:8px}.h-40{height:10rem}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}';
$frame = '<span class="inline-block relative aspect-square w-full"><img class="w-full h-full inset-0 absolute object-cover" src="a.jpg" alt="A"></span>';
$compile = static function (string $body) use ($css): string {
    return (new ArtifactCompiler())->compile(array('files' => array(
        array('path' => 'index.html', 'kind' => 'html', 'content' => '<!doctype html><html><head><link rel="stylesheet" href="s.css"></head><body><main>' . $body . '</main></body></html>'),
        array('path' => 's.css', 'kind' => 'css', 'content' => $css),
    )))->toArray()['serialized_blocks'];
};
$tree = static function (string $markup): array {
    preg_match_all('#<!-- (/?)wp:([a-z0-9/-]+)(?: (\{.*?\}))? (/?)-->#', $markup, $tokens, PREG_SET_ORDER);
    $stack = array(array('name' => 'root', 'class' => '', 'children' => array()));
    foreach ($tokens as [, $close, $name, $attrs, $self]) {
        if ('/' === $close) {
            $node = array_pop($stack);
            $stack[count($stack) - 1]['children'][] = $node;
            continue;
        }
        $node = array('name' => $name, 'class' => (string) (json_decode($attrs ?: '{}', true)['className'] ?? ''), 'children' => array());
        if ('/' === $self) $stack[count($stack) - 1]['children'][] = $node;
        else $stack[] = $node;
    }
    return $stack[0];
};
$find = static function (array $node, string $class) use (&$find): ?array {
    if (in_array($class, explode(' ', $node['class']), true)) return $node;
    foreach ($node['children'] as $child) if (null !== ($hit = $find($child, $class))) return $hit;
    return null;
};

$markup = $compile('<div class="grid"><div class="tile">' . $frame . '</div><div class="tile">' . str_replace('a.jpg', 'b.jpg', $frame) . '</div></div>');
$tile = $find($tree($markup), 'tile');
if (null === $tile || 1 !== count($tile['children']) || 'image' !== $tile['children'][0]['name']
    || !in_array('aspect-square', explode(' ', $tile['children'][0]['class']), true)) {
    throw new RuntimeException('The filled ratio frame must lower as the image figure inside its tile: ' . $markup);
}
if (2 !== substr_count($markup, '<!-- wp:image ') || str_contains($markup, 'wp:html') || str_contains($markup, 'layout-shell')) {
    throw new RuntimeException('Both framed images must stay native core/image blocks: ' . $markup);
}

// A frame that adds its own box (padding, a fixed height) or wraps more than
// the image is not the image's box: it keeps its Group.
foreach (array(
    'padded frame' => str_replace('aspect-square w-full"', 'aspect-square w-full p-2"', $frame),
    'fixed-height frame' => str_replace('aspect-square w-full"', 'aspect-square w-full h-40"', $frame),
    'unpinned image' => str_replace(' inset-0', '', $frame),
    'frame with caption' => str_replace('</span>', '<b>Caption</b></span>', $frame),
) as $case => $html) {
    $markup = $compile('<div class="tile">' . $html . '</div>');
    $ratio = $find($tree($markup), 'aspect-square');
    if (null === $ratio || 'group' !== $ratio['name']) {
        throw new RuntimeException($case . ' must keep the frame as its own Group: ' . $markup);
    }
}

// The frame's ratio is the figure's only height: the image inside is out of
// flow. The bridge that moves an image's own ratio class from its figure down
// onto the image (`<img class="aspect-square">`) must not reset this figure's
// ratio to auto, or the tile collapses to zero height.
$compiled = (new ArtifactCompiler())->compile(array('files' => array(
    array('path' => 'index.html', 'kind' => 'html', 'content' => '<!doctype html><html><head><link rel="stylesheet" href="s.css"></head><body><main><div class="grid"><div class="tile">' . $frame . '</div><div class="tile"><img class="aspect-square w-full object-cover" src="c.jpg" alt="C"></div><div class="tile"><img class="aspect-video w-full" src="d.jpg" alt="D"></div></div></main></body></html>'),
    array('path' => 's.css', 'kind' => 'css', 'content' => $css),
)))->toArray();
preg_match_all('#<figure class="([^"]*)"#', $compiled['serialized_blocks'], $figures);
$frameFigure = array_values(array_filter($figures[1], static fn (string $classes): bool => str_contains(' ' . $classes . ' ', ' inline-block ')));
$imageFigure = array_values(array_filter($figures[1], static fn (string $classes): bool => str_contains(' ' . $classes . ' ', ' aspect-square ') && !str_contains(' ' . $classes . ' ', ' inline-block ')));
if (1 !== count($frameFigure) || 1 !== count($imageFigure)) {
    throw new RuntimeException('Expected one framed and one ratio-classed image figure: ' . $compiled['serialized_blocks']);
}
$stylesheets = implode('', array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $compiled['assets']));
$resetsRatio = static function (string $classes) use ($stylesheets): bool {
    $classes = preg_split('/\s+/', trim($classes)) ?: array();
    preg_match_all('#([^{}]+)\{[^{}]*aspect-ratio:auto[^{}]*\}#', $stylesheets, $rules);
    foreach ($rules[1] as $prelude) {
        foreach (explode(',', $prelude) as $arm) {
            $excluded = preg_match_all('#:not\(\.([A-Za-z0-9_-]+)\)#', $arm, $not) ? $not[1] : array();
            preg_match_all('#\.([A-Za-z0-9_-]+)#', (string) preg_replace('#:(?:where|not)\([^()]*(?:\([^()]*\))?[^()]*\)#', '', $arm), $required);
            if (array() !== $required[1] && array() === array_diff($required[1], $classes) && array() === array_intersect($excluded, $classes)
                && !str_contains($arm, '>') && !str_contains($arm, ' ')) {
                return true;
            }
        }
    }
    return false;
};
if ($resetsRatio($frameFigure[0])) {
    throw new RuntimeException('The framed image figure must keep its frame ratio: ' . $stylesheets);
}
if (!$resetsRatio($imageFigure[0])) {
    throw new RuntimeException('An image that carried its own ratio class still moves that ratio onto the image: ' . $stylesheets);
}

// A ratio reset no framed figure can match stays as the source wrote it:
// the exclusion is confined to the classes framed figures carry, so the
// rest of the site's stylesheet is not restated around them.
if (1 !== preg_match('#\.aspect-video\.wp-block-image(?:[^{},]*)\{aspect-ratio:auto#', $stylesheets, $videoReset)
    || str_contains($videoReset[0], 'blocks-engine-ratio-frame-image-figure')) {
    throw new RuntimeException('A ratio reset no framed figure carries must not exclude framed figures: ' . $stylesheets);
}

echo "positioned-image-frame-figure: passed\n";
