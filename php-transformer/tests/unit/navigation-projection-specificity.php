<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\WordPressCompatCss;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$sources = array(
    '.menu-links>:where(.blocks-engine-source-li-probe):not(source-type)>a',
    '.landmark .menu-links a',
    '#owner .menu-links a',
    '.menu-links:not(.inactive)>:where(.blocks-engine-source-li-probe):not(source-type)>a',
    '.landmark .menu-links a:hover',
);
$css = '';
foreach ($sources as $index => $selector) $css .= $selector . '{--probe-' . $index . ':1}';
$projected = (new WordPressCompatCss())->css($css, array(), array());
$seen = array();
(new CssStylesheetTransformer())->visitStyleRules($projected, static function(string $selectorList, string $body) use (&$seen, $sources): void {
    if (!preg_match('/--probe-(\d+):1/', $body, $match)) return;
    $index = (int) $match[1];
    foreach (CssStylesheetTransformer::splitSelectorList($selectorList) ?? array() as $selector) {
        $selector = trim(preg_replace('#/\*.*?\*/#s', '', $selector) ?? $selector);
        $parsed = CssSelectorMatcher::parse($selector);
        $source = CssSelectorMatcher::parse($sources[$index]);
        // The common :root native scope contributes ten to every family;
        // transport classes inside :where() must contribute nothing else.
        if (!$parsed['supported'] || CssSelectorMatcher::specificity($parsed) !== 10 + CssSelectorMatcher::specificity($source)) {
            throw new RuntimeException('Native transport changed authored specificity: ' . $selector);
        }
        $seen[$index] = true;
    }
});
if (count($seen) !== count($sources)) throw new RuntimeException('An authored mapping family was silently omitted.');
echo "Navigation projection specificity passed: all source type/class/ID/state weights preserved in the common native scope\n";
