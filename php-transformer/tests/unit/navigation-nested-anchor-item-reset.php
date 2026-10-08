<?php
declare(strict_types=1);

/** A source wrapper is an independent box, not a second copy of anchor paint on Core's LI. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$document = static fn(string $wrapperClass, string $extra = '', bool $wrapped = true): string =>
    '<style>div,span,a,ul,li{background:0 0;border:0;margin:0;padding:0}.menu-list{display:flex}'
    . '#menu .menu-root .menu-item{padding:10px;margin:4px 6px}' . $extra . '</style>'
    . '<header><div id="menu"><nav class="menu-root"><ul class="menu-list"><li class="item-wrapper">'
    . ($wrapped ? '<div class="' . $wrapperClass . '">' : '')
    . '<a class="menu-item" href="/vision">Vision</a>'
    . ($wrapped ? '</div>' : '') . '</li></ul></nav></div></header>';
$link = static function (array $result): array {
    preg_match('/<!-- wp:navigation-link (\{.*?\}) \/-->/s', $result['serialized_blocks'], $match);
    return json_decode($match[1] ?? '', true) ?: array();
};
$css = static fn(array $result): string => implode("\n", array_column($result['assets'] ?? array(), 'content'));
$anchorOwns = static function (array $result) use ($link, $css): bool {
    $attrs = $link($result);
    preg_match('/blocks-engine-navigation-anchor-[a-f0-9]{12}-\d+/', $attrs['className'] ?? '', $match);
    if (!isset($match[0])) return false;
    return 1 === preg_match('/' . preg_quote($match[0], '/') . '[^{]*:where\(\.wp-block-navigation-item__content\)[^{]*\{padding:10px\}/', $css($result))
        && 1 === preg_match('/' . preg_quote($match[0], '/') . '[^{]*:where\(\.wp-block-navigation-item__content\)[^{]*\{margin:4px 6px\}/', $css($result));
};

$nested = (new HtmlTransformer())->transform($document('item-container'))->toArray();
$nestedAttrs = $link($nested);
$assert($anchorOwns($nested), 'The actual emitted anchor subject owns both authored padding and margin.');
$assert('menu-item' === ($nestedAttrs['metadata']['blocksEngineNavigationAnchor']['className'] ?? '') && !preg_match('/(?:^|\s)menu-item(?:\s|$)/', $nestedAttrs['className'] ?? ''), 'Anchor identity is retained on the native anchor and absent from the Core item.');
$boxes = $nestedAttrs['metadata']['blocksEngineNavigationAnchor']['boxes'] ?? array();
$assert(1 === count($boxes) && 'div' === $boxes[0]['tag'] && str_contains($css($nested), '.' . $boxes[0]['marker']), 'The original passive wrapper survives as its own stylesheet-addressable native box.');

$classed = (new HtmlTransformer())->transform($document('item-container menu-item'))->toArray();
$classedBoxes = $link($classed)['metadata']['blocksEngineNavigationAnchor']['boxes'] ?? array();
$assert($anchorOwns($classed), 'An anchor keeps its authored box when its wrapper also matches the same rule.');
$assert(1 === count($classedBoxes) && 1 === preg_match('/' . preg_quote($classedBoxes[0]['marker'], '/') . '[^{]*\{padding:10px\}/', $css($classed)), 'A wrapper sharing the styling class keeps its independent source paint.');

$painted = (new HtmlTransformer())->transform($document('item-container', '.item-container{padding:2px}'))->toArray();
$paintedBoxes = $link($painted)['metadata']['blocksEngineNavigationAnchor']['boxes'] ?? array();
$assert($anchorOwns($painted), 'A painted wrapper does not consume the anchor padding or margin.');
$assert(1 === count($paintedBoxes) && 1 === preg_match('/' . preg_quote($paintedBoxes[0]['marker'], '/') . '[^{]*\{padding:2px\}/', $css($painted)), 'Overlapping wrapper padding stays independently owned without a scalar reset.');

$flat = (new HtmlTransformer())->transform($document('', '', false))->toArray();
$assert($anchorOwns($flat) && !isset($link($flat)['metadata']['blocksEngineNavigationAnchor']['boxes']), 'A direct anchor retains the same subject contract and introduces no wrapper.');
$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $document('item-container'))))->toArray();
$compiledCss = $css($compiled);
$assert(str_contains($compiledCss, 'blocks-engine-navigation-anchor-') && str_contains($compiledCss, 'blocks-engine-navigation-box-') && str_contains($compiledCss, '{padding:10px}'), 'The canonical artifact import entry point projects the same independent anchor and wrapper subjects.');
echo "Navigation nested anchor subject ownership passed: 9 assertions\n";
