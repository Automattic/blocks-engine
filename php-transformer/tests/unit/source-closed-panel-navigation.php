<?php
declare(strict_types=1);

/**
 * Plain-PHP test script — no PHPUnit. Two source panels start closed and are
 * opened only by their own trigger:
 *
 * - a desktop dropdown panel closed by the `hidden` attribute and named by a
 *   button's `aria-controls`/`aria-expanded`, wrapping its links in one list;
 * - a mobile drawer closed by the `popover` attribute and named by a
 *   `popovertarget` button, holding mixed content (brand, a captured-dialog
 *   `<nav hidden>` from Data Liberation, CTAs) and its own
 *   `popovertargetaction="hide"` close control labelled in Spanish.
 *
 * Imported, neither may render visible on load. The dropdown is the button's
 * native navigation submenu (closed until its toggle opens it), never a
 * standalone always-visible core/navigation. The whole popover is adopted as
 * the native captured dialog bound to its opener: closed at every viewport,
 * opened by the opener, closed by the hide control (bound by attribute, not
 * label) and by Escape, and its links are shown, not hidden, once it is open.
 *
 * The captured fixture (tests/fixtures/source-popover-captured-panel-drawer.html)
 * is the shape Data Liberation really emits: the inner `<nav>` panel's capture
 * trigger is the popover opener itself, and the hide control is icon-only.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures): void {
    if (! $condition) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
    }
};

$dropdown = '<nav class="nav" aria-label="Primary"><ul class="nav__list" role="list">'
    . '<li class="nav__item"><a class="nav__link" href="/">Home</a></li>'
    . '<li class="nav__item"><button type="button" class="nav__link nav__toggle" aria-expanded="false" aria-controls="nav-services">Services</button>'
    . '<div class="nav__panel" id="nav-services" hidden=""><ul role="list"><li><a href="/services/one/">One</a></li><li><a href="/services/two/">Two</a></li></ul></div></li>'
    . '<li class="nav__item"><a class="nav__link" href="/contact/">Contact</a></li></ul></nav>';
$popoverDrawer = '<button type="button" class="menu-btn" popovertarget="m" aria-label="Abrir menú">Menú</button>'
    . '<div id="m" popover="" class="mobile-menu" aria-label="Menú">'
    . '<div class="mobile-menu__head"><span>Brand</span><button type="button" popovertarget="m" popovertargetaction="hide" aria-label="Cerrar menú">×</button></div>'
    . '<nav hidden="" id="data-blocks-engine-capture-dialog-0" data-blocks-engine-capture-dialog-panel="data-blocks-engine-capture-dialog-0" data-blocks-engine-capture-existing-panel="{&quot;opened&quot;:{&quot;style&quot;:&quot;display:block&quot;}}">'
    . '<p>Servicios</p><ul><li><a href="/a/">A</a></li><li><a href="/b/">B</a></li></ul></nav>'
    . '<div class="mobile-menu__actions"><a href="/contact/">Reservar cita</a><a href="tel:+1">Call</a></div></div>';
$css = '<style>.nav__panel{position:absolute}.menu-btn{display:none}@media(max-width:800px){.nav{display:none}.menu-btn{display:block}}'
    . '.mobile-menu:popover-open{display:flex;flex-direction:column;position:fixed;inset:0}</style>';
$generatedAssets = array();

$compile = static function (string $header) use ($css, &$generatedAssets): string {
    $html = '<!doctype html><html><head>' . $css . '</head><body><header class="site-header"><a href="/" class="brand">Brand</a>' . $header . '</header><main><p>Body</p></main></body></html>';
    $result = (new ArtifactCompiler())->compile(array(
        'site' => array('name' => 'Closed Panels', 'slug' => 'closed-panels'),
        'entrypoint' => 'website/index.html',
        'files' => array(array('path' => 'website/index.html', 'content' => $html)),
    ))->toArray();
    $generatedAssets = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';

    return (string) ($result['serialized_blocks'] ?? '');
};
/** @return list<array<string, mixed>> */
$navigations = static function (string $blocks): array {
    preg_match_all('/<!-- wp:navigation (\{.*?\}) (?:\/)?-->/', $blocks, $matches);
    return array_map(static fn (string $json): array => (array) json_decode($json, true), $matches[1]);
};
$anchored = static function (array $navigations, string $anchor): ?array {
    foreach ($navigations as $attrs) {
        if ($anchor === ($attrs['anchor'] ?? null)) return $attrs;
    }
    return null;
};

// 1. The hidden dropdown panel stays closed behind its own button.
$blocks = $compile($dropdown . $popoverDrawer);
$all = $navigations($blocks);
$assert(null === $anchored($all, 'nav-services'), 'the hidden dropdown panel is not a standalone, always-visible navigation', $blocks);
$assert(1 === preg_match('/<!-- wp:navigation-submenu \{[^}]*"label":"Services"[^}]*\} -->(.*?)<!-- \/wp:navigation-submenu -->/s', $blocks, $submenu), 'the dropdown button becomes a native submenu', $blocks);
$assert(str_contains($submenu[1] ?? '', '"url":"/services/one/"') && str_contains($submenu[1] ?? '', '"url":"/services/two/"'), 'the submenu holds the panel links', $blocks);
$assert(1 === substr_count($blocks, '"url":"/services/two/"'), 'the dropdown links are emitted once, inside the closed submenu', $blocks);

// 2. The whole mixed-content popover is closed on load at every viewport: it
// is one captured dialog (no `open`), whose closed rule carries no media query.
$drawerMatched = 1 === preg_match('/<!-- wp:([a-z0-9-]+\/captured-dialog) (\{.*?\}) -->(<dialog[^>]*>)(.*?)<\/dialog><!-- \/wp:\1 -->/s', $blocks, $drawer);
$assert($drawerMatched, 'the popover becomes one captured dialog', $blocks);
$drawerAttrs = (array) json_decode($drawer[2] ?? '{}', true);
$dialogTag = $drawer[3] ?? '';
$drawerBody = $drawer[4] ?? '';
$assert('m' === ($drawerAttrs['dialogId'] ?? null) && str_contains($dialogTag, 'id="m"') && ! preg_match('/\sopen[\s=>]/', $dialogTag), 'the popover keeps its id and starts closed', $dialogTag);
foreach (array('Brand', 'Servicios', '"url":"/contact/"', '"url":"tel:+1"') as $content) {
    $assert(str_contains($drawerBody, $content), 'the closed dialog holds the popover content: ' . $content, $drawerBody);
}
$assert(! str_contains($blocks, '"anchor":"m"') && ! str_contains($blocks, 'wp:group {"anchor":"m"'), 'no open group copy of the popover renders', $blocks);
$assert(1 === substr_count($blocks, 'Reservar cita') && 1 === substr_count($blocks, 'tel:+1') && 1 === substr_count($blocks, 'href="/a/"'), 'the popover content is emitted once, inside the closed dialog', $blocks);
$assert(str_contains($generatedAssets, 'dialog[data-blocks-engine-triggers]:not([open]){display:none!important}') && ! preg_match('/@media[^{]*\{\s*dialog\[data-blocks-engine-triggers\]:not/', $generatedAssets), 'the closed rule applies at every viewport (390 and 1440 alike)', '');

// 3. The popovertarget opener opens it.
$triggerIds = (array) ($drawerAttrs['triggerIds'] ?? array());
$assert(1 === count($triggerIds) && 1 === preg_match('/<!-- wp:button \{"anchor":"' . preg_quote((string) ($triggerIds[0] ?? '-'), '/') . '"[^}]*\} --><div id="[^"]+"[^>]*><button[^>]*title="Abrir menú">Menú<\/button>/', $blocks), 'the dialog is bound to the popovertarget opener, which renders as an operable button', $blocks);

// 4. Open, the captured navigation links are present and not hidden.
$assert(1 === preg_match('/<nav\b([^>]*)>(.*?)<\/nav>/s', $drawerBody, $nav), 'the captured nav renders inside the dialog', $drawerBody);
$assert(! str_contains($nav[1] ?? 'hidden', 'hidden') && ! str_contains($drawerBody, 'hidden=""') && ! str_contains($drawerBody, '"hidden":true'), 'the captured nav is not hidden inside the open dialog', $drawerBody);
$assert(str_contains($nav[2] ?? '', '<a href="/a/">A</a>') && str_contains($nav[2] ?? '', '<a href="/b/">B</a>'), 'the nav links are usable inside the dialog', $drawerBody);

// 5. The source hide control closes it, bound by its attribute, not its label.
$closerIds = (array) ($drawerAttrs['closerIds'] ?? array());
$assert(1 === count($closerIds) && str_contains($dialogTag, 'data-blocks-engine-closers="' . ($closerIds[0] ?? '-') . '"'), 'the dialog names the popovertargetaction="hide" control as its closer', $dialogTag);
$assert(1 === preg_match('/<!-- wp:button \{"anchor":"' . preg_quote((string) ($closerIds[0] ?? '-'), '/') . '"[^}]*\} --><div id="[^"]+"[^>]*><button[^>]*title="Cerrar menú">×<\/button>/', $drawerBody), 'the hide control survives inside the dialog with the bound id', $drawerBody);
$assert(str_contains($generatedAssets, 'data-blocks-engine-closers'), 'the dialog view script closes from its declared closers', '');
// The bound close needs no English label: a Spanish label and non-× glyph still bind.
$spanish = $compile($dropdown . str_replace('aria-label="Cerrar menú">×', 'aria-label="Cerrar menú">Cerrar', $popoverDrawer));
$assert(1 === preg_match('/data-blocks-engine-closers="([^"]+)"/', $spanish, $spanishCloser) && str_contains($spanish, 'id="' . $spanishCloser[1] . '"'), 'a hide control with no English label or glyph is still bound', $spanish);

// 6. The captured shape Data Liberation really emits: the drawer's inner
// `<nav>` is itself a hidden captured dialog panel whose capture trigger IS
// the popover opener, and the hide control is icon-only (svg + aria-label) in
// a flex top bar beside the logo. Opening the popover opens that panel, so its
// links are the popover's open content; the icon-only closer must survive.
$capturedFixture = (string) file_get_contents(dirname(__DIR__) . '/fixtures/source-popover-captured-panel-drawer.html');
$compileCaptured = static function (string $html): string {
    $result = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array('index.html' => $html),
    ))->toArray();

    return (string) ($result['serialized_blocks'] ?? '');
};
$captured = $compileCaptured($capturedFixture);
$capturedMatched = 1 === preg_match('/<!-- wp:([a-z0-9-]+\/captured-dialog) (\{.*?\}) -->(<dialog[^>]*>)(.*?)<\/dialog><!-- \/wp:\1 -->/s', $captured, $capturedDrawer);
$assert($capturedMatched, 'the captured popover becomes one captured dialog', $captured);
$capturedAttrs = (array) json_decode($capturedDrawer[2] ?? '{}', true);
$capturedTag = $capturedDrawer[3] ?? '';
$capturedBody = $capturedDrawer[4] ?? '';
$assert('mobile-menu' === ($capturedAttrs['dialogId'] ?? null) && ! preg_match('/\sopen[\s=>]/', $capturedTag), 'the captured popover dialog is closed on load', $capturedTag);
foreach (array('Home', 'About', 'One', 'Two', 'Three', 'Blog', 'Contact') as $label) {
    $assert(str_contains($capturedBody, '"label":"' . $label . '"') || str_contains($capturedBody, '>' . $label . '</a>'), 'the opener-triggered panel link renders inside the dialog: ' . $label, $capturedBody);
}
$assert(str_contains($capturedBody, '>Services</p>'), 'the panel label renders inside the dialog', $capturedBody);
$assert(! str_contains($capturedBody, 'hidden=""') && ! str_contains($capturedBody, '"hidden":true') && ! str_contains($capturedBody, 'data-blocks-engine-capture-dialog-panel'), 'the opener-triggered panel is not left hidden inside the dialog', $capturedBody);
$assert(! preg_match('/"overlayMenu":"(?:mobile|always)"/', $capturedBody) && ! str_contains($capturedBody, 'blocks-engine-native-responsive-navigation'), 'no responsive overlay navigation hides links inside the dialog', $capturedBody);
$capturedClosers = (array) ($capturedAttrs['closerIds'] ?? array());
$assert(1 === preg_match('/<button\b[^>]*\bid="([^"]+)"[^>]*aria-label="Close the menu"[^>]*>\s*<svg/', $capturedBody, $closeButton) && in_array($closeButton[1] ?? '', $capturedClosers, true), 'the icon-only hide control renders inside the dialog with its accessible name and bound closer id', $capturedBody);
$assert(1 === count((array) ($capturedAttrs['triggerIds'] ?? array())) && str_contains($captured, '"anchor":"' . ($capturedAttrs['triggerIds'][0] ?? '-') . '"'), 'the popover opener still opens the dialog', $captured);

// A toggle wired to a list inside the dialog does not turn that list into a
// second, Core overlay hamburger: the dialog is already the open mechanism.
$innerToggle = $compileCaptured(str_replace(
    '<ul role="list" class="mobile-menu__main"><li><a href="/">Home</a></li>',
    '<button type="button" aria-controls="inner-list" aria-expanded="false" aria-label="Expand"><svg></svg></button><ul id="inner-list" role="list" class="mobile-menu__main"><li><a href="/">Home</a></li>',
    $capturedFixture
));
$innerBody = 1 === preg_match('/<dialog[^>]*>(.*?)<\/dialog>/s', $innerToggle, $innerDrawer) ? $innerDrawer[1] : '';
$assert(str_contains($innerBody, '"label":"Contact"') && ! preg_match('/"overlayMenu":"(?:mobile|always)"/', $innerBody) && ! str_contains($innerBody, 'blocks-engine-native-responsive-navigation'), 'captured dialog content never becomes a responsive overlay navigation', $innerBody);

if (0 !== $failures) {
    exit(1);
}
echo "source-closed-panel-navigation: ok\n";
