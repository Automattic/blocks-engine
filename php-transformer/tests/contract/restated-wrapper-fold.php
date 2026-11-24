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
 * - Painted evidence, a child with its own id and a contested box
 *   declaration keep the wrapper, and each refusal names its condition.
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
rwf_assert($wrapperKept($compile($page(' id="a"', ' id="b"'), $css . '#a{color:red}', $neutral)['markup']), 'a wrapper whose id cannot move should be kept');
rwf_assert($wrapperKept($compile($html, '.col{margin-top:4px}.inner{margin-top:8px}', $neutral)['markup']), 'a contested box declaration should keep the wrapper');

// 6. Every refusal is a named, staged reason.
$stages = WrapperDecision::loweringGuardStages()['restated_wrapper_fold'] ?? array();
foreach (array('no_box_tree_evidence', 'geometry_not_neutral', 'child_has_own_id', RestatedWrapperFold::INHERITED_TEXT_OVERRIDE, RestatedWrapperFold::WRAPPER_DECLARATION_CONTESTED, RestatedWrapperFold::SELECTOR_MATCH_CHANGED) as $reason) {
    rwf_assert(in_array($stages[$reason] ?? null, WrapperDecision::STAGES, true), "refusal {$reason} should be a staged guard");
}

echo "restated-wrapper-fold: ok\n";
