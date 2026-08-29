<?php
declare(strict_types=1);

ini_set('memory_limit', '96M');

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorStyleAnalysis;

$transformer = new HtmlTransformer();
$reflection = new ReflectionClass($transformer);
$css = '@keyframes inert{' . str_repeat('x', 32 * 1024 * 1024) . '}'
    . '@media (min-width:1px){.menu li a:hover{color:#123456}}';
$document = new DOMDocument();
$document->loadHTML('<!doctype html><html><body></body></html>');
$body = $document->getElementsByTagName('body')->item(0);
if ( ! $body instanceof DOMElement ) {
    throw new RuntimeException('Author style fixture did not produce a body element.');
}
$session = $reflection->getProperty('session')->getValue($transformer);
$session->installAuthorStyleAnalysis(new AuthorStyleAnalysis($css, $css, array(), $body));

// Author-rule collection moved to NavigationStyleProjector. Reach it through
// the transformer's collaborator so this still exercises the real wiring —
// including the context closure that resolves the running transform's session
// state — rather than a projector built in isolation.
$projector = $reflection->getProperty('navigationStyleProjector')->getValue($transformer);
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
