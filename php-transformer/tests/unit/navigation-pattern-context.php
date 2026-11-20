<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\NavigationPatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\SourceTargetProjectionState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\StyleResolverFixture;

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

$document = new DOMDocument();
$document->loadHTML('<!doctype html><html><body><nav><a href="/">Home</a></nav></body></html>');
$nav = $document->getElementsByTagName('nav')->item(0);
$anchor = $document->getElementsByTagName('a')->item(0);
if ( ! $nav instanceof DOMElement || ! $anchor instanceof DOMElement ) {
    fwrite(STDERR, "FAIL: test DOM did not initialize\n");
    exit(1);
}

$styles = new StyleResolverFixture($nav);
// A probe context: no projection, toggle, runtime, or SVG collaborators.
$context = new NavigationPatternContext($styles->styleResolver, $styles->session);

$assert(false === $context->isRuntimeDomTarget($nav), 'null runtime-island checker is not a runtime target');
$assert('never' === $context->overlayMenu($nav), 'null toggle suppressor overlay is never');
$assert(array() === $context->colorInteractionStates($nav), 'null style projector yields no color states');
$assert('' === $context->responsiveToggleMarker($nav), 'null projected navigation yields no toggle marker');
$assert('' === $context->linkIconMarker($anchor), 'null svg materializer yields no link icon');

$state = new SourceTargetProjectionState();
$recording = new NavigationPatternContext($styles->styleResolver, $styles->session, sourceTargetProjection: $state);
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
$assert(1 === count($state->correspondences()), 'inherited presentation records nothing when no author rule styles the links');

// Marker identity must retain author order, not the order of index buckets.
$document = new DOMDocument();
$document->loadHTML('<!doctype html><html><body><nav><a id="home" class="menu-link" data-menu="primary" href="/">Home</a></nav></body></html>');
$anchor = $document->getElementsByTagName('a')->item(0);
$styles = new StyleResolverFixture($document->getElementsByTagName('body')->item(0));
$context = new NavigationPatternContext($styles->styleResolver, $styles->session);
$resolution = $styles->session->sourceStyleResolutionState();
$analysis = $styles->session->authorStyleAnalysis();
$selectorLists = array(
    array('[data-menu]'),
    array('.unrelated'),
    array('*'),
    array('#home', '.menu-link', 'a'),
    array('a:unsupported-pseudo'),
    array('nav > a'),
    array('[aria-current]'),
);
$authorRules = array();
foreach ($selectorLists as $order => $selectors) {
    $authorRules[] = array(
        'order' => $order,
        'selectors' => array_map(static fn (string $text): array => array(
            'selector' => $text,
            'parsed' => $resolution->parsedSelector($text),
        ), $selectors),
        'declarations' => array('color' => 'red'),
        // Even print-only rules contributed to the original marker identity.
        'conditions' => 0 === $order ? array('@media print') : array(),
    );
}
$analysis->installStyleRules($authorRules);
$candidates = $analysis->subjectRuleCandidates($anchor);
$assert(array(0, 2, 3, 4, 5) === array_column($candidates, 'order'), 'subject candidates retain unsupported and universal rules in source order, skipping unrelated subjects');
$assert(!$resolution->matches($anchor, 'a:unsupported-pseudo'), 'unsupported selector remains a non-match');

// Reference the original full scan, including selector order within a rule.
$matched = array();
foreach ($analysis->styleRules() as $rule) {
    foreach ($rule['selectors'] as $selector) {
        $text = $selector['selector'];
        if ('' !== $text && $resolution->matches($anchor, $text) && !$styles->session->authorSelectorProjectionState()->selectsCurrentNavigationState($text, $anchor)) {
            $matched[] = array($text, $rule['declarations'], $rule['conditions'] ?? array());
        }
    }
}
$assert(array('[data-menu]', '*', '#home', '.menu-link', 'a', 'nav > a') === array_column($matched, 0), 'reference identity covers attribute, universal, id, class and tag subjects in source order');
$identity = array('a', 'home', 'menu-link', '', $matched);
$expected = $analysis->allocateStableMarker('navigation-item', (string) json_encode($identity));
$markerMethod = new ReflectionMethod(NavigationPatternContext::class, 'navigationSubjectMarker');
$assert($expected === $markerMethod->invoke($context, $anchor, 'item'), 'indexed navigation marker is byte-identical to the full-scan marker');

// Reinstalling author rules must invalidate the candidate list as well as its index.
$analysis->installStyleRules(array($authorRules[1]));
$assert(array() === $analysis->subjectRuleCandidates($anchor), 'reinstalled rules do not reuse stale subject candidates');

if ( 0 !== $failures ) {
    fwrite(STDERR, "navigation pattern context failed ({$failures} failures, {$passes} passed)\n");
    exit(1);
}

fwrite(STDOUT, "navigation pattern context passed ({$passes} assertions)\n");
