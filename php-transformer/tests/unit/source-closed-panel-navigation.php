<?php
declare(strict_types=1);

/**
 * Plain-PHP test script — no PHPUnit. Two source panels start closed and are
 * opened only by their own trigger:
 *
 * - a desktop dropdown panel closed by the `hidden` attribute and named by a
 *   button's `aria-controls`/`aria-expanded`, wrapping its links in one list;
 * - a mobile drawer closed by the `popover` attribute and named by a
 *   `popovertarget` button, with its own `popovertargetaction="hide"` close.
 *
 * Imported, neither may render visible on load. The dropdown is the button's
 * native navigation submenu (closed until its toggle opens it), never a
 * standalone always-visible core/navigation. The drawer is projected onto its
 * opener as a responsive core/navigation, exactly like the equivalent
 * `hidden` + `aria-controls` drawer, so Core's overlay owns open, close and
 * Escape. The popover opener must not claim the dropdown panel instead.
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
$popoverDrawer = '<button type="button" class="menu-btn" popovertarget="mobile-menu" aria-label="Abrir">☰</button>'
    . '<div id="mobile-menu" popover="" class="mobile-menu" aria-label="Drawer"><a href="/">Home</a><a href="/services/one/">One</a><a href="/contact/">Contact</a>'
    . '<button type="button" popovertarget="mobile-menu" popovertargetaction="hide" aria-label="Cerrar">×</button></div>';
$hiddenDrawer = '<button type="button" class="menu-btn" aria-controls="mobile-menu" aria-expanded="false" aria-label="Abrir">☰</button>'
    . '<div id="mobile-menu" hidden="" class="mobile-menu" aria-label="Drawer"><a href="/">Home</a><a href="/services/one/">One</a><a href="/contact/">Contact</a>'
    . '<button type="button" aria-controls="mobile-menu" aria-label="Cerrar">×</button></div>';
$css = '<style>.nav__panel{position:absolute}.menu-btn{display:none}@media(max-width:800px){.nav{display:none}.menu-btn{display:block}}'
    . '.mobile-menu:popover-open{display:flex;flex-direction:column;position:fixed;inset:0}</style>';

$compile = static function (string $header) use ($css): string {
    $html = '<!doctype html><html><head>' . $css . '</head><body><header class="site-header"><a href="/" class="brand">Brand</a>' . $header . '</header><main><p>Body</p></main></body></html>';
    $result = (new ArtifactCompiler())->compile(array(
        'site' => array('name' => 'Closed Panels', 'slug' => 'closed-panels'),
        'entrypoint' => 'website/index.html',
        'files' => array(array('path' => 'website/index.html', 'content' => $html)),
    ))->toArray();

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

// 2. The popover drawer opens from its source trigger through Core's overlay.
$drawer = $anchored($all, 'mobile-menu');
$assert(is_array($drawer) && in_array($drawer['overlayMenu'] ?? 'never', array('mobile', 'always'), true), 'the popover drawer is a responsive navigation that starts closed behind its opener', $blocks);
$assert(str_contains((string) ($drawer['className'] ?? ''), 'blocks-engine-native-navigation-toggle-'), 'the drawer takes over its popover opener', $blocks);
$assert(! str_contains($blocks, 'wp:group {"anchor":"mobile-menu"') && ! str_contains($blocks, 'title="Abrir"') && ! str_contains($blocks, 'title="Cerrar"'), 'neither the open drawer nor dead copies of its open/close buttons render on load', $blocks);

// 3. A popover drawer and the equivalent hidden drawer import the same way.
$hiddenEquivalent = $anchored($navigations($compile($dropdown . $hiddenDrawer)), 'mobile-menu');
$assert(null !== $hiddenEquivalent && $hiddenEquivalent === $drawer, 'the popover drawer uses the same projection as a hidden aria-controls drawer', json_encode(array($drawer, $hiddenEquivalent)));

if (0 !== $failures) {
    exit(1);
}
echo "source-closed-panel-navigation: ok\n";
