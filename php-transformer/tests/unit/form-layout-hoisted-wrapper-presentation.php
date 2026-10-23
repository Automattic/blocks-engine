<?php
declare(strict_types=1);

/**
 * A field wrapper restored by the field-group spacing hoist (through
 * ensureAncestorNodes()) carries resolved presentation like every other
 * restored container ancestor. Layered `display:grid` (Tailwind v4
 * `@layer utilities`) stays out of `layout`, so `presentation` is the only
 * place a consumer can see that the wrapper is a grid container.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$document = new DOMDocument();
$document->loadHTML('<?xml encoding="utf-8" ?><body><form class="grid gap-5"><div class="grid gap-2"><label for="m">Message</label><textarea id="m" rows="4" class="w-full"></textarea></div></form></body>', LIBXML_NOERROR | LIBXML_NOWARNING);
$form = $document->getElementsByTagName('form')->item(0);
$graph = (new FormLayoutGraphBuilder())->build($form, array(), '@layer utilities{.grid{display:grid}.gap-2{gap:8px}.gap-5{gap:20px}.w-full{width:100%}}');
$nodes = array_column($graph['nodes'], null, 'id');

$control = null;
foreach ( $nodes as $node ) {
    if ( 'control' === $node['kind'] && 'textarea' === ($node['source']['tag'] ?? null) ) {
        $control = $node;
        break;
    }
}
$assert(null !== $control, 'the textarea is a layout graph node', json_encode($graph['nodes']));
$assert('8px' === ($control['layout']['margin_block_start'] ?? null), 'the field-group gap is hoisted onto the control', json_encode($control['layout'] ?? null));
$wrapper = $nodes[$control['parent'] ?? ''] ?? null;
$assert(null !== $wrapper && 'container' === $wrapper['kind'] && 'div' === ($wrapper['source']['tag'] ?? null), 'the control parent is its source wrapper', json_encode($wrapper));
$assert('grid' === ($wrapper['presentation']['styles']['display'] ?? null), 'the hoist-restored wrapper carries layered display:grid on presentation', json_encode($wrapper['presentation'] ?? null));
$assert(! isset($wrapper['layout']['display']), 'layered display stays out of the wrapper layout', json_encode($wrapper['layout'] ?? null));

if ( 0 < $failures ) {
    fwrite(STDERR, "form layout hoisted wrapper presentation FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "form layout hoisted wrapper presentation passed: {$passes} assertions\n";
