<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A `button` reset restated on the core/button link gains two class weights
// over its source specificity. Where a class rule wins a property on the source
// button (a skip link's padding), the boosted restatement must leave that
// property to the class rule; every other button still gets the reset.
$html = '<style>button{padding:0;border:0;background:none}'
    . '.skip{padding:0 24px;background:#fff;color:#116dff;border-radius:24px;position:absolute;top:60px;left:50%;width:0;height:0;margin-left:-94px}</style>'
    . '<main><button class="skip">Skip to Main Content</button><p>Body</p><button class="plain">Open</button></main>';
$result = (new HtmlTransformer())->transform($html, array())->toArray();
$css = '';
array_walk_recursive(
    $result,
    static function ($value, $key) use (&$css): void {
        if ('content' === $key && is_string($value)) {
            $css .= $value;
        }
    }
);
preg_match_all('/([^{}]*\.blocks-engine-role-control\.blocks-engine-role-control[^{}]*|[^{}]*\.(blocks-engine-control-[0-9a-f]+-0)\.\2[^{}]*)\{([^}]*)\}/', $css, $rules, PREG_SET_ORDER);
$restated = array();
foreach ($rules as $rule) {
    $restated[] = array('selector' => $rule[1], 'body' => $rule[3]);
}
preg_match('/<div class="wp-block-button skip (blocks-engine-control-[0-9a-f]+-0)/', $result['serialized_blocks'], $skip);
$skipMarker = $skip[1] ?? '';
if ('' === $skipMarker) {
    throw new RuntimeException('The skip button must be converted to a marked core/button');
}
$padding = array_filter($restated, static fn (array $rule): bool => str_contains($rule['body'], 'padding:0'));
if (array() === $padding) {
    throw new RuntimeException('The reset must still be restated for buttons it wins on');
}
foreach ($padding as $rule) {
    if (str_contains($rule['selector'], $skipMarker) || str_contains($rule['selector'], 'blocks-engine-role-control.blocks-engine-role-control')) {
        throw new RuntimeException('The boosted reset must not override the skip link class padding: ' . $rule['selector']);
    }
}
if (array() === array_filter($restated, static fn (array $rule): bool => str_contains($rule['body'], 'border:0'))) {
    throw new RuntimeException('Reset longhands no class rule wins must still be restated');
}
echo "native-button-reset-restatement-specificity: ok\n";
