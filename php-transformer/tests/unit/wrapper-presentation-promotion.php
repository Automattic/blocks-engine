<?php
declare(strict_types=1);

/**
 * Conservative wrapper promotion: transfer representable presentation onto a
 * single child when DOM, layout, runtime, identity, interaction, box-model,
 * and selector equivalence prove the wrapper boundary is unnecessary.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

$transform = static function (string $html, array $options = array()): array {
    return ( new HtmlTransformer() )->transform($html, $options)->toArray();
};

$maxDepth = static function (array $blocks, int $depth = 1) use (&$maxDepth): int {
    $maximum = 0;
    foreach ( $blocks as $block ) {
        if ( ! is_array($block) ) {
            continue;
        }
        $maximum = max($maximum, $depth, $maxDepth(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array(), $depth + 1));
    }

    return $maximum;
};

$image = $transform('<div class="media-shell"><img src="logo.svg" alt="Logo" width="120" height="80"></div>');
$imageBlock = $image['blocks'][0] ?? array();
$assert('core/image' === ($imageBlock['blockName'] ?? null), 'A presentation-only single-image wrapper promotes onto core/image.');
$assert(str_contains((string) ($imageBlock['attrs']['className'] ?? ''), 'media-shell'), 'The wrapper class identity transfers onto the image.');
$assert('pass' === ($image['source_reports']['wp_block_validity']['status'] ?? ''), 'Promoted images remain editor-valid.');

$runtime = $transform('<div class="media-shell" data-sr-id="12"><img src="logo.svg" alt="Logo" width="120" height="80"></div>');
$assert('core/group' === ($runtime['blocks'][0]['blockName'] ?? null), 'A wrapper that owns runtime identity is preserved.');

$descendant = $transform('<style>.media-shell img{border:1px solid red}</style><div class="media-shell"><img src="logo.svg" alt="Logo" width="120" height="80"></div>');
$assert('core/group' === ($descendant['blocks'][0]['blockName'] ?? null), 'A wrapper required by a descendant selector is preserved.');

$flexSvg = $transform('<div style="display:flex;justify-content:center"><img src="logo.svg" alt="Logo" width="40" height="40"></div>');
$assert('core/group' === ($flexSvg['blocks'][0]['blockName'] ?? null), 'A wrapper that owns flex participation is preserved.');

$list = $transform(
    '<style>.row{display:flex}.inner{display:flex;width:100%;align-items:flex-start}.grid{display:grid;gap:1.5rem}</style>'
    . '<ul class="grid">'
    . '<li class="row"><div class="inner"><span>Jan 26</span><div><h3>Office setup</h3><p>Copy</p></div></div></li>'
    . '<li class="row"><div class="inner"><span>Jan 23</span><div><h3>Road trip</h3><p>Copy</p></div></div></li>'
    . '</ul>'
);
$listRoot = $list['blocks'][0] ?? array();
$items = is_array($listRoot['innerBlocks'] ?? null) ? $listRoot['innerBlocks'] : array();
$assert('ul' === ($listRoot['attrs']['tagName'] ?? null) && 2 === count($items), 'Structural lists keep their list host and item count.');
$assert('li' === ($items[0]['attrs']['tagName'] ?? null) && 'core/group' === ($items[0]['blockName'] ?? null), 'The surviving item keeps list semantics.');
$assert(2 === count($items[0]['innerBlocks'] ?? array()), 'The list item absorbs the unary flex restatement and keeps its two content children.');
$assert(str_contains((string) ($items[0]['attrs']['className'] ?? ''), 'inner') && str_contains((string) ($items[0]['attrs']['className'] ?? ''), 'row'), 'List-item and inner flex classes both survive on the promoted item.');
$assert('Office setup' === ($items[0]['attrs']['metadata']['name'] ?? null), 'Promoted list items are named from the heading they own.');
$assert($maxDepth($list['blocks'] ?? array()) <= 5, 'Unary list-item flex restatement does not add an extra editor depth.');
$assert('pass' === ($list['source_reports']['wp_block_validity']['status'] ?? ''), 'Promoted list items remain editor-valid.');

$selectorEdge = $transform(
    '<style>.row{display:flex}.inner{display:flex;width:100%}li > .inner{margin-left:8px}.grid{display:grid}</style>'
    . '<ul class="grid"><li class="row"><div class="inner"><span>Jan 26</span><div><h3>Office setup</h3><p>Copy</p></div></div></li></ul>'
);
$selectorItem = $selectorEdge['blocks'][0]['innerBlocks'][0] ?? array();
$assert(
    'core/group' === ($selectorItem['blockName'] ?? null)
    && 1 === count($selectorItem['innerBlocks'] ?? array()),
    'A child-combinator selector that cannot survive promotion keeps the inner wrapper.'
);

$stackedFlexItem = $transform('<div style="display:flex"><div><p>A</p><p>B</p></div></div>');
$assert(2 === substr_count((string) ($stackedFlexItem['serialized_blocks'] ?? ''), '<!-- wp:group'), 'A flex item wrapper around stacked content remains a direct child.');

if ( 0 < $failures ) {
    fwrite(STDERR, sprintf('wrapper presentation promotion: %d passed, %d failed%s', $passes, $failures, PHP_EOL));
    exit(1);
}

fwrite(STDOUT, sprintf('wrapper presentation promotion tests: %d passed%s', $passes, PHP_EOL));
