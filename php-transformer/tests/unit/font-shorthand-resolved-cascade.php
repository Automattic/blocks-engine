<?php
declare(strict_types=1);

/** Issue #3453: font resets must reach the site compiler's navigation cascade. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssFontShorthand;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};
$page = '<header><div class="menu"><ul class="nav">'
    . '<li><a href="/a">Galerie<span>Mon</span></a><ul class="sub"><li><a href="/a1">Sculptures</a></li><li><a href="/a2">Tableaux</a></li></ul></li>'
    . '<li><a href="/b">Blog</a></li><li><a href="/c">Contact</a></li>'
    . '</ul></div></header><main><p>Hi</p></main>';
$cases = array(
    'omitted leading resets' => array('font:bold 18px Arial,sans-serif', 'normal'),
    'explicit longhand control' => array('font-weight:bold;line-height:normal', 'normal'),
    'explicit shorthand leading' => array('font:bold 18px/30px Arial,sans-serif', '30px'),
    'supported shorthand resets' => array('font:bold 18px Arial,sans-serif', 'normal', '@supports (display:grid)'),
    'supported explicit leading' => array('font:bold 18px/30px Arial,sans-serif', '30px', '@supports (display:grid)'),
    'later longhand wins' => array('font:bold 18px Arial,sans-serif;line-height:32px', '32px'),
    'later shorthand resets' => array('line-height:32px;font:bold 18px Arial,sans-serif', 'normal'),
    'important longhand wins' => array('line-height:32px!important;font:bold 18px Arial,sans-serif', '32px !important'),
    'important shorthand wins' => array('font:bold 18px/30px Arial,sans-serif!important;line-height:32px', '30px !important'),
    'repeated shorthand preserves earlier reset' => array('font:18px Arial;line-height:32px;font:bold 18px Arial', 'normal'),
    'spaced slash' => array('font:italic small-caps 700 18px / 30px "PT Sans", Arial, sans-serif', '30px'),
);
foreach ($cases as $name => $case) {
    [$declarations, $expected] = $case;
    $anchorRule = '.menu .nav>li>a{' . $declarations . '}';
    if (isset($case[2])) $anchorRule = $case[2] . '{' . $anchorRule . '}';
    $css = 'li{line-height:20px}' . $anchorRule;
    $result = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'website/index.html',
        'files' => array('website/index.html' => '<html><head><style>' . $css . '</style></head><body>' . $page . '</body></html>'),
    ))->toArray();
    // The marker is transported in the compiled site plan, including submenu parents.
    $encoded = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    preg_match_all('/blocks-engine-navigation-anchor-line-height-([a-f0-9]{64})/', $encoded, $matches);
    $markers = array_unique($matches[1]);
    $assert(!in_array(hash('sha256', '20px'), $markers, true), $name . ': ancestor leading must not win');
    $assert(null === $expected ? array() === $markers : in_array(hash('sha256', $expected), $markers, true), $name . ': expected anchor marker ' . json_encode($markers));
}
foreach (array('caption', 'menu', 'inherit', 'initial', 'unset', 'revert', 'revert-layer', 'var(--font)', '18px var(--family)') as $value) {
    $assert(array() === CssFontShorthand::expand($value), 'conservatively skip ' . $value);
}
$expanded = CssFontShorthand::expand('italic small-caps bold 18px "PT Sans",Arial,sans-serif!important');
foreach (array('font-style' => 'italic', 'font-variant' => 'small-caps', 'font-weight' => 'bold', 'font-size' => '18px', 'line-height' => 'normal', 'font-family' => '"PT Sans",Arial,sans-serif') as $property => $value) {
    $assert($value . ' !important' === ($expanded[$property] ?? null), 'expand ' . $property);
}
fwrite(STDOUT, "Font shorthand resolved cascade passed\n");
