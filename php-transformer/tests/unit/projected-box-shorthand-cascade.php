<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Issue #3452's table: separate linked sheets must take the site-compile path,
// not merely the direct HtmlTransformer stylesheet path.
$cases = array(
    'stronger shorthand' => array('.navbar-inner{padding-left:20px;padding-right:20px}', '.navbar_ .navbar-inner{padding:0}', '0'),
    'later shorthand' => array('.navbar-inner{padding-left:20px}', '.navbar-inner{padding:0}', '0'),
    'weaker later longhand' => array('.navbar_ .navbar-inner{padding:0}', '.navbar-inner{padding-left:20px}', '0'),
    'one sheet' => array('', '.navbar-inner{padding-left:20px}.navbar_ .navbar-inner{padding:0}', '0'),
    'shorthand control' => array('.navbar-inner{padding:0 20px}', '.navbar_ .navbar-inner{padding:0}', '0'),
    'longhand control' => array('.navbar-inner{padding-left:20px;padding-right:20px}', '.navbar_ .navbar-inner{padding-left:0;padding-right:0}', '0'),
    'stronger earlier longhand' => array('.navbar_ .navbar-inner{padding-left:20px}', '.navbar-inner{padding:0}', '20px'),
    'stronger later longhand' => array('.navbar-inner{padding:0}', '.navbar_ .navbar-inner{padding-left:20px}', '20px'),
);

foreach ($cases as $label => [$a, $b, $expected]) {
    $result = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array(
            'index.html' => '<html><head><link rel="stylesheet" href="a.css"><link rel="stylesheet" href="b.css"></head><body><header><div class="navbar navbar_"><div class="navbar-inner"><p>Brand</p></div></div></header><main><h1>Home</h1></main></body></html>',
            'a.css' => $a,
            'b.css' => $b,
        ),
    ))->toArray();
    $markup = $result['source_reports']['compiled_site']['pages'][0]['block_markup'] ?? '';
    $dom = new DOMDocument();
    @$dom->loadHTML($markup);
    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " navbar-inner ")]');
    if (1 !== $nodes->length) {
        throw new RuntimeException($label . ': expected one projected navbar-inner: ' . $markup);
    }
    $style = $nodes->item(0)->getAttribute('style');
    if (1 !== preg_match('/(?:^|;)padding-left:' . preg_quote($expected, '/') . '(?:\s*!important)?(?:;|$)/', $style)) {
        throw new RuntimeException($label . ': expected padding-left:' . $expected . ', got ' . $style);
    }
}
// A universal critical reset participates in the same layer cascade as the
// utility being promoted into block spacing. Keep the two style elements: the
// public artifact path must compose their layer metadata before projection.
$layerCases = array(
    'unlayered reset wins' => array('*,::after,::before{box-sizing:border-box;margin:0;padding:0}', '', '', '0'),
    'layered reset control' => array('', '', '', '48px'),
    'important layered reset beats normal utility' => array('', ' !important', '', '0'),
    'important layered utility beats unlayered reset' => array('*{padding:0 !important}', '', ' !important', '48px'),
    'earlier important layer wins' => array('', ' !important', ' !important', '0'),
);
foreach ($layerCases as $label => [$critical, $baseImportance, $utilityImportance, $expected]) {
    $html = '<style id="critical">' . $critical . '</style>'
        . '<style>@layer base,utilities;@layer base{*,::after,::before{margin:0;padding:0' . $baseImportance . '}}'
        . '@layer utilities{.py-12{padding-block:48px' . $utilityImportance . '}.grid{display:grid}}</style>'
        . '<main><div class="grid py-12"><div><h2>Title</h2></div><p>Body text.</p></div></main>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $markup = $result['source_reports']['compiled_site']['pages'][0]['block_markup'] ?? '';
    $dom = new DOMDocument();
    @$dom->loadHTML($markup);
    $nodes = (new DOMXPath($dom))->query('//*[contains(concat(" ", normalize-space(@class), " "), " py-12 ")]');
    if (1 !== $nodes->length || !str_contains($nodes->item(0)->getAttribute('class'), 'wp-block-group')) {
        throw new RuntimeException($label . ': expected one compiled utility group: ' . $markup);
    }
    $style = $nodes->item(0)->getAttribute('style');
    preg_match_all('/(?:^|;)padding(?:-(?:top|right|bottom|left|block|inline)(?:-(?:start|end))?)?:([^;]+)/', $style, $padding);
    if ('0' === $expected) {
        foreach ($padding[1] as $value) {
            if (1 !== preg_match('/^0(?:px)?(?:\\s*!important)?$/', trim($value))) {
                throw new RuntimeException($label . ': expected no non-zero inline padding, got ' . $style);
            }
        }
    } else {
        foreach (array('top', 'bottom') as $side) {
            if (1 !== preg_match('/(?:^|;)padding-' . $side . ':48px(?:\\s*!important)?(?:;|$)/', $style)) {
                throw new RuntimeException($label . ': expected 48px vertical padding, got ' . $style);
            }
        }
    }
}
// Exercise the shared cascade helper directly for all box families and ranking
// dimensions; downstream value projection is deliberately not under test here.
$resolver = (new ReflectionClass(Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver::class))->newInstanceWithoutConstructor();
$apply = new ReflectionMethod($resolver, 'applyMediaTextCascadeDeclaration');
$families = (new ReflectionClass($resolver))->getConstant('BOX_SHORTHAND_LONGHANDS');
foreach ($families as $shorthand => $longhands) {
    foreach ($longhands as $longhand) {
        foreach (array('specificity', 'importance', 'order', 'inline') as $ranking) {
            $weak = array(false, array(0, 1, 0), 1, '1px');
            $strong = match ($ranking) {
                'specificity' => array(false, array(0, 2, 0), 2, '0'),
                'importance' => array(false, array(0, 0, 0), 2, '0 !important'),
                'inline' => array(true, array(PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX), 2, '0'),
                default => array(false, array(0, 1, 0), 2, '0'),
            };
            foreach (array(false, true) as $reverse) {
                foreach (array(false, true) as $longhandWins) {
                    $cascade = array();
                    $entries = array(
                        array($shorthand, $longhandWins ? $weak : $strong),
                        array($longhand, $longhandWins ? $strong : $weak),
                    );
                    if ($reverse) $entries = array_reverse($entries);
                    foreach ($entries as [$property, [$inline, $specificity, $sequence, $value]]) {
                        $apply->invokeArgs($resolver, array(&$cascade, $property, $value, $inline, $specificity, $sequence));
                    }
                    $expectedKeys = $longhandWins ? array($shorthand, $longhand) : array($shorthand);
                    if (array_keys($cascade) !== $expectedKeys) {
                        throw new RuntimeException("{$shorthand}/{$longhand}: {$ranking} failed");
                    }
                }
            }
        }
    }
}
echo "Projected box shorthand cascade passed\n";
