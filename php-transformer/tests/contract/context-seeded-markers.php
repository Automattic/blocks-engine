<?php
declare(strict_types=1);

/**
 * Per-element engine markers are named from the source element's context
 * (#2755): the same element under the same author CSS gets the same marker on
 * every page, and any difference an author selector could observe gets a
 * different one. A rule arm naming a marker in a stylesheet two pages share
 * must never reach an element its selector did not match on the other page.
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

// 3. So does a body class only an attribute predicate observes.
$predicate = $controls($plan(array(
    'index.html' => $document($chrome, ' class="page-id-1 home"'),
    'about.html' => $document($chrome, ' class="icon-page page-id-2"'),
), $css));
$assert(($predicate['index.html'] ?? null) !== ($predicate['about.html'] ?? null), 'a body class read by a [class^=…] predicate gives the element another marker', (string) json_encode($predicate));

// 4. And a different position among siblings (nth-child, + and ~ observe it).
$position = $controls($plan(array(
    'index.html' => $document('<div class="theme">' . $chrome . '</div>'),
    'about.html' => $document('<div class="theme"><aside class="notice">Notice</aside>' . $chrome . '</div>'),
), $css));
$assert(($position['index.html'] ?? null) !== ($position['about.html'] ?? null), 'another sibling structure gives the element another marker', (string) json_encode($position));

// 5. And different author CSS on the page (an inline style of its own).
$styled = $controls($plan(array(
    'index.html' => $document($chrome),
    'about.html' => $document($chrome, '', '<style>.btn{letter-spacing:1px}</style>'),
), $css));
$assert(($styled['index.html'] ?? null) !== ($styled['about.html'] ?? null), 'a page with other author CSS gives the element another marker', (string) json_encode($styled));

// 5b. Source-tag markers name the tag alone, so a type rule projects to the
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

// 6. The site-wide copy is the projection most pages share; the pages that
//    need the same extra slice share one asset, enqueued on each of them
//    right after the site-wide copy. `main>.actions .btn` depends on where
//    the actions sit in `main`, so its control arm stays per element and
//    differs between the two layouts.
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
$assert('' !== $siteCopy && 1 === count($slices) && array( 'index.html', 'd.html' ) === array_column($slices[0]['scopes'], 'source_path'), 'the two pages that need the same slice share one published asset', (string) json_encode(array_map(static fn (array $slice): array => array_column($slice['scopes'], 'source_path'), $slices)));
foreach ( array( 'index.html', 'd.html', 'a.html', 'b.html', 'c.html' ) as $source ) {
    $own = array_values(array_filter($slices, static fn (array $slice): bool => in_array($source, array_column($slice['scopes'], 'source_path'), true)));
    // A stylesheet split for visitors has an editor copy; each context loads one.
    foreach ( array( 'frontend', 'editor' ) as $context ) {
        $rows = array_values(array_filter(WordPressSitePlan::documentStylesheets($sliced, $source), static fn (array $row): bool => in_array($row['stylesheet_target'], array( 'both', $context ), true)));
        $sequence = array_column($rows, 'target_path');
        $at = array_search($siteCopy, $sequence, true);
        if ( false === $at ) $at = array_search(preg_replace('/\.css$/', '.editor.css', $siteCopy), $sequence, true);
        $slice = array() === $own ? null : $own[0]['target_path'];
        $sliceIn = null === $slice ? null : (in_array($slice, $sequence, true) ? $slice : preg_replace('/\.css$/', '.editor.css', $slice));
        $assert(false !== $at && count($own) <= 1 && (null === $sliceIn || ($sequence[$at + 1] ?? null) === $sliceIn), "{$source} enqueues its slice right after the site-wide copy ({$context})", (string) json_encode($sequence));
    }
}

if ( 0 < $failures ) {
    fwrite(STDERR, "context-seeded markers FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "context-seeded markers passed: {$passes} assertions\n";
