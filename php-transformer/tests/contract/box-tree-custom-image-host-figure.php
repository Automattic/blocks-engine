<?php
declare(strict_types=1);

// A captured custom image host (`<wow-image>`) whose observed box is exactly
// its image's box is the image's figure, not a container around it: lowering
// it as a separate core/group adds a List View level to every image chain.
// An unobserved copy of the host (a device island) needs the authored cascade
// to prove the same fill before it may fold.
require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$css = '<style>.layer{position:relative;height:200px}.layer .wow{block-size:100%;inline-size:100%;position:absolute}.layer .wow img{block-size:100%;inline-size:100%}'
    . '.pad .wow{position:absolute;padding:8px}</style>';
$host = static fn (string $id, string $node, string $src): string => '<wow-image id="' . $id . '" class="wow" data-image-info="{&quot;displayMode&quot;:&quot;fill&quot;}"' . ('' !== $node ? ' data-blocks-engine-node-id="' . $node . '"' : '') . '>'
    . '<picture' . ('' !== $node ? ' data-blocks-engine-node-id="' . $node . 'p"' : '') . '><img alt="" style="object-fit: cover;width: 100%" src="/media/' . $src . '.png"' . ('' !== $node ? ' data-blocks-engine-node-id="' . $node . 'i"' : '') . '></picture></wow-image>';
$html = '<!doctype html><html data-blocks-engine-node-id="n1"><head data-blocks-engine-node-id="n2"><title data-blocks-engine-node-id="n3">Image host</title>' . $css . '</head>'
    . '<body data-blocks-engine-node-id="n4"><main data-blocks-engine-node-id="n5">'
    . '<div class="layer" data-blocks-engine-node-id="layer">' . $host('img-observed', 'h', 'observed') . $host('img-island', '', 'island') . '</div>'
    . '<div class="layer pad" data-blocks-engine-node-id="pad">' . $host('img-padded', 'q', 'padded') . '</div>'
    . '</main></body></html>';

$tree = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$tree['source_hash'] = hash('sha256', $html);
$template = $tree['nodes']['n6']['viewports'];
$box = static function (array $boxes, array $styles = array()) use ($template): array {
    $viewports = array();
    foreach ($template as $viewport => $observation) {
        $observation['border_box'] = $boxes[$viewport];
        $observation['used_styles'] = array_replace($observation['used_styles'], $styles);
        $viewports[$viewport] = $observation;
    }
    return $viewports;
};
$frame = array('desktop' => array('x' => 0, 'y' => 0, 'width' => 400, 'height' => 200), 'mobile' => array('x' => 0, 'y' => 0, 'width' => 300, 'height' => 200));
$inner = array('desktop' => array('x' => 8, 'y' => 8, 'width' => 384, 'height' => 184), 'mobile' => array('x' => 8, 'y' => 8, 'width' => 284, 'height' => 184));
$tree['nodes']['n5']['children'] = array('layer', 'pad');
unset($tree['nodes']['n6'], $tree['nodes']['n7']);
$tree['nodes']['layer'] = array('children' => array('h'), 'viewports' => $box($frame, array('position' => 'relative', 'height' => '200px')));
$tree['nodes']['h'] = array('children' => array('hp'), 'viewports' => $box($frame, array('position' => 'absolute')));
$tree['nodes']['hp'] = array('children' => array('hi'), 'viewports' => $box($frame, array('display' => 'inline')));
$tree['nodes']['hi'] = array('children' => array(), 'viewports' => $box($frame, array('display' => 'inline', 'width' => '400px', 'height' => '200px', 'object-fit' => 'cover')));
$tree['nodes']['pad'] = array('children' => array('q'), 'viewports' => $box($frame, array('position' => 'relative', 'height' => '200px')));
$tree['nodes']['q'] = array('children' => array('qp'), 'viewports' => $box($frame, array('position' => 'absolute', 'padding-top' => '8px')));
$tree['nodes']['qp'] = array('children' => array('qi'), 'viewports' => $box($inner, array('display' => 'inline')));
$tree['nodes']['qi'] = array('children' => array(), 'viewports' => $box($inner, array('display' => 'inline', 'object-fit' => 'cover')));

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $html,
    'box-tree/index.json' => json_encode($tree),
)))->toArray();
$markup = (string) $result['serialized_blocks'];

$imageWithAnchor = static fn (string $anchor): bool => 1 === preg_match('/<!-- wp:image \{(?:(?!-->).)*?"anchor":"' . $anchor . '"(?:(?!-->).)*?--><figure id="' . $anchor . '"/', $markup);
$groupWithAnchor = static fn (string $anchor): bool => 1 === preg_match('/<!-- wp:group \{(?:(?!-->).)*?"anchor":"' . $anchor . '"/', $markup);

$assert($imageWithAnchor('img-observed') && !$groupWithAnchor('img-observed'), 'A host observed with its image\'s box is the image figure, not a group around it.');
$assert($imageWithAnchor('img-island') && !$groupWithAnchor('img-island'), 'An unobserved host whose authored image fills it folds the same way.');
$assert($groupWithAnchor('img-padded') && !$imageWithAnchor('img-padded'), 'A host whose box differs from its image keeps its own container.');
$assert(1 === preg_match('/<figure id="img-observed" class="[^"]*\bwow\b/', $markup), 'The folded figure keeps the host class its author rules select.');
$assert(str_contains($markup, 'src="/media/observed.png"') && str_contains($markup, 'src="/media/island.png"'), 'Both images keep their source.');
