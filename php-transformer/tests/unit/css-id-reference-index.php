<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;

$cases = array(
    'fragments in declarations are not selectors' => array('.card { color:#abc; background:url(#paint); content:"#text" }', array()),
    'functional pseudos and escaped identifiers' => array(':is(#one, :not(#two)):has(#three) #a\\:b, #\\31 23 { color:red }', array('one', 'two', 'three', 'a:b', '123')),
    'strings and comments are not id tokens' => array('[data-label="#fake"] /* #comment */ #real { color:red }', array('real')),
    'nested conditional rules and layers' => array('@media (min-width:1px) { @supports (display:grid) { @layer cards { #nested:hover {color:red} } } }', array('nested')),
    'empty rules still reference identities' => array('#empty {} #empty {}', array('empty')),
    'opaque at-rules do not supply selectors' => array('@font-face {src:url(#font)} @keyframes spin {from {color:#abc}} #live {color:red}', array('live')),
);
foreach ($cases as $name => [$css, $expected]) {
    $actual = array_map('strval', array_keys(CssRuleAnalyzer::referencedIds($css)));
    if ($actual !== $expected) throw new RuntimeException($name . ': ' . json_encode($actual));
    echo "PASS: $name\n";
}
