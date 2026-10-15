<?php
declare(strict_types=1);

/**
 * Generated theme CSS must not grow with pages x elements (#2755).
 *
 * N pages sharing one layout and one stylesheet compile that stylesheet to one
 * site-wide asset plus, at most, one slice shared by every page that needs the
 * same extra rules. Each page still resolves the same styles under its own
 * enqueue sequence.
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

// A template every page shares. The body carries a per-page CMS class and
// every page has its own note: neither may give the shared chrome, the list
// or the control a page-specific identity. `#note-0` is a rule that genuinely
// targets one page's element.
$authorCssBody = 'ol,ul{list-style:none}button{padding:.5em 1em}.cta{background-color:#123456;color:#fff}p{margin-top:0;margin-bottom:1.25em}.intro{font-size:1rem}#note-0{color:#2d7bd3}.site-header a{text-decoration:none}';

$page = static function (string $slug, int $index): string {
    return '<!doctype html><html><head><title>Page ' . $slug . '</title><link rel="stylesheet" href="base.css"></head>'
        . '<body class="page page-id-' . (100 + $index) . '"><header class="site-header"><nav><a href="/a.html">Home</a><a href="/b.html">Catalog</a></nav></header>'
        . '<main><ul class="list"><li>One</li><li>Two</li></ul><p class="intro">Intro copy.</p><button class="cta">Send</button><p id="note-' . $index . '">Note ' . $slug . '</p></main></body></html>';
};

$compiledFor = static function (int $pageCount) use ($page, $authorCssBody): array {
    $files = array();
    foreach ( array_slice(range('a', 'j'), 0, $pageCount) as $index => $letter ) {
        $files[] = array( 'path' => $letter . '.html', 'kind' => 'html', 'content' => $page($letter, $index) );
    }
    $files[] = array( 'path' => 'base.css', 'kind' => 'css', 'content' => $authorCssBody );
    $result = ( new ArtifactCompiler() )->compile(array( 'entrypoint' => 'a.html', 'files' => $files ))->toArray();
    $plan = $result['source_reports']['wordpress_site_plan'] ?? array();
    $css = 0;
    foreach ( $plan['assets'] ?? array() as $asset ) {
        if ( 'css' === ($asset['kind'] ?? '') ) {
            $css += strlen((string) ($asset['content'] ?? ''));
        }
    }
    return array( 'css_bytes' => $css, 'plan' => $plan );
};

$small = $compiledFor(2);
$medium = $compiledFor(3);
$large = $compiledFor(10);

$assert(array() !== ($small['plan']['pages'] ?? array()) && 10 === count($large['plan']['pages'] ?? array()), 'both sites compile to a WordPress site plan');
// Ten pages that share a layout must not even double what two pages need.
$assert($large['css_bytes'] < 2 * $small['css_bytes'], 'ten pages sharing a stylesheet need less than twice the generated CSS of two pages', $small['css_bytes'] . ' vs ' . $large['css_bytes']);

// The shared stylesheet compiles to one site-wide asset, the projection most
// pages share, whose bytes do not depend on how many pages consume it, plus at
// most one slice for the page that differs.
$stylesheetAssets = static function (array $plan): array {
    $site = array();
    $slices = array();
    foreach ( $plan['assets'] ?? array() as $asset ) {
        if ( 'css' !== ($asset['kind'] ?? '') ) {
            continue;
        }
        if ( 'base.css' === ($asset['source_path'] ?? '') ) {
            $site[] = $asset;
        } elseif ( 'base.css' === ($asset['stylesheet_parent'] ?? '') ) {
            $slices[] = $asset;
        }
    }
    return array( $site, $slices );
};
[ $mediumSite, $mediumSlices ] = $stylesheetAssets($medium['plan']);
[ $largeSite, $largeSlices ] = $stylesheetAssets($large['plan']);
$assert(1 === count($mediumSite) && 1 === count($largeSite), 'the shared stylesheet compiles to one site-wide asset', (string) json_encode(array( count($mediumSite), count($largeSite) )));
$assert(($mediumSite[0]['content'] ?? null) === ($largeSite[0]['content'] ?? ''), 'the site-wide asset is byte-identical however many pages consume it');
$assert(count($largeSlices) <= 1 && count($largeSlices) === count($mediumSlices) && ($largeSlices[0]['content'] ?? null) === ($mediumSlices[0]['content'] ?? null), 'the page slice does not grow with the page count either', (string) count($largeSlices));
$sliceScopes = array_map(static fn (array $scope): string => (string) ($scope['source_path'] ?? ''), $largeSlices[0]['scopes'] ?? array());
$assert(array() === $largeSlices || array( 'a.html' ) === $sliceScopes, 'only the page whose own element a rule targets (#note-0) loads a slice', (string) json_encode($sliceScopes));

// The control, the list and the shared chrome carry the same per-element
// markers on every page; the per-page body class does not leak into them.
$markersOf = static fn (string $markup): array => preg_match_all('/blocks-engine-(?:control|semantic|richtext|attribute|root-child)-[a-f0-9]{12}-\d+/', $markup, $matches) ? array_values(array_unique($matches[0])) : array();
$pageMarkers = array();
foreach ( $large['plan']['pages'] ?? array() as $planPage ) {
    $pageMarkers[] = $markersOf((string) ($planPage['canonical_block_markup'] ?? ''));
}
$assert(array() !== ($pageMarkers[0] ?? array()) && 1 === count(array_unique(array_map('serialize', array_slice($pageMarkers, 1)))), 'pages sharing a layout carry the same per-element markers', (string) json_encode(array_slice($pageMarkers, 0, 3)));

// Every page resolves the same styles from its own enqueue sequence.
$assetsByTarget = array_column($large['plan']['assets'] ?? array(), null, 'target_path');
$resolved = array();
foreach ( $large['plan']['pages'] ?? array() as $planPage ) {
    $css = '';
    foreach ( WordPressSitePlan::documentStylesheets($large['plan'], (string) $planPage['source_path']) as $stylesheet ) {
        $css .= "\n" . (string) ($assetsByTarget[$stylesheet['target_path']]['content'] ?? '');
    }
    $document = new DOMDocument();
    @$document->loadHTML(StaticStyleParityRunner::candidateHtmlFromSerializedBlocks((string) ($planPage['canonical_block_markup'] ?? '')));
    $cascade = new StaticCssCascade($document, $css);
    $probe = array();
    $button = $document->getElementsByTagName('a')->item(2) ?? $document->getElementsByTagName('button')->item(0);
    foreach ( $document->getElementsByTagName('*') as $element ) {
        if ( $element instanceof DOMElement && str_contains(' ' . $element->getAttribute('class') . ' ', ' wp-block-button__link ') ) {
            $button = $element;
            break;
        }
    }
    if ( $button instanceof DOMElement ) {
        $probe['control'] = $cascade->resolve($button, array( 'background-color', 'color', 'padding-top' ), array());
    }
    foreach ( $document->getElementsByTagName('p') as $paragraph ) {
        if ( str_contains($paragraph->getAttribute('class'), 'intro') ) {
            $probe['intro'] = $cascade->resolve($paragraph, array( 'font-size', 'margin-bottom' ), array());
        }
    }
    $resolved[(string) $planPage['source_path']] = $probe;
}
$first = $resolved['a.html'] ?? array();
$assert('#123456' === strtolower((string) ($first['control']['background-color'] ?? '')) && '1rem' === ($first['intro']['font-size'] ?? null), 'authored control and paragraph styles resolve on the entry page', (string) json_encode($first));
$assert(1 === count(array_unique(array_map('serialize', $resolved))), 'every page resolves the same styles under its own cascade', (string) json_encode(array_slice($resolved, 0, 3)));

// Context-free rules reaching page-specific content. Every page has its own
// buttons, list items, links and native buttons, in different numbers and
// places. `a`, `.btn`, `ul`, `li` and `button` decide their match from the
// element alone, and `.card .btn:hover` matches no source element as a whole,
// so its `.btn` subject is projected onto every button. Before, each of those
// arms named every matched element's own marker, so every page projected the
// stylesheet differently and loaded a slice that grew with its content. Now
// the arms name the carriers' role and subject classes: the stylesheet
// compiles to one asset whatever the pages hold, and each page still resolves
// the same styles.
$variedCss = 'a{color:#0a7c45;text-decoration:none}.btn{background-color:#123456;color:#fff;border-radius:4px}.card .btn:hover{color:#ff0}ul{list-style:none;padding-left:0}li{line-height:1.6}button{font-weight:700}';
$variedPage = static function (int $index): string {
    $buttons = '';
    $items = '';
    $links = '';
    for ( $k = 0; $k <= $index; ++$k ) {
        $buttons .= '<a class="btn" href="/p' . $index . '/buy-' . $k . '" style="padding:8px 16px">Buy ' . $index . '.' . $k . '</a>';
    }
    for ( $k = 0; $k < 2 + $index % 3; ++$k ) {
        $items .= '<li>Item ' . $index . '.' . $k . '</li>';
    }
    for ( $k = 0; $k <= $index % 4; ++$k ) {
        $links .= '<p>Read <a href="/p' . $index . '/more-' . $k . '">more ' . $k . '</a> here.</p>';
    }
    $extra = 0 === $index % 2
        ? '<section class="promo"><h2>Promo ' . $index . '</h2><ul class="features"><li>Fast</li><li>Cheap ' . $index . '</li></ul><button type="button">Send ' . $index . '</button></section>'
        : '<div class="aside"><button type="button" class="ghost">Ask ' . $index . '</button></div>';
    return '<!doctype html><html><head><title>Page ' . $index . '</title><link rel="stylesheet" href="base.css"></head>'
        . '<body class="page page-id-' . (200 + $index) . '"><header class="site-header"><nav><a href="/a.html">Home</a><a href="/b.html">Catalog</a></nav></header>'
        . '<main><h1>Page ' . $index . '</h1><div class="actions">' . $buttons . '</div><ul class="list">' . $items . '</ul>' . $links . $extra . '</main></body></html>';
};
$variedPlan = static function (int $pageCount) use ($variedPage, $variedCss): array {
    $files = array();
    for ( $index = 0; $index < $pageCount; ++$index ) {
        $files[] = array( 'path' => chr(97 + $index) . '.html', 'kind' => 'html', 'content' => $variedPage($index) );
    }
    $files[] = array( 'path' => 'base.css', 'kind' => 'css', 'content' => $variedCss );
    return ( new ArtifactCompiler() )->compile(array( 'entrypoint' => 'a.html', 'files' => $files ))->toArray()['source_reports']['wordpress_site_plan'] ?? array();
};
$authoredAssets = static function (array $plan): array {
    $assets = array();
    foreach ( $plan['assets'] ?? array() as $asset ) {
        if ( 'css' === ($asset['kind'] ?? '') && in_array('base.css', array( $asset['source_path'] ?? '', $asset['stylesheet_parent'] ?? '' ), true) ) {
            $assets[(string) $asset['target_path']] = (string) ($asset['content'] ?? '');
        }
    }
    return $assets;
};
$variedTwo = $variedPlan(2);
$variedTen = $variedPlan(10);
$twoAssets = $authoredAssets($variedTwo);
$tenAssets = $authoredAssets($variedTen);
$assert(1 === count($twoAssets) && 1 === count($tenAssets), 'the stylesheet compiles to one asset however different the pages are', (string) json_encode(array( array_keys($twoAssets), array_keys($tenAssets) )));
$assert($twoAssets === $tenAssets, 'its bytes do not depend on the page count or on what the pages hold', strlen(implode('', $twoAssets)) . ' vs ' . strlen(implode('', $tenAssets)));
$assert(! preg_match('/blocks-engine-(?:control|semantic)-[a-f0-9]{12}-\d+/', implode('', $tenAssets)), 'no arm of the shared stylesheet names a per-element marker', (string) (preg_match('/[^{}]*blocks-engine-(?:control|semantic)-[a-f0-9]{12}-\d+[^{}]*/', implode('', $tenAssets), $named) ? $named[0] : ''));

$variedAssetsByTarget = array_column($variedTen['assets'] ?? array(), null, 'target_path');
$variedResolved = array();
foreach ( $variedTen['pages'] ?? array() as $planPage ) {
    $sheet = '';
    foreach ( WordPressSitePlan::documentStylesheets($variedTen, (string) $planPage['source_path']) as $stylesheet ) {
        $sheet .= "\n" . (string) ($variedAssetsByTarget[$stylesheet['target_path']]['content'] ?? '');
    }
    $document = new DOMDocument();
    @$document->loadHTML('<?xml encoding="UTF-8"><html><body>' . StaticStyleParityRunner::candidateHtmlFromSerializedBlocks((string) ($planPage['canonical_block_markup'] ?? '')) . '</body></html>');
    $cascade = new StaticCssCascade($document, $sheet);
    $probe = array();
    foreach ( $document->getElementsByTagName('*') as $element ) {
        if ( ! $element instanceof DOMElement ) {
            continue;
        }
        $classes = ' ' . $element->getAttribute('class') . ' ';
        $text = trim($element->textContent);
        $role = match ( true ) {
            str_contains($classes, ' wp-block-button__link ') && str_starts_with($text, 'Buy') => 'button-link',
            str_contains($classes, ' wp-block-button__link ') => 'native-button',
            'a' === $element->tagName && str_starts_with($text, 'more') => 'text-link',
            'li' === $element->tagName && str_starts_with($text, 'Item') => 'list-item',
            default => '',
        };
        if ( '' === $role ) {
            continue;
        }
        $style = $cascade->resolve($element, array( 'color', 'background-color', 'font-weight', 'line-height', 'text-decoration' ), array());
        $probe[$role] ??= array();
        if ( ! in_array($style, $probe[$role], true) ) {
            $probe[$role][] = $style;
        }
    }
    ksort($probe);
    $variedResolved[(string) $planPage['source_path']] = $probe;
}
$entry = $variedResolved['a.html'] ?? array();
$assert(
    array( 'button-link', 'list-item', 'native-button', 'text-link' ) === array_keys($entry)
        && '#123456' === strtolower((string) ($entry['button-link'][0]['background-color'] ?? ''))
        && '#0a7c45' === strtolower((string) ($entry['text-link'][0]['color'] ?? ''))
        && '1.6' === (string) ($entry['list-item'][0]['line-height'] ?? '')
        && '700' === (string) ($entry['native-button'][0]['font-weight'] ?? ''),
    'authored button, link, list and native button styles resolve on the entry page',
    (string) json_encode($entry)
);
$assert(10 === count($variedResolved) && 1 === count(array_unique(array_map('serialize', $variedResolved))), 'every page resolves the same styles for its own buttons, links and list items', (string) json_encode(array_slice($variedResolved, 0, 3)));

if ( 0 < $failures ) {
    fwrite(STDERR, "shared-author-stylesheet scaling FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "shared-author-stylesheet scaling passed: {$passes} assertions\n";
