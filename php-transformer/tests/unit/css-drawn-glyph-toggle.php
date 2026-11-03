<?php
declare(strict_types=1);

/**
 * An icon-only toggle whose glyph is drawn purely with CSS — empty child
 * elements styled as bars, no text, no SVG — must not lower to an empty
 * core/button: WordPress renders nothing for an empty core/button, so the
 * control disappears from the site. A span-painted glyph already takes the
 * authored-button route; an empty <div> host or wrapper (a hamburger drawn
 * as stacked empty <div> bars) is the same situation and now takes the same
 * route, keeping the bars at the source geometry and the accessible name.
 * A toggle that controls a menu keeps becoming the native navigation open
 * affordance instead, and labelled or content-bearing buttons are untouched.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$css = '.bar{display:flex;align-items:center;justify-content:space-between;padding:16px}'
    . '.logo{font-weight:700;color:#222;text-decoration:none}'
    . '.toggle{display:block;width:24px;height:24px;padding:0;background:transparent;border:0;cursor:pointer}'
    . '.lines{position:relative;width:24px;height:24px}'
    . '.l1,.l2,.l3{position:absolute;left:2px;width:20px;height:2px;background:#333}'
    . '.l1{top:5px}.l2{top:11px}.l3{top:17px}'
    . '.bars{display:block;width:24px;height:24px;background:#333}'
    . '.wrap{display:block}'
    . '@media(max-width:600px){.menu{display:none}.menu.open{display:flex}.toggle{display:block}}'
    . '@media(min-width:601px){.toggle{display:none}}';

$transform = static fn (string $body): array => ( new HtmlTransformer() )->transform(
    '<style>' . $css . '</style>' . $body . '<main><h1>Welcome</h1></main>',
    array()
)->toArray();

$dom = static function (string $markup): DOMXPath {
    $document = new DOMDocument();
    @$document->loadHTML('<?xml encoding="utf-8" ?><body>' . $markup . '</body>');

    return new DOMXPath($document);
};

// --- Retained: icon-only controls whose glyph is painted empty elements. ---

$retainedCases = array(
    'issue fixture: stacked empty <div> bars in a wrapper' => array(
        '<header class="bar"><a class="logo" href="/">Acme</a>'
        . '<button type="button" class="toggle" aria-label="Toggle header"><div class="lines"><div class="l1"></div><div class="l2"></div><div class="l3"></div></div></button></header>',
        'Toggle header',
        'lines',
    ),
    'single empty <div> host painting the glyph itself' => array(
        '<header class="bar"><a class="logo" href="/">Acme</a>'
        . '<button type="button" class="toggle" aria-label="Open menu"><div class="bars"></div></button></header>',
        'Open menu',
        'bars',
    ),
    'span glyph inside an empty <div> wrapper' => array(
        '<header class="bar"><a class="logo" href="/">Acme</a>'
        . '<button type="button" class="toggle" aria-label="Open menu"><div class="wrap"><span class="bars"></span></div></button></header>',
        'Open menu',
        'bars',
    ),
);

foreach ( $retainedCases as $label => list( $header, $accessibleName, $glyphClass ) ) {
    $result = $transform($header);
    $markup = (string) ( $result['serialized_blocks'] ?? '' );
    $xpath = $dom($markup);

    $assert(str_contains($markup, 'authored-button'), $label . ': the control takes the authored-button route', $markup);
    $assert(! str_contains($markup, '<!-- wp:button '), $label . ': the control does not become an empty core/button', $markup);
    $assert(1 === $xpath->query('//button[@aria-label="' . $accessibleName . '"]')->length, $label . ': the saved control keeps the accessible name "' . $accessibleName . '"', $markup);
    $assert(1 === $xpath->query('//button[@aria-label="' . $accessibleName . '"]//*[contains(concat(" ", normalize-space(@class), " "), " ' . $glyphClass . ' ")]')->length, $label . ': the CSS-painted glyph stays inside the button', $markup);
    $assert('pass' === ($result['source_reports']['wp_block_validity']['status'] ?? ''), $label . ': the saved blocks remain valid', $markup);

    $cssOut = implode("\n", array_column(array_filter($result['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
    $assert(str_contains($cssOut, '.' . $glyphClass), $label . ': the glyph paint selector still addresses its source class', $cssOut);
}

// The issue fixture keeps every bar at its source geometry: the saved button
// carries the three empty bar divs with their paint classes.
$issueResult = $transform(
    '<header class="bar"><a class="logo" href="/">Acme</a>'
    . '<button type="button" class="toggle" aria-label="Toggle header"><div class="lines"><div class="l1"></div><div class="l2"></div><div class="l3"></div></div></button></header>'
);
$issueMarkup = (string) ( $issueResult['serialized_blocks'] ?? '' );
$issuePath = $dom($issueMarkup);
foreach ( array('l1', 'l2', 'l3') as $bar ) {
    $assert(1 === $issuePath->query('//button[@aria-label="Toggle header"]//div[contains(concat(" ", normalize-space(@class), " "), " ' . $bar . ' ")]')->length, 'issue fixture: the "' . $bar . '" bar stays inside the saved toggle', $issueMarkup);
}

// --- Menu control: the toggle becomes the native navigation affordance. ---

$nav = '<nav class="menu" id="menu"><a href="#home">Home</a><a href="#about">About</a><a href="#work">Work</a></nav>';
$menuResult = $transform(
    '<header class="bar"><a class="logo" href="/">Acme</a>'
    . '<button type="button" class="toggle" aria-label="Toggle header" aria-controls="menu" aria-expanded="false"><div class="lines"><div class="l1"></div><div class="l2"></div><div class="l3"></div></div></button>'
    . $nav . '</header>'
);
$menuMarkup = (string) ( $menuResult['serialized_blocks'] ?? '' );
$assert(str_contains($menuMarkup, '"overlayMenu":"mobile"'), 'menu toggle: navigation emits the native mobile overlay', $menuMarkup);
$assert(str_contains($menuMarkup, 'blocks-engine-native-responsive-navigation'), 'menu toggle: native overlay marker is present', $menuMarkup);
$assert(! str_contains($menuMarkup, '<!-- wp:button '), 'menu toggle: the CSS-drawn toggle is not emitted as a dead core/button', $menuMarkup);
$assert(! str_contains($menuMarkup, 'authored-button'), 'menu toggle: the native opener replaces the source toggle without duplicating it', $menuMarkup);
foreach ( array('Home', 'About', 'Work') as $link ) {
    $assert(str_contains($menuMarkup, '"label":"' . $link . '"'), 'menu toggle: keeps the "' . $link . '" destination', $menuMarkup);
}

// --- Untouched: labelled and content-bearing buttons stay on their routes. ---

$labelled = $transform('<header class="bar"><button type="button" class="toggle" aria-label="Open menu">Menu<div class="bars"></div></button></header>');
$labelledMarkup = (string) ( $labelled['serialized_blocks'] ?? '' );
$assert(str_contains($labelledMarkup, '<!-- wp:button '), 'word label beside a glyph div stays a core/button', $labelledMarkup);
$assert(str_contains($labelledMarkup, '>Menu<'), 'word label keeps its text', $labelledMarkup);

$unlabelled = $transform('<header class="bar"><button type="button" class="toggle"><div class="bars"></div></button></header>');
$unlabelledMarkup = (string) ( $unlabelled['serialized_blocks'] ?? '' );
$assert(! str_contains($unlabelledMarkup, 'authored-button'), 'an empty control without an accessible name stays off the icon-only route', $unlabelledMarkup);

$textContent = $transform('<header class="bar"><button type="button" class="toggle" aria-label="Close"><div>Close</div></button></header>');
$textContentMarkup = (string) ( $textContent['serialized_blocks'] ?? '' );
$assert(str_contains($textContentMarkup, '<!-- wp:button '), 'a div carrying text stays a text core/button', $textContentMarkup);
$assert(str_contains($textContentMarkup, '>Close<'), 'the div text becomes the button label', $textContentMarkup);

$flowContent = $transform('<header class="bar"><button type="button" class="toggle" aria-label="Open"><div><p>Real content</p></div></button></header>');
$flowContentMarkup = (string) ( $flowContent['serialized_blocks'] ?? '' );
$assert(! str_contains($flowContentMarkup, 'authored-button'), 'a div wrapping real content is not claimed as icon artwork', $flowContentMarkup);
$assert(str_contains($flowContentMarkup, '>Real content<'), 'the content inside the button survives', $flowContentMarkup);

if ( 0 < $failures ) {
    fwrite(STDERR, "css drawn glyph toggle FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "css drawn glyph toggle passed: {$passes} assertions\n";
