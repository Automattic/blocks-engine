<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorListCompactor;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSpecificityProjection;

foreach (array('a.b.c#x#y', 'a b.c', '.a.b.c', 'a b c') as $selector) {
    $parsed = CssSelectorMatcher::parse($selector);
    $guard = CssSpecificityProjection::shims($parsed, 'be-t-0', 'be-c-0', 'be-i-0');
    $actual = CssSelectorMatcher::specificityCounts(CssSelectorMatcher::parse($guard));
    if ($actual !== CssSelectorMatcher::specificityCounts($parsed)) throw new RuntimeException('Guard changed specificity: ' . $guard);
    if (preg_match('/(:not\([^()]+\))\1/', $guard)) throw new RuntimeException('Repeated guard: ' . $guard);
}
$guard = ':not(:where(.blocks-engine-synthetic-image-figure))';
$source = 'a' . $guard . ',b' . $guard . ',c' . $guard;
$compact = CssSelectorListCompactor::compact($source);
if (':is(a,b,c)' . $guard !== $compact) throw new RuntimeException('Guard was not hoisted: ' . $compact);
if (CssSelectorMatcher::specificityCounts(CssSelectorMatcher::parse('a' . $guard)) !== CssSelectorMatcher::specificityCounts(CssSelectorMatcher::parse($compact))) throw new RuntimeException('Hoisting changed specificity.');
echo "Projected selector guard compaction passed.\n";
