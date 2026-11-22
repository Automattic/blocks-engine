<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A capture composes each route from a desktop and a mobile document and
// switches between them with one width query per route; the width can differ
// between routes (one route switched at 402px, another at 991px). The header
// inside the desktop document is the same on every route: its burger shows,
// opening an offcanvas panel, only up to 991px by the header's own rules.
// The document switch is the route's: it hides the whole document, and the
// composed output keeps it. The burger's collapse boundary, and with it the
// content-addressed native toggle marker, must come from the header's own
// rules alone, so the header stays one shareable part across routes; the
// switch still hides the inactive document on each route.
$css = '.navbar-toggler{display:none}'
    . '.offcanvas{position:fixed;top:0;right:0;bottom:0;display:flex;flex-direction:column;visibility:hidden;transform:translateX(100%)}'
    . '@media(max-width:991px){.navbar-toggler{display:block;width:35px;height:28px}}';
$header = '<header><nav class="navbar" aria-label="Main menu"><div class="container"><a class="navbar-brand" href="https://example.com/">Brand</a>'
    . '<button class="navbar-toggler" type="button" data-bs-toggle="offcanvas" data-bs-target="#mob-nav" aria-controls="mob-nav"><span></span></button>'
    . '<div id="mob-nav" class="offcanvas offcanvas-end" tabindex="-1"><div id="mobileMenu" class="h-100 d-flex flex-column pb-5">'
    . '<ul class="nav navbar-nav"><li class="nav-item"><a class="nav-link" href="https://example.com/about/">About</a></li>'
    . '<li class="nav-item"><a class="nav-link" href="https://example.com/events/">Events</a></li></ul>'
    . '</div></div></div></nav></header>';
$route = static function (int $switch) use ($header, $css): string {
    $documents = '<div class="data-blocks-engine-capture-desktop-document" data-blocks-engine-capture-document-scope="">' . $header . '<main><h1>Contact</h1></main></div>'
        . '<div class="data-blocks-engine-capture-mobile-document" data-blocks-engine-capture-document-scope=""><main><h1>Contact</h1></main></div>';
    $switchCss = '.data-blocks-engine-capture-mobile-document{display:none!important}'
        . '@media(max-width:' . $switch . 'px){.data-blocks-engine-capture-desktop-document{display:none!important}.data-blocks-engine-capture-mobile-document{display:contents!important}}';
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Contact</title><style>' . $css . $switchCss . '</style></head><body>' . $documents . '</body></html>';
};
$compile = static function (string $html): array {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(array('path' => 'index.html', 'content' => $html))))->toArray();
    $json = stripslashes(json_encode($result, JSON_UNESCAPED_SLASHES));
    preg_match_all('/blocks-engine-native-navigation-toggle-[a-f0-9]{12}/', (string) ($result['serialized_blocks'] ?? ''), $matches);
    $markers = array_values(array_unique($matches[0]));
    if (1 !== count($markers)) throw new RuntimeException('The desktop document header must project one native responsive navigation toggle, found ' . count($markers));

    return array($markers[0], $json);
};

[$narrow, $narrowJson] = $compile($route(402));
[$wide, $wideJson] = $compile($route(991));
if ($narrow !== $wide) throw new RuntimeException("The route's document switch width must not change the header's toggle marker: {$narrow} vs {$wide}");
foreach (array($narrowJson, $wideJson) as $json) {
    if (1 !== preg_match('/@media\(min-width:600px\) and \(max-width:991px\)\{[^{}]*' . preg_quote($narrow, '/') . '/', $json)) throw new RuntimeException('The header must keep its own 991px collapse boundary on every route.');
}
if (!str_contains($narrowJson, '@media(max-width:402px){.data-blocks-engine-capture-desktop-document{display:none!important}') || !str_contains($wideJson, '@media(max-width:991px){.data-blocks-engine-capture-desktop-document{display:none!important}')) throw new RuntimeException('Each route must keep its own document switch.');

fwrite(STDOUT, "navigation-collapse-boundary-ignores-document-switch: ok\n");
