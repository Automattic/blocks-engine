<?php
declare(strict_types=1);

/**
 * A captured mobile drawer is exported as a native disclosure: the menu
 * control becomes the summary, and the drawer the details panel, hidden while
 * closed by `details:not([open]) > panel { display:none }`. Controls inside the
 * panel, such as submenu expanders, must not be read as hamburgers whose hidden
 * overlay is the closed panel. That would move the drawer's navigation out of
 * the disclosure and leave the opened menu empty.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
};

$item = static fn (string $href, string $label, string $submenu = ''): string => '<li class="item"><span class="label"><a href="' . $href . '">' . $label . '</a></span>'
    . ('' === $submenu ? '' : '<button aria-expanded="false" aria-haspopup="true" aria-label="' . $label . '" class="expander"></button><ul class="submenu">' . $submenu . '</ul>') . '</li>';
$drawer = '<details class="dla-disclosure"><summary role="button" aria-label="Open navigation menu"><span class="bar"></span><span class="bar"></span><span class="bar"></span></summary>'
    . '<div class="dla-dialog" role="dialog" aria-modal="true"><button class="login"><span>Log In</span></button><nav aria-label="Site"><ul class="menu">'
    . $item('/', 'Home')
    . $item('/about', 'About', $item('/blog', 'Blog'))
    . $item('/shop', 'Shop', $item('/peptides', 'Peptides') . $item('/apparel', 'Apparel'))
    . $item('/contact', 'Contact')
    . '</ul></nav></div></details>';
$css = 'details.dla-disclosure:not([open])>.dla-dialog{display:none!important}';

$result = ( new HtmlTransformer() )->transform('<html><head><style>' . $css . '</style></head><body><header>' . $drawer . '</header></body></html>')->toArray();
$serialized = (string) ($result['serialized_blocks'] ?? '');
$details = strpos($serialized, '<!-- wp:details');
$panel = false === $details ? '' : substr($serialized, $details, (int) strpos($serialized, '<!-- /wp:details -->', $details) - $details);

foreach ( array( '/', '/about', '/shop', '/contact' ) as $url ) {
    $assert(str_contains($panel, '"url":"' . $url . '"'), "the disclosure panel keeps its navigation link to {$url}");
}
$assert(0 === substr_count(substr($serialized, 0, (int) $details), 'wp:navigation-link') && ! str_contains(substr($serialized, (int) strpos($serialized, '<!-- /wp:details -->')), 'wp:navigation-link'), 'no navigation is projected outside the disclosure');

// A separate header toggle must not claim an already-native details panel as
// its hidden projected overlay, even when the authored stylesheet hides it at
// the reference viewport. Its independent links belong to core/details.
$externalToggle = '<header><button class="menu-toggle" aria-expanded="false" aria-controls="mobile-drawer" aria-label="Menu"><span class="bar"></span><span class="bar"></span></button>'
    . '<nav class="desktop-navigation"><a href="/">Home</a><a href="/about">About</a></nav>'
    . '<details class="dla-disclosure"><summary>Open Menu</summary><div id="mobile-drawer" class="dla-dialog" role="dialog" aria-modal="true"><nav><a href="/">Home</a><a href="/about">About</a></nav></div></details></header>';
$externalCss = '#mobile-drawer{display:none}details[open]>#mobile-drawer{display:block}.desktop-navigation{display:flex}';
$projected = ( new HtmlTransformer() )->transform('<html><head><style>' . $externalCss . '</style></head><body>' . $externalToggle . '</body></html>')->toArray();
$projectedMarkup = (string) ($projected['serialized_blocks'] ?? '');
$projectedStart = strpos($projectedMarkup, '<!-- wp:details');
$projectedEnd = false === $projectedStart ? false : strpos($projectedMarkup, '<!-- /wp:details -->', $projectedStart);
$projectedPanel = false === $projectedEnd ? '' : substr($projectedMarkup, $projectedStart, $projectedEnd - $projectedStart);
$assert(str_contains($projectedPanel, '"url":"/about"') && str_contains($projectedPanel, '"url":"/"'), 'a separate header control cannot suppress a native disclosure panel with its own links');

// Native source HTML has the same ownership boundary as captured disclosures.
// A sibling desktop copy cannot make its details-owned mobile panel redundant.
$plain = '<style>.primary{display:flex}.drawer{display:none}@media(max-width:600px){.primary{display:none}.drawer{display:block}}</style><header><nav class="primary"><a href="/">Home</a><a href="/about">About</a></nav><details class="drawer"><summary>Menu</summary><div class="panel"><nav class="mobile"><a href="/">Home</a><a href="/about">About</a></nav></div></details></header>';
$plainMarkup = (new HtmlTransformer())->transform($plain)->toArray()['serialized_blocks'];
$plainStart = strpos($plainMarkup, '<!-- wp:details');
$plainEnd = strpos($plainMarkup, '<!-- /wp:details -->', $plainStart);
$plainPanel = substr($plainMarkup, $plainStart, $plainEnd - $plainStart);
$assert(str_contains($plainPanel, '"url":"/about"') && str_contains($plainPanel, '"url":"/"'), 'ordinary native details retains its menu despite an equivalent desktop sibling');
$assert(!str_contains(substr($plainMarkup, 0, $plainStart), '"overlayMenu":"mobile"'), 'a details-owned mobile counterpart does not fabricate a replacement desktop overlay');

if ( $failures > 0 ) {
    exit(1);
}
fwrite(STDOUT, "Disclosure panel navigation unit tests passed\n");
