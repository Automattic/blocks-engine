<?php
declare(strict_types=1);

/**
 * ADR 0006 slice 1, evidence intake on real imports.
 *
 * - v2 and compact v3 sidecars read through one contract reader into the same
 *   tree, classify alike and convert byte-identically; a v3 tree whose style
 *   index names no style object, or an unknown schema, is no evidence.
 * - A sidecar binds the document's original artifact bytes. Compilation
 *   re-serializes the document (`<!DOCTYPE html>\n<html style="">`) before
 *   HTML conversion, and the neutral wrapper still folds.
 * - Wix gives every element an id: a neutral wrapper whose leaf has its own id
 *   folds and the leaf keeps its id. A wrapper id equal to the leaf's, or one
 *   something addresses, keeps the wrapper.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/box-tree-evidence-fixture.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\BoxTreeWrapperEvidence;

function bti_assert(bool $condition, string $message): void
{
    if (! $condition) {
        fwrite(STDERR, "box-tree-evidence-intake: {$message}\n");
        exit(1);
    }
}

$compile = static function (array $files): string {
    $result = (new ArtifactCompiler())->compile(array('files' => $files))->toArray();
    return (string) $result['serialized_blocks'];
};
$box = array(8, 120, 1424, 200);
$css = array('path' => 's.css', 'kind' => 'css', 'content' => '.outer > .inner{color:red}');
$page = static fn (string $wrapperAttrs, string $leafAttrs, string $prefix = '<html>', string $extra = ''): string => $prefix . '<head><link rel="stylesheet" href="s.css"></head><body>'
    . '<header><p>Head</p></header><main><p>Intro</p>' . $extra
    . '<div class="outer" data-blocks-engine-node-id="w"' . $wrapperAttrs . '><div class="inner" data-blocks-engine-node-id="c"' . $leafAttrs . '>'
    . '<p data-blocks-engine-node-id="p1">One</p><p data-blocks-engine-node-id="p2">Two</p>'
    . '</div></div><p>Outro</p></main></body></html>';
$neutral = array('w' => array('box' => $box), 'c' => array('box' => $box), 'p1' => array(), 'p2' => array());
$folded = static fn (string $markup): bool => 3 === substr_count($markup, '<!-- wp:group') && ! str_contains($markup, '{"className":"outer"}');

// 1. v2 and v3 read alike.
$html = $page('', '');
$files = array(array('path' => 'index.html', 'kind' => 'html', 'content' => $html), $css);
$withoutEvidence = $compile($files);
$source = new DOMDocument();
@$source->loadHTML($html);
$wrapper = (new DOMXPath($source))->query('//div[@class="outer"]')->item(0);
bti_assert($wrapper instanceof DOMElement, 'source wrapper missing');
$pseudoTree = static function (array $sidecar): array {
    $tree = json_decode($sidecar['content'], true, 512, JSON_THROW_ON_ERROR);
    foreach ($tree['nodes']['p1']['viewports'] as $viewport => $observation) {
        $tree['nodes']['p1']['viewports'][$viewport]['pseudo'] = array('before' => array('content' => '"+"', 'used_styles' => array_replace(box_tree_fixture_styles(), array('display' => 'inline')), 'border_box' => array('x' => 0, 'y' => 0, 'width' => 13, 'height' => 13)));
    }
    return array('path' => $sidecar['path'], 'kind' => $sidecar['kind'], 'content' => (string) json_encode($tree, JSON_THROW_ON_ERROR));
};
$equivalence = array(
    'neutral wrapper' => array(box_tree_fixture_sidecar('index.html', $html, $neutral), BoxTreeWrapperEvidence::NEUTRAL),
    'painted wrapper' => array(box_tree_fixture_sidecar('index.html', $html, array_replace($neutral, array('w' => array('box' => $box, 'styles' => array('background-color' => 'rgb(255, 0, 0)'))))), BoxTreeWrapperEvidence::LOAD_BEARING),
    'pseudo-element styles' => array($pseudoTree(box_tree_fixture_sidecar('index.html', $html, $neutral)), BoxTreeWrapperEvidence::NEUTRAL),
);
foreach ($equivalence as $name => [$v2, $state]) {
    $v3 = box_tree_fixture_v3($v2);
    $v3Tree = json_decode($v3['content'], true, 512, JSON_THROW_ON_ERROR);
    bti_assert(BoxTree::SCHEMA_V3 === $v3Tree['schema'] && is_int($v3Tree['nodes']['w']['viewports']['desktop']['used_styles']), "{$name}: the fixture is not compact v3");
    $read = BoxTree::read(json_decode($v2['content'], true, 512, JSON_THROW_ON_ERROR));
    bti_assert(null !== $read && $read === BoxTree::read($v3Tree), "{$name}: v3 does not expand into the v2 tree");
    $v2Answer = (new BoxTreeWrapperEvidence(json_decode($v2['content'], true, 512, JSON_THROW_ON_ERROR)))->classify($wrapper);
    $v3Answer = (new BoxTreeWrapperEvidence($v3Tree))->classify($wrapper);
    bti_assert($state === $v2Answer['state'] && $v2Answer === $v3Answer, "{$name}: v2 and v3 classify differently: " . json_encode(array($v2Answer, $v3Answer)));
    $v2Markup = $compile(array_merge($files, array($v2)));
    bti_assert($v2Markup === $compile(array_merge($files, array($v3))), "{$name}: v2 and v3 convert differently");
    bti_assert((BoxTreeWrapperEvidence::NEUTRAL === $state) === $folded($v2Markup), "{$name}: the fold does not follow the classification");
}
$v3Tree = json_decode(box_tree_fixture_v3(box_tree_fixture_sidecar('index.html', $html, $neutral))['content'], true, 512, JSON_THROW_ON_ERROR);
$unreadable = array(
    'style index out of range' => (static function (array $tree): array { $tree['nodes']['w']['viewports']['mobile']['used_styles'] = count($tree['styles']); return $tree; })($v3Tree),
    'style index not an integer' => (static function (array $tree): array { $tree['nodes']['w']['viewports']['mobile']['used_styles'] = '0'; return $tree; })($v3Tree),
    'pseudo style index out of range' => (static function (array $tree): array { $tree['nodes']['w']['viewports']['mobile']['pseudo'] = array('after' => array('used_styles' => -1)); return $tree; })($v3Tree),
    'missing styles table' => (static function (array $tree): array { unset($tree['styles']); return $tree; })($v3Tree),
    'unknown schema' => array('schema' => 'blocks-engine/box-tree/v4') + $v3Tree,
);
foreach ($unreadable as $name => $tree) {
    bti_assert(null === BoxTree::read($tree), "{$name}: an unreadable tree was accepted");
    bti_assert(! (new BoxTreeWrapperEvidence($tree))->hasEvidence(), "{$name}: an unreadable tree is evidence");
    $sidecar = array('path' => 'box-tree/index.json', 'kind' => 'json', 'content' => (string) json_encode($tree, JSON_THROW_ON_ERROR));
    bti_assert($withoutEvidence === $compile(array_merge($files, array($sidecar))), "{$name}: an unreadable tree changed the conversion");
}

// 2. Evidence binds the original bytes, not the re-serialized document.
$raw = $page('', '', "<!DOCTYPE html>\n<html style=\"\">");
$rawFiles = array(array('path' => 'index.html', 'kind' => 'html', 'content' => $raw), $css);
$rawWithout = $compile($rawFiles);
bti_assert(! $folded($rawWithout), 'without evidence the re-serialized wrapper must keep its group');
foreach (array('v2' => box_tree_fixture_sidecar('index.html', $raw, $neutral), 'v3' => box_tree_fixture_v3(box_tree_fixture_sidecar('index.html', $raw, $neutral))) as $schema => $sidecar) {
    $markup = $compile(array_merge($rawFiles, array($sidecar)));
    bti_assert($folded($markup), "{$schema}: evidence bound to the original bytes did not fold the neutral wrapper: {$markup}");
}
// The staged transport the importer uses carries the intake digest through
// serialized shared and page plans.
$staged = static function (array $files): string {
    $compiler = new ArtifactCompiler();
    $artifact = array('files' => $files);
    $shared = json_decode((string) json_encode($compiler->prepareShared($artifact)), true);
    $pages = array_map(static fn (array $plan): array => json_decode((string) json_encode($plan), true), $compiler->preparePages($artifact, $shared));
    return (string) $compiler->compose($shared, $compiler->compilePreparedPages($shared, array_values($pages)))->toArray()['serialized_blocks'];
};
bti_assert(! $folded($staged($rawFiles)), 'staged: without evidence the wrapper must keep its group');
$stagedMarkup = $staged(array_merge($rawFiles, array(box_tree_fixture_v3(box_tree_fixture_sidecar('index.html', $raw, $neutral)))));
bti_assert($folded($stagedMarkup), 'staged: evidence bound to the original bytes did not fold the neutral wrapper: ' . $stagedMarkup);
$reserialized = str_replace(array("<!DOCTYPE html>\n", '<html style="">'), array('<!DOCTYPE html>', '<html>'), $raw);
bti_assert($rawWithout === $compile(array_merge($rawFiles, array(box_tree_fixture_sidecar('index.html', $reserialized, $neutral)))), 'evidence bound to other bytes must not change the conversion');

// 3. Ids on every element (Wix).
$ids = static function (string $wrapperAttrs, string $leafAttrs, string $extra = '') use ($page, $css, $neutral, $compile): array {
    $html = $page($wrapperAttrs, $leafAttrs, '<html>', $extra);
    $files = array(array('path' => 'index.html', 'kind' => 'html', 'content' => $html), $css);
    return array($compile($files), $compile(array_merge($files, array(box_tree_fixture_sidecar('index.html', $html, $neutral)))));
};
[, $markup] = $ids(' id="comp-wrapper"', ' id="comp-leaf"');
bti_assert($folded($markup), 'a neutral wrapper whose leaf has its own id did not fold: ' . $markup);
bti_assert(str_contains($markup, '"anchor":"comp-leaf"') && str_contains($markup, 'id="comp-leaf"') && ! str_contains($markup, 'id="comp-wrapper"') && ! str_contains($markup, '"anchor":"comp-wrapper"'), 'the leaf must keep its own id: ' . $markup);
[, $markup] = $ids(' id="comp-wrapper"', '');
bti_assert($folded($markup) && str_contains($markup, '"anchor":"comp-wrapper"'), 'the wrapper id must move onto a leaf without one: ' . $markup);
[$without, $markup] = $ids(' id="comp-same"', ' id="comp-same"');
bti_assert($without === $markup, 'a wrapper id that collides with the leaf id must keep the wrapper');
[$without, $markup] = $ids(' id="comp-wrapper"', ' id="comp-leaf"', '<p><a href="#comp-wrapper">Jump</a></p>');
bti_assert($without === $markup, 'an addressed wrapper id must keep the wrapper');

fwrite(STDOUT, "box-tree-evidence-intake contract passed\n");
