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
