<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$out = (new HtmlTransformer())->transform('<style>*{border-width:0;border-style:solid;border-color:#ccc}button{background:transparent}.row{display:flex;flex-direction:column}@media(min-width:640px){.row{flex-direction:row}}.cta{display:inline-flex;padding:12px;background:white}.back{display:flex;padding:4px}</style><div class="row"><a class="cta" href="/one">One</a><a class="cta" href="/two">Two</a></div><div class="row"><button class="back">Back</button></div>')->toArray();
$css = implode('', array_map(static fn(array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string)($asset['content'] ?? '') : '', $out['assets'] ?? array()));
foreach (array(
    'border-width:0!important' => 'zero-width solid reset must suppress theme outline',
    'border-radius:0!important' => 'reset native button must retain square corners',
    'width:auto!important' => 'participating wrapper must follow responsive parent stretch',
    'height:100%' => 'inner carriers must preserve row cross-axis stretch',
) as $needle => $message) {
    if (!str_contains($css, $needle)) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}
fwrite(STDOUT, "button reset flex carriers tests: passed\n");

$margin = (new HtmlTransformer())->transform('<style>.row{display:flex}.back{display:flex;margin-left:-4px;padding:4px;background:transparent;border-width:0;border-style:solid}</style><div class="row"><button class="back">Back</button><span>Label</span></div>')->toArray();
$marginCss = implode('', array_map(static fn(array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string)($asset['content'] ?? '') : '', $margin['assets'] ?? array()));
if (!preg_match('/wp-block-button__link[^{}]*\{margin-left:-4px\}/', $marginCss)) {
    fwrite(STDERR, "FAIL: flattened control margin must address the participating link\n"); exit(1);
}
