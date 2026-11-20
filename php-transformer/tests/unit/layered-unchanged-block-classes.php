<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$transform = static function (string $rules, bool $shell = true): string {
    $result = (new HtmlTransformer())->transform('<style>' . $rules . '</style><footer><div class="layout"><p class="brand">Example <span>Atelier</span></p><p>Contact us</p></div></footer>', array('project_only_matched_author_rules' => $shell))->toArray();
    return implode("\n", array_column($result['assets'], 'content'));
};
$rules = '.layout{display:grid;gap:40px;padding:56px 20px}.brand{letter-spacing:.25em;line-height:32px}';
$css = $transform('@layer utilities{' . $rules . '}');
foreach (array('display', 'gap', 'padding', 'letter-spacing', 'line-height') as $property) {
    if (!str_contains($css, $property . ':revert-layer')) {
        throw new RuntimeException('Unchanged authored block classes must hand ' . $property . ' back to their source layer.');
    }
}
if (!str_contains($css, ':is(.brand):where(.wp-block-group,.wp-block-heading,.wp-block-list,.wp-block-image,p)')) {
    throw new RuntimeException('The bridge must target block hosts, not inline descendants.');
}
if (str_contains($transform($rules), 'revert-layer')) {
    throw new RuntimeException('Unlayered rules need no layer bridge.');
}
$contested = $transform('@layer utilities{' . $rules . '}.brand:hover{letter-spacing:.5em}');
if (str_contains($contested, 'letter-spacing:revert-layer')) {
    throw new RuntimeException('Unlayered state declarations must veto the bridge.');
}
// The same author stylesheet on an ordinary route must not gain a blanket
// utility-class reset. Its source classes are not a shared-shell boundary.
$route = $transform('@layer utilities{' . $rules . '}', false);
if (str_contains($route, ':is(.brand):where(') || str_contains($route, ':is(.layout):where(')) {
    throw new RuntimeException('Shared-shell bridges must not restyle ordinary route subjects.');
}
echo "Layered unchanged block classes: passed\n";
