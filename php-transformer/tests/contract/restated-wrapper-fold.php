<?php
declare(strict_types=1);

/**
 * #7505 (#7471 stage 2): a geometry- and paint-neutral single-child wrapper
 * kept only because emitted CSS consumes its class or id folds into its
 * child, which takes the wrapper's classes and id.
 *
 * - Without box-tree evidence the wrapper is kept, as before.
 * - With neutral evidence it folds: the child carries the wrapper's class
 *   and anchor, and a selector that reached the child through the wrapper
 *   (`.col .inner`) gains the compound arm (`.col.inner`) in the same rule.
 * - An inherited text declaration the child already overrides folds; one
 *   that would beat the child's own declaration keeps the wrapper.
 * - Painted evidence and a contested box declaration keep the wrapper, and
 *   each refusal names its condition.
 * - The node-id carrier never reaches an emitted artifact, and a route
 *   whose wrappers do not fold emits what it emits without evidence.
 * - A non-group child takes the class; an inert data attribute and an
 *   unreferenced id are dropped; a full-width float is neutral; only two
 *   referenced ids keep the wrapper.
 * - A data attribute only author CSS reads projects to an engine-owned
 *   class on the child, and each rule reading it gains the arm naming it.
 * - A child block converted from a deeper element folds when the elements
 *   between are a sole-child chain already folded into it.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/box-tree-evidence-fixture.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\RestatedWrapperFold;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\WrapperDecision;

function rwf_assert(bool $condition, string $message): void
{
    if (! $condition) {
        fwrite(STDERR, "restated-wrapper-fold: {$message}\n");
        exit(1);
    }
}

$box = array(8, 120, 1424, 200);
$page = static fn (string $wrapperAttrs, string $leafAttrs): string => '<html><head><link rel="stylesheet" href="s.css"></head><body>'
    . '<header><p>Head</p></header><main><p>Intro</p>'
    . '<div class="col" data-blocks-engine-node-id="w"' . $wrapperAttrs . '><div class="inner" data-blocks-engine-node-id="c"' . $leafAttrs . '>'
    . '<p data-blocks-engine-node-id="p1">One</p><p data-blocks-engine-node-id="p2">Two</p>'
    . '</div></div><p>Outro</p></main></body></html>';
$neutral = array('w' => array('box' => $box), 'c' => array('box' => $box), 'p1' => array(), 'p2' => array());
$compile = static function (string $html, string $css, ?array $nodes): array {
    $files = array(array('path' => 'index.html', 'kind' => 'html', 'content' => $html), array('path' => 's.css', 'kind' => 'css', 'content' => $css));
    if (null !== $nodes) $files[] = box_tree_fixture_sidecar('index.html', $html, $nodes);
    $result = (new ArtifactCompiler())->compile(array('files' => $files))->toArray();
    $styles = '';
    array_walk_recursive($result, static function (mixed $value, mixed $key) use (&$styles): void {
        if (is_string($value) && str_contains($value, '{') && str_contains($value, '.inner')) $styles .= "\n" . $value;
    });
    return array('markup' => (string) $result['serialized_blocks'], 'styles' => $styles);
};
$wrapperKept = static fn (string $markup): bool => 1 === preg_match('/<div[^>]*class="[^"]*\bwp-block-group\b[^"]*\bcol\b(?![^"]*\binner\b)[^"]*"/', $markup);
$folded = static fn (string $markup): bool => 1 === preg_match('/<div[^>]*class="(?=[^"]*\bcol\b)(?=[^"]*\binner\b)[^"]*"/', $markup) && ! $wrapperKept($markup);

// 1. Without evidence the wrapper stays.
$css = '.col{font-family:serif}.col .inner{padding:1px}';
$html = $page('', '');
$before = $compile($html, $css, null);
rwf_assert($wrapperKept($before['markup']), 'without evidence the consumed wrapper should be kept: ' . $before['markup']);

// 2. With neutral evidence it folds; the class moves, the descendant rule gains its compound arm.
$after = $compile($html, $css, $neutral);
rwf_assert($folded($after['markup']), 'a neutral class-only wrapper should fold into its child: ' . $after['markup']);
rwf_assert(1 === preg_match('/\.col\S*\.inner|\.inner\S*\.col/', $after['styles']), 'the descendant rule should gain the compound arm: ' . $after['styles']);

// 3. The id moves onto a child without one.
$withId = $compile($page(' id="section-1"', ''), $css . '#section-1{color:blue}', array_replace($neutral));
rwf_assert($folded($withId['markup']) && str_contains($withId['markup'], '"anchor":"section-1"'), 'the wrapper id should become the child anchor: ' . $withId['markup']);

// 4. Inherited text the child overrides folds; one beating the child's own declaration does not.
$overridden = $compile($html, '.col{color:red}.col .inner{color:blue}', $neutral);
rwf_assert($folded($overridden['markup']), 'an overridden inherited declaration should fold: ' . $overridden['markup']);
$beating = $compile($html, '.col{color:red !important}.inner{color:blue}', $neutral);
rwf_assert($wrapperKept($beating['markup']), 'an inherited declaration that would beat the child should keep the wrapper: ' . $beating['markup']);

// 5. Refusals keep the wrapper.
$painted = array_replace($neutral, array('w' => array('box' => $box, 'styles' => array('background-color' => 'rgb(255, 0, 0)'))));
rwf_assert($wrapperKept($compile($html, $css, $painted)['markup']), 'a painted wrapper should be kept');
rwf_assert($wrapperKept($compile($html, '.col{margin-top:4px}.inner{margin-top:8px}', $neutral)['markup']), 'a contested box declaration should keep the wrapper');

// 6. The capture node-id carrier never reaches an emitted artifact, and a
//    route whose wrappers do not fold emits what it emits without evidence.
$emitted = static function (string $html, string $css, ?array $nodes): array {
    $files = array(array('path' => 'index.html', 'kind' => 'html', 'content' => $html), array('path' => 's.css', 'kind' => 'css', 'content' => $css));
    if (null !== $nodes) $files[] = box_tree_fixture_sidecar('index.html', $html, $nodes);
    $result = (new ArtifactCompiler())->compile(array('files' => $files))->toArray();
    $strings = array();
    array_walk_recursive($result, static function (mixed $value, mixed $key) use (&$strings, $html): void {
        // The source document itself is echoed as input, not emitted.
        if (is_string($value) && $value !== $html && ! str_contains($value, '"schema":"blocks-engine/box-tree/')) $strings[] = $value;
    });
    return array('markup' => (string) $result['serialized_blocks'], 'strings' => $strings);
};
foreach (array('folded' => $neutral, 'kept' => $painted) as $case => $nodes) {
    foreach ($emitted($html, $css, $nodes)['strings'] as $value) {
        rwf_assert(! str_contains($value, 'data-blocks-engine-node-id'), "the node-id carrier leaked into an emitted artifact ({$case}): " . substr($value, 0, 300));
    }
}
rwf_assert($compile($html, $css, $painted) === $before, 'a route whose wrapper does not fold should emit exactly what it emits without evidence');

// 7. A sole child that is not a group takes the wrapper's class.
$paragraphPage = '<html><head><link rel="stylesheet" href="s.css"></head><body><header><p>Head</p></header><main><p>Intro</p>'
    . '<div class="col" data-blocks-engine-node-id="w"><p class="inner" data-blocks-engine-node-id="c">Lead</p></div><p>Outro</p></main></body></html>';
$paragraph = $compile($paragraphPage, '.col{font-family:serif}.col .inner{padding:1px}', array('w' => array('box' => $box), 'c' => array('box' => $box)));
rwf_assert(1 === preg_match('/<p[^>]*class="(?=[^"]*\bcol\b)(?=[^"]*\binner\b)[^"]*"[^>]*>Lead/', $paragraph['markup']) && ! $wrapperKept($paragraph['markup']), 'a paragraph child should take the wrapper class: ' . $paragraph['markup']);

// 8. An inert data attribute is dropped with the wrapper; one a selector reads keeps it.
$inert = $compile($page(' data-section-label="hero"', ''), $css, $neutral);
rwf_assert($folded($inert['markup']) && ! str_contains($inert['markup'], 'hero'), 'an inert data attribute should be dropped with the wrapper: ' . $inert['markup']);
// A data attribute only author CSS reads is projected to an engine-owned
// class on the child; the rule reading it gains the arm naming that class.
$read = $compile($page(' data-tone="dark"', ''), $css . '[data-tone] .inner{color:red}', $neutral);
rwf_assert($folded($read['markup']) && ! str_contains($read['markup'], 'data-tone'), 'a data attribute only CSS reads should be projected, not keep the wrapper: ' . $read['markup']);
rwf_assert(1 === preg_match('/class="[^"]*\binner\b[^"]*\b(blocks-engine-attribute-[a-f0-9]+-\d+)\b/', $read['markup'], $projected) && 1 === preg_match('/\.' . preg_quote($projected[1], '/') . '\.inner\b/', $read['styles']), 'the projected class should carry the descendant rule: ' . $read['markup'] . $read['styles']);

// 9. An unreferenced wrapper id is dropped, even when the child has its own id.
$unreferenced = $compile($page(' id="a"', ' id="b"'), $css, $neutral);
rwf_assert($folded($unreferenced['markup']) && ! str_contains($unreferenced['markup'], '"anchor":"a"'), 'an unreferenced wrapper id should be dropped: ' . $unreferenced['markup']);
// A referenced wrapper id replaces an unreferenced child id; two referenced ids keep the wrapper.
$replaced = $compile($page(' id="a"', ' id="b"'), $css . '#a{color:red}', $neutral);
rwf_assert($folded($replaced['markup']) && str_contains($replaced['markup'], '"anchor":"a"'), 'a referenced wrapper id should replace an unreferenced child id: ' . $replaced['markup']);
rwf_assert($wrapperKept($compile($page(' id="a"', ' id="b"'), $css . '#a{color:red}#b{color:blue}', $neutral)['markup']), 'a wrapper whose id cannot move should be kept');

// 10. A float that fills its parent's content box, as its child does, is neutral.
$floatPage = str_replace('<main>', '<main data-blocks-engine-node-id="m">', $html);
$floating = array_replace($neutral, array('m' => array('box' => $box), 'w' => array('box' => $box, 'styles' => array('float' => 'left'))));
rwf_assert($folded($compile($floatPage, '.col{float:left}.col .inner{padding:1px}', $floating)['markup']), 'a full-width float should fold');
$narrowParent = array_replace($floating, array('m' => array('box' => array(8, 120, 1424, 400))));
rwf_assert($wrapperKept($compile($floatPage, '.col{float:left}.col .inner{padding:1px}', $narrowParent)['markup']), 'a float that does not fill its parent should be kept');

// 11. A universal pseudo-element rule that already reached the child does not keep the wrapper.
rwf_assert($folded($compile($html, '*::selection{background:yellow}' . $css, $neutral)['markup']), 'a universal ::selection rule should not keep the wrapper');

// 12. Consumed attribute selectors: a wrapper attribute author CSS reads
//     projects to an engine-owned class even when the child carries the
//     same attribute; the wrapper rule and the child rule both gain the arm.
$both = $compile($page(' data-testid="hero"', ' data-testid="card"'), $css . '[data-testid="hero"]{margin-top:0}[data-testid="hero"]>.inner{color:red}[data-testid="card"]{border:0}', $neutral);
rwf_assert($folded($both['markup']), 'a wrapper attribute the child also carries should be projected: ' . $both['markup']);
preg_match_all('/blocks-engine-attribute-[a-f0-9]+-\d+/', $both['markup'], $classes);
$armed = array_values(array_filter(array_unique($classes[0]), static fn (string $class): bool => 1 === preg_match('/\.' . preg_quote($class, '/') . '\.inner\b/', $both['styles'])));
rwf_assert(1 === count($armed) && 1 === preg_match('/\.' . preg_quote($armed[0], '/') . '(?![\w-])(?!\.inner)[^{,]*\{margin-top:0/', $both['styles']), 'the subject rule and the child rule should gain the projected class arm: ' . $both['styles']);
// A projected attribute read inside a functional pseudo-class is not proven.
rwf_assert($wrapperKept($compile($page(' data-tone="dark"', ''), $css . '.col:not([data-tone="light"]) .inner{color:red}', $neutral)['markup']), 'an attribute read inside :not() should keep the wrapper');

// 13. A child block converted from a deeper element folds when the elements
//     between are a sole-child chain already folded into it; the proof runs
//     against that element.
$chainPage = '<html><head><link rel="stylesheet" href="s.css"></head><body><header><p>Head</p></header><main><p>Intro</p>'
    . '<div class="a" data-blocks-engine-node-id="w1"><div class="b" data-blocks-engine-node-id="w2"><div class="inner" data-blocks-engine-node-id="c">'
    . '<p data-blocks-engine-node-id="p1">One</p><p data-blocks-engine-node-id="p2">Two</p></div></div></div><p>Outro</p></main></body></html>';
$chainNodes = array('w1' => array('box' => $box), 'w2' => array('box' => $box), 'c' => array('box' => $box), 'p1' => array(), 'p2' => array());
$chainCss = '.a{margin-top:0}.b{margin-bottom:0}.a .inner{padding:1px}.b .inner{border:0}';
$chainKept = $compile($chainPage, $chainCss, null);
rwf_assert(1 === preg_match('/class="wp-block-group a"/', $chainKept['markup']), 'without evidence the outer wrapper of a folded chain should be kept: ' . $chainKept['markup']);
$chain = $compile($chainPage, $chainCss, $chainNodes);
rwf_assert(1 === preg_match('/<div[^>]*class="(?=[^"]*\ba\b)(?=[^"]*\bb\b)(?=[^"]*\binner\b)[^"]*"/', $chain['markup']) && ! str_contains($chain['markup'], 'class="wp-block-group a"'), 'the outer wrapper should fold into the block its folded chain produced: ' . $chain['markup']);
rwf_assert(str_contains($chain['styles'], '.a.inner'), 'the outer wrapper rule should gain its compound arm against the deeper element: ' . $chain['styles']);
// The chain proof still refuses what the deeper element cannot take.
rwf_assert(str_contains($compile($chainPage, $chainCss . '.a{margin-bottom:4px}', $chainNodes)['markup'], 'class="wp-block-group a"'), 'a declaration contested by the folded chain should keep the outer wrapper');

// 14. Every refusal is a named, staged reason.
$stages = WrapperDecision::loweringGuardStages()['restated_wrapper_fold'] ?? array();
foreach (array('no_box_tree_evidence', 'geometry_not_neutral', 'child_has_own_id', 'child_block_cannot_take_class', 'child_has_moved_attribute', RestatedWrapperFold::INHERITED_TEXT_OVERRIDE, RestatedWrapperFold::WRAPPER_DECLARATION_CONTESTED, RestatedWrapperFold::SELECTOR_MATCH_CHANGED) as $reason) {
    rwf_assert(in_array($stages[$reason] ?? null, WrapperDecision::STAGES, true), "refusal {$reason} should be a staged guard");
}

echo "restated-wrapper-fold: ok\n";
