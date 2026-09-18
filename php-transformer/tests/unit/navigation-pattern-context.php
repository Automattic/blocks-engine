<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\NavigationPatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\SourceTargetProjectionState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;

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

$constructor = new ReflectionMethod(NavigationPatternContext::class, '__construct');
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

$context = new NavigationPatternContext();
$document = new DOMDocument();
$document->loadHTML('<!doctype html><html><body><nav><a href="/">Home</a></nav></body></html>');
$nav = $document->getElementsByTagName('nav')->item(0);
$anchor = $document->getElementsByTagName('a')->item(0);
if ( ! $nav instanceof DOMElement || ! $anchor instanceof DOMElement ) {
    fwrite(STDERR, "FAIL: test DOM did not initialize\n");
    exit(1);
}

$assert(false === $context->isRuntimeDomTarget($nav), 'null runtime-island checker is not a runtime target');
$assert('never' === $context->overlayMenu($nav), 'null toggle suppressor overlay is never');
$assert('' === $context->resolvedStyle($nav), 'null style resolver yields empty style');
$assert('' === $context->resolvedDisplay($nav), 'null style resolver yields empty display');
$assert(false === $context->isHiddenAtReferenceViewport($nav), 'null style resolver is not hidden at the reference viewport');
$assert(array() === $context->colorInteractionStates($nav), 'null style projector yields no color states');
$assert('' === $context->responsiveToggleMarker($nav), 'null projected navigation yields no toggle marker');
$assert('' === $context->linkIconMarker($anchor), 'null svg materializer yields no link icon');
$assert(array() === $context->labelPresentationMarkers($nav), 'null session yields no label markers');
$assert('' === $context->underlineColor($nav, $anchor), 'null style resolver yields no underline color');

$state = new SourceTargetProjectionState();
$recording = new NavigationPatternContext(sourceTargetProjection: $state);
$recording->projectSourceToNativeTarget($nav, '.wp-block-navigation__container', 'padding:0!important');
$assert(
    array(
        array(
            'source_selector' => SourceDom::elementSelector($nav),
            'target_selector' => '.wp-block-navigation__container',
            'declarations' => 'padding:0!important',
        ),
    ) === $state->correspondences(),
    'source-target projection state records native replacement CSS'
);
$recording->recordInheritedPresentation($nav, array( 'menu' ));
$assert(1 === count($state->correspondences()), 'inherited presentation does not record without a style resolver');

if ( 0 !== $failures ) {
    fwrite(STDERR, "navigation pattern context failed ({$failures} failures, {$passes} passed)\n");
    exit(1);
}

fwrite(STDOUT, "navigation pattern context passed ({$passes} assertions)\n");
