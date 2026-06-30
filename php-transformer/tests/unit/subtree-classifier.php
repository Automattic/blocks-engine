<?php
declare(strict_types=1);

/**
 * Unit tests for the standalone subtree classifier (issue #497).
 *
 * Plain-PHP test script in the style of tests/contract/run.php — no PHPUnit.
 * Each case builds a DOMElement subtree + a ClassificationContext (declared CSS
 * and associated JS) and asserts the resulting bucket / confidence behaviour.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\ClassificationContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SubtreeClassifier;

$failures = 0;
$passes   = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

/**
 * Build the first element child of the given HTML fragment as a DOMElement.
 */
$element = static function (string $html): DOMElement {
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML(
        '<!DOCTYPE html><html><body>' . $html . '</body></html>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    $body = $doc->getElementsByTagName('body')->item(0);
    foreach ( $body->childNodes as $child ) {
        if ( $child instanceof DOMElement ) {
            return $child;
        }
    }
    throw new RuntimeException('No element found in fixture HTML.');
};

$classifier = new SubtreeClassifier();

$summarize = static function ($result): string {
    return $result->bucket() . ' @ ' . $result->confidence();
};

// ---------------------------------------------------------------------------
// 1. theme_presentation — scroll-reveal: CSS keyframes/transition + JS that only
//    toggles a class on scroll. No input, data, or state — appearance only.
// ---------------------------------------------------------------------------
$reveal = $element('<div class="reveal"><h2>Our story</h2><p>Lorem ipsum dolor.</p></div>');
$revealCtx = new ClassificationContext(
    "@keyframes fade { from { opacity: 0 } to { opacity: 1 } } .reveal { opacity: 0; transition: opacity .4s; transform: translateY(20px); }",
    "document.querySelectorAll('.reveal').forEach(function(el){ window.addEventListener('scroll', function(){ el.classList.toggle('visible'); }); });"
);
$r = $classifier->classify($reveal, $revealCtx);
$assert($r->is(SubtreeClassifier::BUCKET_THEME_PRESENTATION), '1: scroll-reveal -> theme_presentation', $summarize($r));
$assert($r->confidence() >= 0.6, '1: scroll-reveal confident', $summarize($r));
// Negative: a decorative animation is NOT a content block.
$assert(! $r->is(SubtreeClassifier::BUCKET_CUSTOM_BLOCK), '1neg: decorative animation is not a block', $summarize($r));

// ---------------------------------------------------------------------------
// 2. custom_block — a repeatable, cohesive content unit a user edits (card grid).
//    No state/network. Hover transition styling must NOT flip it to theme.
// ---------------------------------------------------------------------------
$cards = $element(
    '<section class="cards">'
    . '<article class="card"><img src="a.jpg"><h3>One</h3><p>First card.</p></article>'
    . '<article class="card"><img src="b.jpg"><h3>Two</h3><p>Second card.</p></article>'
    . '<article class="card"><img src="c.jpg"><h3>Three</h3><p>Third card.</p></article>'
    . '</section>'
);
$cardsCtx = new ClassificationContext('.card { border: 1px solid #ccc; transition: transform .2s; }', '');
$r = $classifier->classify($cards, $cardsCtx);
$assert($r->is(SubtreeClassifier::BUCKET_CUSTOM_BLOCK), '2: card grid -> custom_block', $summarize($r));
$assert($r->confidence() >= 0.6, '2: card grid confident', $summarize($r));
// Negative: a static content block is NOT an application.
$assert(! $r->is(SubtreeClassifier::BUCKET_CUSTOM_APPLICATION), '2neg: static content is not an application', $summarize($r));

// ---------------------------------------------------------------------------
// 3. custom_application — stateful, data-driven: form controls feed JS that reads
//    input, fetches data, and mutates state. Input drives logic.
// ---------------------------------------------------------------------------
$configurator = $element(
    '<div class="configurator">'
    . '<h3>Build yours</h3>'
    . '<select name="size"><option>S</option><option>L</option></select>'
    . '<input type="number" name="qty" value="1">'
    . '<button type="button">Update</button>'
    . '<div class="price">$0</div>'
    . '</div>'
);
$configuratorCtx = new ClassificationContext(
    '.configurator { display: grid; }',
    "el.querySelector('select').addEventListener('change', function(){ var qty = el.querySelector('input').value; fetch('/api/price?qty=' + qty).then(function(r){ return r.json(); }).then(function(d){ state.total = d.total; }); });"
);
$r = $classifier->classify($configurator, $configuratorCtx);
$assert($r->is(SubtreeClassifier::BUCKET_CUSTOM_APPLICATION), '3: configurator -> custom_application', $summarize($r));
$assert($r->confidence() >= 0.6, '3: configurator confident', $summarize($r));
$assert(! $r->is(SubtreeClassifier::BUCKET_CUSTOM_PLUGIN), '3neg: input-driven app is not a plugin', $summarize($r));

// ---------------------------------------------------------------------------
// 4. custom_plugin — site-wide functional JS not tied to one component, no input
//    in the subtree, no editable content unit (cookie/consent + analytics).
// ---------------------------------------------------------------------------
$banner = $element('<div id="consent"><span>We use cookies.</span></div>');
$bannerCtx = new ClassificationContext(
    '',
    "document.addEventListener('DOMContentLoaded', function(){ if (!localStorage.getItem('consent')) { fetch('/analytics/init'); } document.querySelectorAll('a[href^=\"http\"]').forEach(function(a){ a.setAttribute('rel','noopener'); }); });"
);
$r = $classifier->classify($banner, $bannerCtx);
$assert($r->is(SubtreeClassifier::BUCKET_CUSTOM_PLUGIN), '4: site-wide script -> custom_plugin', $summarize($r));
$assert($r->confidence() >= 0.6, '4: plugin confident', $summarize($r));
$assert(! $r->is(SubtreeClassifier::BUCKET_CUSTOM_APPLICATION), '4neg: no-input site-wide JS is not an application', $summarize($r));

// ---------------------------------------------------------------------------
// 5. unknown — conflicting/weak: a 2-item list with only a hover transition.
//    Repeatable-content signal ties with presentation signal -> stay UNKNOWN.
// ---------------------------------------------------------------------------
$ambiguous = $element('<ul class="items"><li>Alpha</li><li>Beta</li></ul>');
$ambiguousCtx = new ClassificationContext('.items li { transition: color .2s; }', '');
$r = $classifier->classify($ambiguous, $ambiguousCtx);
$assert($r->is(SubtreeClassifier::BUCKET_UNKNOWN), '5: conflicting signals -> unknown', $summarize($r));
$assert($r->confidence() <= 0.4, '5: unknown low confidence', $summarize($r));

// ---------------------------------------------------------------------------
// 6. unknown — a plain static content block (single paragraph, plain styling).
//    Not an app, not a block: too little signal -> conservative fallback.
// ---------------------------------------------------------------------------
$plain = $element('<div class="wrap"><p>Just a paragraph of content.</p></div>');
$plainCtx = new ClassificationContext('.wrap { padding: 20px; color: #333; }', '');
$r = $classifier->classify($plain, $plainCtx);
$assert($r->is(SubtreeClassifier::BUCKET_UNKNOWN), '6: plain static content -> unknown', $summarize($r));
$assert(! $r->is(SubtreeClassifier::BUCKET_CUSTOM_APPLICATION), '6neg: plain content is not an application', $summarize($r));

// ---------------------------------------------------------------------------
// 7. unknown — a single (non-repeated) content unit is too weak to call a block.
// ---------------------------------------------------------------------------
$single = $element('<article class="feature"><img src="x.jpg"><h3>Solo</h3><p>One card only.</p></article>');
$r = $classifier->classify($single, new ClassificationContext());
$assert($r->is(SubtreeClassifier::BUCKET_UNKNOWN), '7: single content unit -> unknown', $summarize($r));

// ---------------------------------------------------------------------------
// 8. custom_application — Interactivity API markup signals a stateful component.
// ---------------------------------------------------------------------------
$interactive = $element('<div data-wp-interactive="my/store" data-wp-context=\'{"open":false}\'><button data-wp-on--click="actions.toggle">Toggle</button></div>');
$r = $classifier->classify($interactive, new ClassificationContext());
$assert($r->is(SubtreeClassifier::BUCKET_CUSTOM_APPLICATION), '8: data-wp-* -> custom_application', $summarize($r));

// ---------------------------------------------------------------------------
// 9. custom_block — accordion/tabs via interactive role with component-local JS.
// ---------------------------------------------------------------------------
$tabs = $element(
    '<div class="tabs" role="tablist">'
    . '<button role="tab">Tab A</button>'
    . '<button role="tab">Tab B</button>'
    . '<div role="tabpanel"><p>Panel A content.</p></div>'
    . '<div role="tabpanel"><p>Panel B content.</p></div>'
    . '</div>'
);
$tabsCtx = new ClassificationContext(
    '',
    "this.querySelectorAll('[role=tab]').forEach(function(t){ t.addEventListener('click', function(){ t.classList.toggle('active'); }); });"
);
$r = $classifier->classify($tabs, $tabsCtx);
$assert($r->is(SubtreeClassifier::BUCKET_CUSTOM_BLOCK), '9: tabs (role + local JS) -> custom_block', $summarize($r));
$assert(! $r->is(SubtreeClassifier::BUCKET_CUSTOM_PLUGIN), '9neg: component-local tabs are not a plugin', $summarize($r));

// ---------------------------------------------------------------------------
// 10. signals diagnostics are populated for the caller.
// ---------------------------------------------------------------------------
$assert(array_key_exists('scores', $r->signals()), '10: result exposes per-bucket scores');
$assert($r->signals()['interactive_role'] === true, '10: result exposes structural signals', json_encode($r->signals()));
$assert(isset($r->toArray()['bucket'], $r->toArray()['confidence'], $r->toArray()['signals']), '10: toArray shape');

// ---------------------------------------------------------------------------

if ( $failures > 0 ) {
    fwrite(STDERR, PHP_EOL . "SubtreeClassifier unit tests: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "SubtreeClassifier unit tests: {$passes} passed" . PHP_EOL);
