<?php
declare(strict_types=1);

/**
 * Source selector bindings remain isolated when routes differ. Published
 * control identity depends on emitted rules, not incidental source context;
 * declaration-addressed-chrome.php covers that contract through both public
 * compilers. Type selectors still need their route-local cascade coverage.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticStyleParityRunner;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$passes = 0;
$failures = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$passes, &$failures): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}" . ('' !== $detail ? " - {$detail}" : '') . "\n");
};

/** @param array<string, string> $pages @return array<string, mixed> */
$plan = static function (array $pages, string $css): array {
    $files = array();
    foreach ( $pages as $path => $html ) {
        $files[] = array( 'path' => $path, 'kind' => 'html', 'content' => $html );
    }
    $files[] = array( 'path' => 'site.css', 'kind' => 'css', 'content' => $css );
    $result = ( new ArtifactCompiler() )->compile(array( 'entrypoint' => array_key_first($pages), 'files' => $files ))->toArray();
    return $result['source_reports']['wordpress_site_plan'] ?? array();
};
/** @return array<string, list<string>> source path => control markers */
$controls = static function (array $plan): array {
    $markers = array();
    foreach ( $plan['pages'] ?? array() as $page ) {
        preg_match_all('/blocks-engine-control-[a-f0-9]{12}-\d+/', (string) ($page['canonical_block_markup'] ?? ''), $matches);
        $markers[(string) $page['source_path']] = array_values(array_unique($matches[0]));
    }
    return $markers;
};
$document = static fn (string $body, string $bodyAttributes = '', string $head = ''): string => '<!doctype html><html><head><link rel="stylesheet" href="site.css">' . $head . '</head><body' . $bodyAttributes . '>' . $body . '</body></html>';
$chrome = '<div class="actions"><a class="btn" href="/go" style="padding:8px;background:#000">Go</a><button class="btn" style="padding:8px;background:#000">Send</button></div>';
$css = '.btn{color:#fff;border-radius:4px}.dark .btn{color:#ff0}[class^=icon-]{display:inline-block}';

// 1. Shared chrome under per-page CMS body classes gets one marker.
$shared = $controls($plan(array(
    'index.html' => $document($chrome . '<main><p>Home</p></main>', ' class="home page-id-1"'),
    'about.html' => $document($chrome . '<main><p>About us</p><p>More</p></main>', ' class="home page-id-2"'),
), $css));
$assert(2 === count($shared['index.html'] ?? array()) && ($shared['index.html'] ?? null) === ($shared['about.html'] ?? array()), 'the same control in shared chrome carries the same marker on every page', (string) json_encode($shared));

// 2. An ancestor class an author selector names (.dark .btn) separates them.
$named = $controls($plan(array(
    'index.html' => $document('<div class="theme">' . $chrome . '</div>'),
    'about.html' => $document('<div class="theme dark">' . $chrome . '</div>'),
), $css));
$assert(2 === count($named['index.html'] ?? array()) && 2 === count($named['about.html'] ?? array()) && array() === array_intersect($named['index.html'], $named['about.html']), 'a selector-observable ancestor class gives the element another marker', (string) json_encode($named));

// Source-tag markers name the tag alone, so a type rule projects to the
//     same text under any author CSS. A type rule from one page's own
//     stylesheet still reaches only that page: shared-chrome extraction never
//     lifts a rule into a global asset through a source-tag marker, even when
//     the shared chrome has elements of that tag.
$tagMarkers = static function (array $plan): array {
    $markers = array();
    foreach ( array_merge($plan['pages'] ?? array(), $plan['template_parts'] ?? array()) as $document ) {
        preg_match_all('/blocks-engine-source-[a-z][a-z0-9-]*-[a-f0-9]{12}-\d+/', (string) ($document['canonical_block_markup'] ?? ''), $matches);
        $markers[(string) ($document['source_path'] ?? $document['slug'] ?? '')] = array_values(array_unique($matches[0]));
    }
    return $markers;
};
$typedChrome = '<header class="site-header"><p class="brand">Brand</p><nav><a href="/">Home</a><a href="/about.html">About</a></nav></header>';
$typed = static fn (string $copy): string => $typedChrome . '<main><section class="band"><p>' . $copy . '</p></section></main><footer class="site-footer"><p>Foot</p></footer>';
$otherCssPlan = $plan(array(
    'index.html' => $document($typed('Copy')),
    'contact.html' => $document($typed('Write')),
    'about.html' => $document($typed('About'), '', '<style>p{color:#f00}</style>'),
), $css . 'section p{color:#123}.site-header .brand{font-weight:700}');
$otherCss = $tagMarkers($otherCssPlan);
$assert(in_array('header', array_column($otherCssPlan['template_parts'] ?? array(), 'area'), true), 'the shared header is extracted as a template part', (string) json_encode(array_column($otherCssPlan['template_parts'] ?? array(), 'area')));
$assert(array() !== ($otherCss['index.html'] ?? array()) && ($otherCss['index.html'] ?? null) === ($otherCss['about.html'] ?? array()), 'pages under different author CSS share source-tag markers', (string) json_encode($otherCss));
$pageColor = static function (array $plan, string $source): array {
    $assets = array_column($plan['assets'] ?? array(), null, 'target_path');
    $sheet = '';
    foreach ( WordPressSitePlan::documentStylesheets($plan, $source) as $stylesheet ) {
        $sheet .= "\n" . (string) ($assets[$stylesheet['target_path']]['content'] ?? '');
    }
    $markup = '';
    foreach ( $plan['template_parts'] ?? array() as $part ) {
        $markup .= (string) ($part['canonical_block_markup'] ?? '');
    }
    foreach ( $plan['pages'] ?? array() as $page ) {
        if ( $source === ($page['source_path'] ?? null) ) {
            $markup .= (string) ($page['canonical_block_markup'] ?? '');
        }
    }
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . StaticStyleParityRunner::candidateHtmlFromSerializedBlocks($markup) . '</body></html>');
    $cascade = new StaticCssCascade($dom, $sheet);
    $colors = array();
    foreach ( $dom->getElementsByTagName('p') as $paragraph ) {
        $colors[trim($paragraph->textContent)] = strtolower((string) ($cascade->resolve($paragraph, array( 'color' ), array())['color'] ?? ''));
    }
    return $colors;
};
$indexColors = $pageColor($otherCssPlan, 'index.html');
$aboutColors = $pageColor($otherCssPlan, 'about.html');
$assert(isset($indexColors['Brand'], $indexColors['Copy'], $indexColors['Foot']) && ! in_array('#f00', $indexColors, true) && '#123' === ($indexColors['Copy'] ?? null), "another page's own type rule reaches neither the chrome nor the content of this page", (string) json_encode($indexColors));
$assert('#f00' === ($aboutColors['Brand'] ?? null) && '#f00' === ($aboutColors['Foot'] ?? null), "the page's own type rule still reaches its own chrome", (string) json_encode($aboutColors));

// 6. Both layouts keep their context-dependent arms in one shared asset.
$sliced = $plan(array(
    'index.html' => $document('<main><p id="lead">Lead</p>' . $chrome . '</main>'),
    'd.html' => $document('<main><p id="lead">Lead</p>' . $chrome . '</main>'),
    'a.html' => $document('<main>' . $chrome . '<p>A</p></main>'),
    'b.html' => $document('<main>' . $chrome . '<p>B</p></main>'),
    'c.html' => $document('<main>' . $chrome . '<p>C</p></main>'),
), $css . 'main>p:first-child{margin:0}#lead{font-weight:700}main>.actions .btn{letter-spacing:1px}');
$siteCopy = '';
$slices = array();
foreach ( $sliced['assets'] ?? array() as $asset ) {
    if ( 'site.css' === ($asset['source_path'] ?? '') ) {
        $siteCopy = (string) $asset['target_path'];
    } elseif ( 'site.css' === ($asset['stylesheet_parent'] ?? '') ) {
        $slices[] = $asset;
    }
}
$assert('' !== $siteCopy && array() === $slices, 'different layouts publish one stylesheet without page slices');
foreach (array('index.html', 'd.html', 'a.html', 'b.html', 'c.html') as $source) {
    $sequence = array_column(WordPressSitePlan::documentStylesheets($sliced, $source), 'target_path');
    $assert(in_array($siteCopy, $sequence, true), $source . ' loads the shared route-scoped stylesheet');
}

if ( 0 < $failures ) {
    fwrite(STDERR, "selector binding isolation FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "selector binding isolation passed: {$passes} assertions\n";
