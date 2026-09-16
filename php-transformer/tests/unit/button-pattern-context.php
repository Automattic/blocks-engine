<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ButtonPatternContext;

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

$constructor = new ReflectionMethod(ButtonPatternContext::class, '__construct');
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

$context = new ButtonPatternContext();
$document = new DOMDocument();
$document->loadHTML('<!doctype html><html><body><a href="/file.pdf" class="download">Spec</a><button>Go</button></body></html>');
$anchor = $document->getElementsByTagName('a')->item(0);
$button = $document->getElementsByTagName('button')->item(0);
if ( ! $anchor instanceof DOMElement || ! $button instanceof DOMElement ) {
    fwrite(STDERR, "FAIL: test DOM did not initialize\n");
    exit(1);
}

$assert(null === $context->fileBlockFromAnchor($anchor), 'null collaborators yield no file block');
$assert('' === $context->resolvedStyle($anchor), 'null style resolver yields empty style');
$assert('' === $context->controlSurfaceStyle($anchor), 'null style resolver yields empty control surface style');
$assert('Spec' === $context->richText($anchor), 'null rich text falls back to inner HTML');
$assert(null === $context->materializeSvgImages($anchor, 'x'), 'null rich text yields no svg materialization');
$assert('/file.pdf' === $context->attribute($anchor, 'href'), 'attributes are read from SourceDom');
$assert(false === $context->isGridItem($button), 'null style resolver is not a grid item');
$assert(false === $context->isRuntimeDomTarget($button), 'null runtime-island checker is not a runtime target');

if ( 0 !== $failures ) {
    fwrite(STDERR, "button pattern context failed ({$failures} failures, {$passes} passed)\n");
    exit(1);
}

fwrite(STDOUT, "button pattern context passed ({$passes} assertions)\n");
