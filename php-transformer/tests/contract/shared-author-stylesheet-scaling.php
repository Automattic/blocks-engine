<?php
declare(strict_types=1);

/**
 * Generated theme CSS must not grow with pages x elements (#2755).
 *
 * N pages sharing one layout and one stylesheet compile that stylesheet to one
 * site-wide asset with route-scoped variants in source order. Each page still
 * resolves the same styles under its own enqueue sequence.
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

// Shared author CSS stays in one site-wide asset, including route differences.
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
$assert(!str_contains(implode('', array_column($large['plan']['assets'], 'source_path')), '.page-'), 'route variants never emit page-copy assets');
$assert(array() === $largeSlices && array() === $mediumSlices, 'neither site needs page slices');

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
    foreach ($planPage['document_metadata']['root_attributes'] ?? array() as $name => $value) $document->documentElement->setAttribute($name, $value);
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
$assert(strlen(implode('', $tenAssets)) < 2 * strlen(implode('', $twoAssets)), 'route lists grow without copying the stylesheet', strlen(implode('', $twoAssets)) . ' vs ' . strlen(implode('', $tenAssets)));
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
    foreach ($planPage['document_metadata']['root_attributes'] ?? array() as $name => $value) $document->documentElement->setAttribute($name, $value);
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

// Context-dependent early rule followed by a long overlapping cascade tail.
// Different wrappers force identity arms rather than constant subject classes.
$contextCss = '.btn-group>.btn{z-index:1}';
for ($i = 0; $i < 160; ++$i) {
    $contextCss .= '.tail-' . $i . '{z-index:2;color:#123456;margin:0;padding:0}';
}
$contextPlan = static function (int $count) use ($contextCss): array {
    $files = array();
    for ($i = 0; $i < $count; ++$i) {
        $html = '<!doctype html><html><head><title>Context ' . $i . '</title><link rel="stylesheet" href="base.css"></head><body><main><div class="btn-group context-' . $i . '"><button class="btn tail-159" type="button">Buy ' . $i . '</button></div></main></body></html>';
        $files[] = array('path' => chr(97 + $i) . '.html', 'kind' => 'html', 'content' => $html);
    }
    $files[] = array('path' => 'base.css', 'kind' => 'css', 'content' => $contextCss);
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'a.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
};
$contextTwo = $contextPlan(2);
$contextTen = $contextPlan(10);
$contextTwoAssets = $authoredAssets($contextTwo);
$contextTenAssets = $authoredAssets($contextTen);
$contextBytes = strlen(implode('', $contextTenAssets));
// Baseline measured with folding disabled: 142,268 bytes for ten pages.
$assert(1 === count($contextTwoAssets) && 1 === count($contextTenAssets), 'context-dependent arms fold without page copies', (string) json_encode(array_keys($contextTenAssets)));
$assert($contextBytes - strlen(implode('', $contextTwoAssets)) < 6000, 'only per-page arm bytes grow, not the overlapping cascade suffix', (string) $contextBytes);
$assert((bool) preg_match('/blocks-engine-control-[a-f0-9]{12}-[0-9]+/', implode('', $contextTenAssets)), 'fixture exercises positive identity-marker arms');
foreach ($contextTen['pages'] as $planPage) {
    $document = new DOMDocument();
    @$document->loadHTML(StaticStyleParityRunner::candidateHtmlFromSerializedBlocks($planPage['canonical_block_markup']));
    $assets = array_column($contextTen['assets'], null, 'target_path');
    $css = '';
    foreach (WordPressSitePlan::documentStylesheets($contextTen, $planPage['source_path']) as $sheet) $css .= "\n" . ($assets[$sheet['target_path']]['content'] ?? '');
    foreach ($planPage['document_metadata']['root_attributes'] ?? array() as $name => $value) $document->documentElement->setAttribute($name, $value);
    $cascade = new StaticCssCascade($document, $css);
    foreach ($document->getElementsByTagName('*') as $element) {
        if (!$element instanceof DOMElement || !str_contains($element->getAttribute('class'), 'wp-block-buttons')) continue;
        if (!str_starts_with(trim($element->textContent), 'Buy')) continue;
        $style = $cascade->resolve($element, array('z-index', 'color'), array());
        $assert('1' === (string) ($style['z-index'] ?? ''), 'folded rule retains the authored context-dependent value on ' . $planPage['source_path'], (string) json_encode($style));
    }
}
echo 'Context fixture author CSS: ' . $contextBytes . " bytes\n";

// Compare the old enqueue cascade with the folded one directly, including
// every overlapping tail rule. Markers absent from another page are inert.
$transformer = new Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer();
$shared = '.group>.btn{z-index:1}';
for ($i = 0; $i < 160; ++$i) $shared .= '.tail-' . $i . '{z-index:2}';
$projections = array();
$markup = array();
for ($i = 0; $i < 10; ++$i) {
    $marker = 'blocks-engine-control-' . substr(hash('sha256', (string) $i), 0, 12) . '-0';
    $projections[$i] = array(str_replace('.group>.btn', '.group>.btn,:where(.' . $marker . ')', $shared));
    $markup[$i] = '<p class="' . $marker . ' tail-159">Probe</p>';
}
$folded = $transformer->foldPositiveMarkerArms(array($shared), $projections, $markup);
foreach ($projections as $owner => $sheets) {
    $before = $shared . $transformer->rulesAbsentFrom($sheets, array($shared), true);
    $after = implode('', $folded['shared']) . $transformer->rulesAbsentFrom($folded['pages'][$owner], $folded['shared'], true);
    $document = new DOMDocument();
    @$document->loadHTML($markup[$owner]);
    $element = $document->getElementsByTagName('p')->item(0);
    $oldStyle = (new StaticCssCascade($document, $before))->resolve($element, array('z-index'), array());
    $newStyle = (new StaticCssCascade($document, $after))->resolve($element, array('z-index'), array());
    $assert($oldStyle === $newStyle && '2' === (string) ($newStyle['z-index'] ?? ''), 'folding preserves the original enqueue cascade on page ' . $owner);
}

// A reused identity is not proof of a shared match: both pages carry the
// marker, but only one projected the arm. Keep it out of the marker fold.
$marker = 'blocks-engine-control-0123456789ab-0';
$base = '.btn-group>.btn{z-index:1}.tail{z-index:2}';
$extra = '.btn-group>.btn,:where(.' . $marker . ')>.wp-block-button__link{z-index:1}.tail{z-index:2}';
$folded = $transformer->foldPositiveMarkerArms(array($base), array('a' => array($extra), 'b' => array($base)), array('a' => '<div class="' . $marker . '"></div>', 'b' => '<div class="' . $marker . '"></div>'));
$assert(!str_contains(implode('', $folded['shared']), $marker), 'a marker carried by a non-projecting page is not folded');
$routed = $transformer->scopeRouteVariants($folded['shared'], $folded['pages']);
$assert(str_contains($routed['stylesheet'], $marker) && 2 === count($routed['scoped']), 'an arm unsafe for marker folding can still be route scoped in place');

// Real constant-arm variants, a dropped control override, and page-only
// image bridge rules. Identical span routes must share one positive rule.
$routeCss = 'html .sample{background-color:#123456}:root .sample{border-top-width:3px}'
    . '@media(min-width:1px){button{padding:7px}.sample{color:red}.only{margin:9px}img{width:41px}}';
for ($i = 0; $i < 100; ++$i) $routeCss .= '.cascade-tail-' . $i . '{color:#123456;padding:2px;border-top-width:1px;background-color:#fff}';
$routeBodies = array(
    '<button class="sample only">Probe</button>',
    '<span class="sample">Probe</span>',
    '<span class="sample">Probe</span>',
    '<button class="sample only">Probe</button>',
    '<button class="sample only">Probe</button><img src="photo.png" alt="Photo">',
);
$routeFiles = array();
foreach ($routeBodies as $i => $body) $routeFiles[] = array('path' => chr(97 + $i) . '.html', 'kind' => 'html', 'content' => '<!doctype html><html><head><title>Routes</title><link rel="stylesheet" href="base.css"></head><body><main>' . $body . '</main></body></html>');
$routeSheet = array('path' => 'base.css', 'kind' => 'css', 'content' => $routeCss);
$routeResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'a.html', 'files' => array_merge($routeFiles, array($routeSheet))))->toArray();
$routePlan = $routeResult['source_reports']['wordpress_site_plan'];
$routeAssets = $authoredAssets($routePlan);
$routeText = implode('', $routeAssets);
$assert(1 === count($routeAssets) && !str_contains(implode('', array_column($routePlan['assets'], 'source_path')), '.page-'), 'constant-arm variants, dropped rules and page-only rules need no copies');
$classes = array();
foreach ($routePlan['pages'] as $row) {
    $class = Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentRootContext::routeClass($row['reconciliation_identity']);
    $classes[$row['source_path']] = $class;
    $assert(in_array($class, explode(' ', $row['document_metadata']['root_attributes']['class'] ?? ''), true), 'document root carries its reconciliation route class: ' . $row['source_path']);
    $functions = array_column($routePlan['writes'], null, 'target_path')['functions.php']['payload']['data'];
    $assert(str_contains($functions, $class) && str_contains($functions, 'blocksEngineDocumentContext') && str_contains($functions, 'language_attributes'), 'the same root registry reaches front end and editor: ' . $row['source_path']);
}
$group = ':where(:root:is(.' . $classes['b.html'] . ',.' . $classes['c.html'] . '))';
$assert(str_contains($routeText, $group . ' .sample'), 'byte-identical constant-arm variants group both span routes');
$assert(str_contains($routeText, 'html:where(:root:') && str_contains($routeText, ':root:where(:root:'), 'html and root scopes merge into the leftmost compound');
$droppedOverride = false;
$conditionalImage = false;
$transformer->visitStyleRules($routeText, static function (string $prelude, string $body, array $context) use (&$droppedOverride, &$conditionalImage, $classes): void {
    if (str_contains($body, 'padding:7px!important')) {
        $droppedOverride = in_array('@media(min-width:1px)', $context, true) && str_contains($prelude, ':root:not(.' . $classes['b.html'] . ',.' . $classes['c.html'] . ')');
    }
    if (str_contains($prelude, '.wp-block-image') && str_contains($prelude, ':root:is(.' . $classes['e.html'] . ')')) $conditionalImage = in_array('@media(min-width:1px)', $context, true);
});
$assert($droppedOverride, 'the dropped override excludes both span routes at its original conditional position');
$assert($conditionalImage, 'page-only image rules retain their at-rule context');
$assert(str_contains($routeText, ':where(:root:is(.' . $classes['e.html'] . '))'), 'page-only image bridge has a positive route scope');

// Each route is compared with its independent public compilation, including
// editor wrapper selectors. Apply exactly the root attributes the theme uses.
$probeStyles = static function (array $plan, array $row, bool $editor): array {
    $assets = array_column($plan['assets'], null, 'target_path');
    $css = '';
    foreach (WordPressSitePlan::documentStylesheets($plan, $row['source_path']) as $sheet) $css .= "\n" . ($assets[$sheet['target_path']]['content'] ?? '');
    $markup = StaticStyleParityRunner::candidateHtmlFromSerializedBlocks($row['canonical_block_markup']);
    $dom = new DOMDocument();
    @$dom->loadHTML('<html><body>' . ($editor ? '<div class="editor-styles-wrapper">' : '') . $markup . ($editor ? '</div>' : '') . '</body></html>');
    foreach ($row['document_metadata']['root_attributes'] ?? array() as $name => $value) $dom->documentElement->setAttribute($name, $value);
    $cascade = new StaticCssCascade($dom, $css);
    $styles = array();
    foreach ($dom->getElementsByTagName('*') as $element) {
        if (!$element instanceof DOMElement) continue;
        if ('img' === $element->tagName) {
            $styles[] = $cascade->resolve($element, array('width', 'max-width', 'display'), array());
        } elseif ('Probe' === trim($element->textContent) && in_array($element->tagName, array('p', 'span', 'a', 'button'), true)) {
            $styles[] = $cascade->resolve($element, array('color', 'background-color', 'padding-top', 'border-top-width'), array());
        }
    }
    return $styles;
};
foreach ($routePlan['pages'] as $row) {
    $file = array_column($routeFiles, null, 'path')[$row['source_path']];
    $alone = (new ArtifactCompiler())->compile(array('entrypoint' => $file['path'], 'files' => array($file, $routeSheet)))->toArray()['source_reports']['wordpress_site_plan'];
    foreach (array(false, true) as $editor) {
        $actual = $probeStyles($routePlan, $row, $editor);
        $expected = $probeStyles($alone, $alone['pages'][0], $editor);
        $assert(array() !== $actual && $expected === $actual, 'route computed-style parity ' . $row['source_path'] . ($editor ? ' editor' : ' front end'), json_encode(array($expected, $actual)));
    }
}
echo 'Route fixture author CSS: ' . strlen($routeText) . ' bytes in ' . count($routeAssets) . " assets\n";

// @namespace requires per-sheet fallback, without preventing another sheet
// on the same route from using in-place variants.
$unsafeSheet = array('path' => 'unsafe.css', 'kind' => 'css', 'content' => '@namespace svg url("http://www.w3.org/2000/svg");' . $routeCss);
$fallbackFiles = $routeFiles;
foreach ($fallbackFiles as &$file) $file['content'] = str_replace('</head>', '<link rel="stylesheet" href="unsafe.css"></head>', $file['content']);
unset($file);
$fallback = (new ArtifactCompiler())->compile(array('entrypoint' => 'a.html', 'files' => array_merge($fallbackFiles, array($routeSheet, $unsafeSheet))))->toArray();
$findings = array_filter($fallback['diagnostics'], static fn (array $row): bool => 'author_stylesheet_route_scope_fallback' === ($row['code'] ?? '') && str_contains($row['message'], '@namespace') && str_contains($row['message'], 'unsafe.css'));
$assert(array() !== $findings, 'unsafe stylesheet emits a page-attributed reason diagnostic');
$fallbackAssets = $fallback['source_reports']['wordpress_site_plan']['assets'];
$copies = array_filter($fallbackAssets, static fn (array $asset): bool => str_contains($asset['source_path'], '.page-'));
$assert(array() !== $copies && array() === array_filter($copies, static fn (array $asset): bool => str_starts_with($asset['source_path'], 'base.page-')), 'fallback is restricted to the unsafe sheet');
foreach (array(
    array('.x::before{color:red}', '.y::before{color:red}', 'pseudo-element'),
    array('.x{color:red}', '& .x{color:red}', 'relative'),
    array('.x{color:red}', '.x{color:red', 'malformed'),
) as [$base, $variant, $reason]) {
    $routed = $transformer->scopeRouteVariants(array($base), array('unsafe' => array($variant)));
    $assert(array() === $routed['scoped'] && str_contains($routed['reasons']['unsafe'] ?? '', $reason), 'unsafe route falls back: ' . $reason);
}

// Identical shared units are one fold match, rewritten at every occurrence.
$marker = 'blocks-engine-control-0123456789ab-0';
$unit = '.group>.btn{z-index:1}';
$variant = '.group>.btn,:where(.' . $marker . '){z-index:1}';
$duplicates = $transformer->foldPositiveMarkerArms(array($unit . '.between{z-index:2}' . $unit), array('a' => array($variant . '.between{z-index:2}' . $variant)), array('a' => '<p class="' . $marker . '">Probe</p>'));
$assert(2 === substr_count(implode('', $duplicates['shared']), $marker), 'marker folding rewrites every byte-identical shared unit without moving it');

if ( 0 < $failures ) {
    fwrite(STDERR, "shared-author-stylesheet scaling FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "shared-author-stylesheet scaling passed: {$passes} assertions\n";
