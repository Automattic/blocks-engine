<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\SharedChromeStylesheets;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$rules = new ReflectionMethod(SharedChromeStylesheets::class, 'detachedStaticStartRules');
$parts = array(array('area' => 'header', 'placement' => array('kind' => 'shared_shell'), 'ancestor_context' => array('static_start_ids' => array('backdrop'))));
$pages = array(array('canonical_block_markup' => '<div id="backdrop" style="left:auto;color:red"></div>'));
$asset = static fn(string $css): array => array('kind' => 'css', 'content' => $css);
$positioning = $asset('#backdrop{position:absolute;inset:auto}');
$expected = '.wp-site-blocks #backdrop{top:0}';
if ($expected !== $rules->invoke(null, $parts, array($positioning), $pages)) throw new RuntimeException('Absolute auto-inset hooks must retain their static start.');

// Simulate repeated route projections, including selector-list items with whitespace.
$noise = '';
for ($i = 0; $i < 200; $i++) $noise .= '.static-start-noise-' . $i . '{color:red;background:white}';
$projection = $asset($noise . '#backdrop,  #backdrop {position:absolute;inset:auto}');
$assets = array_merge(array($positioning), array_fill(0, 100, $projection));
if ($expected !== $rules->invoke(null, $parts, $assets, $pages)) throw new RuntimeException('Duplicated non-positioning rules and route projections must not change output.');

// The parser memoizes selectors: irrelevant rules must never enter that cache.
$parse = new ReflectionMethod(CssSelectorMatcher::class, 'parse');
$cache = $parse->getStaticVariables()['cache'];
for ($i = 0; $i < 200; $i++) {
    if (isset($cache['.static-start-noise-' . $i])) throw new RuntimeException('Non-positioning rules must not be parsed.');
}

$inlinePages = array(array('canonical_block_markup' => '<div id="backdrop" style="position:absolute;inset:auto"></div>'));
if ($expected !== $rules->invoke(null, $parts, array(), $inlinePages)) throw new RuntimeException('Inline absolute auto-inset declarations must qualify.');
foreach (array('position:relative', 'top:0', 'inset-block-start:5px') as $unsafe) {
    $unsafeAssets = array_merge($assets, array($asset('#backdrop{' . $unsafe . '}')));
    if ('' !== $rules->invoke(null, $parts, $unsafeAssets, $pages)) throw new RuntimeException('Conflicting declarations must still veto static start: ' . $unsafe);
}

$noHooks = $parts;
unset($noHooks[0]['ancestor_context']['static_start_ids']);
if ('' !== $rules->invoke(null, $noHooks, array($asset('.static-start-no-hooks{position:absolute}')), $pages)) throw new RuntimeException('Imports without hooks must emit no rules.');
if (isset($parse->getStaticVariables()['cache']['.static-start-no-hooks'])) throw new RuntimeException('Imports without hooks must skip selector parsing.');

echo "detached static start rules contract passed\n";
