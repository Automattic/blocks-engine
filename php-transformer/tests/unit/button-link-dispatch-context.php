<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ButtonLinkDispatchContext;

$failures = 0;
$passes = 0;

$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

$constructor = new ReflectionMethod(ButtonLinkDispatchContext::class, '__construct');
foreach ( $constructor->getParameters() as $parameter ) {
    $type = $parameter->getType();
    $names = $type instanceof ReflectionUnionType
        ? array_map(static fn (ReflectionType $part): string => $part->__toString(), $type->getTypes())
        : array( $type instanceof ReflectionType ? $type->__toString() : '' );
    $assert(
        ! in_array('Closure', $names, true) && ! in_array('callable', $names, true),
        'constructor parameter $' . $parameter->getName() . ' is not a closure'
    );
}

$context = new ButtonLinkDispatchContext(new SourceElementClassifier());
$document = new DOMDocument();
$document->loadHTML('<!doctype html><html><body><a href="/x">go</a></body></html>');
$anchor = $document->getElementsByTagName('a')->item(0);
if ( ! $anchor instanceof DOMElement ) {
    fwrite(STDERR, "FAIL: test DOM did not initialize\n");
    exit(1);
}

$fallbacks = array();
$assert(false === $context->isRuntimeDomTarget($anchor), 'null runtime-island checker is not a runtime target');
$assert('core/html' === ($context->htmlPreservationBlock($anchor)['blockName'] ?? ''), 'null createBlock still preserves HTML');
$assert(null === $context->recognizePatterns($anchor, $fallbacks, array()), 'null registry yields no pattern match');
$assert(null === $context->imageBlockFromAnchor($anchor), 'null leftovers yield no linked image');
$assert(null === $context->convertLinkWrapperGroup($anchor, $fallbacks), 'null leftovers yield no link wrapper');
$assert('/x' === $context->safeLinkUrl('/x'), 'safe link URLs are sanitized without a closure');
$assert('' === $context->safeLinkUrl('javascript:void(0)'), 'unsafe link URLs are stripped without a closure');
$assert(array() === $context->presentationAttributes($anchor), 'null presentation resolver yields no attributes');
$assert(false === $context->hasBlockContentChildren($anchor), 'a text-only anchor has no block children');
$assert(false === $context->hasBlockContentChildren($anchor, true), 'a text-only anchor has no block children with svg treated as inline');

if ( 0 !== $failures ) {
    fwrite(STDERR, "button link dispatch context failed ({$failures} failures, {$passes} passed)\n");
    exit(1);
}

fwrite(STDOUT, "button link dispatch context passed ({$passes} assertions)\n");
