<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Support\DocumentVariantIds;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

$artifact = array(
    'schema' => ArtifactCompiler::INPUT_SCHEMA,
    'entrypoint' => 'website/index.html',
    'document_variants' => array(
        array(
            'source_path' => 'website/index.html',
            'variants' => array(
                array(
                    'id' => 'mobile',
                    'source_path' => 'website/.variants/mobile/index.html',
                    'media' => '(max-width: 768px)',
                ),
            ),
        ),
    ),
    'files' => array(
        array(
            'path' => 'website/index.html',
            'content' => '<!doctype html><html><head><style>:root{--site-width:980px}body.desktop{display:grid;min-width:980px;overflow:hidden}body.desktop main{width:var(--site-width)}.card{display:grid}</style></head><body class="desktop"><main><h1 class="card">Desktop</h1></main></body></html>',
        ),
        array(
            'path' => 'website/.variants/mobile/index.html',
            'role' => 'document_variant',
            'content' => '<!doctype html><html><head><style>:root{--site-width:320px}body.mobile{display:flex;min-width:320px;overflow:hidden}body.mobile main{width:var(--site-width)}.card{display:block}</style></head><body class="mobile" style="overflow:hidden"><main><h1 class="card">Mobile</h1><a href="calendar/index.html">Calendar</a></main></body></html>',
        ),
    ),
);

$compiler = new ArtifactCompiler();
$result = $compiler->compile($artifact)->toArray();
$blocks = (string) ($result['serialized_blocks'] ?? '');
$assetCss = implode("\n", array_map(
    static fn(array $asset): string => (string) ($asset['content'] ?? ''),
    array_filter($result['assets'] ?? array(), 'is_array')
));

$assert(str_contains($blocks, 'site-document-variant-default'), 'Primary document is wrapped as the default variant.');
$assert(str_contains($blocks, 'site-document-variant-mobile'), 'Mobile document is emitted as an editable variant.');
$assert(str_contains($blocks, '>Desktop<') && str_contains($blocks, '>Mobile<'), 'Both responsive document bodies remain editable block content.');
$assert(str_contains($assetCss, '@media (max-width: 768px)'), 'Variant visibility is controlled by the declared media query.');
$assert(str_contains($assetCss, '@scope (.site-document-variant-mobile)'), 'Mobile styles are isolated by the mobile document scope.');
$assert(str_contains($assetCss, ':scope{--site-width:320px}'), 'Mobile root custom properties target the mobile document scope.');
$assert(str_contains($assetCss, ':scope.mobile main'), 'Mobile body selectors are projected onto the mobile document scope.');
$assert(str_contains($assetCss, ':scope.mobile{display:flex;min-width:320px;overflow:hidden}'), 'Mobile body geometry stays on its projected wrapper.');
$assert(!str_contains($assetCss, 'display:contents!important'), 'Variant visibility never removes the active body wrapper box.');
$assert(str_contains($assetCss, '@media not all and (max-width: 768px){.site-document-variant-mobile{display:none!important}}'), 'Only inactive variants receive a display override.');
$assert(str_contains($assetCss, '@scope (.site-document-variant-default)') && str_contains($assetCss, '.card{display:grid}'), 'Primary selectors remain editable inside the default document scope.');
$assert(str_contains($assetCss, '.card{display:block}'), 'Mobile selectors remain editable inside the mobile document scope.');
$assert(str_contains($blocks, 'site-document-variant-default desktop'), 'Primary body classes are projected onto the default document wrapper.');
$assert(str_contains($blocks, 'href="calendar/index.html"'), 'Mobile route destinations retain their existing primary-route interpretation.');

$largeVariantCss = '.variant-large-first{color:#123456}' . str_repeat('/* responsive variant payload */', 40000) . '.variant-large-last{color:#654321}';
$largeVariantArtifact = array(
    'schema' => ArtifactCompiler::INPUT_SCHEMA,
    'entrypoint' => 'index.html',
    'document_variants' => array(array(
        'source_path' => 'index.html',
        'variants' => array(array(
            'id' => 'mobile',
            'source_path' => '.variants/mobile/index.html',
            'media' => '(max-width: 768px)',
        )),
    )),
    'files' => array(
        array('path' => 'index.html', 'content' => '<!doctype html><html><head></head><body><main>Desktop</main></body></html>'),
        array('path' => '.variants/mobile/index.html', 'content' => '<!doctype html><html><head><style media="screen">' . $largeVariantCss . '</style></head><body><main>Mobile</main></body></html>'),
    ),
);
$assert(strlen($largeVariantCss) > 1048576, 'Responsive variant regression CSS exceeds one megabyte.');
$largeVariantResult = (new ArtifactCompiler())->compile($largeVariantArtifact)->toArray();
$largeVariantAssetCss = implode("\n", array_map(
    static fn(array $asset): string => (string) ($asset['content'] ?? ''),
    array_filter($largeVariantResult['assets'] ?? array(), 'is_array')
));
$assert(str_contains($largeVariantAssetCss, '@scope (.site-document-variant-mobile)') && str_contains($largeVariantAssetCss, '.variant-large-first{color:#123456}') && str_contains($largeVariantAssetCss, '.variant-large-last{color:#654321}'), 'Large variant CSS is fully emitted inside the responsive document scope.');

$sharedPlan = $compiler->prepareShared($artifact);
$pagePlan = $compiler->preparePage($artifact, $sharedPlan, 'website/index.html');
$staged = $compiler->compose($sharedPlan, array($pagePlan))->toArray();
$stagedBlocks = (string) ($staged['serialized_blocks'] ?? '');
$assert(str_contains($stagedBlocks, 'site-document-variant-default') && str_contains($stagedBlocks, 'site-document-variant-mobile'), 'Staged compilation preserves the same responsive variants.');
$assert($blocks === $stagedBlocks && ($result['source_reports']['wordpress_site_plan'] ?? array()) === ($staged['source_reports']['wordpress_site_plan'] ?? array()), 'Direct and staged compilation preserve identical responsive document content and site plans.');

$routeArtifact = array(
    'schema' => ArtifactCompiler::INPUT_SCHEMA,
    'entrypoint' => 'website/routes/hpgraduationday/index.html',
    'document_variants' => array(
        array(
            'source_path' => 'website/routes/hpgraduationday/index.html',
            'variants' => array(
                array(
                    'id' => 'mobile',
                    'source_path' => 'website/.variants/mobile/routes/hpgraduationday/index.html',
                    'media' => '(max-width: 500px)',
                ),
            ),
        ),
    ),
    'files' => array(
        array(
            'path' => 'website/routes/hpgraduationday/index.html',
            'content' => '<!doctype html><html><head></head><body><main><h1>Desktop graduation</h1><img src="artwork/hero.svg" alt="Desktop graduation artwork"></main></body></html>',
        ),
        array(
            'path' => 'website/.variants/mobile/routes/hpgraduationday/index.html',
            'role' => 'document_variant',
            'content' => '<!doctype html><html><head></head><body><main><h1>Mobile graduation</h1><img src="artwork/hero.svg" alt="Mobile graduation artwork"></main></body></html>',
        ),
        array(
            'path' => 'website/routes/hpgraduationday/artwork/hero.svg',
            'mime_type' => 'image/svg+xml',
            'content' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 2 1"><rect width="2" height="1" fill="orange"/></svg>',
        ),
        array(
            'path' => 'website/.variants/mobile/routes/hpgraduationday/artwork/hero.svg',
            'mime_type' => 'image/svg+xml',
            'content' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 2"><rect width="1" height="2" fill="navy"/></svg>',
        ),
    ),
);
$routeResult = $compiler->compile($routeArtifact)->toArray();
$routePlan = $routeResult['source_reports']['wordpress_site_plan'] ?? array();
$tokensBySource = array_column($routePlan['reference_tokens'] ?? array(), 'token', 'source_path');
$desktopToken = $tokensBySource['website/routes/hpgraduationday/artwork/hero.svg'] ?? '';
$mobileToken = $tokensBySource['website/.variants/mobile/routes/hpgraduationday/artwork/hero.svg'] ?? '';
$routeMarkup = (string) ($routePlan['pages'][0]['canonical_block_markup'] ?? '');
$assert('' !== $desktopToken && '' !== $mobileToken && $desktopToken !== $mobileToken, 'Desktop and mobile artwork retain distinct publication identities.');
$assert(str_contains($routeMarkup, '{{wordpress-site-plan:asset:' . $desktopToken . '}}'), 'The emitted desktop route selects its captured desktop artwork.');
$assert(str_contains($routeMarkup, '{{wordpress-site-plan:asset:' . $mobileToken . '}}'), 'The emitted mobile route selects its captured mobile artwork.');
$resolvedRoutePlan = (new WordPressSitePlanResolver())->resolve($routePlan, array('theme_uri' => 'https://example.test/wp-content/themes/captured'));
$resolvedRouteMarkup = (string) ($resolvedRoutePlan['pages'][0]['resolved_block_markup'] ?? '');
$assert(str_contains($resolvedRouteMarkup, 'https://example.test/wp-content/themes/captured/assets/website/routes/hpgraduationday/artwork/hero.svg'), 'Desktop artwork resolves to its materialized destination URL.');
$assert(str_contains($resolvedRouteMarkup, 'https://example.test/wp-content/themes/captured/assets/website/.variants/mobile/routes/hpgraduationday/artwork/hero.svg'), 'Mobile artwork resolves to its materialized destination URL.');

$invalid = $artifact;
$invalid['document_variants'][0]['variants'][0]['media'] = '(max-width:768px)}body{display:none}';
try {
    (new ArtifactCompiler())->compile($invalid);
    $assert(false, 'Unsafe variant media is rejected.');
} catch (InvalidArgumentException) {
    $assert(true, 'Unsafe variant media is rejected.');
}

// Desktop and mobile documents commonly reuse the same source id on their own
// copy of the same landmark (e.g. a Wix-style `#PAGES_CONTAINER` wrapper).
// Both variants compile into the same page, so the id must not survive
// unchanged on both copies: that produces duplicate ids in the final HTML,
// which is invalid and breaks same-page anchor navigation to that id.
$duplicateIdArtifact = array(
    'schema' => ArtifactCompiler::INPUT_SCHEMA,
    'entrypoint' => 'website/index.html',
    'document_variants' => array(
        array(
            'source_path' => 'website/index.html',
            'variants' => array(
                array(
                    'id' => 'mobile',
                    'source_path' => 'website/.variants/mobile/index.html',
                    'media' => '(max-width: 768px)',
                ),
            ),
        ),
    ),
    'files' => array(
        array(
            'path' => 'website/index.html',
            'content' => '<!doctype html><html><head></head><body class="desktop"><main id="PAGES_CONTAINER"><h1>Desktop</h1></main></body></html>',
        ),
        array(
            'path' => 'website/.variants/mobile/index.html',
            'role' => 'document_variant',
            'content' => '<!doctype html><html><head></head><body class="mobile"><main id="PAGES_CONTAINER"><h1>Mobile</h1></main></body></html>',
        ),
    ),
);
$duplicateIdResult = (new ArtifactCompiler())->compile($duplicateIdArtifact)->toArray();
$duplicateIdBlocks = (string) ($duplicateIdResult['serialized_blocks'] ?? '');
$assert(1 === substr_count($duplicateIdBlocks, 'id="PAGES_CONTAINER"'), 'Only one compiled element keeps the bare shared id: duplicate ids in the final HTML break same-page anchor navigation.');
$assert(str_contains($duplicateIdBlocks, 'id="PAGES_CONTAINER--dla-mobile"'), 'The mobile document variant copy of a shared id is suffixed so it stays unique on the page, mirroring Data Liberation Agent\'s own --dla-mobile pairing convention.');

// A Data Liberation capture ships both documents in one page and scopes its
// mobile rules by the mobile root (`:where(.data-liberation-mobile-document)
// #id`). Once the mobile copy of a shared id is suffixed, the stylesheets the
// page loads must still reach it: otherwise every mobile id rule silently
// misses and the phone layout falls apart (a static full-height page
// background pushing content off screen, lost grids and fills).
$capturedPairArtifact = array(
    'schema' => ArtifactCompiler::INPUT_SCHEMA,
    'entrypoint' => 'website/index.html',
    'files' => array(
        array(
            'path' => 'website/index.html',
            'content' => '<!doctype html><html><head>'
                . '<link rel="stylesheet" href="/desktop.css" media="(min-width:768px)">'
                . '<link rel="stylesheet" href="/mobile.css" media="(max-width:767px)">'
                . '<style>.data-liberation-mobile-document{display:none}@media (max-width:767px){.data-liberation-desktop-document{display:none}.data-liberation-mobile-document{display:contents}}</style>'
                . '</head><body>'
                . '<div class="data-liberation-desktop-document"><section id="hero" class="hero"><h1>Welcome</h1><p>Desktop copy</p></section></div>'
                . '<div class="data-liberation-mobile-document"><section id="hero" class="hero"><h1>Welcome</h1><p>Mobile copy</p></section><aside id="phone-only" class="note"><p>Phone</p></aside></div>'
                . '</body></html>',
        ),
        array('path' => 'website/desktop.css', 'content' => '#hero{position:relative;padding-top:40px}'),
        array('path' => 'website/mobile.css', 'content' => ':where(.data-liberation-mobile-document) #hero{position:absolute;padding-top:7px}:where(.data-liberation-mobile-document) #phone-only{margin-top:3px}'),
    ),
);
$capturedPairResult = (new ArtifactCompiler())->compile($capturedPairArtifact)->toArray();
$capturedPairPlan = $capturedPairResult['source_reports']['wordpress_site_plan'] ?? array();
$capturedPairMarkup = implode("\n", array_map(static fn (array $page): string => (string) ($page['canonical_block_markup'] ?? ''), (array) ($capturedPairPlan['pages'] ?? array())));
$capturedPairCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? null) ? (string) ($asset['content'] ?? '') : '', (array) ($capturedPairPlan['assets'] ?? array())));
$assert(str_contains($capturedPairMarkup, 'id="hero--dla-mobile"') && str_contains($capturedPairMarkup, 'id="hero"'), 'The captured mobile copy of a shared id renders suffixed beside the desktop bare id.');
$assert(str_contains($capturedPairCss, ':where(.data-liberation-mobile-document) :is(#hero,#hero--dla-mobile){position:absolute;padding-top:7px}'), 'A mobile-scoped id rule in a delivered stylesheet reaches the suffixed mobile copy with the source specificity.');
$assert(1 === preg_match('/:where\(\.data-liberation-mobile-document\) :is\(#phone-only,#phone-only--dla-mobile\)[^{,]*\{margin-top:3px\}/', $capturedPairCss), 'A mobile-only id keeps matching its bare id through the same rewrite.');
$assert(!str_contains($capturedPairCss, '#blocks-engine-specificity-id-site-0--dla-mobile'), 'Engine specificity shims are not treated as source ids.');
$assert(str_contains($capturedPairCss, '#hero{position:relative;padding-top:40px}') && !str_contains($capturedPairCss, '#hero--dla-mobile{position:relative'), 'Desktop id rules are delivered unchanged.');
foreach ((array) ($capturedPairPlan['assets'] ?? array()) as $asset) {
    if ('css' === ($asset['kind'] ?? null) && is_string($asset['content'] ?? null)) {
        $assert(hash('sha256', $asset['content']) === ($asset['content_hash'] ?? null), 'A rewritten stylesheet keeps a content hash that matches its delivered payload.');
    }
}

$scope = ':where(.data-liberation-mobile-document)';
$assert($scope . ' div:is(#a,#a--dla-mobile).b > :is(#c,#c--dla-mobile)::before' === DocumentVariantIds::scopeIdSelector($scope . ' div#a.b > #c::before'), 'Ids keep their position next to type, class and pseudo-element parts.');
$assert($scope . ' :has(> #x--dla-mobile)' === DocumentVariantIds::scopeIdSelector($scope . ' :has(> #x--dla-mobile)'), 'An id that already carries the variant suffix is left alone.');
$assert($scope . ' a[href="#top"]:is(#nav,#nav--dla-mobile)' === DocumentVariantIds::scopeIdSelector($scope . ' a[href="#top"]#nav'), 'Fragments inside attribute values are not ids.');
$assert($scope . ' :is(#a,#a--dla-mobile):not(#blocks-engine-specificity-id-site-0)' === DocumentVariantIds::scopeIdSelector($scope . ' #a:not(#blocks-engine-specificity-id-site-0)'), 'Engine specificity shim ids stay as they are.');
$assert('#a .b' === DocumentVariantIds::scopeIdSelector('#a .b') && ':where(.data-liberation-desktop-document) #a' === DocumentVariantIds::scopeIdSelector(':where(.data-liberation-desktop-document) #a'), 'Unscoped and desktop-scoped selectors keep their bare ids.');
$assert('#a:not(.data-liberation-mobile-document)' === DocumentVariantIds::scopeIdSelector('#a:not(.data-liberation-mobile-document)'), 'A negated variant class does not scope the selector.');
$assert('.site-document-variant-tablet :is(#a,#a--dla-tablet)' === DocumentVariantIds::scopeIdSelector('.site-document-variant-tablet #a'), 'A named site document variant uses its own suffix.');
$assert('@media (max-width:767px){' . $scope . ' :is(#a,#a--dla-mobile){top:0;color:#abc}}' === DocumentVariantIds::scopeIdSelectors('@media (max-width:767px){' . $scope . ' #a{top:0;color:#abc}}'), 'Nested rules are rewritten and declaration values such as hex colors are untouched.');

fwrite(STDOUT, "Responsive document variant tests passed.\n");
