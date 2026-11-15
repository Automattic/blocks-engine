<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder;

$html = '<form method="post"><div class="fields"><div><label for="name">Name</label><input id="name" name="name"></div><div><label for="email">Email</label><input id="email" name="email" type="email"></div></div><button type="submit">Send</button></form>';
$baseCss = 'form, .fields, .fields > div { display:flex; gap:12px; color:#123456; } label { font-size:14px; } input { padding:8px; }';
$css = $baseCss;
for ($i = 0; $i < 500; ++$i) $css .= ':host(.unrelated-' . $i . ') { color:red; }';
$result = (new HtmlTransformer())->transform($html, array('static_css' => $css))->toArray();
$graph = $result['fallbacks'][0]['layout_graph'];
if (count($graph['diagnostics']) > 9 || !in_array('unsupported_selectors:500', $graph['diagnostics'], true)) throw new RuntimeException('Stylesheet diagnostics must contain one count and at most eight samples.');
FormLayoutGraphBuilder::assertValid($graph);
$strip = static function (array $value) use (&$strip): array {
    unset($value['diagnostics'], $value['metrics']);
    foreach ($value as &$item) if (is_array($item)) $item = $strip($item);
    return $value;
};
$hash = hash('sha256', json_encode($strip($result), JSON_THROW_ON_ERROR));
if ('c1d2fae1bb1abe7d03b9c457e9f7e5e39b82bdca7cd8262af17bf6c3cad434a9' !== $hash) throw new RuntimeException('Non-diagnostic output must retain form intent and the control-row presentation baseline: ' . $hash);
$invalid = $graph;
$invalid['diagnostics'][] = 'unsupported_selector::host(.extra)';
try {
    FormLayoutGraphBuilder::assertValid($invalid);
    throw new RuntimeException('Validator must reject more than eight samples.');
} catch (InvalidArgumentException) {
}
echo "form layout stylesheet diagnostic budget passed\n";
