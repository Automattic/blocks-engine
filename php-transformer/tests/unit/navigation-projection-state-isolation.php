<?php
/**
 * Regression contract for #1334.
 *
 * Navigation projection state is keyed by DOMElement::getNodePath(), and those
 * paths collide freely across unrelated documents. When the state lived on the
 * transformer instead of the per-transform session it was never reset, so a
 * document transformed on a reused HtmlTransformer could inherit suppression
 * decisions belonging to an earlier document and silently lose block markup.
 *
 * The contract: transforming a document on a reused transformer must produce
 * exactly what transforming it on a fresh transformer produces.
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\NavigationProjectionState;

$corpus = dirname(__DIR__, 3) . '/fixtures/websites';

/** A document whose hamburger control projects onto a hidden navigation. */
$priming = $corpus . '/33-sports-team-league/index.html';
/** An unrelated document that must not inherit those decisions. */
$subject = $corpus . '/12-portfolio/index.html';

foreach (array($priming, $subject) as $path) {
    if (! is_file($path)) {
        fwrite(STDERR, "Navigation projection isolation contract needs fixture: {$path}\n");
        exit(1);
    }
}

$cssFor = static function (string $htmlPath): string {
    $css = '';
    foreach (glob(dirname($htmlPath) . '/css/*.css') ?: array() as $stylesheet) {
        $css .= file_get_contents($stylesheet) . "\n";
    }
    return $css;
};

$markup = static function (HtmlTransformer $transformer, string $htmlPath) use ($cssFor): string {
    $result = $transformer->transform(
        (string) file_get_contents($htmlPath),
        array( 'static_css' => $cssFor($htmlPath) )
    )->toArray();

    return (string) $result['serialized_blocks'];
};

$expected = $markup(new HtmlTransformer(), $subject);

$reused = new HtmlTransformer();
$markup($reused, $priming);
$actual = $markup($reused, $subject);

if ($expected !== $actual) {
    fwrite(STDERR, sprintf(
        "Navigation projection isolation contract failed: reused transformer produced %d bytes, fresh produced %d.\n",
        strlen($actual),
        strlen($expected)
    ));
    exit(1);
}

// The priming document must genuinely exercise the projection path, otherwise
// the comparison above passes for the wrong reason.
$probe = new HtmlTransformer();
$markup($probe, $priming);
$session = ( new ReflectionClass($probe) )->getProperty('session')->getValue($probe);
$state = $session->navigationProjectionState();
if (! $state instanceof NavigationProjectionState) {
    fwrite(STDERR, "Navigation projection isolation contract failed: session exposes no projection state.\n");
    exit(1);
}

$document = new DOMDocument();
$document->loadHTML('<!doctype html><html><body><button></button></body></html>');
$control = $document->getElementsByTagName('button')->item(0);
if (! $control instanceof DOMElement) {
    throw new RuntimeException('Projection probe fixture did not produce a control element.');
}
$state->projectTarget($control, $control);
$state->suppress($control);
if (! $state->hasTargetForControl($control) || ! $state->isSuppressed($control)) {
    fwrite(STDERR, "Navigation projection isolation contract failed: state does not record projections.\n");
    exit(1);
}

// A fresh transform must not see the writes above.
$markup($probe, $subject);
$freshState = ( new ReflectionClass($probe) )->getProperty('session')->getValue($probe)->navigationProjectionState();
if ($freshState->hasTargetForControl($control) || $freshState->isSuppressed($control)) {
    fwrite(STDERR, "Navigation projection isolation contract failed: state survived a transform.\n");
    exit(1);
}

fwrite(STDOUT, "Navigation projection isolation contract passed\n");
