<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$css = '.nav{display:flex;justify-content:space-between;height:90px}.toggler{--ink:#2d2e2e;position:relative;width:35px;height:28px;border:0;padding:0;background:none;margin:auto 0}.toggler span{display:block;position:absolute;height:2px;width:30px;background-color:var(--ink);left:0}.toggler span:nth-child(1){top:0}.toggler span:nth-child(2){top:10px}.toggler span:nth-child(3){top:20px}.panel{position:fixed;top:0;right:0;bottom:0;width:380px;transform:translateX(100%);visibility:hidden}.desk{display:none}@media(min-width:1400px){.toggler{display:none}.desk{display:flex}}';
$links = '<ul><li><a href="/a/">Alpha</a></li><li><a href="/b/">Beta</a></li></ul>';
$html = '<!doctype html><html><head><style>' . $css . '</style></head><body><header><nav class="nav"><a href="/">Brand</a><button class="toggler" aria-controls="menu" aria-label="Menu"><span></span><span></span><span></span></button><div id="menu" class="panel">' . $links . '</div><div class="desk">' . $links . '</div></nav></header><main><p>x</p></main></body></html>';
foreach (array('absolute' => $html, 'flow' => str_replace('position:absolute;', 'margin-bottom:8px;', $html)) as $mode => $source) {
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toWordPressSitePlanView()['wordpress_site_plan'];
    $markup = '';
    array_walk_recursive($plan, static function (mixed $value) use (&$markup): void { if (is_string($value)) $markup .= $value . "\n"; });
    $markup = str_replace(array('\\u003c', '\\u003e', '\\u0022'), array('<', '>', '"'), $markup);
    foreach (array('<rect x="0px" y="0px"', '<rect x="0px" y="10px"', '<rect x="0px" y="20px"', 'fill="#2d2e2e"') as $expected) {
        if (!str_contains($markup, $expected)) throw new RuntimeException($mode . ' opener missing ' . $expected);
    }
}
echo "Navigation child-bar opener contract passed\n";
