<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

// Neutral reduction of a captured one-page header: a mobile-first burger in a
// controls cluster beside a call link, a desktop menu hidden until the source
// breakpoint, and a hidden mobile panel repeating the same destinations. The
// capture binds the burger to its panel (dialog trigger/panel evidence) and
// keeps the dialog runtime script, so the burger arrives as a runtime DOM
// target. The superseded source burger must still be dropped — core/navigation
// renders the one open button in the burger's slot, and a kept burger would be
// a second, inert menu toggle beside it (its panel no longer exists).
$runtime = <<<'JS'
document.querySelectorAll('[data-blocks-engine-capture-dialog-trigger]').forEach(function (trigger) {
  trigger.addEventListener('click', function () {
    var panel = document.getElementById(trigger.getAttribute('aria-controls'));
    if (!panel) return;
    panel.hidden = !panel.hidden;
    trigger.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
  });
});
JS;

$css = <<<'CSS'
.site-header{position:sticky;top:0}
.header-row{display:flex;align-items:center;justify-content:space-between;height:80px}
.controls{display:flex;align-items:center;gap:12px}
.site-nav{display:none}
.call-link{display:inline-flex;align-items:center;gap:8px}
.burger{display:flex;align-items:center;justify-content:center;width:40px;height:40px}
.mobile-menu{display:block;border-top:1px solid #ddd}
@media (min-width:768px){
.site-nav{display:flex}
.burger{display:none}
.mobile-menu{display:none}
}
CSS;

$links = '<a href="#over">Over</a><a href="#diensten">Diensten</a><a href="#contact">Contact</a>';
$panelLinks = '<ul><li><a href="#over">Over</a></li><li><a href="#diensten">Diensten</a></li><li><a href="#contact">Contact</a></li></ul>';
$html = '<!doctype html><html><head><title>Welkom</title><style>' . $css . '</style></head><body><header class="site-header"><div class="header-row">'
    . '<a href="#hero" class="brand"><img src="logo.png" alt="logo"></a>'
    . '<div class="controls">'
    . '<a href="tel:+31680096783" class="call-link" aria-label="Bel 06 80096783"><svg class="icon-phone" aria-hidden="true"><path d="M22 16.92v3"></path></svg><span class="call-number">06 80096783</span></a>'
    . '<nav aria-label="Hoofdnavigatie" class="site-nav">' . $links . '</nav>'
    . '<button type="button" class="burger" aria-label="Menu openen" aria-expanded="false" aria-controls="mobiel-menu" data-blocks-engine-capture-dialog-trigger="mobiel-menu"><svg class="icon-menu" aria-hidden="true"><path d="M4 5h16"></path><path d="M4 12h16"></path><path d="M4 19h16"></path></svg></button>'
    . '</div></div>'
    . '<div id="mobiel-menu" hidden class="mobile-menu" data-blocks-engine-capture-dialog-panel="mobiel-menu"><nav aria-label="Mobiele navigatie">' . $panelLinks . '</nav></div>'
    . '</header><main><p>Welkom</p></main><script>' . $runtime . '</script></body></html>';

$failures = array();
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(array('path' => 'index.html', 'content' => $html))))->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');

preg_match_all('/<!-- wp:navigation (\{.*?\}) -->/', $markup, $matches);
$navigation = null;
foreach ($matches[1] as $json) {
    $attrs = json_decode($json, true);
    if (is_array($attrs) && 'mobile' === ($attrs['overlayMenu'] ?? null)) {
        $navigation = $navigation ?? $attrs;
        continue;
    }
    $failures[] = 'a header menu must convert once, with the native mobile overlay, not: ' . $json;
}
if (null === $navigation) {
    $failures[] = 'the header menu must convert to core/navigation with the mobile overlay.';
}
if (1 !== count($matches[1])) {
    $failures[] = 'exactly one navigation block must be emitted, found ' . count($matches[1]) . '.';
}

// One visible menu toggle at 390px means the source burger is gone: it may
// neither survive as a kept button nor keep its wiring beside the navigation.
if (str_contains($markup, '<button') || str_contains($markup, 'aria-controls')) {
    $failures[] = 'the superseded source burger must be dropped, not kept as an inert button beside the navigation.';
}
if (str_contains($markup, 'Menu openen')) {
    $failures[] = 'the source burger label must not survive beside the native open button.';
}
foreach (array('#over', '#diensten', '#contact') as $url) {
    if (!str_contains($markup, '"url":"' . $url . '"')) {
        $failures[] = 'destination ' . $url . ' must stay a navigation link.';
    }
}

// Model Core's rendered host and open sheet, including the classes actually
// emitted in block attributes, then read the cascade at both widths.
$dom = new DOMDocument();
@$dom->loadHTML('<html><body></body></html>');
$body = $dom->getElementsByTagName('body')->item(0);
$host = $dom->createElement('nav');
$host->setAttribute('class', 'wp-block-navigation ' . ($navigation['className'] ?? ''));
$opener = $dom->createElement('button');
$opener->setAttribute('class', 'wp-block-navigation__responsive-container-open');
$closed = $dom->createElement('div');
$closed->setAttribute('class', 'wp-block-navigation__responsive-container');
$sheet = $dom->createElement('div');
$sheet->setAttribute('class', 'wp-block-navigation__responsive-container is-menu-open');
foreach (array($opener, $closed, $sheet) as $child) {
    $host->appendChild($child);
}
$body->appendChild($host);

$displays = array();
$boxes = array();
$record = static function (string $selector, array $parsed, string $body, array $conditions, array $widths) use ($opener, $closed, $sheet, &$displays, &$boxes): void {
    foreach (array('opener' => $opener, 'closed' => $closed, 'sheet' => $sheet) as $name => $target) {
        if (!(CssSelectorMatcher::matches($target, $parsed, true)['matches'] ?? false)) {
            continue;
        }
        foreach ($widths as $width) {
            $applies = true;
            foreach ($conditions as $condition) {
                if (preg_match('/min-width:\s*([\d.]+)px/', $condition, $m) && $width < (float) $m[1]) {
                    $applies = false;
                }
                if (preg_match('/max-width:\s*([\d.]+)px/', $condition, $m) && $width > (float) $m[1]) {
                    $applies = false;
                }
            }
            if (!$applies) {
                continue;
            }
            if (preg_match('/(?:^|;)\s*display\s*:\s*([^;!]+)/i', $body, $m)) {
                $displays[$width][$name] = strtolower(trim($m[1]));
            }
            if ('opener' === $name && preg_match('/width:40px/', $body) && preg_match('/height:40px/', $body)) {
                $boxes[$width] = true;
            }
        }
    }
};

foreach ($result['assets'] ?? array() as $asset) {
    if ('css' !== ($asset['kind'] ?? '')) {
        continue;
    }
    (new CssStylesheetTransformer())->visitStyleRules((string) ($asset['content'] ?? ''), static function (string $selector, string $body, array $conditions) use ($record): void {
        $parsed = CssSelectorMatcher::parse($selector);
        $record($selector, $parsed, $body, $conditions, array(390.0, 700.0, 768.0));
    });
}

// 390px: the one toggle is Core's open button in the burger's slot. The source
// button is gone (asserted above) and the marker carries the burger's authored
// box to the opener below the source breakpoint; the open button's own
// visibility below Core's 600px boundary is Core's stylesheet's job.
if (!($boxes[390.0] ?? false)) {
    $failures[] = "at 390px core/navigation's open button must take the burger's authored box in its slot.";
}
// 700px sits between Core's 600px boundary and the source's 768px breakpoint:
// the emitted band keeps the one toggle visible and the closed sheet hidden.
$openerAt700 = $displays[700.0]['opener'] ?? '';
if ('' === $openerAt700 || 'none' === $openerAt700) {
    $failures[] = "in the 600-767px band the emitted CSS must keep the one toggle visible, got display '{$openerAt700}'.";
}
$closedAt700 = $displays[700.0]['closed'] ?? '';
if ('none' !== $closedAt700) {
    $failures[] = "in the 600-767px band the closed container must stay hidden, got display '{$closedAt700}'.";
}
// At the source breakpoint the burger disappears: no emitted rule may force
// the open button to show above it (Core's stylesheet hides it from 600px up).
$openerAt768 = $displays[768.0]['opener'] ?? '';
if ('' !== $openerAt768 && 'none' !== $openerAt768) {
    $failures[] = "above the source breakpoint the open button must follow the burger and disappear, got display '{$openerAt768}'.";
}

// The dropped trigger's runtime wiring is superseded, not a broken target.
foreach ($result['diagnostics'] ?? array() as $diagnostic) {
    if ('runtime_dependency_target_missing' === ($diagnostic['code'] ?? null)) {
        $failures[] = 'the dropped burger\'s runtime wiring must be superseded, not reported missing: ' . json_encode($diagnostic);
    }
    if ('preserved_runtime_island' === ($diagnostic['code'] ?? null) && str_contains((string) ($diagnostic['selector'] ?? ''), 'burger')) {
        $failures[] = 'the superseded burger must not be preserved as a runtime island: ' . json_encode($diagnostic);
    }
}

foreach ($failures as $failure) {
    fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
}
exit(array() === $failures ? 0 : 1);
