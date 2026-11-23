<?php
declare(strict_types=1);

/**
 * ADR 0006 slice 1: box-tree evidence decides which wrappers collapse.
 *
 * One table drives the evidence reader and the compiler together. A wrapper
 * proven neutral at every captured viewport folds into its child even though
 * an author child-combinator rule stops matching, and that rule is restated
 * on an engine-owned carrier. Each refusal reason (paint, containing block,
 * inset, hidden at one viewport, unequal child box, missing evidence) keeps
 * the wrapper and converts exactly as without evidence. Capture identity
 * never reaches the output.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/box-tree-evidence-fixture.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\BoxTreeWrapperEvidence;

function btn_assert(bool $condition, string $message): void
{
    if (! $condition) {
        fwrite(STDERR, "box-tree-neutral-wrapper-collapse: {$message}\n");
        exit(1);
    }
}

$html = '<html><head><link rel="stylesheet" href="s.css"></head><body>'
    . '<header><p>Head</p></header><main><p>Intro</p>'
    . '<div class="outer" data-blocks-engine-node-id="w"><div class="inner" data-blocks-engine-node-id="c">'
    . '<p data-blocks-engine-node-id="p1">One</p><p data-blocks-engine-node-id="p2">Two</p>'
    . '</div></div><p>Outro</p></main></body></html>';
$css = '.outer > .inner{color:red}';
$files = array(
    array('path' => 'index.html', 'kind' => 'html', 'content' => $html),
    array('path' => 's.css', 'kind' => 'css', 'content' => $css),
);
$box = array(8, 120, 1424, 200);
$neutral = array('w' => array('box' => $box), 'c' => array('box' => $box), 'p1' => array(), 'p2' => array());

$cases = array(
    'neutral' => array($neutral, BoxTreeWrapperEvidence::NEUTRAL, 'neutral'),
    'paint' => array(array_replace($neutral, array('w' => array('box' => $box, 'styles' => array('background-color' => 'rgb(255, 0, 0)')))), BoxTreeWrapperEvidence::LOAD_BEARING, 'paint:background-color'),
    'containing block' => array(array_replace($neutral, array(
        'w' => array('box' => $box, 'styles' => array('position' => 'relative')),
        'p1' => array('styles' => array('position' => 'absolute')),
    )), BoxTreeWrapperEvidence::LOAD_BEARING, 'containing_block'),
    'inset' => array(array_replace($neutral, array('w' => array('box' => $box, 'styles' => array('position' => 'relative', 'top' => '10px')))), BoxTreeWrapperEvidence::INSET_ONLY, 'inset:top'),
    'hidden at one viewport' => array(array_replace($neutral, array('w' => array('box' => $box, 'viewports' => array('mobile' => array('box' => array(0, 0, 0, 0), 'styles' => array('display' => 'none')))))), BoxTreeWrapperEvidence::LOAD_BEARING, 'hidden:mobile'),
    'unequal child box' => array(array_replace($neutral, array('c' => array('box' => array(8, 120, 1400, 200)))), BoxTreeWrapperEvidence::LOAD_BEARING, 'child_box_differs:desktop'),
    'missing evidence' => array(array('c' => array('box' => $box), 'p1' => array(), 'p2' => array()), BoxTreeWrapperEvidence::LOAD_BEARING, 'no_evidence'),
);

$compile = static function (array $files): array {
    $result = (new ArtifactCompiler())->compile(array('files' => $files))->toArray();
    $css = '';
    foreach ($result['assets'] as $asset) {
        if ('css' === ($asset['kind'] ?? '') && is_string($asset['content'] ?? null)) $css .= $asset['content'] . "\n";
    }
    return array('markup' => (string) $result['serialized_blocks'], 'css' => $css);
};
$withoutEvidence = $compile($files);
btn_assert(str_contains($withoutEvidence['markup'], '<!-- wp:group {"className":"outer"} -->') && str_contains($withoutEvidence['markup'], '<!-- wp:group {"className":"inner"} -->'), 'without evidence the wrapper keeps its own group');

$source = new DOMDocument();
@$source->loadHTML($html);
$wrapper = (new DOMXPath($source))->query('//div[@class="outer"]')->item(0);
btn_assert($wrapper instanceof DOMElement, 'source wrapper missing');

foreach ($cases as $name => [$nodes, $state, $reason]) {
    $sidecar = box_tree_fixture_sidecar('index.html', $html, $nodes);
    $answer = (new BoxTreeWrapperEvidence(json_decode($sidecar['content'], true, 512, JSON_THROW_ON_ERROR)))->classify($wrapper);
    btn_assert(array('state' => $state, 'reason' => $reason) === $answer, "{$name}: classified " . json_encode($answer));

    $output = $compile(array_merge($files, array($sidecar)));
    btn_assert(! str_contains($output['markup'] . $output['css'], 'data-blocks-engine-node-id'), "{$name}: capture identity reached the output");
    if (BoxTreeWrapperEvidence::NEUTRAL !== $state) {
        btn_assert($withoutEvidence === $output, "{$name}: a refused wrapper must convert exactly as without evidence");
        continue;
    }
    btn_assert(3 === substr_count($output['markup'], '<!-- wp:group') && ! str_contains($output['markup'], '{"className":"outer"}'), "{$name}: the neutral wrapper did not fold: " . $output['markup']);
    btn_assert((bool) preg_match('/<!-- wp:group \{"className":"outer inner (be-inline-geometry-[a-f0-9]{16})"\} -->/', $output['markup'], $carrier), "{$name}: the merged group lacks the wrapper class or its engine carrier");
    btn_assert((bool) preg_match('/\.' . preg_quote($carrier[1], '/') . '\{color:red\s*!important;?\}/', $output['css']), "{$name}: the lost child-combinator rule is not restated on the carrier");
    btn_assert(str_contains($output['markup'], '<p>One</p>') && str_contains($output['markup'], '<p>Two</p>'), "{$name}: the folded content changed");
}

// A stale sidecar (its page hash no longer matches) is no evidence.
$stale = box_tree_fixture_sidecar('index.html', $html . ' ', $neutral);
btn_assert($withoutEvidence === $compile(array_merge($files, array($stale))), 'stale evidence must not change the conversion');

fwrite(STDOUT, "box-tree-neutral-wrapper-collapse contract passed\n");
