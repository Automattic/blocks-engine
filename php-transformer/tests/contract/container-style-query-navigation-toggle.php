<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

// A site builder switches a header menu between presets with a custom
// property: a wrapper sets `--preset: wide`, and `--preset: narrow` below
// 1024px. Component rules key off `@container style(--preset: ...)` to set
// `--menu-display` / `--toggle-display`, and the menu and its toggle read
// those through `var()`. The `<nav>` landmark's own `display` differs from
// its flex list, so the landmark stays a carrier group around the native
// navigation.
//
// On mobile the source hides the menu and shows the burger. The import must:
//  - project the menu as a responsive core/navigation (`overlayMenu:mobile`)
//    bound to that toggle, even though the toggle sits beside the carrier
//    landmark rather than beside the list;
//  - keep the opener mobile-only, switching at the source's 1023px boundary
//    rather than showing it at every width (the toggle is hidden on desktop,
//    but only through the style query);
//  - dissolve the carrier landmark inside that range, because the source's
//    `display:var(--menu-display)` hides it there, and Core's open button
//    lives inside it.
$css = <<<'CSS'
.site-header{display:flex;justify-content:space-between;align-items:center;padding:16px}
.brand{font-weight:700;color:#111;text-decoration:none}
.presets{--preset:wide}
@media screen and (max-width:1023px){.presets{--preset:narrow}}
@container style(--preset: narrow){.presets .menu{--menu-display:none;--toggle-display:flex}}
@container style(--preset: wide){.presets .menu{--menu-display:block;--toggle-display:none}}
.navbar{display:var(--menu-display, block)}
.items{display:flex;gap:24px;list-style:none;margin:0;padding:0}
.opener{display:var(--toggle-display) !important}
.burger{width:32px;height:32px;padding:0;border:0;background:transparent}
CSS;
$html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Presets</title><style>' . $css . '</style></head><body>'
    . '<header class="site-header"><a class="brand" href="/">Acme</a>'
    . '<div class="presets"><div class="menu">'
    . '<nav class="navbar" aria-label="Site"><ul class="items"><li><a href="/about/">About</a></li><li><a href="/work/">Work</a></li><li><a href="/contact/">Contact</a></li></ul></nav>'
    . '<nav class="opener" aria-label="Site"><button type="button" class="burger" aria-label="Open menu" aria-haspopup="dialog"><svg viewBox="0 0 24 24" width="24" height="24"><path d="M3 6h18M3 12h18M3 18h18" stroke="currentColor"/></svg></button></nav>'
    . '</div></div></header><main><h1>Hello</h1><p>Body text.</p></main></body></html>';

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(array('path' => 'index.html', 'content' => $html))))->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$failures = array();

$navigations = array();
if (preg_match_all('/<!-- wp:navigation (\{.*?\}) (?:\/)?-->/s', $markup, $matches)) {
    foreach ($matches[1] as $json) {
        $navigations[] = json_decode($json, true);
    }
}
$navigation = is_array($navigations[0] ?? null) ? $navigations[0] : array();
if (1 !== count($navigations)) {
    $failures[] = 'the header menu must project as exactly one core/navigation, found ' . count($navigations) . '.';
}
if ('mobile' !== ($navigation['overlayMenu'] ?? null)) {
    $failures[] = 'the menu the source collapses behind a burger must be a responsive navigation (overlayMenu:mobile), got ' . json_encode($navigation['overlayMenu'] ?? null) . '.';
}
$marker = preg_match('/blocks-engine-native-navigation-toggle-[0-9a-f]+/', (string) ($navigation['className'] ?? ''), $markerMatch) ? $markerMatch[0] : '';
if ('' === $marker) {
    $failures[] = 'the responsive navigation must carry the toggle marker of the source burger it replaces.';
}
if (str_contains($markup, 'Open menu')) {
    $failures[] = 'the superseded source burger must be dropped, not kept beside the navigation open button.';
}

$activeAt = static function (array $conditions, float $width): bool {
    foreach ($conditions as $condition) {
        $condition = (string) $condition;
        if (preg_match('/min-width:\s*([\d.]+)px/', $condition, $m) && $width < (float) $m[1]) {
            return false;
        }
        if (preg_match('/max-width:\s*([\d.]+)px/', $condition, $m) && $width > (float) $m[1]) {
            return false;
        }
    }
    return true;
};
$rules = array();
if ('' !== $marker) {
    foreach ($result['assets'] ?? array() as $asset) {
        if ('css' !== ($asset['kind'] ?? '')) {
            continue;
        }
        (new CssStylesheetTransformer())->visitStyleRules((string) ($asset['content'] ?? ''), static function (string $selector, string $body, array $conditions) use ($marker, &$rules): void {
            if (str_contains($selector, $marker)) {
                $rules[] = array($selector, $body, $conditions);
            }
        });
    }
}

// The opener shows in the source's collapsed range and nowhere else.
$openerShown = static function (float $width) use ($rules, $activeAt): bool {
    foreach ($rules as [$selector, $body, $conditions]) {
        if (str_ends_with(trim($selector), '>.wp-block-navigation__responsive-container-open')
            && preg_match('/(?:^|;)display:flex!important/', $body)
            && $activeAt($conditions, $width)) {
            return true;
        }
    }
    return false;
};
if ('' !== $marker && ! $openerShown(768.0)) {
    $failures[] = '768: the open button must show across the source collapsed range (up to 1023px), not only below Core\'s 600px switch.';
}
if ('' !== $marker && $openerShown(1440.0)) {
    $failures[] = '1440: the open button must not show on desktop; the source hides its burger there through the style query.';
}

// The carrier landmark the source hides on mobile is dissolved around the host.
foreach (array(390.0, 768.0) as $width) {
    $dissolved = false;
    foreach ($rules as [$selector, $body, $conditions]) {
        if (str_contains($selector, '.wp-block-group:has(>') && ! str_contains($selector, '>:not(')
            && preg_match('/(?:^|;)display:contents!important/', $body) && $activeAt($conditions, $width)) {
            $dissolved = true;
        }
    }
    if ('' !== $marker && ! $dissolved) {
        $failures[] = "$width: the carrier landmark the source hides on mobile must be dissolved around the navigation, or it hides Core's open button with it.";
    }
}
foreach ($rules as [$selector, $body, $conditions]) {
    if (str_contains($selector, '.wp-block-group:has(>') && $activeAt($conditions, 1440.0)) {
        $failures[] = '1440: the carrier rules must stay inside the collapsed range; on desktop the source shows the landmark.';
    }
}

foreach ($failures as $failure) {
    fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
}
exit(array() === $failures ? 0 : 1);
