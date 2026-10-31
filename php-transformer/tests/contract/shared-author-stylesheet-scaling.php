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
$assert($contextBytes - strlen(implode('', $contextTwoAssets)) < 12000, 'per-page arm and no-route scope bytes grow, not the overlapping cascade suffix', (string) $contextBytes);
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

// Compare the old enqueue cascade with arm reconciliation directly, including
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
$routed = $transformer->scopeRouteVariants(array($shared), $projections, array(), null, $markup);
foreach ($projections as $owner => $sheets) {
    $before = $shared . $transformer->rulesAbsentFrom($sheets, array($shared), true);
    $after = $routed['stylesheet'];
    $document = new DOMDocument();
    @$document->loadHTML($markup[$owner]);
    $element = $document->getElementsByTagName('p')->item(0);
    $oldStyle = (new StaticCssCascade($document, $before))->resolve($element, array('z-index'), array());
    $newStyle = (new StaticCssCascade($document, $after))->resolve($element, array('z-index'), array());
    $assert($oldStyle === $newStyle && '2' === (string) ($newStyle['z-index'] ?? ''), 'arm reconciliation preserves the original enqueue cascade on page ' . $owner);
}

// A reused identity is not proof of a shared match: both pages carry the
// marker, but only one projected the arm. Scope that arm alone.
$marker = 'blocks-engine-control-0123456789ab-0';
$base = '.btn-group>.btn{z-index:1}.tail{z-index:2}';
$extra = '.btn-group>.btn,:where(.' . $marker . ')>.wp-block-button__link{z-index:1}.tail{z-index:2}';
$routed = $transformer->scopeRouteVariants(array($base), array('a' => array($extra), 'b' => array($base)), array(), null, array('a' => '<div class="' . $marker . '"></div>', 'b' => '<div class="' . $marker . '"></div>'));
$assert(str_contains($routed['stylesheet'], $marker) && 2 === count($routed['scoped']), 'a shared marker arm is route scoped in place');

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
    $class = Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentRootContext::routeClass(WordPressSitePlan::identity('page', $row['source_path'], $row['source_path']));
    $classes[$row['source_path']] = $class;
    $rootClasses = explode(' ', $row['document_metadata']['root_attributes']['class'] ?? '');
    $assert(in_array($class, $rootClasses, true) && in_array('be-r', $rootClasses, true), 'every plan document root carries exact plan marker and source route class: ' . $row['source_path']);
    $functions = array_column($routePlan['writes'], null, 'target_path')['functions.php']['payload']['data'];
    $assert(str_contains($functions, $class) && str_contains($functions, 'blocksEngineDocumentContext') && str_contains($functions, 'language_attributes'), 'the same root registry reaches front end and editor: ' . $row['source_path']);
}
$group = ':where(:root:is(.' . $classes['b.html'] . ',.' . $classes['c.html'] . '))';
$assert(str_contains($routeText, '.sample{'), 'plain variants inert on other routes remain shared');
$assert(str_contains($routeText, 'html:where(') && str_contains($routeText, ':root:where('), 'variant-only html and root arms merge their positive route scope');
$droppedOverride = false;
$conditionalImage = false;
$transformer->visitStyleRules($routeText, static function (string $prelude, string $body, array $context) use (&$droppedOverride, &$conditionalImage, $classes): void {
    if (str_contains($body, 'padding:7px')) {
        $droppedOverride = in_array('@media(min-width:1px)', $context, true);
    }
    if (str_contains($prelude, '.wp-block-image')) $conditionalImage = in_array('@media(min-width:1px)', $context, true);
});
$assert($droppedOverride, 'the inert dropped override retains its original conditional position');
$assert($conditionalImage, 'page-only image rules retain their at-rule context');
$assert(str_contains($routeText, ':where(:root:is(.' . $classes['e.html'] . '))'), 'page-only image bridge excludes the unknown non-plan document');

// Each route is compared with its independent public compilation, including
// editor wrapper selectors. Apply exactly the root attributes the theme uses.
$probeStyles = static function (array $plan, array $row, bool $editor, array $properties = array('color', 'background-color', 'padding-top', 'border-top-width')): array {
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
            $styles[] = $cascade->resolve($element, $properties, array());
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
$frameworkTail = '';
for ($i = 0; $i < 180; ++$i) $frameworkTail .= '.framework-tail-' . $i . '{color:#456789;padding:2px;border-top-width:1px;background-color:#fff}';
$unsafeSheet = array('path' => 'unsafe.css', 'kind' => 'css', 'content' => '@namespace svg url("http://www.w3.org/2000/svg");' . $routeCss . $frameworkTail);
$fallbackFiles = $routeFiles;
foreach ($fallbackFiles as &$file) $file['content'] = str_replace('</head>', '<link rel="stylesheet" href="unsafe.css"></head>', $file['content']);
unset($file);
$fallback = (new ArtifactCompiler())->compile(array('entrypoint' => 'a.html', 'files' => array_merge($fallbackFiles, array($routeSheet, $unsafeSheet))))->toArray();
$findings = array_filter($fallback['diagnostics'], static fn (array $row): bool => 'author_stylesheet_route_scope_fallback' === ($row['code'] ?? '') && str_contains($row['message'], '@namespace') && str_contains($row['message'], 'unsafe.css'));
$assert(array() !== $findings, 'unsafe stylesheet emits a page-attributed reason diagnostic');
$fallbackAssets = $fallback['source_reports']['wordpress_site_plan']['assets'];
$copies = array_filter($fallbackAssets, static fn (array $asset): bool => str_contains($asset['source_path'], '.page-'));
$assert(array() !== $copies && array() === array_filter($copies, static fn (array $asset): bool => str_starts_with($asset['source_path'], 'base.page-')), 'fallback is restricted to the unsafe sheet');
$unsafeCopies = array_values(array_filter($copies, static fn (array $asset): bool => str_starts_with($asset['source_path'], 'unsafe.page-')));
$largestUnsafeCopy = 0;
foreach ($unsafeCopies as $copy) {
    $largestUnsafeCopy = max($largestUnsafeCopy, (int) ($copy['bytes'] ?? strlen((string) ($copy['content'] ?? ''))));
}
// Every framework tail rule shares a property family with the page-projected
// rules, so family overlap alone re-emitted all of them into every page copy.
// Only shared rules styling the page rules' elements may ride along (#3844).
$assert(array() !== $unsafeCopies && strlen($frameworkTail) > 10 * $largestUnsafeCopy, 'selector interaction drops same-family framework rules from page copies', strlen($frameworkTail) . ' framework bytes vs largest page copy ' . $largestUnsafeCopy);
foreach ($fallback['source_reports']['wordpress_site_plan']['pages'] as $row) {
    $file = array_column($fallbackFiles, null, 'path')[$row['source_path']];
    $alone = (new ArtifactCompiler())->compile(array('entrypoint' => $file['path'], 'files' => array($file, $routeSheet, $unsafeSheet)))->toArray()['source_reports']['wordpress_site_plan'];
    $assert($probeStyles($alone, $alone['pages'][0], false) === $probeStyles($fallback['source_reports']['wordpress_site_plan'], $row, false), 'page-copy cascade parity ' . $row['source_path']);
}
foreach (array(
    array('@keyframes k{to{opacity:0}}.x{color:red}', '.x{color:red}@keyframes k{to{opacity:1}}', 'conflicting @keyframes k'),
    array('@font-face{font-family:"F";src:url(a.woff2)}.x{color:red}', '.x{color:red}@font-face{font-family:F;src:url(b.woff2)}', 'conflicting @font-face'),
    array('.x{color:red}', '@page{margin:0}.x{color:red}', 'non-selector'),
    array('.x{color:red}', '& .x{color:red}', 'relative'),
    array('.x{color:red}', '.x{color:red', 'malformed'),
) as [$base, $variant, $reason]) {
    $routed = $transformer->scopeRouteVariants(array($base), array('unsafe' => array($variant)));
    $assert(array() === $routed['scoped'] && str_contains($routed['reasons']['unsafe'] ?? '', $reason), 'unsafe route falls back: ' . $reason);
}

// Real-site shapes that used to send a whole page x stylesheet pair back to
// a page copy. Each page's `button` arms are rewritten differently from the
// span page's, so the feature rides in a route variant or a dropped rule. The
// button page's extra `!important` override aligns with `.tail`, so that page
// drops `@keyframes` at its source position and re-adds it after `.tail`.
$probeProperties = array('color', 'background-color', 'padding-top', 'border-top-width', 'margin-top', 'animation-name');
foreach (array(
    'leading comment' => '/*# sourceMappingURL=x.map */ /*! Lib | a & b {} */ button,.sample{padding:7px}/* #global {color:red} */ .sample{margin-top:3px}',
    'interactive pseudo-classes' => 'button,select:disabled,#lien .content_site:hover .actions,.input-group:not(.has-validation)>.dropdown-toggle:nth-last-child(n+3){border-top-width:4px}',
    'pseudo-element arm' => 'button,.sample::-webkit-date-and-time-value,::-moz-focus-inner,[list]:not([type=date])::-webkit-calendar-picker-indicator,.sample::before{padding:5px}',
    'keyframes dropped on one page' => '/*# sourceMappingURL=x.map */@charset "UTF-8";button{padding:7px}@keyframes spinner-border{to{transform:rotate(1turn)}}.tail{padding:7px!important}.sample{animation:spinner-border 1s}',
    'functional root arm' => ':where(:root .editor-styles-wrapper) button,:where(:root .editor-styles-wrapper) .sample[data-empty="true"],:where(:root) .sample{background-color:#abcdef}',
) as $label => $variantCss) {
    $variantSheet = array('path' => 'base.css', 'kind' => 'css', 'content' => $variantCss . '.sample{color:#123456}');
    $variantFiles = array_slice($routeFiles, 0, 3);
    $variantResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'a.html', 'files' => array_merge($variantFiles, array($variantSheet))))->toArray();
    $variantPlan = $variantResult['source_reports']['wordpress_site_plan'];
    $variantFallbacks = array_values(array_filter($variantResult['diagnostics'], static fn (array $row): bool => 'author_stylesheet_route_scope_fallback' === ($row['code'] ?? '')));
    $assert(array() === $variantFallbacks, $label . ': every route is scoped in place', json_encode(array_column($variantFallbacks, 'message')));
    $assert(!str_contains(implode('', array_column($variantPlan['assets'], 'source_path')), '.page-'), $label . ': no page-copy assets');
    $variantText = implode('', $authoredAssets($variantPlan));
    $assert(str_contains($variantText, ':where(:root:'), $label . ': the shared sheet carries route scopes', $variantText);
    foreach ($variantPlan['pages'] as $row) {
        $file = array_column($variantFiles, null, 'path')[$row['source_path']];
        $alone = (new ArtifactCompiler())->compile(array('entrypoint' => $file['path'], 'files' => array($file, $variantSheet)))->toArray()['source_reports']['wordpress_site_plan'];
        foreach (array(false, true) as $editor) {
            $actual = $probeStyles($variantPlan, $row, $editor, $probeProperties);
            $expected = $probeStyles($alone, $alone['pages'][0], $editor, $probeProperties);
            $assert(array() !== $actual && $expected === $actual, $label . ': computed-style parity ' . $row['source_path'] . ($editor ? ' editor' : ' front end'), json_encode(array($expected, $actual)));
        }
    }
    if ('keyframes dropped on one page' === $label) {
        $assert(1 === substr_count($variantText, '@keyframes spinner-border') && !preg_match('/:root:[^{]*\{[^{}]*@keyframes/', $variantText), $label . ': the definition is emitted once, unscoped', $variantText);
    }
    if ('functional root arm' === $label) {
        $assert(str_contains($variantText, ':where(:root:where(:root:is(') || str_contains($variantText, ':where(:root:where(:root:not('), $label . ': the route class merges into the inner root compound', $variantText);
    }
    if ('leading comment' === $label) {
        $assert(str_contains($variantText, '/*! Lib | a & b {} */'), $label . ': leading comments are kept inert', $variantText);
    }
    echo 'Variant fixture (' . $label . ') author CSS: ' . strlen($variantText) . ' bytes in ' . count($authoredAssets($variantPlan)) . " assets\n";
}

// A long selector list projected three ways used to exhaust the byte bound
// by copying every arm into whole-rule variants. Only differing arms now
// carry scope overhead, so this fixture stays below the safety budget.
$boundArms = array();
for ($i = 0; $i < 60; ++$i) $boundArms[] = '.long-selector-arm-' . $i;
$boundSheet = array('path' => 'base.css', 'kind' => 'css', 'content' => '.sample{color:#123456}' . implode(',', $boundArms) . ',button,img{outline-style:solid;letter-spacing:1px;padding:3px}');
$boundFiles = array();
foreach (array('<span class="sample">Probe</span>', '<span class="sample">Probe</span>', '<span class="sample">Probe</span>', '<button class="sample">Probe</button>', '<img src="p.png" alt="P">', '<button class="sample">Probe</button><img src="p.png" alt="P">') as $i => $body) {
    $boundFiles[] = array('path' => 'bound-' . $i . '.html', 'kind' => 'html', 'content' => '<!doctype html><html><head><title>Bound</title><link rel="stylesheet" href="base.css"></head><body><main>' . $body . '</main></body></html>');
}
$bound = (new ArtifactCompiler())->compile(array('entrypoint' => 'bound-0.html', 'files' => array_merge($boundFiles, array($boundSheet))))->toArray();
$boundPlan = $bound['source_reports']['wordpress_site_plan'];
$boundFindings = array_values(array_filter($bound['diagnostics'], static fn (array $row): bool => 'author_stylesheet_route_scope_fallback' === ($row['code'] ?? '') && str_contains($row['message'], 'largest')));
$assert(array() === $boundFindings, 'arm-level scoping avoids the former whole-rule byte-budget fallback', json_encode(array_column($bound['diagnostics'], 'message')));
$boundAssets = $authoredAssets($boundPlan);
$assert(1 === count($boundAssets) && !str_contains(implode('', array_keys($boundAssets)), '.page-'), 'long unchanged arm lists stay in one site-wide asset', json_encode(array_map('strlen', $boundAssets)));
foreach ($boundPlan['pages'] as $row) {
    $file = array_column($boundFiles, null, 'path')[$row['source_path']];
    $alone = (new ArtifactCompiler())->compile(array('entrypoint' => $file['path'], 'files' => array($file, $boundSheet)))->toArray()['source_reports']['wordpress_site_plan'];
    $boundProperties = array('color', 'outline-style', 'letter-spacing');
    $actual = $probeStyles($boundPlan, $row, false, $boundProperties);
    $expected = $probeStyles($alone, $alone['pages'][0], false, $boundProperties);
    $assert(array() !== $actual && $expected === $actual, 'fallback computed-style parity ' . $row['source_path'], json_encode(array($expected, $actual)));
}
echo 'Bound fixture author CSS: ' . json_encode(array_map('strlen', $boundAssets)) . "\n";

// This former safety-net fixture now avoids route-list overhead altogether:
// absent button/image arms are provably inert on the other routes.
$budgetFiles = array();
for ($i = 0; $i < 24; ++$i) {
    $file = $boundFiles[$i % count($boundFiles)];
    $file['path'] = 'budget-' . $i . '.html';
    $budgetFiles[] = $file;
}
$budgetCss = '.sample{color:#123456}';
for ($i = 0; $i < 80; ++$i) $budgetCss .= 'button,img{outline-style:solid;letter-spacing:' . $i . 'px;padding:3px}';
$budgetSheet = array('path' => 'base.css', 'kind' => 'css', 'content' => $budgetCss);
$budgetResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'budget-0.html', 'files' => array_merge($budgetFiles, array($budgetSheet))))->toArray();
$budgetFindings = array_filter($budgetResult['diagnostics'], static fn (array $row): bool => 'author_stylesheet_route_scope_fallback' === ($row['code'] ?? '') && str_contains($row['message'], 'largest'));
$budgetPlan = $budgetResult['source_reports']['wordpress_site_plan'];
$assert(array() === array_values($budgetFindings) && !str_contains(implode('', array_column($budgetPlan['assets'], 'source_path')), '.page-'), 'inert plain arms avoid the former route-list byte-budget fallback');
foreach (array_slice($budgetPlan['pages'], 0, 2) as $row) {
    $file = array_column($budgetFiles, null, 'path')[$row['source_path']];
    $alone = (new ArtifactCompiler())->compile(array('entrypoint' => $file['path'], 'files' => array($file, $budgetSheet)))->toArray()['source_reports']['wordpress_site_plan'];
    $properties = array('color', 'outline-style', 'letter-spacing');
    $assert($probeStyles($alone, $alone['pages'][0], false, $properties) === $probeStyles($budgetPlan, $row, false, $properties), 'byte-budget fallback computed-style parity ' . $row['source_path']);
}

// Rules that differ between pages only by page-local block markers. Every
// page has its own buttons in a `.row-cols-3` grid, so `.row-cols-3>*` and
// `.row>*` project onto each page's own per-element markers, which the page
// projection packs as `>:is(:where(.m1),…)`. Private markers are absent
// from shared template parts, so even variant-only arms stay unscoped.
$markerCss = '.row{display:flex;flex-wrap:wrap}.row-cols-3>*{flex:0 0 auto;width:33.3%}.row>*{flex-shrink:0;max-width:100%}'
    . '.btn{background-color:#123456;color:#fff;border-radius:4px;padding:8px 16px}';
for ($i = 0; $i < 120; ++$i) $markerCss .= '.marker-tail-' . $i . '{color:#123456;margin:0;padding:2px;border-top-width:1px}';
$markerSheet = array('path' => 'base.css', 'kind' => 'css', 'content' => $markerCss);
$markerFile = static function (int $index, bool $gaps = false): array {
    $buttons = '';
    if (!$gaps || 2 !== $index % 3) for ($k = 0; $k <= $index % 3; ++$k) $buttons .= '<a class="btn" href="/m' . $index . '/' . $k . '">Buy ' . $index . '.' . $k . '</a>';
    return array('path' => 'm' . $index . '.html', 'kind' => 'html', 'content' => '<!doctype html><html><head><title>Marker ' . $index . '</title><link rel="stylesheet" href="base.css"></head><body><main><h1>Page ' . $index . '</h1><div class="row row-cols-3">' . $buttons . '</div></main></body></html>');
};
$markerSite = static function (int $count, bool $gaps = false) use ($markerFile, $markerSheet, $authoredAssets): array {
    $files = array();
    for ($i = 0; $i < $count; ++$i) $files[] = $markerFile($i, $gaps);
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'm0.html', 'files' => array_merge($files, array($markerSheet))))->toArray();
    $plan = $result['source_reports']['wordpress_site_plan'];
    return array('plan' => $plan, 'assets' => $authoredAssets($plan), 'diagnostics' => $result['diagnostics']);
};
$markerSizes = array();
foreach (array(2, 6, 10) as $count) {
    $site = $markerSite($count);
    $text = implode('', $site['assets']);
    $markerSizes[$count] = strlen($text);
    $fallbacks = array_filter($site['diagnostics'], static fn (array $row): bool => 'author_stylesheet_route_scope_fallback' === ($row['code'] ?? ''));
    $assert(1 === count($site['assets']) && !str_contains($text, 'be-r-') && !$fallbacks, $count . ' private marker pages: unscoped shared asset without fallback');
}
$markerText = implode('', $site['assets']);
$assert(1 === count(array_unique(preg_match_all('/blocks-engine-control-[a-f0-9]{12}-\d+/', $markerText, $m) ? $m[0] : array('', 'x'))), 'identical page-local controls share one declaration address', json_encode(array_values(array_unique($m[0] ?? array()))));
// Growth is limited to marker names, not route scopes or cascade copies.
$assert($markerSizes[10] < 1.2 * $markerSizes[2] && $markerSizes[10] - $markerSizes[6] < 1.2 * ($markerSizes[6] - $markerSizes[2]) + 512, 'site-wide sheet bytes stay roughly constant in the page count', json_encode($markerSizes));
$markerProbe = static function (array $plan, array $row): array {
    $assets = array_column($plan['assets'], null, 'target_path');
    $css = '';
    foreach (WordPressSitePlan::documentStylesheets($plan, $row['source_path']) as $sheet) $css .= "\n" . ($assets[$sheet['target_path']]['content'] ?? '');
    $dom = new DOMDocument();
    @$dom->loadHTML('<html><body>' . StaticStyleParityRunner::candidateHtmlFromSerializedBlocks($row['canonical_block_markup']) . '</body></html>');
    foreach ($row['document_metadata']['root_attributes'] ?? array() as $name => $value) $dom->documentElement->setAttribute($name, $value);
    $cascade = new StaticCssCascade($dom, $css);
    $styles = array();
    foreach ($dom->getElementsByTagName('*') as $element) {
        if (!$element instanceof DOMElement) continue;
        $classes = ' ' . $element->getAttribute('class') . ' ';
        if (str_contains($classes, ' wp-block-buttons ') || str_contains($classes, ' wp-block-button ') || str_contains($classes, ' wp-block-button__link ')) {
            $styles[] = $cascade->resolve($element, array('width', 'flex-shrink', 'max-width', 'background-color', 'color'), array());
        }
    }
    return $styles;
};
// With every third page empty, its projection is the shared one. Other
// pages' marker rules are page-only but absent from shared chrome.
$gapSite = $markerSite(9, true);
$gapText = implode('', $gapSite['assets']);
$scopedMarkerRule = false;
$transformer->visitStyleRules($gapText, static function (string $prelude) use (&$scopedMarkerRule): void {
    if (str_contains($prelude, 'be-r-') && preg_match('/blocks-engine-control-[a-f0-9]{12}-\d+/', $prelude)) $scopedMarkerRule = true;
});
$assert(1 === count($gapSite['assets']) && !$scopedMarkerRule, 'page-only private marker rules stay shared and unscoped');
foreach (array(array($site, false), array($gapSite, true)) as [$checked, $gaps]) {
    foreach ($checked['plan']['pages'] as $row) {
        $index = (int) substr($row['source_path'], 1);
        $alone = (new ArtifactCompiler())->compile(array('entrypoint' => $row['source_path'], 'files' => array($markerFile($index, $gaps), $markerSheet)))->toArray()['source_reports']['wordpress_site_plan'];
        $actual = $markerProbe($checked['plan'], $row);
        $expected = $markerProbe($alone, $alone['pages'][0]);
        $hasGrid = !$gaps || 2 !== $index % 3;
        $assert($expected === $actual && $hasGrid === in_array('33.3%', array_column($actual, 'width'), true), 'marker-only computed-style parity ' . $row['source_path'] . ($gaps ? ' (with empty pages)' : ''), json_encode(array($expected, $actual)));
    }
}
echo 'Marker-only fixture author CSS: ' . json_encode($markerSizes) . " bytes for 2/6/10 pages\n";

// Mixed real-site rule shapes: hundreds of base marker arms must not inherit
// a shared-chrome arm's scope. Richtext swaps and a dropped plain rule use
// the same per-arm membership path, including complementary scopes.
$armSizes = array();
foreach (array(2, 10, 30) as $count) {
    $chrome = 'blocks-engine-control-aaaaaaaaaaaa-0';
    $richA = 'blocks-engine-richtext-bbbbbbbbbbbb-0';
    $richB = 'blocks-engine-richtext-cccccccccccc-0';
    $richArm = static fn (string $marker): string => ':where(mark[style*="--blocks-engine-richtext-marker:' . $marker . ';"])';
    $safeArms = array('.plain');
    for ($j = 0; $j < 200; ++$j) $safeArms[] = ':where(.blocks-engine-control-' . substr(hash('sha256', 'safe-' . $j), 0, 12) . '-0)';
    $common = implode(',', $safeArms);
    $liveSafe = 'blocks-engine-control-' . substr(hash('sha256', 'safe-0'), 0, 12) . '-0';
    $base = '@media(min-width:1px){' . $common . ',:where(.' . $chrome . '){color:red}' . $richArm($richA) . '{background-color:blue}.drop{padding-top:7px}}';
    $pages = $markup = array();
    for ($j = 0; $j < $count; ++$j) {
        $pages['arm-' . $j] = array('@media(min-width:1px){' . $common . (0 === $j ? ',:where(.' . $chrome . ')' : '') . '{color:red}' . $richArm(0 === $j ? $richA : $richB) . '{background-color:blue}' . (1 === $j ? '' : '.drop{padding-top:7px}') . '}');
        $markup['arm-' . $j] = '<p class="' . $chrome . ' drop ' . $liveSafe . '">Chrome</p><mark style="--blocks-engine-richtext-marker:' . $richA . ';">A</mark><mark style="--blocks-engine-richtext-marker:' . $richB . ';">B</mark>';
    }
    $result = $transformer->scopeRouteVariants(array($base), $pages, array(), null, $markup);
    $text = Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorListCompactor::stylesheet($result['stylesheet'], true);
    $armSizes[$count] = strlen($text);
    $assert(count($result['scoped']) === $count && array() === $result['reasons'], $count . ' mixed-arm routes reconcile without page-copy fallback');
    $assert(1 === substr_count($text, 'blocks-engine-control-' . substr(hash('sha256', 'safe-0'), 0, 12)), 'one shared-chrome arm never duplicates its safe sibling list');
    foreach ($pages as $owner => $sheets) {
        $dom = new DOMDocument();
        @$dom->loadHTML('<html><body>' . $markup[$owner] . '</body></html>');
        $dom->documentElement->setAttribute('class', 'be-r ' . Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentRootContext::routeClass($owner));
        $expected = new StaticCssCascade($dom, implode('', $sheets));
        $actual = new StaticCssCascade($dom, $text);
        foreach (array('p', 'mark') as $tag) foreach ($dom->getElementsByTagName($tag) as $element) {
            $properties = array('color', 'background-color', 'padding-top');
            $assert($expected->resolve($element, $properties, array()) === $actual->resolve($element, $properties, array()), 'mixed-arm computed-style parity ' . $count . ' routes: ' . $owner . ' ' . $element->textContent);
        }
    }
    $assert(str_contains($text, ':root:not(') || 2 === $count, 'the dropped base rule uses complementary scope');
}
$assert($armSizes[30] < 1.4 * $armSizes[2], 'mixed-arm site-wide bytes stay roughly constant in N', json_encode($armSizes));

// Private richtext carriers (including packed style$/editor-span variants)
// still need positive scope when absent from the virtual base projection.
$richOne = 'blocks-engine-richtext-dddddddddddd-0';
$richTwo = 'blocks-engine-richtext-eeeeeeeeeeee-0';
$richList = static fn (string $marker): string => ':where(mark[style*="--blocks-engine-richtext-marker:' . $marker . ';"],mark[style$="--blocks-engine-richtext-marker:' . $marker . '"],span[data-blocks-engine-richtext-marker="' . $marker . '"])';
$richPages = array('one' => array($richList($richOne) . '{color:red}'), 'two' => array($richList($richTwo) . '{color:red}'));
$richMarkup = array('one' => '<mark style="--blocks-engine-richtext-marker:' . $richOne . ';">One</mark>', 'two' => '<span data-blocks-engine-richtext-marker="' . $richTwo . '">Two</span>');
$privateRich = $transformer->scopeRouteVariants($richPages['one'], $richPages, array(), null, $richMarkup);
$assert(str_contains($privateRich['stylesheet'], ':root:is('), 'variant-only private richtext excludes non-plan pages');
$unknownRich = $transformer->scopeRouteVariants($richPages['one'], $richPages);
$assert(str_contains($unknownRich['stylesheet'], 'be-r-'), 'missing carrier evidence never proves richtext arms inert');
$negated = ':not(:where(.blocks-engine-control-aaaaaaaaaaaa-0)){color:red}';
$negativeMarker = $transformer->scopeRouteVariants(array($negated), array('one' => array($negated), 'two' => array('')), array(), null, array('one' => '', 'two' => ''));
$assert(str_contains($negativeMarker['stylesheet'], 'be-r-'), 'negated markers are not positive carrier requirements');

// Plain arms are absent because they do not match, not because of marker
// ownership. Keep the authored input fixed as N increases. A native-lowered
// arm absent from a projection but still matching its DOM MUST stay scoped.
$plainSizes = array();
$plainStarted = microtime(true);
$plainArms = array();
for ($k = 0; $k < 112; ++$k) $plainArms[] = '#page-only-' . $k . ' .x';
$plainBase = '.native{z-index:9}' . implode(',', $plainArms) . '{color:red}';
foreach (array(2, 12, 112) as $count) {
    $plainPages = $plainMarkup = $plainDocuments = array();
    for ($k = 0; $k < $count; ++$k) {
        $owner = 'plain-' . $k;
        $plainPages[$owner] = array(0 === $k ? $plainBase : (1 === $k ? '' : '.native{z-index:9}') . '#page-only-' . $k . ' .x{color:red}');
        $plainMarkup[$owner] = '<div id="page-only-' . $k . '"><p class="x native">Probe</p></div>';
        $dom = new DOMDocument();
        @$dom->loadHTML('<html><body>' . $plainMarkup[$owner] . '</body></html>');
        $dom->documentElement->setAttribute('class', 'be-r ' . Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentRootContext::routeClass($owner));
        $plainDocuments[$owner] = $dom;
    }
    $index = new Automattic\BlocksEngine\PhpTransformer\Css\RouteSelectorMatchIndex($plainDocuments);
    $plainResult = $transformer->scopeRouteVariants(array($plainBase), $plainPages, array(), null, $plainMarkup, $index);
    $plainSizes[$count] = strlen($plainResult['stylesheet']);
    $assert(array() === $plainResult['reasons'] && $count === count($plainResult['scoped']), $count . ' plain routes reconcile without page copies');
    $transformer->visitStyleRules($plainResult['stylesheet'], static function (string $prelude) use ($assert): void {
        if (str_contains($prelude, '#page-only-')) $assert(!str_contains($prelude, 'be-r-'), 'plain page-local arms are unscoped');
        if (str_contains($prelude, '.native')) $assert(str_contains($prelude, 'be-r-'), 'matching native-lowered arm stays scoped');
    });
    foreach ($plainDocuments as $owner => $dom) {
        $element = $dom->getElementsByTagName('p')->item(0);
        $expected = new StaticCssCascade($dom, implode('', $plainPages[$owner]));
        $actual = new StaticCssCascade($dom, $plainResult['stylesheet']);
        $assert($expected->resolve($element, array('color', 'z-index'), array()) === $actual->resolve($element, array('color', 'z-index'), array()), 'plain-arm computed-style parity ' . $owner);
    }
}
$assert($plainSizes[112] < 1.02 * $plainSizes[2], 'plain-arm site-wide bytes stay constant in N', json_encode($plainSizes));
echo 'Plain-arm fixture: ' . json_encode($plainSizes) . ' bytes; ' . round(microtime(true) - $plainStarted, 3) . " seconds including parity\n";
// Exercise the public asset path too: unmatched plain arms must not create
// .page- companions, even when every page retains a different id ancestry.
$plainAssetSizes = array();
foreach (array(2, 12) as $count) {
    $files = array();
    for ($k = 0; $k < $count; ++$k) $files[] = array('path' => 'plain-' . $k . '.html', 'kind' => 'html', 'content' => '<html><head><link rel="stylesheet" href="base.css"></head><body><main id="page-only-' . $k . '"><p class="x">Probe</p></main></body></html>');
    $sheet = array('path' => 'base.css', 'kind' => 'css', 'content' => $plainBase);
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'plain-0.html', 'files' => array_merge($files, array($sheet))))->toArray()['source_reports']['wordpress_site_plan'];
    $plainAssetSizes[$count] = strlen(implode('', $authoredAssets($plan)));
    $assert(!str_contains(implode('', array_column($plan['assets'], 'source_path')), '.page-'), $count . ' plain pages emit no page-copy assets');
    foreach ($plan['pages'] as $row) {
        $alone = (new ArtifactCompiler())->compile(array('entrypoint' => $row['source_path'], 'files' => array(array_column($files, null, 'path')[$row['source_path']], $sheet)))->toArray()['source_reports']['wordpress_site_plan'];
        $assert($probeStyles($alone, $alone['pages'][0], false) === $probeStyles($plan, $row, false), 'public plain-arm computed-style parity ' . $row['source_path']);
    }
}
$assert($plainAssetSizes[12] < 1.1 * $plainAssetSizes[2], 'plain-arm published bytes stay roughly constant', json_encode($plainAssetSizes));

// Matching dropped arms still contribute to the unchanged byte safety net.
$costlyBase = '';
for ($k = 0; $k < 80; ++$k) $costlyBase .= '.native{z-index:' . $k . '}';
$costlyPages = array();
foreach ($plainDocuments as $owner => $_) $costlyPages[$owner] = array(0 === ((int) substr($owner, 6)) % 2 ? $costlyBase : '');
$costly = $transformer->scopeRouteVariants(array($costlyBase), $costlyPages, array(), null, $plainMarkup, $index);
$assert($costly['route_bytes'] > 8192 && $costly['route_bytes'] > 3 * strlen($costlyBase), 'matching dropped arms retain over-budget scope accounting');

// Unknown, negated-state and missing-document evidence is conservative.
foreach (array('.x:unknown', '.x:not(:hover)', '.x:has(> .y)') as $unknownArm) {
    $assert(!$index->inertOutside($unknownArm, array('plain-1' => true)), 'undecidable arm stays scoped: ' . $unknownArm);
}
$assert(!$index->inertOutside('.absent', array('missing' => true)), 'missing document never proves inertness');
$assert($index->inertOutside('.absent:hover', array('plain-1' => true)), 'state-independent non-match is inert');
$assert(!$index->inertOutside('.x:hover', array('plain-1' => true)), 'stateful structural match stays scoped');

// Virtual-base proofs use required carriers only, not arbitrary DOM matches.
// Exercise literal subjects, required ancestors, and the positiveMarker form.
foreach (array('.%s', '.%s a', ':where(.%s)') as $shape) {
    $sizes = array();
    foreach (array(2, 12, 112) as $count) {
        $pages = $markup = array();
        for ($k = 0; $k < $count; ++$k) {
            $marker = 'blocks-engine-control-' . substr(hash('sha256', (string) $k), 0, 12) . '-0';
            $pages['route-' . $k] = array(sprintf($shape, $marker) . '{color:red}');
            $markup['route-' . $k] = '<div class="' . $marker . '"><a>Local</a></div>';
        }
        $private = $transformer->scopeRouteVariants(array(), $pages, array(), null, $markup, null, array(), '<header>Shared</header>');
        $assert(!str_contains($private['stylesheet'], 'be-r-') && 0 === $private['route_bytes'], $shape . ': N variant-only private classes stay unscoped: ' . $count);
        // Serialized arm names grow; scope overhead stays constant at zero.
        $sizes[$count] = $private['route_bytes'];
        $header = '<header class="' . $marker . '"><a>Shared</a></header>';
        $carried = $transformer->scopeRouteVariants(array(), $pages, array(), null, $markup, null, array(), $header);
        $assert(str_contains($carried['stylesheet'], 'be-r-'), $shape . ': shared-header carrier prevents base inertness: ' . $count);
        $dom = new DOMDocument();
        @$dom->loadHTML('<html><body>' . $header . '</body></html>');
        $cascade = new StaticCssCascade($dom, $carried['stylesheet']);
        $target = str_ends_with($shape, ' a') ? $dom->getElementsByTagName('a')->item(0) : $dom->getElementsByTagName('header')->item(0);
        $assert(!isset($cascade->resolve($target, array('color'), array())['color']), 'variant does not paint shared header on a no-route page');
    }
    $assert(1 === count(array_unique($sizes)), 'private marker scope overhead is constant in N');
}
foreach (array(':not(.blocks-engine-control-aaaaaaaaaaaa-0)', ':is(.blocks-engine-control-aaaaaaaaaaaa-0,a)', '[title=".blocks-engine-control-aaaaaaaaaaaa-0"]') as $arm) {
    $result = $transformer->scopeRouteVariants(array(), array('one' => array($arm . '{color:red}')), array(), null, array('one' => ''), null, array(), '');
    $assert(str_contains($result['stylesheet'], ':root.be-r'), 'optional or quoted markers do not prove base inertness: ' . $arm);
}

// Route state is restored after compilation. Neither DOM non-matches nor
// private marker carriers can prove these arms inert on another route.
$navMarkup = '<nav class="wp-block-navigation"><li class="wp-block-navigation-item"><a class="wp-block-navigation-item__content">Current</a></li></nav>';
$navDocuments = array();
foreach (array('kept', 'outside') as $owner) {
    $dom = new DOMDocument();
    @$dom->loadHTML('<html><body>' . $navMarkup . '</body></html>');
    $dom->documentElement->setAttribute('class', 'be-r ' . Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentRootContext::routeClass($owner));
    $navDocuments[$owner] = $dom;
}
$navIndex = new Automattic\BlocksEngine\PhpTransformer\Css\RouteSelectorMatchIndex($navDocuments);
$navArm = ':root .wp-block-navigation .wp-block-navigation-item .wp-block-navigation-item__content[aria-current="page"]:not(.be-c-0):not(.be-c-0):not(.be-c-0):not(.be-c-0)';
$navRule = $navArm . '{background:rgb(245,245,245);color:black}';
$navResult = $transformer->scopeRouteVariants(array($navRule), array('kept' => array($navRule), 'outside' => array('')), array(), null, array('kept' => $navMarkup, 'outside' => $navMarkup), $navIndex);
$assert(str_contains($navResult['stylesheet'], 'be-r-'), 'render-time aria-current rule stays route scoped');
foreach ($navDocuments as $owner => $dom) {
    $anchor = $dom->getElementsByTagName('a')->item(0);
    $anchor->setAttribute('aria-current', 'page');
    $expected = new StaticCssCascade($dom, 'kept' === $owner ? $navRule : '');
    $actual = new StaticCssCascade($dom, $navResult['stylesheet']);
    $assert($expected->resolve($anchor, array('background', 'color'), array()) === $actual->resolve($anchor, array('background', 'color'), array()), 'rendered current navigation parity: ' . $owner);
}
foreach (array('current-menu-item', 'current_page_item', 'current-menu-parent', 'current_page_parent', 'current-menu-ancestor', 'current_page_ancestor', 'blocks-engine-current-navigation-item', 'active', 'is-selected') as $stateClass) {
    $assert(!$navIndex->inertOutside('.' . $stateClass . ' a', array('outside' => true)), 'render-time class cannot prove inertness: ' . $stateClass);
}
$stateMarker = 'blocks-engine-control-0123456789ab-99';
$stateRule = ':where(.' . $stateMarker . ') [aria-current="page"]{color:red}';
$stateResult = $transformer->scopeRouteVariants(array($stateRule), array('kept' => array($stateRule), 'outside' => array('')), array(), null, array('kept' => '<div class="' . $stateMarker . '"></div>', 'outside' => $navMarkup), $navIndex);
$assert(str_contains($stateResult['stylesheet'], 'be-r-'), 'marker shortcut cannot bypass render-time route state');

// A non-plan page has no route hook: its projection is the shared base, not
// the union of page variants. A base arm must use exclusions even when a
// positive list would be shorter. Variant-only arms must not leak either.
$unplannedBase = 'body{font-family:Arial;line-height:1.5;color:red}';
$unplannedPages = array('kept' => array($unplannedBase . 'body{background:blue}'));
for ($k = 0; $k < 8; ++$k) $unplannedPages['dropped-' . $k] = array('');
$unplannedResult = $transformer->scopeRouteVariants(array($unplannedBase), $unplannedPages);
$assert(str_contains($unplannedResult['stylesheet'], ':root:not('), 'base rules exclude dropped plan routes rather than requiring a route class');
foreach (array_merge(array(null), array_keys($unplannedPages)) as $owner) {
    $dom = new DOMDocument();
    @$dom->loadHTML('<html><body>Non-plan page</body></html>');
    if (null !== $owner) $dom->documentElement->setAttribute('class', 'be-r ' . Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentRootContext::routeClass($owner));
    $expected = new StaticCssCascade($dom, null === $owner ? $unplannedBase : implode('', $unplannedPages[$owner]));
    $actual = new StaticCssCascade($dom, $unplannedResult['stylesheet']);
    $body = $dom->getElementsByTagName('body')->item(0);
    $assert($expected->resolve($body, array('font-family', 'line-height', 'color', 'background'), array()) === $actual->resolve($body, array('font-family', 'line-height', 'color', 'background'), array()), 'base/variant parity with route class: ' . ($owner ?? 'none'));
}

// Identical shared units align separately at each source occurrence.
$marker = 'blocks-engine-control-0123456789ab-0';
$unit = '.group>.btn{z-index:1}';
$variant = '.group>.btn,:where(.' . $marker . '){z-index:1}';
$duplicates = $transformer->scopeRouteVariants(array($unit . '.between{z-index:2}' . $unit), array('a' => array($variant . '.between{z-index:2}' . $variant)), array(), null, array('a' => '<p class="' . $marker . '">Probe</p>'));
$assert(4 === substr_count($duplicates['stylesheet'], '{z-index:1}') && 4 === substr_count($duplicates['stylesheet'], $marker), 'arm reconciliation retains both occurrences with descendant/root route alternatives');

// Identical leading imports remain site-wide despite non-dedupable units.
$import = '@import url(/external/8e2e7efb6cf0362d/css-659beb8e13af.css);';
$importSheet = $boundSheet;
$importSheet['content'] = $import . $boundSheet['content'];
$importResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'bound-0.html', 'files' => array_merge($boundFiles, array($importSheet))))->toArray();
$importPlan = $importResult['source_reports']['wordpress_site_plan'];
$assert(!str_contains(implode('', array_column($importPlan['assets'], 'source_path')), '.page-'), 'identical leading imports emit no page copies');
$assert(1 === substr_count(implode('', $authoredAssets($importPlan)), $import), 'identical import stays site-wide once');
foreach (array('@import url(other.css);', '', $import . '@import url(extra.css);') as $otherImport) {
    $result = $transformer->scopeRouteVariants(array($import . '.a{color:red}'), array('a' => array($otherImport . '.a{color:blue}')));
    $assert(isset($result['reasons']['a']), 'different import lists fall back');
}
$result = $transformer->scopeRouteVariants(array($import . '@import url(second.css);.a{color:red}'), array('a' => array('@import url(second.css);' . $import . '.a{color:blue}')));
$assert(isset($result['reasons']['a']), 'reordered imports fall back');
// CSS ignores @import after other rules, so repeated trailing imports (concatenated sheets) are inert on every route.
$repeated = $import . '.a{color:red}' . $import;
$result = $transformer->scopeRouteVariants(array($repeated), array('a' => array($import . '.a{color:blue}' . $import)));
$assert(!isset($result['reasons']['a']) && 1 === substr_count($result['stylesheet'], $import), 'ignored trailing imports neither block route scoping nor survive');

$rootContext = Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentRootContext::class;
$classes = array();
foreach ($importPlan['pages'] as $row) {
    preg_match('/\\bbe-r-[a-f0-9]+\\b/', $row['document_metadata']['root_attributes']['class'], $match);
    $classes[] = $match[0] ?? '';
}
$assert(count(array_unique($classes)) === count($classes) && !in_array('', $classes, true), 'route classes are unique across the plan');
$assert(array(13) === array_values(array_unique(array_map('strlen', $classes))), 'normal route classes use the readable eight-hex minimum');
$collisionIdentities = array('collision-55045', 'collision-70885', 'ordinary-route');
$collisionClasses = $rootContext::routeClasses($collisionIdentities);
$assert(3 === count(array_unique($collisionClasses)) && 14 === strlen($collisionClasses['collision-55045']) && 13 === strlen($collisionClasses['ordinary-route']), 'colliding hashes lengthen only as needed');
$assert($collisionClasses === $rootContext::routeClasses(array_reverse($collisionIdentities)), 'class allocation is independent of route order');

// A majority variant uses exact plan membership plus short exclusions.
$majorityPages = array();
for ($k = 0; $k < 32; ++$k) $majorityPages['majority-' . $k] = array($k ? '.majority{color:red}' : '');
$majority = $transformer->scopeRouteVariants(array(), $majorityPages);
$assert(str_contains($majority['stylesheet'], ':root.be-r:not('), 'majority variant chooses short exact exclusion scope');
foreach (array_merge(array(null, 'pretend'), array_keys($majorityPages)) as $owner) {
    $dom = new DOMDocument();
    @$dom->loadHTML('<html><body><p class="majority">Probe</p></body></html>');
    if (null !== $owner) {
        $metadata = Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentRootContext::metadataFromHtml('', $owner);
        $dom->documentElement->setAttribute('class', 'pretend' === $owner ? 'be-r-impostor' : $metadata['root_attributes']['class']);
    }
    $cascade = new StaticCssCascade($dom, $majority['stylesheet']);
    $painted = isset($cascade->resolve($dom->getElementsByTagName('p')->item(0), array('color'), array())['color']);
    $assert($painted === (null !== $owner && 'pretend' !== $owner && 'majority-0' !== $owner), 'majority exclusion parity: ' . ($owner ?? 'no route'));
}

// Many arms share one scope: expanded accounting exceeds the budget, but
// the serialized common prefix ships once and must not trigger page copies.
$groupArms = array();
for ($k = 0; $k < 400; ++$k) $groupArms[] = 'p.a' . $k;
$groupRule = implode(',', $groupArms) . '{color:red}';
$groupPages = array('kept' => array($groupRule), 'dropped' => array(''));
$grouped = $transformer->scopeRouteVariants(array($groupRule), $groupPages);
$compact = static fn (string $css): string => Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorListCompactor::stylesheet($css, true);
$shippedGrowth = strlen($compact($grouped['stylesheet'])) - strlen($compact($groupRule));
$groupBudget = max(8192, 3 * strlen($groupRule));
$assert($grouped['route_bytes'] === $shippedGrowth && $shippedGrowth < $groupBudget && strlen($grouped['stylesheet']) - strlen($groupRule) > $groupBudget, 'budget counts shipped common scope once, not per expanded arm');
$groupDiagnostics = array();
$method = new ReflectionMethod(ArtifactCompiler::class, 'applyAuthorStylesheetProjections');
$groupFiles = array(array('path' => 'group.css', 'kind' => 'css', 'content' => $groupRule));
$groupOutput = $method->invokeArgs(new ArtifactCompiler(), array($groupFiles, array(array('path' => 'group.css', 'content' => $groupRule, 'owner' => 'kept'), array('path' => 'group.css', 'content' => '', 'owner' => 'dropped')), array(array('path' => 'group.css', 'content' => $groupRule)), '', array(), &$groupDiagnostics));
$assert(!$groupDiagnostics && !str_contains(implode('', array_column($groupOutput, 'path')), '.page-'), 'compiler accepts grouped shipped scope below budget');

// Exercise the compiler budget with explicit projections: all routes carry
// the subject, so dropping a rule cannot be proven inert. Long unchanged
// cascade tails make legacy copies costly in aggregate, not individually.
$copyBase = '.native{z-index:1}';
for ($k = 0; $k < 160; ++$k) $copyBase .= '.tail-' . $k . '{z-index:2;color:#123456;margin:0;padding:0}';
$copyVariant = str_replace('.native{z-index:1}', '.native{z-index:3}', $copyBase);
$copyPages = $copyFiles = $copyProjections = $copyMarkup = array();
for ($k = 0; $k < 112; ++$k) {
    $owner = 'copy-' . $k;
    $copyPages[$owner] = array($k % 2 ? $copyVariant : $copyBase);
    $copyMarkup[$owner] = '<div class="native">Probe</div>';
    $copyProjections[] = array('path' => 'base.css', 'content' => $copyPages[$owner][0], 'owner' => $owner);
}
// Many repeated differing units make the scope overhead exceed the old 3x
// limit while the shared cascade tail dominates each complete page copy.
$copyBase = str_repeat('.native{z-index:1}', 10) . $copyBase;
$copyVariant = str_replace('z-index:1}', 'z-index:3}', $copyBase);
foreach ($copyPages as $owner => &$sheets) $sheets = array((int) substr($owner, 5) % 2 ? $copyVariant : $copyBase);
unset($sheets);
foreach ($copyProjections as &$projection) $projection['content'] = $copyPages[$projection['owner']][0];
unset($projection);
$copyRoute = $transformer->scopeRouteVariants(array($copyBase), $copyPages, array(), null, $copyMarkup);
$totalCopies = $largestCopy = 0;
foreach ($copyPages as $sheets) {
    $bytes = strlen($transformer->rulesAbsentFrom($sheets, array($copyBase), true));
    $totalCopies += $bytes;
    $largestCopy = max($largestCopy, $bytes);
}
$assert($copyRoute['route_bytes'] > max(8192, 3 * $largestCopy) && $copyRoute['route_bytes'] <= intdiv($totalCopies, 10), 'total-copy budget accepts overhead rejected by the old largest-copy bound', json_encode(array($copyRoute['route_bytes'], $largestCopy, $totalCopies)));
$copyFiles[] = array('path' => 'base.css', 'kind' => 'css', 'content' => $copyBase);
$method = new ReflectionMethod(ArtifactCompiler::class, 'applyAuthorStylesheetProjections');
$copyDiagnostics = array();
$copyOutput = $method->invokeArgs(new ArtifactCompiler(), array($copyFiles, $copyProjections, array(array('path' => 'base.css', 'content' => $copyBase)), '', $copyMarkup, &$copyDiagnostics));
$assert(!str_contains(implode('', array_column($copyOutput, 'path')), '.page-') && !$copyDiagnostics, 'compiler applies total-copy budget without fallback', json_encode($copyDiagnostics));

if ( 0 < $failures ) {
    fwrite(STDERR, "shared-author-stylesheet scaling FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "shared-author-stylesheet scaling passed: {$passes} assertions\n";
