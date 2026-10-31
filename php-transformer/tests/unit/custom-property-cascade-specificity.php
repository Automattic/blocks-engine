<?php
declare(strict_types=1);

/**
 * A custom property resolves to the cascade winner, not the last matching
 * declaration in document order (#1982). The specific override and the
 * component default are swapped between rows; both must resolve to 40px.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$body = '<div id="root"><a href="#x" class="c b">Label<span class="ic x"><svg viewBox="0 0 200 200" width="200" height="200"><rect width="200" height="200" fill="red"/></svg></span></a></div>';
$specific = '#root :where(.c).b .x.x{--size:40px}';
$default = '.x{--size:16px}';
$sizing = '.x svg{width:var(--size);height:var(--size)}';
foreach ( array(
    'default authored last'  => $specific . $sizing . $default,
    'default authored first' => $default . $specific . $sizing,
    'important default wins' => $specific . $sizing . '.x{--size:24px!important}',
) as $case => $css ) {
    $expected = 'important default wins' === $case ? '24px' : '40px';
    $result = (new HtmlTransformer())->transform('<style>' . $css . '</style>' . $body)->toArray();
    preg_match('/be-inline-geometry-[0-9a-f]+/', $result['serialized_blocks'], $carrier);
    $rules = stripslashes(json_encode($result, JSON_UNESCAPED_SLASHES));
    preg_match('/\.' . ($carrier[0] ?? 'missing') . '\{[^}]*--size:\s*([0-9a-z]+)/', $rules, $size);
    if ( $expected !== ($size[1] ?? null) ) {
        fwrite(STDERR, "FAIL: {$case}: --size resolved to " . var_export($size[1] ?? null, true) . ", expected {$expected}\n");
        exit(1);
    }
}
echo "custom property cascade specificity passed\n";
