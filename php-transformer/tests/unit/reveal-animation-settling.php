<?php
declare(strict_types=1);

/**
 * A captured reveal must never leave content less visible than its end state.
 *
 * Wix pauses an entrance animation (`animation: motion-fadeIn … backwards paused`)
 * behind a runtime attribute, and capture can hand the same animation to a
 * scroll-driven timeline (`animation-timeline: view()`). Both are carried by
 * import while the driver that would finish them is not, and
 * `animation-fill-mode: backwards` then pins the element to the `0% {opacity:0}`
 * keyframe forever: the content is in the block markup and never paints (#239).
 *
 * The repair replaces an animation that cannot progress with the resolved state
 * it was travelling towards. An animation that can still run, and one whose start
 * keyframe is no less visible than its end, are left exactly as authored.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\RevealAnimationSettler;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$settler = new RevealAnimationSettler();
$fadeIn = '@keyframes motion-fadeIn{0%{opacity:0}100%{opacity:var(--comp-opacity, 1)}}';

// The two shapes observed on the imported site, verbatim.
$paused = $fadeIn . '@media (prefers-reduced-motion: no-preference){#comp-k3nod418:not(.blocks-engine-attribute-state-c78e1e3229d6-119){animation:motion-fadeIn 1200ms 1ms cubic-bezier(0.445, 0.05, 0.55, 0.95) backwards 1 paused;animation-composition:replace}}';
$assert(
    array( ':root #comp-k3nod418:not(.blocks-engine-attribute-state-c78e1e3229d6-119){animation:none!important;opacity:var(--comp-opacity, 1)!important}' ) === $settler->settleRules($paused),
    'a paused entrance animation settles at its end keyframe on the selector that applied it',
    implode(' | ', $settler->settleRules($paused))
);

$scrollDriven = $fadeIn . '@supports (animation-timeline: view()){#comp-k3o4lijt{animation:motion-fadeIn 1200ms 1100ms cubic-bezier(0.445, 0.05, 0.55, 0.95) backwards 1;animation-composition:replace;animation-play-state:running;animation-timeline:view();animation-range:entry 0% cover 40%}}';
$assert(
    array( ':root #comp-k3o4lijt{animation:none!important;opacity:var(--comp-opacity, 1)!important}' ) === $settler->settleRules($scrollDriven),
    'a scroll-driven entrance animation settles rather than resting on an unadvanced timeline',
    implode(' | ', $settler->settleRules($scrollDriven))
);

// Reveals expressed with the longhands, and with visibility rather than opacity.
$longhand = '@keyframes reveal{from{opacity:0;visibility:hidden;transform:translateY(40px)}to{opacity:1;visibility:visible;transform:none}}.reveal{animation-name:reveal;animation-duration:.6s;animation-fill-mode:backwards;animation-play-state:paused}';
$assert(
    array( ':root .reveal{animation:none!important;opacity:1!important;transform:none!important;visibility:visible!important}' ) === $settler->settleRules($longhand),
    'longhand reveals settle, restating every end-state property the keyframes animate',
    implode(' | ', $settler->settleRules($longhand))
);

// A stalled animation with no fill still shows its start frame when the delay
// leaves it inside the active phase at time zero.
$noFillNoDelay = $fadeIn . '.hero{animation:motion-fadeIn 1s;animation-play-state:paused}';
$assert(
    array() !== $settler->settleRules($noFillNoDelay),
    'a paused animation with no delay settles even without a backwards fill mode'
);
$assert(
    array() === $settler->settleRules($fadeIn . '.hero{animation:motion-fadeIn 1s 2s;animation-play-state:paused}'),
    'a paused animation that only its fill mode could paint is left alone when it has none',
    implode(' | ', $settler->settleRules($fadeIn . '.hero{animation:motion-fadeIn 1s 2s;animation-play-state:paused}'))
);

// Everything that is not a stalled reveal stays exactly as authored.
foreach ( array(
    'a runnable entrance animation on the document timeline' => $fadeIn . '.hero{animation:motion-fadeIn 1.2s backwards 1}',
    'a paused cyclical animation, whose last frame is not a resting state' => '@keyframes spin{from{transform:rotate(0)}to{transform:rotate(360deg)}}.spinner{animation:spin 1s linear infinite paused}',
    'a paused marquee that only moves' => '@keyframes slide{from{transform:translateX(0)}to{transform:translateX(-100%)}}.track{animation:slide 8s linear infinite paused}',
    'a scroll-driven animation that only ever adds visibility' => '@keyframes fadeOutIn{0%{opacity:1}100%{opacity:1}}.bar{animation:fadeOutIn 1s backwards;animation-timeline:scroll()}',
    'an animation whose keyframes are not in the stylesheet' => '.hero{animation:elsewhere 1s backwards paused}',
    'a stylesheet with no animations at all' => '.hero{opacity:1}',
) as $description => $css ) {
    $assert(array() === $settler->settleRules($css), 'leaves alone: ' . $description, implode(' | ', $settler->settleRules($css)));
}

// Pseudo-elements are decoration, not the imported content the repair protects.
$assert(
    array() === $settler->settleRules($fadeIn . '.hero::after{animation:motion-fadeIn 1s backwards paused}'),
    'pseudo-element animations are not restated'
);

// Keyframes are found wherever they are declared, including inside conditions.
$nested = '@media screen{@keyframes motion-fadeIn{0%{opacity:0}to{opacity:1}}}.hero{animation:motion-fadeIn 1s backwards paused}';
$assert(
    array( ':root .hero{animation:none!important;opacity:1!important}' ) === $settler->settleRules($nested),
    'keyframes nested in a condition are still read',
    implode(' | ', $settler->settleRules($nested))
);

$names = array();
( new CssStylesheetTransformer() )->visitKeyframeRules('@-webkit-keyframes "quoted"{from{opacity:0}}@media screen{@keyframes nested{to{opacity:1}}}@font-face{font-family:x}', static function (string $name) use (&$names): void {
    $names[] = $name;
});
$assert(array( 'quoted', 'nested' ) === $names, 'keyframes visiting reports prefixed, quoted, and nested rules only', implode(',', $names));

// End to end: the hidden content paints, in the theme CSS and in the editor.
$html = <<<'HTML'
<div id="page">
  <h2 id="comp-k3nod418">We have the freedom to choose, so we chose to be good.</h2>
  <p id="comp-k3jrzme8">Our mission is a kinder salon.</p>
</div>
HTML;
$css = '@keyframes motion-fadeIn{0%{opacity:0}100%{opacity:var(--comp-opacity, 1)}}'
    . '@media (prefers-reduced-motion: no-preference){#comp-k3nod418{animation:motion-fadeIn 1200ms 1ms ease backwards 1 paused;animation-composition:replace}}'
    . '@supports (animation-timeline: view()){#comp-k3jrzme8{animation:motion-fadeIn 1200ms 1ms ease backwards 1;animation-play-state:running;animation-timeline:view();animation-range:entry 0% cover 40%}}';

$result = ( new HtmlTransformer() )->transform($html, array( 'static_css' => $css ))->toArray();
$assets = is_array($result['assets'] ?? null) ? $result['assets'] : array();
$collect = static function (array $assets, callable $keep): string {
    $chunks = array();
    foreach ( $assets as $asset ) {
        if ( 'css' === ($asset['kind'] ?? '') && $keep($asset) ) {
            $chunks[] = (string) ($asset['content'] ?? '');
        }
    }

    return implode("\n", $chunks);
};

$afterAuthor = $collect($assets, static fn (array $asset): bool => 'engine-support' === ($asset['source'] ?? '') && 'after-author' === ($asset['stylesheet_placement'] ?? ''));
foreach ( array( 'comp-k3nod418', 'comp-k3jrzme8' ) as $id ) {
    $assert(
        str_contains($afterAuthor, ':root #' . $id . '{animation:none!important;opacity:var(--comp-opacity, 1)!important}'),
        'the theme stylesheet settles #' . $id . ' after the author CSS that hides it',
        $afterAuthor
    );
}

$editorStaticState = $collect($assets, static fn (array $asset): bool => 'editor-static-state' === ($asset['source'] ?? ''));
$assert(
    str_contains($editorStaticState, 'animation-play-state:running!important') && str_contains($editorStaticState, 'animation-timeline:auto!important'),
    'the editor static state overrides the play state and timeline it winds animations past',
    $editorStaticState
);

if ( $failures > 0 ) {
    fwrite(STDERR, "reveal animation settling: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "reveal animation settling: {$passes} passed\n");
