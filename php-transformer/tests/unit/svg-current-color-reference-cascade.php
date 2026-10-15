<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A materialized SVG is a standalone image, so `currentColor` artwork is baked
// with the color the icon inherits. That color must be the author cascade
// winner at the desktop reference viewport. A responsive capture scopes each
// document's stylesheet under a width query, and importance and specificity
// decide the winner, not source order alone.
$svg = '<svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M0 0h20v20H0z" fill="currentColor"></path></svg>';

/** @return list<string> The paint each materialized `<path>` carries. */
$bakedFills = static function (string $styles) use ($svg): array {
    $source = $styles . '<main><p>Body</p></main>'
        . '<footer class="footer"><a href="https://example.com/"><span class="logo">' . $svg . '</span></a></footer>';
    $compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toArray();
    $output = stripslashes(json_encode($compiled, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    preg_match_all('/<path d="M0 0h20v20H0z" fill="([^"]+)"/', $output, $matches);

    return array_values(array_unique(array_diff($matches[1], array('currentColor'))));
};

$cases = array(
    'a desktop-scoped !important color beats the link color' => array(
        '<style>a{color:#0d6efd}</style><style media="(min-width:1000px)">.footer .logo{color:rgb(0,0,0)!important}</style>',
        'rgb(0,0,0)',
    ),
    'a desktop @media color beats the link color' => array(
        '<style>a{color:#0d6efd}@media (min-width:1000px){.footer .logo{color:rgb(0,0,0)}}</style>',
        'rgb(0,0,0)',
    ),
    'a more specific earlier rule beats a later type rule' => array(
        '<style>.footer a{color:rgb(0,128,0)}a{color:#0d6efd}</style>',
        'rgb(0,128,0)',
    ),
    'an earlier !important rule beats a later one' => array(
        '<style>a{color:rgb(0,128,0)!important}a{color:#0d6efd}</style>',
        'rgb(0,128,0)',
    ),
    'a mobile-only color stays out of the desktop paint' => array(
        '<style>a{color:#0d6efd}@media (max-width:600px){.footer .logo{color:rgb(255,0,0)}}</style>',
        '#0d6efd',
    ),
    'a resting color still resolves' => array(
        '<style>a{color:#0d6efd}</style>',
        '#0d6efd',
    ),
);

$failures = array();
foreach ( $cases as $name => [$styles, $expected] ) {
    $fills = $bakedFills($styles);
    if ( array($expected) !== $fills ) {
        $failures[] = $name . ': expected ' . $expected . ', got ' . (array() === $fills ? '(none)' : implode(', ', $fills));
    }
}

if ( array() !== $failures ) {
    throw new RuntimeException("SVG currentColor reference cascade failed:\n" . implode("\n", $failures));
}

echo 'SVG currentColor reference cascade passed: ' . count($cases) . " assertions\n";
