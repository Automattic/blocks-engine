<?php
declare(strict_types=1);

/**
 * Contract for anchors a navigation landmark used to drop entirely.
 *
 * `isNavigationChromeElement()` decides which children of a navigation landmark
 * are decoration rather than content, and decoration is skipped — it reaches the
 * output nowhere. Two authored shapes fell through that test and vanished:
 *
 * - An image brand the author named: `<a class="mark"><img alt="Harbor"></a>`.
 *   The anchor holds no text of its own, so it read as unnamed and therefore
 *   decorative, even though `anchorLabel()` already treats a descendant image's
 *   `alt` as the anchor's label. Worse, an anchor with no text contributes no
 *   item to the SOURCE menu either, so the counts matched and semantic parity
 *   reported `pass` with no findings while the brand disappeared.
 *
 * - A switcher whose class merely contains the word `toggle`:
 *   `<a class="lang-toggle" href="/fr/">FR</a>`. The chrome vocabulary matches
 *   the bare token `toggle`, so an ordinary link to an ordinary URL was read as
 *   a menu control and dropped.
 *
 * The controls below pin the shapes that must STILL be treated as chrome, so the
 * fix cannot be read as "stop dropping anything".
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;

$assert = static function (bool $condition, string $message, string $detail = ''): void {
    global $failures, $passes;
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$css = '<style>nav{display:flex;gap:20px;padding:22px}'
    . '.navlinks{list-style:none;margin:0;padding:0;display:flex;gap:20px}</style>';
$list = '<ul class="navlinks"><li><a href="/a/">A</a></li><li><a href="/b/">B</a></li></ul>';

$transform = static fn (string $extra): array => ( new HtmlTransformer() )->transform(
    $css . '<nav aria-label="Primary">' . $extra . $list . '</nav>',
    array()
)->toArray();

/** @return array{markup: string, status: string, findings: int} */
$outcome = static function (string $extra) use ($transform): array {
    $result = $transform($extra);
    $report = $result['source_reports']['semantic_parity'] ?? array();

    return array(
        'markup' => (string) ($result['serialized_blocks'] ?? ''),
        'status' => (string) ($report['status'] ?? ''),
        'findings' => count(is_array($report['findings'] ?? null) ? $report['findings'] : array()),
    );
};

// -- An image brand the author gave an accessible name survives.
$imageBrand = $outcome('<a class="mark" href="/"><img src="/logo.svg" alt="Harbor"></a>');
$assert(
    str_contains($imageBrand['markup'], '/logo.svg'),
    'an image brand named by its alt text reaches the output',
    substr($imageBrand['markup'], 0, 200)
);
$assert(
    str_contains($imageBrand['markup'], 'Harbor'),
    'the image brand keeps the accessible name the author gave it',
    substr($imageBrand['markup'], 0, 200)
);

// -- A switcher whose class merely contains `toggle` is an ordinary link.
foreach ( array( 'lang-toggle' => '/fr/', 'theme-toggle' => '/dark/' ) as $class => $href ) {
    $switcher = $outcome('<a class="' . $class . '" href="' . $href . '">Switch</a>');
    $assert(
        str_contains($switcher['markup'], $href),
        'an anchor classed ' . $class . ' keeps its destination instead of being dropped as chrome',
        substr($switcher['markup'], 0, 200)
    );
    $assert(
        'pass' === $switcher['status'] && 0 === $switcher['findings'],
        'an anchor classed ' . $class . ' leaves semantic parity clean once it is represented',
        $switcher['status'] . '/' . (string) $switcher['findings']
    );
}

// -- CONTROLS: these must still be treated as chrome and dropped.
$controls = array(
    'a decorative image with an empty alt' => array( '<a class="mark" href="/"><img src="/deco.svg" alt=""></a>', '/deco.svg' ),
    'a menu toggle anchor targeting a fragment' => array( '<a class="menu-toggle" href="#menu">Menu</a>', '#menu' ),
    'a menu toggle anchor declaring aria-expanded' => array( '<a class="nav-toggle" href="/menu/" aria-expanded="false">Menu</a>', '/menu/' ),
    'a button menu toggle' => array( '<button class="toggle" aria-expanded="false">Menu</button>', 'aria-expanded' ),
    'a hamburger element' => array( '<div class="hamburger"><span></span></div>', 'hamburger' ),
    'a separator' => array( '<span class="divider">|</span>', 'divider' ),
    // A separator is decoration by authored intent even when it links somewhere,
    // so the destination escape must not rescue it. The parity reporter reads
    // these two tokens as chrome on the source side; rescuing them here made a
    // clean `pass` become a false count mismatch.
    'a separator anchor with a real href' => array( '<a class="separator" href="/sep/">|</a>', '/sep/' ),
    'a divider anchor with a real href' => array( '<a class="nav-divider" href="/x/">/</a>', '/x/' ),
);

foreach ( $controls as $label => [$markup, $needle] ) {
    $control = $outcome($markup);
    $assert(
        ! str_contains($control['markup'], $needle),
        'control: ' . $label . ' is still treated as navigation chrome',
        substr($control['markup'], 0, 200)
    );
}

// -- An anchor named by an image alone is labelled with that alt text, not with
// the raw `<img>` markup it is built from.
$combined = $outcome(
    '<a class="mark" href="/"><img src="/logo.svg" alt="Harbor"></a>'
    . '<a class="lang-toggle" href="/fr/">FR</a>'
);
$assert(
    str_contains($combined['markup'], '"label":"Harbor"'),
    'an image-named anchor is labelled with its alt text rather than with raw markup',
    substr($combined['markup'], 0, 240)
);
$assert(
    ! str_contains($combined['markup'], '"label":"<img'),
    'an image-named anchor never carries raw markup as its label',
    substr($combined['markup'], 0, 240)
);

// Two anchors beside the list means the branding carrier cannot claim the
// landmark, so both become menu items. Both sides must then count all four.
$assert(
    'pass' === $combined['status'] && 0 === $combined['findings'],
    'a brand and a switcher beside the list leave semantic parity clean',
    $combined['status'] . '/' . (string) $combined['findings']
);
$assert(
    str_contains($combined['markup'], '/fr/'),
    'the switcher keeps its destination when it shares the landmark with a brand',
    substr($combined['markup'], 0, 240)
);

// -- A decorative image ahead of the named one must not decide the answer. The
// accessible-name test scans every image for a non-empty alt, so the label
// builders and the parity reporter have to scan the same way: taking the FIRST
// image instead of the first NAMED image made the two sides disagree again, and
// the block side lost its menu record entirely.
$leadingDecorative = $outcome(
    '<a class="mark" href="/"><img src="/deco.svg" alt=""><img src="/logo.svg" alt="Harbor"></a>'
);
$assert(
    str_contains($leadingDecorative['markup'], 'Harbor'),
    'a decorative image before the named one still leaves the anchor named by the real alt',
    substr($leadingDecorative['markup'], 0, 240)
);
$assert(
    'pass' === $leadingDecorative['status'] && 0 === $leadingDecorative['findings'],
    'a decorative image before the named one leaves both parity sides in agreement',
    $leadingDecorative['status'] . '/' . (string) $leadingDecorative['findings']
);

// -- The menu itself is untouched in every case above: both list anchors remain.
$menuIntact = $outcome('<a class="mark" href="/"><img src="/logo.svg" alt="Harbor"></a>');
$assert(
    str_contains($menuIntact['markup'], '/a/') && str_contains($menuIntact['markup'], '/b/'),
    'the menu keeps both of its own links while the brand is rescued',
    substr($menuIntact['markup'], 0, 200)
);

if ( $failures > 0 ) {
    fwrite(STDERR, "Navigation dropped anchors contract: {$failures} failed, {$passes} passed\n");
    exit(1);
}

echo "Navigation dropped anchors contract passed: {$passes} assertions\n";
