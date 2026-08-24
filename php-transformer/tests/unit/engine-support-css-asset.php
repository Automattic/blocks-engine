<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;

$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
};

/** @return array<int,array<string,mixed>> */
$cssAssets = static fn (array $result): array => array_values(array_filter(
    is_array($result['assets'] ?? null) ? $result['assets'] : array(),
    static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')
));

/** @return array<int,array<string,mixed>> */
$sourceAssets = static fn (array $result, string $source): array => array_values(array_filter(
    is_array($result['assets'] ?? null) ? $result['assets'] : array(),
    static fn (array $asset): bool => $source === ($asset['source'] ?? '')
));

$cssFor = static function (array $result, string $source, ?string $placement = null) use ($sourceAssets): string {
    $assets = $sourceAssets($result, $source);
    if ( null !== $placement ) {
        $assets = array_values(array_filter(
            $assets,
            static fn (array $asset): bool => $placement === ($asset['stylesheet_placement'] ?? '')
        ));
    }

    return implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $assets));
};

$authorOrder = ( new HtmlTransformer() )->transform(
    '<style>@layer contract;.contract-author-only{color:#123456}.desktop-nav a{color:#fff}.btn{display:inline-flex;padding:1rem;background:#123456;width:100%}@media(max-width:700px){.desktop-nav{display:none}.mobile-nav{background:rgba(0,0,0,.9)}}</style>'
        . '<nav class="desktop-nav"><ul><li><a href="/">Home</a></li><li><a href="/about">About</a></li></ul></nav>'
        . '<div class="mobile-nav"><nav><ul><li><a href="/">Home</a></li><li><a href="/about">About</a></li></ul></nav></div>'
        . '<p class="contract-author-only">Contract</p><main><section><a class="btn" href="/submit">Submit</a></section></main>'
)->toArray();

$authorAssets = $sourceAssets($authorOrder, 'author-css');
$assert(1 === count($authorAssets), 'G2: transform emits exactly one author-css asset');
$normalizedAuthorCss = preg_replace('/\s+/', '', (string) ($authorAssets[0]['content'] ?? '')) ?? '';
$assert(
    '@layercontract;.contract-author-only{color:#123456}.desktop-nava{color:#fff}:where(.blocks-engine-control-6494fb2a0d77-3):where(.wp-block-buttons){width:100%}:where(.blocks-engine-control-6494fb2a0d77-3):not(.blocks-engine-specificity-class-6494fb2a0d77-1)>:where(.wp-block-button__link){display:inline-flex;padding:1rem;background:#123456}@media(max-width:700px){.desktop-nav{display:none}.mobile-nav{background:rgba(0,0,0,.9)}}' === $normalizedAuthorCss,
    'G2: author-css contains only its leading at-rule preamble and rewritten author stylesheet'
);
$assert('author' === ($authorAssets[0]['stylesheet_placement'] ?? ''), 'G4: author-css record declares author placement');

$geometry = ( new HtmlTransformer() )->transform(
    '<main><p id="target" style="width:30rem">Geometry</p></main>',
    array( 'static_css' => '#target{width:12rem}' )
)->toArray();
$richText = ( new HtmlTransformer() )->transform(
    '<style>.maintenance-loop{display:grid}.maintenance-loop li > span{display:inline-block;width:10px;height:10px;border-radius:50%;background:#e8a020}</style>'
        . '<ul class="maintenance-loop"><li><span>Build</span></li></ul>'
)->toArray();
$synthetic = ( new HtmlTransformer() )->transform(
    '<style>p{margin:0}.site-header{display:flex;align-items:center}.brand{font-size:18px;font-weight:700}</style>'
        . '<header class="site-header"><a class="brand" href="/">Verified Artifact</a></header><footer><span>Portable input.</span></footer><p>Source paragraph.</p>'
)->toArray();
$colouredSyntheticLink = ( new HtmlTransformer() )->transform(
    '<style>a{color:#ffd400}.site-header{display:flex}.brand{color:#f7f2ff}</style>'
        . '<header class="site-header"><a class="brand" href="/">Super <span>Coaching</span></a></header>'
)->toArray();
$uncolouredSyntheticLink = ( new HtmlTransformer() )->transform(
    '<header><a href="/">Theme-owned link</a></header>'
)->toArray();
$inlineLayout = ( new HtmlTransformer() )->transform(
    '<style>.artifact-card{display:grid;grid-template-columns:1fr auto}.artifact-card > strong{display:block;margin:12px 0 4.8px}.artifact-card .card-label{display:block;grid-column:1 / -1;color:#6040cc;margin:2px 0}</style>'
        . '<div class="artifact-card"><span class="card-label">Input</span><strong>index.html</strong><span class="card-label">styles.css</span></div>'
)->toArray();
$layoutItems = ( new ArtifactCompiler() )->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        array( 'path' => 'index.html', 'kind' => 'html', 'content' => '<link rel="stylesheet" href="styles.css"><main><div class="hero-visual"><div class="artifact-card"><span class="card-label">Input</span><strong>index.html</strong><span>styles.css</span><span>assets/</span></div></div></main>' ),
        array( 'path' => 'styles.css', 'kind' => 'css', 'content' => '.hero-visual{display:grid;gap:2rem}.artifact-card{display:grid;grid-template-columns:1fr auto}.artifact-card > span:not(.card-label){grid-column:2}.artifact-card > strong{grid-column:1}.artifact-card .card-label{grid-column:1 / -1}' ),
    ),
) )->toArray();
$positionedLink = ( new HtmlTransformer() )->transform(
    '<style>.skip-link{position:fixed;top:-200px;left:0;padding:12px 18px;background:#135e96;color:#fff;border-radius:999px}.skip-link:focus{top:0}</style>'
        . '<a class="skip-link" href="#content">Skip to content</a><main id="content">Content</main>'
)->toArray();
$emptyFlex = ( new HtmlTransformer() )->transform(
    '<style>.utils{display:flex}.placeholder{visibility:hidden;height:80px}</style><div class="utils"><span>Action</span><div class="placeholder"></div></div>'
)->toArray();
$nativeSearch = ( new HtmlTransformer() )->transform(
    '<style>.search-icon{display:none;height:80px}.search-icon.visible{display:block}</style><script>document.querySelector(".search-icon").classList.add("visible")</script>'
        . '<header><div class="site-utils"><span class="provider-search"><form id="provider-search" action="/apps/search" method="get"><input type="text" name="q" placeholder="Search"></form></span><button class="search-icon"><svg width="12px" height="13px" viewBox="0 0 12 13"><path d="M1 1"></path></svg></button><button class="search-close">close</button></div></header>'
)->toArray();
$nativeButton = ( new HtmlTransformer() )->transform(
    '<style>.cta{display:inline-block;border:1px solid #000}.cta .cta-inner{display:inline-block;min-width:170px;padding:22px 26px;background-color:#00ff8e;color:#000;font-size:16px;line-height:1;font-weight:700}.highlight .cta-inner{background:#fff;color:#000}</style>'
        . '<div style="text-align:center"><a class="cta highlight" href="/learn"><span class="cta-inner">Learn more</span></a></div>'
)->toArray();
$directFlexButton = ( new HtmlTransformer() )->transform(
    '<style>.stack{display:flex;flex-direction:column;gap:2rem}.row{display:flex;align-items:center;gap:1rem;padding:1rem;background:#123456}.row__name{flex:1}</style>'
        . '<main><div class="stack"><a class="row" href="/product"><span class="row__name">Product</span><span>$25</span></a></div></main>'
)->toArray();
$fullWidthButton = ( new HtmlTransformer() )->transform(
    '<style>.btn{display:inline-flex;align-items:center;padding:1rem;background:#123456}.btn--full{width:100%}</style>'
        . '<main><section><a class="btn btn--full selector-submit" href="/submit">Submit</a></section></main>'
)->toArray();
$syntheticImageFigure = ( new HtmlTransformer() )->transform(
    '<main><img src="portrait.jpg" alt="Portrait"><figure class="authored-figure"><img src="work.jpg" alt="Work"></figure></main>'
)->toArray();
$adminBar = ( new HtmlTransformer() )->transform(
    '<style>.fixed-shell{position:fixed;top:0}.sticky-toc{position:sticky;top:calc(var(--header-h) + 1rem)}.ordinary{position:relative;top:1rem}</style>'
        . '<header class="fixed-shell">Header</header><aside class="sticky-toc">Contents</aside><main class="ordinary">Content</main>'
)->toArray();

$results = array(
    $authorOrder,
    $geometry,
    $richText,
    $synthetic,
    $inlineLayout,
    $layoutItems,
    $positionedLink,
    $emptyFlex,
    $nativeSearch,
    $nativeButton,
    $directFlexButton,
    $fullWidthButton,
    $colouredSyntheticLink,
    $uncolouredSyntheticLink,
    $syntheticImageFigure,
);
$beforeCss = '';
$afterCss = '';
$authorCss = '';
foreach ( $results as $result ) {
    $beforeCss .= "\n" . $cssFor($result, 'engine-support', 'before-author');
    $afterCss .= "\n" . $cssFor($result, 'engine-support', 'after-author');
    $authorCss .= "\n" . $cssFor($result, 'author-css');
}

$beforeFamilies = array(
    'be-inline-geometry' => '.be-inline-geometry-',
    'richtext-marker reset' => ':where(mark)[style*="--blocks-engine-richtext-marker:"]{background-color:transparent;color:inherit}',
    'synthetic-paragraph' => ':root :where(.blocks-engine-synthetic-paragraph){margin-top:0;margin-bottom:0}',
    'synthetic-anchor-undecorated' => 'blocks-engine-synthetic-anchor-undecorated',
    'synthetic-image-figure' => '.blocks-engine-synthetic-image-figure{margin:0}',
    'inline-layout-carrier' => ':where(p.blocks-engine-inline-layout-carrier){display:contents;margin:0!important;padding:0!important;border:0!important}',
    'css-owned-flow paragraph' => ':root :where(.blocks-engine-css-owned-flow>p){margin-top:0;margin-bottom:0}',
    'css-owned-flow direct children' => ':root :where(.wp-block-group.blocks-engine-css-owned-flow)>*{margin-block-start:0;margin-block-end:0}',
    'css-owned-grid' => ':root :where(.blocks-engine-css-owned-grid)>*{margin-block-start:0;margin-block-end:0}',
    'positioned-fragment-link-carrier' => ':where(.blocks-engine-positioned-fragment-link-carrier){display:contents!important}',
    'empty-flex-item' => ':where(.blocks-engine-empty-flex-item){flex:0 0 0!important;width:0!important;min-width:0!important;margin-left:0!important;margin-right:0!important}',
    'list-navigation base' => '.wp-block-navigation.blocks-engine-list-navigation .wp-block-navigation-item.wp-block-navigation-link{display:list-item;font:inherit}',
    'nativeSearchTriggerCssRules' => 'flex:0 0 24px!important;width:24px!important;height:80px!important',
);
$assert(str_contains((string) ($syntheticImageFigure['serialized_blocks'] ?? ''), '<figure class="wp-block-image blocks-engine-synthetic-image-figure"><img src="portrait.jpg" alt="Portrait"/></figure>'), 'direct source images mark their introduced core/image figure for margin normalization');
$assert(! str_contains((string) ($syntheticImageFigure['serialized_blocks'] ?? ''), 'authored-figure blocks-engine-synthetic-image-figure'), 'authored source figures retain their own spacing contract');
$afterFamilies = array(
    'list-navigation host' => '.wp-block-navigation.blocks-engine-list-navigation.blocks-engine-native-responsive-navigation{display:flex!important}',
    'list-navigation mobile overlay' => '.wp-block-navigation.blocks-engine-list-navigation .wp-block-navigation__responsive-container.is-menu-open{background:rgba(0,0,0,.9)!important}',
    'nativeButtonStyleRules' => 'background-color:#fff!important;color:#000!important',
    'directFlexButtonStyleRules' => '.wp-block-buttons){display:block!important;gap:0!important;min-width:0;width:100%!important}',
    'fullWidthButtonStyleRules' => '.wp-block-buttons){display:block!important;gap:0!important;width:100%!important}',
);
foreach ( $beforeFamilies as $family => $needle ) {
    $assert(str_contains($beforeCss, $needle), 'G3: ' . $family . ' lives in before-author engine-support');
    $assert(! str_contains($authorCss, $needle), 'G3: ' . $family . ' does not leak into author-css');
}
$assert(
    str_contains($beforeCss, ':root :where(p.blocks-engine-synthetic-paragraph.has-text-color)>a{color:inherit}'),
    'G3: a synthetic paragraph with native text colour carries that colour through its direct anchor'
);
$assert(
    ! str_contains((string) ($uncolouredSyntheticLink['serialized_blocks'] ?? ''), 'has-text-color'),
    'G3: an uncoloured synthetic paragraph leaves its anchor under theme link colour ownership'
);
foreach ( $afterFamilies as $family => $needle ) {
    $assert(str_contains($afterCss, $needle), 'G3: ' . $family . ' lives in after-author engine-support');
    $assert(! str_contains($authorCss, $needle), 'G3: ' . $family . ' does not leak into author-css');
}

$orderedCssAssets = $cssAssets($authorOrder);
$beforeIndex = null;
$authorIndex = null;
$afterIndex = null;
foreach ( $orderedCssAssets as $index => $asset ) {
    if ( 'engine-support' === ($asset['source'] ?? '') && 'before-author' === ($asset['stylesheet_placement'] ?? '') ) {
        $beforeIndex = $index;
    }
    if ( 'author-css' === ($asset['source'] ?? '') ) {
        $authorIndex = $index;
    }
    if ( 'engine-support' === ($asset['source'] ?? '') && 'after-author' === ($asset['stylesheet_placement'] ?? '') ) {
        $afterIndex = $index;
    }
}
$assert(is_int($beforeIndex) && is_int($authorIndex) && is_int($afterIndex) && $beforeIndex < $authorIndex && $authorIndex < $afterIndex, 'G4: direct transform preserves before-author, author, after-author asset order');
$assert(str_contains((string) ($orderedCssAssets[$beforeIndex]['content'] ?? ''), 'blocks-engine-list-navigation') && str_contains((string) ($orderedCssAssets[$afterIndex]['content'] ?? ''), 'blocks-engine-list-navigation'), 'G4: split list-navigation rules retain both cascade sides');

$navArtifact = ( new ArtifactCompiler() )->compile(array(
    'entry' => 'index.html',
    'files' => array(
        'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="styles.css"></head><body><nav class="desktop-nav"><ul><li><a href="/">Home</a></li><li><a href="/about">About</a></li></ul></nav><div class="mobile-nav"><nav><ul><li><a href="/">Home</a></li><li><a href="/about">About</a></li></ul></nav></div></body></html>',
        'styles.css' => '.desktop-nav a{color:#fff}@media(max-width:700px){.desktop-nav{display:none}.mobile-nav{background:rgba(0,0,0,.9)}}',
    ),
) )->toArray();
$navAssets = $navArtifact['assets'] ?? array();
$navBeforeIndex = null;
$navAuthorIndex = null;
$navAfterIndex = null;
foreach ( $navAssets as $index => $asset ) {
    if ( 'engine-support' === ($asset['source'] ?? '') && 'before-author' === ($asset['stylesheet_placement'] ?? '') ) {
        $navBeforeIndex = $index;
    }
    if ( 'styles.css' === ($asset['path'] ?? '') ) {
        $navAuthorIndex = $index;
    }
    if ( 'engine-support' === ($asset['source'] ?? '') && 'after-author' === ($asset['stylesheet_placement'] ?? '') ) {
        $navAfterIndex = $index;
    }
}
$assert(is_int($navBeforeIndex) && is_int($navAuthorIndex) && is_int($navAfterIndex) && $navBeforeIndex < $navAuthorIndex && $navAuthorIndex < $navAfterIndex, 'G4: ArtifactCompiler preserves generated support around manifest author CSS');

foreach ( array( 'compiled_site', 'materialization_plan', 'wordpress_site_plan' ) as $reportName ) {
    $reportAssets = $navArtifact['source_reports'][$reportName]['assets'] ?? array();
    $supportRows = array_values(array_filter(
        is_array($reportAssets) ? $reportAssets : array(),
        static fn (array $asset): bool => 'engine-support' === ($asset['source'] ?? '')
    ));
    $placements = array_column($supportRows, 'stylesheet_placement');
    $assert(in_array('before-author', $placements, true) && in_array('after-author', $placements, true), 'G4: ' . $reportName . ' exposes support placement without parsing CSS');
}

$neutralizer = '.wp-block-group.blocks-engine-css-owned-layout>:where(:not(.alignleft):not(.alignright):not(.alignfull)){max-width:none!important;margin-left:0!important;margin-right:0!important}';
$layoutCssSurfaces = array($beforeCss);
foreach ( array( 'compiled_site', 'materialization_plan', 'wordpress_site_plan' ) as $reportName ) {
    foreach ( $layoutItems['source_reports'][$reportName]['assets'] ?? array() as $asset ) {
        if ( is_array($asset) && 'css' === ($asset['kind'] ?? '') ) {
            $layoutCssSurfaces[] = (string) ($asset['content'] ?? '');
        }
    }
}
$assert(! str_contains(implode("\n", $layoutCssSurfaces), $neutralizer), 'G5: css-owned-layout neutralizer is absent from every compiler output');
$assert(! str_contains((string) ($layoutItems['serialized_blocks'] ?? ''), 'max-width:none!important'), 'G5: css-owned-layout neutralizer is absent from serialized block markup');

$richTextMarkup = (string) ($richText['serialized_blocks'] ?? '');
$assert(
    1 === preg_match('/<mark\b[^>]*style="[^"]*--blocks-engine-richtext-marker:[^"]*"/', $richTextMarkup)
        && ! str_contains($richTextMarkup, 'background-color:transparent')
        && ! str_contains($richTextMarkup, 'color:inherit'),
    'G6: richtext-marker mark defers neutral background and color to engine support CSS'
);
$assert(str_contains($beforeCss, ':where(mark)[style*="--blocks-engine-richtext-marker:"]{background-color:transparent;color:inherit}'), 'G6: richTextMarkerResetCss remains in engine-support');

$adminBarAuthorCss = $cssFor($adminBar, 'author-css');
$adminBarSupportCss = $cssFor($adminBar, 'engine-support', 'after-author');
$assert(str_contains($adminBarAuthorCss, '.fixed-shell{position:fixed;top:0}') && ! str_contains($adminBarAuthorCss, 'body.admin-bar'), 'G7: authored fixed CSS remains logged-out source CSS');
$assert(str_contains($adminBarSupportCss, 'body.admin-bar .fixed-shell{top:calc((0px) + var(--wp-admin--admin-bar--height, 32px))!important}') && str_contains($adminBarSupportCss, 'body.admin-bar .sticky-toc{top:calc((calc(var(--header-h) + 1rem)) + var(--wp-admin--admin-bar--height, 32px))!important}'), 'G7: post-author support offsets fixed and sticky layers');
$assert(! str_contains($adminBarSupportCss, '.ordinary'), 'G7: ordinary positioned rules do not receive admin-bar support CSS');

if ( $failures > 0 ) {
    fwrite(STDERR, "Engine support CSS asset contract: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Engine support CSS asset contract passed: {$passes} assertions\n");
