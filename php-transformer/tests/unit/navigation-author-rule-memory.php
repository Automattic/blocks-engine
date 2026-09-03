<?php
declare(strict_types=1);

ini_set('memory_limit', '96M');

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlCompilation;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorStyleAnalysis;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;

$compilation = new HtmlCompilation();
$reflection = new ReflectionClass($compilation);
$css = '@keyframes inert{' . str_repeat('x', 32 * 1024 * 1024) . '}'
    . '@media (min-width:1px){.menu li a:hover{color:#123456}}';
$document = new DOMDocument();
$document->loadHTML('<!doctype html><html><body></body></html>');
$body = $document->getElementsByTagName('body')->item(0);
if ( ! $body instanceof DOMElement ) {
    throw new RuntimeException('Author style fixture did not produce a body element.');
}
$session = $reflection->getProperty('session')->getValue($compilation);
$authorStyles = new AuthorStyleAnalysis($css, $css, array(), $body);
$authorStyles->installStyleRules(array(array(
    'order' => 0,
    'declarations' => array('color' => '#123456'),
    'conditions' => array('@media (min-width:1px)'),
    'selectors' => array(array(
        'selector' => '.menu li a:hover',
        'parsed' => CssSelectorMatcher::parse('.menu li a:hover'),
        'direct_child_parsed' => CssSelectorMatcher::parse('.menu li a:hover'),
    )),
)));
$session->installAuthorStyleAnalysis($authorStyles);

// Author-rule collection moved to NavigationStyleProjector. Reach it through
// the compilation collaborator so this still exercises the real run-scoped
// wiring rather than a projector built in isolation.
$projector = $reflection->getProperty('navigationStyleProjector')->getValue($compilation);
$collect = ( new ReflectionClass($projector) )->getMethod('navigationAuthorStyleRules');
$rules = $collect->invoke($projector);
$rule = is_array($rules) ? ($rules[0] ?? array()) : array();

$valid = 1 === count($rules)
    && '.menu li a:hover' === ($rule['selector'] ?? null)
    && array( 'color' => '#123456' ) === ($rule['declarations'] ?? null)
    && array( '@media (min-width:1px)' ) === ($rule['conditions'] ?? null)
    && array( 0, 2, 2 ) === ($rule['specificity'] ?? null)
    && 0 === ($rule['order'] ?? null);

if ( ! $valid ) {
    fwrite(STDERR, 'Navigation author-rule memory contract failed: ' . json_encode($rules) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Navigation author-rule memory contract passed\n");
