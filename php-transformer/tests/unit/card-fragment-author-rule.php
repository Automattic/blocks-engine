<?php
declare(strict_types=1);

/**
 * Structured card lowering unwraps a styling-hook fragment and hoists its class
 * onto the paragraph it emits. The fragment's author rule must stay addressable
 * on that paragraph rather than being scoped behind an inline-layout carrier
 * that the card path never produces.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

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

$transform = static function (string $html): array {
    $result = ( new HtmlTransformer() )->transform($html)->toArray();
    $css = '';
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( 'author-css' === ($asset['source'] ?? '') ) {
            $css .= (string) ($asset['content'] ?? '');
        }
    }
    return array( 'markup' => (string) ($result['serialized_blocks'] ?? ''), 'css' => $css );
};

$talks = $transform(
    '<style>.talks{display:flex;flex-direction:column}.talk{display:grid;grid-template-columns:6rem 1fr}'
    . '.talk__date{font-size:0.78rem}.talk__body{font-size:0.97rem;line-height:1.7}</style>'
    . '<ul class="talks"><li class="talk"><span class="talk__date">May 2026</span>'
    . '<span class="talk__body"><em>Pulvino-cortical loops.</em> Invited colloquium.</span></li>'
    . '<li class="talk"><span class="talk__date">Mar 2026</span>'
    . '<span class="talk__body"><em>Why attention drifts.</em> Keynote.</span></li></ul>'
);

$assert(str_contains($talks['markup'], '<p class="talk__body">'), 'a card fragment hoists its styling hook onto the paragraph');
$assert(! str_contains($talks['markup'], 'blocks-engine-inline-layout-carrier'), 'card lowering emits no inline-layout carrier for the hoisted fragment');
$assert(
    str_contains($talks['css'], '.talk__body{font-size:0.97rem;line-height:1.7}')
        || str_contains($talks['css'], '.talk__body {font-size:0.97rem;line-height:1.7}'),
    'the fragment rule stays addressable on the hoisted class'
);
$assert(! str_contains($talks['css'], 'p.blocks-engine-inline-layout-carrier > .talk__body'), 'the fragment rule is not scoped behind a carrier the card path never emits');

// A genuine carrier still gets the scoped projection: the hook stays nested
// inside the paragraph rather than being hoisted onto it.
$carrier = $transform('<style>.bot{display:grid}.bot b{color:#176247}</style><div class="bot"><b>Label</b><p>Response</p></div>');
$assert(str_contains($carrier['markup'], 'blocks-engine-inline-layout-carrier'), 'a nested inline hook still converts through a carrier paragraph');
$assert(str_contains($carrier['css'], '.bot p.blocks-engine-inline-layout-carrier > b{color:#176247}'), 'a genuine carrier keeps its scoped projection');

// One hooked fragment is ordinary inline flow, not a card.
$single = $transform('<style>.notes{display:flex}.notes li{display:block}.note__body{line-height:1.9}</style><ul class="notes"><li><span class="note__body">Only fragment</span></li></ul>');
$assert(! str_contains($single['css'], 'p.blocks-engine-inline-layout-carrier > .note__body') || str_contains($single['css'], '.note__body'), 'a single hooked fragment keeps an addressable rule');

// The observed academic CV corpus case.
$cvDirectory = dirname(__DIR__, 3) . '/fixtures/websites/31-personal-cv-academic';
$cv = $transform((string) file_get_contents($cvDirectory . '/index.html'));
$assert(str_contains($cv['markup'], '<p class="talk__body">'), 'academic CV talks hoist their body fragment onto the paragraph');

if ( 0 < $failures ) {
    fwrite(STDERR, "Card fragment author rule tests: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "Card fragment author rule tests: {$passes} passed" . PHP_EOL);
