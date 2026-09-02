<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use Automattic\BlocksEngine\PhpTransformer\Contract\EditabilityReport;
use Automattic\BlocksEngine\PhpTransformer\Contract\VisualParityReportContract;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactNormalizer;
use Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\ReferenceAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\FormatBridge\FormatAdapterInterface;
use Automattic\BlocksEngine\PhpTransformer\FormatBridge\FormatBridge;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\BlockFactory;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\LinkUrlSanitizer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\TableClassificationPolicy;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternRecognizerInterface;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternRecognizerRegistry;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternRecognitionResult;
use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;
use Automattic\BlocksEngine\PhpTransformer\StaticSite\FontMaterialization\FontMaterializationPlanBuilder;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanView;
use Automattic\BlocksEngine\PhpTransformer\StaticSite\MaterializationPlanBuilder;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\TypographyVisualProbe;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\TypographyVisualProbeComparator;
use Automattic\BlocksEngine\PhpTransformer\WordPress\CanonicalSaveShapeValidator;

if ( ! function_exists('serialize_blocks') ) {
    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    function serialize_blocks(array $blocks): string
    {
        $serialized = '';
        foreach ( $blocks as $block ) {
            $name         = $block['blockName'];
            $attrs        = empty($block['attrs']) ? '' : ' ' . json_encode($block['attrs'], JSON_UNESCAPED_SLASHES);
            $innerContent = $block['innerContent'] ?? array();
            $innerBlocks  = $block['innerBlocks'] ?? array();
            $inner        = '';

            foreach ( $innerContent as $part ) {
                if ( null === $part ) {
                    $inner .= serialize_blocks(array( array_shift($innerBlocks) ));
                    continue;
                }
                $inner .= $part;
            }

            $commentName = str_starts_with($name, 'core/') ? substr($name, 5) : $name;
            $serialized .= '<!-- wp:' . $commentName . $attrs . ' -->' . $inner . '<!-- /wp:' . $commentName . ' -->';
        }

        return $serialized;
    }
}

$assert = static function (bool $condition, string $message, string $detail = ''): void {
    if ( $condition ) {
        return;
    }

    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
    exit(1);
};

$videoResult = ( new HtmlTransformer() )->transform('<video src="hero.mp4" autoplay loop muted playsinline></video>')->toArray();
$assert(
    array(
        'autoplay'    => true,
        'loop'        => true,
        'muted'       => true,
        'playsInline' => true,
    ) === array_intersect_key($videoResult['blocks'][0]['attrs'] ?? array(), array_flip(array( 'autoplay', 'loop', 'muted', 'playsInline' ))),
    'video playback attributes should map to canonical core/video attributes'
);
$assert(
    str_contains($videoResult['blocks'][0]['innerHTML'] ?? '', '<video src="hero.mp4" autoplay="autoplay" loop="loop" muted="muted" playsinline="playsinline"></video>'),
    'video playback attributes should be preserved in native save markup'
);
$coffeeFestivalVideoResult = ( new HtmlTransformer() )->transform('<wix-video><video src="hero.mp4" poster="hero.jpg" controls autoplay loop muted playsinline><track kind="captions" src="captions.vtt" srclang="en" label="English" default></video></wix-video>')->toArray();
$assert(
    'core/video' === ($coffeeFestivalVideoResult['blocks'][0]['blockName'] ?? null)
        && 'hero.jpg' === ($coffeeFestivalVideoResult['blocks'][0]['attrs']['poster'] ?? null)
        && array(array( 'kind' => 'captions', 'src' => 'captions.vtt', 'srcLang' => 'en', 'label' => 'English', 'default' => true )) === ($coffeeFestivalVideoResult['blocks'][0]['attrs']['tracks'] ?? null)
        && str_contains((string) ($coffeeFestivalVideoResult['serialized_blocks'] ?? ''), '<track kind="captions" src="captions.vtt" srclang="en" label="English" default="default">')
        && array() === ($coffeeFestivalVideoResult['fallbacks'] ?? array()),
    'the presentation-transparent Coffee Festival custom video lowers to editable core/video markup'
);
$styledCustomVideoResult = ( new HtmlTransformer() )->transform('<wix-video style="display:block;width:320px;overflow:hidden;transform:scale(.9);border:1px solid red"><video src="hero.mp4"></video></wix-video>')->toArray();
$assert(
    'custom/responsive-media' === ($styledCustomVideoResult['blocks'][0]['blockName'] ?? null)
        && str_contains((string) ($styledCustomVideoResult['blocks'][0]['attrs']['content'] ?? ''), 'style="display:block;width:320px;overflow:hidden;transform:scale(.9);border:1px solid red"')
        && ! str_contains((string) ($styledCustomVideoResult['serialized_blocks'] ?? ''), '<!-- wp:html'),
    'styled custom video hosts preserve presentation in a typed gap instead of lowering to core/video'
);
$ambiguousCustomVideoResult = ( new HtmlTransformer() )->transform('<wix-video><video src="hero.mp4"></video><video src="trailer.mp4"></video></wix-video>')->toArray();
$assert(
    'core/video' !== ($ambiguousCustomVideoResult['blocks'][0]['blockName'] ?? null)
        && ! str_contains((string) ($ambiguousCustomVideoResult['serialized_blocks'] ?? ''), '<!-- wp:html'),
    'ambiguous custom media hosts remain typed gaps rather than raw HTML'
);

$runtimeMediaMaskFixture = file_get_contents(dirname(__DIR__) . '/fixtures/unsupported-runtime-media-mask.html');
$runtimeMediaMaskResult = ( new HtmlTransformer() )->transform((string) $runtimeMediaMaskFixture)->toArray();
$runtimeMediaMaskMarkup = (string) ($runtimeMediaMaskResult['serialized_blocks'] ?? '');
$runtimeMediaMaskFallback = $runtimeMediaMaskResult['fallbacks'][0] ?? array();
$runtimeMediaMaskReportFallback = $runtimeMediaMaskResult['source_reports']['conversion_report']['fallback_diagnostics'][0] ?? array();
$assert(
    'runtime-slideshow' === ($runtimeMediaMaskFallback['tag'] ?? null)
        && 'runtime_media_mask' === ($runtimeMediaMaskFallback['dependent_losses'][0]['relationship'] ?? null)
        && 'omitted' === ($runtimeMediaMaskFallback['dependent_losses'][0]['disposition'] ?? null)
        && ($runtimeMediaMaskFallback['dependent_losses'] ?? null) === ($runtimeMediaMaskReportFallback['dependent_losses'] ?? null),
    'unsupported runtime media records its adjacent decorative mask as an explicit dependent loss'
);
$assert(
    ! str_contains($runtimeMediaMaskMarkup, '334.611')
        && str_contains($runtimeMediaMaskMarkup, 'Quality one')
        && str_contains($runtimeMediaMaskMarkup, 'Quality two')
        && str_contains($runtimeMediaMaskMarkup, 'Quality three'),
    'a dependent mask is omitted without discarding independent sibling labels'
);
$assert(
    1 === count(array_filter($runtimeMediaMaskResult['assets'] ?? array(), static fn (array $asset): bool => 'inline-svg' === ($asset['source'] ?? null)))
        && str_contains($runtimeMediaMaskMarkup, '<img src="assets/materialized-svg/')
        && str_contains(implode("\n", array_column($runtimeMediaMaskResult['assets'] ?? array(), 'content')), 'Independent mark'),
    'an independent labeled SVG still follows the normal native image materialization path'
);

$responsiveImageResult = ( new HtmlTransformer() )->transform('<img src="hero.jpg" srcset="hero.jpg 1x, hero-2x.jpg 2x" sizes="100vw" alt="Hero">')->toArray();
$assert(
    'core/image' === ($responsiveImageResult['blocks'][0]['blockName'] ?? null)
        && ! isset($responsiveImageResult['blocks'][0]['attrs']['srcset'], $responsiveImageResult['blocks'][0]['attrs']['sizes'])
        && ! str_contains($responsiveImageResult['serialized_blocks'] ?? '', 'srcset=')
        && array() === ($responsiveImageResult['fallbacks'] ?? array()),
    'responsive img sources use their captured primary candidate in valid editable core/image markup'
);
$customImageResult = ( new HtmlTransformer() )->transform('<media-image id="hero" class="media-frame"><img class="photo" src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP" data-src="hero.jpg" srcset="hero-small.jpg 340w, hero.jpg 680w" sizes="100vw" alt="Hero"></media-image>')->toArray();
$assert(
    'core/image' === ($customImageResult['blocks'][0]['blockName'] ?? null)
        && 'hero.jpg' === ($customImageResult['blocks'][0]['attrs']['url'] ?? null)
        && str_contains((string) ($customImageResult['blocks'][0]['attrs']['className'] ?? ''), 'media-frame photo')
        && 'hero' === ($customImageResult['blocks'][0]['attrs']['anchor'] ?? null)
        && 0 === substr_count((string) ($customImageResult['serialized_blocks'] ?? ''), '<!-- wp:html')
        && array() === ($customImageResult['fallbacks'] ?? array()),
    'image-only custom elements should lower to core/image while retaining lazy image and CSS identity'
);
$dimensionedCustomImageResult = ( new HtmlTransformer() )->transform('<media-image><img src="hero.jpg" style="width:320px;height:281px;object-fit:cover" width="1951" height="1951" alt="Hero"></media-image>')->toArray();
$assert(
    str_contains((string) ($dimensionedCustomImageResult['serialized_blocks'] ?? ''), 'style="object-fit:cover;width:320px;height:281px"')
        && ! str_contains((string) ($dimensionedCustomImageResult['serialized_blocks'] ?? ''), 'width:1951px'),
    'explicit display dimensions override intrinsic HTML dimensions and serialize as valid CSS lengths'
);
$linkedDimensionedImageResult = ( new HtmlTransformer() )->transform('<a href="/profile"><img src="avatar.jpg" style="width:44px;height:44px" width="44" height="44" alt="Profile"></a>')->toArray();
$assert(
    'custom/responsive-media' === ($linkedDimensionedImageResult['blocks'][0]['blockName'] ?? null)
        && str_contains((string) ($linkedDimensionedImageResult['blocks'][0]['attrs']['content'] ?? ''), 'style="width:44px;height:44px"')
        && '' === ($linkedDimensionedImageResult['blocks'][0]['innerHTML'] ?? 'x'),
    'linked images use the responsive-media companion while preserving authored geometry'
);
$visualLayerImageResult = ( new HtmlTransformer() )->transform('<style>.media-column{position:relative}.visual-layer{position:absolute}</style><div class="media-column"><div class="visual-layer"><media-image><img src="hero.jpg" style="width:320px;height:281px" width="320" height="281" alt="Hero"></media-image></div></div>')->toArray();
$visualLayerImageCss = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $visualLayerImageResult['assets'] ?? array()));
$assert(
    str_contains((string) ($visualLayerImageResult['serialized_blocks'] ?? ''), '<!-- wp:group')
        && str_contains($visualLayerImageCss, 'min-height:281px')
        && str_contains($visualLayerImageCss, 'position:relative'),
    'a media-only container retains intrinsic height when its visual layer is out of flow'
);
$staticVisualMediaWrapperResult = ( new HtmlTransformer() )->transform('<style>.visual-layer{position:absolute}</style><div class="media-shell"><div class="visual-layer"><media-image><img src="hero.jpg" style="width:320px;height:281px" width="320" height="281" alt="Hero"></media-image></div></div>')->toArray();
$staticVisualMediaWrapperCss = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $staticVisualMediaWrapperResult['assets'] ?? array()));
$staticVisualMediaWrapperClass = (string) ($staticVisualMediaWrapperResult['blocks'][0]['attrs']['className'] ?? '');
preg_match('/(?:^|\s)(be-inline-geometry-[a-f0-9]+)(?:\s|$)/', $staticVisualMediaWrapperClass, $staticVisualMediaWrapperCarrier);
$assert(
    isset($staticVisualMediaWrapperCarrier[1])
        && str_contains($staticVisualMediaWrapperCss, 'min-height:281px')
        && ! preg_match('/\.' . preg_quote($staticVisualMediaWrapperCarrier[1], '/') . '\{[^}]*position:relative/', $staticVisualMediaWrapperCss),
    'a source-static visual media wrapper reserves intrinsic height without changing the absolute child containing block'
);
$stickyVisualLayerImageResult = ( new HtmlTransformer() )->transform('<style>.media-column{position:relative}.visual-layer{position:absolute}.sticky-image{position:sticky}</style><div class="media-column"><div class="visual-layer"><media-image class="sticky-image"><img src="hero.jpg" style="width:320px;height:281px" width="320" height="281" alt="Hero"></media-image></div><div class="content"><p>Caption establishes the section height.</p></div></div>')->toArray();
$stickyVisualLayerImageCss = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $stickyVisualLayerImageResult['assets'] ?? array()));
$assert(
    ! str_contains($stickyVisualLayerImageCss, 'min-height:281px'),
    'an absolute visual layer does not impose its image height when normal-flow content already establishes the section height'
);
$customPictureResult = ( new HtmlTransformer() )->transform('<media-image><picture><source media="(min-width: 800px)" srcset="hero-large.jpg 1200w"><img src="hero.jpg" alt="Hero"></picture></media-image>')->toArray();
$assert(
    'custom/responsive-media' === ($customPictureResult['blocks'][0]['blockName'] ?? null)
        && str_contains($customPictureResult['blocks'][0]['attrs']['content'] ?? '', '<media-image><picture>')
        && str_contains($customPictureResult['blocks'][0]['attrs']['content'] ?? '', 'media="(min-width: 800px)" srcset="hero-large.jpg 1200w"')
        && array() === ($customPictureResult['fallbacks'] ?? array()),
    'custom image wrappers preserve picture source selection in the reusable editable companion block'
);
$responsiveSrcsetSanitization = ( new HtmlTransformer() )->transform('<picture><source srcset="javascript:alert(1) 1x, safe.webp 2x"><img src="hero.jpg" srcset="data:image/png;base64,aGVsbG8= 1x, blob:https://example.com/id 2x, hero-2x.jpg 3x"></picture>')->toArray();
$responsiveSrcsetMarkup = (string) ($responsiveSrcsetSanitization['serialized_blocks'] ?? '');
$assert(
    str_contains($responsiveSrcsetMarkup, 'safe.webp 2x')
        && str_contains($responsiveSrcsetMarkup, 'data:image/png;base64,aGVsbG8= 1x')
        && str_contains($responsiveSrcsetMarkup, 'hero-2x.jpg 3x')
        && ! str_contains($responsiveSrcsetMarkup, 'javascript:')
        && ! str_contains($responsiveSrcsetMarkup, 'blob:'),
    'responsive core/html fallback strips unsafe srcset candidates while retaining safe URLs and descriptors'
);
$responsiveGallery = ( new HtmlTransformer() )->transform('<div class="gallery"><figure><img src="one.jpg" srcset="one-2x.jpg 2x"></figure><figure><img src="two.jpg"></figure></div>')->toArray();
$assert(
    'custom/responsive-media' === ($responsiveGallery['blocks'][0]['blockName'] ?? null)
        && 1 === count($responsiveGallery['source_reports']['generated_blocks'] ?? array())
        && array() === ($responsiveGallery['fallbacks'] ?? array())
        && str_contains($responsiveGallery['blocks'][0]['attrs']['content'] ?? '', '<div class="gallery">')
        && ! str_contains((string) ($responsiveGallery['serialized_blocks'] ?? ''), '<!-- wp:gallery'),
    'responsive gallery media is preserved as one reusable editable block instead of a gallery with unsupported children'
);
$carouselSource = '<div class="service-carousel"><button aria-label="Previous slide">Previous</button><div role="list"><div role="listitem" aria-label="Back pain"><img src="back.jpg" alt="Back pain"><div class="title">Back pain</div><div class="description">Treatment for back pain.</div></div><div role="listitem" aria-label="Sciatica"><img src="sciatica.jpg" alt="Sciatica"><div class="title">Sciatica</div><div class="description">Treatment for sciatica.</div></div></div><button aria-label="Next slide">Next</button><div class="expanded-gallery"><div role="list"><div role="listitem"><img src="back-expanded.jpg" alt="Back pain expanded"></div><div role="listitem"><img src="sciatica-expanded.jpg" alt="Sciatica expanded"></div></div></div></div>';
$carouselResult = ( new HtmlTransformer() )->transform($carouselSource)->toArray();
$carouselBlock = $carouselResult['blocks'][0] ?? array();
$carouselMarkup = (string) ($carouselResult['serialized_blocks'] ?? '');
$carouselDefinitions = $carouselResult['source_reports']['generated_blocks'] ?? array();
$assert(
    'custom/authored-carousel' === ($carouselBlock['blockName'] ?? null)
        && 2 === count($carouselBlock['innerBlocks'] ?? array())
        && 'core/image' === ($carouselBlock['innerBlocks'][0]['blockName'] ?? null)
        && str_contains($carouselMarkup, 'back.jpg')
        && str_contains($carouselMarkup, 'sciatica.jpg')
        && str_contains($carouselMarkup, 'Treatment for back pain.')
        && ! str_contains($carouselMarkup, 'back-expanded.jpg')
        && ! str_contains($carouselMarkup, 'expanded-gallery'),
    'bounded carousel topology lowers one primary ordered rail to editable native slide blocks'
);
$assert(
    1 === count($carouselDefinitions)
        && 'authored-carousel' === ($carouselDefinitions[0]['name'] ?? null)
        && 'file:./view.js' === ($carouselDefinitions[0]['block_json']['viewScriptModule'] ?? null)
        && true === ($carouselDefinitions[0]['block_json']['supports']['interactivity'] ?? null)
        && str_contains((string) ($carouselDefinitions[0]['view_js'] ?? ''), "store( 'blocks-engine/carousel'")
        && isset($carouselDefinitions[0]['assets']['style.css']),
    'bounded carousel projection carries one generic editor block with scoped frontend behavior'
);
$staticGalleryResult = ( new HtmlTransformer() )->transform('<div class="service-gallery"><div role="list"><div role="listitem"><img src="one.jpg" alt="One"></div><div role="listitem"><img src="two.jpg" alt="Two"></div></div></div>')->toArray();
$assert(
    'custom/authored-carousel' !== ($staticGalleryResult['blocks'][0]['blockName'] ?? null),
    'an ordered image collection without previous and next controls is not promoted to an interactive carousel'
);

$referenceAnalyzer = new ReferenceAnalyzer();
$htmlCandidates = $referenceAnalyzer->htmlReferenceCandidates('<a href="about.html">About</a><img src="assets/logo.png" alt="Logo">', 'index.html');
$assert('href' === ($htmlCandidates[0]['attribute'] ?? ''), 'reference analyzer extracts HTML href references');
$assert('about.html' === ($htmlCandidates[0]['url'] ?? ''), 'reference analyzer preserves HTML href URL values');
$assert('src' === ($htmlCandidates[1]['attribute'] ?? ''), 'reference analyzer extracts HTML src references');
$assert('assets/logo.png' === ($htmlCandidates[1]['url'] ?? ''), 'reference analyzer preserves HTML src URL values');

$cssCandidates = $referenceAnalyzer->cssReferenceCandidates('@import "fonts/fonts.css"; body{background:url("../assets/paper.png")} @font-face{font-family:"Fixture Sans";src:url("FixtureSans.woff2") format("woff2")}', 'theme/site.css');
$assert('css-import' === ($cssCandidates[0]['context'] ?? ''), 'reference analyzer extracts CSS @import references');
$assert('fonts/fonts.css' === ($cssCandidates[0]['url'] ?? ''), 'reference analyzer preserves CSS @import URL values');
$assert('css-url' === ($cssCandidates[1]['context'] ?? ''), 'reference analyzer extracts CSS url() references');
$assert('../assets/paper.png' === ($cssCandidates[1]['url'] ?? ''), 'reference analyzer preserves CSS url() values');
$assert('css-font-face' === ($cssCandidates[2]['context'] ?? ''), 'reference analyzer marks @font-face url() references');
$assert('FixtureSans.woff2' === ($cssCandidates[2]['url'] ?? ''), 'reference analyzer preserves @font-face local font references');

$referenceReports = $referenceAnalyzer->referenceReports(array(
    array('path' => 'index.html', 'kind' => 'html', 'content' => '<a href="about.html">About</a><img src="assets/logo.png" alt="Logo">', 'binary' => false),
    array('path' => 'about.html', 'kind' => 'html', 'content' => '<h1>About</h1>', 'binary' => false),
    array('path' => 'theme/site.css', 'kind' => 'css', 'content' => '@import "fonts/fonts.css"; body{background:url("../assets/paper.png")} @font-face{font-family:"Fixture Sans";src:url("FixtureSans.woff2") format("woff2")}', 'binary' => false),
    array('path' => 'theme/fonts/fonts.css', 'kind' => 'css', 'content' => '', 'binary' => false, 'mime_type' => 'text/css', 'role' => 'style', 'bytes' => 0),
    array('path' => 'assets/logo.png', 'kind' => 'image', 'content_base64' => base64_encode('logo'), 'binary' => true, 'mime_type' => 'image/png', 'role' => 'asset', 'bytes' => 4),
    array('path' => 'assets/paper.png', 'kind' => 'image', 'content_base64' => base64_encode('paper'), 'binary' => true, 'mime_type' => 'image/png', 'role' => 'asset', 'bytes' => 5),
    array('path' => 'theme/FixtureSans.woff2', 'kind' => 'font', 'content_base64' => base64_encode('font'), 'binary' => true, 'mime_type' => 'font/woff2', 'role' => 'asset', 'bytes' => 4),
));
$assert('about.html' === ($referenceReports['internal_links'][0]['target_path'] ?? ''), 'reference analyzer assembles HTML href internal link reports');
$assert('assets/logo.png' === ($referenceReports['asset_references'][0]['asset_path'] ?? ''), 'reference analyzer assembles HTML src asset reference reports');
$assert('theme/fonts/fonts.css' === ($referenceReports['asset_references'][1]['asset_path'] ?? ''), 'reference analyzer assembles CSS @import asset reference reports');
$assert('assets/paper.png' === ($referenceReports['asset_references'][2]['asset_path'] ?? ''), 'reference analyzer resolves CSS url() reports relative to source CSS');
$assert('theme/FixtureSans.woff2' === ($referenceReports['asset_references'][3]['asset_path'] ?? ''), 'reference analyzer assembles @font-face local font reference reports');
$assert(2 === count($referenceReports['image_references']), 'reference analyzer projects HTML and CSS image asset references');
$assert('assets/paper.png' === ($referenceReports['image_references'][1]['asset_path'] ?? ''), 'reference analyzer projects CSS background images into image references');
$assert('css-url' === ($referenceReports['image_references'][1]['context'] ?? ''), 'reference analyzer preserves CSS background image context');

$imageReferenceReports = $referenceAnalyzer->referenceReports(array(
    array('path' => 'pages/index.html', 'kind' => 'html', 'content' => '<picture><source srcset="../assets/hero-small.png 480w, ../assets/hero-large.png 960w"><img src="../assets/logo.png" srcset="../assets/logo@2x.png 2x" alt="Logo"></picture><section style="background-image:url(../assets/panel.png)"></section><svg><image href="../assets/vector.png"></image></svg>', 'binary' => false),
    array('path' => 'assets/hero-small.png', 'kind' => 'image', 'content_base64' => base64_encode('small'), 'binary' => true, 'mime_type' => 'image/png', 'role' => 'asset', 'bytes' => 5),
    array('path' => 'assets/hero-large.png', 'kind' => 'image', 'content_base64' => base64_encode('large'), 'binary' => true, 'mime_type' => 'image/png', 'role' => 'asset', 'bytes' => 5),
    array('path' => 'assets/logo.png', 'kind' => 'image', 'content_base64' => base64_encode('logo'), 'binary' => true, 'mime_type' => 'image/png', 'role' => 'asset', 'bytes' => 4),
    array('path' => 'assets/logo@2x.png', 'kind' => 'image', 'content_base64' => base64_encode('retina'), 'binary' => true, 'mime_type' => 'image/png', 'role' => 'asset', 'bytes' => 6),
    array('path' => 'assets/panel.png', 'kind' => 'image', 'content_base64' => base64_encode('panel'), 'binary' => true, 'mime_type' => 'image/png', 'role' => 'asset', 'bytes' => 5),
    array('path' => 'assets/vector.png', 'kind' => 'image', 'content_base64' => base64_encode('vector'), 'binary' => true, 'mime_type' => 'image/png', 'role' => 'asset', 'bytes' => 6),
));
$assert(6 === count($imageReferenceReports['image_references']), 'image reference analysis reports src, srcset, inline background, picture source, and SVG image href references');
$assert('source' === ($imageReferenceReports['image_references'][0]['element'] ?? ''), 'image reference analysis reports picture source elements');
$assert('srcset' === ($imageReferenceReports['image_references'][0]['attribute'] ?? ''), 'image reference analysis preserves srcset attributes');
$assert('assets/hero-small.png' === ($imageReferenceReports['image_references'][0]['asset_path'] ?? ''), 'image reference analysis resolves source srcset paths relative to the HTML document');
$assert('inline-style' === ($imageReferenceReports['image_references'][4]['context'] ?? ''), 'image reference analysis reports inline CSS background image references');
$assert('assets/panel.png' === ($imageReferenceReports['image_references'][4]['asset_path'] ?? ''), 'image reference analysis resolves inline style image paths relative to the HTML document');
$assert('image' === ($imageReferenceReports['image_references'][5]['element'] ?? ''), 'image reference analysis reports SVG image href elements');
$assert('assets/vector.png' === ($imageReferenceReports['image_references'][5]['asset_path'] ?? ''), 'image reference analysis resolves SVG image href paths relative to the HTML document');

$assertNormalizedFallbackDiagnostic = static function (array $diagnostic, string $code, string $severity, string $runtimeRequirement, string $suggestedPrimitive, string $conversionClassification = '') use ($assert): void {
    $assert($code === ($diagnostic['diagnostic_code'] ?? ''), "conversion report exposes {$code} diagnostic code");
    $assert($severity === ($diagnostic['severity'] ?? ''), "conversion report exposes {$code} severity");
    if ( '' !== $conversionClassification ) {
        $assert($conversionClassification === ($diagnostic['conversion_classification'] ?? ''), "conversion report exposes {$code} conversion classification");
        $assert(isset($diagnostic['preservation_strategy']) && '' !== $diagnostic['preservation_strategy'], "conversion report exposes {$code} preservation strategy");
    }
    $assert($runtimeRequirement === ($diagnostic['runtime_requirement'] ?? ''), "conversion report exposes {$code} runtime requirement");
    $assert(isset($diagnostic['recoverability']) && '' !== $diagnostic['recoverability'], "conversion report exposes {$code} recoverability");
    $assert(isset($diagnostic['actionability']) && '' !== $diagnostic['actionability'], "conversion report exposes {$code} actionability");
    $assert($suggestedPrimitive === ($diagnostic['suggested_primitive'] ?? ''), "conversion report exposes {$code} suggested primitive");
    $assert(isset($diagnostic['materialization_hint']) && '' !== $diagnostic['materialization_hint'], "conversion report exposes {$code} materialization hint");
};

$assertInvalidCanonicalEnvelope = static function (array $result, string $expectedMessage, string $message, bool $requireMaterializationPlan = false) use ($assert): void {
    try {
        TransformerResult::assertCanonicalEnvelope($result, $requireMaterializationPlan);
    } catch ( \InvalidArgumentException $exception ) {
        $assert(str_contains($exception->getMessage(), $expectedMessage), $message, $exception->getMessage());
        return;
    }

    $assert(false, $message, 'Canonical envelope validation unexpectedly passed.');
};

$visualParityFixture = array(
    'schema'     => VisualParityReportContract::FIXTURE_SCHEMA,
    'name'       => 'visual-parity-contract-fixture',
    'source'     => array('html_path' => 'source/index.html', 'renderer' => 'playwright'),
    'target'     => array('url' => 'https://example.test/', 'renderer' => 'wordpress'),
    'viewports'  => array(
        array('id' => 'mobile', 'width' => 390, 'height' => 844),
        array('id' => 'desktop', 'width' => 1440, 'height' => 1000),
    ),
    'capture'    => array(
        array('kind' => 'button', 'selector' => '.hero .button'),
        array('kind' => 'menu', 'selector' => 'nav'),
        array('kind' => 'card', 'selector' => '.feature-card'),
        array('kind' => 'form', 'selector' => 'form'),
    ),
    'matchers'   => array(
        array('kind' => 'selector', 'source_selector' => '.hero .button', 'target_selector' => '.wp-block-button__link', 'min_confidence' => 0.9),
    ),
    'thresholds' => array('max_mismatch_percent' => 0.5, 'max_style_deltas' => 4, 'min_match_confidence' => 0.75, 'severity_gate' => 'error'),
);
VisualParityReportContract::assertFixture($visualParityFixture);

$visualParityReport = array(
    'schema'                => VisualParityReportContract::REPORT_SCHEMA,
    'status'                => 'warning',
    'severity'              => 'warning',
    'source_render'         => array('kind' => 'source', 'route' => '/', 'html_path' => 'source/index.html', 'renderer' => 'playwright', 'screenshot_path' => 'screens/source-desktop.png'),
    'target_render'         => array('kind' => 'target', 'route' => '/', 'url' => 'https://example.test/', 'renderer' => 'wordpress', 'screenshot_path' => 'screens/target-desktop.png'),
    'viewports'             => array(
        array('id' => 'desktop', 'width' => 1440, 'height' => 1000, 'source_screenshot_path' => 'screens/source-desktop.png', 'target_screenshot_path' => 'screens/target-desktop.png', 'diff_screenshot_path' => 'screens/diff-desktop.png'),
    ),
    'matches'               => array(
        array('kind' => 'button', 'source_selector' => '.hero .button', 'target_selector' => '.wp-block-button__link', 'confidence' => 0.96, 'button' => array('label' => 'Book now', 'href' => '/book', 'variant' => 'primary', 'icon_position' => 'none')),
        array('kind' => 'menu', 'source_selector' => 'nav.primary', 'target_selector' => '.wp-block-navigation', 'confidence' => 0.92, 'menu' => array('orientation' => 'horizontal', 'item_count' => 3, 'labels' => array('Home', 'Services', 'Contact'), 'has_submenus' => false)),
        array('kind' => 'card', 'source_selector' => '.feature-card', 'target_selector' => '.wp-block-group.feature-card', 'confidence' => 0.88, 'card' => array('heading' => 'Therapy', 'media_present' => true, 'link_present' => true, 'action_count' => 1)),
        array('kind' => 'form', 'source_selector' => 'form.contact', 'target_selector' => '.wp-block-html form', 'confidence' => 0.84, 'form' => array('action' => '/contact', 'method' => 'post', 'control_count' => 3, 'control_types' => array('email', 'select', 'submit'), 'required_count' => 1)),
    ),
    'computed_style_deltas' => array(
        array('viewport_id' => 'desktop', 'source_selector' => '.hero .button', 'target_selector' => '.wp-block-button__link', 'property' => 'border-radius', 'source_value' => '999px', 'target_value' => '4px', 'delta' => 'rounded-to-square', 'severity' => 'warning'),
    ),
    'visual_diff'           => array('available' => true, 'mismatch_percent' => 0.42, 'mismatch_pixels' => 420, 'total_pixels' => 100000, 'ssim' => 0.98, 'threshold' => 0.5, 'diff_screenshot_path' => 'screens/diff-desktop.png'),
    'capture_diagnostics'   => array('runner' => 'visual-parity-fixture-runner', 'browser' => 'chromium', 'timing_ms' => 123.4, 'artifact_paths' => array('screens/source-desktop.png', 'screens/target-desktop.png'), 'warnings' => array()),
    'findings'              => array(
        array(
            'id'                 => 'style-button-radius',
            'severity'           => 'warning',
            'category'           => 'style',
            'summary'            => 'Button radius changed.',
            'reason_code'        => 'button_radius_changed',
            'repair_bucket'      => 'style_token_alignment',
            'pattern_family'     => 'button_shape',
            'confidence'         => 0.91,
            'kind'               => 'button',
            'selector_evidence'  => array('source_selector' => '.hero .button', 'target_selector' => '.wp-block-button__link', 'source_text' => 'Book now', 'target_text' => 'Book now'),
            'property_evidence'  => array(array('property' => 'border-radius', 'source_value' => '999px', 'target_value' => '4px', 'delta' => 'rounded-to-square')),
            'recommendation_ids' => array('rec-button-radius'),
        ),
    ),
    'recommendations'       => array(
        array('id' => 'rec-button-radius', 'priority' => 'medium', 'summary' => 'Align target button radius with the source button treatment.', 'repair_bucket' => 'style_token_alignment', 'pattern_family' => 'button_shape', 'confidence' => 0.86, 'finding_ids' => array('style-button-radius')),
    ),
);
VisualParityReportContract::assertReport($visualParityReport);

$invalidVisualParityReport = $visualParityReport;
$invalidVisualParityReport['matches'][0]['kind'] = 'woocommerce-button';
try {
    VisualParityReportContract::assertReport($invalidVisualParityReport);
    $assert(false, 'visual parity report rejects product-specific match kinds');
} catch ( \InvalidArgumentException $exception ) {
    $assert(str_contains($exception->getMessage(), 'unsupported component kind'), 'visual parity report rejects product-specific match kinds', $exception->getMessage());
}

$invalidVisualParityReport = $visualParityReport;
$invalidVisualParityReport['findings'][0]['confidence'] = 1.5;
try {
    VisualParityReportContract::assertReport($invalidVisualParityReport);
    $assert(false, 'visual parity report rejects out-of-range finding confidence');
} catch ( \InvalidArgumentException $exception ) {
    $assert(str_contains($exception->getMessage(), 'numeric between 0 and 1'), 'visual parity report rejects out-of-range finding confidence', $exception->getMessage());
}

// Typography visual probe comparator emits reports through the shared
// VisualParityReportContract: findings when source vs target typography drifts,
// none when they align.
$typographyProbe = new TypographyVisualProbe();
$typographyComparator = new TypographyVisualProbeComparator();
$typographySource = '<style>body{font-family:"Inter",sans-serif}h1{font-family:"Playfair Display",serif;font-size:48px;font-weight:700}p{font-size:18px}</style><body><article><h1>Welcome Home</h1><p>Intro body copy here.</p></article></body>';
$typographyTarget = '<style>body{font-family:Arial,sans-serif}h1{font-size:32px;font-weight:400}p{font-size:18px}</style><body><article><h1>Welcome Home</h1><p>Intro body copy here.</p></article></body>';

$typographyMismatchReport = $typographyComparator->compare(
    $typographyProbe->extract($typographySource),
    $typographyProbe->extract($typographyTarget)
);
VisualParityReportContract::assertReport($typographyMismatchReport);
$assert(VisualParityReportContract::REPORT_SCHEMA === ($typographyMismatchReport['schema'] ?? ''), 'typography probe emits the visual parity report contract schema');
$assert('warning' === ($typographyMismatchReport['status'] ?? ''), 'typography probe report warns when source vs target typography differs');
$assert(count($typographyMismatchReport['findings'] ?? array()) > 0, 'typography probe emits findings on typography drift');
$typographyCategories = array_map(static fn (array $finding): string => (string) ($finding['category'] ?? ''), $typographyMismatchReport['findings'] ?? array());
$assert(in_array('typography', $typographyCategories, true), 'typography probe findings use the typography category');
$typographyMatchKinds = array_map(static fn (array $match): string => (string) ($match['kind'] ?? ''), $typographyMismatchReport['matches'] ?? array());
$assert(array() === array_diff($typographyMatchKinds, array('generic')), 'typography probe matches use the generic component kind');

$typographyMatchReport = $typographyComparator->compare(
    $typographyProbe->extract($typographySource),
    $typographyProbe->extract($typographySource)
);
VisualParityReportContract::assertReport($typographyMatchReport);
$assert('pass' === ($typographyMatchReport['status'] ?? ''), 'typography probe report passes when source and target typography match');
$assert(array() === ($typographyMatchReport['findings'] ?? array('non-empty')), 'typography probe emits no findings when typography matches');

$assert('assets/logo.png' === ArtifactPath::safeRelativePath(' ./assets//logo.png '), 'artifact paths trim relative markers and duplicate separators');
$assert('' === ArtifactPath::safeRelativePath('/assets/logo.png'), 'artifact paths reject root-absolute paths');
$assert('' === ArtifactPath::safeRelativePath('C:\\assets\\logo.png'), 'artifact paths reject drive-absolute paths');
$assert('' === ArtifactPath::safeRelativePath('../secrets/logo.png'), 'artifact paths reject traversal paths');
$assert('assets/logo.png' === ArtifactPath::resolveRelativePath('../assets/logo.png?version=1#hash', 'pages/home.html'), 'artifact references resolve relative paths without query or fragment');
$assert('assets/JOHN-OATES-‘ARKANSAS.jpg' === ArtifactPath::resolveRelativePath('../assets/JOHN-OATES-%E2%80%98ARKANSAS.jpg', 'pages/home.html'), 'artifact references resolve percent-encoded Unicode path segments to canonical artifact paths');
$assert('' === ArtifactPath::resolveRelativePath('../assets%2flogo.png', 'pages/home.html'), 'artifact references reject encoded path separators');
$assert('' === ArtifactPath::resolveRelativePath('https://example.com/logo.png', 'pages/home.html'), 'artifact references reject URL references');
$assert('' === ArtifactPath::resolveRelativePath('../../logo.png', 'pages/home.html'), 'artifact references reject traversal above the artifact root');

$registryDocument = new DOMDocument();
$registryDocument->loadHTML('<div></div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
$registryElement = $registryDocument->getElementsByTagName('div')->item(0);
$registry = new PatternRecognizerRegistry(array(
    new class implements PatternRecognizerInterface {
        public function recognize(DOMElement $element, PatternContext $context): ?PatternRecognitionResult
        {
            return 'div' === strtolower($element->tagName) ? new PatternRecognitionResult(array('blockName' => 'core/group')) : null;
        }
    },
));
$registryContext = new PatternContext(
    static fn (DOMElement $element): array => array(),
    new \Automattic\BlocksEngine\PhpTransformer\Tests\Support\SourceBlockCreatorFixture(static fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => array('blockName' => $name, 'attrs' => $attrs, 'innerBlocks' => $innerBlocks))
);
$assert($registryElement instanceof DOMElement, 'pattern registry fixture element parses');
$assert('core/group' === ($registry->firstMatch($registryElement, $registryContext)?->block()['blockName'] ?? null), 'pattern registry returns the first recognizer match');

$tableElement = static function (string $html): DOMElement {
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $table = $document->getElementsByTagName('table')->item(0);
    if ( ! $table instanceof DOMElement ) {
        throw new RuntimeException('Fixture did not contain a table.');
    }

    return $table;
};
$tablePolicy = new TableClassificationPolicy();
$simpleDataTableClassification = $tablePolicy->classify($tableElement('<table><thead><tr><th>Name</th><th>Role</th></tr></thead><tbody><tr><td>Ada</td><td>Engineer</td></tr></tbody></table>'));
$assert(TableClassificationPolicy::DATA === ($simpleDataTableClassification['classification'] ?? null), 'table classifier identifies simple header tables as data tables');
$assert(true === ($simpleDataTableClassification['representable'] ?? null), 'table classifier marks simple data tables representable');
$assert(array(2, 2) === ($simpleDataTableClassification['signals']['column_counts'] ?? null), 'table classifier exposes direct-row column counts');
$layoutTableClassification = $tablePolicy->classify($tableElement('<table><tr><td>Legacy layout copy</td></tr></table>'));
$assert(TableClassificationPolicy::LAYOUT_SIMPLE === ($layoutTableClassification['classification'] ?? null), 'table classifier identifies rectangular tables without data semantics as simple layout tables');
$nestedTableClassification = $tablePolicy->classify($tableElement('<table><tr><td>Outer<table><tr><td>Inner</td></tr></table></td></tr></table>'));
$assert(TableClassificationPolicy::COMPLEX_NESTED === ($nestedTableClassification['classification'] ?? null), 'table classifier identifies descendant tables as complex nested');
$assert(false === ($nestedTableClassification['representable'] ?? null), 'table classifier marks descendant tables not representable as native tables');
$assert(array(1) === ($nestedTableClassification['signals']['column_counts'] ?? null), 'table classifier scopes row signals to the current table before nested fallback');
$spanningTableClassification = $tablePolicy->classify($tableElement('<table><tr><td colspan="2">Merged</td></tr><tr><td>A</td><td>B</td></tr></table>'));
$assert(TableClassificationPolicy::COMPLEX_SPANNING === ($spanningTableClassification['classification'] ?? null), 'table classifier identifies colspan tables as complex spanning');
$assert(true === ($spanningTableClassification['signals']['has_colspan'] ?? null), 'table classifier exposes colspan signal');

$simpleDataTableResult = ( new HtmlTransformer() )->transform('<table><thead><tr><th>Name</th><th>Role</th></tr></thead><tbody><tr><td>Ada</td><td>Engineer</td></tr></tbody></table>')->toArray();
$assert('core/table' === ($simpleDataTableResult['blocks'][0]['blockName'] ?? null), 'simple data table converts to native core/table');
$assert(str_contains((string) ($simpleDataTableResult['serialized_blocks'] ?? ''), '<!-- wp:table'), 'simple data table serializes native table markup');
$assert(false === ($simpleDataTableResult['blocks'][0]['attrs']['hasFixedLayout'] ?? null) && str_contains((string) ($simpleDataTableResult['serialized_blocks'] ?? ''), '<table>') && ! str_contains((string) ($simpleDataTableResult['serialized_blocks'] ?? ''), 'has-fixed-layout'), 'source-auto tables retain native automatic column sizing');
$fixedSourceTableResult = ( new HtmlTransformer() )->transform('<style>table{table-layout:fixed}</style><table><tbody><tr><td>Fixed</td><td>Columns</td></tr></tbody></table>')->toArray();
$assert(true === ($fixedSourceTableResult['blocks'][0]['attrs']['hasFixedLayout'] ?? null) && str_contains((string) ($fixedSourceTableResult['serialized_blocks'] ?? ''), '<table class="has-fixed-layout">'), 'explicit source fixed table layout retains native fixed layout');
$styledTableResult = ( new HtmlTransformer() )->transform('<style>.services-table td.feature{width:40%;color:#123}.services-table tbody tr:last-child td:first-child{padding:12px}.services-table td.feature:hover{color:#456}.team-table th.role{font-weight:700}.multi-body tbody:nth-child(2) td{background:#eee}</style><table class="services-table"><tbody><tr><td>Label</td><td class="feature">Value</td></tr><tr><td>Last</td><td>Row</td></tr></tbody></table><table class="team-table"><thead><tr><th>Name</th><th class="role">Role</th></tr></thead></table><table class="multi-body"><tbody><tr><td>First</td></tr></tbody><tbody><tr><td>Second</td></tr></tbody></table>')->toArray();
$styledTableMarkup = (string) ($styledTableResult['serialized_blocks'] ?? '');
$styledTableCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $styledTableResult['assets'] ?? array()));
$assert(3 === count(array_filter($styledTableResult['blocks'] ?? array(), static fn (array $block): bool => 'core/table' === ($block['blockName'] ?? ''))), 'class-addressed native tables remain core/table blocks');
$assert(3 === count(array_filter($styledTableResult['blocks'] ?? array(), static fn (array $block): bool => str_contains((string) ($block['attrs']['className'] ?? ''), 'blocks-engine-table-'))) && ! str_contains($styledTableMarkup, '<td class=') && ! str_contains($styledTableMarkup, '<th class='), 'native table wrappers carry isolated projection markers without unsupported cell classes');
$assert(str_contains($styledTableCss, '>table>tbody>tr:nth-child(1)>td:nth-child(2)') && str_contains($styledTableCss, '>table>thead>tr:nth-child(1)>th:nth-child(2)'), 'cell-owned author selectors project to exact native table structure');
$assert(str_contains($styledTableCss, ':hover{color:#456}') && str_contains($styledTableCss, '.services-table tbody tr:last-child td:first-child{padding:12px}'), 'projected table selectors retain pseudo-state while already-valid structural selectors stay compact');
$assert(str_contains($styledTableCss, '>table>tbody>tr:nth-child(2)>td:nth-child(1)') && str_contains($styledTableCss, '{background:#eee}'), 'section-position selectors retain the matched source rows when native table bodies merge');
$assert(3 === count(array_unique(array_map(static fn (array $block): string => (string) ($block['attrs']['className'] ?? ''), $styledTableResult['blocks'] ?? array()))), 'table projection markers isolate same-shaped native tables');
$assert('pass' === ($styledTableResult['source_reports']['wp_block_validity']['status'] ?? ''), 'structurally projected native tables remain editor-valid');
$inlineWidthTableResult = ( new HtmlTransformer() )->transform('<table class="layout-table"><tbody><tr><td style="width:27.5%">Left</td><td style="width:45%">Center</td><td style="width:27.5%">Right</td></tr></tbody></table>')->toArray();
$inlineWidthTableCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $inlineWidthTableResult['assets'] ?? array()));
$assert('core/table' === ($inlineWidthTableResult['blocks'][0]['blockName'] ?? null), 'layout tables with authored cell widths remain editable native tables');
$assert(str_contains($inlineWidthTableCss, '>table>tbody>tr:nth-child(1)>td:nth-child(1){width:27.5%!important}') && str_contains($inlineWidthTableCss, '>table>tbody>tr:nth-child(1)>td:nth-child(2){width:45%!important}'), 'native table geometry CSS preserves authored cell proportions');
$assert('pass' === ($inlineWidthTableResult['source_reports']['wp_block_validity']['status'] ?? ''), 'inline-width native tables remain editor-valid');
$paddedTableResult = ( new HtmlTransformer() )->transform('<table><tbody><tr><td style="width:50%;padding:0 15px"><div style="text-align:center"><img src="centered.jpg" alt="Centered" style="width:auto;max-width:100%"></div></td><td style="width:50%;padding:0 15px">Copy</td></tr></tbody></table>')->toArray();
$paddedTableCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $paddedTableResult['assets'] ?? array()));
$assert('core/columns' === ($paddedTableResult['blocks'][0]['blockName'] ?? null) && str_contains($paddedTableCss, 'width:50% !important') && str_contains((string) ($paddedTableResult['serialized_blocks'] ?? ''), 'padding-right:15px'), 'image-and-copy layout tables lower to native columns while preserving authored cell geometry');
$assert('pass' === ($paddedTableResult['source_reports']['wp_block_validity']['status'] ?? ''), 'padded native image columns remain editor-valid');
$legacyMediaTableResult = ( new HtmlTransformer() )->transform('<table class="portfolio-row"><tbody><tr><td style="width:50%;text-align:right"><img src="dsc-9062.jpg" alt="Portrait" style="width:466"></td><td style="width:50%"><h2>Portfolio</h2><p>Selected work.</p></td></tr></tbody></table>')->toArray();
$legacyMediaTableMarkup = (string) ($legacyMediaTableResult['serialized_blocks'] ?? '');
$legacyMediaTableCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $legacyMediaTableResult['assets'] ?? array()));
$legacyMediaTableColumns = $legacyMediaTableResult['blocks'][0]['innerBlocks'][0]['innerBlocks'] ?? array();
$assert('core/group' === ($legacyMediaTableResult['blocks'][0]['blockName'] ?? null) && 'core/columns' === ($legacyMediaTableResult['blocks'][0]['innerBlocks'][0]['blockName'] ?? null) && 2 === count($legacyMediaTableColumns) && 'core/image' === ($legacyMediaTableColumns[0]['innerBlocks'][0]['blockName'] ?? null) && 'core/heading' === ($legacyMediaTableColumns[1]['innerBlocks'][0]['blockName'] ?? null) && 'core/paragraph' === ($legacyMediaTableColumns[1]['innerBlocks'][1]['blockName'] ?? null), 'legacy image table layouts lower their media and editorial cells into native Columns blocks');
$assert(! str_contains($legacyMediaTableMarkup, '<!-- wp:table') && ! str_contains($legacyMediaTableMarkup, '<!-- wp:html') && str_contains($legacyMediaTableMarkup, 'width:466px') && str_contains($legacyMediaTableCss, 'text-align:right') && str_contains($legacyMediaTableCss, 'width:466px !important'), 'legacy image table lowering removes opaque table HTML while preserving aligned valid image geometry');
$assert('pass' === ($legacyMediaTableResult['source_reports']['wp_block_validity']['status'] ?? ''), 'legacy image table columns remain Gutenberg-valid');
$multiRowMediaTableResult = ( new HtmlTransformer() )->transform('<table><tbody><tr><td><img src="one.jpg" alt="One"></td><td><h2>One</h2></td></tr><tr><td><img src="two.jpg" alt="Two"></td><td><p>Two</p></td></tr></tbody></table>')->toArray();
$assert('core/group' === ($multiRowMediaTableResult['blocks'][0]['blockName'] ?? null) && 2 === count($multiRowMediaTableResult['blocks'][0]['innerBlocks'] ?? array()) && array() === ($multiRowMediaTableResult['fallbacks'] ?? array()) && 'pass' === ($multiRowMediaTableResult['source_reports']['wp_block_validity']['status'] ?? ''), 'repeated legacy image rows retain their row topology as native Columns without fallbacks');
$maxWidthImageResult = ( new HtmlTransformer() )->transform('<img src="centered.jpg" alt="Centered" style="width:auto;max-width:100%">')->toArray();
$maxWidthImageMarkup = (string) ($maxWidthImageResult['serialized_blocks'] ?? '');
$maxWidthImageCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $maxWidthImageResult['assets'] ?? array()));
$assert(! str_contains($maxWidthImageMarkup, 'style="max-width:100%"'), 'native image save markup omits unsupported max-width block support');
$assert(str_contains($maxWidthImageCss, 'max-width:100%'), 'native image max-width remains in its generated geometry carrier');
$assert('pass' === ($maxWidthImageResult['source_reports']['wp_block_validity']['status'] ?? ''), 'max-width native image remains editor-valid');
$nestedTableResult = ( new HtmlTransformer() )->transform('<table><tr><td>Outer<table><tr><td>Inner</td></tr></table></td></tr></table>')->toArray();
$assert('core/columns' === ($nestedTableResult['blocks'][0]['blockName'] ?? null), 'nested single-row layout table lowers to responsive columns');
$assert(! str_contains((string) ($nestedTableResult['serialized_blocks'] ?? ''), '<!-- wp:html') && str_contains((string) ($nestedTableResult['serialized_blocks'] ?? ''), 'Outer') && str_contains((string) ($nestedTableResult['serialized_blocks'] ?? ''), 'Inner'), 'nested layout table lowering preserves content without an HTML fallback');
$nestedLayoutTableSource = '<table class="layout"><tbody><tr><td style="width:30%"><a href="/quote"><img src="quote.jpg" alt="Quote"></a></td><td style="width:70%"><table><tbody><tr><td style="width:20%"><img src="mark.jpg" alt="Mark"></td><td style="width:80%"><p>Layout copy</p></td></tr></tbody></table></td></tr></tbody></table>';
$nestedLayoutTableResult = ( new HtmlTransformer() )->transform($nestedLayoutTableSource)->toArray();
$nestedLayoutTableMarkup = (string) ($nestedLayoutTableResult['serialized_blocks'] ?? '');
$nestedLayoutTableLinkedMedia = $nestedLayoutTableResult['blocks'][0]['innerBlocks'][0]['innerBlocks'][0] ?? array();
$assert(TableClassificationPolicy::COMPLEX_NESTED === ($tablePolicy->classify($tableElement($nestedLayoutTableSource))['classification'] ?? null) && $tablePolicy->isNestedLayoutTable($tableElement($nestedLayoutTableSource)), 'nested single-row headerless tables are recognized as layout columns');
$assert('core/columns' === ($nestedLayoutTableResult['blocks'][0]['blockName'] ?? null) && 2 === count($nestedLayoutTableResult['blocks'][0]['innerBlocks'] ?? array()) && 'core/columns' === ($nestedLayoutTableResult['blocks'][0]['innerBlocks'][1]['innerBlocks'][0]['blockName'] ?? null), 'nested layout tables lower to responsive native column blocks');
$assert('30%' === ($nestedLayoutTableResult['blocks'][0]['innerBlocks'][0]['attrs']['width'] ?? null) && '70%' === ($nestedLayoutTableResult['blocks'][0]['innerBlocks'][1]['attrs']['width'] ?? null) && str_contains((string) ($nestedLayoutTableResult['serialized_blocks'] ?? ''), 'flex-basis:30%') && str_contains((string) ($nestedLayoutTableResult['serialized_blocks'] ?? ''), 'flex-basis:70%'), 'layout table cell percentages become rendered core/column widths');

$percentLayoutTable = ( new HtmlTransformer() )->transform('<table class="wsite-multicol-table"><tr><td style="width:18.5%">Left</td><td style="width:63%">Center</td><td style="width:18.5%">Right</td></tr></table>')->toArray();
$percentLayoutTableBlock = $percentLayoutTable['blocks'][0] ?? array();
$assert('core/columns' === ($percentLayoutTableBlock['blockName'] ?? null) && 3 === count($percentLayoutTableBlock['innerBlocks'] ?? array()), 'percent-width layout tables become core/columns');
$assert('18.5%' === ($percentLayoutTableBlock['innerBlocks'][0]['attrs']['width'] ?? null) && '63%' === ($percentLayoutTableBlock['innerBlocks'][1]['attrs']['width'] ?? null) && '18.5%' === ($percentLayoutTableBlock['innerBlocks'][2]['attrs']['width'] ?? null), 'percent-width layout tables preserve cell percentages as column widths');
$percentLayoutTableCss = implode("\n", array_column($percentLayoutTable['assets'] ?? array(), 'content'));
$assert(str_contains((string) ($percentLayoutTable['serialized_blocks'] ?? ''), 'blocks-engine-layout-table-columns') && str_contains($percentLayoutTableCss, '.wp-block-columns.blocks-engine-layout-table-columns{gap:0}'), 'layout-table columns suppress the core default gap because source cells own their gutters');
$assert('core/table' === (( new HtmlTransformer() )->transform('<table><tr><td>A</td><td>B</td></tr></table>')->toArray()['blocks'][0]['blockName'] ?? null), 'headerless tables without cell percentages remain data tables');
$assert(! str_contains($nestedLayoutTableMarkup, '<!-- wp:html') && 'custom/responsive-media' === ($nestedLayoutTableLinkedMedia['blockName'] ?? null) && str_contains((string) ($nestedLayoutTableLinkedMedia['attrs']['content'] ?? ''), 'href="/quote"') && str_contains((string) ($nestedLayoutTableLinkedMedia['attrs']['content'] ?? ''), 'src="quote.jpg"') && str_contains($nestedLayoutTableMarkup, 'src="mark.jpg"') && str_contains($nestedLayoutTableMarkup, 'Layout copy'), 'nested layout table lowering preserves links, media, and content order without HTML fallback');
$assert('pass' === ($nestedLayoutTableResult['source_reports']['wp_block_validity']['status'] ?? null), 'nested layout table columns remain Gutenberg-valid');
$nestedDataTableResult = ( new HtmlTransformer() )->transform('<table><tr><td><table><thead><tr><th>Name</th></tr></thead><tbody><tr><td>Ada</td></tr></tbody></table></td></tr></table>')->toArray();
$assert('core/html' === ($nestedDataTableResult['blocks'][0]['blockName'] ?? null), 'nested data tables retain conservative HTML fallback');
$colspanTableResult = ( new HtmlTransformer() )->transform('<table><tr><td colspan="2">Merged</td></tr><tr><td>A</td><td>B</td></tr></table>')->toArray();
$assert('core/html' === ($colspanTableResult['blocks'][0]['blockName'] ?? null), 'colspan table falls back to core/html');
$rowspanTableResult = ( new HtmlTransformer() )->transform('<table><tr><td rowspan="2">Merged</td><td>A</td></tr><tr><td>B</td></tr></table>')->toArray();
$assert('core/html' === ($rowspanTableResult['blocks'][0]['blockName'] ?? null), 'rowspan table falls back to core/html');

$metadataDefinitionList = ( new HtmlTransformer() )->transform(
    '<style>.facts{display:grid;grid-template-columns:8rem 1fr;gap:8px 18px}</style><dl class="facts"><dt>Office</dt><dd>North Hall</dd><dt>Hours</dt><dd>Weekdays</dd></dl>'
)->toArray();
$metadataDefinitionListMarkup = (string) ($metadataDefinitionList['serialized_blocks'] ?? '');
$metadataDefinitionListBlock = $metadataDefinitionList['blocks'][0] ?? array();
$assert('blocks-engine/description-list' === ($metadataDefinitionListBlock['blockName'] ?? null), 'direct definition lists use the semantic companion block');
$assert(str_contains($metadataDefinitionListMarkup, '<dl class="facts"><dt>Office</dt><dd>North Hall</dd>'), 'definition-list markup retains source dl, dt, and dd semantics');
$assert('pass' === ($metadataDefinitionList['source_reports']['wp_block_validity']['status'] ?? ''), 'description-list block emits editor-valid static markup');

$repeatedMetadataRows = ( new HtmlTransformer() )->transform(
    '<style>.record{display:grid;grid-template-columns:7rem 1fr;gap:6px 12px}</style><section><div class="record"><strong>Role</strong><span>Coordinator</span></div><div class="record"><strong>Location</strong><span>Remote</span></div></section>'
)->toArray();
$repeatedMetadataMarkup = (string) ($repeatedMetadataRows['serialized_blocks'] ?? '');
$assert(str_contains($repeatedMetadataMarkup, 'record') && ! str_contains($repeatedMetadataMarkup, 'is-layout-grid'), 'repeated visually labelled rows preserve their stylesheet-owned grids');
$assert(! str_contains($repeatedMetadataMarkup, '<strong>Role</strong> Coordinator'), 'repeated metadata rows do not flatten labels and values into prose');

$ordinaryDefinitionList = ( new HtmlTransformer() )->transform('<dl><dt>First topic</dt><dd>A full explanatory paragraph.</dd><dt>Second topic</dt><dd>Another explanatory paragraph.</dd></dl>')->toArray();
$assert('blocks-engine/description-list' === ($ordinaryDefinitionList['blocks'][0]['blockName'] ?? null), 'ordinary direct definition lists retain semantic markup');
$ordinaryProseRows = ( new HtmlTransformer() )->transform('<section><div style="display:grid;grid-template-columns:1fr 1fr"><p>First paragraph.</p><p>Second paragraph.</p></div><div style="display:grid;grid-template-columns:1fr 1fr"><p>Third paragraph.</p><p>Fourth paragraph.</p></div></section>')->toArray();
$assert(0 === substr_count((string) ($ordinaryProseRows['serialized_blocks'] ?? ''), 'margin-top:0;margin-bottom:0'), 'ordinary grid prose is not misclassified as metadata rows');
$horizontalFlexDefinitionList = ( new HtmlTransformer() )->transform('<style>.terms{display:flex;flex-direction:row;gap:1rem}</style><dl class="terms"><dt>One</dt><dd>First</dd><dt>Two</dt><dd>Second</dd></dl>')->toArray();
$assert('blocks-engine/description-list' === ($horizontalFlexDefinitionList['blocks'][0]['blockName'] ?? null), 'direct flex definition lists retain semantic markup');
$wrappingFlexDefinitionList = ( new HtmlTransformer() )->transform('<style>.terms{display:flex;flex-wrap:wrap;column-gap:18px;row-gap:8px}</style><dl class="terms"><dt>One</dt><dd>First</dd><dt>Two</dt><dd>Second</dd></dl>')->toArray();
$wrappingFlexMarkup = (string) ($wrappingFlexDefinitionList['serialized_blocks'] ?? '');
$assert('blocks-engine/description-list' === ($wrappingFlexDefinitionList['blocks'][0]['blockName'] ?? null), 'wrapping direct definition lists retain semantic markup');
$assert(str_contains($wrappingFlexMarkup, '<dl class="terms">') && ! str_contains($wrappingFlexMarkup, 'is-layout-flex'), 'wrapping definition lists preserve stylesheet classes without Gutenberg layout classes');

$navigationResult = ( new HtmlTransformer() )->transform('<nav class="primary"><a href="/about">About</a><a href="/contact">Contact</a></nav>')->toArray();
$navigationBlock = $navigationResult['blocks'][0] ?? array();
$assert('core/navigation' === ($navigationBlock['blockName'] ?? null), 'navigation conversion still emits a navigation block');
$assert(2 === count($navigationBlock['innerBlocks'] ?? array()), 'navigation conversion still preserves direct navigation links');
$assert('About' === ($navigationBlock['innerBlocks'][0]['attrs']['label'] ?? null), 'navigation conversion still preserves link labels');
$assert('/about' === ($navigationBlock['innerBlocks'][0]['attrs']['url'] ?? null), 'navigation conversion still preserves link URLs');

$socialLinksResult = ( new HtmlTransformer() )->transform('<style>.social-links .social-item{display:inline-block;width:22px;height:22px;margin:0 11px 0 0}</style><ul class="social-links"><li class="social-item"><a href="https://github.com/Automattic" aria-label="GitHub"><svg width="22" height="22" aria-hidden="true"></svg></a></li><li class="social-item"><a href="https://www.instagram.com/wordpress/" title="Instagram"><svg width="22" height="22" aria-hidden="true"></svg></a></li></ul>')->toArray();
$socialLinksBlock = $socialLinksResult['blocks'][0] ?? array();
$assert('core/social-links' === ($socialLinksBlock['blockName'] ?? null), 'explicit social profile clusters convert to core/social-links instead of generic navigation');
$assert('github' === ($socialLinksBlock['innerBlocks'][0]['attrs']['service'] ?? null) && 'instagram' === ($socialLinksBlock['innerBlocks'][1]['attrs']['service'] ?? null), 'social profile hosts map to WordPress social-link service semantics');
$assert('GitHub' === ($socialLinksBlock['innerBlocks'][0]['attrs']['label'] ?? null) && 'Instagram' === ($socialLinksBlock['innerBlocks'][1]['attrs']['label'] ?? null), 'icon-only social links retain accessible profile labels');
$socialLinksMarkup = (string) ($socialLinksResult['serialized_blocks'] ?? '');
$assert(str_contains((string) ($socialLinksBlock['className'] ?? $socialLinksBlock['attrs']['className'] ?? ''), 'is-style-logos-only'), 'image-backed social clusters use core logos-only presentation instead of adding provider backgrounds');
$assert('normal' === ($socialLinksBlock['attrs']['size'] ?? null) && str_contains($socialLinksMarkup, 'normal has-normal-icon-size'), 'explicit source icon dimensions select the nearest core Social Links size preset');
$assert('social-item' === ($socialLinksBlock['innerBlocks'][0]['attrs']['className'] ?? null), 'social-link children retain their structural item class where core renders it');
$assert(str_contains((string) ($socialLinksBlock['attrs']['className'] ?? ''), 'blocks-engine-source-social-item-spacing'), 'structural social items mark the wrapper so source item spacing remains authoritative');
$socialLinksCss = implode("\n", array_column($socialLinksResult['assets'] ?? array(), 'content'));
$assert(str_contains($socialLinksCss, '.wp-block-social-links.blocks-engine-source-social-item-spacing{gap:0}'), 'engine support CSS neutralizes the core default gap without adding invalid saved styles');
$assert(str_contains($socialLinksCss, '.wp-block-social-links.is-style-logos-only .wp-social-link{background-image:none;background-color:transparent}'), 'logos-only social links drop source sprite backgrounds without !important');
$assert(! str_contains($socialLinksMarkup, 'style="gap:') && ! str_contains($socialLinksMarkup, '<li ') && ! str_contains($socialLinksMarkup, '<a href='), 'social-link children preserve their dynamic empty-save contract inside the canonical social-links wrapper');
$assert('pass' === ($socialLinksResult['source_reports']['wp_block_validity']['status'] ?? ''), 'dynamic social-link children and their static parent remain WordPress-valid');

$visibleSocialLabels = ( new HtmlTransformer() )->transform('<div class="social-links"><a href="https://github.com/Automattic/blocks-engine">Blocks Engine</a><a href="https://github.com/Automattic/static-site-importer">Static Site Importer</a></div>')->toArray();
$assert(str_contains((string) ($visibleSocialLabels['serialized_blocks'] ?? ''), 'class="wp-block-social-links has-visible-labels social-links"'), 'social-links save markup carries the canonical has-visible-labels class when labels are shown');

$spanSocialSource = '<span class="wsite-social wsite-social-default"><a class="wsite-social-item wsite-social-facebook" href="https://www.facebook.com/tasteandtravelitaly" aria-label="Facebook"><span class="wsite-social-item-inner"></span></a><a class="wsite-social-item wsite-social-twitter" href="//#" aria-label="Twitter"><span class="wsite-social-item-inner"></span></a><a class="wsite-social-item wsite-social-instagram" href="https://instagram.com/tasteandtravel_italy" aria-label="Instagram"><span class="wsite-social-item-inner"></span></a><a class="wsite-social-item wsite-social-mail" href="mailto:hello@example.com" aria-label="Mail"><span class="wsite-social-item-inner"></span></a></span>';
$spanSocialResult = ( new HtmlTransformer() )->transform($spanSocialSource)->toArray();
$spanSocialBlock = $spanSocialResult['blocks'][0] ?? array();
$spanSocialServices = array_map(static fn(array $link): string => (string) ($link['attrs']['service'] ?? ''), $spanSocialBlock['innerBlocks'] ?? array());
$assert('core/social-links' === ($spanSocialBlock['blockName'] ?? null), 'inline social clusters convert to core/social-links instead of empty mark hooks');
$assert(array( 'facebook', 'twitter', 'instagram', 'mail' ) === $spanSocialServices, 'explicit labeled social placeholders infer their service while mailto maps to mail', json_encode($spanSocialServices));
$assert(str_contains((string) ($spanSocialBlock['attrs']['className'] ?? ''), 'is-style-logos-only'), 'empty generated-content inners count as icon-only social presentation');
$assert('small' === ($spanSocialBlock['attrs']['size'] ?? null), 'icon-font social clusters default to compact core size when source icons are not measured bitmaps');
$assert(! str_contains((string) ($spanSocialResult['serialized_blocks'] ?? ''), '<mark'), 'icon-font inner spans are not lowered to mark');

$placeholderSocialSource = '<style>.footer-social{display:flex;gap:14px}.footer-social a{display:inline-flex;width:32px;height:32px}</style><div class="footer-social"><a href="#" aria-label="LinkedIn"><svg width="14" height="14" aria-hidden="true"><path d="M0 0h1v1z"/></svg></a><a href="#" aria-label="X / Twitter"><svg width="14" height="14" aria-hidden="true"><path d="M0 0h1v1z"/></svg></a><a href="#" aria-label="YouTube"><svg width="14" height="14" aria-hidden="true"><path d="M0 0h1v1z"/></svg></a><a href="#" aria-label="GitHub"><svg width="14" height="14" aria-hidden="true"><path d="M0 0h1v1z"/></svg></a></div>';
$placeholderSocialResult = ( new HtmlTransformer() )->transform($placeholderSocialSource)->toArray();
$placeholderSocialBlock = $placeholderSocialResult['blocks'][0] ?? array();
$placeholderSocialServices = array_map(static fn(array $link): string => (string) ($link['attrs']['service'] ?? ''), $placeholderSocialBlock['innerBlocks'] ?? array());
$placeholderSocialUrls = array_map(static fn(array $link): string => (string) ($link['attrs']['url'] ?? ''), $placeholderSocialBlock['innerBlocks'] ?? array());
$placeholderSocialLabels = array_map(static fn(array $link): string => (string) ($link['attrs']['label'] ?? ''), $placeholderSocialBlock['innerBlocks'] ?? array());
$assert('core/social-links' === ($placeholderSocialBlock['blockName'] ?? null), 'explicit labeled social placeholders convert to core/social-links');
$assert(array( 'linkedin', 'x', 'youtube', 'github' ) === $placeholderSocialServices, 'social placeholder services infer from accessible labels', json_encode($placeholderSocialServices));
$assert(array( '#', '#', '#', '#' ) === $placeholderSocialUrls, 'social placeholder URLs survive unchanged', json_encode($placeholderSocialUrls));
$assert(array( 'LinkedIn', 'X / Twitter', 'YouTube', 'GitHub' ) === $placeholderSocialLabels, 'social placeholder accessible labels survive', json_encode($placeholderSocialLabels));
$assert(str_contains((string) ($placeholderSocialBlock['attrs']['className'] ?? ''), 'is-style-logos-only'), 'labeled SVG placeholders retain logos-only presentation');

$unknownPlaceholderSocial = ( new HtmlTransformer() )->transform('<div class="footer-social"><a href="#" aria-label="Community"><svg aria-hidden="true"></svg></a></div>')->toArray();
$assert('core/social-links' !== ($unknownPlaceholderSocial['blocks'][0]['blockName'] ?? null), 'unknown placeholder labels do not fabricate social services');

$ordinaryFooterLinks = ( new HtmlTransformer() )->transform('<nav aria-label="Company"><a href="/about">About</a><a href="/contact">Contact</a></nav>')->toArray();
$assert('core/navigation' === ($ordinaryFooterLinks['blocks'][0]['blockName'] ?? null), 'ordinary navigation does not become social links without profile-host or social-cluster semantics');

// core/navigation emits its own item markup, so a builder wrapper that carried
// the menu's type and colour does not survive and its rule matches nothing.
// Recover that presentation onto the native counterpart, and deliver it as CSS:
// shell identity compares block markup across documents, so a per-page value in
// that markup would split one shared template part into several.
$navInherited = ( new HtmlTransformer() )->transform(
    '<style>body{font-family:Arial;font-size:10px;color:#000}.labelBox{color:rgb(238,255,255);font-family:helvetica-w01-roman;font-size:15.75px}</style>'
    . '<body><header><nav class="menu navbar" aria-label="Main"><ul>'
    . '<li><div class="labelBox"><a href="/features">Features</a></div></li>'
    . '<li><div class="labelBox"><a href="/benefits">Benefits</a></div></li>'
    . '</ul></nav></header></body>'
)->toArray();
$navInheritedCss = implode("\n", array_column($navInherited['assets'] ?? array(), 'content'));
$navInheritedMarkup = (string) ($navInherited['serialized_blocks'] ?? '');
$assert(str_contains($navInheritedCss, '.wp-block-navigation.menu.navbar .wp-block-navigation-item__content{color:rgb(238,255,255);font-family:helvetica-w01-roman;font-size:15.75px}'), 'menu presentation on a replaced source wrapper is recovered onto the native navigation item');
$assert(! str_contains($navInheritedCss, '.wp-block-navigation.menu.navbar .wp-block-navigation-item__content{color:#000') && ! str_contains($navInheritedCss, 'font-family:Arial;font-size:10px}'), 'recovery reads the source menu rather than the document default that surrounds it');
preg_match('/<!--\s*wp:navigation\s*(\{.*?\})\s*-->/s', $navInheritedMarkup, $navInheritedAttrs);
$navInheritedBlock = $navInheritedAttrs[1] ?? '';
$assert('' !== $navInheritedBlock && ! str_contains($navInheritedBlock, 'customTextColor') && ! str_contains($navInheritedBlock, 'helvetica-w01-roman'), 'recovered navigation presentation stays out of the navigation block so documents sharing a shell keep identical markup: ' . $navInheritedBlock);
$navNoInheritance = ( new HtmlTransformer() )->transform(
    '<header><nav class="plain-nav" aria-label="Main"><ul><li><a href="/a">A</a></li><li><a href="/b">B</a></li></ul></nav></header>'
)->toArray();
$assert(! str_contains(implode("\n", array_column($navNoInheritance['assets'] ?? array(), 'content')), '.wp-block-navigation.plain-nav '), 'a menu with no distinct presentation gets no fabricated rule');

// A source nav landmark keeps native menu semantics, so its icon-only anchors
// must not silently lose the artwork core/navigation-link cannot save.
$navIconResult = ( new HtmlTransformer() )->transform(
    '<style>.social-nav a svg{width:23px;height:23px}</style><nav class="social-nav" aria-label="Social"><a href="https://www.facebook.com/wix" aria-label="Facebook"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M0 0h24v24H0z"></path></svg></a><a href="https://x.com/wix" aria-label="Twitter"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle></svg></a></nav>'
)->toArray();
$navIconMarkup = (string) ($navIconResult['serialized_blocks'] ?? '');
$navIconCss = implode("\n", array_column($navIconResult['assets'] ?? array(), 'content'));
$assert('core/navigation' === ($navIconResult['blocks'][0]['blockName'] ?? null), 'icon-only anchors inside a nav landmark stay native navigation');
$assert(str_contains($navIconMarkup, '"label":"Facebook"') && str_contains($navIconMarkup, '"label":"Twitter"'), 'icon-only navigation links keep their accessible name as the saved label');
$assert(1 === preg_match('/blocks-engine-navigation-link-icon-[a-f0-9]{12}/', $navIconMarkup), 'icon-only navigation links carry an opaque icon marker');
$assert(str_contains($navIconCss, 'background-image:url("data:image/svg+xml,') && str_contains($navIconCss, 'width:23px;height:23px'), 'recovered navigation icons project the source artwork at its source box');
$assert(str_contains($navIconCss, 'font-size:0') && ! str_contains($navIconCss, 'visibility:hidden;background-image'), 'recovered navigation icons collapse the label without removing it from the accessibility tree');
$navTextLinks = ( new HtmlTransformer() )->transform(
    '<nav class="main-nav" aria-label="Main"><a href="/work">Work</a><a href="/about">About</a></nav>'
)->toArray();
$assert(! str_contains((string) ($navTextLinks['serialized_blocks'] ?? ''), 'blocks-engine-navigation-link-icon-'), 'text navigation links do not fabricate icon markers');

// A row of button-styled links whose container merely carries a `links` token is
// a call-to-action button group, not site navigation. It must convert to
// core/buttons (preserving pill geometry) instead of being flattened into a
// core/navigation menu of half-height text links.
$ctaLinkRowResult = ( new HtmlTransformer() )->transform('<style>.stream-btn{display:inline-flex;padding:10px 16px;background:#135e96;color:#fff;border-radius:4px}</style><div class="stream-links"><a class="stream-btn" href="#">Spotify</a><a class="stream-btn" href="#">Bandcamp</a><a class="stream-btn" href="#">Apple Music</a></div>')->toArray();
$ctaSerialized = (string) ($ctaLinkRowResult['serialized_blocks'] ?? '');
$assert(str_contains($ctaSerialized, '<!-- wp:buttons'), 'button-styled link row converts to core/buttons instead of navigation');
$assert(! str_contains($ctaSerialized, '<!-- wp:navigation'), 'button-styled link row is not misclassified as core/navigation');
$assert(str_contains($ctaSerialized, 'stream-links'), 'the converted button group preserves the container class');

$accordionResult = ( new HtmlTransformer() )->transform('<section class="faq"><div class="faq-item active"><button class="faq-question" aria-expanded="true" aria-controls="answer-a">What is covered?</button><div id="answer-a" class="faq-answer"><p>Assessment and treatment planning.</p></div></div><div class="faq-item"><button class="faq-question" aria-expanded="false" aria-controls="answer-b">How long is a visit?</button><div id="answer-b" class="faq-answer"><p>Most visits take 45 minutes.</p></div></div></section>')->toArray();
$accordionBlock = $accordionResult['blocks'][0] ?? array();
$accordionItems = $accordionBlock['innerBlocks'] ?? array();
$assert('core/accordion' === ($accordionBlock['blockName'] ?? null), 'clean FAQ containers convert to core accordion');
$assert(2 === count($accordionItems), 'accordion conversion preserves repeated items');
$assert('core/accordion-item' === ($accordionItems[0]['blockName'] ?? null), 'accordion conversion emits core accordion items');
$assert(true === ($accordionItems[0]['attrs']['openByDefault'] ?? null), 'accordion conversion maps obvious expanded state');
$assert('What is covered?' === ($accordionItems[0]['innerBlocks'][0]['attrs']['title'] ?? null), 'accordion conversion preserves item heading text');
$assert('core/accordion-panel' === ($accordionItems[0]['innerBlocks'][1]['blockName'] ?? null), 'accordion conversion emits core accordion panels');
$assert('Assessment and treatment planning.' === ($accordionItems[0]['innerBlocks'][1]['innerBlocks'][0]['attrs']['content'] ?? null), 'accordion conversion preserves panel text');
$assert(str_contains((string) ($accordionResult['serialized_blocks'] ?? ''), '<!-- wp:accordion '), 'accordion conversion serializes native accordion block comments');

$decorativeAccordionResult = ( new HtmlTransformer() )->transform('<section class="faq"><div class="faq-item"><button aria-controls="answer-a"><svg aria-hidden="true"></svg><span aria-hidden="true">Question:</span> What is covered?</button><div id="answer-a"><p>Assessment and treatment planning.</p></div></div><div class="faq-item"><button aria-controls="answer-b">How long is a visit?</button><div id="answer-b"><p>Most visits take 45 minutes.</p></div></div></section>')->toArray();
$assert('What is covered?' === ($decorativeAccordionResult['blocks'][0]['innerBlocks'][0]['innerBlocks'][0]['attrs']['title'] ?? null), 'accordion labels omit decorative SVG and hidden text');

$complexAccordionResult = ( new HtmlTransformer() )->transform('<section class="faq"><div class="faq-item"><button aria-controls="a">Question A</button><div id="a"><script src="accordion.js"></script><p>Answer A</p></div></div><div class="faq-item"><button aria-controls="b">Question B</button><div id="b"><p>Answer B</p></div></div></section>')->toArray();
$assert('core/accordion' !== (($complexAccordionResult['blocks'][0] ?? array())['blockName'] ?? null), 'runtime-heavy accordion markup is not forced into native accordion');

$detailsAccordionResult = ( new HtmlTransformer() )->transform('<div class="accordion"><details open><summary>Can I reschedule?</summary><p>Yes, with notice.</p></details><details><summary>Do you take cards?</summary><p>Yes.</p></details></div>')->toArray();
$detailsAccordionItems = $detailsAccordionResult['blocks'][0]['innerBlocks'] ?? array();
$assert('core/accordion' === (($detailsAccordionResult['blocks'][0] ?? array())['blockName'] ?? null), 'repeated details inside accordion wrappers convert to core accordion');
$assert(true === ($detailsAccordionItems[0]['attrs']['openByDefault'] ?? null), 'details open state maps to accordion item open state');
$assert('Can I reschedule?' === ($detailsAccordionItems[0]['innerBlocks'][0]['attrs']['title'] ?? null), 'details summary text maps to accordion heading');
$assert('Yes, with notice.' === ($detailsAccordionItems[0]['innerBlocks'][1]['innerBlocks'][0]['attrs']['content'] ?? null), 'details body text maps to accordion panel');

$openDetailsResult = ( new HtmlTransformer() )->transform('<details open><summary>Open summary</summary><p>Open content.</p></details>')->toArray();
$openDetailsBlock = $openDetailsResult['blocks'][0] ?? array();
$openDetailsMarkup = (string) ($openDetailsResult['serialized_blocks'] ?? '');
$assert('core/details' === ($openDetailsBlock['blockName'] ?? null), 'open native details converts to core/details');
$assert(true === ($openDetailsBlock['attrs']['showContent'] ?? null), 'open native details maps to the core/details showContent attribute');
$assert(str_contains($openDetailsMarkup, '<details class="wp-block-details" open><summary>Open summary</summary>'), 'open native details serializes the frontend open attribute before its summary');
$assert(strpos($openDetailsMarkup, '<summary>Open summary</summary>') < strpos($openDetailsMarkup, '<p>Open content.</p>'), 'open native details preserves summary before content through final serialization');
$assert('pass' === ($openDetailsResult['source_reports']['wp_block_validity']['status'] ?? ''), 'open native details serialization remains Gutenberg-valid');

$closedDetailsResult = ( new HtmlTransformer() )->transform('<details><summary>Closed summary</summary><p>Closed content.</p></details>')->toArray();
$closedDetailsBlock = $closedDetailsResult['blocks'][0] ?? array();
$closedDetailsMarkup = (string) ($closedDetailsResult['serialized_blocks'] ?? '');
$assert('core/details' === ($closedDetailsBlock['blockName'] ?? null), 'closed native details converts to core/details');
$assert(false === ($closedDetailsBlock['attrs']['showContent'] ?? false), 'closed native details keeps the core/details default closed state');
$assert(str_contains($closedDetailsMarkup, '<details class="wp-block-details"><summary>Closed summary</summary>'), 'closed native details serializes without the frontend open attribute');
$assert(strpos($closedDetailsMarkup, '<summary>Closed summary</summary>') < strpos($closedDetailsMarkup, '<p>Closed content.</p>'), 'closed native details preserves summary before content through final serialization');
$assert('pass' === ($closedDetailsResult['source_reports']['wp_block_validity']['status'] ?? ''), 'closed native details serialization remains Gutenberg-valid');

// A visually empty native summary is capture scaffolding, not an editor-visible
// disclosure trigger. Keep adjacent prose editable while lowering the bounded
// dialog separately so core/details cannot add its default closed-state height.
$capturedDisclosureResult = ( new HtmlTransformer() )->transform('<div class="rich-text"><p>Copyright text</p><details class="dla-disclosure"><summary>&nbsp;</summary><div class="dla-dialog" role="dialog"><nav><a href="/about">About</a><a href="/contact">Contact</a></nav></div></details></div>')->toArray();
$capturedDisclosureRoot = $capturedDisclosureResult['blocks'][0] ?? array();
$capturedDisclosureChildren = $capturedDisclosureRoot['innerBlocks'] ?? array();
$capturedDisclosureDialog = $capturedDisclosureChildren[1] ?? array();
$capturedDisclosureMarkup = (string) ($capturedDisclosureResult['serialized_blocks'] ?? '');
$assert('core/group' === ($capturedDisclosureRoot['blockName'] ?? null) && 'core/paragraph' === (($capturedDisclosureChildren[0] ?? array())['blockName'] ?? null) && 'Copyright text' === (($capturedDisclosureChildren[0]['attrs']['content'] ?? null)), 'mixed rich text keeps ordinary sibling prose editable when an empty-summary disclosure is present');
$assert(str_ends_with((string) ($capturedDisclosureDialog['blockName'] ?? ''), '/captured-dialog') && 'core/navigation' === (($capturedDisclosureDialog['innerBlocks'][0] ?? array())['blockName'] ?? null), 'bounded empty-summary dialog disclosures lower to the typed dialog block with native navigation children');
$assert(! str_contains($capturedDisclosureMarkup, '<!-- wp:details') && ! str_contains($capturedDisclosureMarkup, '/collection') && str_contains($capturedDisclosureMarkup, '<dialog class="dla-dialog">'), 'empty-summary dialog disclosures avoid both details trigger geometry and collection fallback');

$unsafeCapturedDisclosureResult = ( new HtmlTransformer() )->transform('<div class="rich-text"><p>Copyright text</p><details><summary>&nbsp;</summary><div role="dialog"><script>window.open()</script><nav><a href="/about">About</a></nav></div></details></div>')->toArray();
$unsafeCapturedDisclosureMarkup = (string) ($unsafeCapturedDisclosureResult['serialized_blocks'] ?? '');
$assert(! str_contains($unsafeCapturedDisclosureMarkup, '/captured-dialog') && str_contains($unsafeCapturedDisclosureMarkup, 'Copyright text'), 'runtime-heavy empty-summary dialogs fail closed without swallowing adjacent editable prose');

// A single disclosure widget (toggle control + collapsible region) carries no
// faq/accordion class, only the structural WAI-ARIA disclosure shape, and is
// converted to a native zero-JS core/details block instead of leaking a dead
// toggle button and an always-visible panel.
$disclosureResult = ( new HtmlTransformer() )->transform('<div><button aria-expanded="false" aria-controls="answer-1">What is your refund policy?</button><div id="answer-1" hidden><p>Full refund within 30 days.</p></div></div>')->toArray();
$disclosureBlock = $disclosureResult['blocks'][0] ?? array();
$assert('core/details' === ($disclosureBlock['blockName'] ?? null), 'a single aria disclosure widget converts to core/details');
$assert('What is your refund policy?' === ($disclosureBlock['attrs']['summary'] ?? null), 'disclosure toggle text maps to the details summary');
$assert('Full refund within 30 days.' === ($disclosureBlock['innerBlocks'][0]['attrs']['content'] ?? null), 'disclosure panel content is preserved inside core/details');
$assert(str_contains((string) ($disclosureResult['serialized_blocks'] ?? ''), '<!-- wp:details'), 'disclosure conversion serializes a native details block comment');

$decorativeDisclosureResult = ( new HtmlTransformer() )->transform('<div><button aria-expanded="false" aria-controls="answer-2"><svg aria-hidden="true"></svg><span aria-hidden="true">Question:</span> What is your refund policy?</button><div id="answer-2"><p>Full refund within 30 days.</p></div></div>')->toArray();
$assert('What is your refund policy?' === ($decorativeDisclosureResult['blocks'][0]['attrs']['summary'] ?? null), 'disclosure labels omit decorative SVG and hidden text');

// A heading-wrapped toggle (button nested inside the header) is recognized by
// the same structural signal.
$headingDisclosureResult = ( new HtmlTransformer() )->transform('<div class="item"><h3><button aria-expanded="false" aria-controls="panel-1">Shipping times?</button></h3><div id="panel-1" role="region"><p>Ships in 2 days.</p></div></div>')->toArray();
$assert('core/details' === (($headingDisclosureResult['blocks'][0] ?? array())['blockName'] ?? null), 'a heading-wrapped disclosure toggle converts to core/details');
$assert('Shipping times?' === (($headingDisclosureResult['blocks'][0] ?? array())['attrs']['summary'] ?? null), 'heading-wrapped disclosure toggle text maps to the details summary');

// A nested navigation toggle cannot consume its page-container ancestor as a
// disclosure header and discard the sibling page content.
$pageShellDisclosureResult = ( new HtmlTransformer() )->transform('<div id="master-page"><div id="site-pages"><header><button aria-expanded="false">Open site navigation</button></header><main><h1>Portfolio heading</h1><p>Substantive page content.</p><img src="portrait.jpg" alt="Portrait"></main></div><div class="navigation-overlay"><nav><a href="/about">About</a></nav></div></div>')->toArray();
$pageShellDisclosureMarkup = (string) ($pageShellDisclosureResult['serialized_blocks'] ?? '');
$assert(! str_contains($pageShellDisclosureMarkup, '<!-- wp:details'), 'a page shell with a nested navigation toggle is not converted to core/details');
$assert(str_contains($pageShellDisclosureMarkup, 'Portfolio heading'), 'a false disclosure preserves the substantive page heading');
$assert(str_contains($pageShellDisclosureMarkup, 'Substantive page content.'), 'a false disclosure preserves the substantive page paragraph');
$assert(str_contains($pageShellDisclosureMarkup, '<!-- wp:image'), 'a false disclosure preserves the substantive page image');

// Negative guard: a plain heading followed by text is NOT a disclosure (no
// toggle control, aria-expanded, or aria-controls) and must stay as a heading +
// paragraph rather than being forced into core/details.
$plainResult = ( new HtmlTransformer() )->transform('<div><h3>About us</h3><p>We are a company.</p></div>')->toArray();
$plainBlock = $plainResult['blocks'][0] ?? array();
$assert('core/details' !== ($plainBlock['blockName'] ?? null), 'a plain heading followed by text is not converted to core/details');
$plainInner = $plainBlock['innerBlocks'] ?? array();
$assert('core/heading' === ($plainInner[0]['blockName'] ?? null), 'plain heading remains a core/heading');
$assert('core/paragraph' === ($plainInner[1]['blockName'] ?? null), 'plain body text remains a core/paragraph');

$fixture = file_get_contents(dirname(__DIR__) . '/fixtures/simple-html.html');
$result  = ( new HtmlTransformer() )->transform($fixture . "\n<ul><li>One</li><li><strong>Two</strong></li></ul><canvas>Fallback</canvas>")->toArray();

$assert(TransformerResult::SCHEMA === $result['schema'], 'result exposes schema');
TransformerResult::assertCanonicalEnvelope($result);

foreach ( array( 'status', 'components', 'block_types', 'source_reports', 'blocks', 'serialized_blocks', 'documents', 'assets', 'diagnostics', 'fallbacks', 'provenance', 'coverage', 'context', 'metrics' ) as $key ) {
    $assert(array_key_exists($key, $result), "Missing result key: {$key}");
}
$assert(! array_key_exists('legacy_mapping', $result), 'canonical result omits compatibility-only legacy mapping');
$assertInvalidCanonicalEnvelope(array_merge($result, array('legacy_mapping' => array())), 'legacy_mapping', 'canonical validation rejects legacy mapping aliases');
$assertInvalidCanonicalEnvelope(array_merge($result, array('conversion_report' => $result['source_reports']['conversion_report'])), 'only under source_reports', 'canonical validation rejects top-level conversion report aliases');
$assertInvalidCanonicalEnvelope(array_merge($result, array('materialization_plan' => array())), 'only under source_reports', 'canonical validation rejects top-level materialization plan aliases');
$coverage = $result['coverage'][0] ?? array();
$supportedBlocks = $coverage['supported_blocks'] ?? array();
$runtimeAvailableBlocks = $coverage['runtime_available_blocks'] ?? array();
$capabilityMatrix = $coverage['capability_matrix'] ?? array();
$conversionReportNativeTargetBlocks = $result['source_reports']['conversion_report']['native_target_blocks'] ?? array();
$assert(in_array('core/paragraph', $supportedBlocks, true), 'coverage derives implemented, contract-tested block support from the capability matrix');
$assert(in_array('core/accordion', $runtimeAvailableBlocks, true), 'coverage exposes core/accordion as runtime availability rather than transformer support');
$assert(in_array('core/icon', $runtimeAvailableBlocks, true), 'coverage exposes core/icon as runtime availability');
$assert(in_array('core/math', $runtimeAvailableBlocks, true), 'coverage exposes core/math as runtime availability');
$assert('implemented' === ($capabilityMatrix['blocks']['core/accordion']['implementation'] ?? null) && 'contract_tested' === ($capabilityMatrix['blocks']['core/accordion']['verification'] ?? null), 'coverage derives native accordion support from its emitter contract');
$assert('7.1' === ($capabilityMatrix['blocks']['core/tabs']['minimum_runtime'] ?? null), 'matrix records the WordPress 7.1 Tabs runtime gate');
$assert($runtimeAvailableBlocks === ($capabilityMatrix['runtime_available_blocks'] ?? array()), 'coverage records runtime availability separately inside the matrix');
$assert($runtimeAvailableBlocks === $conversionReportNativeTargetBlocks, 'conversion report exposes runtime availability metadata');
$assert(in_array('core/accordion', $supportedBlocks, true), 'coverage reports the emitted native accordion family as converted support');
$assert(! in_array('core/icon', $supportedBlocks, true), 'coverage does not report runtime-only core/icon as transformer output');
$runtimeCanvasResult = ( new HtmlTransformer() )->transform('<main><canvas id="fixture-canvas">Fallback</canvas></main>', array('runtime_canvas_selectors' => array('#fixture-canvas')))->toArray();
$assert('canvas' === ($runtimeCanvasResult['source_reports']['runtime_islands'][0]['kind'] ?? ''), 'HTML transform reports runtime-targeted canvas fallback as a runtime island');
$assert('canvas_requires_runtime' === ($runtimeCanvasResult['source_reports']['runtime_islands'][0]['preservation_reason'] ?? ''), 'runtime island exposes canvas preservation reason');
$assert(str_contains((string) ($runtimeCanvasResult['source_reports']['runtime_islands'][0]['source_snippet'] ?? ''), '<canvas id="fixture-canvas">Fallback</canvas>'), 'runtime island exposes bounded source snippet');
$assert('runtime_canvas' === ($runtimeCanvasResult['source_reports']['runtime_islands'][0]['pattern_family'] ?? ''), 'runtime island exposes generic pattern family metadata');
$assert('1,0,0' === ($runtimeCanvasResult['source_reports']['runtime_islands'][0]['source_selector_specificity']['score'] ?? ''), 'runtime island exposes source selector specificity');
$assert('preserve_runtime_island' === ($runtimeCanvasResult['source_reports']['runtime_islands'][0]['suggested_generic_repair_class'] ?? ''), 'runtime island exposes generic repair class metadata');
$assert($runtimeCanvasResult['source_reports']['runtime_islands'] === ($runtimeCanvasResult['source_reports']['conversion_report']['runtime_islands'] ?? array()), 'conversion report projects runtime islands');

$assert(array() === ($runtimeCanvasResult['fallbacks'] ?? array()), 'runtime-targeted canvas preservation does not emit a fallback warning');
$assert('core/html' === ($runtimeCanvasResult['blocks'][0]['blockName'] ?? null), 'runtime-targeted canvas is materialized as bounded raw HTML');
$assert(str_contains((string) ($runtimeCanvasResult['serialized_blocks'] ?? ''), 'id="fixture-canvas"'), 'runtime-targeted canvas remains addressable in serialized blocks');

$runtimeAppShell = ( new HtmlTransformer() )->transform(
    '<main class="app-shell"><section id="stage"><canvas id="scene"></canvas><button id="run">Run</button><div id="log"></div></section></main>',
    array(
        'runtime_canvas_selectors' => array('#scene'),
        'runtime_dom_selectors'    => array('#scene', '#run', '#log'),
        'runtime_script_metadata'  => array(array('path' => 'app.js')),
    )
)->toArray();
$runtimeAppShellIsland = $runtimeAppShell['source_reports']['runtime_islands'][0] ?? array();
$assert('core/html' === ($runtimeAppShell['blocks'][0]['blockName'] ?? null), 'runtime app shell is preserved as one bounded raw HTML island');
$assert('app_shell' === ($runtimeAppShellIsland['kind'] ?? ''), 'runtime app shell reports a dedicated island kind');
$assert('runtime_app_shell' === ($runtimeAppShellIsland['preservation_reason'] ?? ''), 'runtime app shell reports the app-shell preservation reason');
$assert(3 === ($runtimeAppShellIsland['target_count'] ?? null), 'runtime app shell reports bounded descendant runtime target count');
$assert(in_array('app_root_token', $runtimeAppShellIsland['app_shell_signals'] ?? array(), true), 'runtime app shell reports app-root token evidence');
$assert(str_contains((string) ($runtimeAppShell['serialized_blocks'] ?? ''), '<main class="app-shell">'), 'runtime app shell preserves the source root markup');

$inlineSemanticRuntime = ( new HtmlTransformer() )->transform(
    '<span class="qty-display" aria-live="polite">1</span>',
    array('runtime_dom_selectors' => array('.qty-display'), 'runtime_script_metadata' => array(array('path' => 'app.js')))
)->toArray();
$inlineSemanticIsland = $inlineSemanticRuntime['source_reports']['runtime_islands'][0] ?? array();
$assert('core/html' === ($inlineSemanticRuntime['blocks'][0]['blockName'] ?? null), 'runtime-targeted inline semantic HTML remains a bounded core/html island to preserve attributes');
$assert(str_contains((string) ($inlineSemanticRuntime['serialized_blocks'] ?? ''), 'aria-live="polite"'), 'runtime-targeted inline semantic HTML preserves aria-live in serialized markup');
$assert('inline_semantic_html' === ($inlineSemanticIsland['pattern_family'] ?? ''), 'runtime-targeted inline semantic HTML reports a specific fallback pattern family');
$assert('preserve_runtime_island' === ($inlineSemanticIsland['suggested_generic_repair_class'] ?? ''), 'runtime-targeted inline semantic HTML is classified as an attribute-preserving runtime island, not a generic unsupported span');
$assert('preserved_runtime_island' === ($inlineSemanticIsland['reason_code'] ?? ''), 'runtime-targeted inline semantic HTML keeps the runtime-island reason code');

$runtimeSvgRoot = ( new HtmlTransformer() )->transform(
    '<main><svg id="graph" viewBox="0 0 640 360"></svg></main>',
    array('runtime_dom_selectors' => array('#graph'), 'runtime_script_metadata' => array(array('path' => 'app.js')))
)->toArray();
$runtimeSvgMarkup = (string) ($runtimeSvgRoot['serialized_blocks'] ?? '');
$runtimeSvgIsland = $runtimeSvgRoot['source_reports']['runtime_islands'][0] ?? array();
$assert(str_contains($runtimeSvgMarkup, '<!-- wp:html'), 'runtime-targeted empty SVG root is preserved as a native DOM target');
$assert(str_contains($runtimeSvgMarkup, '<svg id="graph" viewBox="0 0 640 360"></svg>'), 'runtime-targeted empty SVG root preserves id and viewBox casing');
$assert('dom' === ($runtimeSvgIsland['kind'] ?? ''), 'runtime-targeted empty SVG root reports as a DOM runtime island');
$assert(array() === ($runtimeSvgRoot['fallbacks'] ?? array()), 'runtime-targeted empty SVG root does not emit decorative SVG fallback metadata');

$invalidStatus = $result;
$invalidStatus['status'] = 'ok';
$assertInvalidCanonicalEnvelope($invalidStatus, 'unsupported status', 'canonical validation rejects unsupported status values');

$invalidConversionReport = $result;
$invalidConversionReport['source_reports']['conversion_report']['source_format'] = '';
$assertInvalidCanonicalEnvelope($invalidConversionReport, 'source_format', 'canonical validation rejects conversion reports without a source format');

$missingConversionReport = $result;
unset($missingConversionReport['source_reports']['conversion_report']);
$assertInvalidCanonicalEnvelope($missingConversionReport, 'source_reports.conversion_report', 'canonical validation rejects results without conversion reports');

$assertInvalidCanonicalEnvelope($result, 'Materialization-plan validation was removed', 'canonical validation rejects removed materialization-plan requirements', true);

$contextual = ( new HtmlTransformer() )->transform(
    '<main><h1>Context</h1><canvas id="runtime-context">Fallback</canvas></main>',
    array(
        'source'          => 'fixture:contextual-html',
        'source_scope'    => 'contract-test',
        'strict'          => true,
        'allow_fallbacks' => false,
        'runtime_canvas_selectors' => array('#runtime-context'),
    )
)->toArray();
$assert('success' === $contextual['status'], 'strict HTML transform succeeds when runtime-targeted canvas is preserved without fallbacks', (string) $contextual['status']);
$assert(true === ($contextual['context']['strict'] ?? null), 'HTML transform context exposes strict mode');
$assert(false === ($contextual['context']['allow_fallbacks'] ?? null), 'HTML transform context exposes fallback policy');
$assert('fixture:contextual-html' === ($contextual['provenance'][0]['source'] ?? ''), 'HTML provenance exposes generic source metadata');
$assert('contract-test' === ($contextual['provenance'][0]['scope'] ?? ''), 'HTML provenance exposes generic scope metadata');

$formFallback = ( new HtmlTransformer() )->transform(
    '<main><form action="/contact" method="post" data-action="contact-submit"><label class="field-label" for="email">Email</label><input id="email" class="field-input" name="email" type="email" required><select name="topic"><option value="support" selected>Support</option></select><button type="submit">Send</button></form></main>'
)->toArray();
$formFallbackDiagnostic = $formFallback['fallbacks'][0] ?? array();
$assert(1 === count($formFallback['fallbacks'] ?? array()), 'data-entry runtime form surfaces a materializable form fallback finding');
$assert('html_form_fallback' === ($formFallbackDiagnostic['diagnostic_code'] ?? ''), 'data-entry runtime form fallback carries the form diagnostic code');
$assert('email' === ($formFallbackDiagnostic['controls'][0]['name'] ?? ''), 'data-entry runtime form fallback carries generic control metadata');
$assert('field-input' === ($formFallbackDiagnostic['controls'][0]['class'] ?? '') && 'field-label' === ($formFallbackDiagnostic['controls'][0]['label_class'] ?? ''), 'data-entry runtime form fallback carries bounded control and label presentation classes');
$assert('/contact' === ($formFallbackDiagnostic['form']['action'] ?? ''), 'data-entry runtime form fallback carries form action metadata');
$assert('form' === ($formFallbackDiagnostic['materialization_target']['capability'] ?? ''), 'data-entry runtime form targets a form materializer capability');
$assert('form_provider' === ($formFallbackDiagnostic['materialization_target']['provider_role'] ?? ''), 'data-entry runtime form targets a form provider role');
$assert(preg_match('/^[a-f0-9]{64}$/', $formFallbackDiagnostic['fallback_identity'] ?? '') === 1 && ($formFallbackDiagnostic['fallback_identity'] ?? null) === ($formFallbackDiagnostic['reconciliation_identity'] ?? null), 'data-entry runtime form fallback carries a stable generic reconciliation identity');
$assertNormalizedFallbackDiagnostic($formFallback['source_reports']['conversion_report']['fallback_diagnostics'][0] ?? array(), 'html_form_fallback', 'warning', 'server_or_client_form_handler', 'form');
$assert('form_provider' === ($formFallback['source_reports']['conversion_report']['fallback_diagnostics'][0]['materialization_target']['provider_role'] ?? ''), 'conversion report preserves form provider materialization target');
$assert(($formFallbackDiagnostic['fallback_identity'] ?? null) === ($formFallback['source_reports']['conversion_report']['fallback_diagnostics'][0]['fallback_identity'] ?? null) && ($formFallbackDiagnostic['reconciliation_identity'] ?? null) === ($formFallback['source_reports']['conversion_report']['fallback_diagnostics'][0]['reconciliation_identity'] ?? null), 'conversion report preserves form fallback reconciliation identities');
$assert('core/html' === ($formFallback['blocks'][0]['blockName'] ?? ''), 'data-entry form materializes as preserved form HTML');
$assert(str_contains((string) ($formFallback['serialized_blocks'] ?? ''), '<form action="/contact" method="post"'), 'data-entry form serialized markup keeps the form element');
$assert(str_contains((string) ($formFallback['serialized_blocks'] ?? ''), '<input id="email"'), 'data-entry form serialized markup keeps input controls');
$assert(str_contains((string) ($formFallback['serialized_blocks'] ?? ''), '<select name="topic"'), 'data-entry form serialized markup keeps select controls');
$assert(str_contains((string) ($formFallback['serialized_blocks'] ?? ''), '<button type="submit"'), 'data-entry form serialized markup keeps submit buttons');
$assert('form' === ($formFallback['source_reports']['interaction_candidates'][0]['kind'] ?? ''), 'HTML source report exposes form interaction candidate');
$assert('form' === ($formFallback['source_reports']['conversion_report']['interaction_candidates'][0]['kind'] ?? ''), 'conversion report projects interaction candidates');
$assert('/contact' === ($formFallback['source_reports']['interaction_candidates'][0]['target'] ?? ''), 'form interaction candidate exposes action target');
$formRuntimeIslands = array_values(array_filter($formFallback['source_reports']['runtime_islands'] ?? array(), static fn (array $island): bool => 'form' === ($island['kind'] ?? '')));
$assert(1 === count($formRuntimeIslands), 'data-entry form preservation reports a form runtime island');
$assert('server_or_client_form_handler' === ($formRuntimeIslands[0]['runtime_requirement'] ?? ''), 'form runtime island carries the server/client form-handler requirement');
$nestedControlSlot = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<form><div class="controls"><div><input name="email" type="email"><iframe src="https://example.com/form-help" width="80" height="60"></iframe></div><div><select name="region"><option>Global</option></select></div></div><button type="submit">Send</button></form>')))->toArray();
$nestedFormDeclaration = current(array_filter($nestedControlSlot['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array(), static fn (array $declaration): bool => 'forms' === ($declaration['type'] ?? null)));
$nestedFormBinding = $nestedFormDeclaration['payload']['entities'][0]['bindings'][0]['search_block_markup'] ?? '';
$assert(is_string($nestedFormBinding) && str_contains($nestedFormBinding, '<!-- wp:') && !str_contains($nestedFormBinding, '<!-- wp:html'), 'nested form binding slot uses the normal native converter instead of preserved HTML');
$assert(0 === substr_count((string) ($nestedControlSlot['serialized_blocks'] ?? ''), '<!-- wp:html'), 'nested form controls and bounded iframe avoid core/html fallbacks');
$requiredFormPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<main><form><input name="email" required><textarea name="message" aria-required="true"></textarea><button type="submit">Send</button></form></main>')))->toArray();
$requiredFormDeclarations = array_values(array_filter($requiredFormPlan['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array(), static fn (array $declaration): bool => 'forms' === ($declaration['type'] ?? null)));
$requiredFormControls = $requiredFormDeclarations[0]['payload']['entities'][0]['controls'] ?? array();
$assert(true === ($requiredFormControls[0]['required'] ?? null) && true === ($requiredFormControls[1]['required'] ?? null), 'generic form declarations normalize native and ARIA required semantics through artifact compilation');
$boundedTopologyHtml = static function (int $extraControls): string {
    $controls = '';
    for ( $index = 0; $index < 63; ++$index ) $controls .= '<div><input name="field-' . $index . '"></div>';
    $controls .= '<input name="standalone"><button type="submit">Send</button>';
    for ( $index = 0; $index < $extraControls; ++$index ) $controls .= '<input name="extra-' . $index . '">';
    return '<main><form>' . $controls . '</form></main>';
};
$exactTopology = ( new HtmlTransformer() )->transform($boundedTopologyHtml(0))->toArray()['fallbacks'][0]['control_topology'] ?? array();
$overflowTopology = ( new HtmlTransformer() )->transform($boundedTopologyHtml(1))->toArray()['fallbacks'][0]['control_topology'] ?? array();
$assert(128 === count($exactTopology['nodes'] ?? array()) && false === ($exactTopology['truncated'] ?? null), 'form control topology retains exactly the configured node limit without reporting truncation');
$assert(128 === count($overflowTopology['nodes'] ?? array()) && true === ($overflowTopology['truncated'] ?? null), 'form control topology truncates deterministically when one source-ordered control exceeds the node limit');
$assert(64 === ($overflowTopology['nodes'][127]['control'] ?? null), 'node-limit truncation preserves the last in-bounds flat control reference');
$exactTopologyResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $boundedTopologyHtml(0))))->toArray();
$overflowTopologyResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $boundedTopologyHtml(1))))->toArray();
$runtimeDeclarationKeys = static fn(array $result): array => array_map(static fn(array $declaration): string => ($declaration['kind'] ?? '') . ':' . ($declaration['type'] ?? $declaration['capability'] ?? ''), $result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array());
$assert(in_array('entity_collection:forms', $runtimeDeclarationKeys($exactTopologyResult), true), 'complete bounded form topology remains provider-materializable');
$exactFormDeclaration = current(array_filter($exactTopologyResult['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array(), static fn(array $declaration): bool => 'forms' === ($declaration['type'] ?? null)));
$assert(($exactTopologyResult['fallbacks'][0]['fallback_identity'] ?? null) === ($exactFormDeclaration['payload']['entities'][0]['fallback_identity'] ?? null) && ($exactTopologyResult['fallbacks'][0]['reconciliation_identity'] ?? null) === ($exactFormDeclaration['payload']['entities'][0]['reconciliation_identity'] ?? null), 'complete form projection carries its source fallback reconciliation identities unchanged');
$assert(!in_array('entity_collection:forms', $runtimeDeclarationKeys($overflowTopologyResult), true) && !in_array('dependency:form', $runtimeDeclarationKeys($overflowTopologyResult), true), 'truncated form topology remains fallback-only without claiming provider materialization');
$assert('html_form_fallback' === ($overflowTopologyResult['fallbacks'][0]['diagnostic_code'] ?? '') && true === ($overflowTopologyResult['fallbacks'][0]['control_topology']['truncated'] ?? null), 'fallback-only overflow forms retain explicit source-loss evidence');
$assert(isset($overflowTopologyResult['fallbacks'][0]['fallback_identity']) && array() === array_values(array_filter($overflowTopologyResult['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array(), static fn(array $declaration): bool => 'forms' === ($declaration['type'] ?? null))), 'truncated form identity remains unresolved because no provider entity is emitted');
$assert(isset($overflowTopologyResult['source_reports']['wordpress_site_plan']), 'fallback-only overflow forms still produce a WordPress site plan');
$presentationTopology = ( new HtmlTransformer() )->transform('<main><form><custom-element id="bad id" class="safe bad/token one two three four five six seven eight nine ' . str_repeat('x', 81) . '"><input name="safe"></custom-element><button type="submit">Send</button></form></main>')->toArray()['fallbacks'][0]['control_topology']['nodes'][0] ?? array();
$assert(! isset($presentationTopology['tag']) && ! isset($presentationTopology['source_id']), 'form topology omits unsupported wrapper tags and malformed source IDs');
$assert('safe one two three four five six seven' === ($presentationTopology['class'] ?? ''), 'form topology retains only the first eight bounded safe class tokens');
$layoutGraphHtml = '<main><form class="form"><div class="row-2"><div class="field"><input name="first"></div><div class="field"><input name="last"></div></div><button type="submit">Send</button></form></main>';
$layoutGraphCss = '.form{display:grid;grid-template-columns:1fr;gap:1rem}.form .row-2{display:grid;grid-template-columns:1fr 1fr;gap:1rem}.field{display:flex;flex-direction:column;gap:.3rem}@media (max-width:640px){.form .row-2{grid-template-columns:1fr}}@container form-shell (max-width:30rem){.field{gap:.5rem}}@supports (display:grid){.form{align-content:start}}';
$layoutGraphFallback = (new HtmlTransformer())->transform($layoutGraphHtml, array('static_css' => $layoutGraphCss))->toArray()['fallbacks'][0] ?? array();
$layoutGraph = $layoutGraphFallback['layout_graph'] ?? array();
$layoutNodes = array_column($layoutGraph['nodes'] ?? array(), null, 'id');
$assert('generic/computed-layout-graph/v2' === ($layoutGraph['schema'] ?? null) && 'source_css_cascade' === ($layoutGraph['basis'] ?? null), 'form fallback emits the v2 declared-CSS layout graph contract');
$assert('grid' === ($layoutNodes['form']['layout']['display'] ?? null) && '1fr 1fr' === ($layoutNodes['wrapper-0']['layout']['columns'] ?? null) && 'flex' === ($layoutNodes['wrapper-1']['layout']['display'] ?? null), 'layout graph preserves form, row, and field layout facts in source order');
$assert('form' === ($layoutNodes['wrapper-0']['parent'] ?? null) && 'wrapper-0' === ($layoutNodes['wrapper-1']['parent'] ?? null), 'layout graph preserves deterministic source parentage without inferring Columns');
$layoutVariantKinds = array_values(array_unique(array_column(array_column($layoutGraph['variants'] ?? array(), 'condition'), 'kind'))); sort($layoutVariantKinds, SORT_STRING);
$assert(array('container', 'media', 'supports') === $layoutVariantKinds, 'layout graph preserves media, container, and supports declarations as conditional variants');
$malformedLayoutGraph = (new HtmlTransformer())->transform($layoutGraphHtml, array('static_css' => '.form{display:grid'))->toArray()['fallbacks'][0]['layout_graph'] ?? array();
$assert(in_array('malformed_stylesheet:inline-style', $malformedLayoutGraph['diagnostics'] ?? array(), true), 'layout graph reports malformed source stylesheets instead of guessing layout facts');
$cascadeGraph = (new HtmlTransformer())->transform($layoutGraphHtml, array('static_css' => '.form{display:flex;display:grid!important;display:block}.field{display:flex}@media (max-width:50rem){@supports (display:grid){.field{gap:1rem}}}.form button[type="submit"]{order:2}'))->toArray()['fallbacks'][0]['layout_graph'] ?? array();
$cascadeNodes = array_column($cascadeGraph['nodes'] ?? array(), null, 'id');
$cascadeVariant = $cascadeGraph['variants'][0] ?? array();
$assert('grid' === ($cascadeNodes['form']['layout']['display'] ?? null) && !isset($cascadeNodes['control-2']['layout']['order']) && in_array('unsupported_selector:.form button[type="submit"]', $cascadeGraph['diagnostics'] ?? array(), true), 'layout graph resolves duplicate same-rule declarations by declaration order and !important, and reports unsupported attribute selector semantics explicitly');
$assert('all' === ($cascadeVariant['condition']['kind'] ?? null) && $cascadeVariant['condition'] === ($cascadeVariant['provenance'][0]['condition'] ?? null), 'layout graph retains nested condition chains and conditional provenance exactly.');
$conditionalOnlyGraph = (new HtmlTransformer())->transform('<form><div class="field"><input name="x"></div><button type="submit">Send</button></form>', array('static_css' => '@media (max-width:50rem){.field{display:flex;flex-direction:column;align-items:flex-start}}'))->toArray()['fallbacks'][0]['layout_graph'] ?? array();
$conditionalOnlyNode = array_column($conditionalOnlyGraph['nodes'] ?? array(), null, 'id')['wrapper-0'] ?? array(); $conditionalOnlyVariant = $conditionalOnlyGraph['variants'][0] ?? array();
$conditionalOnlyProperties = $conditionalOnlyVariant['provenance'][0]['properties'] ?? array();
$assert(array() === ($conditionalOnlyNode['layout'] ?? null) && array() === ($conditionalOnlyNode['provenance'] ?? null) && 'column' === ($conditionalOnlyVariant['layout_patch']['direction'] ?? null) && 'flex-start' === ($conditionalOnlyVariant['layout_patch']['align_items'] ?? null) && isset($conditionalOnlyVariant['precedence']['flex-direction'], $conditionalOnlyVariant['precedence']['align-items']) && in_array('flex-direction', $conditionalOnlyProperties, true) && in_array('align-items', $conditionalOnlyProperties, true), 'conditional-only layout nodes retain explicit empty base facts and canonical normalized-key to CSS-property correspondence.');
$highSpecificitySelector = str_repeat('#active', 101);
$inlineGraph = (new HtmlTransformer())->transform('<form id="active" style="display:flex"><input name="x"><button type="submit">Send</button></form>', array('static_css' => $highSpecificitySelector . '{display:grid}'))->toArray()['fallbacks'][0]['layout_graph'] ?? array();
$inlineNode = array_column($inlineGraph['nodes'] ?? array(), null, 'id')['form'] ?? array();
$assert('flex' === ($inlineNode['layout']['display'] ?? null) && 'inline-style' === ($inlineNode['provenance'][0]['source_path'] ?? null) && '[style]' === ($inlineNode['provenance'][0]['selector'] ?? null), 'inline normal declarations outrank matching normal stylesheet selectors with more than 100 IDs.');
$importantStylesheetGraph = (new HtmlTransformer())->transform('<form id="active" style="display:flex"><input name="x"><button type="submit">Send</button></form>', array('static_css' => $highSpecificitySelector . '{display:grid!important}'))->toArray()['fallbacks'][0]['layout_graph'] ?? array();
$importantStylesheetNode = array_column($importantStylesheetGraph['nodes'] ?? array(), null, 'id')['form'] ?? array();
$assert('grid' === ($importantStylesheetNode['layout']['display'] ?? null) && $highSpecificitySelector === ($importantStylesheetNode['provenance'][0]['selector'] ?? null), 'important stylesheet declarations outrank normal inline declarations.');
$importantInlineGraph = (new HtmlTransformer())->transform('<form id="active" style="display:flex!important"><input name="x"><button type="submit">Send</button></form>', array('static_css' => $highSpecificitySelector . '{display:grid!important}'))->toArray()['fallbacks'][0]['layout_graph'] ?? array();
$importantInlineNode = array_column($importantInlineGraph['nodes'] ?? array(), null, 'id')['form'] ?? array();
$assert('flex' === ($importantInlineNode['layout']['display'] ?? null) && 'inline-style' === ($importantInlineNode['provenance'][0]['source_path'] ?? null), 'important inline declarations outrank important stylesheet declarations regardless of selector specificity.');
$deepColumns = '';
foreach ( array('first', 'second', 'third') as $name ) $deepColumns .= '<td style="width:33.333333333333%"><div class="field"><input name="' . $name . '"></div></td>';
$deepThreeColumnHtml = '<main><form class="deep-form">' . str_repeat('<div>', 9) . '<table><tbody><tr>' . $deepColumns . '</tr></tbody></table>' . str_repeat('</div>', 9) . '<button type="submit">Send</button></form></main>';
$largeUnrelatedCss = ''; for ( $index = 0; $index < 1200; ++$index ) $largeUnrelatedCss .= '.unrelated-' . $index . '{display:grid}'; $largeUnrelatedCss .= '.deep-form{display:flex;flex-direction:column}';
$deepGraph = (new HtmlTransformer())->transform($deepThreeColumnHtml, array('static_css' => $largeUnrelatedCss))->toArray()['fallbacks'][0]['layout_graph'] ?? array();
$deepGraphNodes = array_column($deepGraph['nodes'] ?? array(), null, 'id');
$deepWidthNodes = array_values(array_filter($deepGraph['nodes'] ?? array(), static fn(array $node): bool => 'td' === ($node['source']['tag'] ?? null) && '33.333333333333%' === ($node['layout']['width'] ?? null)));
$assert(false === ($deepGraph['truncated'] ?? null) && 'generic/computed-layout-graph/v2' === ($deepGraph['schema'] ?? null) && 16 === ($deepGraph['limits']['depth'] ?? null) && 'flex' === ($deepGraphNodes['form']['layout']['display'] ?? null) && '.deep-form' === ($deepGraphNodes['form']['provenance'][0]['selector'] ?? null) && !in_array('css_rule_or_selector_limit', $deepGraph['diagnostics'] ?? array(), true) && !in_array('css_selector_scan_limit', $deepGraph['diagnostics'] ?? array(), true), 'deep form layout analysis retains matching stylesheet facts after 1,200 unrelated selectors within the documented v2 scan and topology bounds.');
$assert(3 === count($deepWidthNodes) && array('width') === ($deepWidthNodes[0]['provenance'][0]['properties'] ?? null) && 'inline-style' === ($deepWidthNodes[0]['provenance'][0]['source_path'] ?? null) && '[style]' === ($deepWidthNodes[0]['provenance'][0]['selector'] ?? null), 'deep three-column form cells retain explicit percentage width facts with inline source provenance.');
$deepGraphAgain = (new HtmlTransformer())->transform($deepThreeColumnHtml, array('static_css' => $largeUnrelatedCss))->toArray()['fallbacks'][0]['layout_graph'] ?? array();
$assert(hash('sha256', json_encode($deepGraph)) === hash('sha256', json_encode($deepGraphAgain)), 'deep form layout facts and provenance are deterministic across repeated transforms.');
$deepArtifact = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<link rel="stylesheet" href="style.css">' . $deepThreeColumnHtml, 'style.css' => $largeUnrelatedCss)))->toArray();
$deepDeclaration = current(array_filter($deepArtifact['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array(), static fn(array $declaration): bool => 'forms' === ($declaration['type'] ?? null)));
$projectedDeepGraph = $deepDeclaration['payload']['entities'][0]['layout_graph'] ?? array();
$projectedWidthNodes = array_values(array_filter($projectedDeepGraph['nodes'] ?? array(), static fn(array $node): bool => 'td' === ($node['source']['tag'] ?? null) && '33.333333333333%' === ($node['layout']['width'] ?? null)));
$assert(3 === count($projectedWidthNodes) && false === ($projectedDeepGraph['truncated'] ?? null), 'artifact compilation projects the complete deep percentage-width graph into generic/forms/v1.');
$depthBoundaryHtml = '<form>' . str_repeat('<div>', 16) . '<input name="edge">' . str_repeat('</div>', 16) . '<button type="submit">Send</button></form>';
$depthOverflowHtml = '<form>' . str_repeat('<div>', 17) . '<input name="overflow">' . str_repeat('</div>', 17) . '<button type="submit">Send</button></form>';
$depthBoundaryGraph = (new HtmlTransformer())->transform($depthBoundaryHtml, array('static_css' => 'input{width:100%}'))->toArray()['fallbacks'][0]['layout_graph'] ?? array();
$depthOverflowGraph = (new HtmlTransformer())->transform($depthOverflowHtml, array('static_css' => 'input{width:100%}'))->toArray()['fallbacks'][0]['layout_graph'] ?? array();
$assert(false === ($depthBoundaryGraph['truncated'] ?? null) && true === ($depthOverflowGraph['truncated'] ?? null) && in_array('node_or_depth_limit', $depthOverflowGraph['diagnostics'] ?? array(), true), 'layout traversal accepts topology depth 16 exactly and fails closed at depth 17.');
$selectorOverflowCss = str_repeat('.deep-form{display:grid}', 513);
$selectorOverflowGraph = (new HtmlTransformer())->transform($deepThreeColumnHtml, array('static_css' => $selectorOverflowCss))->toArray()['fallbacks'][0]['layout_graph'] ?? array();
$selectorOverflowArtifact = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<link rel="stylesheet" href="style.css">' . $deepThreeColumnHtml, 'style.css' => $selectorOverflowCss)))->toArray();
$selectorOverflowDeclaration = current(array_filter($selectorOverflowArtifact['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array(), static fn(array $declaration): bool => 'forms' === ($declaration['type'] ?? null)));
$assert(true === ($selectorOverflowGraph['truncated'] ?? null) && in_array('css_rule_or_selector_limit', $selectorOverflowGraph['diagnostics'] ?? array(), true) && !isset($selectorOverflowDeclaration['payload']['entities'][0]['layout_graph']), 'retained rule overflow remains explicit and incomplete graphs remain omitted from generic/forms/v1.');
$scanOverflowCss = ''; for ( $index = 0; $index < 4097; ++$index ) $scanOverflowCss .= '.unrelated-' . $index . '{display:grid}';
$scanOverflowGraph = (new HtmlTransformer())->transform($deepThreeColumnHtml, array('static_css' => $scanOverflowCss))->toArray()['fallbacks'][0]['layout_graph'] ?? array();
$assert(true === ($scanOverflowGraph['truncated'] ?? null) && in_array('css_selector_scan_limit', $scanOverflowGraph['diagnostics'] ?? array(), true) && !in_array('css_rule_or_selector_limit', $scanOverflowGraph['diagnostics'] ?? array(), true), 'unrelated selector scanning fails closed at its independent 4,096-selector work budget.');
$assert(is_int($cascadeVariant['precedence']['gap']['source_order'] ?? null) && is_int($cascadeVariant['precedence']['gap']['specificity'] ?? null) && is_bool($cascadeVariant['precedence']['gap']['important'] ?? null), 'conditional variants carry deterministic cascade precedence rather than implying independent winners.');
$crossConditionGraph = (new HtmlTransformer())->transform('<form id="active" class="form"><input name="x"><button type="submit">Send</button></form>', array('static_css' => '.form{display:grid!important}@media (max-width:50rem){.form{display:flex}}@media (min-width:40rem){.form#active{display:flex!important}}'))->toArray()['fallbacks'][0]['layout_graph'] ?? array();
$crossConditionVariants = $crossConditionGraph['variants'] ?? array();
$assert(1 === count($crossConditionVariants) && 'flex' === ($crossConditionVariants[0]['layout_patch']['display'] ?? null) && true === ($crossConditionVariants[0]['precedence']['display']['important'] ?? null), 'conditional graph patches exclude weaker declarations that cannot override unconditional winners while retaining effective important variants.');
$nestedConditions = str_repeat('@media (min-width:1px){', 9) . '.form{display:grid}' . str_repeat('}', 9); $nestedGraph = (new HtmlTransformer())->transform($layoutGraphHtml, array('static_css' => $nestedConditions))->toArray()['fallbacks'][0]['layout_graph'] ?? array();
$assert(true === ($nestedGraph['truncated'] ?? null) && in_array('condition_depth_limit', $nestedGraph['diagnostics'] ?? array(), true), 'layout graph bounds nested conditional at-rule recursion before parsing deeper rules.');
$nestedLayoutResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<link rel="stylesheet" href="style.css">' . $layoutGraphHtml, 'style.css' => $nestedConditions)))->toArray();
$nestedLayoutDeclaration = current(array_filter($nestedLayoutResult['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array(), static fn(array $declaration): bool => 'forms' === ($declaration['type'] ?? null)));
$nestedLayoutFallback = current(array_filter($nestedLayoutResult['fallbacks'] ?? array(), static fn(array $fallback): bool => 'html_form_fallback' === ($fallback['diagnostic_code'] ?? null)));
$assert(in_array('entity_collection:forms', $runtimeDeclarationKeys($nestedLayoutResult), true) && in_array('dependency:form', $runtimeDeclarationKeys($nestedLayoutResult), true) && !isset($nestedLayoutDeclaration['payload']['entities'][0]['layout_graph']), 'forms with complete controls remain provider-materializable without an incomplete optional layout graph');
$assert(true === ($nestedLayoutFallback['layout_graph']['truncated'] ?? null) && in_array('condition_depth_limit', $nestedLayoutFallback['layout_graph']['diagnostics'] ?? array(), true), 'provider forms retain truncated layout graph evidence in their static fallback');
$ancestryGraph = (new HtmlTransformer())->transform('<form><div><div class="field"><input name="x"></div></div><button type="submit">Send</button></form>', array('static_css' => '.field{display:flex}'))->toArray()['fallbacks'][0]['layout_graph'] ?? array();
$ancestryIds = array_flip(array_column($ancestryGraph['nodes'] ?? array(), 'id')); foreach ($ancestryGraph['nodes'] ?? array() as $node) $assert(null === ($node['parent'] ?? null) || isset($ancestryIds[$node['parent']]), 'layout graph emits deterministic ancestry instead of dangling parents.');
$boundedCss = str_repeat('.form{display:grid}', 600); $boundedGraph = (new HtmlTransformer())->transform($layoutGraphHtml, array('static_css' => $boundedCss))->toArray()['fallbacks'][0]['layout_graph'] ?? array();
$assert(true === ($boundedGraph['truncated'] ?? null) && in_array('css_rule_or_selector_limit', $boundedGraph['diagnostics'] ?? array(), true), 'layout graph bounds CSS parsing and rule matching work with explicit diagnostics.');
$invalidLayoutGraph = $layoutGraph; $invalidLayoutGraph['nodes'][0]['id'] = 'wrapper-0'; try { \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder::assertValid($invalidLayoutGraph); $assert(false, 'layout graph validation rejects duplicate node identities'); } catch (\InvalidArgumentException) { $assert(true, 'layout graph validation rejects duplicate node identities'); }
$unsafeGraph = $layoutGraph; $unsafeGraph['nodes'][0]['provenance'][0]['source_path'] = '../../untrusted.css'; try { \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder::assertValid($unsafeGraph); $assert(false, 'layout graph validation rejects unsafe provenance traversal paths'); } catch (\InvalidArgumentException) { $assert(true, 'layout graph validation rejects unsafe provenance traversal paths'); }
$semanticGraph = $layoutGraph; $semanticGraph['nodes'][0]['layout']['unknown_layout'] = 'value'; try { \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder::assertValid($semanticGraph); $assert(false, 'layout graph validation rejects unknown semantic layout keys'); } catch (\InvalidArgumentException) { $assert(true, 'layout graph validation rejects unknown semantic layout keys'); }
$v1LayoutGraph = $layoutGraph; $v1LayoutGraph['schema'] = 'generic/computed-layout-graph/v1'; $v1LayoutGraph['limits']['depth'] = 8;
try { \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder::assertValid($v1LayoutGraph); $assert(true, 'layout graph validation accepts persisted v1 depth-8 graphs using the old property vocabulary'); } catch (\InvalidArgumentException) { $assert(false, 'layout graph validation accepts persisted v1 depth-8 graphs using the old property vocabulary'); }
$v1WidthGraph = $v1LayoutGraph; $v1WidthGraph['nodes'][0]['layout']['width'] = '100%'; try { \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder::assertValid($v1WidthGraph); $assert(false, 'v1 layout graph validation rejects v2 width facts'); } catch (\InvalidArgumentException) { $assert(true, 'v1 layout graph validation rejects v2 width facts'); }
$v1Depth16Graph = $v1LayoutGraph; $v1Depth16Graph['limits']['depth'] = 16; try { \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder::assertValid($v1Depth16Graph); $assert(false, 'v1 layout graph validation rejects v2 depth limits'); } catch (\InvalidArgumentException) { $assert(true, 'v1 layout graph validation rejects v2 depth limits'); }
$v2Depth8Graph = $layoutGraph; $v2Depth8Graph['limits']['depth'] = 8; try { \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder::assertValid($v2Depth8Graph); $assert(false, 'v2 layout graph validation rejects v1 depth limits'); } catch (\InvalidArgumentException) { $assert(true, 'v2 layout graph validation rejects v1 depth limits'); }

$newsletterFallback = ( new HtmlTransformer() )->transform(
    '<main><section><h2>Newsletter</h2><form class="newsletter-form" action="#" method="post" novalidate><input type="email" name="email" placeholder="your@email.com" autocomplete="email" required aria-label="Email address"><button type="submit">Subscribe</button></form></section></main>'
)->toArray();
$newsletterFallbackDiagnostic = $newsletterFallback['fallbacks'][0] ?? array();
$assert('html_form_fallback' === ($newsletterFallbackDiagnostic['diagnostic_code'] ?? ''), 'static newsletter form stays classified as a provider-materializable form target');
$assert('interactive_form' === ($newsletterFallbackDiagnostic['pattern_family'] ?? ''), 'static newsletter form uses the interactive_form family');
$assert('form' === ($newsletterFallbackDiagnostic['suggested_primitive'] ?? ''), 'static newsletter form suggests a form primitive, not a fake native layout');
$assert('form_provider' === ($newsletterFallbackDiagnostic['materialization_target']['provider_role'] ?? ''), 'static newsletter form declares the form provider materialization role');
$assert(0 === substr_count((string) ($newsletterFallback['serialized_blocks'] ?? ''), '<!-- wp:html'), 'readable newsletter form output avoids core/html while keeping fallback metadata explicit');

$nestedPseudoForm = ( new HtmlTransformer() )->transform(
    '<article><nav aria-label="Blog"><a href="/posts">Posts</a></nav><div class="content-wrapper"><h1>Article title</h1><p>Article copy stays editable.</p><div class="contact-panel" action="/contact"><label for="contact-email">Email</label><input id="contact-email" name="email" type="email"><button>Send message</button><p role="status">Thanks, we will reply shortly.</p></div><p>Related reading.</p></div></article>'
)->toArray();
$nestedPseudoFallback = $nestedPseudoForm['fallbacks'][0] ?? array();
$nestedPseudoBoundary = $nestedPseudoFallback['form_boundary'] ?? array();
$assert(1 === count($nestedPseudoForm['fallbacks'] ?? array()) && 'contact-panel' === ($nestedPseudoFallback['form']['class'] ?? ''), 'nested pseudo-form selects the local control region instead of its article wrapper');
$assert('/contact' === ($nestedPseudoFallback['form']['action'] ?? '') && 'Email' === ($nestedPseudoFallback['controls'][0]['label'] ?? '') && 'Send message' === ($nestedPseudoFallback['controls'][1]['text'] ?? '') && 'Thanks, we will reply shortly.' === ($nestedPseudoFallback['success_panel']['text'] ?? ''), 'nested pseudo-form preserves action, associated label, submit text, and success-state metadata');
$assert('generic/form-boundary/v1' === ($nestedPseudoBoundary['schema'] ?? '') && array( 'local_controls', 'associated_label', 'submit_semantics' ) === ($nestedPseudoBoundary['selection_basis'] ?? array()) && in_array('contains_unrelated_landmark', array_column($nestedPseudoBoundary['rejected_ancestors'] ?? array(), 'reason'), true), 'pseudo-form diagnostics explain the local boundary and rejected editorial ancestors');
$assert(str_contains((string) ($nestedPseudoForm['serialized_blocks'] ?? ''), 'Article title') && str_contains((string) ($nestedPseudoForm['serialized_blocks'] ?? ''), 'Related reading.') && ! str_contains((string) ($nestedPseudoFallback['html'] ?? ''), 'Article title'), 'surrounding article content remains native blocks and outside pseudo-form fallback metadata');
$actionPseudoForm = ( new HtmlTransformer() )->transform('<div action="/request-quote"><label for="quote-email">Email</label><input id="quote-email" name="email" type="email"><button>Continue</button></div>')->toArray();
$assert('/request-quote' === ($actionPseudoForm['fallbacks'][0]['form']['action'] ?? '') && 'Continue' === ($actionPseudoForm['fallbacks'][0]['controls'][1]['text'] ?? ''), 'explicit local action semantics retain a coherent pseudo-form with a neutral submit label');

$broadPseudoForm = ( new HtmlTransformer() )->transform(
    '<div id="content-wrapper"><nav aria-label="Blog"><a href="/posts">Posts</a></nav><article><h1>Post title</h1><div class="search"><input class="search-input" type="text" placeholder="Search"></div><button aria-label="Share via Facebook">Share</button><p>Long article copy.</p></article></div>'
)->toArray();
$assert(array() === array_values(array_filter($broadPseudoForm['fallbacks'] ?? array(), static fn (array $fallback): bool => 'html_form_fallback' === ($fallback['diagnostic_code'] ?? ''))), 'search fields and unrelated buttons never promote a content wrapper to a pseudo-form');
$assert(str_contains((string) ($broadPseudoForm['serialized_blocks'] ?? ''), 'Post title') && str_contains((string) ($broadPseudoForm['serialized_blocks'] ?? ''), 'Long article copy.'), 'rejected broad pseudo-form candidates remain ordinary native content');

$commerceControls = ( new HtmlTransformer() )->transform(
    '<main><ul class="products"><li><article class="product-card"><h3>Tour Tee</h3><p>Heavy cotton shirt.</p><div class="price">$30</div><div aria-label="Quantity"><button data-dir="down" aria-label="Decrease quantity">-</button><span aria-live="polite">1</span><button data-dir="up" aria-label="Increase quantity">+</button></div><button class="add-to-cart">Add to cart</button></article></li><li><article class="product-card"><h3>Signed CD</h3><p>Hand-signed disc.</p><div class="price">$15</div><div aria-label="Quantity"><button data-dir="down" aria-label="Decrease quantity">-</button><span aria-live="polite">1</span><button data-dir="up" aria-label="Increase quantity">+</button></div><button class="add-to-cart">Add to cart</button></article></li></ul></main>'
)->toArray();
$commerceDiagnostics = array();
foreach ( $commerceControls['fallbacks'] ?? array() as $fallback ) {
    $commerceDiagnostics[(string) ($fallback['diagnostic_code'] ?? '')] = $fallback;
}
$assert(isset($commerceDiagnostics['html_product_grid_fallback']), 'commerce cards still expose product-grid materialization metadata');
$assert(isset($commerceDiagnostics['html_commerce_controls_fallback']), 'commerce quantity/cart controls expose a dedicated runtime diagnostic');
$assert('commerce_product_provider' === ($commerceDiagnostics['html_product_grid_fallback']['materialization_target']['provider_role'] ?? ''), 'commerce product grid targets product materialization through a shop provider');
$assert('product' === ($commerceDiagnostics['html_product_grid_fallback']['materialization_target']['entity'] ?? ''), 'commerce product grid materialization target is product data');
$commerceReportDiagnostics = array();
foreach ( $commerceControls['source_reports']['conversion_report']['fallback_diagnostics'] ?? array() as $diagnostic ) {
    $commerceReportDiagnostics[(string) ($diagnostic['diagnostic_code'] ?? '')] = $diagnostic;
}
$assert(2 === ($commerceReportDiagnostics['html_product_grid_fallback']['product_count'] ?? 0), 'conversion report preserves product-grid product count');
$assert('Tour Tee' === ($commerceReportDiagnostics['html_product_grid_fallback']['products'][0]['name'] ?? ''), 'conversion report preserves product data for shop-provider materialization');
$assert('commerce_product_provider' === ($commerceReportDiagnostics['html_product_grid_fallback']['materialization_target']['provider_role'] ?? ''), 'conversion report preserves shop-provider product target');
$assert('commerce_controls' === ($commerceDiagnostics['html_commerce_controls_fallback']['pattern_family'] ?? ''), 'commerce controls use the commerce_controls pattern family');
$assert('commerce_cart_runtime' === ($commerceDiagnostics['html_commerce_controls_fallback']['runtime_requirement'] ?? ''), 'commerce controls require a commerce cart runtime');
$assert('commerce_controls' === ($commerceDiagnostics['html_commerce_controls_fallback']['suggested_primitive'] ?? ''), 'commerce controls do not pretend to have a native core block path');
$assert('commerce_cart_runtime' === ($commerceDiagnostics['html_commerce_controls_fallback']['materialization_target']['provider_role'] ?? ''), 'commerce controls target cart runtime binding, not product data seeding');
$assert(true === ($commerceDiagnostics['html_commerce_controls_fallback']['controls'][0]['has_quantity_control'] ?? null), 'commerce controls preserve quantity-control evidence');

$contactLayout = ( new HtmlTransformer() )->transform(
    '<main><section class="contact-layout"><div><h2>Booking</h2><p>For shows, email <a href="mailto:booking@example.com">booking@example.com</a>.</p></div><div><h2>Follow</h2><p><a href="https://example.com">Instagram</a></p></div></section></main>'
)->toArray();
$assert(array() === ($contactLayout['fallbacks'] ?? array()), 'static contact layout decomposes without fallback diagnostics');
$assert(0 === substr_count((string) ($contactLayout['serialized_blocks'] ?? ''), '<!-- wp:html'), 'static contact layout emits native blocks only');

$canonicalLinkUrls = ( new HtmlTransformer() )->transform(
    '<main><p><a href="hello@richlynngroup.com&nbsp;">Entity whitespace</a><a href="hello@richlynngroup.com' . "\xC2\xA0" . '">Literal whitespace</a><a href="mailto:hello@richlynngroup.com?subject=Hello">Mail query</a><a href="martinguitar.com">Bare domain</a><a href="https://example.test/?x=&amp;copy;">Literal entity query</a><a href="&quot;quoted.local&quot;@example.test">Quoted mailbox</a><a href="δοκιμή@παράδειγμα.δοκιμή">Unicode mailbox</a><a href="members/hello@richlynngroup.com/profile">Relative path</a><a href="java&#x0A;script&#58;alert(1)">Obfuscated script</a><a href="data&#58;text/plain,unsafe">Data</a><a href="vbscript&#58;msgbox(1)">VBScript</a></p><nav><a href="hello@richlynngroup.com">Email</a></nav></main>'
)->toArray();
$canonicalLinkMarkup = (string) ($canonicalLinkUrls['serialized_blocks'] ?? '');
$canonicalNavigation = $canonicalLinkUrls['blocks'][0]['innerBlocks'][1]['innerBlocks'][0]['attrs']['url'] ?? null;
$assert(2 === substr_count($canonicalLinkMarkup, 'href="mailto:hello@richlynngroup.com"') && str_contains($canonicalLinkMarkup, 'href="mailto:hello@richlynngroup.com?subject=Hello"') && str_contains($canonicalLinkMarkup, 'href="https://martinguitar.com"') && str_contains($canonicalLinkMarkup, 'href="https://example.test/?x=&amp;copy;"') && str_contains($canonicalLinkMarkup, 'href="mailto:%22quoted.local%22@example.test"') && str_contains($canonicalLinkMarkup, 'href="mailto:%CE%B4%CE%BF%CE%BA%CE%B9%CE%BC%CE%AE@%CF%80%CE%B1%CF%81%CE%AC%CE%B4%CE%B5%CE%B9%CE%B3%CE%BC%CE%B1.%CE%B4%CE%BF%CE%BA%CE%B9%CE%BC%CE%AE"') && str_contains($canonicalLinkMarkup, 'href="members/hello@richlynngroup.com/profile"') && ! str_contains($canonicalLinkMarkup, 'script:') && ! str_contains($canonicalLinkMarkup, 'data:') && ! str_contains($canonicalLinkMarkup, 'vbscript:'), 'link sanitization canonicalizes DOM-decoded NBSP-trimmed bare emails and web hosts, preserves literal entity query text and relative @ paths, supports quoted and Unicode mailboxes, and rejects unsafe schemes');
$assert('mailto:hello@richlynngroup.com' === $canonicalNavigation, 'native navigation conversion shares bare-email link canonicalization');
$assert('https://example.test/?x=&copy;' === LinkUrlSanitizer::sanitize('https://example.test/?x=&copy;') && 'https://martinguitar.com' === LinkUrlSanitizer::sanitize('martinguitar.com') && 'guide.html' === LinkUrlSanitizer::sanitize('guide.html') && 'mailto:"quoted.local"@example.test' === LinkUrlSanitizer::sanitize('"quoted.local"@example.test') && 'mailto:δοκιμή@παράδειγμα.δοκιμή' === LinkUrlSanitizer::sanitize('δοκιμή@παράδειγμα.δοκιμή'), 'link sanitization recognizes bare web hosts without converting common relative file links and recognizes quoted and Unicode mailboxes without IDN conversion');
$safeLinkProtocols = array( 'http', 'https', 'ftp', 'ftps', 'mailto', 'news', 'irc', 'ircs', 'gopher', 'nntp', 'feed', 'telnet', 'mms', 'rtsp', 'svn', 'tel', 'fax', 'xmpp', 'webcal', 'urn' );
foreach ( $safeLinkProtocols as $protocol ) $assert($protocol . ':value' === LinkUrlSanitizer::sanitize($protocol . ':value'), 'link sanitization permits WordPress-safe explicit scheme ' . $protocol);
foreach ( array( '/relative/path', '../relative', '//example.test/path', '#fragment', '?query=value' ) as $relativeUrl ) $assert($relativeUrl === LinkUrlSanitizer::sanitize($relativeUrl), 'link sanitization preserves relative, protocol-relative, fragment, and query URLs');
foreach ( array( 'data:text/plain,unsafe', 'vbscript:msgbox(1)', 'javascript:alert(1)', 'unknown:value', "java\nscript:alert(1)" ) as $unsafeUrl ) $assert('' === LinkUrlSanitizer::sanitize($unsafeUrl), 'link sanitization rejects unsafe or unknown explicit schemes and scheme obfuscation');

$numericImageDimensions = ( new HtmlTransformer() )->transform(
    '<main><img src="photo.jpg" alt="Photo" width="181" height="217" style="object-fit:cover;width:181;height:217"></main>'
)->toArray();
$numericImageAttrs = $numericImageDimensions['blocks'][0]['attrs'] ?? array();
$numericImageMarkup = (string) ($numericImageDimensions['serialized_blocks'] ?? '');
$assert('181px' === ($numericImageAttrs['width'] ?? null) && '217px' === ($numericImageAttrs['height'] ?? null) && str_contains($numericImageMarkup, 'style="object-fit:cover;width:181px;height:217px"'), 'numeric image display dimensions use canonical CSS lengths in both block attributes and saved markup');

$numericLinkedImageDimensions = ( new HtmlTransformer() )->transform(
    '<main><a href="https://example.com"><img src="photo.jpg" alt="Photo" width="44" height="44" style="object-fit:cover;width:44;height:44"></a></main>'
)->toArray();
$numericLinkedImageAttrs = $numericLinkedImageDimensions['blocks'][0]['attrs'] ?? array();
$numericLinkedImageMarkup = (string) ($numericLinkedImageDimensions['serialized_blocks'] ?? '');
$assert('custom/responsive-media' === ($numericLinkedImageDimensions['blocks'][0]['blockName'] ?? null) && str_contains((string) ($numericLinkedImageAttrs['content'] ?? ''), 'style="object-fit:cover;width:44;height:44"') && str_contains($numericLinkedImageMarkup, 'responsive-media'), 'numeric linked-image dimensions remain in the reusable responsive-media companion markup');

$inlineSvgArtwork = ( new HtmlTransformer() )->transform(
    '<main><svg class="album-art" viewBox="0 0 100 100" role="img" aria-label="Album art"><rect width="100" height="100" fill="#111"/><circle cx="50" cy="50" r="30" fill="#c4581a"/></svg></main>'
)->toArray();
$inlineSvgMarkup = (string) ($inlineSvgArtwork['serialized_blocks'] ?? '');
$assert('core/image' === ($inlineSvgArtwork['blocks'][0]['blockName'] ?? ''), 'passive meaningful inline SVG artwork materializes as native core/image');
$assert(str_contains($inlineSvgMarkup, '<!-- wp:image'), 'passive meaningful inline SVG artwork serializes as core/image');
$assert(str_contains($inlineSvgMarkup, 'assets/materialized-svg/'), 'passive meaningful inline SVG artwork uses a materialized SVG asset URL');
$assert(str_contains((string) ($inlineSvgArtwork['assets'][0]['content'] ?? ''), '<svg'), 'passive meaningful inline SVG artwork carries sanitized SVG asset content');
$assert(str_contains($inlineSvgMarkup, 'class="wp-block-image is-resized album-art be-inline-geometry-'), 'passive meaningful inline SVG artwork preserves source class and inline line-box geometry on the image block wrapper');
$assert(str_contains($inlineSvgMarkup, 'alt="Album art"'), 'passive meaningful inline SVG artwork maps accessible label to image alt text');
$inlineSvgCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $inlineSvgArtwork['assets'] ?? array()));
$assert(str_contains($inlineSvgMarkup, 'be-inline-geometry-') && ! str_contains($inlineSvgMarkup, 'line-height:0') && str_contains($inlineSvgCss, '>img{display:inline;vertical-align:baseline}'), 'default-inline SVG core/image restores the source baseline over WordPress image alignment');

$exportedSvgArtwork = ( new HtmlTransformer() )->transform(
    '<main><svg version="1.1" class="quote-icon" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px" viewBox="0 0 25.666 20.188" enable-background="new 0 0 25.666 20.188" xml:space="preserve"><g><path d="M9.33,9.33H4.814V0h9.33V9.33z"></path></g></svg></main>'
)->toArray();
$exportedSvgMarkup = (string) ($exportedSvgArtwork['serialized_blocks'] ?? '');
$assert('core/image' === ($exportedSvgArtwork['blocks'][0]['blockName'] ?? '') && ! str_contains($exportedSvgMarkup, '<!-- wp:html'), 'passive exported SVG metadata remains native core/image artwork');

$annotatedSvgArtwork = ( new HtmlTransformer() )->transform(
    '<main><svg viewBox="0 0 24 24" data-producer-node="vector" data-source-node-id="icon:1"><path d="M2 12h20"></path></svg></main>'
)->toArray();
$assert('core/image' === ($annotatedSvgArtwork['blocks'][0]['blockName'] ?? '') && array() === ($annotatedSvgArtwork['fallbacks'] ?? array()), 'passive SVG producer data attributes remain native core/image metadata');

$positionedFillSvg = ( new HtmlTransformer() )->transform(
    '<style>.hero-media{position:relative;width:1280px;height:760px}@media(max-width:700px){.hero-media{width:320px;height:240px}}</style><main><div class="hero-media"><svg class="hero-art" width="100%" height="100%" style="object-fit:cover" viewBox="0 0 1280 728.88"><rect width="1280" height="728.88" fill="#111"/></svg></div></main>'
)->toArray();
$positionedFillMarkup = (string) ($positionedFillSvg['serialized_blocks'] ?? '');
$positionedFillCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $positionedFillSvg['assets'] ?? array()));
$positionedFillRoundTrip = ( new \Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime() )->serializeBlocks(( new \Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime() )->parseBlocks($positionedFillMarkup));
$assert(str_contains($positionedFillMarkup, 'wp-block-image hero-art be-inline-geometry-') && str_contains($positionedFillCss, '.wp-block-image.be-inline-geometry-') && str_contains($positionedFillCss, '>img{width:100%;height:100%;-o-object-fit:cover;object-fit:cover}'), 'positioned SVG fill serializes native image wrapper and image rules with greater specificity than WordPress core intrinsic-image CSS');
$assert(str_contains($positionedFillRoundTrip, 'wp-block-image hero-art be-inline-geometry-'), 'positioned SVG fill survives the serialized WordPress block parse/save contract without dropping its native fill carrier');

// The parent-fill intent reads the same whether object-fit is authored inline or
// in a stylesheet: `object-fit` is a resolvable presentation declaration, so a
// `.hero-art svg{object-fit:cover}` rule drives the fill carrier exactly as the
// inline declaration above does, instead of collapsing to intrinsic geometry.
$stylesheetFillSvg = ( new HtmlTransformer() )->transform(
    '<style>.hero{position:relative;width:1280px;height:760px}.hero-art{position:absolute;inset:0;width:100%;height:100%}.hero-art svg{object-fit:cover}</style><main><section class="hero"><div class="hero-art"><svg width="100%" height="100%" viewBox="0 0 1280 728.88"><rect width="1280" height="728.88" fill="#111"/></svg></div></section></main>'
)->toArray();
$stylesheetFillMarkup = (string) ($stylesheetFillSvg['serialized_blocks'] ?? '');
$stylesheetFillCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $stylesheetFillSvg['assets'] ?? array()));
$assert(str_contains($stylesheetFillMarkup, 'class="wp-block-image be-inline-geometry-') && str_contains($stylesheetFillCss, 'line-height:0'), 'stylesheet-sourced object-fit drives the SVG parent-fill carrier and drops the image line box');
$assert(str_contains($stylesheetFillCss, '{margin:0;width:100%;height:100%;line-height:0}') && str_contains($stylesheetFillCss, '>img{width:100%;height:100%;-o-object-fit:cover;object-fit:cover}'), 'stylesheet-sourced object-fit projects the same fill rules as an inline object-fit declaration');
$assert(! str_contains($stylesheetFillMarkup, 'is-resized') && ! str_contains($stylesheetFillMarkup, 'style="width:100%"'), 'stylesheet-sourced object-fit no longer falls back to intrinsic resized geometry');

$cssOwnedSvgFill = ( new HtmlTransformer() )->transform(
    '<style>.grid-scene,.flex-scene{width:640px;height:1496px}.grid-scene{display:grid}.flex-scene{display:flex}.grid-scene svg,.flex-scene svg{width:100%;height:100%}</style><main><div class="grid-scene"><svg class="grid-art" viewBox="0 0 700 780" preserveAspectRatio="xMidYMid slice"><rect width="700" height="780" fill="#111"/></svg></div><div class="flex-scene"><svg class="flex-art" viewBox="0 0 700 780" preserveAspectRatio="xMidYMid slice"><rect width="700" height="780" fill="#111"/></svg></div></main>'
)->toArray();
$cssOwnedSvgFillCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $cssOwnedSvgFill['assets'] ?? array()));
$assert(str_contains($cssOwnedSvgFillCss, '.grid-scene :where(figure)') && str_contains($cssOwnedSvgFillCss, '.flex-scene :where(figure)') && str_contains($cssOwnedSvgFillCss, '.wp-block-image > img{display:block;width:100%;height:100%;max-width:100%;object-fit:inherit'), 'CSS-owned slice SVG selectors project their media box onto native images in sized grid and flex parents');
$assert(str_contains($cssOwnedSvgFillCss, '.wp-block-image > img{display:block;width:100%;height:100%;max-width:100%;object-fit:inherit'), 'CSS-owned slice SVG projection does not add object-fit over the source preserveAspectRatio behavior');

$intrinsicSvgArtwork = ( new HtmlTransformer() )->transform(
    '<style>.intrinsic-scene{display:grid;width:640px;height:1496px}.intrinsic-scene svg{color:#111}</style><main><div class="intrinsic-scene"><svg class="intrinsic-art" viewBox="0 0 700 780" preserveAspectRatio="xMidYMid slice"><rect width="700" height="780" fill="currentColor"/></svg></div></main>'
)->toArray();
$intrinsicSvgCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $intrinsicSvgArtwork['assets'] ?? array()));
$assert(! str_contains($intrinsicSvgCss, '.intrinsic-scene .wp-block-image > img'), 'intrinsic slice SVGs without CSS-owned width and height do not receive fill-image projection');

$flexItemSvgArtwork = ( new HtmlTransformer() )->transform(
    '<style>.signal-icon{width:26px;height:26px;display:flex;align-items:center;justify-content:center}</style><div class="signal-icon" style="background:#7657ff"><svg width="14" height="14" viewBox="0 0 14 14"><circle cx="7" cy="7" r="6"/></svg></div>'
)->toArray();
$flexItemSvgWrapper = $flexItemSvgArtwork['blocks'][0] ?? array();
$flexItemSvg = $flexItemSvgArtwork['blocks'][0]['innerBlocks'][0] ?? array();
$assert('core/group' === ($flexItemSvgWrapper['blockName'] ?? '') && array() === ($flexItemSvgArtwork['source_reports']['generated_blocks'] ?? array()), 'single-child flex media wrappers retain source layout ownership through core/group');
$assert(str_contains((string) ($flexItemSvg['attrs']['className'] ?? ''), 'be-inline-geometry-') && ! isset($flexItemSvg['attrs']['style']['typography']['lineHeight']), 'standalone SVG flex items carry metadata-skipped baseline styling outside native image attributes');

$classSizedInlineSvgArtwork = ( new HtmlTransformer() )->transform(
    '<style>.map-art{width:100%}</style><main><div><svg class="map-art" viewBox="0 0 440 280" role="img" aria-label="Map"><rect width="440" height="280" fill="#111"/></svg></div></main>'
)->toArray();
$classSizedInlineSvgCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $classSizedInlineSvgArtwork['assets'] ?? array()));
$assert(str_contains($classSizedInlineSvgCss, '>img{display:inline;vertical-align:baseline;width:100%}'), 'inline SVG core/image applies class-owned responsive width to the image element');

$emptyVisualCluster = ( new HtmlTransformer() )->transform(
    '<style>.titlebar-dots{display:flex;gap:5px}.titlebar-dots span{width:10px;height:10px;border-radius:50%}.titlebar-dots span:nth-child(1){background:#ff5f57}.titlebar-dots span:nth-child(2){background:#ffbd2e}.titlebar-dots span:nth-child(3){background:#28ca41}</style><div class="titlebar-dots"><span></span><span></span><span></span></div>'
)->toArray();
$emptyVisualItems = $emptyVisualCluster['blocks'][0]['innerBlocks'] ?? array();
$emptyVisualCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $emptyVisualCluster['assets'] ?? array()));
$assert(3 === count($emptyVisualItems), 'classless empty inline items in a decorative cluster remain native blocks');
$assert(! array_filter($emptyVisualItems, static fn (array $block): bool => 'core/spacer' !== ($block['blockName'] ?? '') || '10px' !== ($block['attrs']['height'] ?? '') || '10px' !== ($block['attrs']['width'] ?? '')), 'decorative cluster items use direct native spacer carriers with source dimensions');
$assert(str_contains($emptyVisualCss, 'blocks-engine-semantic-') && str_contains($emptyVisualCss, '{width:10px;height:10px;border-radius:50%}') && str_contains($emptyVisualCss, 'background:#ff5f57') && str_contains($emptyVisualCss, 'background:#ffbd2e') && str_contains($emptyVisualCss, 'background:#28ca41'), 'decorative cluster items preserve projected selectors, dimensions, and background paint through author CSS');
$assert(str_contains($emptyVisualCss, 'blocks-engine-css-owned-flow') && str_contains($emptyVisualCss, 'margin-block-start:0'), 'decorative cluster neutralizes only the marked core group flow defaults');

$cssSizedInlineSvgArtwork = ( new HtmlTransformer() )->transform(
    '<style>.album-cover{width:100%;max-width:380px;aspect-ratio:1;display:block;box-shadow:0 40px 80px rgba(0,0,0,.6)}</style><main><div class="album-card"><svg class="album-cover" viewBox="0 0 500 500" role="img" aria-label="Album cover"><rect width="500" height="500" fill="#111"/></svg></div></main>'
)->toArray();
$cssSizedInlineSvgArtworkMarkup = (string) ($cssSizedInlineSvgArtwork['serialized_blocks'] ?? '');
$cssSizedInlineSvgArtworkCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $cssSizedInlineSvgArtwork['assets'] ?? array()));
$assert(str_contains($cssSizedInlineSvgArtworkMarkup, 'class="wp-block-image album-cover be-inline-geometry-') && str_contains($cssSizedInlineSvgArtworkMarkup, 'blocks-engine-synthetic-image-figure'), 'CSS-sized inline SVG artwork preserves the media class on the native image wrapper');
$assert(! str_contains($cssSizedInlineSvgArtworkMarkup, 'is-resized album-cover'), 'CSS-sized inline SVG artwork does not add resized wrapper geometry over source CSS');
$assert(! str_contains($cssSizedInlineSvgArtworkMarkup, 'style="width:500px;height:500px"'), 'CSS-sized inline SVG artwork does not force intrinsic SVG dimensions over source CSS sizing');
$assert(str_contains($cssSizedInlineSvgArtworkCss, 'line-height:0') && str_contains($cssSizedInlineSvgArtworkCss, '>img{display:block;width:100%;max-width:380px;aspect-ratio:1}'), 'explicit block SVG core/image carries metadata-skipped line-box geometry with its materialized image rules');

$artifactInlineSvg = ( new ArtifactCompiler() )->compile(
    array(
        'entrypoint' => 'website/index.html',
        'files'      => array(
            'website/index.html' => '<main><div><svg class="feature-icon" viewBox="0 0 48 48" aria-hidden="true"><rect width="48" height="48"/></svg><h3>Feature</h3></div></main>',
        ),
    )
)->toArray();
$artifactInlineSvgMarkup = (string) ($artifactInlineSvg['serialized_blocks'] ?? '');
$assert(str_contains($artifactInlineSvgMarkup, '<!-- wp:paragraph') && str_contains($artifactInlineSvgMarkup, 'assets/materialized-svg/'), 'source-relative materialized SVG remains a native RichText image object');
$assert(! str_contains($artifactInlineSvgMarkup, '<!-- wp:html'), 'source-relative materialized SVG does not fall back to core/html');

$largeCssSizedInlineSvgArtwork = ( new HtmlTransformer() )->transform(
    '<style>.hero-cover{width:100%;max-width:380px;aspect-ratio:1;display:block}</style><main><svg class="hero-cover" viewBox="0 0 500 500" role="img" aria-label="Hero cover">' . str_repeat('<rect width="500" height="500" fill="#111"/>', 2000) . '</svg></main>'
)->toArray();
$largeCssSizedInlineSvgArtworkMarkup = (string) ($largeCssSizedInlineSvgArtwork['serialized_blocks'] ?? '');
$assert(str_contains($largeCssSizedInlineSvgArtworkMarkup, '<!-- wp:image'), 'large CSS-sized inline SVG artwork materializes as native image without a data URI budget');
$assert(! str_contains($largeCssSizedInlineSvgArtworkMarkup, '<svg class="hero-cover" viewBox="0 0 500 500" role="img" aria-label="Hero cover" width="500" height="500"'), 'large CSS-sized inline SVG artwork does not inject intrinsic SVG dimensions over source CSS sizing');

$percentageWidthSvg = ( new HtmlTransformer() )->transform('<main><svg width="100%" viewBox="0 0 620 380" role="img" aria-label="Responsive map"><rect width="620" height="380" fill="#111"/></svg></main>')->toArray();
$percentageWidthSvgMarkup = (string) ($percentageWidthSvg['serialized_blocks'] ?? '');
$assert(str_contains($percentageWidthSvgMarkup, 'style="width:100%"') && ! str_contains($percentageWidthSvgMarkup, 'height:auto') && ! str_contains($percentageWidthSvgMarkup, 'height:380px') && str_contains((string) ($percentageWidthSvg['assets'][0]['content'] ?? ''), 'viewBox="0 0 620 380"'), 'percentage-width inline SVG core/image matches the WordPress 7.0.4 width-only save shape');

$fractionalPercentageWidthSvg = ( new HtmlTransformer() )->transform('<main><svg width=".5%" viewBox="0 0 620 380" role="img" aria-label="Fractional responsive map"><rect width="620" height="380" fill="#111"/></svg></main>')->toArray();
$fractionalPercentageWidthSvgMarkup = (string) ($fractionalPercentageWidthSvg['serialized_blocks'] ?? '');
$assert(str_contains($fractionalPercentageWidthSvgMarkup, 'style="width:.5%"') && ! str_contains($fractionalPercentageWidthSvgMarkup, 'height:auto') && ! str_contains($fractionalPercentageWidthSvgMarkup, 'height:380px'), 'fractional percentage-width inline SVG core/image uses the WordPress 7.0.4 width-only save shape');

$signedPercentageWidthSvg = ( new HtmlTransformer() )->transform('<main><svg width="+.5%" viewBox="0 0 620 380" role="img" aria-label="Signed responsive map"><rect width="620" height="380" fill="#111"/></svg></main>')->toArray();
$signedPercentageWidthSvgMarkup = (string) ($signedPercentageWidthSvg['serialized_blocks'] ?? '');
$assert(str_contains($signedPercentageWidthSvgMarkup, 'style="width:+.5%"') && ! str_contains($signedPercentageWidthSvgMarkup, 'height:auto') && ! str_contains($signedPercentageWidthSvgMarkup, 'height:380px'), 'signed fractional percentage-width inline SVG core/image uses the WordPress 7.0.4 width-only save shape');

$exponentPercentageWidthSvg = ( new HtmlTransformer() )->transform('<main><svg width="1e2%" viewBox="0 0 620 380" role="img" aria-label="Exponent responsive map"><rect width="620" height="380" fill="#111"/></svg></main>')->toArray();
$exponentPercentageWidthSvgMarkup = (string) ($exponentPercentageWidthSvg['serialized_blocks'] ?? '');
$assert(str_contains($exponentPercentageWidthSvgMarkup, 'style="width:1e2%"') && ! str_contains($exponentPercentageWidthSvgMarkup, 'height:auto') && ! str_contains($exponentPercentageWidthSvgMarkup, 'height:380px'), 'exponent percentage-width inline SVG core/image uses the WordPress 7.0.4 width-only save shape');

$negativePercentageWidthSvg = ( new HtmlTransformer() )->transform('<main><svg width="-1%" viewBox="0 0 620 380" role="img" aria-label="Invalid negative responsive map"><rect width="620" height="380" fill="#111"/></svg></main>')->toArray();
$negativePercentageWidthSvgMarkup = (string) ($negativePercentageWidthSvg['serialized_blocks'] ?? '');
$assert(! str_contains($negativePercentageWidthSvgMarkup, 'width:-1%') && str_contains($negativePercentageWidthSvgMarkup, 'height:380px'), 'negative SVG percentage width is rejected as invalid non-negative SVG geometry');

$fixedBackgroundLayer = ( new HtmlTransformer() )->transform(
    '<style>.page-bg{position:fixed;inset:0;z-index:-1;background:linear-gradient(180deg,#211,#000)}</style><main><div class="page-bg" aria-hidden="true"></div><section class="hero"><h1>Hero</h1></section></main>'
)->toArray();
$fixedBackgroundLayerMarkup = (string) ($fixedBackgroundLayer['serialized_blocks'] ?? '');
$assert(str_contains($fixedBackgroundLayerMarkup, 'page-bg'), 'fixed background visual layer keeps its CSS-addressable class');
$assert(1 === preg_match('/<div class="[^"]*wp-block-group[^"]*page-bg[^"]*"/', $fixedBackgroundLayerMarkup), 'fixed background visual layer materializes as an empty group wrapper for source CSS');
$fixedBackgroundEditorCss = implode("\n", array_map(static fn (array $asset): string => 'editor-static-state' === ($asset['source'] ?? '') ? (string) ($asset['content'] ?? '') : '', $fixedBackgroundLayer['assets'] ?? array()));
$assert(str_contains($fixedBackgroundLayerMarkup, 'blocks-engine-empty-visual-group') && str_contains($fixedBackgroundEditorCss, '.blocks-engine-empty-visual-group.wp-block-group__placeholder{position:relative!important;inset:auto!important'), 'empty painted groups retain frontend geometry while their Gutenberg placeholder is bounded in normal flow');
$assert(str_contains($fixedBackgroundEditorCss, '.blocks-engine-empty-visual-group.wp-block-group__placeholder>*{display:none!important}'), 'painted source layers withhold core empty-group variation pickers so they do not stack layout controls in the editor');
// Reserving height for a withheld picker displaces every following block, which
// moves the whole source composition down the editor canvas.
$assert(str_contains($fixedBackgroundEditorCss, 'min-height:0!important') && ! str_contains($fixedBackgroundEditorCss, '.blocks-engine-empty-visual-group.wp-block-group__placeholder{position:relative!important;inset:auto!important;width:auto!important;height:auto!important;min-height:2rem'), 'painted source layers reserve no editor height for the picker they withhold');

$styleOnlyVisualShell = ( new HtmlTransformer() )->transform(
    '<style>.footer-wrap{background:#000}.footer-wrap .container{padding:40px 0}</style><main><div class="footer-wrap"><div class="container"><style>.footer-wrap{min-height:80px}</style></div></div></main>'
)->toArray();
$styleOnlyVisualShellMarkup = (string) ($styleOnlyVisualShell['serialized_blocks'] ?? '');
$assert(1 === preg_match('/<div class="[^"]*wp-block-group[^"]*footer-wrap[^"]*"/', $styleOnlyVisualShellMarkup), 'visual shell containing only stylesheet metadata keeps its outer source wrapper');
$assert(1 === preg_match('/<div class="[^"]*wp-block-group[^"]*container[^"]*"/', $styleOnlyVisualShellMarkup), 'visual shell containing only stylesheet metadata keeps its nested source wrapper');
$assert(! str_contains($styleOnlyVisualShellMarkup, '<style') && ! str_contains($styleOnlyVisualShellMarkup, '<!-- wp:html'), 'stylesheet metadata does not materialize as visible block content');

$classOwnedGrid = ( new HtmlTransformer() )->transform('<style>.hero-inner{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(260px,.9fr);gap:4rem}</style><main><div class="hero-inner"><div>Text</div><div>Art</div></div></main>')->toArray();
$classOwnedGridMarkup = (string) ($classOwnedGrid['serialized_blocks'] ?? '');
$assert(str_contains($classOwnedGridMarkup, 'hero-inner'), 'class-owned CSS grid keeps the source class');
$assert(! str_contains($classOwnedGridMarkup, 'is-layout-grid'), 'class-owned CSS grid avoids WP layout classes that override exact source tracks');

$explicitGridPlacement = ( new HtmlTransformer() )->transform('<style>.essay{display:grid;grid-template-columns:1fr minmax(0,900px) 320px;gap:3rem}.essay__body{grid-column:2}.essay__side{grid-column:3}</style><main><div class="essay"><article class="essay__body">Body</article><aside class="essay__side">Sidebar</aside></div></main>')->toArray();
$explicitGridPlacementMarkup = (string) ($explicitGridPlacement['serialized_blocks'] ?? '');
$assert(str_contains($explicitGridPlacementMarkup, 'wp-block-group') && str_contains($explicitGridPlacementMarkup, 'essay') && str_contains($explicitGridPlacementMarkup, 'blocks-engine-css-owned-layout'), 'explicitly positioned grid children retain their core group track container');
$assert(! str_contains($explicitGridPlacementMarkup, '<!-- wp:columns'), 'explicitly positioned grid children do not become flex-based core columns');

$classOwnedFlex = ( new HtmlTransformer() )->transform('<style>.hero{display:flex;align-items:center;min-height:100vh}</style><main><section class="hero"><div>Text</div></section></main>')->toArray();
$classOwnedFlexMarkup = (string) ($classOwnedFlex['serialized_blocks'] ?? '');
$assert(str_contains($classOwnedFlexMarkup, 'hero'), 'class-owned CSS flex keeps the source class');
$assert(! str_contains($classOwnedFlexMarkup, 'is-layout-flex'), 'class-owned CSS flex avoids WP layout classes that override exact source layout');

$inlineBreadcrumb = ( new HtmlTransformer() )->transform(
    '<style>.crumb{padding:20px 0 0}.crumb .sep{margin:0 .6rem}</style><main><nav class="crumb" aria-label="Breadcrumb"><a href="/exhibitions">Exhibitions</a><span class="sep">/</span><span>Current</span></nav><section>Exhibition</section></main>'
)->toArray();
$inlineBreadcrumbMarkup = (string) ($inlineBreadcrumb['serialized_blocks'] ?? '');
$inlineBreadcrumbNavMarkup = strstr($inlineBreadcrumbMarkup, '</nav>', true) ?: '';
$assert(str_contains($inlineBreadcrumbMarkup, '<nav class="wp-block-group crumb"'), 'inline-only semantic navigation retains its nav group wrapper');
$assert(1 === substr_count($inlineBreadcrumbNavMarkup, '<!-- wp:paragraph'), 'inline-only semantic navigation keeps one RichText flow instead of stacking each token');
$assert(str_contains($inlineBreadcrumbMarkup, '<a href="/exhibitions">Exhibitions</a>') && str_contains($inlineBreadcrumbMarkup, '>Current<'), 'inline-only semantic navigation preserves link and text token order');

$outlineButton = ( new HtmlTransformer() )->transform(
    '<main><a class="btn btn-secondary" style="display:inline-block;padding:1rem 2rem;border:1px solid #c4a070;background:transparent;color:#eee;text-transform:uppercase" href="/tickets"><span>Tickets</span></a></main>'
)->toArray();
$outlineButtonMarkup = (string) ($outlineButton['serialized_blocks'] ?? '');
$outlineButtonCss = implode("\n", array_column($outlineButton['assets'] ?? array(), 'content'));
$assert(str_contains($outlineButtonMarkup, '<!-- wp:button'), 'styled anchor with presentational span materializes as core/button');
$assert(str_contains($outlineButtonCss, 'background-color:transparent'), 'outline button carries transparent background to suppress default theme fill');
$assert(! str_contains($outlineButtonCss, 'border-radius:0'), 'outline button does not infer a square radius from its border declarations');
$assert(! str_contains($outlineButtonMarkup, '<div class="wp-block-button btn btn-secondary'), 'outline button with native styles avoids duplicating source button chrome on the outer wrapper');
$assert(! str_contains($outlineButtonMarkup, '<span>Tickets</span>'), 'button label unwraps presentational span to avoid nested default styling');

$descendantSurfaceButton = ( new HtmlTransformer() )->transform(
    '<style>.cta{display:inline-block;border:1px solid #000}.cta .cta-inner{display:inline-block;box-sizing:border-box;min-width:170px;padding:22px 26px;background:#fff;color:#000;font:700 16px/16px Montserrat}</style><div style="text-align:center"><a class="cta" href="/learn"><span class="cta-inner">Learn more</span></a></div>'
)->toArray();
$descendantSurfaceButtonMarkup = (string) ($descendantSurfaceButton['serialized_blocks'] ?? '');
$descendantSurfaceButtonCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $descendantSurfaceButton['assets'] ?? array()));
$assert(str_contains($descendantSurfaceButtonMarkup, '<!-- wp:button') && str_contains($descendantSurfaceButtonMarkup, '"justifyContent":"center"'), 'composite button surfaces remain native and inherit source wrapper alignment');
$assert(str_contains($descendantSurfaceButtonCss, '> :where(.wp-block-button__link)') && str_contains($descendantSurfaceButtonCss, 'min-width:170px') && str_contains($descendantSurfaceButtonCss, 'padding:22px 26px'), 'composite button descendant selectors project their complete painted geometry onto the native link');
$assert('pass' === ($descendantSurfaceButton['source_reports']['wp_block_validity']['status'] ?? ''), 'composite button surface conversion remains editor-valid');

$flexAnchorButton = ( new HtmlTransformer() )->transform(
    '<style>.product-row{display:flex;align-items:center;gap:1rem;padding:1rem;background:#123456}.product-row__name{flex:1}</style><main><a class="product-row" href="/product"><span class="product-row__name">Product</span><span>$25</span></a></main>'
)->toArray();
$flexAnchorButtonAttrs = $flexAnchorButton['blocks'][0]['innerBlocks'][0]['innerBlocks'][0]['attrs'] ?? array();
$flexAnchorButtonMarkup = (string) ($flexAnchorButton['serialized_blocks'] ?? '');
$flexAnchorButtonCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $flexAnchorButton['assets'] ?? array()));
$assert(str_contains((string) ($flexAnchorButtonAttrs['className'] ?? ''), 'blocks-engine-control-') && ! str_contains((string) ($flexAnchorButtonAttrs['className'] ?? ''), 'product-row'), 'styled anchor button uses a generated control marker instead of its source anchor class');
$assert(! str_contains($flexAnchorButtonMarkup, 'wp-block-button product-row') && ! str_contains($flexAnchorButtonMarkup, 'wp-element-button product-row'), 'styled anchor button keeps source anchor classes out of canonical core/button markup');
$assert(str_contains($flexAnchorButtonCss, '> :where(.wp-block-button__link){display:flex;align-items:center;gap:1rem') && str_contains($flexAnchorButtonCss, 'blocks-engine-richtext-marker') && str_contains($flexAnchorButtonCss, '{flex:1}'), 'styled anchor root and descendant selectors project through the generated marker after lowering');
$assert(str_contains($flexAnchorButtonMarkup, 'class="product-row__name"'), 'styled anchor button preserves descendant classes in its RichText content');
$assert('pass' === ($flexAnchorButton['source_reports']['wp_block_validity']['status'] ?? ''), 'styled anchor button remains editor-valid with marker-projected source selectors');

$flexChainButton = ( new HtmlTransformer() )->transform(
    '<style>.stack{display:flex;flex-direction:column;gap:2rem}.row{display:flex;align-items:center;gap:1rem;padding:1rem;background:#123456}.row__name{flex:1}</style><main><div class="stack"><a class="row" href="/product"><span class="row__name">Product</span><span>$25</span></a></div></main>'
)->toArray();
$flexChainButtonMarkup = (string) ($flexChainButton['serialized_blocks'] ?? '');
$flexChainButtonCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $flexChainButton['assets'] ?? array()));
$assert(str_contains($flexChainButtonMarkup, 'wp-block-buttons blocks-engine-control-') && str_contains($flexChainButtonMarkup, 'wp-block-button blocks-engine-control-'), 'direct flex-child anchor carries one generated marker across both synthetic wrappers');
$assert(str_contains($flexChainButtonCss, '.wp-block-buttons){display:block!important;gap:0!important;min-width:0;width:100%!important}') && str_contains($flexChainButtonCss, '.wp-block-button){display:block!important;margin:0!important;min-width:0;width:100%!important}') && str_contains($flexChainButtonCss, '.wp-block-button__link){box-sizing:border-box;width:100%!important}'), 'direct column flex-child anchor bridges wrapper sizing while only the synthetic inner wrapper has neutral margin');
$assert('pass' === ($flexChainButton['source_reports']['wp_block_validity']['status'] ?? ''), 'direct flex-child wrapper chain remains editor-valid');

$flexAnchorAutoMargin = ( new HtmlTransformer() )->transform(
    '<style>.nav{display:flex;align-items:center;gap:1rem}.nav__brand{font-weight:700}.nav__cta{display:inline-flex;padding:.5rem 1rem;background:#123456;color:#fff;margin-right:auto}.nav__action{display:inline-flex;padding:.5rem 1rem;background:#456789;color:#fff}</style><main><nav class="nav"><a class="nav__brand" href="/">Brand</a><a class="nav__cta" href="/start">Start</a><button class="nav__action" type="button">Menu</button></nav></main>'
)->toArray();
$flexAnchorAutoMarginMarkup = (string) ($flexAnchorAutoMargin['serialized_blocks'] ?? '');
$flexAnchorAutoMarginCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $flexAnchorAutoMargin['assets'] ?? array()));
$assert(preg_match('/\.wp-block-buttons\.blocks-engine-control-[^{]+\{margin-right:auto\}/', $flexAnchorAutoMarginCss) && 2 === substr_count($flexAnchorAutoMarginMarkup, 'wp-block-buttons'), 'direct flex anchor preserves its authored auto margin on the lowered source flex-item wrapper beside navigation and button siblings');
$assert(str_contains($flexAnchorAutoMarginCss, '.wp-block-buttons){display:block!important;gap:0!important;min-width:0}') && ! str_contains($flexAnchorAutoMarginCss, '.wp-block-buttons){display:block!important;gap:0!important;margin:0!important;min-width:0}') && str_contains($flexAnchorAutoMarginCss, '.wp-block-button){display:block!important;margin:0!important;min-width:0}'), 'direct flex bridge leaves source wrapper margins intact while neutralizing only the synthetic inner wrapper');
$assert('pass' === ($flexAnchorAutoMargin['source_reports']['wp_block_validity']['status'] ?? ''), 'direct flex anchor with auto margin remains editor-valid beside navigation and button siblings');

$anchorButtonMarginCases = array(
    'directional' => array(
        'source'   => 'margin-left:2rem;margin-right:3rem',
        'expected' => array( 'right' => '3rem', 'left' => '2rem' ),
        'css'      => 'margin-left:2rem;margin-right:3rem',
    ),
    'shorthand' => array(
        'source'   => 'margin:1rem 2rem 3rem 4rem',
        'expected' => array( 'top' => '1rem', 'right' => '2rem', 'bottom' => '3rem', 'left' => '4rem' ),
        'css'      => 'margin:1rem 2rem 3rem 4rem',
    ),
);
foreach ( $anchorButtonMarginCases as $marginCase => $margin ) {
    $directFlexMarginButton = ( new HtmlTransformer() )->transform(
        '<style>.stack{display:flex;flex-direction:column}.cta{display:inline-flex;padding:1rem;background:#123456;' . $margin['source'] . '}</style><main><div class="stack"><a class="cta" href="/start">Start</a></div></main>'
    )->toArray();
    $directFlexMarginWrapper = $directFlexMarginButton['blocks'][0]['innerBlocks'][0] ?? array();
    $directFlexMarginInner = $directFlexMarginWrapper['innerBlocks'][0] ?? array();
    $directFlexMarginCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $directFlexMarginButton['assets'] ?? array()));
    $assert(! isset($directFlexMarginInner['attrs']['style']['spacing']['margin']) && str_contains($directFlexMarginCss, '.wp-block-button){display:block!important;margin:0!important;min-width:0;width:100%!important}'), 'direct-flex ' . $marginCase . ' anchor keeps the synthetic inner core/button margin-neutral');
    $assert(str_contains($directFlexMarginCss, $margin['css']) && preg_match('/\.wp-block-buttons\.blocks-engine-control-[^{]+\{margin-right:' . preg_quote($margin['expected']['right'], '/') . ';margin-left:' . preg_quote($margin['expected']['left'], '/') . '\}/', $directFlexMarginCss), 'direct-flex ' . $marginCase . ' anchor preserves authored outer margin priority without !important');

    $fullWidthMarginButton = ( new HtmlTransformer() )->transform(
        '<style>.cta{display:inline-flex;padding:1rem;background:#123456;width:100%;' . $margin['source'] . '}</style><main><section><a class="cta" href="/start">Start</a></section></main>'
    )->toArray();
    $fullWidthMarginWrapper = $fullWidthMarginButton['blocks'][0] ?? array();
    $fullWidthMarginInner = $fullWidthMarginWrapper['innerBlocks'][0] ?? array();
    $fullWidthMarginCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $fullWidthMarginButton['assets'] ?? array()));
    $assert(! isset($fullWidthMarginInner['attrs']['style']['spacing']['margin']) && str_contains($fullWidthMarginCss, '.wp-block-button){display:block!important;margin:0!important;width:100%!important}'), 'full-width ' . $marginCase . ' anchor keeps the synthetic inner core/button margin-neutral');
    $assert(str_contains($fullWidthMarginCss, $margin['css']) && preg_match('/\.wp-block-buttons\.blocks-engine-control-[^{]+\{margin-right:' . preg_quote($margin['expected']['right'], '/') . ';margin-left:' . preg_quote($margin['expected']['left'], '/') . '\}/', $fullWidthMarginCss), 'full-width ' . $marginCase . ' anchor preserves authored outer margin priority without !important');
}

$fullWidthAnchorButton = ( new HtmlTransformer() )->transform(
    '<style>.btn{display:inline-flex;align-items:center;padding:1rem;background:#123456}.btn--full{width:100%}</style><main><section><a class="btn btn--full selector-submit" href="/submit">Submit</a></section></main>'
)->toArray();
$fullWidthAnchorButtonMarkup = (string) ($fullWidthAnchorButton['serialized_blocks'] ?? '');
$fullWidthAnchorButtonCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $fullWidthAnchorButton['assets'] ?? array()));
$assert(! str_contains($fullWidthAnchorButtonMarkup, 'has-custom-width') && ! str_contains($fullWidthAnchorButtonMarkup, 'wp-block-button__width-100') && str_contains($fullWidthAnchorButtonMarkup, 'blocks-engine-control-'), 'styled full-width anchor uses the WordPress 7.1 core/button save shape and its generated marker');
$assert(! str_contains($fullWidthAnchorButtonMarkup, 'wp-block-button selector-submit') && ! str_contains($fullWidthAnchorButtonMarkup, 'wp-element-button selector-submit'), 'styled full-width anchor without descendants keeps authored root classes out of canonical button markup');
$assert(str_contains($fullWidthAnchorButtonCss, '.wp-block-buttons){display:block!important;gap:0!important;width:100%!important}') && str_contains($fullWidthAnchorButtonCss, '.wp-block-button){display:block!important;margin:0!important;width:100%!important}') && str_contains($fullWidthAnchorButtonCss, '.wp-block-button__link){box-sizing:border-box;width:100%!important}'), 'styled full-width anchor bridges width through every synthetic wrapper while preserving source wrapper margins');
$assert('pass' === ($fullWidthAnchorButton['source_reports']['wp_block_validity']['status'] ?? ''), 'styled full-width anchor wrapper chain remains editor-valid');

$fullWidthNativeButton = ( new HtmlTransformer() )->transform(
    '<style>button{background:none;border:none}.btn{display:inline-flex;align-items:center;padding:1rem;background:#123456}.btn--full{width:100%}</style><main><section><button class="btn btn--full selector-submit" type="button">Submit</button></section></main>'
)->toArray();
$fullWidthNativeButtonMarkup = (string) ($fullWidthNativeButton['serialized_blocks'] ?? '');
$fullWidthNativeButtonAttrs = $fullWidthNativeButton['blocks'][0]['innerBlocks'][0]['attrs'] ?? array();
$fullWidthNativeButtonCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $fullWidthNativeButton['assets'] ?? array()));
$assert(! isset($fullWidthNativeButtonAttrs['width']) && str_contains((string) ($fullWidthNativeButtonAttrs['className'] ?? ''), 'blocks-engine-control-') && ! str_contains((string) ($fullWidthNativeButtonAttrs['className'] ?? ''), 'selector-submit'), 'styled full-width native button omits the legacy width attribute and uses a generated marker instead of source root classes');
$assert(! str_contains($fullWidthNativeButtonMarkup, 'wp-block-button selector-submit') && ! str_contains($fullWidthNativeButtonMarkup, 'wp-element-button selector-submit'), 'styled full-width native button keeps source root classes out of canonical markup');
$assert(! str_contains((string) ($fullWidthNativeButtonAttrs['className'] ?? ''), 'is-style-outline') && ! isset($fullWidthNativeButtonAttrs['style']['color']['background']) && str_contains($fullWidthNativeButtonCss, 'background-color:#123456!important'), 'a filled button variant carries its fill after an earlier native-button background reset without becoming an outline control');
$assert(str_contains($fullWidthNativeButtonCss, '.wp-block-buttons){display:block!important;gap:0!important;width:100%!important}') && str_contains($fullWidthNativeButtonCss, '.wp-block-button__link){box-sizing:border-box;width:100%!important}'), 'styled full-width native button projects root geometry through the wrapper chain without overriding source wrapper margins');
$assert('pass' === ($fullWidthNativeButton['source_reports']['wp_block_validity']['status'] ?? ''), 'styled full-width native button wrapper chain remains editor-valid');

$contextualSurfaceButton = ( new HtmlTransformer() )->transform(
    '<style>.cta{display:inline-block;border:1px solid #000}.cta .cta-inner{display:inline-block;min-width:170px;padding:22px 26px;border-radius:0;background-color:#00ff8e;color:#000;font-size:16px;line-height:1;font-weight:700}.highlight .cta-inner{background:#fff;color:#000}</style><div style="text-align:center"><a class="cta highlight" href="/learn"><span class="cta-inner">Learn more</span></a></div>'
)->toArray();
$contextualSurfaceButtonAttrs = $contextualSurfaceButton['blocks'][0]['innerBlocks'][0]['attrs'] ?? array();
$contextualSurfaceButtonCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $contextualSurfaceButton['assets'] ?? array()));
$assert(! isset($contextualSurfaceButtonAttrs['style']['color']['background']) && str_contains($contextualSurfaceButtonCss, 'background-color:#fff!important'), 'later contextual background shorthand carries over an earlier descendant background color');
$assert(! isset($contextualSurfaceButtonAttrs['style']['border']['radius']) && str_contains($contextualSurfaceButtonCss, 'border-radius:0!important'), 'authored square button borders carry the suppression of rounded theme defaults');
$assert(! str_contains((string) ($contextualSurfaceButtonAttrs['className'] ?? ''), 'cta-inner'), 'descendant presentation classes do not paint the structural core button wrapper');
$assert(str_contains($contextualSurfaceButtonCss, 'background-color:#fff!important') && str_contains($contextualSurfaceButtonCss, 'color:#000!important'), 'native button control rule protects resolved source paint from theme defaults');

$declarativeCounter = ( new HtmlTransformer() )->transform(
    '<div id="element-counter-one"><div class="counter-number"><div class="content-number-bold"></div></div><div>YEARS</div></div><script>var PlatformElementSettings = true; _Element.prototype.settings = new PlatformElementSettings({"end":1350,"duration":2}); _Element.prototype.element_id = "counter-one";</script>'
)->toArray();
$assert(str_contains((string) ($declarativeCounter['serialized_blocks'] ?? ''), '>1350<'), 'bounded declarative counter settings materialize their final numeric state as editable content');
$externalDeclarativeCounter = ( new HtmlTransformer() )->transform(
    '<div id="element-counter-two"><div class="content-number-bold"></div><div>CLIENTS</div></div>',
    array( 'declarative_state_html' => '<script>var PlatformElementSettings = true; _Element.prototype.settings = new PlatformElementSettings({"end":27000}); _Element.prototype.element_id = "counter-two";</script>' )
)->toArray();
$assert(str_contains((string) ($externalDeclarativeCounter['serialized_blocks'] ?? ''), '>27000<'), 'declarative counter state materializes when artifact safety removes executable scripts before conversion');

$unlinkedWrappedImage = ( new HtmlTransformer() )->transform('<div class="image"><a><img src="testimonial.jpg" alt="Clients"></a><div class="caption"></div></div>')->toArray();
$unlinkedWrappedImageMarkup = (string) ($unlinkedWrappedImage['serialized_blocks'] ?? '');
$assert(str_contains($unlinkedWrappedImageMarkup, '<!-- wp:image') && str_contains($unlinkedWrappedImageMarkup, 'src="testimonial.jpg"'), 'image-only anchors without href preserve their image as a native block');

$imageCarrierButton = ( new HtmlTransformer() )->transform('<main><button class="gallery-trigger" type="button"><div class="gallery-frame"><media-image class="source-image"><img src="product.jpg" alt="Product"></media-image></div><svg aria-hidden="true"><path d="M0 0h1v1z"/></svg></button></main>')->toArray();
$imageCarrierButtonMarkup = (string) ($imageCarrierButton['serialized_blocks'] ?? '');
$assert(str_contains($imageCarrierButtonMarkup, '<!-- wp:image') && str_contains($imageCarrierButtonMarkup, 'src="product.jpg"'), 'an unlabeled image carrier control preserves its nested image as a native block');
$assert(! str_contains($imageCarrierButtonMarkup, '<!-- wp:button'), 'an unlabeled image carrier control does not route media through core/button RichText');
$multiImageCarrierButton = ( new HtmlTransformer() )->transform('<main><button class="gallery-trigger" type="button"><media-image><img src="one.jpg" alt="Product"></media-image><media-image><img src="two.jpg" alt="Product"></media-image></button></main>')->toArray();
$multiImageCarrierButtonMarkup = (string) ($multiImageCarrierButton['serialized_blocks'] ?? '');
$assert(2 === substr_count($multiImageCarrierButtonMarkup, '<!-- wp:image') && str_contains($multiImageCarrierButtonMarkup, 'src="one.jpg"') && str_contains($multiImageCarrierButtonMarkup, 'src="two.jpg"'), 'an unlabeled multi-image gallery control preserves every nested image as native blocks');
$assert(! str_contains($multiImageCarrierButtonMarkup, '<!-- wp:button'), 'an unlabeled multi-image gallery control does not flatten image alternatives into button RichText');

$dataAncestryLayout = ( new HtmlTransformer() )->transform('<style>[data-layout="grid"]{display:grid;grid-template-rows:100px}[data-layout="grid"] > [id="hero"]{position:relative;grid-area:2 / 1 / 3 / 2}</style><main><div data-layout="grid"><section id="hero"><p>Hero</p></section></div></main>')->toArray();
$dataAncestryCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $dataAncestryLayout['assets'] ?? array()));
$assert(str_contains($dataAncestryCss, ':where(#hero)') && str_contains($dataAncestryCss, 'position:relative'), 'source-proven data-attribute ancestry projects onto a surviving matched element ID');
$assert(str_contains($dataAncestryCss, ':where(.blocks-engine-attribute-') && ! str_contains($dataAncestryCss, '[data-layout="grid"]{') && str_contains((string) ($dataAncestryLayout['serialized_blocks'] ?? ''), 'blocks-engine-attribute-'), 'direct data-attribute layout selectors project through deterministic structural marker classes');

$dataAttributeFlex = ( new HtmlTransformer() )->transform('<style>[data-label="copy"]{flex-grow:1}</style><main><div style="display:flex"><div data-label="copy"><p>A flexible label</p></div></div></main>')->toArray();
$dataAttributeFlexCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $dataAttributeFlex['assets'] ?? array()));
$assert(str_contains($dataAttributeFlexCss, ':where(.blocks-engine-attribute-') && str_contains($dataAttributeFlexCss, 'flex-grow:1') && ! str_contains($dataAttributeFlexCss, '[data-label="copy"]'), 'data-attribute selectors carrying flex growth project through deterministic structural marker classes');
$assert(str_contains((string) ($dataAttributeFlex['serialized_blocks'] ?? ''), 'blocks-engine-attribute-') && 'pass' === ($dataAttributeFlex['source_reports']['wp_block_validity']['status'] ?? ''), 'flex growth attribute projection survives valid Gutenberg serialization');

$dataAttributePointerTarget = ( new HtmlTransformer() )->transform('<style>[data-mesh-id$="inlineContent"]{pointer-events:none;position:relative}[data-mesh-id$="gridContainer"]{display:grid}[data-mesh-id$="gridContainer"] > *{pointer-events:auto}</style><main><div data-mesh-id="header-inlineContent"><div data-mesh-id="header-gridContainer"><div id="menu"><a href="/contact">Contact</a></div><p id="copy">Copy</p></div></div></main>')->toArray();
$dataAttributePointerTargetCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $dataAttributePointerTarget['assets'] ?? array()));
$assert(str_contains($dataAttributePointerTargetCss, ':where(.blocks-engine-attribute-') && str_contains($dataAttributePointerTargetCss, ':where(#menu)') && str_contains($dataAttributePointerTargetCss, 'pointer-events:none') && str_contains($dataAttributePointerTargetCss, 'pointer-events:auto'), 'data-attribute interaction boundaries retain disabled-container and enabled-child hit targeting after selector projection');
$assert(str_contains((string) ($dataAttributePointerTarget['serialized_blocks'] ?? ''), 'blocks-engine-attribute-') && 'pass' === ($dataAttributePointerTarget['source_reports']['wp_block_validity']['status'] ?? ''), 'pointer-event attribute projection survives valid Gutenberg serialization');

$mixedIdentityPointerTarget = ( new HtmlTransformer() )->transform('<style>[data-mesh-id$="-gridContainer"] > *{pointer-events:auto}</style><main><div data-mesh-id="header-gridContainer"><div id="menu"><a href="/contact">Contact</a></div><div><p>Copy</p></div></div></main>')->toArray();
$mixedIdentityPointerCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $mixedIdentityPointerTarget['assets'] ?? array()));
$assert(str_contains($mixedIdentityPointerCss, ':where(#menu)') && str_contains($mixedIdentityPointerCss, ':where(.blocks-engine-attribute-') && ! str_contains($mixedIdentityPointerCss, '[data-mesh-id$="-gridContainer"]'), 'data-attribute ancestry projects mixed ID and marker targets without retaining dead source ancestry');
$assert(str_contains((string) ($mixedIdentityPointerTarget['serialized_blocks'] ?? ''), 'blocks-engine-attribute-') && 'pass' === ($mixedIdentityPointerTarget['source_reports']['wp_block_validity']['status'] ?? ''), 'mixed-identity pointer targets retain projection markers through valid Gutenberg serialization');

$fallbackAttributeFlex = ( new HtmlTransformer() )->transform('<style>label[data-hook="checkbox-core"] div[data-hook="label-wrapper"]{flex-grow:1}</style><form><label data-hook="checkbox-core"><input type="checkbox" name="consent"><div data-hook="label-wrapper">Consent copy</div></label><button type="submit">Send</button></form>')->toArray();
$fallbackAttributeFlexCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $fallbackAttributeFlex['assets'] ?? array()));
$fallbackAttributeFlexHtml = implode("\n", array_map(static fn (array $fallback): string => (string) ($fallback['html'] ?? ''), $fallbackAttributeFlex['fallbacks'] ?? array()));
$assert(str_contains($fallbackAttributeFlexHtml, 'data-hook="label-wrapper"') && str_contains($fallbackAttributeFlexHtml, 'blocks-engine-attribute-'), 'data-attribute projection markers survive inside bounded fallback islands');
$assert(str_contains($fallbackAttributeFlexCss, ':where(.blocks-engine-attribute-') && str_contains($fallbackAttributeFlexCss, 'flex-grow:1') && 'pass' === ($fallbackAttributeFlex['source_reports']['wp_block_validity']['status'] ?? ''), 'bounded fallback attribute projection remains styled and Gutenberg-valid');

$fallbackTagReset = ( new HtmlTransformer() )->transform('<style>p{margin:0}label[data-hook="checkbox-core"] div[data-hook="label-wrapper"]{flex-grow:1}</style><form><label data-hook="checkbox-core"><input type="checkbox"><div data-hook="label-wrapper"><p>Consent copy</p></div></label><button type="submit">Send</button></form>')->toArray();
$fallbackTagResetCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $fallbackTagReset['assets'] ?? array()));
$fallbackTagResetHtml = implode("\n", array_map(static fn (array $fallback): string => (string) ($fallback['html'] ?? ''), $fallbackTagReset['fallbacks'] ?? array()));
$assert(str_contains($fallbackTagResetHtml, '<p class="blocks-engine-source-p-') && str_contains($fallbackTagResetCss, ':where(.blocks-engine-source-p-') && str_contains($fallbackTagResetCss, '{margin:0}'), 'source tag projection markers preserve authored resets on descendants inside bounded fallback islands');

$settledAttributeState = ( new HtmlTransformer() )->transform('<style>.animated:not([data-state="done"]){animation:fade 1s backwards paused}@keyframes fade{from{opacity:0}to{opacity:1}}</style><main><div class="animated" data-state="done"><img src="hero.jpg" alt="Hero"></div><div class="animated"><p>Pending</p></div></main>')->toArray();
$settledAttributeStateCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $settledAttributeState['assets'] ?? array()));
$assert(str_contains($settledAttributeStateCss, ':not(.blocks-engine-attribute-state-') && str_contains((string) ($settledAttributeState['serialized_blocks'] ?? ''), 'blocks-engine-attribute-state-'), 'negated data-attribute state selectors preserve source matching through specificity-equivalent marker classes');
$assert('pass' === ($settledAttributeState['source_reports']['wp_block_validity']['status'] ?? ''), 'settled data-attribute state projection preserves valid Gutenberg serialization');

$emptyDataLayoutCarrier = ( new HtmlTransformer() )->transform('<style>[data-mesh-id="header"]{height:auto;min-height:83px}</style><header id="site-header"><div><div data-mesh-id="header"></div></div></header>')->toArray();
$emptyDataLayoutCarrierCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $emptyDataLayoutCarrier['assets'] ?? array()));
$assert(str_contains((string) ($emptyDataLayoutCarrier['serialized_blocks'] ?? ''), 'blocks-engine-attribute-'), 'empty data-addressed layout carriers survive as editable structural groups');
$assert(str_contains($emptyDataLayoutCarrierCss, ':where(.blocks-engine-attribute-') && str_contains($emptyDataLayoutCarrierCss, 'min-height:83px'), 'empty data-addressed layout carriers retain projected box geometry');

$roundedOutlineButton = ( new HtmlTransformer() )->transform(
    '<main><a class="btn btn-secondary" style="display:inline-block;padding:1rem 2rem;border:1px solid #c4a070;border-radius:12px;background:transparent;color:#eee" href="/tickets">Tickets</a></main>'
)->toArray();
$roundedOutlineButtonMarkup = (string) ($roundedOutlineButton['serialized_blocks'] ?? '');
$roundedOutlineButtonCss = implode("\n", array_column($roundedOutlineButton['assets'] ?? array(), 'content'));
$assert(str_contains($roundedOutlineButtonCss, 'border-radius:12px !important'), 'outline button carries an explicit source border radius');
$assert(! str_contains($roundedOutlineButtonCss, 'border-radius:0!important'), 'outline button does not override an explicit source border radius');

$wrapperOwnedButton = ( new HtmlTransformer() )->transform(
    '<main><div class="hero-cta" role="button" style="display:inline-flex;align-items:center;padding:14px 24px;border-radius:999px;background:#2563eb;color:#ffffff;font-weight:700"><a href="/start"><span>Start free</span></a></div></main>'
)->toArray();
$wrapperOwnedButtonMarkup = (string) ($wrapperOwnedButton['serialized_blocks'] ?? '');
$wrapperOwnedButtonCss = implode("\n", array_column($wrapperOwnedButton['assets'] ?? array(), 'content'));
$assert(str_contains($wrapperOwnedButtonMarkup, '<!-- wp:button'), 'button-like wrapper with a single simple anchor materializes as core/button');
$assert(str_contains($wrapperOwnedButtonMarkup, 'href="/start"'), 'button-like wrapper preserves the nested anchor URL on the native button');
$assert(str_contains($wrapperOwnedButtonMarkup, 'Start free'), 'button-like wrapper preserves the nested anchor label');
$assert(str_contains($wrapperOwnedButtonCss, 'background-color:#2563eb !important'), 'button-like wrapper carries wrapper-owned fill color to the native button chrome');
$assert(str_contains($wrapperOwnedButtonCss, 'color:#ffffff !important'), 'button-like wrapper carries wrapper-owned text color to the native button chrome');
$assert(str_contains($wrapperOwnedButtonCss, 'border-radius:999px !important'), 'button-like wrapper carries wrapper-owned radius to the native button chrome');
$assert(str_contains($wrapperOwnedButtonCss, 'padding-top:14px !important'), 'button-like wrapper carries wrapper-owned padding to the native button chrome');
$assert('pass' === ($wrapperOwnedButton['source_reports']['wp_block_validity']['status'] ?? ''), 'button-like wrapper conversion emits valid native button markup');

$fullWidthButton = ( new HtmlTransformer() )->transform(
    '<main><a class="btn tier-cta" style="display:inline-flex;width:100%;justify-content:center;padding:10px 18px;background:#111827;color:#ffffff" href="/pricing">Start free</a></main>'
)->toArray();
$fullWidthButtonMarkup = (string) ($fullWidthButton['serialized_blocks'] ?? '');
$assert(! isset($fullWidthButton['blocks'][0]['innerBlocks'][0]['attrs']['width']), '100% source button width omits the legacy core/button width attribute');
$assert(str_contains($fullWidthButtonMarkup, '<div class="wp-block-button btn tier-cta blocks-engine-native-button-') && ! str_contains($fullWidthButtonMarkup, 'has-custom-width') && ! str_contains($fullWidthButtonMarkup, 'wp-block-button__width-100'), '100% source button width emits the WordPress 7.1 wrapper shape plus its scoped native style marker');
$assert('pass' === ($fullWidthButton['source_reports']['wp_block_validity']['status'] ?? ''), 'full-width button serialization passes generated WordPress block validity checks');

$cssVariableButton = ( new HtmlTransformer() )->transform(
    '<style>:root{--amber:#f0ac22;--ink:#050d1a;--radius:6px}.btn-primary{padding:9px 20px;border-radius:var(--radius);background:var(--amber);color:var(--ink)}</style><main><a class="btn btn-primary" href="/start">Start free</a></main>'
)->toArray();
$cssVariableButtonMarkup = (string) ($cssVariableButton['serialized_blocks'] ?? '');
$cssVariableButtonCss = implode("\n", array_column($cssVariableButton['assets'] ?? array(), 'content'));
$assert(str_contains($cssVariableButtonCss, 'background-color:#f0ac22!important'), 'button CSS variable fill resolves to a concrete carried background color');
$assert(str_contains($cssVariableButtonCss, 'color:#050d1a!important'), 'button CSS variable text color resolves to a concrete carried text color');
$assert(str_contains($cssVariableButtonCss, 'border-radius:6px!important'), 'button CSS variable radius resolves to a concrete carried radius');
$assert(! str_contains($cssVariableButtonMarkup, 'var(--amber)'), 'button fill avoids leaking source-local CSS custom properties into standalone block markup');
$assert('pass' === ($cssVariableButton['source_reports']['wp_block_validity']['status'] ?? ''), 'CSS-variable button serialization passes generated WordPress block validity checks');

$borderWidthVariableCta = ( new HtmlTransformer() )->transform(
    '<style>:root{--corvid-border-width:var(--brw,0)}.cta{display:inline-block;width:142px;height:40px;background:#1684d6;color:#fff;border-color:var(--corvid-border-width,var(--brw,0))}</style><a class="cta" href="/more">Meer info</a>'
)->toArray();
$borderWidthVariableCtaMarkup = (string) ($borderWidthVariableCta['serialized_blocks'] ?? '');
$borderWidthVariableCtaCss = implode("\n", array_column($borderWidthVariableCta['assets'] ?? array(), 'content'));
$assert(! isset($borderWidthVariableCta['blocks'][0]['attrs']['style']['border']['color']) && ! str_contains($borderWidthVariableCtaMarkup, 'has-border-color'), 'dimension-valued custom properties do not activate Gutenberg border color support');
$assert(str_contains($borderWidthVariableCtaMarkup, 'Meer info') && str_contains($borderWidthVariableCtaCss, 'width:142px;height:40px;background:#1684d6') && str_contains($borderWidthVariableCtaCss, 'border-color:var(--corvid-border-width,var(--brw,0))'), 'rejected CTA border color remains in authored CSS with its label, source fill, and dimensions');
$assert('pass' === ($borderWidthVariableCta['source_reports']['wp_block_validity']['status'] ?? ''), 'dimension-valued CTA border variable preserves valid Gutenberg serialization');

$plainWrappedLink = ( new HtmlTransformer() )->transform('<main><div class="card-link"><a href="/docs">Read docs</a></div></main>')->toArray();
$assert(! str_contains((string) ($plainWrappedLink['serialized_blocks'] ?? ''), '<!-- wp:button'), 'plain single-anchor wrappers without button signals do not become buttons');

$separatorResult = ( new HtmlTransformer() )->transform('<main><hr class="wp-block-separator has-alpha-channel-opacity has-css-opacity divider"></main>')->toArray();
$separatorMarkup = (string) ($separatorResult['serialized_blocks'] ?? '');
$separatorAttrs = $separatorResult['blocks'][0]['attrs'] ?? array();
$assert('divider' === ($separatorAttrs['className'] ?? ''), 'separator filters generated core classes from promoted className');
$assert(str_contains($separatorMarkup, 'class="wp-block-separator has-alpha-channel-opacity has-css-opacity divider"'), 'separator emits canonical generated classes plus source divider class exactly once');
$assert(1 === substr_count($separatorMarkup, 'has-alpha-channel-opacity'), 'separator serialization emits has-alpha-channel-opacity exactly once');
$assert(1 === substr_count($separatorMarkup, 'has-css-opacity'), 'separator serialization emits has-css-opacity exactly once');
$assert(! str_contains($separatorMarkup, 'wp-block-separator divider wp-block-separator'), 'separator serialization does not duplicate generated classes');

$boundedSeparator = ( new HtmlTransformer() )->transform('<main><hr class="rule" style="max-width:var(--max);margin:0 auto"></main>')->toArray();
$boundedSeparatorAttrs = $boundedSeparator['blocks'][0]['attrs'] ?? array();
$boundedSeparatorMarkup = (string) ($boundedSeparator['serialized_blocks'] ?? '');
$boundedSeparatorCss = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $boundedSeparator['assets'] ?? array()));
$assert(array('top' => '0', 'bottom' => '0') === ($boundedSeparatorAttrs['style']['spacing']['margin'] ?? null) && ! isset($boundedSeparatorAttrs['style']['dimensions']), 'separator keeps only spacing supported by its canonical core block attributes');
$assert(! str_contains($boundedSeparatorMarkup, 'margin-left:auto') && ! str_contains($boundedSeparatorMarkup, 'max-width:var(--max)') && str_contains($boundedSeparatorCss, 'margin-left:auto !important') && str_contains($boundedSeparatorCss, 'margin-right:auto !important') && str_contains($boundedSeparatorCss, 'max-width:var(--max) !important'), 'separator moves unsupported horizontal and width geometry to its generated carrier stylesheet');

$boundedColumn = ( new HtmlTransformer() )->transform(
    '<main><div class="column-row" style="display:flex"><article class="bounded-column"><h2>Article</h2><p>Body</p></article><aside><p>Aside</p></aside></div></main>',
    array('static_css' => ':root{--measure:42rem}.bounded-column{max-width:var(--measure);padding:1rem}')
)->toArray();
$boundedColumnBlock = $boundedColumn['blocks'][0]['innerBlocks'][0] ?? array();
$boundedColumnAttrs = $boundedColumnBlock['attrs'] ?? array();
$boundedColumnMarkup = (string) ($boundedColumn['serialized_blocks'] ?? '');
$boundedColumnCss = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $boundedColumn['assets'] ?? array()));
$assert('core/group' === ($boundedColumnBlock['blockName'] ?? '') && str_contains((string) ($boundedColumnAttrs['className'] ?? ''), 'bounded-column') && 'article' === ($boundedColumnAttrs['tagName'] ?? ''), 'CSS-owned flex rows retain semantic article children through core/group');
$assert(! isset($boundedColumnAttrs['style']['dimensions']['maxWidth']) && ! str_contains($boundedColumnMarkup, 'max-width:var(--measure)'), 'column omits max-width unsupported by its canonical Gutenberg save wrapper');
$assert(str_contains($boundedColumnCss, '.bounded-column{max-width:var(--measure);padding:1rem}'), 'generated stylesheet retains the exact class-owned column max-width geometry');
$assert('pass' === ($boundedColumn['source_reports']['wp_block_validity']['status'] ?? ''), 'bounded column serialization passes canonical Gutenberg wrapper validity');

$customStateFindings = ( new CanonicalSaveShapeValidator() )->findings(array(array(
    'blockName'    => 'core/group',
    'attrs'        => array(),
    'innerBlocks'  => array(),
    'innerHTML'    => '<div class="wp-block-group is-custom-state"></div>',
    'innerContent' => array('<div class="wp-block-group is-custom-state"></div>'),
)));
$assert('unexpected_wrapper_class' === ($customStateFindings[0]['details']['reason'] ?? ''), 'validator rejects arbitrary is-* wrapper classes that are not sourced from className');

$customStateClassNameFindings = ( new CanonicalSaveShapeValidator() )->findings(array(array(
    'blockName'    => 'core/group',
    'attrs'        => array( 'className' => 'is-custom-state' ),
    'innerBlocks'  => array(),
    'innerHTML'    => '<div class="wp-block-group is-custom-state"></div>',
    'innerContent' => array('<div class="wp-block-group is-custom-state"></div>'),
)));
$assert(array() === $customStateClassNameFindings, 'validator accepts arbitrary is-* wrapper classes only when reproduced from className');

$scriptOnlyFormFallback = ( new HtmlTransformer() )->transform('<main><form action="/contact" method="post"><script>window.submitContact()</script></form></main>')->toArray();
$scriptOnlyFormDiagnostic = $scriptOnlyFormFallback['source_reports']['conversion_report']['fallback_diagnostics'][0] ?? array();
$assertNormalizedFallbackDiagnostic($scriptOnlyFormDiagnostic, 'html_form_fallback', 'warning', 'server_or_client_form_handler', 'form');
$assert('interactive_form' === ($scriptOnlyFormDiagnostic['pattern_family'] ?? ''), 'conversion report exposes form fallback pattern family');
$assert('inside_main' === ($scriptOnlyFormDiagnostic['parent_reason'] ?? ''), 'conversion report exposes fallback parent reason');
$assert('0,2,2' === ($scriptOnlyFormDiagnostic['source_selector_specificity']['score'] ?? ''), 'conversion report exposes fallback selector specificity');
$assert('preserve_runtime_island' === ($scriptOnlyFormDiagnostic['suggested_generic_repair_class'] ?? ''), 'conversion report exposes form fallback generic repair class');
$assert(array() === ($scriptOnlyFormFallback['blocks'] ?? array()), 'runtime form without readable controls still falls back only as metadata');

$rangeControlResult = ( new HtmlTransformer() )->transform(
    '<main><section><label for="density">Density</label><input type="range" id="density" min="6" max="60" step="2" value="28"></section></main>'
)->toArray();
$rangeControlText = (string) ($rangeControlResult['blocks'][0]['innerBlocks'][1]['attrs']['content'] ?? '');
$assert(array() === ($rangeControlResult['fallbacks'] ?? array()), 'standalone readable range input converts without unsupported-element fallback');
$assert(str_contains($rangeControlText, 'Density: 28'), 'range input summary preserves current value');
$assert(str_contains($rangeControlText, 'min 6, max 60, step 2'), 'range input summary preserves bounds');

$standaloneControls = ( new HtmlTransformer() )->transform(
    '<main><input id="donation" type="number" aria-label="Custom donation amount" placeholder="Enter amount"><label for="product-sort">Sort products</label><select id="product-sort" name="products" class="catalog-sort" placeholder="Sort products"><option value="" selected disabled>Choose an order</option><option value="featured">Featured</option><option value="price">Price: Low to High</option></select><select class="js-sort-select" aria-label="Runtime sort"><option>Newest</option></select></main>',
    array('runtime_dom_selectors' => array('.js-sort-select'), 'static_css' => '.catalog-sort{appearance:none;border:2px solid #123;padding:8px}')
)->toArray();
$standaloneControlBlocks = $standaloneControls['blocks'][0]['innerBlocks'] ?? array();
$assert(array() === ($standaloneControls['fallbacks'] ?? array()), 'standalone readable controls convert without unsupported-element fallback');
$assert('core/paragraph' === ($standaloneControlBlocks[0]['blockName'] ?? ''), 'standalone non-runtime input converts to readable paragraph');
$assert('core/paragraph' === ($standaloneControlBlocks[1]['blockName'] ?? ''), 'source select label remains a sibling editable block');
$assert('core/group' === ($standaloneControlBlocks[2]['blockName'] ?? ''), 'standalone static select retains the legacy structural group boundary');
$assert('blocks-engine/authored-select' === ($standaloneControlBlocks[2]['innerBlocks'][0]['blockName'] ?? ''), 'standalone non-runtime select uses an authored-select editable native-control block inside its compatibility wrapper');
$authoredSelectBlocks = array_values(array_filter($standaloneControls['source_reports']['generated_blocks'] ?? array(), static fn (array $block): bool => 'authored-select' === ($block['name'] ?? '')));
$authoredSelectCss = (string) ($authoredSelectBlocks[0]['assets']['style.css'] ?? '');
$assert(str_contains($authoredSelectCss, '.wp-block-group.blocks-engine-authored-select-wrapper{display:contents}') && ! str_contains($authoredSelectCss, '!important'), 'authored-select companion wrapper CSS preserves display contents without important declarations');
$assert('core/html' === ($standaloneControlBlocks[3]['blockName'] ?? ''), 'runtime-targeted select preserves native DOM output');
$assert(str_contains((string) ($standaloneControls['serialized_blocks'] ?? ''), '<option value="" selected disabled>Choose an order</option>'), 'compact select preserves selected placeholder option state');
$assert(str_contains((string) ($standaloneControls['serialized_blocks'] ?? ''), '<select id="product-sort" name="products" placeholder="Sort products" class="catalog-sort">'), 'compact select preserves native id, name, placeholder, and CSS selector identity');
$assert(1 === substr_count((string) ($standaloneControls['serialized_blocks'] ?? ''), '>Sort products</p>') && ! str_contains((string) ($standaloneControls['serialized_blocks'] ?? ''), '<label'), 'styled select emits its source label exactly once without a duplicate custom-block label');
$assert(! str_contains((string) ($standaloneControls['serialized_blocks'] ?? ''), '<!-- wp:html') || str_contains((string) ($standaloneControls['serialized_blocks'] ?? ''), '<select class="js-sort-select"'), 'only the runtime-targeted select uses core/html');
$assert(str_contains((string) ($standaloneControls['serialized_blocks'] ?? ''), '<select class="js-sort-select"'), 'runtime-targeted select preserves native markup in serialized blocks');
$assert(str_contains((string) ($standaloneControls['serialized_blocks'] ?? ''), 'id="donation"'), 'readable input output preserves source id as a block anchor');
$assert(str_contains((string) ($standaloneControls['serialized_blocks'] ?? ''), 'js-sort-select'), 'runtime-targeted select keeps behavior-hook class on native markup');
$assert(1 === count($standaloneControls['source_reports']['runtime_islands'] ?? array()), 'runtime islands report only the explicitly runtime-targeted standalone control');
$assert('control' === ($standaloneControls['source_reports']['runtime_islands'][0]['kind'] ?? ''), 'runtime-targeted standalone control reports as a control island');
$assert('.js-sort-select' === ($standaloneControls['source_reports']['runtime_islands'][0]['selector'] ?? ''), 'runtime-targeted standalone control reports selector metadata');
$assert('select' === ($standaloneControls['source_reports']['runtime_islands'][0]['control']['tag'] ?? ''), 'runtime-targeted standalone control reports control metadata');
$assert(str_contains((string) ($standaloneControls['source_reports']['runtime_islands'][0]['source_snippet'] ?? ''), '<select class="js-sort-select"'), 'runtime-targeted standalone control preserves source snippet metadata');

$styledInputs = ( new HtmlTransformer() )->transform(
    '<main><input id="newsletter" class="footer-newsletter__input" type="email" name="email" value="member@example.com" placeholder="Trail updates + new kits" aria-label="Email for newsletter" required disabled readonly><input id="plain-input" class="plain-input" type="text" placeholder="Readable summary"><input class="js-filter" type="text" placeholder="Runtime filter"></main>',
    array('runtime_dom_selectors' => array('.js-filter'), 'static_css' => '.footer-newsletter__input{flex:1;border:1px solid #123;padding:10px}')
)->toArray();
$styledInputBlocks = $styledInputs['blocks'][0]['innerBlocks'] ?? array();
$styledInputMarkup = (string) ($styledInputs['serialized_blocks'] ?? '');
$assert('blocks-engine/authored-input' === ($styledInputBlocks[0]['blockName'] ?? ''), 'static input with authored presentation uses an authored-input editable native-control block');
$assert('core/paragraph' === ($styledInputBlocks[1]['blockName'] ?? ''), 'unstyled static input retains the readable-summary representation');
$assert('core/html' === ($styledInputBlocks[2]['blockName'] ?? ''), 'runtime-targeted input retains native runtime-island behavior');
$assert(str_contains($styledInputMarkup, '<input type="email" id="newsletter" name="email" value="member@example.com" placeholder="Trail updates + new kits" aria-label="Email for newsletter" class="footer-newsletter__input" required disabled readonly>'), 'compact input preserves authored type, identity, value, accessibility, state, and CSS selector attributes');
$assert(! str_contains($styledInputMarkup, '<!-- wp:html') || str_contains($styledInputMarkup, '<input class="js-filter"'), 'styled static input never uses core/html while runtime input remains compatible');
$assert('pass' === ($styledInputs['source_reports']['wp_block_validity']['status'] ?? ''), 'compact input serialization passes canonical Gutenberg validity');

$whitespaceInput = ( new HtmlTransformer() )->transform(
    '<input class="authored-input" type="text" name="expected-# of people " placeholder=" ">',
    array('static_css' => '.authored-input{border:1px solid;padding:1rem}')
)->toArray();
$whitespaceInputBlock = $whitespaceInput['blocks'][0] ?? array();
$assert('expected-# of people ' === ($whitespaceInputBlock['attrs']['name'] ?? null) && ' ' === ($whitespaceInputBlock['attrs']['placeholder'] ?? null) && str_contains((string) ($whitespaceInput['serialized_blocks'] ?? ''), 'name="expected-# of people " placeholder=" "'), 'compact input PHP markup preserves the same safe whitespace-bearing attributes as its companion save function');

$unstyledSelect = ( new HtmlTransformer() )->transform(
    '<main><select id="plain-sort" class="catalog-sort" name="products" aria-label="Sort products"><option selected>Featured</option><option>Price</option></select></main>'
)->toArray();
$unstyledSelectBlock = $unstyledSelect['blocks'][0] ?? array();
$assert('core/group' === ($unstyledSelectBlock['blockName'] ?? '') && 'core/list' === ($unstyledSelectBlock['innerBlocks'][1]['blockName'] ?? ''), 'static select without authored presentation evidence retains the readable-list representation');
$assert(! str_contains((string) ($unstyledSelect['serialized_blocks'] ?? ''), '<!-- wp:blocks-engine/authored-select'), 'unstyled static select does not generate an authored-select native-control block from class identity alone');

$gridSelect = ( new HtmlTransformer() )->transform(
    '<main><div class="control-grid"><select id="grid-sort" class="catalog-sort"><option>Featured</option></select></div></main>',
    array('static_css' => '.control-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.catalog-sort{width:100%;padding:8px}')
)->toArray();
$gridSelectBlock = $gridSelect['blocks'][0]['innerBlocks'][0] ?? array();
$gridSelectDefinition = $gridSelect['source_reports']['generated_blocks'][0] ?? array();
$assert('core/group' === ($gridSelectBlock['blockName'] ?? '') && 'blocks-engine/authored-select' === ($gridSelectBlock['innerBlocks'][0]['blockName'] ?? ''), 'styled select retains the valid compatibility group while its native control remains the authored grid child');
$assert(str_contains((string) ($gridSelectDefinition['assets']['style.css'] ?? ''), 'display:contents'), 'compact select wrapper stylesheet flattens the compatibility group for authored grid and flex item sizing');

$standaloneSearch = ( new HtmlTransformer() )->transform(
    '<div class="site-search"><input type="search" name="s" placeholder="Search articles" aria-label="Search articles"></div>'
)->toArray();
$standaloneSearchBlock = $standaloneSearch['blocks'][0] ?? array();
$assert('core/search' === ($standaloneSearchBlock['blockName'] ?? ''), 'script-free standalone search input converts to core/search');
$assert('Search articles' === ($standaloneSearchBlock['attrs']['placeholder'] ?? ''), 'standalone core/search preserves the source placeholder');
$assert('no-button' === ($standaloneSearchBlock['attrs']['buttonPosition'] ?? ''), 'standalone input-only search keeps the no-button presentation');
$assert(! str_contains((string) ($standaloneSearch['serialized_blocks'] ?? ''), '<!-- wp:html'), 'standalone search input avoids core/html');

$runtimeDescendantSearch = ( new HtmlTransformer() )->transform(
    '<div class="site-search"><input type="search" name="s" placeholder="Search"><span id="search-status" aria-live="polite"></span></div>',
    array('runtime_dom_selectors' => array('#search-status'))
)->toArray();
$assert(! str_contains((string) ($runtimeDescendantSearch['serialized_blocks'] ?? ''), '<!-- wp:search'), 'synthetic search with an additional runtime descendant is not collapsed to core/search');
$assert(str_contains((string) ($runtimeDescendantSearch['serialized_blocks'] ?? ''), 'search-status'), 'synthetic search preserves an additional runtime descendant');
$assert(1 === count($runtimeDescendantSearch['source_reports']['runtime_islands'] ?? array()), 'synthetic search reports its preserved runtime descendant');

$runtimeClockCss = '*{margin:0;padding:0}.site-footer{display:flex}.footer-left{display:flex;flex-direction:column}.clock-time{font-size:.75rem;font-weight:700}.clock-date{font-size:.7rem;min-height:1.2em}.blink-colon{animation:blink 1s infinite}#timezone{margin-left:.2rem;opacity:.6}';
$runtimeClock = ( new HtmlTransformer() )->transform(
    '<footer class="site-footer"><div id="clock-container" class="footer-left"><div id="clock-time" class="clock-time"><!-- Initial State: 00:00 --><span id="hours">12</span><span id="colon" class="blink-colon">:</span><span id="minutes">28</span><span id="ampm">PM</span><span id="timezone">(GMT -4)</span></div><div id="clock-date" class="clock-date">Saturday</div></div></footer>',
    array(
        'runtime_dom_selectors' => array('#clock-container', '#hours', '#colon', '#minutes', '#ampm', '#timezone', '#clock-date'),
        'static_css' => $runtimeClockCss,
        'author_stylesheet_assets' => array(array('path' => 'style.css', 'source_path' => 'style.css', 'content' => $runtimeClockCss, 'source_hash' => hash('sha256', $runtimeClockCss), 'media' => '', 'type' => '')),
        'skip_author_stylesheet_materialization' => true,
    )
)->toArray();
$runtimeClockMarkup = (string) ($runtimeClock['serialized_blocks'] ?? '');
$assert(str_contains($runtimeClockMarkup, 'className":"clock-time blocks-engine-editor-anchor-clock-time blocks-engine-synthetic-paragraph') && 0 === substr_count($runtimeClockMarkup, '<!-- wp:html'), 'selector-addressed inline clock values remain one native RichText run inside a CSS-owned flex ancestor', $runtimeClockMarkup);
$assert(str_contains($runtimeClockMarkup, 'id="hours"') && str_contains($runtimeClockMarkup, 'id="colon"') && str_contains($runtimeClockMarkup, 'id="minutes"') && str_contains($runtimeClockMarkup, 'id="ampm"') && str_contains($runtimeClockMarkup, 'id="timezone"'), 'native runtime text run retains every script-addressed id', $runtimeClockMarkup);
$assert(! str_contains($runtimeClockMarkup, 'Initial State'), 'source comments do not become visible RichText editor content', $runtimeClockMarkup);
$runtimeClockRoundTrip = new \Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime();
$runtimeClockEdited = $runtimeClockRoundTrip->parseBlocks($runtimeClockMarkup);
$editRuntimeClock = static function (array &$blocks) use (&$editRuntimeClock): void {
    foreach ($blocks as &$block) {
        if (is_array($block['innerContent'] ?? null)) {
            foreach ($block['innerContent'] as &$content) {
                if (is_string($content)) {
                    $content = str_replace('id="hours" style="margin:0;padding:0;background-color:transparent;color:inherit">12</mark>', 'id="hours" style="margin:0;padding:0;background-color:transparent;color:inherit">13</mark>', $content);
                }
            }
            unset($content);
        }
        if (is_array($block['innerBlocks'] ?? null)) {
            $editRuntimeClock($block['innerBlocks']);
        }
    }
    unset($block);
};
$editRuntimeClock($runtimeClockEdited);
$runtimeClockEditedMarkup = $runtimeClockRoundTrip->serializeBlocks($runtimeClockEdited);
$runtimeClockRendered = $runtimeClockRoundTrip->renderBlocks($runtimeClockEdited);
$assert(str_contains($runtimeClockEditedMarkup, 'id="hours" style="margin:0;padding:0;background-color:transparent;color:inherit">13</mark>') && str_contains($runtimeClockRendered, 'id="minutes"') && ! str_contains($runtimeClockEditedMarkup, '<!-- wp:html'), 'native runtime RichText survives parse, edit, serialize, and render without becoming Custom HTML', $runtimeClockEditedMarkup);

$runtimeGroup = ( new HtmlTransformer() )->transform(
    '<section id="scoreboard"><p>Score: <span id="score">0</span></p></section>',
    array('runtime_dom_selectors' => array('#scoreboard', '#score'))
)->toArray();
$runtimeGroupMarkup = (string) ($runtimeGroup['serialized_blocks'] ?? '');
$runtimeGroupDiagnostics = array_values(array_filter($runtimeGroup['diagnostics'] ?? array(), static fn (array $diagnostic): bool => 'runtime_dom_contract_preserved' === ($diagnostic['code'] ?? '')));
$assert('core/group' === ($runtimeGroup['blocks'][0]['blockName'] ?? '') && str_contains($runtimeGroupMarkup, 'id="scoreboard"') && str_contains($runtimeGroupMarkup, 'id="score"') && ! str_contains($runtimeGroupMarkup, '<!-- wp:html') && 1 <= count($runtimeGroupDiagnostics), 'script-addressed container remains a native Group with a stable native-preservation diagnostic', $runtimeGroupMarkup);

$mixedCanvasRuntime = ( new HtmlTransformer() )->transform(
    '<section class="demo"><p>Editable <span id="count">0</span></p><canvas id="chart">Chart</canvas><p>Still editable</p></section>',
    array('runtime_dom_selectors' => array('#count'), 'runtime_canvas_selectors' => array('#chart'))
)->toArray();
$mixedCanvasMarkup = (string) ($mixedCanvasRuntime['serialized_blocks'] ?? '');
$assert(1 === substr_count($mixedCanvasMarkup, '<!-- wp:html') && str_contains($mixedCanvasMarkup, '<canvas id="chart">Chart</canvas>') && str_contains($mixedCanvasMarkup, 'Editable <span id="count">0</span>') && str_contains($mixedCanvasMarkup, 'Still editable'), 'an irreducible canvas remains one bounded runtime island without forcing editable siblings into Custom HTML', $mixedCanvasMarkup);

$emptyRuntimeText = ( new HtmlTransformer() )->transform(
    '<footer class="footer"><div id="runtime-status" class="runtime-status"></div></footer>',
    array(
        'runtime_dom_selectors' => array('#runtime-status'),
        'static_css' => 'body{overflow:hidden;height:100dvh;width:100vw}.footer{display:flex}.runtime-status{min-height:1.2em}',
    )
)->toArray();
$emptyRuntimeTextMarkup = (string) ($emptyRuntimeText['serialized_blocks'] ?? '');
$emptyRuntimeTextEditorCss = implode("\n", array_map(static fn (array $asset): string => 'editor' === ($asset['stylesheet_target'] ?? '') ? (string) ($asset['content'] ?? '') : '', $emptyRuntimeText['assets'] ?? array()));
$assert(str_contains($emptyRuntimeTextMarkup, 'blocks-engine-empty-runtime-target'), 'empty script-owned text targets carry a dedicated editor placeholder class', $emptyRuntimeTextMarkup);
$assert(str_contains($emptyRuntimeTextEditorCss, 'content:"Dynamic content"') && str_contains($emptyRuntimeTextEditorCss, '>*{display:none!important}'), 'empty script-owned text targets replace the Group inserter with an editor-only dynamic-content placeholder', $emptyRuntimeTextEditorCss);
$assert(str_contains($emptyRuntimeTextEditorCss, ':root body{overflow:auto!important;height:auto!important;min-height:100%!important;width:auto!important}'), 'viewport-locked source documents remain scrollable inside the block editor', $emptyRuntimeTextEditorCss);

$labelWrappedRuntimeControls = ( new HtmlTransformer() )->transform(
    '<main><label class="tool"><span>Theme</span><select id="scheme-select"><option>Harbor</option></select></label><label class="tool"><input type="checkbox" id="crt-toggle"><span>CRT</span></label></main>',
    array('runtime_dom_selectors' => array('#scheme-select', '#crt-toggle'))
)->toArray();
$labelWrappedRuntimeMarkup = (string) ($labelWrappedRuntimeControls['serialized_blocks'] ?? '');
$assert(str_contains($labelWrappedRuntimeMarkup, '<select id="scheme-select"'), 'label-wrapped runtime select preserves exact native DOM target');
$assert(str_contains($labelWrappedRuntimeMarkup, '<input type="checkbox" id="crt-toggle"'), 'label-wrapped runtime checkbox preserves exact native DOM target');

$artifactControlSelectors = ( new ArtifactCompiler() )->compile(
    array(
        'entrypoint' => 'index.html',
        'files'      => array(
            'index.html' => '<main><input id="newsletter-email" class="email-field" type="email" placeholder="you@example.com"><select id="sort-select" class="sort-select"><option selected>Featured</option><option>Newest</option></select><input id="live-filter" class="live-filter" type="text" placeholder="Filter"><script src="js/app.js"></script></main>',
            'js/app.js' => 'document.getElementById("newsletter-email"); document.querySelector(".sort-select"); const liveFilter = document.getElementById("live-filter"); liveFilter.addEventListener("input", function () { window.__changed = true; });',
        ),
    )
)->toArray();
$artifactControlMarkup = (string) ($artifactControlSelectors['serialized_blocks'] ?? '');
$assert(! str_contains($artifactControlMarkup, '<input id="newsletter-email"'), 'artifact compiler converts generically queried static input to readable block output');
$assert(! str_contains($artifactControlMarkup, '<select id="sort-select"'), 'artifact compiler retains readable static select output without authored presentation evidence');
$assert(str_contains($artifactControlMarkup, 'you@example.com'), 'artifact static input readable output preserves placeholder text');
$assert(str_contains($artifactControlMarkup, 'Featured (selected)'), 'artifact static select readable output preserves selected option state');
$assert(str_contains($artifactControlMarkup, '<input id="live-filter"'), 'artifact compiler preserves behavior-bearing control native DOM in serialized blocks');
$assert(str_contains($artifactControlMarkup, 'placeholder="Filter"'), 'artifact behavior-bearing control preserves placeholder attribute on native DOM');
$assert(str_contains($artifactControlMarkup, 'id="newsletter-email"'), 'artifact readable static input preserves source id as a block anchor');
$assert(str_contains($artifactControlMarkup, 'sort-select'), 'artifact readable static select preserves source class on generated markup');
$assert(str_contains($artifactControlMarkup, 'id="live-filter"'), 'artifact readable runtime control preserves source id on generated markup');
$artifactControlIslands = $artifactControlSelectors['source_reports']['runtime_islands'] ?? array();
$assert(1 === count($artifactControlIslands), 'artifact compiler reports only behavior-bearing controls as runtime islands');
$assert('#live-filter' === ($artifactControlIslands[0]['selector'] ?? ''), 'artifact runtime control island points at behavior-bearing control selector');
$assert(str_contains((string) ($artifactControlIslands[0]['source_snippet'] ?? ''), '<input id="live-filter"'), 'artifact runtime control island preserves source snippet metadata');
$artifactControlRuntimeReport = $artifactControlSelectors['source_reports']['runtime_dependency_parity'] ?? array();
$assert('pass' === ($artifactControlRuntimeReport['status'] ?? ''), 'runtime parity does not flag readable static controls as missing runtime targets');
$artifactNeutralRuntimeSelector = ( new ArtifactCompiler() )->compile(
    array(
        'entrypoint' => 'index.html',
        'files'      => array(
            'index.html' => '<main><div class="hero-banner"><p>Welcome</p></div><script src="js/app.js"></script></main>',
            'js/app.js'  => 'document.querySelector(".hero-banner");',
        ),
    )
)->toArray();
$artifactNeutralRuntimeIslands = $artifactNeutralRuntimeSelector['source_reports']['runtime_islands'] ?? array();
$assert(1 === count($artifactNeutralRuntimeIslands) && '.hero-banner' === ($artifactNeutralRuntimeIslands[0]['selector'] ?? ''), 'artifact compilation preserves a neutral queried selector identified by shared fail-closed runtime evidence');
$assert('pass' === ($artifactNeutralRuntimeSelector['source_reports']['runtime_dependency_parity']['status'] ?? ''), 'runtime parity consumes the same neutral selector evidence as artifact compilation');
$artifactRuntimeAnchor = ( new ArtifactCompiler() )->compile(
    array(
        'entrypoint' => 'index.html',
        'files'      => array(
            'index.html' => '<main><div class="event"><a class="event-add" href="#">Add to calendar</a></div><script src="js/app.js"></script></main>',
            'js/app.js'  => 'document.querySelectorAll(".event-add").forEach(function (link) { link.addEventListener("click", function (event) { event.preventDefault(); }); });',
        ),
    )
)->toArray();
$artifactRuntimeAnchorMarkup = (string) ($artifactRuntimeAnchor['serialized_blocks'] ?? '');
$artifactRuntimeAnchorIslands = $artifactRuntimeAnchor['source_reports']['runtime_islands'] ?? array();
$assert(str_contains($artifactRuntimeAnchorMarkup, '<!-- wp:html ') && str_contains($artifactRuntimeAnchorMarkup, '<a class="event-add" href="#">Add to calendar</a>'), 'artifact compiler preserves behavior-bearing anchors as exact runtime DOM targets');
$assert(1 === count($artifactRuntimeAnchorIslands) && '.event-add' === ($artifactRuntimeAnchorIslands[0]['selector'] ?? ''), 'artifact compiler reports a behavior-bearing anchor as one bounded runtime DOM island');
$assert('pass' === ($artifactRuntimeAnchor['source_reports']['runtime_dependency_parity']['status'] ?? ''), 'runtime dependency parity resolves behavior-bearing anchor selectors against preserved markup');
$runtimeMutationStateIslands = ( new ArtifactCompiler() )->compile(
    array(
        'entrypoint' => 'index.html',
        'files'      => array(
            'index.html' => '<main><div class="lang-toggle"><button class="on">EN</button><button>DE</button></div><div data-chip-group><button class="chip is-on">All</button><button class="chip">New</button></div><script src="js/app.js"></script></main>',
            'js/app.js' => 'document.querySelectorAll(".lang-toggle button").forEach(btn => { btn.addEventListener("click", () => { const group = btn.closest(".lang-toggle"); group.querySelectorAll("button").forEach(b => b.classList.remove("on")); btn.classList.add("on"); }); }); document.querySelectorAll("[data-chip-group]").forEach(group => { const chips = group.querySelectorAll(".chip"); chips.forEach(chip => chip.addEventListener("click", () => { chips.forEach(c => c.classList.remove("is-on")); chip.classList.add("is-on"); })); });',
        ),
    )
)->toArray();
$runtimeMutationStateSelectors = array_map(static fn (array $island): string => (string) ($island['selector'] ?? ''), $runtimeMutationStateIslands['source_reports']['runtime_islands'] ?? array());
$assert(in_array('.lang-toggle', $runtimeMutationStateSelectors, true), 'runtime islands retain stable language-toggle query target');
$assert(2 === count($runtimeMutationStateSelectors), 'nested runtime targets collapse into their preserved stable query roots');
$assert(! in_array('.on', $runtimeMutationStateSelectors, true) && ! in_array('.is-on', $runtimeMutationStateSelectors, true), 'runtime islands do not report mutation-state classes as target selectors');
$separateRuntimeIslands = ( new ArtifactCompiler() )->compile(
    array(
        'entrypoint' => 'index.html',
        'files'      => array(
            'index.html' => '<main><div class="runtime-parent"><button class="runtime-child">Nested</button></div><button class="runtime-child">Separate</button><script src="js/app.js"></script></main>',
            'js/app.js' => 'document.querySelector(".runtime-parent").addEventListener("click", () => {}); document.querySelectorAll(".runtime-child").forEach(button => button.addEventListener("click", () => {}));',
        ),
    )
)->toArray();
$separateRuntimeIslandSelectors = array_map(static fn (array $island): string => (string) ($island['selector'] ?? ''), $separateRuntimeIslands['source_reports']['runtime_islands'] ?? array());
$assert(2 === count($separateRuntimeIslandSelectors), 'runtime island collapse retains matching controls outside the preserved parent subtree');
$runtimeContext = ( new ArtifactCompiler() )->runtimeContextForSource(
    '<header><button class="theme-toggle">Theme</button><script src="js/app.js"></script></header>',
    'index.html',
    array(
        'index.html' => '<header><button class="theme-toggle">Theme</button><script src="js/app.js"></script></header>',
        'js/app.js'  => "document.querySelectorAll('.theme-toggle').forEach(button => {\n  const sync = () => button.setAttribute('aria-label', 'Toggle theme');\n  button.addEventListener('click', toggleTheme);\n});",
    )
);
$assert(in_array('.theme-toggle', $runtimeContext['runtime_dom_selectors'] ?? array(), true), 'standalone source runtime context exposes behavior-bearing DOM selectors');
$assert('js/app.js' === ($runtimeContext['runtime_script_metadata'][0]['path'] ?? ''), 'standalone source runtime context exposes materialized script metadata');

$artifactSvgSelectors = ( new ArtifactCompiler() )->compile(
    array(
        'entrypoint' => 'index.html',
        'files'      => array(
            'index.html' => '<main><svg id="graph"></svg><svg id="mapsvg"></svg><svg id="mapSvg"></svg><svg id="miniSvg"></svg><section id="panel"><svg></svg></section><script src="js/app.js"></script></main>',
            'js/app.js'  => 'document.getElementById("graph"); document.querySelector("#mapsvg"); const mapSvg = document.getElementById("mapSvg"); mapSvg.appendChild(document.createElementNS("http://www.w3.org/2000/svg", "g")); const miniSvg = document.querySelector("#miniSvg"); miniSvg.setAttribute("data-ready", "1"); const panel = document.getElementById("panel"); const nested = panel.querySelector("svg"); nested.setAttribute("data-root", "1");',
        ),
    )
)->toArray();
$artifactSvgMarkup = (string) ($artifactSvgSelectors['serialized_blocks'] ?? '');
foreach ( array( 'graph', 'mapsvg', 'mapSvg', 'miniSvg' ) as $svgId ) {
    $assert(str_contains($artifactSvgMarkup, '<svg id="' . $svgId . '"'), 'artifact compiler preserves runtime-targeted SVG root #' . $svgId);
}
$assert(str_contains($artifactSvgMarkup, '<section id="panel"'), 'artifact compiler preserves script-appended SVG container root');
$assert('pass' === ($artifactSvgSelectors['source_reports']['runtime_dependency_parity']['status'] ?? ''), 'runtime parity passes for queried and script-populated SVG roots');

$buttonResult = ( new HtmlTransformer() )->transform(
    '<main><a class="primary-button" href="#" style="padding:10px 16px;background:#135e96"><h3>Reserve now</h3><span aria-hidden="true"></span></a><button><strong>Call us</strong></button></main>'
)->toArray();
$buttonBlocks = $buttonResult['blocks'][0]['innerBlocks'] ?? array();
$assert('core/buttons' === ($buttonBlocks[0]['blockName'] ?? ''), 'anchor converts to buttons block');
$assert(str_contains((string) ($buttonBlocks[0]['innerBlocks'][0]['attrs']['text'] ?? ''), 'Reserve now'), 'anchor button text preserves visible label');
$assert('Reserve now' === ($buttonBlocks[0]['innerBlocks'][0]['attrs']['text'] ?? ''), 'anchor button text unwraps block-level label markup for valid inline RichText');
$assert(str_contains((string) ($buttonBlocks[1]['innerBlocks'][0]['attrs']['text'] ?? ''), 'Call us'), 'button text preserves visible label');
$assert(! str_contains((string) $buttonResult['serialized_blocks'], '\\u003c'), 'button serialization avoids escaped nested HTML attrs');
$assert(! str_contains((string) $buttonResult['serialized_blocks'], '<h3>Reserve now</h3>'), 'button serialization avoids block-level markup inside link text');
$assert('pass' === ($buttonResult['source_reports']['wp_block_validity']['status'] ?? ''), 'HTML transform exposes passing WordPress block validity report for generated buttons');

$buttonCustomFontSizeResult = ( new HtmlTransformer() )->transform(
    '<main><a class="artist-button" href="/music" style="padding:10px 16px;font-size:1rem;color:#fdf0d5;border:1px solid #fdf0d5">Listen now</a></main>'
)->toArray();
$buttonCustomFontSizeMarkup = (string) ($buttonCustomFontSizeResult['serialized_blocks'] ?? '');
$buttonCustomFontSizeCss = implode("\n", array_column($buttonCustomFontSizeResult['assets'] ?? array(), 'content'));
$assert(! str_contains($buttonCustomFontSizeMarkup, 'has-custom-font-size'), 'button custom font-size avoids unsupported native save markup');
$assert(str_contains($buttonCustomFontSizeCss, 'font-size:1rem !important'), 'button custom font-size preserves the declaration through the carrier');
$assert('pass' === ($buttonCustomFontSizeResult['source_reports']['wp_block_validity']['status'] ?? ''), 'button custom font-size serialization passes generated WordPress block validity checks');

$rubyResult = ( new HtmlTransformer() )->transform(
    '<main><blockquote><ruby>翻訳<rt>ほんやく</rt></ruby> keeps pronunciation visible.</blockquote></main>'
)->toArray();
$rubyQuote = $rubyResult['blocks'][0] ?? array();
$assert(array() === ($rubyResult['fallbacks'] ?? array()), 'ruby phrasing content does not create unsupported fallbacks');
$assert('core/quote' === ($rubyQuote['blockName'] ?? ''), 'ruby phrasing content remains inside quote block');
$assert(str_contains((string) ($rubyResult['serialized_blocks'] ?? ''), '<ruby>翻訳<rt>ほんやく</rt></ruby>'), 'ruby markup is preserved in quote content');

$quoteMarginResult = ( new HtmlTransformer() )->transform(
    '<blockquote>Direct quote.</blockquote><blockquote><p style="margin-top:12px;margin-bottom:8px">Source paragraph.</p></blockquote>'
)->toArray();
$directQuote = $quoteMarginResult['blocks'][0] ?? array();
$sourceParagraphQuote = $quoteMarginResult['blocks'][1] ?? array();
$quoteMarginMarkup = (string) ($quoteMarginResult['serialized_blocks'] ?? '');
$quoteMarginCss = implode("\n", array_column(array_filter($quoteMarginResult['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
$assert('core/quote' === ($directQuote['blockName'] ?? '') && 'blocks-engine-synthetic-paragraph' === ($directQuote['innerBlocks'][0]['attrs']['className'] ?? '') && str_contains($quoteMarginMarkup, '<blockquote class="wp-block-quote"><!-- wp:paragraph {"className":"blocks-engine-synthetic-paragraph"} --><p class="blocks-engine-synthetic-paragraph">Direct quote.</p>'), 'direct-text quotes use native core/quote with a scoped synthetic paragraph save shape');
$inlineQuote = ( new HtmlTransformer() )->transform('<blockquote><span>Inline quote.</span></blockquote>')->toArray();
$assert('blocks-engine-synthetic-paragraph' === ($inlineQuote['blocks'][0]['innerBlocks'][0]['attrs']['className'] ?? ''), 'inline-wrapped quote content keeps the synthesized paragraph margin-neutral');
$assert(str_contains($quoteMarginCss, ':root :where(.blocks-engine-synthetic-paragraph){margin-top:0;margin-bottom:0}') && ! str_contains($quoteMarginCss, 'blockquote p{margin-top:0') && ! str_contains($quoteMarginCss, 'blockquote p{margin:0'), 'direct-text quote margin neutralization is scoped to synthesized paragraphs without a broad quote override');
$assert('core/quote' === ($sourceParagraphQuote['blockName'] ?? '') && ! isset($sourceParagraphQuote['innerBlocks'][0]['attrs']['className']) && str_contains($quoteMarginMarkup, '<p style="margin-top:12px;margin-bottom:8px">Source paragraph.</p>'), 'source quote paragraphs preserve authored margins without the synthetic reset');
$assert(array() === ( new CanonicalSaveShapeValidator() )->findings($quoteMarginResult['blocks'] ?? array()) && 'pass' === ($quoteMarginResult['source_reports']['wp_block_validity']['status'] ?? ''), 'direct-text and source-paragraph quote variants retain canonical editor-valid save shapes');

$plaintextResult = ( new HtmlTransformer() )->transform(
    '<p>Before</p><PLAINTEXT>Plain legacy text with &lt;b&gt;literal tags&lt;/b&gt;</PLAINTEXT><p>After</p>'
)->toArray();
$plaintextBlocks = $plaintextResult['blocks'] ?? array();
$plaintextBlock = $plaintextBlocks[1] ?? array();
$plaintextInnerHtml = (string) ($plaintextBlock['innerHTML'] ?? '');
$assert(array() === ($plaintextResult['fallbacks'] ?? array()), 'plaintext content does not create unsupported fallbacks');
$assert('core/paragraph' === ($plaintextBlocks[0]['blockName'] ?? ''), 'plaintext preserves preceding sibling content');
$assert('core/preformatted' === ($plaintextBlock['blockName'] ?? ''), 'case-insensitive plaintext content converts to a preformatted block');
$assert('core/paragraph' === ($plaintextBlocks[2]['blockName'] ?? ''), 'plaintext preserves following sibling content');
$assert(str_contains($plaintextInnerHtml, '&lt;b&gt;literal tags&lt;/b&gt;'), 'plaintext literal tags are escaped once in preformatted content');
$assert(! str_contains($plaintextInnerHtml, '&amp;lt;b'), 'plaintext entity content is not double-escaped');
$assert(! str_contains($plaintextInnerHtml, '</body>') && ! str_contains($plaintextInnerHtml, '</main>'), 'plaintext content excludes synthetic parser wrappers');

$preAndCodeResult = ( new HtmlTransformer() )->transform(
    '<p>&lt;b&gt;ordinary text&lt;/b&gt;</p><pre>ordinary pre</pre><pre><code>ordinary code</code></pre>'
)->toArray();
$preAndCodeBlocks = $preAndCodeResult['blocks'] ?? array();
$assert('core/paragraph' === ($preAndCodeBlocks[0]['blockName'] ?? '') && str_contains((string) ($preAndCodeBlocks[0]['innerHTML'] ?? ''), '&lt;b&gt;ordinary text&lt;/b&gt;'), 'documents without plaintext preserve ordinary encoded content');
$assert('core/preformatted' === ($preAndCodeBlocks[1]['blockName'] ?? ''), 'ordinary pre content remains preformatted');
$assert('core/code' === ($preAndCodeBlocks[2]['blockName'] ?? ''), 'ordinary pre/code content remains code');

$linkedLogoResult = ( new HtmlTransformer() )->transform(
    '<main><a class="site-logo" href="/">Mara Vale</a></main>'
)->toArray();
$linkedLogoBlock = $linkedLogoResult['blocks'][0] ?? array();
$linkedLogoSerialized = (string) ($linkedLogoResult['serialized_blocks'] ?? '');
$assert('core/paragraph' === ($linkedLogoBlock['blockName'] ?? ''), 'linked logo text converts to a paragraph block');
$assert(! array_key_exists('content', is_array($linkedLogoBlock['attrs'] ?? null) ? $linkedLogoBlock['attrs'] : array()), 'paragraph source content is not serialized as a block comment attribute');
$assert(str_contains($linkedLogoSerialized, '<p class="site-logo blocks-engine-synthetic-paragraph"><a href="/">Mara Vale</a></p>'), 'linked logo paragraph hoists link styling hooks to its marginless synthetic wrapper and keeps valid anchor markup');
$assert(! str_contains($linkedLogoSerialized, '\\u003ca'), 'linked logo paragraph avoids raw anchor HTML in delimiter JSON');
$assert('pass' === ($linkedLogoResult['source_reports']['wp_block_validity']['status'] ?? ''), 'linked logo paragraph passes generated block validity checks');

$syntheticInlineParagraphs = ( new HtmlTransformer() )->transform(
    '<style>p{margin:0}.site-header{display:flex;align-items:center;padding:20px 32px}.brand{font-size:18px;font-weight:700}</style><header class="site-header"><a class="brand" href="/">Verified Artifact</a></header><footer><span>Portable input.</span></footer><p>Source paragraph.</p>'
)->toArray();
$syntheticInlineMarkup = (string) ($syntheticInlineParagraphs['serialized_blocks'] ?? '');
$syntheticInlineCss = implode("\n", array_column(array_filter($syntheticInlineParagraphs['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
$assert(2 <= substr_count($syntheticInlineMarkup, 'blocks-engine-synthetic-paragraph') && str_contains($syntheticInlineMarkup, 'Verified Artifact') && str_contains($syntheticInlineMarkup, '<p class="blocks-engine-synthetic-paragraph"><span>Portable input.</span></p>') && ! str_contains($syntheticInlineMarkup, 'wp-block-blocks-engine-author-layout'), 'native anchors and standalone spans retain valid synthetic paragraph wrappers');
$assert(str_contains($syntheticInlineCss, ':root :where(.blocks-engine-synthetic-paragraph){margin-top:0;margin-bottom:0}') && strpos($syntheticInlineCss, ':root :where(.blocks-engine-synthetic-paragraph)') < strpos($syntheticInlineCss, ':where(.blocks-engine-source-p-'), 'synthetic paragraph reset precedes projected author CSS so explicit source margins retain cascade precedence');
$assert(preg_match('/<p class="blocks-engine-source-p-[^"]+">Source paragraph\.<\/p>/', $syntheticInlineMarkup) === 1 && ! str_contains($syntheticInlineMarkup, 'blocks-engine-synthetic-paragraph blocks-engine-source-p-') && 'pass' === ($syntheticInlineParagraphs['source_reports']['wp_block_validity']['status'] ?? ''), 'source paragraphs retain source-p selector provenance without the synthetic inline wrapper reset');

$richTextMediaAnchor = ( new HtmlTransformer() )->transform(
    '<style>.row{display:flex}.logo > svg{width:24px;height:18px}</style><div class="row"><a class="logo" href="/"><svg viewBox="0 0 10 10" aria-hidden="true"><circle cx="5" cy="5" r="4"/></svg><span>Logo</span></a></div>'
)->toArray();
$richTextMediaMarkup = (string) ($richTextMediaAnchor['serialized_blocks'] ?? '');
$richTextMediaAssetCount = count(array_filter($richTextMediaAnchor['assets'] ?? array(), static fn (array $asset): bool => 'inline-svg' === ($asset['source'] ?? '')));
$assert(str_contains($richTextMediaMarkup, '<img src="assets/materialized-svg/') && ! str_contains($richTextMediaMarkup, '<svg') && ! str_contains($richTextMediaMarkup, 'wp-block-blocks-engine-author-layout') && 1 === $richTextMediaAssetCount, 'Passive SVG anchors retain resolved image sizing and a materialized asset without a companion block.');
$assert(array() === ($richTextMediaAnchor['source_reports']['conversion_report']['gutenberg_incompatibilities']['author_layout_topology'] ?? array()), 'SVG-to-image anchor materialization does not report an intentional media-tag normalization as a topology change.');
$structuredMediaAnchor = ( new HtmlTransformer() )->transform('<style>.row{display:flex}</style><div class="row"><a class="card" href="/"><span>Copy</span><div>Structured</div></a></div>')->toArray();
$assert(! str_contains((string) ($structuredMediaAnchor['serialized_blocks'] ?? ''), 'wp-block-blocks-engine-author-layout'), 'Block-structured anchors retain native blocks without a companion save shape.');

$responsiveDivParagraph = ( new HtmlTransformer() )->transform(
    '<style>div.paragraph{padding-bottom:20px}@media(max-width:600px){div.paragraph{padding-bottom:8px}}</style><main><div class="paragraph"><span>Responsive copy.</span></div></main>'
)->toArray();
$responsiveDivParagraphMarkup = (string) ($responsiveDivParagraph['serialized_blocks'] ?? '');
$responsiveDivParagraphCss = implode("\n", array_column(array_filter($responsiveDivParagraph['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
$assert(preg_match('/<p class="paragraph blocks-engine-synthetic-paragraph (blocks-engine-source-div-[^"]+)"><span>Responsive copy\.<\/span><\/p>/', $responsiveDivParagraphMarkup, $responsiveDivParagraphMarker) === 1, 'div-backed native paragraphs retain source-div selector provenance');
$assert(str_contains($responsiveDivParagraphCss, ':where(.' . ($responsiveDivParagraphMarker[1] ?? '') . ')') && str_contains($responsiveDivParagraphCss, 'padding-bottom:20px') && str_contains($responsiveDivParagraphCss, 'padding-bottom:8px'), 'source div selectors preserve responsive paragraph spacing after the native tag changes');
$assert('pass' === ($responsiveDivParagraph['source_reports']['wp_block_validity']['status'] ?? ''), 'source-div paragraph selector projection preserves valid block markup');

$canonicalWrapperAttrsResult = ( new HtmlTransformer() )->transform(
    '<main><section class="menu-grid" style="display:grid;gap:2rem"><h2 class="section-title" style="color:red">Menu</h2><p class="card-desc" style="margin-bottom:1rem">Fresh daily.</p></section></main>'
)->toArray();
$canonicalWrapperAttrsSerialized = (string) ($canonicalWrapperAttrsResult['serialized_blocks'] ?? '');
$assert(str_contains($canonicalWrapperAttrsSerialized, '<section class="wp-block-group') && str_contains($canonicalWrapperAttrsSerialized, 'menu-grid') && str_contains($canonicalWrapperAttrsSerialized, 'blocks-engine-css-owned-layout'), 'CSS-owned wrappers preserve semantic tags and source classes through core/group');
$assert(! str_contains($canonicalWrapperAttrsSerialized, 'style="display:grid'), 'CSS-owned groups leave grid authority in the source stylesheet');
$assert(str_contains($canonicalWrapperAttrsSerialized, '<h2 class="wp-block-heading has-text-color section-title" style="color:red">Menu</h2>'), 'heading wrappers include canonical and support classes with supported color style');
$assert(str_contains($canonicalWrapperAttrsSerialized, '<p class="card-desc" style="margin-bottom:1rem">Fresh daily.</p>'), 'paragraph wrappers preserve runtime-addressable classes and supported margin style');

$paragraphGeneratedClassLeakResult = ( new HtmlTransformer() )->transform(
    '<main><p class="has-text-color has-text-color hero-tagline" style="color:red">Slow roasted daily.</p></main>'
)->toArray();
$paragraphGeneratedClassLeakBlock = $paragraphGeneratedClassLeakResult['blocks'][0] ?? array();
$paragraphGeneratedClassLeakSerialized = (string) ($paragraphGeneratedClassLeakResult['serialized_blocks'] ?? '');
$assert('hero-tagline' === ($paragraphGeneratedClassLeakBlock['attrs']['className'] ?? ''), 'paragraph className strips duplicate generated color classes while preserving custom classes');
$assert(str_contains($paragraphGeneratedClassLeakSerialized, '<p class="has-text-color hero-tagline" style="color:red">Slow roasted daily.</p>'), 'paragraph serialization emits generated has-text-color once before custom classes');
$assert(1 === substr_count($paragraphGeneratedClassLeakSerialized, 'has-text-color'), 'paragraph serialization emits has-text-color exactly once');
$assert('pass' === ($paragraphGeneratedClassLeakResult['source_reports']['wp_block_validity']['status'] ?? ''), 'paragraph with stripped generated className passes generated block validity checks');

$paragraphFactoryGeneratedClassLeakBlock = ( new BlockFactory() )->create('core/paragraph', array(
    'content'   => 'Slow roasted daily.',
    'className' => 'has-text-color has-text-color hero-tagline',
    'style'     => array('color' => array('text' => 'red')),
));
$assert('hero-tagline' === ($paragraphFactoryGeneratedClassLeakBlock['attrs']['className'] ?? ''), 'BlockFactory strips generated classes from direct paragraph className attrs');
$assert(str_contains((string) ($paragraphFactoryGeneratedClassLeakBlock['innerHTML'] ?? ''), '<p class="has-text-color hero-tagline" style="color:red">Slow roasted daily.</p>'), 'BlockFactory paragraph innerHTML emits a single generated color class');

$fixtureParagraphByContent = static function (array $blocks, string $content) use (&$fixtureParagraphByContent): ?array {
    foreach ( $blocks as $block ) {
        if ( ! is_array($block) ) {
            continue;
        }
        if ( 'core/paragraph' === ($block['blockName'] ?? '') && str_contains((string) ($block['attrs']['content'] ?? ''), $content) ) {
            return $block;
        }
        $match = $fixtureParagraphByContent(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array(), $content);
        if ( null !== $match ) {
            return $match;
        }
    }

    return null;
};

$fixtureRoot = dirname(__DIR__, 3) . '/fixtures/websites';
foreach ( array(
    array( '10-nonprofit', 'css/style.css', 'Harbor Steps prepares coastal communities' ),
    array( '13-realistic-small-business', 'styles.css', 'Houseplants, handmade pots' ),
    array( '74-lumen-coffee', 'css/styles.css', 'We source single-origin lots' ),
) as [ $fixtureName, $stylesheetPath, $paragraphContent ] ) {
    $fixturePath = $fixtureRoot . '/' . $fixtureName;
    $fixtureResult = ( new HtmlTransformer() )->transform(
        (string) file_get_contents($fixturePath . '/index.html'),
        array( 'static_css' => (string) file_get_contents($fixturePath . '/' . $stylesheetPath) )
    )->toArray();
    $fixtureParagraph = $fixtureParagraphByContent($fixtureResult['blocks'] ?? array(), $paragraphContent);
    $fixtureMarkup = (string) ($fixtureParagraph['innerHTML'] ?? '');

    $assert(null !== $fixtureParagraph, $fixtureName . ' fixture serializes its hero paragraph through core/paragraph');
    $assert(! str_contains((string) ($fixtureParagraph['attrs']['className'] ?? ''), 'has-text-color'), $fixtureName . ' fixture keeps generated text color classes out of paragraph className');
    $assert(1 === substr_count($fixtureMarkup, 'has-text-color'), $fixtureName . ' fixture emits has-text-color exactly once in paragraph markup', $fixtureMarkup);
    $assert('pass' === ($fixtureResult['source_reports']['wp_block_validity']['status'] ?? ''), $fixtureName . ' fixture paragraph passes the serialized block validity path');
}

$smallBusinessPath = $fixtureRoot . '/13-realistic-small-business';
$smallBusinessResult = ( new HtmlTransformer() )->transform(
    (string) file_get_contents($smallBusinessPath . '/index.html'),
    array( 'static_css' => (string) file_get_contents($smallBusinessPath . '/styles.css') )
)->toArray();
$smallBusinessInlineGeometryParagraph = $fixtureParagraphByContent($smallBusinessResult['blocks'] ?? array(), 'Birthdays, team outings');
$smallBusinessInlineGeometryAttrs = is_array($smallBusinessInlineGeometryParagraph['attrs'] ?? null) ? $smallBusinessInlineGeometryParagraph['attrs'] : array();
$smallBusinessInlineGeometryMarkup = (string) ($smallBusinessInlineGeometryParagraph['innerHTML'] ?? '');
$smallBusinessGeometryClass = (string) ($smallBusinessInlineGeometryAttrs['className'] ?? '');
$smallBusinessAssets = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $smallBusinessResult['assets'] ?? array()));

$assert(null !== $smallBusinessInlineGeometryParagraph, '13-realistic-small-business fixture keeps the inline-width CTA copy as a paragraph');
$assert(str_contains($smallBusinessGeometryClass, 'be-inline-geometry-'), '13-realistic-small-business fixture preserves inline max-width with a generated geometry class', $smallBusinessGeometryClass);
$assert(! isset($smallBusinessInlineGeometryAttrs['style']['dimensions']['maxWidth']), 'paragraph comment attrs omit maxWidth that core save does not reproduce', json_encode($smallBusinessInlineGeometryAttrs));
$assert(str_contains($smallBusinessInlineGeometryMarkup, 'style="margin-top:1rem"'), 'paragraph markup retains native margin-top support', $smallBusinessInlineGeometryMarkup);
$assert(! str_contains($smallBusinessInlineGeometryMarkup, 'max-width:380px'), 'paragraph markup omits max-width that core save does not reproduce', $smallBusinessInlineGeometryMarkup);
$assert(str_contains($smallBusinessAssets, '.' . preg_replace('/^.*\b(be-inline-geometry-[^\s]+).*$/', '$1', $smallBusinessGeometryClass) . '{max-width:380px !important}'), 'generated geometry stylesheet preserves paragraph max-width deterministically', $smallBusinessAssets);

$paragraphSvgResult = ( new HtmlTransformer() )->transform(
    '<main><p class="social-link"><a class="social-link" href="#" aria-label="Follow"><svg viewBox="0 0 10 10" aria-hidden="true"><path d="M0 0h10v10H0z"></path></svg></a></p></main>'
)->toArray();
$paragraphSvgBlock = $paragraphSvgResult['blocks'][0] ?? array();
$paragraphSvgSerialized = (string) ($paragraphSvgResult['serialized_blocks'] ?? '');
$assert('core/paragraph' === ($paragraphSvgBlock['blockName'] ?? ''), 'paragraph content with a safe inline SVG remains a native RichText paragraph');
$assert(str_contains($paragraphSvgSerialized, '<!-- wp:paragraph'), 'paragraph inline SVG serializes as a native paragraph block');
$assert(str_contains($paragraphSvgSerialized, '<a class="social-link" href="#" aria-label="Follow"><img src="assets/materialized-svg/'), 'paragraph inline SVG materializes as a linked RichText image object with its source link identity');
$assert(! str_contains($paragraphSvgSerialized, '<svg'), 'paragraph inline SVG is not stored as unsupported SVG RichText markup');

$inlineFlexSvgResult = ( new HtmlTransformer() )->transform(
    '<style>.track{display:flex}.token{display:inline-flex;align-items:center;gap:8px}.token svg{width:18px;height:18px}</style><main><div class="track"><span class="token">Open Source <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/></svg></span></div></main>'
)->toArray();
$inlineFlexSvgMarkup = (string) ($inlineFlexSvgResult['serialized_blocks'] ?? '');
$assert(! str_contains($inlineFlexSvgMarkup, 'wp-block-blocks-engine-author-layout') && str_contains($inlineFlexSvgMarkup, '<!-- wp:paragraph'), 'inline flex text and SVG retain valid native paragraph blocks');
$assert(str_contains($inlineFlexSvgMarkup, 'token') && str_contains($inlineFlexSvgMarkup, '<img src="assets/materialized-svg/'), 'inline flex text and its native SVG image retain source styling and materialized media');
$assert(str_contains($inlineFlexSvgMarkup, 'style="width:18px;height:18px"'), 'CSS-owned inline SVG geometry is carried onto the materialized RichText image');

$nestedControlSvg = ( new HtmlTransformer() )->transform('<style>.nested-control{display:flex;align-items:center;gap:14px;height:72px;border:6px solid #65625d;border-top-left-radius:36px;border-top-right-radius:36px;border-bottom-right-radius:36px;border-bottom-left-radius:36px}.nested-control>.nested-icon{width:36px;height:36px}.nested-control>.nested-icon>*{width:100%;height:100%}.nested-icon-source{width:77px;height:77px}</style><button class="nested-control"><span>WhatsApp</span><div class="nested-icon" data-source-visual-width="77" data-source-visual-height="77"><div class="nested-icon-source" data-source-visual-width="77" data-source-visual-height="77"><svg width="100%" height="100%" viewBox="0 0 77 77" aria-hidden="true"><path d="M0 0h77v77H0z"/></svg></div></div></button>')->toArray();
$nestedControlSvgMarkup = (string) ($nestedControlSvg['serialized_blocks'] ?? '');
$nestedControlSvgCss = implode("\n", array_column($nestedControlSvg['assets'] ?? array(), 'content'));
$assert(str_contains($nestedControlSvgMarkup, '<button type="button" class="wp-block-button__link') && str_contains($nestedControlSvgMarkup, '<img src="assets/materialized-svg/'), 'nested button artwork remains native button RichText media');
$assert(str_contains($nestedControlSvgMarkup, 'style="width:36px;height:36px"'), 'nested button artwork resolves percentage geometry through flattened wrappers');
$assert(str_contains($nestedControlSvgCss, '.wp-block-button{height:100%}') && str_contains($nestedControlSvgCss, 'height:100%!important'), 'fixed-height native button fills its projected intermediate wrapper');
$assert(str_contains($nestedControlSvgCss, 'border-top-left-radius:36px!important') && str_contains($nestedControlSvgCss, 'border-bottom-right-radius:36px!important'), 'native outline button preserves authored individual corner radii');
$assert('pass' === ($inlineFlexSvgResult['source_reports']['wp_block_validity']['status'] ?? ''), 'inline flex SVG paragraph remains editor-valid');

$coffeeHtml = (string) file_get_contents(dirname(__DIR__, 3) . '/fixtures/websites/2-onepager-coffee/index.html');
$coffeeResult = ( new HtmlTransformer() )->transform($coffeeHtml, array())->toArray();
$coffeeSerialized = (string) ($coffeeResult['serialized_blocks'] ?? '');
$coffeeStylesheets = array_values(array_filter($coffeeResult['assets'] ?? array(), static fn (array $asset): bool => 'stylesheet' === ($asset['role'] ?? '') && 'text/css' === ($asset['mime_type'] ?? '')));
$coffeeStylesheetCss = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $coffeeStylesheets));
$coffeeRiskCount = 0;
if ( preg_match_all('/<!-- wp:(paragraph|heading|list-item)(?: (\{.*?\}))? -->(.*?)<!-- \/wp:\\1 -->/s', $coffeeSerialized, $coffeeBlocks, PREG_SET_ORDER) ) {
    foreach ( $coffeeBlocks as $coffeeBlock ) {
        $coffeeAttrs = json_decode($coffeeBlock[2] ?? '', true);
        if ( str_contains((string) ($coffeeAttrs['className'] ?? ''), 'blocks-engine-inline-layout-carrier') ) {
            continue;
        }
        if ( preg_match('/<span\b[^>]*(?:class|style)=|<a\b[^>]*style=|<svg\b/i', $coffeeBlock[3]) ) {
            ++$coffeeRiskCount;
        }
    }
}
$assert(0 === $coffeeRiskCount, '2-onepager-coffee emits no unsupported styled RichText nodes or SVG', (string) $coffeeRiskCount);
$assert('pass' === ($coffeeResult['source_reports']['wp_block_validity']['status'] ?? ''), '2-onepager-coffee generated block serialization remains valid after stylesheet materialization');
$assert(str_contains($coffeeStylesheetCss, '.about-section'), '2-onepager-coffee materializes source About-section CSS as class-owned theme CSS');
$assert(str_contains($coffeeStylesheetCss, '.about-title'), '2-onepager-coffee materializes Born from Fog & Flame heading paint/spacing CSS without group style attrs');
$assert(! preg_match('/<!-- wp:group [^>]*"blockGap"/s', $coffeeSerialized), '2-onepager-coffee keeps group spacing out of saved attrs that core/group does not serialize here');

$invalidButtonBlocks = array(
    array(
        'blockName'    => 'core/button',
        'attrs'        => array('text' => 'Book now', 'url' => '/book'),
        'innerBlocks'  => array(),
        'innerHTML'    => '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/contact">Contact us</a></div>',
        'innerContent' => array('<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/contact">Contact us</a></div>'),
    ),
);
$invalidButtonReport = ( new \Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime() )->validateBlockSerialization($invalidButtonBlocks);
$invalidButtonCodes = array_map(static fn (array $finding): string => (string) ($finding['code'] ?? ''), $invalidButtonReport['findings'] ?? array());
$assert('blocks-engine/php-transformer/wp-block-validity-report/v1' === ($invalidButtonReport['schema'] ?? ''), 'runtime exposes WordPress block validity report schema');
$assert('warning' === ($invalidButtonReport['status'] ?? ''), 'runtime warns on button attribute/markup mismatches');
$assert(in_array('button_text_markup_mismatch', $invalidButtonCodes, true), 'runtime reports invalid button text serialization');
$assert(in_array('button_url_markup_mismatch', $invalidButtonCodes, true), 'runtime reports invalid button URL serialization');

$invalidBlockLevelButtonBlocks = array(
    array(
        'blockName'    => 'core/button',
        'attrs'        => array('text' => 'Book now', 'url' => '/book'),
        'innerBlocks'  => array(),
        'innerHTML'    => '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/book"><h3>Book now</h3></a></div>',
        'innerContent' => array('<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/book"><h3>Book now</h3></a></div>'),
    ),
);
$invalidBlockLevelButtonReport = ( new \Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime() )->validateBlockSerialization($invalidBlockLevelButtonBlocks);
$invalidBlockLevelButtonCodes = array_map(static fn (array $finding): string => (string) ($finding['code'] ?? ''), $invalidBlockLevelButtonReport['findings'] ?? array());
$assert(in_array('button_block_level_link_markup', $invalidBlockLevelButtonCodes, true), 'runtime reports invalid block-level button link markup');

// A doubled structural class token on the inner element (the historic core/button
// leak that merged wp-element-button on top of a source className already carrying
// it) makes the stored markup diverge from save(). The canonical save()-shape
// validator must flag it as duplicate_class_token in the pure-PHP loop so the
// regression is caught off the editor gate, even though the duplicate sits on a
// structural child the wrapper shape assertions never inspect.
$duplicateClassTokenButtonBlocks = array(
    array(
        'blockName'    => 'core/button',
        'attrs'        => array('text' => 'Book now', 'url' => '/book'),
        'innerBlocks'  => array(),
        'innerHTML'    => '<div class="wp-block-button"><a class="wp-element-button wp-block-button__link has-text-color has-background wp-element-button" href="/book">Book now</a></div>',
        'innerContent' => array('<div class="wp-block-button"><a class="wp-element-button wp-block-button__link has-text-color has-background wp-element-button" href="/book">Book now</a></div>'),
    ),
);
$duplicateClassTokenReport = ( new \Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime() )->validateBlockSerialization($duplicateClassTokenButtonBlocks);
$duplicateClassTokenCodes = array_map(static fn (array $finding): string => (string) ($finding['code'] ?? ''), $duplicateClassTokenReport['findings'] ?? array());
$assert('warning' === ($duplicateClassTokenReport['status'] ?? ''), 'runtime warns on a button carrying a doubled class token');
$assert(in_array('duplicate_class_token', $duplicateClassTokenCodes, true), 'canonical save()-shape validator flags a duplicate class token on the inner button element');
$duplicateClassTokenFinding = null;
foreach ( $duplicateClassTokenReport['findings'] ?? array() as $finding ) {
    if ( 'duplicate_class_token' === ($finding['code'] ?? '') ) {
        $duplicateClassTokenFinding = $finding;
        break;
    }
}
$assert(is_array($duplicateClassTokenFinding) && in_array('wp-element-button', $duplicateClassTokenFinding['details']['duplicate_tokens'] ?? array(), true), 'duplicate_class_token finding names the doubled wp-element-button token');

// A canonical button (each class emitted once) must not be false-flagged.
$canonicalButtonValidity = (array) ($buttonResult['source_reports']['wp_block_validity'] ?? array());
$canonicalButtonCodes = array_map(static fn (array $finding): string => (string) ($finding['code'] ?? ''), $canonicalButtonValidity['findings'] ?? array());
$assert(! in_array('duplicate_class_token', $canonicalButtonCodes, true), 'canonical generated buttons are not flagged for duplicate class tokens');

$inlineSvgVisualWrapper = ( new HtmlTransformer() )->transform(
    '<main><section class="visual-region"><div class="map-layer"><div class="map-image" style="background-image:url(assets/map.png)"><svg><path d="M0 0h1v1z"></path></svg></div></div></section></main>'
)->toArray();
$serializedInlineSvgVisualWrapper = (string) ($inlineSvgVisualWrapper['serialized_blocks'] ?? '');
$assert(str_contains($serializedInlineSvgVisualWrapper, 'visual-region'), 'HTML transform preserves CSS-addressable visual wrapper classes');
$assert(str_contains($serializedInlineSvgVisualWrapper, 'map-layer'), 'HTML transform preserves nested visual wrapper classes');
$assert(str_contains($serializedInlineSvgVisualWrapper, 'map-image'), 'HTML transform preserves background-image visual leaf classes when inline SVG children are present');

$backgroundContainer = ( new HtmlTransformer() )->transform(
    '<main><section class="hero" style="height:640px;background-image:url(assets/hero.jpg)"><div class="content"><h1>Hero</h1><p>Body</p></div></section></main>'
)->toArray();
$serializedBackgroundContainer = (string) ($backgroundContainer['serialized_blocks'] ?? '');
$backgroundContainerCss = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $backgroundContainer['assets'] ?? array()));
$assert(! str_contains($serializedBackgroundContainer, 'blocks-engine-background-image'), 'background images on content containers do not become in-flow image children');
$assert(str_contains($serializedBackgroundContainer, 'class="wp-block-cover hero') && str_contains($serializedBackgroundContainer, 'class="wp-block-group content'), 'gated background heroes emit core/cover while retaining their CSS-addressable content wrapper');
$assert(! str_contains($backgroundContainerCss, 'background-image:url(assets/hero.jpg)'), 'cover-consumed background paint is not duplicated in the generated author stylesheet');

$emptyBackgroundVisual = ( new HtmlTransformer() )->transform(
    '<main><div class="map-image" style="width:640px;height:320px;background-image:url(assets/map.png)" aria-label="Service area"></div></main>'
)->toArray();
$serializedEmptyBackgroundVisual = (string) ($emptyBackgroundVisual['serialized_blocks'] ?? '');
$assert(str_contains($serializedEmptyBackgroundVisual, 'blocks-engine-background-image'), 'empty background visual elements remain editable image blocks');

// Slice 4 case 1: a gated hero serializes to the exact canonical core/cover
// shape and passes the pure-PHP save-shape validator.
$coverHero = ( new HtmlTransformer() )->transform(
    '<section class="hero" style="background-image:url(https://example.com/hero.jpg);background-size:cover;min-height:480px"><h1>Build</h1><p>Ship faster with blocks.</p></section>'
)->toArray();
$coverHeroSerialized = (string) ($coverHero['serialized_blocks'] ?? '');
$expectedCoverHeroSerialized = '<!-- wp:cover {"className":"hero","url":"https://example.com/hero.jpg","alt":"","dimRatio":0,"minHeight":480} --><div class="wp-block-cover hero" style="min-height:480px"><img class="wp-block-cover__image-background" alt="" src="https://example.com/hero.jpg" data-object-fit="cover"/><span aria-hidden="true" class="wp-block-cover__background has-background-dim-0 has-background-dim"></span><div class="wp-block-cover__inner-container"><!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Build</h1><!-- /wp:heading --><!-- wp:paragraph --><p>Ship faster with blocks.</p><!-- /wp:paragraph --></div></div><!-- /wp:cover -->';
$assert($expectedCoverHeroSerialized === $coverHeroSerialized, 'gated hero serializes to the exact canonical core/cover golden', $coverHeroSerialized);
$assert(array() === ( new CanonicalSaveShapeValidator() )->findings($coverHero['blocks'] ?? array()), 'canonical hero cover passes save-shape validation');

// Slice 4 case 2: a uniform dim overlay moves to cover attributes and the
// overlay span without retaining a second background paint on the hero.
$dimCoverHero = ( new HtmlTransformer() )->transform(
    '<section class="hero" style="background:linear-gradient(rgba(0,0,0,0.5),rgba(0,0,0,0.5)),url(https://example.com/hero.jpg) center/cover;min-height:480px"><h1>Build</h1><p>Ship faster with blocks.</p></section>'
)->toArray();
$dimCoverBlock = $dimCoverHero['blocks'][0] ?? array();
$dimCoverAttrs = is_array($dimCoverBlock['attrs'] ?? null) ? $dimCoverBlock['attrs'] : array();
$dimCoverCss = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $dimCoverHero['assets'] ?? array()));
$assert(50 === ($dimCoverAttrs['dimRatio'] ?? null), 'dim overlay cover records dimRatio 50', json_encode($dimCoverAttrs));
$assert('#000000' === ($dimCoverAttrs['customOverlayColor'] ?? null), 'dim overlay cover records the uniform custom overlay color', json_encode($dimCoverAttrs));
$assert(str_contains((string) ($dimCoverBlock['innerHTML'] ?? ''), '<span aria-hidden="true" class="wp-block-cover__background has-background-dim" style="background-color:#000000"></span>'), 'dim overlay cover paints the overlay span with the custom color');
$assert(! isset($dimCoverAttrs['style']['color']['gradient']) && ! isset($dimCoverAttrs['style']['color']['background']), 'dim overlay cover attrs do not retain hero background paint', json_encode($dimCoverAttrs));
$assert(! str_contains($dimCoverCss, 'background') && ! str_contains($dimCoverCss, 'gradient') && ! str_contains($dimCoverCss, 'hero.jpg'), 'dim overlay cover generated author CSS does not double-paint the hero', $dimCoverCss);

// Slice 4 case 3: a repeating texture remains byte-identical to trunk's
// core/group serialization and never enters the cover path.
$repeatingTexture = ( new HtmlTransformer() )->transform(
    '<div style="background-image:url(https://example.com/texture.png);background-repeat:repeat"><h2>Pricing</h2><p>Plans</p></div>'
)->toArray();
$repeatingTextureSerialized = (string) ($repeatingTexture['serialized_blocks'] ?? '');
$expectedRepeatingTextureSerialized = '<!-- wp:group {"className":"be-inline-geometry-f4d07b1703db9de9dac1e6c7827e053199fb87461a7cc50a0228652699ebb807"} --><div class="wp-block-group be-inline-geometry-f4d07b1703db9de9dac1e6c7827e053199fb87461a7cc50a0228652699ebb807"><!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Pricing</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Plans</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
$assert($expectedRepeatingTextureSerialized === $repeatingTextureSerialized, 'repeating texture preserves byte-identical trunk core/group serialization', $repeatingTextureSerialized);
$assert('core/group' === ($repeatingTexture['blocks'][0]['blockName'] ?? null) && ! str_contains($repeatingTextureSerialized, '<!-- wp:cover'), 'repeating texture is rejected from core/cover');

// Slice 4 case 4: a cover may wrap a recognized two-column content split while
// both wrappers retain canonical save() shapes.
$columnsCoverHero = ( new HtmlTransformer() )->transform(
    '<section class="hero" style="background-image:url(https://example.com/hero.jpg);background-size:cover;min-height:480px"><div class="hero-columns" style="display:flex;gap:24px"><div><h2>Build</h2><p>Plan</p></div><div><h2>Ship</h2><p>Launch</p></div></div></section>'
)->toArray();
$columnsCoverBlock = $columnsCoverHero['blocks'][0] ?? array();
$assert('core/cover' === ($columnsCoverBlock['blockName'] ?? null) && 'core/group' === ($columnsCoverBlock['innerBlocks'][0]['blockName'] ?? null), 'core/cover wraps the CSS-owned core group', json_encode($columnsCoverBlock));
$assert(array() === ( new CanonicalSaveShapeValidator() )->findings($columnsCoverHero['blocks'] ?? array()), 'cover with nested columns passes save-shape validation');

// Slice 4 case 5: an empty background container keeps the tagged core/image
// projection and never enters the text-bearing cover path.
$emptyCoverCandidate = ( new HtmlTransformer() )->transform(
    '<div style="background-image:url(https://example.com/decor.png);background-size:cover;min-height:400px"></div>'
)->toArray();
$emptyCoverCandidateSerialized = (string) ($emptyCoverCandidate['serialized_blocks'] ?? '');
$expectedEmptyCoverCandidateSerialized = '<!-- wp:group {"className":"be-inline-geometry-218c90ba931caddc1d55a64151a2f27f83f6d8e4595b0e904092ee275b5d2485","style":{"dimensions":{"minHeight":"400px"}}} --><div class="wp-block-group be-inline-geometry-218c90ba931caddc1d55a64151a2f27f83f6d8e4595b0e904092ee275b5d2485" style="min-height:400px"><!-- wp:image {"className":"blocks-engine-background-image blocks-engine-background-image-cover blocks-engine-synthetic-image-figure","scale":"cover"} --><figure class="wp-block-image blocks-engine-background-image blocks-engine-background-image-cover blocks-engine-synthetic-image-figure"><img src="https://example.com/decor.png" alt="" style="object-fit:cover"/></figure><!-- /wp:image --></div><!-- /wp:group -->';
$assert($expectedEmptyCoverCandidateSerialized === $emptyCoverCandidateSerialized, 'empty background container preserves exact tagged core/image serialization', $emptyCoverCandidateSerialized);
$assert('core/image' === ($emptyCoverCandidate['blocks'][0]['innerBlocks'][0]['blockName'] ?? null) && 'blocks-engine-background-image blocks-engine-background-image-cover blocks-engine-synthetic-image-figure' === ($emptyCoverCandidate['blocks'][0]['innerBlocks'][0]['attrs']['className'] ?? null) && ! str_contains($emptyCoverCandidateSerialized, '<!-- wp:cover'), 'empty background container retains the tagged core/image path without core/cover');

// Slice 4 L6: support-derived color and spacing declarations retain canonical
// wrapper attribute order before the cover-owned min-height declaration.
$styledCoverHero = ( new HtmlTransformer() )->transform(
    '<section class="hero" style="background-image:url(https://example.com/hero.jpg);background-size:cover;min-height:480px;padding:24px;background-color:#123456"><h1>Build</h1></section>'
)->toArray();
$styledCoverBlock = $styledCoverHero['blocks'][0] ?? array();
$styledCoverOpeningContent = (string) ($styledCoverBlock['innerContent'][0] ?? '');
$styledCoverOpeningEnd = strpos($styledCoverOpeningContent, '>');
$styledCoverWrapperOpening = false === $styledCoverOpeningEnd ? '' : substr($styledCoverOpeningContent, 0, $styledCoverOpeningEnd + 1);
$styledCoverCss = implode("\n", array_column($styledCoverHero['assets'] ?? array(), 'content'));
$assert(str_contains($styledCoverWrapperOpening, 'class="wp-block-cover hero be-inline-geometry-') && str_contains($styledCoverWrapperOpening, 'padding-top:24px;padding-right:24px;padding-bottom:24px;padding-left:24px;min-height:480px') && str_contains($styledCoverCss, 'background-color:#123456 !important'), 'styled cover carries metadata-rejected background while retaining spacing and min-height order', $styledCoverWrapperOpening);
$assert(array() === ( new CanonicalSaveShapeValidator() )->findings($styledCoverHero['blocks'] ?? array()), 'styled cover passes save-shape validation');

$flexIconRow = ( new HtmlTransformer() )->transform(
    '<main><div class="notice-row" style="display: flex; gap: 1rem;"><svg aria-hidden="true" viewBox="0 0 10 10"><circle cx="5" cy="5" r="5"></circle><path d="M2 5h6"></path></svg><div><strong>Venue address</strong><br>Asheville, NC</div></div></main>'
)->toArray();
$serializedFlexIconRow = (string) ($flexIconRow['serialized_blocks'] ?? '');
$assert(array() === ($flexIconRow['fallbacks'] ?? array()), 'decorative SVG flex rows and standalone line breaks do not emit unsupported fallback diagnostics');
$assert(str_contains($serializedFlexIconRow, 'notice-row'), 'decorative SVG flex rows preserve the CSS-addressable wrapper');
$assert(str_contains($serializedFlexIconRow, 'Venue address'), 'decorative SVG flex rows preserve adjacent text content');
$assert(str_contains($serializedFlexIconRow, 'Asheville, NC'), 'standalone line break siblings preserve following text content');
$assert(! str_contains($serializedFlexIconRow, '<!-- wp:columns'), 'decorative SVG flex rows are not misclassified as columns');

$safeInlineSvg = ( new HtmlTransformer() )->transform(
    '<main><section class="icon-row"><span class="icon"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M0 0h16v16H0z"></path></svg></span></section></main>',
    array(
        'strict'          => true,
        'allow_fallbacks' => false,
    )
)->toArray();
$safeInlineSvgSerialized = (string) ($safeInlineSvg['serialized_blocks'] ?? '');
$assert('success' === ($safeInlineSvg['status'] ?? ''), 'safe inline SVG does not trip strict fallback gates', (string) ($safeInlineSvg['status'] ?? ''));
$assert(array() === ($safeInlineSvg['fallbacks'] ?? array()), 'safe decorative inline SVG is consumed instead of recorded as fallback metadata');
$assert('core/group' === ($safeInlineSvg['blocks'][0]['blockName'] ?? ''), 'decorative inline SVG preserves its CSS-addressable wrapper when present');
$assert('core/image' === ($safeInlineSvg['blocks'][0]['innerBlocks'][0]['innerBlocks'][0]['blockName'] ?? ''), 'icon-context decorative SVG is represented as native core/image, not dynamic core/icon');
$assert(str_contains($safeInlineSvgSerialized, '<!-- wp:image'), 'icon-context inline SVG is serialized through core/image');
$assert(str_contains($safeInlineSvgSerialized, 'assets/materialized-svg/'), 'decorative inline SVG uses a materialized SVG asset source');
$assert(str_contains($safeInlineSvgSerialized, 'style="width:16px;height:16px"'), 'decorative icon SVG keeps intrinsic viewBox dimensions through core/image save styles');

$safeInlineSvgAsset = ( new HtmlTransformer() )->transform(
    '<svg role="img" aria-label="Status badge" viewBox="0 0 10 10"><title>Status badge</title><circle cx="5" cy="5" r="4"></circle></svg>'
)->toArray();
$safeInlineSvgAssetUrl = (string) ($safeInlineSvgAsset['blocks'][0]['attrs']['url'] ?? '');
$assert('core/image' === ($safeInlineSvgAsset['blocks'][0]['blockName'] ?? ''), 'simple accessible inline SVG is represented as native core/image, not dynamic core/icon');
$assert('Status badge' === ($safeInlineSvgAsset['blocks'][0]['attrs']['alt'] ?? ''), 'safe accessible inline SVG maps its accessible label to image alt text');
$assert(str_contains($safeInlineSvgAssetUrl, 'assets/materialized-svg/'), 'safe accessible inline SVG serializes a materialized SVG asset URL');
$assert(str_contains((string) ($safeInlineSvgAsset['assets'][0]['content'] ?? ''), 'viewBox="0 0 10 10"'), 'safe accessible inline SVG preserves its correct-case viewBox in the materialized SVG source');
$assert(1 === count(array_filter($safeInlineSvgAsset['assets'] ?? array(), static fn (array $asset): bool => 'svg' === ($asset['kind'] ?? ''))), 'safe accessible inline SVG icon generates one image asset');

$exportedSvgMetadata = ( new HtmlTransformer() )->transform('<svg preserveAspectRatio="none" data-bbox="0 0 200 200" data-type="color" viewBox="0 0 200 200" role="presentation" aria-hidden="true"><g><path data-color="1" d="M200 100c0 55-45 100-100 100S0 155 0 100 45 0 100 0s100 45 100 100z"></path></g></svg>')->toArray();
$assert('core/image' === ($exportedSvgMetadata['blocks'][0]['blockName'] ?? '') && array() === ($exportedSvgMetadata['fallbacks'] ?? array()), 'passive exported SVG metadata and stretched artwork remain native image compatible');

$exportedSvgFilter = ( new HtmlTransformer() )->transform('<svg viewBox="0 0 40 40" focusable="false"><defs><filter id="shadow"><feGaussianBlur stdDeviation="2" result="blur"></feGaussianBlur><feOffset in="blur" x="1" y="1" result="offset"></feOffset><feColorMatrix in="offset" values="1 0 0 0 0 0 1 0 0 0 0 0 1 0 0 0 0 .5 0"></feColorMatrix></filter></defs><rect data-testid="shape" width="40" height="40" filter="url(#shadow)"></rect></svg>')->toArray();
$assert('core/image' === ($exportedSvgFilter['blocks'][0]['blockName'] ?? '') && array() === ($exportedSvgFilter['fallbacks'] ?? array()), 'passive exported SVG filter primitives materialize without raw HTML fallback');

$exportedSvgBoxVariables = ( new HtmlTransformer() )->transform('<div><svg viewBox="0 0 24 24" width="24" height="24" style="height:1em;min-height:calc(var(--nav-icon-width)*1px);min-width:calc(var(--nav-icon-width)*1px);width:1em"><path fill-rule="evenodd" d="M15 5 L16 6 L9 12 L16 18 L15 19 L8 12 Z"></path></svg></div>')->toArray();
$assert('core/image' === ($exportedSvgBoxVariables['blocks'][0]['blockName'] ?? '') && array() === ($exportedSvgBoxVariables['fallbacks'] ?? array()), 'passive SVG root box variables transfer to native image geometry without raw HTML fallback');

$exportedSvgPaintVariable = ( new HtmlTransformer() )->transform('<div><svg viewBox="0 0 24 24" style="fill:var(--icon-color)"><path d="M0 0h24v24H0z"></path></svg></div>')->toArray();
$assert('core/html' === ($exportedSvgPaintVariable['blocks'][0]['blockName'] ?? ''), 'SVG paint variables remain inline because an image document cannot inherit the source custom property');

$complexSvgAsset = ( new HtmlTransformer() )->transform(
    '<svg role="img" aria-label="Site illustration" viewBox="0 0 400 200"><title>Site illustration</title><path d="M0 0h400v200H0z"></path></svg>'
)->toArray();
$complexSvgContent = (string) ($complexSvgAsset['assets'][0]['content'] ?? '');
$assert('core/image' === ($complexSvgAsset['blocks'][0]['blockName'] ?? ''), 'large passive illustrative inline SVG is represented as native core/image');
$assert(1 === count(array_filter($complexSvgAsset['assets'] ?? array(), static fn (array $asset): bool => 'svg' === ($asset['kind'] ?? ''))), 'inline illustrative SVG is externalized to one generated .svg image asset');
$assert(str_contains($complexSvgContent, '<svg') && str_contains($complexSvgContent, 'viewBox="0 0 400 200"'), 'inline illustrative SVG preserves its viewBox casing so it scales correctly');
$assert(str_contains($complexSvgContent, 'role="img"') && str_contains($complexSvgContent, 'aria-label="Site illustration"'), 'inline illustrative SVG preserves accessibility attributes');

$mathMlResult = ( new HtmlTransformer() )->transform('<main><math><mi>x</mi><mo>=</mo><mn>2</mn></math></main>')->toArray();
$mathMlBlock = $mathMlResult['blocks'][0] ?? array();
$assert('core/math' === ($mathMlBlock['blockName'] ?? ''), 'MathML converts to a core math block');
$assert(str_contains((string) ($mathMlBlock['attrs']['content'] ?? ''), '<math>'), 'MathML core math block preserves the expression markup');

$texClassResult = ( new HtmlTransformer() )->transform('<main><span class="katex">E = mc^2</span><p>\(a^2 + b^2 = c^2\)</p></main>')->toArray();
$texClassBlocks = $texClassResult['blocks'][0]['innerBlocks'] ?? array();
$assert('core/math' === ($texClassBlocks[0]['blockName'] ?? ''), 'math-like class wrapper converts to a core math block');
$assert('E = mc^2' === ($texClassBlocks[0]['attrs']['content'] ?? ''), 'math-like class wrapper preserves expression text');
$assert('core/math' === ($texClassBlocks[1]['blockName'] ?? ''), 'TeX-delimited text wrapper converts to a core math block');
$assert(str_contains((string) ($texClassBlocks[1]['attrs']['content'] ?? ''), 'a^2 + b^2 = c^2'), 'TeX-delimited math preserves expression content');

$unsafeInlineSvg = ( new HtmlTransformer() )->transform('<main><svg onload="alert(1)"><path d="M0 0h1v1z"></path></svg></main>')->toArray();
$unsafeInlineSvgContent = (string) ($unsafeInlineSvg['blocks'][0]['attrs']['content'] ?? '');
$assert('core/html' === ($unsafeInlineSvg['blocks'][0]['blockName'] ?? ''), 'unsafe inline SVG is sanitized and preserved as a core/html block instead of being dropped');
$assert(array() === ($unsafeInlineSvg['fallbacks'] ?? array()), 'inline SVG with stripped unsafe parts keeps its artwork and emits no fallback diagnostic');
$assert(str_contains($unsafeInlineSvgContent, '<svg') && str_contains($unsafeInlineSvgContent, '<path'), 'sanitized inline SVG keeps its shape markup');
$assert(! str_contains($unsafeInlineSvgContent, 'onload'), 'sanitized inline SVG strips event-handler attributes while keeping the shapes');

$asideContainer = ( new HtmlTransformer() )->transform(
    '<main><aside class="sidebar"><h2>Docs</h2><nav><a href="/start">Start</a><a href="/api">API</a></nav></aside><section><h1>Content</h1></section></main>',
    array(
        'strict'          => true,
        'allow_fallbacks' => false,
    )
)->toArray();
$asideSerialized = (string) ($asideContainer['serialized_blocks'] ?? '');
$assert('success' === ($asideContainer['status'] ?? ''), 'semantic aside containers convert without strict fallback failures', (string) ($asideContainer['status'] ?? ''));
$assert(array() === ($asideContainer['fallbacks'] ?? array()), 'semantic aside containers are treated as layout wrappers, not unsupported fallbacks');
$assert(str_contains($asideSerialized, 'sidebar'), 'semantic aside container preserves CSS-addressable sidebar class');
$assert(str_contains($asideSerialized, '<!-- wp:navigation'), 'semantic aside container preserves nested navigation patterns');

$sidebarFormLayout = ( new HtmlTransformer() )->transform(
    '<main><div class="contact-content"><!-- left rail --><aside class="contact-sidebar"><h2>Booking</h2><p>Email us.</p></aside><!-- form pane --><div class="contact-form-wrap"><h2>Contact</h2><form><label>Name<input name="name"></label><button type="submit">Send</button></form></div></div></main>',
    array(
        'strict'          => true,
        'allow_fallbacks' => true,
    )
)->toArray();
$sidebarFormSerialized = (string) ($sidebarFormLayout['serialized_blocks'] ?? '');
$assert(str_contains($sidebarFormSerialized, '<!-- wp:columns'), 'sidebar plus form layouts convert to native columns instead of one raw HTML island');
$assert(str_contains($sidebarFormSerialized, 'contact-sidebar'), 'sidebar plus form layout preserves sidebar class on the column wrapper');
$assert(str_contains($sidebarFormSerialized, 'contact-form-wrap'), 'sidebar plus form layout preserves form-side class on the column wrapper');

$nonprofitNavigation = ( new HtmlTransformer() )->transform(
    '<header><nav aria-label="Main navigation"><ul><li><a href="/">Home</a></li><li><a href="/the-measure/">The Measure</a></li><li><a href="/supporters/">Supporters</a></li><li><a href="/volunteer/">Volunteer</a></li><li><a href="/donate/">Donate</a></li><li><a href="/faq/">FAQ</a></li><li><a href="/vote-yes/">Vote YES</a></li></ul></nav></header><main><h1>Campaign</h1></main><footer>Paid for by neighbors.</footer>',
    array(
        'strict'          => true,
        'allow_fallbacks' => false,
    )
)->toArray();
$nonprofitSemanticParity = $nonprofitNavigation['source_reports']['semantic_parity'] ?? array();
$nonprofitConversionSemanticParity = $nonprofitNavigation['source_reports']['conversion_report']['semantic_parity'] ?? array();
$nonprofitBlockMenu = $nonprofitSemanticParity['navigation_menus']['blocks'][0] ?? array();
$assert('success' === ($nonprofitNavigation['status'] ?? ''), 'nonprofit-style navigation converts without strict fallback failures', (string) ($nonprofitNavigation['status'] ?? ''));
$assert('pass' === ($nonprofitSemanticParity['status'] ?? ''), 'semantic parity passes for nonprofit-style source navigation');
$assert('pass' === ($nonprofitConversionSemanticParity['status'] ?? ''), 'conversion report projects semantic parity status');
$assert(1 === ($nonprofitSemanticParity['landmarks']['source']['nav'] ?? null), 'semantic parity counts source nav landmarks');
$assert(1 === ($nonprofitSemanticParity['landmarks']['blocks']['nav'] ?? null), 'semantic parity counts generated core navigation landmarks');
$assert(7 === ($nonprofitBlockMenu['item_count'] ?? null), 'semantic parity counts generated core navigation menu items');
$assert(true === ($nonprofitBlockMenu['represented_as_core_navigation'] ?? null), 'semantic parity reports menus represented as core/navigation');
$assert('The Measure' === ($nonprofitBlockMenu['items'][1]['label'] ?? ''), 'semantic parity preserves navigation item labels');
$assert('/vote-yes/' === ($nonprofitBlockMenu['items'][6]['url'] ?? ''), 'semantic parity preserves navigation item URLs');

$navigationLabelResult = ( new HtmlTransformer() )->transform(
    '<header><nav><a href="/docs"><h3>Docs</h3><span aria-hidden="true"></span></a><ul><li><a href="/guides"><div>Guides</div></a><ul><li><a href="/api"><p>API</p></a></li></ul></li></ul></nav></header>'
)->toArray();
$navigationLabelBlocks = $navigationLabelResult['blocks'][0]['innerBlocks'] ?? array();
$assert('Docs' === ($navigationLabelBlocks[0]['attrs']['label'] ?? ''), 'direct navigation link label unwraps block-level markup for valid inline RichText');
$assert('Guides' === ($navigationLabelBlocks[1]['attrs']['label'] ?? ''), 'navigation submenu label unwraps block-level markup for valid inline RichText');
$assert('API' === ($navigationLabelBlocks[1]['innerBlocks'][0]['attrs']['label'] ?? ''), 'nested navigation link label unwraps block-level markup for valid inline RichText');
$assert(! str_contains((string) ($navigationLabelResult['serialized_blocks'] ?? ''), '<h3>Docs</h3>'), 'navigation serialization avoids heading markup inside link text');
$assert(! str_contains((string) ($navigationLabelResult['serialized_blocks'] ?? ''), '<div>Guides</div>'), 'navigation serialization avoids div markup inside submenu link text');
$assert('pass' === ($navigationLabelResult['source_reports']['wp_block_validity']['status'] ?? ''), 'navigation labels with block-level source markup pass WordPress block validity');

$listGapNavigation = ( new HtmlTransformer() )->transform(
    '<nav><ul style="gap:0"><li><a href="/one">One</a></li><li><a href="/two">Two</a></li></ul></nav>'
)->toArray();
$listGapNavigationBlock = $listGapNavigation['blocks'][0] ?? array();
$listGapNavigationSerialized = (string) ($listGapNavigation['serialized_blocks'] ?? '');
$assert('0' === ($listGapNavigationBlock['attrs']['style']['spacing']['blockGap'] ?? ''), 'direct navigation list gap projects onto core/navigation');
$assert('pass' === ($listGapNavigation['source_reports']['semantic_parity']['status'] ?? ''), 'direct navigation list gap preserves semantic parity');
$assert('pass' === ($listGapNavigation['source_reports']['wp_block_validity']['status'] ?? ''), 'direct navigation list gap serializes to a valid WordPress block');
$assert(str_contains($listGapNavigationSerialized, '<!-- wp:navigation ') && str_contains($listGapNavigationSerialized, '"blockGap":"0"'), 'direct navigation list gap uses canonical dynamic navigation serialization');

$wrappedListGapNavigation = ( new HtmlTransformer() )->transform(
    '<style>.wsite-menu-default{display:flex;gap:20px}</style><nav aria-label="Primary"><div class="nav-wrap"><ul class="wsite-menu-default"><li><a href="/one">One</a></li><li><a href="/two">Two</a></li></ul></div></nav>'
)->toArray();
$wrappedListGapNavigationAttrs = $wrappedListGapNavigation['blocks'][0]['attrs'] ?? array();
$wrappedListGapNavigationSerialized = (string) ($wrappedListGapNavigation['serialized_blocks'] ?? '');
$assert('20px' === ($wrappedListGapNavigationAttrs['style']['spacing']['blockGap'] ?? ''), '#748 wrapper-originated navigation preserves the authored list gap');
$assert(str_contains((string) ($wrappedListGapNavigationAttrs['className'] ?? ''), 'wsite-menu-default'), '#748 wrapper-originated navigation keeps the logical source-list class');
$assert(str_contains($wrappedListGapNavigationSerialized, '"blockGap":"20px"'), '#748 wrapper-originated navigation serializes native block spacing');
$assert('pass' === ($wrappedListGapNavigation['source_reports']['semantic_parity']['status'] ?? ''), '#748 wrapper-originated navigation preserves semantic parity');
$assert('pass' === ($wrappedListGapNavigation['source_reports']['wp_block_validity']['status'] ?? ''), '#748 wrapper-originated navigation stays editor-valid');
$wrappedListGapNavigationCss = implode("\n", array_column($wrappedListGapNavigation['assets'] ?? array(), 'content'));
$assert(str_contains($wrappedListGapNavigationCss, '.wp-block-navigation.blocks-engine-list-navigation .wp-block-navigation__container{display:flex;flex-direction:row;flex-wrap:wrap;list-style:none}'), 'list-navigation inner container stays a row without !important');

$outerGapNavigation = ( new HtmlTransformer() )->transform(
    '<nav style="gap:1rem"><ul style="gap:0"><li><a href="/one">One</a></li><li><a href="/two">Two</a></li></ul></nav>'
)->toArray();
$assert('1rem' === ($outerGapNavigation['blocks'][0]['attrs']['style']['spacing']['blockGap'] ?? ''), 'outer navigation gap takes precedence over direct list gap');

$brandedListNavigation = ( new HtmlTransformer() )->transform(
    '<style>nav{display:flex}.links{display:flex}</style><nav><a class="brand" href="/">Brand</a><ul class="links"><li><a href="/one">One</a></li><li><a href="/two">Two</a></li></ul><a class="cta" href="/start">Start</a></nav>'
)->toArray();
$assert('pass' === ($brandedListNavigation['source_reports']['semantic_parity']['status'] ?? ''), 'navigation parity counts a direct list menu without counting brand and CTA siblings');
$assert(2 === ($brandedListNavigation['source_reports']['semantic_parity']['navigation_menus']['source'][0]['item_count'] ?? null), 'source navigation menu uses the direct list item count when sibling controls are present');

$footerNavigationSections = ( new HtmlTransformer() )->transform(
    '<footer><div class="footer-grid"><nav aria-label="Product"><h3>Product</h3><ul><li><a class="footer-link" href="/features">Features</a></li><li><a class="footer-link" href="/pricing">Pricing</a></li></ul></nav><nav aria-label="Company"><p class="nav-title">Company</p><a class="footer-link" href="/about">About</a><a class="footer-link" href="/contact">Contact</a></nav><nav class="social-links" aria-label="Social"><a class="social-link" href="https://example.com/mastodon" aria-label="Mastodon"><svg aria-hidden="true"><path d="M0 0h1v1z"></path></svg></a><a class="social-link" href="https://example.com/github" title="GitHub"><span aria-hidden="true"></span></a></nav></div></footer>'
)->toArray();
$footerNavigationParity = $footerNavigationSections['source_reports']['semantic_parity'] ?? array();
$footerNavigationMenus = $footerNavigationParity['navigation_menus']['blocks'] ?? array();
$footerNavigationSerialized = (string) ($footerNavigationSections['serialized_blocks'] ?? '');
$assert('pass' === ($footerNavigationParity['status'] ?? ''), 'footer navigation sections with headings and social labels pass semantic parity');
$assert(3 === count($footerNavigationMenus), 'footer navigation sections emit one core/navigation block per source nav landmark');
$assert(2 === ($footerNavigationMenus[0]['item_count'] ?? null), 'footer heading nav preserves list link count');
$assert('Mastodon' === ($footerNavigationMenus[2]['items'][0]['label'] ?? ''), 'icon-only social links use aria-label as navigation label');
$assert('GitHub' === ($footerNavigationMenus[2]['items'][1]['label'] ?? ''), 'icon-only social links use title as navigation label');
$assert(str_contains($footerNavigationSerialized, 'footer-link'), 'footer navigation preserves link classes for styling and script targets');
$assert(str_contains($footerNavigationSerialized, 'social-link'), 'social navigation preserves social link classes for styling and script targets');
$assert(str_contains($footerNavigationSerialized, '<!-- wp:heading {"level":3}') && str_contains($footerNavigationSerialized, '>Product</h3>'), 'labeled footer navigation preserves its heading as native content');
$assert(str_contains($footerNavigationSerialized, '<!-- wp:paragraph {"className":"nav-title"}') && str_contains($footerNavigationSerialized, '>Company</p>'), 'paragraph-labeled footer navigation preserves its descriptive title');
$assert(2 === substr_count($footerNavigationSerialized, '"orientation":"vertical"'), 'labeled footer navigation retains vertical column flow without changing unlabeled social navigation');

$complexHeaderNavigation = ( new HtmlTransformer() )->transform(
    '<header class="site-header"><div class="header-inner"><button class="menu-toggle" aria-expanded="false" aria-controls="menu">Menu</button><nav class="primary-nav" aria-label="Primary"><div id="menu" class="nav-list"><a href="/">Home</a><a class="nav-divider" role="separator" href="#">/</a><span class="separator">|</span><button class="dropdown-toggle" aria-expanded="false">More</button><a href="/shop"><span>Shop</span><svg aria-hidden="true"><path d="M0 0h1v1z"></path></svg></a><ul><li><a href="/services">Services</a><ul><li><a href="/consulting">Consulting</a></li></ul></li></ul><a class="icon-button" href="/cart" aria-label="Cart"><svg aria-hidden="true"><path d="M0 0h1v1z"></path></svg></a></div></nav><div class="mobile-nav overlay"><div class="drawer-panel"><nav class="drawer-nav" aria-label="Mobile"><a href="/">Home</a><a href="/shop">Shop</a><ul><li><a href="/services">Services</a><ul><li><a href="/consulting">Consulting</a></li></ul></li></ul><a class="icon-button" href="/cart" aria-label="Cart"><svg aria-hidden="true"><path d="M0 0h1v1z"></path></svg></a></nav></div></div></div></header>'
)->toArray();
$complexHeaderParity = $complexHeaderNavigation['source_reports']['semantic_parity'] ?? array();
$complexHeaderBlockMenus = $complexHeaderParity['navigation_menus']['blocks'] ?? array();
$complexHeaderSourceMenus = $complexHeaderParity['navigation_menus']['source'] ?? array();
$assert('pass' === ($complexHeaderParity['status'] ?? ''), 'complex header navigation chrome preserves semantic parity');
$assert(1 === count($complexHeaderSourceMenus), 'source semantic parity dedupes duplicated mobile drawer navigation');
$assert(1 === count($complexHeaderBlockMenus), 'generated navigation dedupes duplicated mobile drawer navigation');
$assert(5 === ($complexHeaderBlockMenus[0]['item_count'] ?? null), 'complex header navigation skips chrome and preserves real item count');
$assert('Cart' === ($complexHeaderBlockMenus[0]['items'][4]['label'] ?? ''), 'icon-only header navigation links use accessible labels');
$assert(! str_contains((string) ($complexHeaderNavigation['serialized_blocks'] ?? ''), 'drawer-nav'), 'complex header navigation removes duplicate mobile drawer core/navigation children');

$visibleVariantNavigation = ( new HtmlTransformer() )->transform(
    '<header><nav class="placeholder" style="display:none"><a href="/">Home</a><a href="/about">About</a></nav><nav class="primary"><a href="/">Home</a><a href="/about">About</a></nav><label><span><div></div><div></div><div></div></span><span>menu</span><span>close</span></label></header><nav class="collapsed-nav" style="display:none"><a href="/">Home</a><a href="/about">About</a></nav>'
)->toArray();
$visibleVariantSerialized = (string) ($visibleVariantNavigation['serialized_blocks'] ?? '');
$assert(1 === substr_count($visibleVariantSerialized, '<!-- wp:navigation {'), 'equivalent sibling navigation variants retain only the visible source variant');
$assert(str_contains($visibleVariantSerialized, 'primary') && ! str_contains($visibleVariantSerialized, 'placeholder') && ! str_contains($visibleVariantSerialized, 'collapsed-nav'), 'navigation deduplication prefers the visible source variant over hidden placeholder and collapsed variants');
$assert(! str_contains($visibleVariantSerialized, '>menu<') && ! str_contains($visibleVariantSerialized, '>close<'), 'input-free label hamburger chrome is superseded by native navigation controls');

$splitResponsiveSubmenu = ( new HtmlTransformer() )->transform(
    '<header><div class="desktop"><ul class="menu"><li id="home"><a href="/">Home</a></li><li id="portfolio"><a>Portfolio</a></li><li id="about"><a href="/about">About</a></li></ul></div>'
    . '<div class="mobile" style="display:none"><ul class="menu"><li id="home"><a href="/">Home</a></li><li id="portfolio" class="has-submenu"><a>Portfolio</a><div class="menu-wrap"><ul><li><a href="/portraits">Portraits</a></li><li><a href="/families">Families</a></li></ul></div></li><li id="about"><a href="/about">About</a></li></ul></div></header>'
)->toArray();
$splitResponsiveSubmenuSerialized = (string) ($splitResponsiveSubmenu['serialized_blocks'] ?? '');
$splitResponsiveSubmenuMenus = $splitResponsiveSubmenu['source_reports']['semantic_parity']['navigation_menus']['blocks'] ?? array();
$assert(1 === substr_count($splitResponsiveSubmenuSerialized, '<!-- wp:navigation {'), 'split responsive menu variants reconcile into one canonical navigation block');
$assert(1 === substr_count($splitResponsiveSubmenuSerialized, '<!-- wp:navigation-submenu '), 'visible shallow navigation item adopts the duplicate responsive submenu');
$assert(str_contains($splitResponsiveSubmenuSerialized, '"label":"Portfolio"') && str_contains($splitResponsiveSubmenuSerialized, '"label":"Portraits","url":"/portraits"') && str_contains($splitResponsiveSubmenuSerialized, '"label":"Families","url":"/families"'), 'reconciled submenu preserves the parent label and ordered child destinations');
$assert(5 === ($splitResponsiveSubmenuMenus[0]['item_count'] ?? null), 'reconciled responsive navigation reports every parent and submenu item');
$assert('pass' === ($splitResponsiveSubmenu['source_reports']['wp_block_validity']['status'] ?? ''), 'reconciled responsive submenu remains WordPress block-valid');

$bodyStateProjection = ( new HtmlTransformer() )->transform(
    '<!doctype html><html><body class="fixed-shell no-header-page"><div class="wrapper"><div class="main-wrap"><p>Content</p></div></div></body></html>',
    array( 'static_css' => '.no-header-page .main-wrap{padding-top:80px}body.fixed-shell .main-wrap{background:#fff}' )
)->toArray();
$bodyStateSerialized = (string) ($bodyStateProjection['serialized_blocks'] ?? '');
$bodyStateCss = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $bodyStateProjection['assets'] ?? array()));
$assert(str_contains($bodyStateSerialized, 'wrapper fixed-shell no-header-page') && str_contains($bodyStateSerialized, 'main-wrap'), 'stylesheet-referenced body state projects onto converted root blocks');
$assert(str_contains($bodyStateCss, '.no-header-page .main-wrap{padding-top:80px}'), 'body-state descendant selectors continue matching beneath the projected root block state');
$assert(str_contains($bodyStateCss, '.fixed-shell .main-wrap{background:#fff}') && ! str_contains($bodyStateCss, 'body.fixed-shell'), 'explicit body-state selectors retarget the projected root state while retaining descendant structure');

$styledLogo = ( new HtmlTransformer() )->transform(
    '<style>#wordmark{font-family:Fjalla One,sans-serif;font-size:36px}</style><a class="logo" href="/"><span id="wordmark">Brand Name</span></a>'
)->toArray();
$styledLogoSerialized = (string) ($styledLogo['serialized_blocks'] ?? '');
$assert(str_contains($styledLogoSerialized, '<mark style="') && str_contains($styledLogoSerialized, 'Brand Name</mark>'), 'selector-addressed logo labels retain a valid RichText semantic marker instead of flattening to plain text');
$assert('pass' === ($styledLogo['source_reports']['wp_block_validity']['status'] ?? ''), 'selector-addressed logo label markers remain editor-valid');

$nestedStyledLogo = ( new HtmlTransformer() )->transform(
    '<style>.header #wordmark{font-family:Fjalla One,sans-serif;font-size:36px}</style><div class="header"><div class="logo"><span><a href="/"><span id="wordmark">Nested Brand</span></a></span></div></div>'
)->toArray();
$nestedStyledLogoSerialized = (string) ($nestedStyledLogo['serialized_blocks'] ?? '');
$assert(str_contains($nestedStyledLogoSerialized, '<a href="/"><mark style="') && str_contains($nestedStyledLogoSerialized, 'Nested Brand</mark></a>'), 'nested logo chrome unwraps presentational containers while preserving the styled semantic label and link');
$assert(str_contains($nestedStyledLogoSerialized, '"margin":{"top":"0","right":"0","bottom":"0","left":"0"}'), 'div-based logos neutralize core paragraph margins that the source element did not have');
$explicitLogoMargin = ( new HtmlTransformer() )->transform('<div class="logo" style="margin-bottom:4px"><a href="/">Brand</a></div>')->toArray();
$explicitLogoMarginSerialized = (string) ($explicitLogoMargin['serialized_blocks'] ?? '');
$assert(str_contains($explicitLogoMarginSerialized, '"margin":{"top":"0","right":"0","bottom":"4px","left":"0"}'), 'div-based logo margin defaults preserve explicitly authored source sides');

$brandedHeaderNavigation = ( new HtmlTransformer() )->transform(
    '<header><div class="container"><nav class="nav-inner" aria-label="Main navigation"><a href="/" class="nav-logo" aria-label="Acme home"><svg aria-hidden="true"><path d="M0 0h1v1z"></path></svg><span>Acme</span></a><ul class="nav-links"><li><a href="/work">Work</a></li><li><a href="/pricing">Pricing</a></li><li><a href="/about">About</a></li></ul><div class="nav-actions"><a href="/start" class="button">Get Started</a><button class="nav-toggle" aria-label="Open menu" aria-expanded="false"><span></span><span></span></button></div></nav></div></header>'
)->toArray();
$brandedHeaderParity = $brandedHeaderNavigation['source_reports']['semantic_parity'] ?? array();
$brandedHeaderBlockMenu = $brandedHeaderParity['navigation_menus']['blocks'][0] ?? array();
$assert('pass' === ($brandedHeaderParity['status'] ?? ''), 'branded header nav with mobile toggle preserves semantic parity');
$assert(3 === ($brandedHeaderBlockMenu['item_count'] ?? null), 'branded header nav counts signaled menu links while preserving surrounding chrome separately');
$assert('Work' === ($brandedHeaderBlockMenu['items'][0]['label'] ?? ''), 'branded header nav preserves first menu link label');
$assert(3 === ($brandedHeaderParity['navigation_menus']['source'][0]['item_count'] ?? null), 'branded header source parity counts the same signaled menu subset as generated navigation');

$dropdownHeaderNavigation = ( new HtmlTransformer() )->transform(
    '<style>.dropdown{background:#181818;color:#f2f2f2}</style><header><nav class="main-nav" aria-label="Main navigation"><div class="nav-item"><a href="/shop" class="nav-link">Shop All</a></div><div class="nav-item"><a href="/outing" class="nav-link">By Outing <svg aria-hidden="true"><path d="M0 0h1v1z"></path></svg></a><div class="dropdown"><a href="/outing#day" class="dropdown__link">Day Hike</a><a href="/outing#camp" class="dropdown__link">Weekend Camp</a></div></div><div class="nav-item"><a href="/bundles" class="nav-link">Bundles</a></div></nav></header>'
)->toArray();
$dropdownHeaderSerialized = (string) ($dropdownHeaderNavigation['serialized_blocks'] ?? '');
$dropdownHeaderCss = implode("\n", array_column($dropdownHeaderNavigation['assets'] ?? array(), 'content'));
$dropdownHeaderParity = $dropdownHeaderNavigation['source_reports']['semantic_parity'] ?? array();
$dropdownHeaderBlockMenu = $dropdownHeaderParity['navigation_menus']['blocks'][0] ?? array();
$assert('pass' === ($dropdownHeaderParity['status'] ?? ''), 'dropdown header nav wrappers preserve semantic parity');
$assert(5 === ($dropdownHeaderBlockMenu['item_count'] ?? null), 'dropdown header nav counts parent and submenu items consistently');
$assert('Day Hike' === ($dropdownHeaderBlockMenu['items'][2]['label'] ?? ''), 'dropdown header nav preserves submenu item labels');
$assert(! str_contains($dropdownHeaderSerialized, '"color":{"background":"#181818"}') && str_contains($dropdownHeaderCss, '.dropdown{background:#181818;color:#f2f2f2}'), 'dropdown header navigation retains authored submenu background in its projected stylesheet');

$nestedNavMenu = ( new HtmlTransformer() )->transform(
    '<nav aria-label="Main"><ul><li><a href="/coffee">Coffee</a><nav id="nav-links" class="wp-block-navigation nav-links" style="display:none;align-items:flex-start;gap:1.4rem;background:var(--cream);flex-direction:column;padding:1.8rem var(--gutter) 2rem;box-shadow:0 10px 20px rgba(0,0,0,.2)"><a href="#espresso">Espresso</a><a href="#latte">Latte</a></nav></li><li><a href="/visit">Visit</a></li></ul></nav>'
)->toArray();
$nestedNavMenuSerialized = (string) ($nestedNavMenu['serialized_blocks'] ?? '');
$nestedNavMenuParity = $nestedNavMenu['source_reports']['semantic_parity'] ?? array();
$nestedNavMenuBlock = $nestedNavMenuParity['navigation_menus']['blocks'][0] ?? array();
$nestedNavMenuSubmenuSource = array_values(array_filter(
    $nestedNavMenu['source_reports']['html']['source_provenance'] ?? array(),
    static fn (array $entry): bool => str_contains((string) ($entry['navigation_source_ownership']['submenu']['class_name'] ?? ''), 'nav-links')
));
$assert('pass' === ($nestedNavMenu['source_reports']['wp_block_validity']['status'] ?? ''), 'nested nav/menu serializes to valid WordPress navigation blocks');
$assert(str_contains($nestedNavMenuSerialized, '<!-- wp:navigation-submenu'), 'nested nav/menu emits a canonical navigation-submenu block');
$assert(! str_contains($nestedNavMenuSerialized, '<nav id="nav-links"'), 'nested nav/menu does not embed a raw nav wrapper inside core/navigation content');
$assert(! str_contains($nestedNavMenuSerialized, 'style="display:none'), 'nested nav/menu does not freeze hidden raw inline nav styles into serialized block markup');
$assert(! str_contains($nestedNavMenuSerialized, 'wp-block-navigation nav-links'), 'nested nav/menu strips core wrapper classes while preserving custom nav classes');
$assert(str_contains((string) ($nestedNavMenuSubmenuSource[0]['navigation_source_ownership']['submenu']['class_name'] ?? ''), 'nav-links'), 'nested nav/menu preserves custom submenu ownership in the stable source report');
$assert(! str_contains($nestedNavMenuSerialized, 'anchorClassName') && ! str_contains($nestedNavMenuSerialized, 'submenuClassName'), 'nested nav/menu serializes no unregistered navigation source attributes');
$assert(4 === ($nestedNavMenuBlock['item_count'] ?? null), 'nested nav/menu preserves parent, submenu, and sibling link items');
$assert('Latte' === ($nestedNavMenuBlock['items'][2]['label'] ?? ''), 'nested nav/menu preserves submenu item labels');

// Regression: a <nav> that sits as a SIBLING of a brand/logo and a menu-toggle
// inside header/footer "chrome" container divs (direct-anchor menus, no <ul>)
// must still be represented as core/navigation. This locks in the diagnostic
// findings html_semantic_parity_landmark_count_mismatch (header nav) and
// html_semantic_parity_navigation_menu_missing (footer nav) reported against an
// earlier deployed transformer for shared-chrome static sites. Markup is generic
// (structural signals only — no fixture-specific class names).
$chromeHeaderNavigation = ( new HtmlTransformer() )->transform(
    '<header class="masthead" role="banner"><div class="bar inner"><a class="logo" href="/" aria-label="Brand home"><svg viewBox="0 0 10 10" aria-hidden="true"><path d="M0 0h1v1z"></path></svg><span>Brand</span></a><nav class="primary" aria-label="Primary navigation"><a href="/">Home</a><a href="/about">About</a><a href="/teams">Teams</a><a href="/contact">Contact</a></nav><button class="burger" aria-label="Open navigation menu" aria-expanded="false" aria-controls="drawer"><span></span><span></span><span></span></button></div></header><nav class="drawer" id="drawer" aria-label="Mobile navigation"><a href="/">Home</a><a href="/about">About</a><a href="/teams">Teams</a><a href="/contact">Contact</a></nav>'
)->toArray();
$chromeHeaderParity = $chromeHeaderNavigation['source_reports']['semantic_parity'] ?? array();
$chromeHeaderBlockMenu = $chromeHeaderParity['navigation_menus']['blocks'][0] ?? array();
$chromeHeaderFindingCodes = array_map(static fn ($f): string => (string) ($f['code'] ?? ''), $chromeHeaderParity['findings'] ?? array());
$assert('pass' === ($chromeHeaderParity['status'] ?? ''), 'header chrome sibling nav (brand + nav + toggle) preserves semantic parity');
$assert(! in_array('landmark_count_mismatch', $chromeHeaderFindingCodes, true), 'header chrome sibling nav avoids landmark_count_mismatch loss');
$assert(($chromeHeaderParity['landmarks']['source']['nav'] ?? -1) === ($chromeHeaderParity['landmarks']['blocks']['nav'] ?? -2), 'header chrome sibling nav generates one core navigation landmark per source nav landmark');
$assert(1 === count($chromeHeaderParity['navigation_menus']['blocks'] ?? array()), 'header chrome sibling nav dedupes the mobile drawer duplicate menu');
$assert(true === ($chromeHeaderBlockMenu['represented_as_core_navigation'] ?? null), 'header chrome sibling nav is represented as core/navigation');
$assert(4 === ($chromeHeaderBlockMenu['item_count'] ?? null), 'header chrome sibling nav preserves all direct-anchor menu items');
$assert('Home' === ($chromeHeaderBlockMenu['items'][0]['label'] ?? ''), 'header chrome sibling nav preserves menu item labels');

$chromeFooterNavigation = ( new HtmlTransformer() )->transform(
    '<footer class="colophon"><div class="wrap"><div class="cols"><div class="about"><span>Brand Org</span></div><nav class="secondary" aria-label="Footer navigation"><a href="/">Home</a><a href="/about">About</a><a href="/teams">Teams</a><a href="/contact">Contact</a></nav></div><div class="legal">(c) 2026 Brand.</div></div></footer>'
)->toArray();
$chromeFooterParity = $chromeFooterNavigation['source_reports']['semantic_parity'] ?? array();
$chromeFooterBlockMenu = $chromeFooterParity['navigation_menus']['blocks'][0] ?? array();
$chromeFooterFindingCodes = array_map(static fn ($f): string => (string) ($f['code'] ?? ''), $chromeFooterParity['findings'] ?? array());
$assert('pass' === ($chromeFooterParity['status'] ?? ''), 'footer chrome nested-div sibling nav preserves semantic parity');
$assert(! in_array('navigation_menu_missing', $chromeFooterFindingCodes, true), 'footer chrome nested-div sibling nav avoids navigation_menu_missing loss');
$assert(true === ($chromeFooterBlockMenu['represented_as_core_navigation'] ?? null), 'footer chrome nested-div nav is represented as core/navigation');
$assert(4 === ($chromeFooterBlockMenu['item_count'] ?? null), 'footer chrome nested-div nav preserves all direct-anchor menu items');
$assert('Contact' === ($chromeFooterBlockMenu['items'][3]['label'] ?? ''), 'footer chrome nested-div nav preserves last menu item label');

// Regression: a JS-only hamburger menu-toggle that opens a nav which converts to
// core/navigation is redundant chrome (core/navigation ships its own responsive
// overlay) and must be dropped instead of emitted as a dead core/button. The
// toggle is detected by generic structural signals (aria-controls/aria-expanded
// plus empty decorative bars), never by a fixture-specific class string.
$redundantToggleHeader = ( new HtmlTransformer() )->transform(
    '<header><div class="header-inner"><a class="brand" href="/">Logo</a><nav class="nav-links"><a href="/">Home</a><a href="/about">About</a><a href="/contact">Contact</a></nav><button class="nav-toggle" aria-label="Open navigation menu" aria-controls="mobile-nav" aria-expanded="false"><span></span><span></span><span></span></button></div></header><nav class="mobile-nav" id="mobile-nav"><a href="/">Home</a><a href="/about">About</a><a href="/contact">Contact</a></nav>'
)->toArray();
$redundantToggleSerialized = (string) ($redundantToggleHeader['serialized_blocks'] ?? '');
$assert(str_contains($redundantToggleSerialized, '<!-- wp:navigation'), 'redundant menu-toggle header still converts the nav to core/navigation');
$assert(! str_contains($redundantToggleSerialized, '<!-- wp:button'), 'redundant JS hamburger menu-toggle is dropped instead of emitted as a dead core/button');
$assert(! str_contains($redundantToggleSerialized, 'nav-toggle'), 'redundant menu-toggle chrome class is not emitted into block output');

// Negative: a real labeled button, and a toggle-looking control with no associated
// navigation, must still convert to core/button — only redundant chrome is dropped.
$labeledButtons = ( new HtmlTransformer() )->transform(
    '<div class="cta"><button type="submit">Sign Up</button></div><header><button aria-controls="missing" aria-expanded="false"><span></span></button></header>'
)->toArray();
$labeledButtonsSerialized = (string) ($labeledButtons['serialized_blocks'] ?? '');
$assert(str_contains($labeledButtonsSerialized, '<!-- wp:button'), 'labeled/standalone buttons still convert to core/button');
$assert(str_contains($labeledButtonsSerialized, 'Sign Up'), 'labeled button text is preserved as core/button');
$assert(! str_contains($labeledButtonsSerialized, 'aria-controls="missing"'), 'a toggle-looking control with no associated nav omits unsupported ARIA from native core/button markup');

// Recursively counts blocks by name across the block tree (the serialized string
// renders nested navigation/buttons without block-comment delimiters, so structural
// counts are the reliable signal).
$countBlockName = static function (array $blocks, string $name) use (&$countBlockName): int {
    $count = 0;
    foreach ( $blocks as $block ) {
        if ( ! is_array($block) ) {
            continue;
        }
        if ( $name === ( $block['blockName'] ?? '' ) ) {
            $count++;
        }
        if ( ! empty($block['innerBlocks']) && is_array($block['innerBlocks']) ) {
            $count += $countBlockName($block['innerBlocks'], $name);
        }
    }
    return $count;
};

// Regression (#232): the common "navbar" header — a brand/logo anchor + a list of
// nav links + a hamburger toggle inside ONE <nav> — must convert the link list to
// core/navigation, lift the brand out separately (not as a menu item), and drop the
// dead hamburger. Generic structural markup only (no fixture-specific class names).
$navbarHeader = ( new HtmlTransformer() )->transform(
    '<nav class="masthead" role="navigation" aria-label="Main navigation"><a href="/" class="brand">Studio <em>Vale</em></a><ul class="primary-menu"><li><a href="/">Home</a></li><li><a href="/music">Music</a></li><li><a href="/tour">Tour</a></li></ul><button class="burger" aria-label="Toggle menu" aria-expanded="false"><span></span><span></span><span></span></button></nav>'
)->toArray();
$navbarBlocks = $navbarHeader['blocks'] ?? array();
$navbarSerialized = (string) ($navbarHeader['serialized_blocks'] ?? '');
$navbarParity = $navbarHeader['source_reports']['semantic_parity'] ?? array();
$navbarBlockMenu = $navbarParity['navigation_menus']['blocks'][0] ?? array();
$assert('pass' === ($navbarParity['status'] ?? ''), 'navbar (brand + ul links + toggle) preserves semantic parity');
$assert(true === ($navbarBlockMenu['represented_as_core_navigation'] ?? null), 'navbar link list is represented as core/navigation');
$assert(1 === $countBlockName($navbarBlocks, 'core/navigation'), 'navbar emits exactly one core/navigation block for the link list');
$assert(3 === ($navbarBlockMenu['item_count'] ?? null), 'navbar core/navigation carries the link list while the brand is lifted out separately');
$assert(str_contains($navbarSerialized, 'Studio'), 'navbar brand/logo is preserved (lifted out of the menu) rather than dropped');
$assert(0 === $countBlockName($navbarBlocks, 'core/button'), 'navbar hamburger toggle is dropped instead of emitted as a dead core/button');
$assert(! str_contains($navbarSerialized, 'burger'), 'navbar hamburger toggle chrome class is not emitted into block output');

// Regression (#232): broaden #221 — a hamburger toggle associated with a nav that
// does NOT convert to core/navigation (e.g. its list has a non-link item) must STILL
// be dropped, never emitted as an always-visible dead core/button the source hid
// behind responsive CSS/JS the importer cannot carry ("added UI" defect).
$nonConvertingNavbar = ( new HtmlTransformer() )->transform(
    '<header><a class="brand" href="/">Studio</a><nav class="primary" aria-label="Primary"><ul class="primary-menu"><li>Plain announcement copy</li><li><a href="/music">Music</a></li></ul></nav><button class="burger" aria-label="Toggle menu" aria-controls="primary-menu" aria-expanded="false"><span></span><span></span></button></header>'
)->toArray();
$nonConvertingBlocks = $nonConvertingNavbar['blocks'] ?? array();
$nonConvertingSerialized = (string) ($nonConvertingNavbar['serialized_blocks'] ?? '');
$assert(0 === $countBlockName($nonConvertingBlocks, 'core/button'), 'hamburger toggle for a non-converting nav is dropped rather than emitted as a dead core/button');
$assert(! str_contains($nonConvertingSerialized, 'burger'), 'non-converting navbar hamburger toggle chrome class is not emitted into block output');
$assert(str_contains($nonConvertingSerialized, 'Studio'), 'non-converting navbar preserves the brand/logo content');

// Negative (#232): a labelless toggle-shaped control with no associated navigation in
// scope must NOT be over-suppressed by the broadened rule — it still converts to
// core/button (only navigation-associated dead hamburgers are dropped).
$standaloneToggle = ( new HtmlTransformer() )->transform(
    '<section class="widget"><button aria-controls="panel" aria-expanded="false"><span></span><span></span></button></section>'
)->toArray();
$standaloneToggleBlocks = $standaloneToggle['blocks'] ?? array();
$assert(1 === $countBlockName($standaloneToggleBlocks, 'core/button'), 'a toggle-shaped control with no navigation in scope still converts to core/button');

$runtimeTargetNavigation = ( new HtmlTransformer() )->transform(
    '<nav aria-label="Docs"><ul><li><a class="nav-link" href="/guide">Guide</a></li></ul></nav>',
    array('runtime_dom_selectors' => array('.nav-link'))
)->toArray();
$runtimeTargetNavigationSerialized = (string) ($runtimeTargetNavigation['serialized_blocks'] ?? '');
$runtimeTargetNavigationItemAttrs = $runtimeTargetNavigation['blocks'][0]['innerBlocks'][0]['attrs'] ?? array();
$assert('nav-link' === ($runtimeTargetNavigationItemAttrs['className'] ?? ''), 'runtime-target navigation link classes are preserved on navigation item attrs');
// core/navigation-link is dynamic (save() returns null): the canonical stored
// block carries no static <li> markup, so the runtime-target class rides in the
// block comment className attribute, which core's className support renders onto
// the navigation item at runtime. Emitting a static <li> here would make
// wp.blocks.validateBlock flag the block invalid in the editor.
$assert(str_contains($runtimeTargetNavigationSerialized, '"className":"nav-link"'), 'runtime-target navigation link classes are preserved in the canonical navigation-link block comment');
$assert(! str_contains($runtimeTargetNavigationSerialized, '<li class="wp-block-navigation-item'), 'canonical navigation-link emits no static <li> markup that the editor would reject');

$activeNavigation = ( new HtmlTransformer() )->transform(
    '<nav aria-label="Primary"><ul class="nav-links"><li><a href="/" class="active">Home</a></li><li><a href="/music">Music</a></li></ul></nav>'
)->toArray();
$activeNavigationLinks = $activeNavigation['blocks'][0]['innerBlocks'] ?? array();
$assert('0px' === ($activeNavigation['blocks'][0]['attrs']['style']['spacing']['blockGap'] ?? ''), 'list navigation neutralizes the core default gap when the source has no authored gap');
$assert(str_contains((string) ($activeNavigationLinks[0]['attrs']['className'] ?? ''), 'blocks-engine-current-navigation-item'), 'active navigation link carries a frontend current-item styling marker');
$assert(! isset($activeNavigationLinks[0]['attrs']['style']['typography']['textDecoration']), 'current navigation signals do not invent an underline absent source styling');
$assert(! isset($activeNavigationLinks[1]['attrs']['style']['typography']['textDecoration']), 'inactive navigation link does not get active underline styling');
$assert(! str_contains((string) ($activeNavigation['serialized_blocks'] ?? ''), '"textDecoration":"underline"'), 'unstyled current navigation remains visually faithful in serialized block attrs');

$activeNavigationColor = ( new HtmlTransformer() )->transform(
    '<style>.nav-links a{color:var(--bone);font-family:monospace;font-size:12px;line-height:1.65;letter-spacing:.05em}.nav-links a.active{color:var(--bone);text-decoration:underline}.nav-links a.active::after{content:"";display:block;background:var(--ember);height:2px;width:100%}</style><nav aria-label="Primary"><ul class="nav-links"><li><a href="/" class="active">Home</a></li><li><a href="/music">Music</a></li></ul></nav>'
)->toArray();
$activeNavigationColorLinks = $activeNavigationColor['blocks'][0]['innerBlocks'] ?? array();
$activeNavigationColorAttrs = $activeNavigationColor['blocks'][0]['attrs'] ?? array();
$activeNavigationColorSerialized = (string) ($activeNavigationColor['serialized_blocks'] ?? '');
$activeNavigationColorCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $activeNavigationColor['assets'] ?? array()));
$assert(! isset($activeNavigationColorLinks[0]['attrs']['style']['color']['text']) && str_contains($activeNavigationColorCss, 'color:var(--bone)'), 'navigation link retains source anchor text color in projected CSS instead of an unsupported native attribute');
$assert(str_contains((string) ($activeNavigationColorLinks[0]['attrs']['className'] ?? ''), 'blocks-engine-current-navigation-underline'), 'source-authored active underline carries an explicit frontend compatibility marker');
$assert(! isset($activeNavigationColorLinks[0]['attrs']['style']['typography']['fontFamily']) && str_contains($activeNavigationColorCss, 'font-family:monospace'), 'list navigation leaves anchor typography in mapped author CSS instead of applying it to the core list item');
// The anchors carry their own typography. Projecting it onto the navigation
// container also re-struts the list, because the li line box is governed by the
// container font-size/line-height rather than by the inline anchor. Keeping the
// container typography separate preserves the source line box exactly.
$assert(! isset($activeNavigationColorAttrs['customTextColor']) && ! isset($activeNavigationColorAttrs['style']['typography']) && str_contains((string) ($activeNavigationColorAttrs['className'] ?? ''), 'blocks-engine-list-navigation') && str_contains($activeNavigationColorCss, 'color:var(--bone)'), 'list navigation keeps source container typography separate while retaining shared color through projected CSS');
$assert(! str_contains($activeNavigationColorCss, '.wp-block-navigation__container{gap:') && str_contains($activeNavigationColorCss, '.wp-block-navigation-item.wp-block-navigation-link{display:list-item;font:inherit}') && str_contains($activeNavigationColorCss, '.wp-block-navigation-item__content{display:inline}'), 'list navigation uses native block gap while preserving source list-item and inline-anchor formatting semantics');
$assert('var(--ember)' === ($activeNavigationColorLinks[0]['attrs']['style']['typography']['textDecorationColor'] ?? ''), 'active navigation underline color carries source pseudo underline paint');
$assert(! isset($activeNavigationColorLinks[1]['attrs']['style']['typography']['textDecorationColor']), 'inactive navigation link does not get underline color styling');
$assert(str_contains($activeNavigationColorSerialized, '<!-- wp:navigation-link'), 'active navigation color case keeps canonical navigation-link serialization');
$assert(str_contains($activeNavigationColorSerialized, '"textDecorationColor":"var(--ember)"'), 'active navigation underline color is serialized into the dynamic navigation-link block attrs');
$assert(! str_contains($activeNavigationColorSerialized, '<li class="wp-block-navigation-item'), 'active navigation color serialization emits no invalid static navigation item markup');

$authoredNavigationGap = ( new HtmlTransformer() )->transform(
    '<style>.nav-links{display:flex;gap:2px}</style><nav aria-label="Primary"><ul class="nav-links"><li><a href="/">Product</a></li><li><a href="/features">Features</a></li></ul></nav>'
)->toArray();
$authoredNavigationGapAttrs = $authoredNavigationGap['blocks'][0]['attrs'] ?? array();
$authoredNavigationGapCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $authoredNavigationGap['assets'] ?? array()));
$assert('2px' === ($authoredNavigationGapAttrs['style']['spacing']['blockGap'] ?? ''), 'list navigation preserves an authored gap as native block spacing');
$assert(! str_contains($authoredNavigationGapCss, '.wp-block-navigation__container{gap:'), 'list navigation does not override an authored gap with generated runtime CSS');

$leadingStyleRules = implode('', array_map(static fn (int $index): string => '.rule-' . $index . '{color:#111}', range(1, 201)));
$lateNavigationStyle = ( new HtmlTransformer() )->transform(
    '<style>' . $leadingStyleRules . '.footer-links a{color:#637188;font-family:monospace;font-size:11px;letter-spacing:.05em}</style><footer><nav class="footer-links"><a href="#privacy">Privacy</a></nav></footer>'
)->toArray();
$lateNavigationLinkAttrs = $lateNavigationStyle['blocks'][0]['innerBlocks'][0]['attrs'] ?? array();
$lateNavigationAttrs = $lateNavigationStyle['blocks'][0]['attrs'] ?? array();
$lateNavigationCss = implode("\n", array_column($lateNavigationStyle['assets'] ?? array(), 'content'));
$assert(! isset($lateNavigationLinkAttrs['style']['color']['text']) && str_contains($lateNavigationCss, '.footer-links a{color:#637188'), 'stylesheet rules after the first 200 preserve navigation text color through projected CSS');
$assert('monospace' === ($lateNavigationLinkAttrs['style']['typography']['fontFamily'] ?? ''), 'stylesheet rules after the first 200 preserve navigation typography');
$assert(! isset($lateNavigationAttrs['customTextColor']) && str_contains($lateNavigationCss, '.footer-links a{color:#637188'), 'late shared link color remains in navigation CSS when metadata rejects the context attr');

$headerCluster = ( new HtmlTransformer() )->transform(
    '<header class="site-header"><a class="site-logo" href="/">Acme Lab</a><nav class="primary-nav" aria-label="Primary"><a class="nav-link" href="/work">Work</a><a class="nav-link" href="/docs"><span>Docs</span></a></nav><form class="site-search" role="search" action="/search"><label for="q">Search</label><input id="q" type="search" name="q" placeholder="Search docs"><button type="submit">Search</button></form><div class="header-actions"><a class="cta" href="/start" style="padding:10px 16px;background:#135e96">Get started</a></div></header>'
)->toArray();
$headerClusterSerialized = (string) ($headerCluster['serialized_blocks'] ?? '');
$headerClusterParity = $headerCluster['source_reports']['semantic_parity'] ?? array();
$assert('pass' === ($headerClusterParity['status'] ?? ''), 'header logo/nav/search/CTA clusters preserve source navigation semantic parity');
$assert(str_contains($headerClusterSerialized, 'site-logo'), 'header cluster preserves logo link wrapper');
$assert(str_contains($headerClusterSerialized, 'nav-link'), 'header cluster preserves nav link class target');
$assert(str_contains($headerClusterSerialized, '<!-- wp:search'), 'header cluster converts a constrained GET search form to native core/search');
$assert(str_contains($headerClusterSerialized, '"className":"site-search"'), 'header search conversion preserves the source form class');
$assert(str_contains($headerClusterSerialized, '"label":"Search"') && str_contains($headerClusterSerialized, '"showLabel":true'), 'header search conversion preserves its visible label');
$assert(str_contains($headerClusterSerialized, '"placeholder":"Search docs"') && str_contains($headerClusterSerialized, '"buttonText":"Search"'), 'header search conversion preserves placeholder and submit text');
$assert(! str_contains($headerClusterSerialized, '<form class="site-search"'), 'native search conversion emits no invalid static form payload');
$assert(str_contains($headerClusterSerialized, '<!-- wp:buttons'), 'header cluster converts CTA action to buttons');

$expandableHeaderSearch = ( new HtmlTransformer() )->transform(
    '<style>.search-icon{display:none;height:80px}.search-icon.visible{display:block}</style><script>document.querySelector(".search-icon").classList.add("visible")</script><header><div class="site-utils"><span class="provider-search"><form id="provider-search" action="/apps/search" method="get"><input type="text" name="q" placeholder="Search"></form></span><button class="search-icon"><svg width="12px" height="13px" viewBox="0 0 12 13"><path d="M1 1"></path></svg></button><button class="search-close">close</button></div></header>'
)->toArray();
$expandableHeaderSearchSerialized = (string) ($expandableHeaderSearch['serialized_blocks'] ?? '');
$expandableHeaderSearchCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $expandableHeaderSearch['assets'] ?? array()));
$assert(str_contains($expandableHeaderSearchSerialized, '<!-- wp:search'), 'provider search cluster migrates to native WordPress search');
$assert(str_contains($expandableHeaderSearchSerialized, '"buttonPosition":"button-only"') && str_contains($expandableHeaderSearchSerialized, '"buttonUseIcon":true'), 'adjacent icon trigger maps to expandable icon-only core/search');
$assert(! str_contains($expandableHeaderSearchSerialized, '"text":"#000000"') && str_contains($expandableHeaderSearchCss, 'color:#000!important') && str_contains($expandableHeaderSearchCss, 'background:none!important') && str_contains($expandableHeaderSearchCss, 'border:0!important'), 'expandable native search preserves borderless black icon treatment through the carrier');
$assert(str_contains($expandableHeaderSearchSerialized, '"showLabel":false') && str_contains($expandableHeaderSearchSerialized, '"placeholder":"Search"'), 'provider search cluster uses an accessible hidden label and preserves placeholder text');
$assert(! str_contains($expandableHeaderSearchSerialized, '/apps/search') && ! str_contains($expandableHeaderSearchSerialized, 'name="q"'), 'native search migration removes provider-specific endpoint semantics');
$assert(! str_contains($expandableHeaderSearchSerialized, '"className":"provider-search"') && str_contains($expandableHeaderSearchSerialized, 'search-icon blocks-engine-source-search-icon-') && ! str_contains($expandableHeaderSearchSerialized, 'search-close'), 'native search cluster absorbs provider wrappers while carrying the trigger presentation onto core search');
$assert(str_contains($expandableHeaderSearchCss, 'flex:0 0 24px!important;width:24px!important;height:80px!important') && str_contains($expandableHeaderSearchCss, 'width:12px;height:13px') && str_contains($expandableHeaderSearchCss, 'data:image/svg+xml,'), 'native icon search replays the source trigger flex geometry and exact SVG artwork');

$viewBoxOnlyHeaderSearch = ( new HtmlTransformer() )->transform(
    '<header><form role="search"><input name="s" type="search"></form><button class="search-trigger"><svg viewBox="0 0 12 13"><path d="M1 1"></path></svg></button></header>'
)->toArray();
$viewBoxOnlyHeaderSearchCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $viewBoxOnlyHeaderSearch['assets'] ?? array()));
$assert(str_contains($viewBoxOnlyHeaderSearchCss, 'width:12px;height:13px'), 'native icon search derives intrinsic dimensions from an HTML-normalized lowercase viewBox attribute');

$cssSizedHeaderSearch = ( new HtmlTransformer() )->transform(
    '<style>.search-trigger{width:48px;height:32px}.search-trigger svg{width:20px;height:21px}</style><header><form role="search"><input name="s" type="search"></form><button class="search-trigger"><svg viewBox="0 0 12 13"><path d="M1 1"></path></svg></button></header>'
)->toArray();
$cssSizedHeaderSearchCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $cssSizedHeaderSearch['assets'] ?? array()));
$assert(str_contains($cssSizedHeaderSearchCss, 'width:48px!important;height:32px!important') && str_contains($cssSizedHeaderSearchCss, 'width:20px;height:21px'), 'native icon search honors authored trigger and SVG dimensions before intrinsic dimensions');

$emptyFlexUtility = ( new HtmlTransformer() )->transform(
    '<style>.utils{display:flex}.placeholder{visibility:hidden;height:80px}</style><div class="utils"><span>Action</span><div class="placeholder"></div></div>'
)->toArray();
$emptyFlexUtilitySerialized = (string) ($emptyFlexUtility['serialized_blocks'] ?? '');
$emptyFlexUtilityCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $emptyFlexUtility['assets'] ?? array()));
$assert(str_contains($emptyFlexUtilitySerialized, 'placeholder blocks-engine-empty-flex-item'), 'preserved empty flex children carry a zero-intrinsic-size compatibility marker');
$assert(str_contains($emptyFlexUtilityCss, 'flex:0 0 0!important;width:0!important;min-width:0!important;margin-left:0!important;margin-right:0!important'), 'empty flex compatibility CSS prevents core group chrome and margins from adding a source-absent footprint');

$boundedHeaderSearch = ( new HtmlTransformer() )->transform(
    '<header><div class="site-utils"><div class="search-shell">Search our catalog<form role="search"><input type="search" name="s"></form></div><button class="search-help" aria-label="Search help"><svg viewBox="0 0 10 10"><path d="M1 1"></path></svg></button></div></header>'
)->toArray();
$boundedHeaderSearchSerialized = (string) ($boundedHeaderSearch['serialized_blocks'] ?? '');
$assert(str_contains($boundedHeaderSearchSerialized, 'Search our catalog'), 'search conversion preserves meaningful wrapper text');
$assert(str_contains($boundedHeaderSearchSerialized, 'search-shell'), 'search conversion preserves a non-cluster wrapper presentation hook');
$assert(str_contains($boundedHeaderSearchSerialized, 'search-help'), 'search conversion preserves unrelated adjacent icon-only search utility controls');

$arbitrarySearchForm = ( new HtmlTransformer() )->transform(
    '<form class="catalog-filter" role="search" action="/products" method="post" data-endpoint="catalog"><input type="hidden" name="token" value="abc"><label for="term">Find</label><input id="term" type="search" name="term" value="chairs"><select name="category"><option>All</option></select><button type="submit" data-track="filter">Go</button></form>'
)->toArray();
$arbitrarySearchFormSerialized = (string) ($arbitrarySearchForm['serialized_blocks'] ?? '');
$assert(str_contains($arbitrarySearchFormSerialized, '<!-- wp:html'), 'arbitrary imported search forms are preserved as raw HTML');
$assert(str_contains($arbitrarySearchFormSerialized, 'method="post"'), 'arbitrary imported search form method is preserved');
$assert(str_contains($arbitrarySearchFormSerialized, 'data-endpoint="catalog"'), 'arbitrary imported search form data attributes are preserved');
$assert(str_contains($arbitrarySearchFormSerialized, '<select name="category">'), 'arbitrary imported search form controls are preserved');
$assert(! str_contains($arbitrarySearchFormSerialized, '<!-- wp:search'), 'arbitrary imported search forms never convert to static core/search');

$unmappedNavigation = ( new HtmlTransformer() )->transform(
    '<main><nav aria-label="Main navigation"><ul><li><a href="/">Home</a></li></ul><p>Unexpected helper copy</p></nav></main>'
)->toArray();
$unmappedSemanticParity = $unmappedNavigation['source_reports']['semantic_parity'] ?? array();
$unmappedFinding = $unmappedSemanticParity['findings'][0] ?? array();
$unmappedNavigationFinding = $unmappedSemanticParity['findings'][1] ?? array();
$assert('warning' === ($unmappedSemanticParity['status'] ?? ''), 'semantic parity warns when source nav is not represented as core navigation');
$assert('landmark_count_mismatch' === ($unmappedFinding['code'] ?? ''), 'semantic parity reports a precise missing nav landmark finding');
$assert('nav' === ($unmappedFinding['kind'] ?? ''), 'semantic parity missing landmark finding names the nav kind');
$assert(1 === ($unmappedFinding['source_count'] ?? null), 'semantic parity missing landmark finding exposes source count');
$assert(0 === ($unmappedFinding['block_count'] ?? null), 'semantic parity missing landmark finding exposes generated block count');
$assert('navigation_menu_missing' === ($unmappedNavigationFinding['code'] ?? ''), 'semantic parity reports missing navigation menu diagnostics');
$assert(array('label' => 'Home', 'url' => '/') === (($unmappedNavigationFinding['source_items'] ?? array())[0] ?? array()), 'semantic parity missing navigation diagnostics expose source nav items');
$assert(array() === ($unmappedNavigationFinding['block_items'] ?? null), 'semantic parity missing navigation diagnostics expose empty generated nav items');

$quoteCitationFooter = ( new HtmlTransformer() )->transform(
    '<main><section><blockquote><p>Lovely dinner.</p><footer>Local Guide</footer></blockquote></section></main><footer>Restaurant footer</footer>'
)->toArray();
$quoteCitationParity = $quoteCitationFooter['source_reports']['semantic_parity'] ?? array();
$assert('pass' === ($quoteCitationParity['status'] ?? ''), 'blockquote citation footer is not counted as a page footer landmark');
$assert(1 === ($quoteCitationParity['landmarks']['source']['footer'] ?? null), 'semantic parity counts only the actual page footer landmark');

$decoratedFigureQuote = ( new HtmlTransformer() )->transform(
    '<style>.quote-card .mark{font-size:3rem}.quote-who{display:flex;gap:.8rem}.quote-avatar{width:44px;height:44px}</style><figure class="quote-card"><div class="mark" aria-hidden="true">&ldquo;</div><blockquote>Open publishing matters.</blockquote><figcaption class="quote-who"><span class="quote-avatar" aria-hidden="true">RM</span><span><b>Rosa Medina</b><span>Writer</span></span></figcaption></figure>'
)->toArray();
$decoratedFigureQuoteSerialized = (string) ($decoratedFigureQuote['serialized_blocks'] ?? '');
$assert(str_contains($decoratedFigureQuoteSerialized, '<p class="mark">“</p>'), 'figure quote preserves a styled decorative lead mark as native content');
$assert(str_contains($decoratedFigureQuoteSerialized, '<cite><span class="quote-who">'), 'figure quote preserves the figcaption layout class around native citation content');
$assert('pass' === ($decoratedFigureQuote['source_reports']['wp_block_validity']['status'] ?? ''), 'decorated figure quote remains block-valid');

$assertNoInnerContentChildCountMismatch = static function (array $result, string $message) use ($assert): void {
    $findingCodes = array_map(static fn (array $finding): string => (string) ($finding['code'] ?? ''), $result['source_reports']['wp_block_validity']['findings'] ?? array());
    $assert(! in_array('inner_content_child_count_mismatch', $findingCodes, true), $message, implode(', ', $findingCodes));
};
$assertPlaceholderCountsMatchChildren = static function (array $blocks, string $path = 'blocks') use (&$assertPlaceholderCountsMatchChildren, $assert): void {
    foreach ( $blocks as $index => $block ) {
        if ( ! is_array($block) ) {
            continue;
        }

        $blockPath = $path . '.' . $index;
        $innerBlocks = is_array($block['innerBlocks'] ?? null) ? array_values($block['innerBlocks']) : array();
        $innerContent = is_array($block['innerContent'] ?? null) ? $block['innerContent'] : array();
        $placeholderCount = count(array_filter($innerContent, static fn ($part): bool => null === $part));
        $assert(count($innerBlocks) === $placeholderCount, 'innerContent placeholder count matches innerBlocks count at ' . $blockPath, 'children=' . count($innerBlocks) . ' placeholders=' . $placeholderCount);
        $assertPlaceholderCountsMatchChildren($innerBlocks, $blockPath . '.innerBlocks');
    }
};

$deduplicatedMobileNavigation = ( new HtmlTransformer() )->transform(
    '<header class="site-header"><nav class="primary-nav"><a href="/">Home</a><a href="/shop">Shop</a><a href="/contact">Contact</a></nav><div class="mobile-nav overlay"><div class="mobile-nav-panel"><nav class="drawer-nav"><a href="/">Home</a><a href="/shop">Shop</a><a href="/contact">Contact</a></nav></div></div></header>'
)->toArray();
$assert('pass' === ($deduplicatedMobileNavigation['source_reports']['wp_block_validity']['status'] ?? ''), 'deduplicated desktop/mobile navigation passes WordPress block validity');
$assertNoInnerContentChildCountMismatch($deduplicatedMobileNavigation, 'deduplicated desktop/mobile navigation does not report innerContent child-count mismatch');
$assertPlaceholderCountsMatchChildren($deduplicatedMobileNavigation['blocks'] ?? array());
$assert(2 === count($deduplicatedMobileNavigation['blocks'][0]['innerBlocks'] ?? array()), 'deduplicated desktop/mobile navigation preserves drawer target wrapper');
$assert(str_contains((string) ($deduplicatedMobileNavigation['serialized_blocks'] ?? ''), 'mobile-nav'), 'deduplicated desktop/mobile navigation preserves mobile navigation target class');
$assert(! str_contains((string) ($deduplicatedMobileNavigation['serialized_blocks'] ?? ''), 'drawer-nav'), 'deduplicated desktop/mobile navigation removes duplicate drawer navigation children');

$decoratedImageLink = ( new HtmlTransformer() )->transform(
    '<a href="/photo.jpg" class="lightbox"><img src="/photo.jpg" alt="Photo"><div class="overlay"></div><div class="overlay-inner"></div></a>'
)->toArray();
$assert(str_contains((string) ($decoratedImageLink['serialized_blocks'] ?? ''), '<!-- wp:custom/responsive-media') && str_contains((string) ($decoratedImageLink['serialized_blocks'] ?? ''), 'photo.jpg'), 'an image-only link tolerates empty decorative overlay siblings without losing its media');

$centeredSocialLinks = ( new HtmlTransformer() )->transform(
    '<div style="text-align:center"><span class="social-links"><a href="https://facebook.com/example" aria-label="Facebook"><span></span></a><a href="https://instagram.com/example" aria-label="Instagram"><span></span></a></span></div>'
)->toArray();
$assert(str_contains((string) ($centeredSocialLinks['serialized_blocks'] ?? ''), '"justifyContent":"center"'), 'social links inherit explicit alignment from their source wrapper');
$assert(str_contains((string) ($centeredSocialLinks['serialized_blocks'] ?? ''), 'is-content-justification-center'), 'social link justification is present in rendered save markup');
$centeredSocialLinksCss = implode("\n", array_column($centeredSocialLinks['assets'] ?? array(), 'content'));
$assert(str_contains($centeredSocialLinksCss, '.wp-block-social-links.is-content-justification-center{justify-content:center}'), 'social link justification renders without depending on theme block CSS');

$deduplicatedNestedNavigation = ( new HtmlTransformer() )->transform(
    '<main><section class="shell"><div class="desktop-wrap"><nav><a href="/">Home</a><a href="/services">Services</a></nav></div><div class="mobile-nav drawer"><div class="drawer-panel"><nav><a href="/">Home</a><a href="/services">Services</a></nav></div></div><article><h2>Services</h2><p>Copy</p></article></section></main>'
)->toArray();
$assert('pass' === ($deduplicatedNestedNavigation['source_reports']['wp_block_validity']['status'] ?? ''), 'nested wrapper navigation dedupe passes WordPress block validity');
$assertNoInnerContentChildCountMismatch($deduplicatedNestedNavigation, 'nested wrapper navigation dedupe does not report innerContent child-count mismatch');
$assertPlaceholderCountsMatchChildren($deduplicatedNestedNavigation['blocks'] ?? array());
$assert(str_contains((string) ($deduplicatedNestedNavigation['serialized_blocks'] ?? ''), '<!-- wp:heading'), 'nested wrapper navigation dedupe preserves non-navigation siblings');

$normalizedFallbacks = ( new HtmlTransformer() )->transform(
    '<main><svg><circle cx="5" cy="5" r="5"></circle></svg><svg><script>alert(1)</script></svg><script src="/app.js">init()</script><canvas>Fallback</canvas><iframe src="javascript:alert(1)"></iframe></main>'
)->toArray();
$normalizedDiagnostics = $normalizedFallbacks['source_reports']['conversion_report']['fallback_diagnostics'] ?? array();
$diagnosticsByCode = array();
foreach ( $normalizedDiagnostics as $diagnostic ) {
    $diagnosticsByCode[$diagnostic['diagnostic_code'] ?? ''] = $diagnostic;
}
$assertNormalizedFallbackDiagnostic($diagnosticsByCode['html_unsafe_inline_svg'] ?? array(), 'html_unsafe_inline_svg', 'warning', 'sanitization_review', 'image_asset');
$assertNormalizedFallbackDiagnostic($diagnosticsByCode['html_script_fallback'] ?? array(), 'html_script_fallback', 'warning', 'client_script_execution', 'script_asset');
$assert('runtime_island_preserved' === ($diagnosticsByCode['html_script_fallback']['conversion_classification'] ?? ''), 'script fallback is classified as runtime island preservation');
$assert('runtime_island_preserved' === ($diagnosticsByCode['html_script_fallback']['loss_class'] ?? ''), 'script fallback exposes preserved runtime island loss class');
$assert('runtime_island_preserved' === ($diagnosticsByCode['html_script_fallback']['diagnostic_class'] ?? ''), 'script fallback exposes preserved runtime island diagnostic class');
$assert('preserve_runtime_island' === ($diagnosticsByCode['html_script_fallback']['suggested_repair_class'] ?? ''), 'script fallback routes to runtime island preservation rather than unsupported HTML replacement');
$assert('runtime_script' === ($diagnosticsByCode['html_script_fallback']['pattern_family'] ?? ''), 'script fallback exposes generic pattern family');
$assert('preserve_runtime_island' === ($diagnosticsByCode['html_script_fallback']['suggested_generic_repair_class'] ?? ''), 'script fallback exposes generic repair class');
$scriptRuntimeIslands = array_values(array_filter($normalizedFallbacks['source_reports']['runtime_islands'] ?? array(), static fn (array $island): bool => 'script' === ($island['kind'] ?? '')));
$assert(1 === count($scriptRuntimeIslands), 'runtime script fallback projects as a runtime island');
$assert('script_requires_runtime' === ($scriptRuntimeIslands[0]['preservation_reason'] ?? ''), 'runtime script island exposes preservation reason');
$assert('preserve' === ($scriptRuntimeIslands[0]['disposition'] ?? ''), 'runtime script island exposes accepted preserve disposition');
$assert('accepted_runtime_preservation' === ($scriptRuntimeIslands[0]['preservation_status'] ?? ''), 'runtime script island exposes accepted runtime preservation status');
$assert('preserve_verbatim' === ($scriptRuntimeIslands[0]['js_handling'] ?? ''), 'runtime script island exposes verbatim JS preservation intent');
$preservedRuntimeDiagnostics = array_values(array_filter($normalizedFallbacks['diagnostics'] ?? array(), static fn (array $diagnostic): bool => 'preserved_runtime_island' === ($diagnostic['code'] ?? '')));
$assert(1 <= count($preservedRuntimeDiagnostics), 'runtime script fallback emits preserved_runtime_island diagnostics');
$assert('runtime_island_preserved' === ($preservedRuntimeDiagnostics[0]['diagnostic_class'] ?? ''), 'preserved_runtime_island diagnostic exposes runtime-island diagnostic class');
$assert('accepted_runtime_preservation' === ($preservedRuntimeDiagnostics[0]['preservation_status'] ?? ''), 'preserved_runtime_island diagnostic exposes accepted preservation status');
$assertNormalizedFallbackDiagnostic($diagnosticsByCode['html_iframe_embed_fallback'] ?? array(), 'html_iframe_embed_fallback', 'warning', 'third_party_embed_runtime', 'embed');
$assert(! isset($diagnosticsByCode['html_inline_svg_fallback']), 'safe inline SVGs convert to inline core/html blocks instead of fallback diagnostics');
$assert(! isset($diagnosticsByCode['html_canvas_runtime_fallback']), 'non-runtime canvas does not emit runtime canvas fallback diagnostics');

$emptySvgPlaceholder = ( new HtmlTransformer() )->transform(
    '<main><div role="button" aria-label="Open gallery image"><svg class="zoom-mask" viewBox="0 0 10000 10000"></svg><img src="portrait.jpg" alt="Portrait"></div></main>'
)->toArray();
$assert(array() === ($emptySvgPlaceholder['fallbacks'] ?? array()), 'a structurally empty SVG placeholder does not emit fallback metadata');
$assert(str_contains((string) ($emptySvgPlaceholder['serialized_blocks'] ?? ''), 'portrait.jpg'), 'discarding an empty SVG placeholder preserves its independent image sibling');
$unsafeEmptySvg = ( new HtmlTransformer() )->transform('<main><svg onload="alert(1)"></svg></main>')->toArray();
$assert('html_unsafe_inline_svg' === ($unsafeEmptySvg['fallbacks'][0]['diagnostic_code'] ?? ''), 'an unsafe empty SVG retains its fallback diagnostic');

$coffeeFixturePath = dirname(__DIR__, 3) . '/fixtures/websites/2-onepager-coffee/index.html';
$coffeeFixtureHtml = (string) file_get_contents($coffeeFixturePath);
$coffeeResult = ( new HtmlTransformer() )->transform($coffeeFixtureHtml)->toArray();
$coffeeScriptIslands = array_values(array_filter($coffeeResult['source_reports']['runtime_islands'] ?? array(), static fn (array $island): bool => 'script' === ($island['kind'] ?? '')));
$assert(1 === count($coffeeScriptIslands), '2-onepager-coffee inline runtime script is classified as a single script runtime island');
$assert('script:nth-of-type(1)' === ($coffeeScriptIslands[0]['selector'] ?? ''), '2-onepager-coffee script island keeps the source selector');
$assert('script_requires_runtime' === ($coffeeScriptIslands[0]['preservation_reason'] ?? ''), '2-onepager-coffee script island keeps the runtime preservation reason');
$assert('accepted_runtime_preservation' === ($coffeeScriptIslands[0]['preservation_status'] ?? ''), '2-onepager-coffee script island is marked as accepted runtime preservation');
$assert('preserve_verbatim' === ($coffeeScriptIslands[0]['js_handling'] ?? ''), '2-onepager-coffee script island carries explicit verbatim JS preservation intent');
$coffeeScriptDiagnostics = array_values(array_filter($coffeeResult['diagnostics'] ?? array(), static fn (array $diagnostic): bool => 'preserved_runtime_island' === ($diagnostic['code'] ?? '') && 'script' === ($diagnostic['kind'] ?? '')));
$assert(1 === count($coffeeScriptDiagnostics), '2-onepager-coffee emits one script preserved_runtime_island diagnostic');
$assert('accepted_runtime_preservation' === ($coffeeScriptDiagnostics[0]['preservation_status'] ?? ''), '2-onepager-coffee diagnostic exposes accepted runtime preservation metadata');

$safeProviderIframe = ( new HtmlTransformer() )->transform(
    '<main><iframe title="Demo" src="https://www.youtube.com/embed/dQw4w9WgXcQ" width="560" height="315"></iframe></main>'
)->toArray();
$safeProviderBlock = $safeProviderIframe['blocks'][0] ?? array();
$assert('core/embed' === ($safeProviderBlock['blockName'] ?? ''), 'safe provider iframe converts to core/embed');
$assert('https://www.youtube.com/watch?v=dQw4w9WgXcQ' === ($safeProviderBlock['attrs']['url'] ?? ''), 'safe provider iframe canonicalizes embed URL');
$assert('youtube' === ($safeProviderBlock['attrs']['providerNameSlug'] ?? ''), 'safe provider iframe records provider slug');
$assert(array() === ($safeProviderIframe['fallbacks'] ?? array()), 'safe provider iframe does not emit fallback metadata');

$facebookProviderIframe = ( new HtmlTransformer() )->transform(
    '<main><iframe title="Facebook video" src="https://www.facebook.com/plugins/video.php?href=https%3A%2F%2Fwww.facebook.com%2Fexample%2Fvideos%2F123&amp;autoplay=true" width="637" height="358"></iframe></main>'
)->toArray();
$facebookProviderBlock = $facebookProviderIframe['blocks'][0] ?? array();
$assert('core/embed' === ($facebookProviderBlock['blockName'] ?? ''), 'Facebook plugin iframe converts to core/embed');
$assert('https://www.facebook.com/example/videos/123' === ($facebookProviderBlock['attrs']['url'] ?? ''), 'Facebook plugin iframe extracts its canonical video URL');
$assert('facebook' === ($facebookProviderBlock['attrs']['providerNameSlug'] ?? ''), 'Facebook plugin iframe records provider slug');
$assert(array() === ($facebookProviderIframe['fallbacks'] ?? array()), 'Facebook plugin iframe does not emit fallback metadata');

$unknownIframe = ( new HtmlTransformer() )->transform(
    '<main><section><h2>Playground</h2><p>Before embed.</p><iframe title="Interactive demo" src="https://example.test/playground" width="640" height="360" allow="fullscreen" loading="lazy" sandbox="allow-scripts" referrerpolicy="no-referrer"></iframe><p>After embed.</p></section></main>'
)->toArray();
$unknownDiagnostics = $unknownIframe['source_reports']['conversion_report']['fallback_diagnostics'] ?? array();
$unknownIframeDiagnostics = array_values(array_filter($unknownDiagnostics, static fn (array $diagnostic): bool => 'html_iframe_embed_fallback' === ($diagnostic['diagnostic_code'] ?? '')));
$assert(array() === $unknownIframeDiagnostics, 'bounded visual iframe does not emit a fallback diagnostic');
$assert(array() === ($unknownIframe['fallbacks'] ?? array()), 'bounded visual iframe does not increase fallback count');
$unknownIframeIslands = array_values(array_filter($unknownIframe['source_reports']['runtime_islands'] ?? array(), static fn (array $island): bool => 'iframe' === ($island['kind'] ?? '')));
$assert(1 === count($unknownIframeIslands), 'unknown iframe projects as a runtime island');
$assert('iframe_requires_embed_runtime' === ($unknownIframeIslands[0]['preservation_reason'] ?? ''), 'unknown iframe runtime island exposes preservation reason');
$unknownVisualIframeBlock = array_values(array_filter($unknownIframe['blocks'][0]['innerBlocks'] ?? array(), static fn (array $block): bool => 'custom/visual-iframe' === ($block['blockName'] ?? '')))[0] ?? array();
$unknownSerialized = (string) ($unknownIframe['serialized_blocks'] ?? '');
$assert(! str_contains($unknownSerialized, '<!-- wp:embed'), 'unknown iframe does not become a provider embed block');
$assert('custom/visual-iframe' === ($unknownVisualIframeBlock['blockName'] ?? ''), 'bounded visible unknown iframe is materialized as the typed visual-iframe companion');
$assert(! str_contains($unknownSerialized, '<!-- wp:html') && str_contains($unknownSerialized, '<!-- wp:custom/visual-iframe'), 'bounded visual iframe does not use a core HTML fallback');
$assert(str_contains($unknownSerialized, '<iframe') && str_contains($unknownSerialized, 'width="640"') && str_contains($unknownSerialized, 'height="360"'), 'bounded iframe source dimensions survive companion save serialization');
$assert(str_contains($unknownSerialized, 'title="Interactive demo"') && str_contains($unknownSerialized, 'loading="lazy"') && str_contains($unknownSerialized, 'sandbox="allow-scripts"') && str_contains($unknownSerialized, 'referrerpolicy="no-referrer"'), 'bounded iframe accessibility and safe runtime attributes survive companion save serialization');
$assert(str_contains($unknownSerialized, 'Playground'), 'ancestor content around unknown iframe still converts heading content');
$assert(str_contains($unknownSerialized, 'Before embed.'), 'ancestor content before unknown iframe still converts');
$assert(str_contains($unknownSerialized, 'After embed.'), 'ancestor content after unknown iframe still converts');
$assert('pass' === ($unknownIframe['source_reports']['wp_block_validity']['status'] ?? ''), 'bounded iframe companion save shape is Gutenberg-valid');

$responsiveIframeWrapper = ( new HtmlTransformer() )->transform(
    '<main><wix-iframe data-src=""><div class="map-container"><iframe title="Map" src="https://example.test/map" width="1280" height="350"></iframe></div></wix-iframe><wix-iframe data-src=""><div class="map-container"></div></wix-iframe></main>'
)->toArray();
$assert(array() === ($responsiveIframeWrapper['fallbacks'] ?? array()), 'an inactive custom media placeholder does not emit an unsupported-element fallback');
$assert(1 === substr_count((string) ($responsiveIframeWrapper['serialized_blocks'] ?? ''), '<iframe'), 'the active custom media variant still lowers through bounded iframe conversion');

$customIframeMap = ( new HtmlTransformer() )->transform(
    '<main><vendor-iframe data-src="https://example.test/map" title="Studio map" width="1280" height="350"><div class="map-container"></div></vendor-iframe></main>'
)->toArray();
$customIframeMapMarkup = (string) ($customIframeMap['serialized_blocks'] ?? '');
$customIframeMapBlock = array_values(array_filter($customIframeMap['blocks'][0]['innerBlocks'] ?? array(), static fn (array $block): bool => 'custom/visual-iframe' === ($block['blockName'] ?? '')))[0] ?? ($customIframeMap['blocks'][0] ?? array());
$assert('custom/visual-iframe' === ($customIframeMapBlock['blockName'] ?? ''), 'portable custom iframe map materializes as the typed visual-iframe companion');
$assert('https://example.test/map' === ($customIframeMapBlock['attrs']['src'] ?? '') && '1280' === ($customIframeMapBlock['attrs']['width'] ?? ''), 'portable custom iframe map retains destination and geometry');
$assert(array() === ($customIframeMap['fallbacks'] ?? array()) && ! str_contains($customIframeMapMarkup, '<!-- wp:html') && ! str_contains($customIframeMapMarkup, 'html_unsupported_element'), 'portable custom iframe map does not emit raw HTML or unsupported-element fallbacks');
$assert('pass' === ($customIframeMap['source_reports']['wp_block_validity']['status'] ?? ''), 'portable custom iframe map save shape is Gutenberg-valid');
$customIframeMapIslands = array_values(array_filter($customIframeMap['source_reports']['runtime_islands'] ?? array(), static fn (array $island): bool => 'iframe' === ($island['kind'] ?? '')));
$assert(1 === count($customIframeMapIslands) && 'typed_visual_iframe_companion' === ($customIframeMapIslands[0]['preservation_strategy'] ?? ''), 'portable custom iframe map keeps runtime-dependency parity as a typed island');

$customIframeGaps = ( new HtmlTransformer() )->transform(
    '<main><vendor-iframe src="https://example.test/one" data-src="https://example.test/two" width="640" height="360"></vendor-iframe><vendor-iframe data-widget-id="comp-runtime" width="640" height="360"></vendor-iframe></main>'
)->toArray();
$customIframeGapRows = array_values(array_filter($customIframeGaps['fallbacks'] ?? array(), static fn (array $fallback): bool => 'html_iframe_surface_capability_gap' === ($fallback['diagnostic_code'] ?? '')));
$assert(2 === count($customIframeGapRows), 'ambiguous and source-runtime-only custom iframes emit capability-gap diagnostics');
$assert(array( 'ambiguous_iframe_destination', 'source_runtime_only_iframe' ) === array_values(array_map(static fn (array $fallback): string => (string) ($fallback['reason'] ?? ''), $customIframeGapRows)), 'custom iframe capability gaps keep explicit rejection reasons');
$assert(array() === array_values(array_filter($customIframeGaps['fallbacks'] ?? array(), static fn (array $fallback): bool => 'html_unsupported_element' === ($fallback['diagnostic_code'] ?? ''))), 'classified custom iframe rejections do not use html_unsupported_element');
$assert(! str_contains((string) ($customIframeGaps['serialized_blocks'] ?? ''), '<!-- wp:html'), 'rejected custom iframe surfaces do not emit core/html fallbacks');

$visualIframeGeometry = ( new HtmlTransformer() )->transform(
    '<main><div style="width:1280px;height:350px;margin:0 80px 10px"><iframe title="Map surface" src="https://example.test/map" width="100%" height="100%"></iframe></div><p>Following content</p></main>'
)->toArray();
$visualIframeGeometryMarkup = (string) ($visualIframeGeometry['serialized_blocks'] ?? '');
$visualIframeGeometryCss = (string) ($visualIframeGeometry['css'] ?? '');
$assert(str_contains($visualIframeGeometryMarkup, 'width="100%"') && str_contains($visualIframeGeometryMarkup, 'height="100%"') && str_contains($visualIframeGeometryMarkup, 'title="Map surface"'), 'bounded iframe retains source sizing and title within its geometry-owning wrapper');
$assert((str_contains($visualIframeGeometryMarkup, 'margin-bottom:10px') || str_contains($visualIframeGeometryCss, 'margin-bottom:10px')) && str_contains($visualIframeGeometryMarkup, 'Following content'), 'iframe wrapper margins and following content layout remain serialized');

$suppressedIframe = ( new HtmlTransformer() )->transform(
    '<main><iframe src="https://example.test/tag-manager" width="0" height="0" style="display:none"></iframe><iframe src="https://example.test/worker" width="1" height="1" style="visibility:hidden"></iframe><iframe src="javascript:alert(1)" width="640" height="360" srcdoc="<p>unsafe</p>"></iframe><iframe src="https://example.test/unbounded"></iframe></main>'
)->toArray();
$suppressedIframeMarkup = (string) ($suppressedIframe['serialized_blocks'] ?? '');
$assert(! str_contains($suppressedIframeMarkup, '<iframe'), 'hidden, zero-size, unsafe, and unbounded iframes remain suppressed');

$staticTemplate = ( new HtmlTransformer() )->transform(
    '<main><section><h2>Visible</h2><template><article><h3>Deferred article</h3><p>Readable metadata.</p></article></template><p>After.</p></section></main>'
)->toArray();
$staticTemplateDiagnostics = $staticTemplate['source_reports']['conversion_report']['fallback_diagnostics'] ?? array();
$staticTemplateMetadata = array_values(array_filter($staticTemplateDiagnostics, static fn (array $diagnostic): bool => 'html_template_metadata' === ($diagnostic['diagnostic_code'] ?? '')));
$assert(1 === count($staticTemplateMetadata), 'static HTML template emits bounded metadata instead of unsupported fallback');
$assert('native_conversion' === ($staticTemplateMetadata[0]['conversion_classification'] ?? ''), 'static HTML template metadata is not classified as unsupported loss');
$assert('inert_template_metadata' === ($staticTemplateMetadata[0]['pattern_family'] ?? ''), 'static HTML template exposes generic inert template pattern family');
$assert('none' === ($staticTemplateMetadata[0]['runtime_requirement'] ?? ''), 'static HTML template metadata does not require runtime');
$staticTemplateUnsupported = array_values(array_filter($staticTemplateDiagnostics, static fn (array $diagnostic): bool => 'html_unsupported_element' === ($diagnostic['diagnostic_code'] ?? '')));
$assert(array() === $staticTemplateUnsupported, 'static HTML template does not emit unsupported element fallback diagnostics');
$assert(! str_contains((string) ($staticTemplate['serialized_blocks'] ?? ''), 'Deferred article'), 'static HTML template content is omitted from visual block output');

$runtimeTemplate = ( new HtmlTransformer() )->transform(
    '<main><div id="content-store" hidden><template data-content="readme"><article><h1>Runtime readme</h1><p>Loaded by app.js.</p></article></template></div><script src="/app.js"></script></main>'
)->toArray();
$runtimeTemplateDiagnostics = $runtimeTemplate['source_reports']['conversion_report']['fallback_diagnostics'] ?? array();
$runtimeTemplateFallbacks = array_values(array_filter($runtimeTemplateDiagnostics, static fn (array $diagnostic): bool => 'html_template_runtime_fallback' === ($diagnostic['diagnostic_code'] ?? '')));
$assert(1 === count($runtimeTemplateFallbacks), 'runtime HTML template emits template runtime fallback metadata');
$assert('runtime_island_preserved' === ($runtimeTemplateFallbacks[0]['conversion_classification'] ?? ''), 'runtime HTML template fallback is classified as preserved runtime island');
$assert('runtime_template' === ($runtimeTemplateFallbacks[0]['pattern_family'] ?? ''), 'runtime HTML template exposes generic runtime template pattern family');
$templateRuntimeIslands = array_values(array_filter($runtimeTemplate['source_reports']['runtime_islands'] ?? array(), static fn (array $island): bool => 'template' === ($island['kind'] ?? '')));
$assert(1 === count($templateRuntimeIslands), 'runtime HTML template projects as a runtime island');
$assert('template_requires_runtime' === ($templateRuntimeIslands[0]['preservation_reason'] ?? ''), 'runtime HTML template island exposes preservation reason');
$assert('data_template' === ($templateRuntimeIslands[0]['template_role'] ?? ''), 'runtime HTML template island preserves source role metadata');
$assert(! str_contains((string) ($runtimeTemplate['serialized_blocks'] ?? ''), '<!-- wp:html'), 'runtime HTML template does not emit raw HTML fallback blocks');
$assert(! str_contains((string) ($runtimeTemplate['serialized_blocks'] ?? ''), '<template'), 'runtime HTML template does not serialize inert template markup into visual output');

$canvasFallback = ( new HtmlTransformer() )->transform(
    '<main><canvas id="bonsai" class="stage" width="640" height="360">Fallback</canvas><script src="/js/script.js"></script></main>',
    array('runtime_canvas_selectors' => array('#bonsai'))
)->toArray();
$canvasIsland = $canvasFallback['source_reports']['runtime_islands'][0] ?? array();
$canvasFallbackRows = array_values(array_filter($canvasFallback['fallbacks'] ?? array(), static fn (array $fallback): bool => 'canvas_requires_runtime' === ($fallback['reason'] ?? '')));
$assert(array() === $canvasFallbackRows, 'runtime canvas preservation does not emit canvas fallback diagnostics');
$assert('canvas' === ($canvasIsland['kind'] ?? ''), 'runtime canvas projects as a runtime island');
$assert('canvas_requires_runtime' === ($canvasIsland['preservation_reason'] ?? ''), 'runtime canvas island exposes preservation reason');
$assert('runtime_canvas' === ($canvasIsland['pattern_family'] ?? ''), 'runtime canvas island exposes generic pattern family');
$assert(str_contains((string) ($canvasFallback['serialized_blocks'] ?? ''), 'id="bonsai"'), 'runtime canvas serialized output preserves id for runtime mapping');
$canvasRuntimeIslands = array_values(array_filter($canvasFallback['source_reports']['runtime_islands'] ?? array(), static fn (array $island): bool => 'canvas' === ($island['kind'] ?? '')));
$assert(1 === count($canvasRuntimeIslands), 'runtime canvas projects as a bounded runtime island');
$assert('#bonsai' === ($canvasRuntimeIslands[0]['selector'] ?? ''), 'runtime canvas island preserves script-addressable selector');
$assert(str_contains((string) ($canvasRuntimeIslands[0]['source_snippet'] ?? ''), '<canvas id="bonsai"'), 'runtime canvas island preserves bounded source snippet for runtime mapping');
$assert(1 === count($canvasRuntimeIslands[0]['required_scripts'] ?? array()), 'runtime canvas island preserves required script context');
$assert(str_contains((string) ($canvasFallback['serialized_blocks'] ?? ''), '<!-- wp:html'), 'runtime canvas emits bounded core/html preservation blocks');
$assert(str_contains((string) ($canvasFallback['serialized_blocks'] ?? ''), '<canvas id="bonsai"'), 'runtime canvas serializes raw canvas markup into block output');

$runtimePreserved = ( new HtmlTransformer() )->transform(
    '<main><canvas id="stage" aria-hidden="true"></canvas><input id="amount" value="10"><div id="app-shell">Runtime shell</div></main>',
    array(
        'runtime_canvas_selectors' => array('#stage'),
        'runtime_dom_selectors'    => array('#amount', '#app-shell'),
    )
)->toArray();
$runtimeSelectors = $runtimePreserved['source_reports']['conversion_report']['selector_summary']['selectors'] ?? array();
$runtimeClassifications = array();
foreach ( $runtimeSelectors as $selector ) {
    if ( 'block' === ($selector['kind'] ?? '') && 'core/html' === ($selector['block_name'] ?? '') ) {
        $runtimeClassifications[$selector['tag'] ?? ''] = $selector['conversion_classification'] ?? '';
    }
    if ( 'runtime_island' === ($selector['kind'] ?? '') ) {
        $runtimeClassifications[$selector['tag'] ?? ''] = $selector['conversion_classification'] ?? '';
    }
}
$assert('runtime_island_preserved' === ($runtimeClassifications['canvas'] ?? ''), 'runtime-preserved canvas metadata is classified as runtime island preservation');
$assert('runtime_island_preserved' === ($runtimeClassifications['input'] ?? ''), 'runtime-preserved control metadata is classified as runtime island preservation');
$runtimePreservedIslandKinds = array_map(static fn (array $island): string => (string) ($island['kind'] ?? ''), $runtimePreserved['source_reports']['runtime_islands'] ?? array());
$assert(in_array('dom', $runtimePreservedIslandKinds, true), 'runtime-preserved DOM target projects as a runtime island');
$runtimeSummary = $runtimePreserved['source_reports']['conversion_report']['conversion_classification_summary']['by_classification'] ?? array();
$assert(3 <= ($runtimeSummary['runtime_island_preserved'] ?? 0), 'conversion report summarizes runtime island preservation counts');

$decorativeSvgLayout = ( new HtmlTransformer() )->transform(
    '<div class="layout"><aside><svg class="brand-mark" aria-hidden="true"><path d="M0 0h10v10z"></path></svg><button id="navToggle" aria-label="Toggle navigation">Menu</button></aside><div id="overlay"></div><main><h1>Docs</h1><p>Readable content.</p></main></div>',
    array('runtime_dom_selectors' => array('#navToggle', '#overlay'))
)->toArray();
$decorativeSvgLayoutShellHtml = array_values(array_filter(
    $decorativeSvgLayout['blocks'] ?? array(),
    static fn (array $block): bool => 'core/html' === ($block['blockName'] ?? '') && str_contains((string) ($block['attrs']['content'] ?? ''), 'class="layout"')
));
$assert(array() === $decorativeSvgLayoutShellHtml, 'decorative SVG descendants do not force an ordinary layout wrapper into a raw app-shell island');
$assert(str_contains((string) ($decorativeSvgLayout['serialized_blocks'] ?? ''), '<!-- wp:heading'), 'decomposed decorative-SVG layout keeps native content blocks');

$runtimeSvgLayout = ( new HtmlTransformer() )->transform(
    '<div class="layout"><svg id="graph" role="img" aria-label="Runtime graph"></svg><button id="run">Run</button></div>',
    array('runtime_dom_selectors' => array('#graph', '#run'))
)->toArray();
$runtimeSvgLayoutShellHtml = array_values(array_filter(
	$runtimeSvgLayout['blocks'] ?? array(),
	static fn (array $block): bool => 'core/html' === ($block['blockName'] ?? '') && str_contains((string) ($block['attrs']['content'] ?? ''), 'class="layout"')
));
$runtimeSvgLayoutIslandSelectors = array_map(static fn (array $island): string => (string) ($island['selector'] ?? ''), $runtimeSvgLayout['source_reports']['runtime_islands'] ?? array());
$assert(array() === $runtimeSvgLayoutShellHtml, 'runtime-addressed SVG surfaces do not force their enclosing layout into a raw app-shell island');
$assert(in_array('#graph', $runtimeSvgLayoutIslandSelectors, true), 'runtime-addressed SVG surfaces preserve the SVG as a bounded runtime island');
$assert(in_array('#run', $runtimeSvgLayoutIslandSelectors, true), 'runtime-addressed SVG layouts preserve sibling runtime controls as bounded runtime islands');

$staggeredCards = ( new HtmlTransformer() )->transform(
    '<div class="cards" data-stagger="120"><article class="card"><h2>One</h2><p>Alpha.</p></article><article class="card"><h2>Two</h2><p>Beta.</p></article></div>',
    array('runtime_dom_selectors' => array('[data-stagger]'))
)->toArray();
$staggeredCardsHtml = array_values(array_filter(
    $staggeredCards['blocks'] ?? array(),
    static fn (array $block): bool => 'core/html' === ($block['blockName'] ?? '') && str_contains((string) ($block['attrs']['content'] ?? ''), 'data-stagger')
));
$assert(array() === $staggeredCardsHtml, 'presentational data-stagger animation hooks do not preserve card grids as raw runtime HTML');
$assert(str_contains((string) ($staggeredCards['serialized_blocks'] ?? ''), '<!-- wp:heading'), 'staggered card grids decompose to native editable blocks');

$unsupportedLoss = ( new HtmlTransformer() )->transform('<main><applet code="clock.class"></applet></main>')->toArray();
$unsupportedDiagnostic = $unsupportedLoss['source_reports']['conversion_report']['fallback_diagnostics'][0] ?? array();
$assert('html_unsupported_element' === ($unsupportedDiagnostic['diagnostic_code'] ?? ''), 'unsupported element emits fallback diagnostic');
$assert('unsupported_loss' === ($unsupportedDiagnostic['conversion_classification'] ?? ''), 'true unsupported fallback is classified as unsupported loss');
$assert('unsupported_applet' === ($unsupportedDiagnostic['pattern_family'] ?? ''), 'unsupported fallback exposes tag-specific pattern family');
$assert('add_generic_pattern_recognizer' === ($unsupportedDiagnostic['suggested_generic_repair_class'] ?? ''), 'unsupported fallback exposes generic recognizer repair class');
$assert('inside_main' === ($unsupportedDiagnostic['parent_reason'] ?? ''), 'unsupported fallback exposes parent context reason');

$decorativeCanvas = ( new HtmlTransformer() )->transform(
    '<main><section class="hero"><canvas id="stars" aria-hidden="true"></canvas><h1>Stars</h1></section></main>',
    array(
        'strict'          => true,
        'allow_fallbacks' => false,
    )
)->toArray();
$assert('success' === ($decorativeCanvas['status'] ?? ''), 'decorative canvas without runtime selectors does not trip strict fallback gates', (string) ($decorativeCanvas['status'] ?? ''));
$assert(array() === ($decorativeCanvas['fallbacks'] ?? array()), 'decorative canvas without runtime selectors is omitted instead of reported as runtime fallback');
$assert(! str_contains((string) ($decorativeCanvas['serialized_blocks'] ?? ''), '<canvas'), 'decorative canvas without runtime selectors is not emitted as raw markup');

$staticCanvas = ( new HtmlTransformer() )->transform(
    '<main><canvas id="static-canvas" class="preview" width="640" height="360"></canvas><h2>Static preview</h2></main>',
    array(
        'strict'          => true,
        'allow_fallbacks' => false,
    )
)->toArray();
$assert('success' === ($staticCanvas['status'] ?? ''), 'static canvas without runtime selectors does not trip strict fallback gates', (string) ($staticCanvas['status'] ?? ''));
$assert(array() === ($staticCanvas['fallbacks'] ?? array()), 'static canvas without runtime selectors is omitted instead of reported as runtime fallback');
$assert(! str_contains((string) ($staticCanvas['serialized_blocks'] ?? ''), '<canvas'), 'static canvas without runtime selectors is not emitted as raw markup');

$starfieldCanvas = ( new HtmlTransformer() )->transform(
    '<main><canvas class="starfield" aria-hidden="true"></canvas><h1>Night sky</h1></main>'
)->toArray();
$assert(array() === ($starfieldCanvas['source_reports']['runtime_islands'] ?? array()), 'decorative starfield canvas without runtime selectors is not reported as a runtime island');
$assert(array() === ($starfieldCanvas['fallbacks'] ?? array()), 'decorative starfield canvas without runtime selectors does not emit runtime fallback diagnostics');
$assert(! str_contains((string) ($starfieldCanvas['serialized_blocks'] ?? ''), 'starfield'), 'decorative starfield canvas without runtime selectors is omitted from serialized blocks');

$safeDecorativeSvg = ( new HtmlTransformer() )->transform(
    '<main><svg aria-hidden="true" viewBox="0 0 10 10"><circle cx="5" cy="5" r="5"></circle></svg><div class="site-logo"><svg viewBox="0 0 10 10"><path d="M0 0h10v10H0z"></path></svg></div></main>'
)->toArray();
$safeDecorativeDiagnostics = $safeDecorativeSvg['source_reports']['conversion_report']['fallback_diagnostics'] ?? array();
$assert(array() === $safeDecorativeDiagnostics, 'safe decorative inline SVGs do not emit fallback diagnostics');
$assert(1 <= ($safeDecorativeSvg['metrics']['block_count'] ?? 0), 'safe decorative inline SVG wrappers still materialize when they carry presentation signals');
$assert(str_contains((string) ($safeDecorativeSvg['serialized_blocks'] ?? ''), 'assets/materialized-svg/'), 'safe passive decorative inline SVGs serialize as native image asset URLs');
$assert(str_contains((string) ($safeDecorativeSvg['assets'][0]['content'] ?? ''), '<svg'), 'safe logo-like inline SVG markup is preserved inside the generated .svg asset');
$assert(1 <= count($safeDecorativeSvg['assets'] ?? array()), 'safe decorative inline SVG generates external .svg image assets');
$assert(str_contains((string) ($safeDecorativeSvg['serialized_blocks'] ?? ''), 'site-logo'), 'safe logo-like inline SVG context preserves its wrapper class');

$unsafeDecorativeSvg = ( new HtmlTransformer() )->transform(
    '<main><svg aria-hidden="true" viewBox="0 0 10 10"><script>alert(1)</script><circle onclick="alert(1)" cx="5" cy="5" r="5"></circle></svg></main>'
)->toArray();
$unsafeDecorativeContent = (string) ($unsafeDecorativeSvg['blocks'][0]['attrs']['content'] ?? '');
$assert('core/html' === ($unsafeDecorativeSvg['blocks'][0]['blockName'] ?? ''), 'unsafe decorative inline SVG is sanitized and preserved as a core/html block rather than dropped');
$assert(array() === ($unsafeDecorativeSvg['source_reports']['conversion_report']['fallback_diagnostics'] ?? array()), 'sanitized decorative inline SVG keeps its artwork and emits no fallback diagnostic');
$assert(str_contains($unsafeDecorativeContent, '<circle'), 'unsafe decorative inline SVG keeps its shape markup after sanitization');
$assert(! str_contains($unsafeDecorativeContent, '<script'), 'unsafe decorative inline SVG strips scripts while keeping the shapes');
$assert(! str_contains($unsafeDecorativeContent, 'onclick'), 'unsafe decorative inline SVG strips event-handler attributes while keeping the shapes');
$unsafeSvgEvidence = $unsafeDecorativeSvg['source_reports']['conversion_report']['core_html_fallback_evidence'] ?? array();
$unsafeSvgEmission = $unsafeSvgEvidence['emissions'][0] ?? array();
$assert('blocks-engine/core-html-fallback-evidence/v1' === ($unsafeSvgEvidence['schema'] ?? ''), 'core/html emissions expose versioned fallback evidence');
$assert('sanitization' === ($unsafeSvgEmission['reason'] ?? '') && '' !== ($unsafeSvgEmission['source_selector'] ?? '') && '' !== ($unsafeSvgEmission['block_path'] ?? ''), 'core/html evidence has a deterministic generic reason and source selector/path');
$assert(64 === strlen((string) ($unsafeSvgEmission['source_subtree']['digest'] ?? '')) && 64 === strlen((string) ($unsafeSvgEmission['emitted']['content_digest'] ?? '')), 'core/html evidence hashes source and emitted content');
$assert(! str_contains((string) ($unsafeSvgEmission['source_subtree']['snippet'] ?? ''), 'alert(1)') && ! str_contains((string) ($unsafeSvgEmission['source_subtree']['snippet'] ?? ''), 'onclick'), 'core/html evidence source snippets exclude source values and script payloads');

$interactions = ( new HtmlTransformer() )->transform(
    '<main><button aria-controls="panel" aria-expanded="false" data-action="toggle">Toggle</button><section id="panel">Panel</section><div role="tablist"><button role="tab" aria-controls="tab-one">One</button></div><div id="tab-one">Tab one</div><dialog id="signup">Join</dialog><div class="hero-carousel"><button class="carousel-next">Next</button></div></main>'
)->toArray();
$interactionKinds = array_map(static fn (array $candidate): string => (string) ($candidate['kind'] ?? ''), $interactions['source_reports']['interaction_candidates'] ?? array());
$assert(in_array('control', $interactionKinds, true), 'HTML source report detects declarative control interactions');
$assert(in_array('tabs', $interactionKinds, true), 'HTML source report detects tab interactions');
$assert(in_array('modal', $interactionKinds, true), 'HTML source report detects modal-ish interactions');
$assert(in_array('carousel', $interactionKinds, true), 'HTML source report detects carousel-ish interactions');
$assert('#panel' === ($interactions['source_reports']['interaction_candidates'][0]['target'] ?? ''), 'control interaction candidate exposes aria-controls target');

$emptyRuntimeControl = ( new HtmlTransformer() )->transform(
    '<main><button class="nav-toggle" aria-label="Open navigation" aria-expanded="false"><span></span><span></span><span></span></button></main>'
)->toArray();
$assert(str_contains((string) ($emptyRuntimeControl['serialized_blocks'] ?? ''), 'nav-toggle'), 'empty runtime control button class is preserved for scripts');
$assert(! str_contains((string) ($emptyRuntimeControl['serialized_blocks'] ?? ''), 'aria-expanded="false"'), 'empty runtime control button omits unsupported ARIA state from native core/button markup');

// Behavior-loss diagnostic: an interactive control converted to a static block
// without its behavior must surface a generic, severity-warning finding so the
// loss is no longer silent. Detection is structural (handler attributes, ARIA
// control state, declarative JS hooks, button role on a non-button), never a
// fixture-specific class string.
$behaviorLossCollect = static function (array $result): array {
    $codes = array();
    foreach ( $result['fallbacks'] ?? array() as $fallback ) {
        if ( 'interactive_control_behavior_lost' === ($fallback['diagnostic_code'] ?? '') ) {
            $codes[] = $fallback;
        }
    }
    return $codes;
};

$handlerControl = ( new HtmlTransformer() )->transform('<main><button onclick="doThing()">Act</button></main>')->toArray();
$handlerFindings = $behaviorLossCollect($handlerControl);
$assert(1 === count($handlerFindings), 'a button with an onclick handler that becomes a static block emits one behavior-loss finding');
$assert(in_array('onclick', $handlerFindings[0]['interaction_signals'] ?? array(), true), 'behavior-loss finding records the structural interaction signal');
$assert('warning' === ($handlerFindings[0]['severity'] ?? ''), 'behavior-loss finding is a warning');
$assert('behavior_loss' === ($handlerFindings[0]['conversion_classification'] ?? ''), 'behavior-loss finding is classified as behavior loss');
$assert('restore_interactive_behavior' === ($handlerFindings[0]['suggested_repair_class'] ?? ''), 'behavior-loss finding routes to the feature-parity repair bucket');
$assert('interactive_control' === ($handlerFindings[0]['pattern_family'] ?? ''), 'behavior-loss finding exposes the generic interactive control pattern family');
$handlerLossDiagnostic = array_values(array_filter($handlerControl['diagnostics'] ?? array(), static fn (array $diagnostic): bool => 'interactive_control_behavior_lost' === ($diagnostic['code'] ?? '')));
$assert(1 === count($handlerLossDiagnostic), 'behavior-loss finding is projected into the diagnostics stream');

$ariaToggleNoNav = ( new HtmlTransformer() )->transform('<main><header><button aria-controls="missing" aria-expanded="false"><span></span></button></header></main>')->toArray();
$assert(array() === $behaviorLossCollect($ariaToggleNoNav), 'ARIA state without a handler or available script remains a static native control without a behavior-loss finding');

$roleButtonControl = ( new HtmlTransformer() )->transform('<main><div role="button" data-action="open">Open</div></main>')->toArray();
$assert(1 === count($behaviorLossCollect($roleButtonControl)), 'a non-button element with role=button plus a declarative handler emits a behavior-loss finding');

$inertPopupControl = ( new HtmlTransformer() )->transform('<main><a role="button" aria-haspopup="dialog" data-popupid="x">Contact</a><div role="button" aria-label="Enlarge"><span>Enlarge</span></div></main>')->toArray();
$assert(array() === $behaviorLossCollect($inertPopupControl), 'static controls with inert popup metadata retain native content without inferred runtime fallbacks');

$stylesheetInContent = ( new HtmlTransformer() )->transform('<main><link rel="stylesheet" href="theme.css"><p>Visible copy</p></main>')->toArray();
$assert(array() === ($stylesheetInContent['fallbacks'] ?? array()) && str_contains((string) ($stylesheetInContent['serialized_blocks'] ?? ''), 'Visible copy') && ! str_contains((string) ($stylesheetInContent['serialized_blocks'] ?? ''), '<link'), 'document resource links do not become page-content fallbacks');

$inertSvgStorage = ( new HtmlTransformer() )->transform('<main><svg aria-hidden="true" style="display:none"><defs id="store"></defs></svg><p>Visible copy</p></main>')->toArray();
$assert(! str_contains((string) ($inertSvgStorage['serialized_blocks'] ?? ''), '<!-- wp:html') && str_contains((string) ($inertSvgStorage['serialized_blocks'] ?? ''), 'Visible copy'), 'hidden SVG storage without drawable or accessible content does not become a page-content island');

// Negatives: ordinary content must stay silent.
$plainButton = ( new HtmlTransformer() )->transform('<main><button type="submit">Sign Up</button></main>')->toArray();
$assert(array() === $behaviorLossCollect($plainButton), 'a plain button with no interaction signals does not emit a behavior-loss finding');

$plainLink = ( new HtmlTransformer() )->transform('<main><a href="/about">About</a></main>')->toArray();
$assert(array() === $behaviorLossCollect($plainLink), 'a plain link does not emit a behavior-loss finding');

$roleButtonLink = ( new HtmlTransformer() )->transform('<main><a role="button" href="/buy">Buy</a></main>')->toArray();
$assert(array() === $behaviorLossCollect($roleButtonLink), 'a real link styled with role=button preserves navigation and does not emit a behavior-loss finding');

$valueDataAttribute = ( new HtmlTransformer() )->transform('<main><span data-target="47200">0</span></main>')->toArray();
$assert(array() === $behaviorLossCollect($valueDataAttribute), 'a data-* attribute that carries a value rather than binding behavior does not emit a behavior-loss finding');

$foldedNavToggle = ( new HtmlTransformer() )->transform('<header><div class="header-inner"><a class="brand" href="/">Logo</a><nav class="nav-links"><a href="/">Home</a><a href="/about">About</a></nav><button class="nav-toggle" aria-label="Open navigation menu" aria-controls="mobile-nav" aria-expanded="false"><span></span><span></span><span></span></button></div></header><nav class="mobile-nav" id="mobile-nav"><a href="/">Home</a><a href="/about">About</a></nav>')->toArray();
$assert(array() === $behaviorLossCollect($foldedNavToggle), 'a hamburger toggle folded into core/navigation does not emit a behavior-loss finding');

$assetMetadataOptions = array(
    'context' => array(
        'asset_metadata' => array(
            'assets/hero.jpg' => array(
                'id'  => 42,
                'url' => 'https://example.test/wp-content/uploads/hero.jpg',
            ),
            'media/root-hero.jpg' => array(
                'id'  => 43,
                'url' => 'https://example.test/wp-content/uploads/root-hero.jpg',
            ),
            'media/root-background.jpg' => array(
                'url' => 'https://example.test/wp-content/uploads/root-background.jpg',
            ),
        ),
    ),
);
$resolvedImage = ( new HtmlTransformer() )->transform('<main><img src="assets/hero.jpg" alt="Hero alt"></main>', $assetMetadataOptions)->toArray();
$resolvedImageAttrs = $resolvedImage['blocks'][0]['attrs'] ?? array();
$assert(42 === ($resolvedImageAttrs['id'] ?? null), 'HTML image transform applies resolved asset id from context metadata');
$assert('https://example.test/wp-content/uploads/hero.jpg' === ($resolvedImageAttrs['url'] ?? ''), 'HTML image transform applies resolved asset URL from context metadata');
$assert('Hero alt' === ($resolvedImageAttrs['alt'] ?? ''), 'HTML image transform preserves original alt text while resolving asset metadata');
$assert(str_contains((string) ($resolvedImage['serialized_blocks'] ?? ''), 'src="https://example.test/wp-content/uploads/hero.jpg"'), 'HTML image transform serializes resolved asset URL');
$assert(str_contains((string) ($resolvedImage['serialized_blocks'] ?? ''), 'class="wp-image-42"'), 'HTML image transform serializes resolved image id class');

$resolvedRootImage = ( new HtmlTransformer() )->transform('<main><img src="/media/root-hero.jpg?size=large#hero" srcset="/media/root-hero.jpg?size=small 480w, /media/root-hero.jpg?size=large 960w" alt="Root hero"></main>', $assetMetadataOptions)->toArray();
$resolvedRootImageAttrs = $resolvedRootImage['blocks'][0]['attrs'] ?? array();
$assert('core/image' === ($resolvedRootImage['blocks'][0]['blockName'] ?? null), 'metadata-backed standalone responsive image remains core/image');
$assert('https://example.test/wp-content/uploads/root-hero.jpg?size=large#hero' === ($resolvedRootImageAttrs['url'] ?? ''), 'metadata-backed root-relative image preserves its authored query and fragment suffix');
$assert(! isset($resolvedRootImageAttrs['srcset'], $resolvedRootImageAttrs['sizes']) && ! str_contains((string) ($resolvedRootImage['serialized_blocks'] ?? ''), 'srcset=') && ! str_contains((string) ($resolvedRootImage['serialized_blocks'] ?? ''), 'sizes='), 'metadata-backed standalone responsive image does not add srcset or sizes to core/image');

$resolvedRootBackground = ( new HtmlTransformer() )->transform('<main><div style="width:640px;height:320px;background-image:url(/media/root-background.jpg?crop=wide#panel)"></div></main>', $assetMetadataOptions)->toArray();
$resolvedRootBackgroundMarkup = (string) ($resolvedRootBackground['serialized_blocks'] ?? '');
$assert(str_contains($resolvedRootBackgroundMarkup, 'src="https://example.test/wp-content/uploads/root-background.jpg?crop=wide#panel"'), 'metadata-backed extracted root-relative background preserves its authored query and fragment suffix');
$assert(str_contains($resolvedRootBackgroundMarkup, 'blocks-engine-background-image'), 'metadata-backed root-relative background remains an extracted editable image reference');

$linkedRuntimeImage = ( new HtmlTransformer() )->transform(
    '<main><a id="productHero" class="product-detail__main-image" href="/product"><img src="assets/product.jpg" alt="Product"></a></main>'
)->toArray();
$linkedRuntimeImageSerialized = (string) ($linkedRuntimeImage['serialized_blocks'] ?? '');
$linkedRuntimeImageContent = (string) ($linkedRuntimeImage['blocks'][0]['attrs']['content'] ?? '');
$assert(str_contains($linkedRuntimeImageContent, 'id="productHero"'), 'linked image conversion preserves linked media anchor IDs for runtime selectors');
$assert(str_contains($linkedRuntimeImageContent, 'class="product-detail__main-image"'), 'linked image conversion preserves linked media classes for runtime selectors');

$bridgeImageBlocks = ( new FormatBridge() )->toBlocks('<main><img src="assets/hero.jpg" alt="Hero alt"></main>', 'html', $assetMetadataOptions);
$bridgeImageAttrs = $bridgeImageBlocks[0]['attrs'] ?? array();
$assert(42 === ($bridgeImageAttrs['id'] ?? null), 'FormatBridge HTML adapter applies resolved asset id from context metadata');
$assert('https://example.test/wp-content/uploads/hero.jpg' === ($bridgeImageAttrs['url'] ?? ''), 'FormatBridge HTML adapter applies resolved asset URL from context metadata');
$assert('Hero alt' === ($bridgeImageAttrs['alt'] ?? ''), 'FormatBridge HTML adapter preserves original alt text while resolving asset metadata');

$compiler = new ArtifactCompiler();

$simple = $compiler->compile(
    array(
        'schema'         => ArtifactCompiler::INPUT_SCHEMA,
        'generated_html' => '<main><article data-component="Hero"><h1>Hello artifact</h1></article></main>',
    )
)->toArray();
TransformerResult::assertCanonicalEnvelope($simple);
$assert('success' === $simple['status'], 'simple artifact compiles successfully', (string) $simple['status']);
$simplePlanView = ( new WordPressSitePlanView() )->fromResult($simple);
$boundedHandoffResult = $compiler->compile(array('generated_html' => '<main><h1>Bounded handoff</h1></main>'));
$simpleObjectPlanView = $boundedHandoffResult->toWordPressSitePlanView();
$assert(WordPressSitePlanView::SCHEMA === ($simplePlanView['schema'] ?? ''), 'WordPress site plan view exposes its own schema');
$assert(WordPressSitePlanView::SCHEMA === ($simpleObjectPlanView['schema'] ?? ''), 'Transformer result exposes the bounded WordPress site plan view directly');
$assert(($simple['source_reports']['wordpress_site_plan'] ?? array()) === ($simplePlanView['wordpress_site_plan'] ?? null), 'WordPress site plan view preserves the exact canonical plan');
$assert(($boundedHandoffResult->toArray()['source_reports']['wordpress_site_plan'] ?? array()) === ($simpleObjectPlanView['wordpress_site_plan'] ?? null), 'TransformerResult handoff preserves the exact canonical plan without a compatibility projection');
$assert(($simple['source_reports']['editability_report'] ?? null) === ($simplePlanView['editability_report'] ?? null), 'WordPress site plan view preserves the producer-owned editability report exactly');
$assert(array('schema', 'metrics', 'block_types', 'documents', 'signals', 'signal_totals') === array_keys($simplePlanView['editability_report'] ?? array()) && EditabilityReport::SCHEMA === ($simplePlanView['editability_report']['schema'] ?? null), 'WordPress site plan view exposes the current versioned editability report shape');
$boundedEditabilityReport = (new EditabilityReport())->fromDocuments(array('large.html' => array('blocks' => array_fill(0, 101, array('blockName' => 'core/group', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '')))));
$boundedPlanResult = $simple;
$boundedPlanResult['source_reports']['editability_report'] = $boundedEditabilityReport;
$boundedPlanView = (new WordPressSitePlanView())->fromResult($boundedPlanResult);
$assert($boundedEditabilityReport === ($boundedPlanView['editability_report'] ?? null) && 101 === ($boundedPlanView['editability_report']['signal_totals']['observed'] ?? null) && 100 === ($boundedPlanView['editability_report']['signal_totals']['reported'] ?? null) && 1 === ($boundedPlanView['editability_report']['signal_totals']['omitted'] ?? null) && true === ($boundedPlanView['editability_report']['signal_totals']['truncated'] ?? null) && 100 === count($boundedPlanView['editability_report']['signals'] ?? array()), 'WordPress site plan view preserves bounded editability evidence without reprojecting it');
$assert(array('schema', 'result_schema', 'status', 'wordpress_site_plan', 'gutenberg_gaps', 'companion_plugin_payload', 'font_materialization', 'editability_report', 'diagnostics') === array_keys($simplePlanView), 'WordPress site plan view has a stable bounded shape');
$assert(!isset($simplePlanView['compiled_site'], $simplePlanView['materialization_plan'], $simplePlanView['assets'], $simplePlanView['documents'], $simplePlanView['blocks']), 'WordPress site plan view omits duplicate legacy and root projections');
$failedPlanResult = $simple;
$failedPlanResult['status'] = 'failed';
unset($failedPlanResult['source_reports']['wordpress_site_plan'], $failedPlanResult['source_reports']['wordpress_site_plan_diagnostics']);
$failedPlanResult['diagnostics'] = array(
    array('code' => 'non_plan_warning', 'severity' => 'warning', 'message' => 'Not actionable for a failed handoff.'),
    array('code' => 'non_plan_failure', 'severity' => 'error', 'message' => 'Canonical compiler failure.'),
);
$failedPlanView = (new WordPressSitePlanView())->fromResult($failedPlanResult);
$assert(array('non_plan_failure') === array_column($failedPlanView['diagnostics'], 'code'), 'failed WordPress site plan view retains canonical compiler errors when no plan-specific diagnostic exists');
$planSpecificDiagnostic = array('code' => 'wordpress_site_plan_invalid', 'severity' => 'error', 'message' => 'Plan-specific failure.');
$failedPlanResult['source_reports']['wordpress_site_plan_diagnostics'] = array($planSpecificDiagnostic);
$failedPlanView = (new WordPressSitePlanView())->fromResult($failedPlanResult);
$assert(array($planSpecificDiagnostic) === $failedPlanView['diagnostics'], 'failed WordPress site plan view preserves existing plan-specific diagnostics exactly');
unset($failedPlanResult['source_reports']['wordpress_site_plan_diagnostics']);
$failedPlanResult['diagnostics'] = array_map(
    static fn (int $index): array => array('code' => 'compiler_error_' . $index, 'severity' => 'error', 'message' => 'Compiler error.'),
    range(1, WordPressSitePlanView::MAX_FAILURE_DIAGNOSTICS + 5)
);
$failedPlanView = (new WordPressSitePlanView())->fromResult($failedPlanResult);
$truncationDiagnostic = $failedPlanView['diagnostics'][WordPressSitePlanView::MAX_FAILURE_DIAGNOSTICS - 1] ?? array();
$assert(WordPressSitePlanView::MAX_FAILURE_DIAGNOSTICS === count($failedPlanView['diagnostics']) && 'compiler_error_1' === ($failedPlanView['diagnostics'][0]['code'] ?? '') && 'compiler_error_99' === ($failedPlanView['diagnostics'][98]['code'] ?? '') && 'wordpress_site_plan_view_diagnostics_truncated' === ($truncationDiagnostic['code'] ?? '') && 99 === ($truncationDiagnostic['retained_count'] ?? null) && 6 === ($truncationDiagnostic['omitted_count'] ?? null), 'failed WordPress site plan view deterministically bounds canonical compiler errors with explicit truncation evidence');
$assert(ArtifactCompiler::INPUT_SCHEMA === ($simple['source_reports']['artifact']['schema'] ?? ''), 'artifact report exposes canonical site artifact schema');
$assert(ArtifactCompiler::INPUT_SCHEMA === ($simple['source_reports']['artifact']['original_schema'] ?? ''), 'canonical site artifact input schema is accepted and preserved');
$assert('index.html' === ($simple['source_reports']['artifact']['entry_path'] ?? ''), 'generated HTML becomes an index entry');
$assert(str_contains((string) $simple['serialized_blocks'], '<!-- wp:heading'), 'artifact HTML is transformed into native serialized block markup');
$assert(! str_contains((string) $simple['serialized_blocks'], '<!-- wp:html -->'), 'artifact HTML does not fall back to raw HTML when transformer-safe');
$assert('hero' === ($simple['components'][0]['name'] ?? ''), 'component candidates are exposed');
$assert(! array_key_exists('legacy_mapping', $simple), 'artifact result omits compatibility-only legacy mapping');
$assert(strlen('<main><article data-component="Hero"><h1>Hello artifact</h1></article></main>') === ($simple['metrics']['input_bytes'] ?? null), 'artifact metrics expose input bytes');
$assert(strlen((string) $simple['serialized_blocks']) === ($simple['metrics']['output_bytes'] ?? null), 'artifact metrics expose output bytes');
$assert(2 === ($simple['metrics']['block_count'] ?? null), 'artifact metrics expose nested block count');
$assert(0 === ($simple['metrics']['fallback_count'] ?? null), 'artifact metrics expose fallback count');
$assert(0 === ($simple['metrics']['diagnostic_count'] ?? null), 'artifact metrics expose diagnostic count');
$assert(is_float($simple['metrics']['transform_duration_ms'] ?? null), 'artifact metrics expose transform duration');
$assert(!isset($simple['source_reports']['materialization_plan']), 'artifact omits the superseded materialization plan projection');
$assert('index.html' === ($simple['source_reports']['wordpress_site_plan']['source']['entry_path'] ?? ''), 'canonical plan exposes entry path');

$artifactNavAnchorCss = $compiler->compile(
    array(
        'entry' => 'index.html',
        'files' => array(
            'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="styles.css"></head><body><header class="site-header"><nav class="subnav"><a href="#one">One</a></nav></header></body></html>',
            'styles.css' => '.site-header .subnav a{color:#31251c;text-decoration:none;border-color:#31251c}.site-header .subnav a:hover{color:#8f5031;border-color:#8f5031}',
        ),
    )
)->toArray();
$artifactNavAnchorStaticCss = (string) ($artifactNavAnchorCss['source_reports']['compiled_site']['theme']['static_css'] ?? '');
$assert(str_contains($artifactNavAnchorStaticCss, '.site-header .subnav.wp-block-navigation .wp-block-navigation-item__content, .site-header .subnav .wp-block-navigation .wp-block-navigation-item__content { color:#31251c;text-decoration:none;border-color:#31251c }'), 'artifact static CSS replays nested nav anchor color through direct and descendant core/navigation wrappers');
$assert(str_contains($artifactNavAnchorStaticCss, '.site-header .subnav.wp-block-navigation .wp-block-navigation-item__content:hover, .site-header .subnav .wp-block-navigation .wp-block-navigation-item__content:hover { color:#8f5031;border-color:#8f5031 }'), 'artifact static CSS replays nested nav anchor hover color through core/navigation wrappers');
$assert(! str_contains($artifactNavAnchorStaticCss, '.site-header.wp-block-navigation .subnav'), 'artifact static CSS does not attach core/navigation to the wrong ancestor selector');
$artifactNavAnchorRepairCss = (string) ($artifactNavAnchorCss['source_reports']['compiled_site']['visual_repair']['css'] ?? '');
$assert(str_contains($artifactNavAnchorRepairCss, '.site-header .subnav.wp-block-navigation .wp-block-navigation-item__content, .site-header .subnav .wp-block-navigation .wp-block-navigation-item__content { color:#31251c;text-decoration:none;border-color:#31251c }'), 'artifact visual repair CSS carries nav anchor replay for downstream theme materializers');

$artifactNavStructureCss = $compiler->compile(
    array(
        'entry' => 'index.html',
        'files' => array(
            'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="styles.css"></head><body class="menu-ready"><header><nav class="desktop-nav"><ul class="site-menu"><li><a href="#one">One</a></li></ul></nav></header></body></html>',
            'styles.css' => '.desktop-nav li{float:left}@media(max-width:700px){.site-menu{display:none!important}}@media screen and (min-width:1025px){body.menu-ready .desktop-nav ul.site-menu{visibility:hidden;opacity:0}body.menu-ready .desktop-nav ul.site-menu.visible{visibility:visible;opacity:1}body.menu-ready .desktop-nav/* menu, desktop */ ul.site-menu>li{display:flex;align-items:center;margin-right:30px}body.menu-ready .desktop-nav ul.site-menu>li a{font-family:Montserrat,sans-serif;font-size:16px;color:#000;text-transform:lowercase;padding-bottom:7px}.nav\\,alternate ul.site-menu>li{gap:4px}.desktop-nav ul.site-menu:has([data-kind="/* promoted */"])>li{order:2}}',
        ),
    )
)->toArray();
$artifactNavStructureMarkup = (string) ($artifactNavStructureCss['serialized_blocks'] ?? '');
$artifactNavStructureStaticCss = (string) ($artifactNavStructureCss['source_reports']['compiled_site']['theme']['static_css'] ?? '');
$assert(str_contains($artifactNavStructureMarkup, 'desktop-nav') && str_contains($artifactNavStructureMarkup, 'site-menu') && str_contains($artifactNavStructureMarkup, 'blocks-engine-list-navigation'), 'list navigation promotes source list classes onto the core navigation wrapper');
$assert(str_contains($artifactNavStructureStaticCss, '@media screen and (min-width:1025px)'), 'artifact navigation projection preserves its authored responsive condition');
$assert(str_contains($artifactNavStructureStaticCss, '.desktop-nav.site-menu.wp-block-navigation .wp-block-navigation__container>') && str_contains($artifactNavStructureStaticCss, 'display:flex;align-items:center') && str_contains($artifactNavStructureStaticCss, 'margin-right:30px'), 'artifact CSS projects source navigation item geometry onto core navigation items', $artifactNavStructureStaticCss);
$assert(str_contains($artifactNavStructureStaticCss, '.desktop-nav.site-menu.wp-block-navigation .wp-block-navigation__container>') && str_contains($artifactNavStructureStaticCss, '.wp-block-navigation-item__content { font-family:Montserrat,sans-serif;font-size:16px;color:#000;text-transform:lowercase;padding-bottom:7px }'), 'artifact CSS projects source list anchor typography onto core navigation content');
$assert(str_contains($artifactNavStructureStaticCss, '.nav\\,alternate.site-menu.wp-block-navigation .wp-block-navigation__container>') && str_contains($artifactNavStructureStaticCss, 'gap:4px'), 'artifact navigation projection preserves escaped selector punctuation');
$assert(str_contains($artifactNavStructureStaticCss, '[data-kind="/* promoted */"]') && str_contains($artifactNavStructureStaticCss, 'order:2'), 'artifact navigation projection preserves comment-like text inside quoted selector values');
$artifactNavStructureCompatOffset = strpos($artifactNavStructureStaticCss, 'wp-compat: project source list navigation structure');
$artifactNavStructureCompatCss = false === $artifactNavStructureCompatOffset ? '' : substr($artifactNavStructureStaticCss, $artifactNavStructureCompatOffset);
$artifactNavStructureAssetCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $artifactNavStructureCss['assets'] ?? array()));
$assert(str_contains($artifactNavStructureStaticCss, '.wp-block-navigation__container>.wp-block-navigation-item') && ! str_contains($artifactNavStructureCompatCss, 'blocks-engine-source-li-'), 'artifact navigation projection replaces non-serialized source list markers with core navigation item selectors');
$assert(str_contains($artifactNavStructureCompatCss, '.desktop-nav.wp-block-navigation .wp-block-navigation__container .wp-block-navigation-item { float:left }'), 'artifact navigation projection maps classed navigation ancestor item selectors onto core navigation structure', $artifactNavStructureCompatCss);
$assert(str_contains($artifactNavStructureMarkup, '"overlayMenu":"never"') && ! str_contains($artifactNavStructureAssetCss, 'blocks-engine-native-responsive-navigation{display:flex!important}'), 'list navigation without an authored responsive control preserves its mobile visibility contract', $artifactNavStructureAssetCss);
$assert(! str_contains($artifactNavStructureCompatCss, '.wp-block-navigation__container { visibility:hidden }'), 'artifact navigation projection leaves script-driven list container visibility to core navigation');
$assert(str_contains($artifactNavStructureCompatCss, '.menu-ready .desktop-nav.site-menu.wp-block-navigation .wp-block-navigation__container { visibility:visible;opacity:1 }'), 'artifact navigation projection materializes the source list stable visible state for core navigation', $artifactNavStructureCompatCss);

$artifactMobileNavOverlay = $compiler->compile(
    array(
        'entry' => 'index.html',
        'files' => array(
            'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="styles.css"></head><body><nav class="desktop-nav"><ul><li><a href="/">Home</a></li><li><a href="/about">About</a></li></ul></nav><div class="mobile-nav"><nav><ul><li><a href="/">Home</a></li><li><a href="/about">About</a></li></ul></nav></div></body></html>',
            'styles.css' => '.desktop-nav a{color:#fff}@media(max-width:700px){.desktop-nav{display:none}.mobile-nav{background:rgba(0,0,0,.9)}}',
        ),
    )
)->toArray();
$artifactMobileNavOverlayAssetCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $artifactMobileNavOverlay['assets'] ?? array()));
$assert(str_contains($artifactMobileNavOverlayAssetCss, '.wp-block-navigation.blocks-engine-list-navigation .wp-block-navigation__responsive-container.is-menu-open{background:rgba(0,0,0,.9)!important}'), 'deduplicated mobile navigation projects its authored background authoritatively over the core overlay default', $artifactMobileNavOverlayAssetCss);
$assert(str_contains((string) ($artifactMobileNavOverlay['serialized_blocks'] ?? ''), 'blocks-engine-native-responsive-navigation') && str_contains((string) ($artifactMobileNavOverlay['serialized_blocks'] ?? ''), '"overlayMenu":"mobile"'), 'equivalent authored desktop/mobile navigations retain one native responsive overlay');

$artifactToggleNavigation = $compiler->compile(
    array(
        'entry' => 'index.html',
        'files' => array(
            'index.html' => '<header><button aria-controls="menu" aria-expanded="false"><span></span><span></span></button><nav id="menu"><ul><li><a href="/">Home</a></li><li><a href="/about">About</a></li></ul></nav></header>',
        ),
    )
)->toArray();
$artifactToggleNavigationMarkup = (string) ($artifactToggleNavigation['serialized_blocks'] ?? '');
$artifactToggleNavigationCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $artifactToggleNavigation['assets'] ?? array()));
$assert(str_contains($artifactToggleNavigationMarkup, '"overlayMenu":"mobile"') && str_contains($artifactToggleNavigationMarkup, 'blocks-engine-native-responsive-navigation'), 'an authored hamburger control promotes its associated menu to native responsive navigation');
$assert(str_contains($artifactToggleNavigationCss, '.wp-block-navigation.blocks-engine-list-navigation.blocks-engine-native-responsive-navigation{display:flex!important}'), 'only authored responsive navigation receives the after-author visible-host bridge');

$artifactSummaryToggleNavigation = $compiler->compile(
    array(
        'entry' => 'index.html',
        'files' => array(
            'index.html' => '<header><nav class="menu"><ul><li><a href="/">Home</a></li><li><a href="/about">About</a></li></ul></nav><nav><details><summary aria-label="Menu" style="box-sizing:border-box;width:40px;height:40px;padding:5px"><svg aria-hidden="true"></svg></summary></details></nav></header>',
        ),
    )
)->toArray();
$artifactSummaryToggleNavigationMarkup = (string) ($artifactSummaryToggleNavigation['serialized_blocks'] ?? '');
$artifactSummaryToggleNavigationCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $artifactSummaryToggleNavigation['assets'] ?? array()));
$assert(str_contains($artifactSummaryToggleNavigationMarkup, '"overlayMenu":"mobile"') && str_contains($artifactSummaryToggleNavigationMarkup, 'blocks-engine-native-responsive-navigation'), 'a semantic details summary menu control promotes its associated menu to native responsive navigation');
$assert(! str_contains($artifactSummaryToggleNavigationMarkup, '<!-- wp:details') && ! str_contains($artifactSummaryToggleNavigationMarkup, '<summary'), 'native responsive navigation supersedes empty details summary menu chrome', $artifactSummaryToggleNavigationMarkup);
$assert(str_contains($artifactSummaryToggleNavigationMarkup, 'blocks-engine-native-navigation-toggle-') && str_contains($artifactSummaryToggleNavigationCss, '>.wp-block-navigation__responsive-container-open{') && str_contains($artifactSummaryToggleNavigationCss, 'width:40px!important') && str_contains($artifactSummaryToggleNavigationCss, 'padding:5px!important'), 'native responsive navigation projects source toggle geometry onto the core open control', $artifactSummaryToggleNavigationCss);

$artifactCheckboxLabelNavigation = $compiler->compile(
    array(
        'entry' => 'index.html',
        'files' => array(
            'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="styles.css"></head><body><input type="checkbox" id="menu-toggle"><div id="header-wrapper"><div class="actions"><div class="menu"><ul><li><a href="/">Home</a></li><li><a href="/about">About</a></li></ul></div><label class="hamburger" for="menu-toggle"></label></div></div></body></html>',
            'styles.css' => '#menu-toggle{position:absolute;opacity:0}.actions{text-align:right}.menu{display:inline-block}.menu a{color:#fff}@media(max-width:700px){.menu{display:none!important}}',
        ),
    )
)->toArray();
$artifactCheckboxLabelNavigationMarkup = (string) ($artifactCheckboxLabelNavigation['serialized_blocks'] ?? '');
$artifactCheckboxLabelNavigationCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $artifactCheckboxLabelNavigation['assets'] ?? array()));
$assert(str_contains($artifactCheckboxLabelNavigationMarkup, '"overlayMenu":"mobile"') && str_contains($artifactCheckboxLabelNavigationMarkup, 'blocks-engine-native-responsive-navigation') && str_contains($artifactCheckboxLabelNavigationMarkup, 'blocks-engine-inline-navigation'), 'a checkbox-bound empty label associated with an inline menu promotes native responsive navigation while retaining inline layout');
$assert(! str_contains($artifactCheckboxLabelNavigationMarkup, 'menu-toggle') && str_contains($artifactCheckboxLabelNavigationCss, '.wp-block-navigation.blocks-engine-native-responsive-navigation.blocks-engine-inline-navigation{display:inline-flex!important}') && str_contains($artifactCheckboxLabelNavigationCss, '>.wp-block-navigation__responsive-container-open') && str_contains($artifactCheckboxLabelNavigationCss, '{color:#fff}'), 'native responsive navigation supersedes checkbox-label chrome and restores inline-level host alignment and toggle contrast after author CSS');

$artifactFormCheckboxLabel = $compiler->compile(
    array(
        'entry' => 'index.html',
        'files' => array(
            'index.html' => '<nav><ul><li><a href="/">Home</a></li><li><a href="/about">About</a></li></ul></nav><form><input type="checkbox" id="terms"><label for="terms"></label></form>',
        ),
    )
)->toArray();
$assert(! str_contains((string) ($artifactFormCheckboxLabel['serialized_blocks'] ?? ''), 'blocks-engine-native-responsive-navigation'), 'an empty checkbox label inside a form does not promote unrelated navigation to a responsive overlay');

$artifactVisibleCheckboxLabel = $compiler->compile(
    array(
        'entry' => 'index.html',
        'files' => array(
            'index.html' => '<nav><ul><li><a href="/">Home</a></li><li><a href="/about">About</a></li></ul></nav><input type="checkbox" id="setting"><label for="setting"></label>',
        ),
    )
)->toArray();
$artifactVisibleCheckboxLabelMarkup = (string) ($artifactVisibleCheckboxLabel['serialized_blocks'] ?? '');
$assert(str_contains($artifactVisibleCheckboxLabelMarkup, 'setting') && ! str_contains($artifactVisibleCheckboxLabelMarkup, 'blocks-engine-native-responsive-navigation'), 'a visible non-form checkbox and empty label remain content and do not promote unrelated navigation');

$artifactHiddenCheckboxLabel = $compiler->compile(
    array(
        'entry' => 'index.html',
        'files' => array(
            'index.html' => '<style>#theme-toggle{position:absolute;opacity:0}</style><header><nav><ul><li><a href="/">Home</a></li><li><a href="/about">About</a></li></ul></nav><input type="checkbox" id="theme-toggle"><label for="theme-toggle"></label></header>',
        ),
    )
)->toArray();
$artifactHiddenCheckboxLabelMarkup = (string) ($artifactHiddenCheckboxLabel['serialized_blocks'] ?? '');
$assert(str_contains($artifactHiddenCheckboxLabelMarkup, 'theme-toggle') && ! str_contains($artifactHiddenCheckboxLabelMarkup, 'blocks-engine-native-responsive-navigation'), 'an accessible-hidden theme toggle beside header navigation remains content and does not promote unrelated navigation');

$artifactNonResponsiveInlineNavigation = $compiler->compile(
    array(
        'entry' => 'index.html',
        'files' => array(
            'index.html' => '<style>.menu{display:inline-block}@media(max-width:700px){.menu{display:none}}</style><nav class="menu"><ul><li><a href="/">Home</a></li><li><a href="/about">About</a></li></ul></nav>',
        ),
    )
)->toArray();
$artifactNonResponsiveInlineNavigationMarkup = (string) ($artifactNonResponsiveInlineNavigation['serialized_blocks'] ?? '');
$artifactNonResponsiveInlineNavigationCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $artifactNonResponsiveInlineNavigation['assets'] ?? array()));
$assert(! str_contains($artifactNonResponsiveInlineNavigationMarkup, 'blocks-engine-inline-navigation') && ! str_contains($artifactNonResponsiveInlineNavigationCss, 'display:inline-flex!important'), 'a non-responsive inline menu retains its authored mobile display cascade without a native visibility override');

$artifactHeaderRuntimeCss = $compiler->compile(
    array(
        'entry' => 'index.html',
        'files' => array(
            'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="styles.css"></head><body><header><nav><ul><li id=selected><a href="/">Home</a></li></ul></nav><div class="site-utils"><span class="wsite-search"><form action="/apps/search" method="get"><input name="q" type="text"></form></span><button class="search-icon"><svg></svg></button></div></header></body></html>',
            'styles.css' => '@media (min-width:1px){.site-utils .search-icon{display:none;height:80px}}',
        ),
    )
)->toArray();
$artifactHeaderRuntimeStaticCss = (string) ($artifactHeaderRuntimeCss['source_reports']['compiled_site']['theme']['static_css'] ?? '');
$assert(str_contains($artifactHeaderRuntimeStaticCss, '.blocks-engine-current-navigation-underline>.wp-block-navigation-item__content { text-decoration:underline }'), 'artifact CSS renders only source-authored current navigation underlines on core navigation links');
$assert(str_contains($artifactHeaderRuntimeStaticCss, '.wp-block-search__button.has-icon>.search-icon { display:block!important;height:1.25em!important }'), 'artifact CSS protects the core search SVG from colliding source search-icon hidden states');

$artifactNavContainerCss = $compiler->compile(
    array(
        'entry' => 'index.html',
        'files' => array(
            'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="styles.css"><script src="startup.js"></script></head><body><header class="site-header"><nav class="desktop-nav"><a href="#one">One</a></nav><div class="collapsed-nav"><ul><li><a href="#one">One</a></li></ul></div></header></body></html>',
            'styles.css' => '.collapsed-nav,.drawer-panel{display:none;position:absolute}.collapsed-nav.visible{display:block!important}.desktop-nav{display:block}body.fade-in-nav .site-header{opacity:1!important}.collapsed-nav>ul{padding:2rem}@media(max-width:600px){.desktop-nav{display:none!important}}',
            'startup.js' => '$("body").addClass("fade-in-nav");',
        ),
    )
)->toArray();
$artifactNavContainerStaticCss = (string) ($artifactNavContainerCss['source_reports']['compiled_site']['theme']['static_css'] ?? '');
$assert(str_contains($artifactNavContainerStaticCss, '.collapsed-nav.wp-block-navigation { display:none;position:absolute }'), 'artifact static CSS strengthens source navigation container state against core navigation display rules');
$assert(str_contains($artifactNavContainerStaticCss, '.collapsed-nav.visible.wp-block-navigation { display:block!important }'), 'artifact static CSS preserves source navigation container visible state');
$assert(! str_contains($artifactNavContainerStaticCss, '.desktop-nav.wp-block-navigation'), 'artifact static CSS leaves desktop navigation display rules under their authored responsive cascade');
$assert(! str_contains($artifactNavContainerStaticCss, '.drawer-panel.wp-block-navigation'), 'artifact navigation container replay does not rewrite unrelated drawer selectors');
$assert(! str_contains($artifactNavContainerStaticCss, '.collapsed-nav.wp-block-navigation>ul'), 'artifact navigation container replay does not rewrite descendant targets as containers');
$assert(str_contains($artifactNavContainerStaticCss, 'body .site-header { opacity:1!important }'), 'artifact static CSS materializes a stable root class added by source startup code');
$artifactNavContainerRepair = $artifactNavContainerCss['source_reports']['compiled_site']['visual_repair'] ?? array();
$artifactNavContainerRepairCss = (string) ($artifactNavContainerRepair['css'] ?? '');
$assert(str_contains($artifactNavContainerRepairCss, '.collapsed-nav.wp-block-navigation { display:none;position:absolute }') && str_contains($artifactNavContainerRepairCss, 'body .site-header { opacity:1!important }'), 'artifact visual repair carries navigation cascade and stable startup state projections');
$assert(str_contains((string) ($artifactNavContainerRepair['compat_css'] ?? ''), '.collapsed-nav.wp-block-navigation { display:none;position:absolute }') && str_contains((string) ($artifactNavContainerRepair['compat_css'] ?? ''), 'body .site-header { opacity:1!important }'), 'artifact visual repair exposes self-contained WordPress compatibility CSS separately from legacy repair aggregation');
$artifactNavContainerCompatAssets = array_values(array_filter($artifactNavContainerCss['source_reports']['compiled_site']['assets'] ?? array(), static fn (array $asset): bool => 'wordpress-compat' === ($asset['source'] ?? '')));
$assert(1 === count($artifactNavContainerCompatAssets) && str_contains((string) ($artifactNavContainerCompatAssets[0]['content'] ?? ''), '.collapsed-nav.wp-block-navigation { display:none;position:absolute }') && str_contains((string) ($artifactNavContainerCompatAssets[0]['content'] ?? ''), 'body .site-header { opacity:1!important }'), 'artifact compiler emits self-contained WordPress compatibility CSS as an enqueued stylesheet asset');

$artifactGeometry = $compiler->compile(
    array(
        'schema'         => ArtifactCompiler::INPUT_SCHEMA,
        'generated_html' => '<main><section class="feature" style="width:75%;max-width:72rem;aspect-ratio:16 / 9"><p>Geometry</p></section></main>',
    )
)->toArray();
$artifactGeometryAssets = is_array($artifactGeometry['assets'] ?? null) ? $artifactGeometry['assets'] : array();
$artifactGeometryCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $artifactGeometryAssets));
$artifactGeometryMarkup = (string) ($artifactGeometry['serialized_blocks'] ?? '');
$assert(str_contains($artifactGeometryMarkup, 'be-inline-geometry-'), 'artifact compiler serializes geometry carrier classes into primary block output', $artifactGeometryMarkup);
$assert(str_contains($artifactGeometryCss, 'width:75%') && str_contains($artifactGeometryCss, 'max-width:72rem') && str_contains($artifactGeometryCss, 'aspect-ratio:16 / 9'), 'artifact compiler exposes carrier CSS in primary assets', $artifactGeometryCss);
$artifactPlanCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $artifactGeometry['source_reports']['wordpress_site_plan']['assets'] ?? array()));
$assert(str_contains($artifactPlanCss, 'width:75%') && str_contains($artifactPlanCss, 'max-width:72rem'), 'canonical plan carries the primary geometry asset');

$artifactGeometryCascade = $compiler->compile(
    array(
        'entry' => 'index.html',
        'files' => array(
            'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><p id="target" style="width:30rem">Cascade</p></main></body></html>',
            'site.css' => '#target{width:12rem}.authored-important{width:8rem!important}',
        ),
    )
)->toArray();
$geometryAssetIndex = null;
$authorAssetIndex = null;
foreach (($artifactGeometryCascade['assets'] ?? array()) as $index => $asset) {
    if (str_contains((string) ($asset['content'] ?? ''), '.be-inline-geometry-')) {
        $geometryAssetIndex = $index;
    }
    if ('site.css' === ($asset['path'] ?? '')) {
        $authorAssetIndex = $index;
    }
}
$assert(is_int($geometryAssetIndex) && is_int($authorAssetIndex) && $geometryAssetIndex < $authorAssetIndex, 'artifact compiler orders geometry carriers before authored CSS to preserve !important cascade');

$artifactInlineSvg = $compiler->compile(
    array(
        'schema'         => ArtifactCompiler::INPUT_SCHEMA,
        'generated_html' => '<svg role="img" aria-label="Inline logo" viewBox="0 0 12 12"><title>Inline logo</title><path d="M0 0h12v12H0z"></path></svg>',
    )
)->toArray();
$artifactInlineSvgAssets = $artifactInlineSvg['source_reports']['wordpress_site_plan']['assets'] ?? array();
$artifactInlineSvgImageAssets = array_values(array_filter($artifactInlineSvgAssets, static fn (array $asset): bool => 'svg' === ($asset['kind'] ?? '')));
$assert('core/image' === ($artifactInlineSvg['blocks'][0]['blockName'] ?? ''), 'artifact safe passive inline SVG is represented as native core/image');
$assert(1 === count($artifactInlineSvgImageAssets), 'artifact safe inline SVG is externalized to one generated .svg image asset');
$assert(str_contains((string) ($artifactInlineSvgImageAssets[0]['content'] ?? ''), 'aria-label="Inline logo"'), 'artifact inline SVG asset preserves sanitized SVG content');
$assert('importer_owned' === ($artifactInlineSvgImageAssets[0]['source_role'] ?? '') && true === ($artifactInlineSvgImageAssets[0]['pipeline_sanitized'] ?? null), 'artifact materialization plan preserves generated SVG ownership and sanitization provenance');
$assert(str_contains((string) ($artifactInlineSvg['serialized_blocks'] ?? ''), 'assets/materialized-svg/'), 'artifact safe inline SVG serializes a materialized image URL');

$artifactNonEntryInlineSvg = $compiler->compile(
    array(
        'entry' => 'index.html',
        'files' => array(
            'index.html' => '<main><h1>Home</h1></main>',
            'about.html' => '<main><svg role="img" aria-label="About icon" viewBox="0 0 8 8"><title>About icon</title><circle cx="4" cy="4" r="3"></circle></svg></main>',
        ),
    )
)->toArray();
$artifactNonEntryInlineSvgPage = $artifactNonEntryInlineSvg['source_reports']['wordpress_site_plan']['pages'][1] ?? array();
$artifactNonEntryInlineSvgAssets = $artifactNonEntryInlineSvg['source_reports']['wordpress_site_plan']['assets'] ?? array();
$artifactNonEntryInlineSvgImageAssets = array_values(array_filter($artifactNonEntryInlineSvgAssets, static fn (array $asset): bool => 'svg' === ($asset['kind'] ?? '')));
$assert(str_contains((string) ($artifactNonEntryInlineSvgPage['canonical_block_markup'] ?? ''), '<!-- wp:image'), 'non-entry artifact simple icon SVG is represented as native core/image, not a dynamic core/icon');
$assert(str_contains((string) ($artifactNonEntryInlineSvgImageAssets[0]['content'] ?? ''), 'aria-label="About icon"') && str_contains((string) ($artifactNonEntryInlineSvgImageAssets[0]['content'] ?? ''), 'viewBox="0 0 8 8"'), 'non-entry artifact faithful SVG preserves its accessible label and correct-case viewBox in the generated asset');
$assert(1 === count(array_filter($artifactNonEntryInlineSvgAssets, static fn (array $asset): bool => 'svg' === ($asset['kind'] ?? ''))), 'non-entry artifact simple icon SVG materializes one generated image asset');

$artifactInlineScript = $compiler->compile(
    array(
        'schema'         => ArtifactCompiler::INPUT_SCHEMA,
        'generated_html' => '<!doctype html><html><head><script type="application/ld+json">{"name":"metadata"}</script></head><body><main><h1>Cafe</h1></main><script defer>document.documentElement.classList.add("hydrated");</script></body></html>',
    )
)->toArray();
$artifactInlineScriptAssets = $artifactInlineScript['source_reports']['wordpress_site_plan']['assets'] ?? array();
$artifactInlineScriptAsset = array_values(array_filter($artifactInlineScriptAssets, static fn (array $asset): bool => 'inline-script' === ($asset['source'] ?? '')))[0] ?? array();
$assert('js' === ($artifactInlineScriptAsset['kind'] ?? ''), 'artifact inline executable script becomes a JS materialization asset');
$assert('script' === ($artifactInlineScriptAsset['role'] ?? ''), 'artifact inline executable script asset has script role');
$assert('behavior' === ($artifactInlineScriptAsset['intent'] ?? ''), 'artifact inline executable script asset has behavior intent');
$assert('body' === ($artifactInlineScriptAsset['placement'] ?? ''), 'artifact inline executable script placement is preserved');
$assert(true === ($artifactInlineScriptAsset['defer'] ?? false), 'artifact inline executable script defer metadata is preserved');
$assert('index.inline-2.js' === ($artifactInlineScriptAsset['source_path'] ?? ''), 'artifact inline executable script path is stable and indexed by source script position');
$assert('script:nth-of-type(2)' === ($artifactInlineScriptAsset['selector'] ?? ''), 'artifact inline executable script selector is preserved');
$assert(str_contains((string) ($artifactInlineScriptAsset['content'] ?? ''), 'classList.add'), 'artifact inline executable script content is preserved');
$assert(in_array('index.inline-2.js', array_column($artifactInlineScript['source_reports']['wordpress_site_plan']['assets'] ?? array(), 'source_path'), true), 'artifact inline executable script is exposed as a canonical plan asset');
$assert(! str_contains((string) ($artifactInlineScript['serialized_blocks'] ?? ''), '<!-- wp:html'), 'artifact materialized inline script does not become a core/html fallback block');
$assert(! str_contains((string) ($artifactInlineScript['serialized_blocks'] ?? ''), 'classList.add'), 'artifact materialized inline script body is removed from serialized block content');

$assert(1 === count($simple['source_reports']['wordpress_site_plan']['pages'] ?? array()), 'canonical plan counts pages');
$assert('index' === ($simple['source_reports']['wordpress_site_plan']['pages'][0]['slug'] ?? ''), 'canonical plan exposes page slug');
$assert(str_contains((string) ($simple['source_reports']['wordpress_site_plan']['pages'][0]['canonical_block_markup'] ?? ''), '<!-- wp:'), 'canonical plan exposes converted block markup');

$formatResult = ( new FormatBridge() )->convertResult('# Format report', 'markdown', 'blocks')->toArray();
TransformerResult::assertCanonicalEnvelope($formatResult);
$assert('blocks-engine/php-transformer/conversion-report/v1' === ($formatResult['source_reports']['conversion_report']['schema'] ?? ''), 'format bridge exposes canonical conversion report');

$staticSite = $compiler->compile(
    array(
        'entrypoint' => 'index.html',
        'files'      => array(
            'index.html' => '<main><img src="assets/logo.png" alt="Logo"></main>',
            'parts/header.html' => '<header><nav><a href="/">Home</a><a href="/about.html">About</a></nav><img src="assets/logo.png" alt="Logo"></header>',
            'about.html' => '<main><h1>About</h1></main>',
            'assets/logo.png' => array(
                'content_base64' => base64_encode("\x89PNG\r\n\x1a\n"),
                'mime_type'      => 'image/png',
            ),
            'visual-repair.css' => '.wp-site-blocks{min-height:100vh}',
        ),
    )
)->toArray();
$staticPlan = $staticSite['source_reports']['wordpress_site_plan'] ?? array();
$aboutCompiledPage = null;
foreach ( $staticSite['source_reports']['compiled_site']['pages'] ?? array() as $compiledPage ) {
    if ( 'about.html' === ($compiledPage['source_path'] ?? '') ) {
        $aboutCompiledPage = $compiledPage;
    }
}
$aboutPlanPage = null;
foreach ( $staticPlan['pages'] ?? array() as $planPage ) {
    if ( 'about.html' === ($planPage['source_path'] ?? '') ) {
        $aboutPlanPage = $planPage;
    }
}
$assert(str_contains((string) ($aboutCompiledPage['block_markup'] ?? ''), '<!-- wp:heading'), 'compiled site transforms non-entry HTML pages into semantic block markup');
$assert(! str_contains((string) ($aboutCompiledPage['block_markup'] ?? ''), '<!-- wp:html -->'), 'compiled site avoids full-document core/html wrappers for transformer-safe non-entry HTML pages');
$assert(str_contains((string) ($aboutPlanPage['canonical_block_markup'] ?? ''), '<!-- wp:heading'), 'canonical plan preserves transformed non-entry HTML page markup');
$assert('parts/header.html' === ($staticPlan['template_parts'][0]['source_path'] ?? ''), 'canonical plan exposes template parts');
$assert('theme_template_part' === ($staticPlan['writes'][2]['kind'] ?? '') || !empty($staticPlan['template_parts']), 'canonical plan exposes template part writes');
$assert(str_contains((string) ($staticPlan['visual_repair']['css'] ?? ''), 'min-height:100vh'), 'canonical plan exposes visual repair CSS');
$assert('/' === ($staticPlan['routes'][0]['target_path'] ?? ''), 'canonical plan exposes entry route path');
$assert('/about' === ($staticPlan['routes'][1]['target_path'] ?? ''), 'canonical plan exposes document route path');
$assert(empty(array_filter($staticPlan['assets'] ?? array(), static fn (array $asset): bool => 'html' === ($asset['kind'] ?? '') || str_ends_with((string) ($asset['source_path'] ?? ''), '.html'))), 'canonical plan omits HTML documents from asset rows');
$assert('navigation_link' === ($staticPlan['navigation_links'][0]['kind'] ?? ''), 'canonical plan exposes generic navigation link rows');
$assert('About' === ($staticPlan['navigation_links'][1]['label'] ?? ''), 'canonical plan exposes navigation link labels');
$assert('/about' === ($staticPlan['navigation_links'][1]['target_path'] ?? ''), 'canonical plan exposes navigation target paths');
$assert('menu' === ($staticPlan['menus'][0]['kind'] ?? ''), 'canonical plan exposes generic menu rows');
$assert(2 === ($staticPlan['menus'][0]['items'] ?? null), 'canonical plan counts menu items');
$staticSummary = $staticSite['source_reports']['conversion_report']['source_summary'] ?? array();
$assert(count($staticPlan['pages'] ?? array()) === ($staticSummary['page_count'] ?? null), 'conversion report page count matches canonical plan');
$assert(count($staticPlan['assets'] ?? array()) === ($staticSummary['asset_count'] ?? null), 'conversion report asset count matches canonical plan');
$assert(count($staticPlan['routes'] ?? array()) === ($staticSummary['route_count'] ?? null), 'conversion report route count matches canonical plan');
$assert(count($staticPlan['navigation_links'] ?? array()) === ($staticSummary['navigation_link_count'] ?? null), 'conversion report navigation link count matches canonical plan');
$assert(count($staticPlan['menus'] ?? array()) === ($staticSummary['menu_count'] ?? null), 'conversion report menu count matches canonical plan');

$footerShellSite = $compiler->compile(
    array(
        'entry' => 'index.html',
        'files' => array(
            'index.html' => '<!doctype html><html><body><main><h1>Home</h1><p>Body copy</p></main><footer class="site-footer"><nav><a href="/privacy">Privacy</a></nav><p>Global footer copy</p></footer></body></html>',
            'about.html' => '<!doctype html><html><body><main><article><h1>About</h1><footer class="article-footer"><p>Article byline footer</p></footer></article></main><footer class="site-footer"><p>Global footer copy</p></footer></body></html>',
            'parts/footer.html' => '<footer class="site-footer"><nav><a href="/privacy">Privacy</a></nav><p>Global footer copy</p></footer>',
        ),
    )
)->toArray();
$footerShellPages = $footerShellSite['source_reports']['compiled_site']['pages'] ?? array();
$footerShellTemplateParts = $footerShellSite['source_reports']['compiled_site']['template_parts'] ?? array();
$footerShellIndexPage = array_values(array_filter($footerShellPages, static fn (array $page): bool => 'index.html' === ($page['source_path'] ?? '')))[0] ?? array();
$footerShellAboutPage = array_values(array_filter($footerShellPages, static fn (array $page): bool => 'about.html' === ($page['source_path'] ?? '')))[0] ?? array();
$footerShellPart = $footerShellTemplateParts[0] ?? array();
$assert(! str_contains((string) ($footerShellIndexPage['block_markup'] ?? ''), 'Global footer copy'), 'compiled site removes global footer shell from page body when a footer template part exists');
$assert(str_contains((string) ($footerShellPart['block_markup'] ?? ''), 'Global footer copy'), 'compiled site preserves global footer copy in the footer template part');
$assert(str_contains((string) ($footerShellAboutPage['block_markup'] ?? ''), 'Article byline footer'), 'compiled site preserves page-local article footer content while pruning global footer shell');
$assert(! str_contains((string) ($footerShellAboutPage['block_markup'] ?? ''), 'Global footer copy'), 'compiled site does not duplicate global footer shell on secondary page bodies');

$canonicalShellSite = $compiler->compile(
    array(
        'entrypoint' => 'index.html',
        'files' => array(
            'index.html' => '<style>header{position:sticky;background:#fff}footer{padding:24px}.site-nav{display:flex}.nav-links{display:flex}.btn{display:inline-flex;padding:9px 20px;background:#e8a020}</style><header><nav class="site-nav"><a class="nav-logo" href="/">Brand</a><ul class="nav-links" role="list"><li><a href="/product">Product</a></li></ul><a class="btn nav-cta" href="/start">Get started</a></nav></header><main><h1>Home</h1></main><footer><p>Global footer</p></footer>',
        ),
    )
)->toArray();
$canonicalShellPlan = $canonicalShellSite['source_reports']['wordpress_site_plan'] ?? array();
$canonicalHeaderPart = array_values(array_filter($canonicalShellPlan['template_parts'] ?? array(), static fn (array $part): bool => 'header' === ($part['area'] ?? '')))[0] ?? array();
$canonicalFooterPart = array_values(array_filter($canonicalShellPlan['template_parts'] ?? array(), static fn (array $part): bool => 'footer' === ($part['area'] ?? '')))[0] ?? array();
$canonicalEntryPage = array_values(array_filter($canonicalShellPlan['pages'] ?? array(), static fn (array $page): bool => 'index.html' === ($page['source_path'] ?? '')))[0] ?? array();
$assert(! str_contains((string) ($canonicalEntryPage['canonical_block_markup'] ?? ''), 'Get started') && str_contains((string) ($canonicalHeaderPart['canonical_block_markup'] ?? ''), 'Get started'), 'canonical entry header is projected only to its shell part, without duplicate post-content chrome');
$assert(str_contains((string) ($canonicalHeaderPart['canonical_block_markup'] ?? ''), '<!-- wp:') && ! str_contains((string) ($canonicalEntryPage['canonical_block_markup'] ?? ''), '<!-- wp:custom/layout-shell'), 'canonical shell extraction recognizes a semantic outer wrapper projected through a layout shell');
$assert(str_contains((string) ($canonicalFooterPart['canonical_block_markup'] ?? ''), 'Global footer'), 'canonical entry footer part preserves global footer content');
$assert(! str_contains((string) ($canonicalHeaderPart['canonical_block_markup'] ?? ''), '<header') && ! str_contains((string) ($canonicalFooterPart['canonical_block_markup'] ?? ''), '<footer'), 'canonical shell parts rely on their semantic template-part references instead of nesting duplicate landmarks');
$assert(2 === count(array_filter($canonicalShellPlan['writes'] ?? array(), static fn (array $write): bool => 'theme_template_part' === ($write['kind'] ?? ''))), 'WordPress site plan exposes canonical entry header and footer writes');

$runtimeDependencySite = $compiler->compile(
    array(
        'entrypoint' => 'index.html',
        'files'      => array(
            'index.html' => '<main><canvas id="canvas" class="stage"></canvas><canvas id="unused-canvas"></canvas><div id="status-container"><h2>Status</h2><p>Ready</p></div><script src="js/script.js"></script><script src="js/rum.js"></script><script id="netlify-rum-container" src="js/self-rum.js" data-netlify-cwv-token="token"></script></main>',
            'js/script.js' => 'const canvas = document.getElementById("canvas"); canvas.getContext("2d"); const stage = document.querySelector(".stage"); stage.getContext("2d"); const status = document.querySelector("#status-container"); status.addEventListener("click", function () {});',
            'js/rum.js' => 'document.querySelector("#netlify-rum-target");',
            'js/self-rum.js' => 'document.querySelector("#netlify-rum-container")?.getAttribute("data-netlify-cwv-token");',
        ),
    )
)->toArray();
$runtimeDependencyReport = $runtimeDependencySite['source_reports']['runtime_dependency_parity'] ?? array();
$runtimeDependencyConversionReport = $runtimeDependencySite['source_reports']['conversion_report']['runtime_dependency_parity'] ?? array();
$runtimeFindings = $runtimeDependencyReport['findings'] ?? array();
$canvasFinding = null;
$rumFinding = null;
$selfRumFinding = null;
foreach ( $runtimeFindings as $finding ) {
    if ( '#canvas' === ($finding['selector'] ?? '') ) {
        $canvasFinding = $finding;
    }
    if ( '#netlify-rum-target' === ($finding['selector'] ?? '') ) {
        $rumFinding = $finding;
    }
    if ( '#netlify-rum-container' === ($finding['selector'] ?? '') ) {
        $selfRumFinding = $finding;
    }
}
$canvasDependency = null;
$stageDependency = null;
$statusDependency = null;
$selfRumDependency = null;
foreach ( $runtimeDependencyReport['dependencies'] ?? array() as $dependency ) {
    if ( '#canvas' === ($dependency['selector'] ?? '') ) {
        $canvasDependency = $dependency;
    }
    if ( '.stage' === ($dependency['selector'] ?? '') ) {
        $stageDependency = $dependency;
    }
    if ( '#status-container' === ($dependency['selector'] ?? '') ) {
        $statusDependency = $dependency;
    }
    if ( '#netlify-rum-container' === ($dependency['selector'] ?? '') ) {
        $selfRumDependency = $dependency;
    }
}
$runtimeDependencyMarkup = (string) ($runtimeDependencySite['serialized_blocks'] ?? '');
$assert('blocks-engine/php-transformer/runtime-dependency-parity/v1' === ($runtimeDependencyReport['schema'] ?? ''), 'runtime dependency parity report exposes schema');
$assert($runtimeDependencyReport === $runtimeDependencyConversionReport, 'conversion report projects runtime dependency parity');
$assert(null === $canvasFinding, 'runtime dependency parity does not report preserved canvas DOM target as missing');
$assert(null !== $canvasDependency, 'runtime dependency parity records canvas id dependency');
$assert('index.html' === ($canvasDependency['source_path'] ?? ''), 'runtime dependency parity records source path for canvas DOM target');
$assert('canvas' === ($canvasDependency['target_id'] ?? ''), 'runtime dependency parity records canvas target id');
$assert('canvas' === ($canvasDependency['target_kind'] ?? ''), 'runtime dependency parity identifies canvas source target kind');
$assert(true === ($canvasDependency['canvas_api'] ?? null), 'runtime dependency parity flags canvas 2d API usage');
$assert(true === ($canvasDependency['generated_present'] ?? null), 'runtime dependency parity passes preserved canvas id target');
$assert(null !== $stageDependency, 'runtime dependency parity records canvas class querySelector dependency');
$assert(true === ($stageDependency['generated_present'] ?? null), 'runtime dependency parity passes preserved canvas class target');
$assert(str_contains($runtimeDependencyMarkup, '<canvas id="canvas" class="stage"></canvas>'), 'artifact compiler emits referenced canvas runtime target markup');
$assert(! str_contains($runtimeDependencyMarkup, 'unused-canvas'), 'artifact compiler does not preserve unreferenced canvas markup');
$runtimeDependencyIslands = $runtimeDependencySite['source_reports']['runtime_islands'] ?? array();
$runtimeDependencyIslandsByKind = array();
foreach ( $runtimeDependencyIslands as $island ) {
    $runtimeDependencyIslandsByKind[$island['kind'] ?? ''][] = $island;
}
$assert(1 === count($runtimeDependencyIslandsByKind['canvas'] ?? array()), 'artifact compiler reports the preserved canvas as a runtime island');
$assert(1 === count($runtimeDependencyIslandsByKind['dom'] ?? array()), 'artifact compiler reports the runtime DOM target as a runtime island');
$runtimeDependencyCanvasIsland = $runtimeDependencyIslandsByKind['canvas'][0] ?? array();
$runtimeDependencyDomIsland = $runtimeDependencyIslandsByKind['dom'][0] ?? array();
$assert('canvas' === ($runtimeDependencyCanvasIsland['kind'] ?? ''), 'artifact runtime island identifies canvas kind');
$assert('#canvas' === ($runtimeDependencyCanvasIsland['selector'] ?? ''), 'artifact runtime island exposes canvas selector');
$assert('stage' === ($runtimeDependencyCanvasIsland['attributes']['class'] ?? ''), 'artifact runtime island exposes canvas class for runtime dependency parity');
$assert(str_contains((string) ($runtimeDependencyCanvasIsland['source_snippet'] ?? ''), '<canvas id="canvas" class="stage"></canvas>'), 'artifact runtime island exposes canvas source snippet');
$assert(! empty($runtimeDependencyCanvasIsland['required_scripts'] ?? array()), 'artifact runtime island exposes required script metadata');
$assert('#status-container' === ($runtimeDependencyDomIsland['selector'] ?? ''), 'artifact DOM runtime island exposes selector');
$assert('runtime_dom_target' === ($runtimeDependencyDomIsland['preservation_reason'] ?? ''), 'artifact DOM runtime island exposes preservation reason');
$assert($runtimeDependencyIslands === ($runtimeDependencySite['source_reports']['conversion_report']['runtime_islands'] ?? array()), 'artifact conversion report projects runtime islands');
$assert(null !== $statusDependency, 'runtime dependency parity records preserved status container dependency');
$assert('index.html' === ($statusDependency['source_path'] ?? ''), 'runtime dependency parity records source path for preserved DOM dependency');
$assert(true === ($statusDependency['generated_present'] ?? null), 'runtime dependency parity passes preserved div id target');
$assert(false === ($statusDependency['canvas_api'] ?? null), 'runtime dependency parity does not mark non-canvas DOM targets as canvas API dependencies');
$assert(! empty($statusDependency['events'] ?? array()), 'runtime dependency parity records simple addEventListener usage');
$assert('info' === ($rumFinding['severity'] ?? ''), 'telemetry-like runtime dependency misses are info severity');
$assert(null === $selfRumFinding, 'telemetry script self-target is not reported as a missing block DOM target');
$assert(null !== $selfRumDependency, 'runtime dependency parity records telemetry script self-target dependency');
$assert('script' === ($selfRumDependency['target_kind'] ?? ''), 'runtime dependency parity identifies telemetry script self-target kind');

$firstPartyRuntimeContracts = $compiler->compile(
    array(
        'entrypoint' => 'index.html',
        'files'      => array(
            'index.html' => '<main><section class="panel"><p id="clock" class="clock">0 <span id="score">score</span></p><p data-counter="clock">Counter</p><p>Editable sibling</p></section><script src="js/clock.js"></script></main>',
            'js/clock.js' => 'document.getElementById("clock").textContent = "1"; document.querySelector(".clock").classList.add("ready"); document.querySelector("[data-counter]").textContent = "2"; document.querySelector("#score").closest(".panel").classList.add("ready");',
        ),
    )
)->toArray();
$firstPartyRuntimeMarkup = (string) ($firstPartyRuntimeContracts['serialized_blocks'] ?? '');
$firstPartyRuntimeDependencies = array_column($firstPartyRuntimeContracts['source_reports']['runtime_dependency_parity']['dependencies'] ?? array(), null, 'selector');
$firstPartyRuntimeDiagnostics = array_values(array_filter($firstPartyRuntimeContracts['diagnostics'] ?? array(), static fn (array $diagnostic): bool => 'runtime_dom_contract_preserved' === ($diagnostic['code'] ?? '')));
$firstPartyRuntimeDiagnosticSelectors = array_column($firstPartyRuntimeDiagnostics, 'selector');
$firstPartyRuntimeFallbacks = array_values(array_filter($firstPartyRuntimeContracts['diagnostics'] ?? array(), static fn (array $diagnostic): bool => 'runtime_dom_contract_fallback' === ($diagnostic['code'] ?? '')));
$assert('pass' === ($firstPartyRuntimeContracts['source_reports']['runtime_dependency_parity']['status'] ?? '') && array() === ($firstPartyRuntimeContracts['source_reports']['runtime_dependency_parity']['findings'] ?? array()), 'first-party ID, class, closest-parent, and data-attribute runtime selectors pass only when their generated contracts are present');
foreach (array('#clock', '.clock', '.panel', '[data-counter]', '#score') as $selector) {
    $assert(true === ($firstPartyRuntimeDependencies[$selector]['generated_present'] ?? null), 'first-party runtime dependency parity preserves ' . $selector);
}
$assert(in_array('#clock', $firstPartyRuntimeDiagnosticSelectors, true) && in_array('.clock', $firstPartyRuntimeDiagnosticSelectors, true) && in_array('#score', $firstPartyRuntimeDiagnosticSelectors, true) && '[data-counter]' === ($firstPartyRuntimeFallbacks[0]['selector'] ?? ''), 'per-target diagnostics distinguish native ID/class/inline preservation from data-attribute fallback');
$assert(str_contains($firstPartyRuntimeMarkup, '<section class="wp-block-group panel">') && str_contains($firstPartyRuntimeMarkup, '<p id="clock" class="clock">0 <span id="score">score</span></p>') && str_contains($firstPartyRuntimeMarkup, '<!-- wp:html --><p data-counter="clock">Counter</p><!-- /wp:html -->') && str_contains($firstPartyRuntimeMarkup, '<p>Editable sibling</p>') && ! str_contains($firstPartyRuntimeMarkup, '<mark data-counter='), 'runtime data attributes retain their source p tag in one bounded island while native parent and siblings remain editable', $firstPartyRuntimeMarkup);
$missingRuntimeContract = (new \Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDependencyParityReport())->fromArtifact(
    array(array('path' => 'js/clock.js', 'kind' => 'js', 'content' => 'document.querySelector("[data-counter]").textContent = "2";')),
    '<main><p data-counter="clock">0</p></main>',
    '<!-- wp:paragraph --><p>0</p><!-- /wp:paragraph -->',
    'index.html'
);
$assert('warning' === ($missingRuntimeContract['status'] ?? '') && 'runtime_dependency_target_missing' === ($missingRuntimeContract['findings'][0]['code'] ?? ''), 'runtime dependency parity fails closed when a required source selector is absent from generated markup');
$missingRuntimeCompile = $compiler->compile(
    array(
        'entrypoint' => 'index.html',
        'files' => array(
            'index.html' => '<main><style data-counter="clock">.clock{color:red}</style><p>Editable</p><script src="js/clock.js"></script></main>',
            'js/clock.js' => 'document.querySelector("[data-counter]").textContent = "2";',
        ),
    )
)->toArray();
$missingRuntimeCompileDiagnostics = array_values(array_filter($missingRuntimeCompile['diagnostics'] ?? array(), static fn (array $diagnostic): bool => 'runtime_dependency_contract_failed' === ($diagnostic['code'] ?? '')));
$assert('failed' === ($missingRuntimeCompile['status'] ?? '') && '[data-counter]' === ($missingRuntimeCompileDiagnostics[0]['context']['selector'] ?? ''), 'ArtifactCompiler promotes required runtime selector parity failures to deterministic top-level errors');

$expandedRuntimeTargetsSite = $compiler->compile(
    array(
        'entrypoint' => 'index.html',
        'files'      => array(
            'index.html' => '<main><section id="app-root"><canvas class="preview" aria-label="Preview"></canvas><svg class="dial" viewBox="0 0 10 10" data-tool><circle cx="5" cy="5" r="4"></circle></svg><div data-tool></div><div id="mounted-app"></div></section><script src="js/runtime.js"></script></main>',
            'js/runtime.js' => 'const app = document.getElementById("app-root"); const scopedCanvas = app.querySelector("canvas"); scopedCanvas.getContext("2d"); document.querySelector("canvas.preview").getContext("2d"); const svgRoot = app.querySelector("svg"); svgRoot.addEventListener("pointerdown", function () {}); svgRoot.addEventListener("wheel", function () {}); document.querySelector("[data-tool]").addEventListener("click", function () {}); const mounted = document.getElementById("mounted-app"); mounted.appendChild(document.createElementNS("http://www.w3.org/2000/svg", "svg"));',
        ),
    )
)->toArray();
$expandedRuntimeReport = $expandedRuntimeTargetsSite['source_reports']['runtime_dependency_parity'] ?? array();
$expandedRuntimeDependencies = array();
foreach ( $expandedRuntimeReport['dependencies'] ?? array() as $dependency ) {
    $expandedRuntimeDependencies[$dependency['selector'] ?? ''] = $dependency;
}
$expandedRuntimeMarkup = (string) ($expandedRuntimeTargetsSite['serialized_blocks'] ?? '');
$assert('pass' === ($expandedRuntimeReport['status'] ?? ''), 'expanded runtime target selectors pass dependency parity');
foreach ( array('canvas.preview', 'canvas', 'svg', '[data-tool]', '#mounted-app') as $selector ) {
    $assert(true === ($expandedRuntimeDependencies[$selector]['generated_present'] ?? null), 'expanded runtime dependency preserves ' . $selector);
}
$assert(true === ($expandedRuntimeDependencies['canvas.preview']['canvas_api'] ?? null), 'compound canvas selector records canvas API usage');
$assert(str_contains($expandedRuntimeMarkup, '<canvas class="preview" aria-label="Preview"></canvas>'), 'compound canvas selector preserves canvas markup');
$assert(str_contains($expandedRuntimeMarkup, 'data-tool'), 'data attribute runtime selector remains addressable in generated markup');
$assert(true === ($expandedRuntimeDependencies['[data-tool]']['source_present'] ?? null), 'data attribute runtime selector is recorded as present in source markup');
$assert(str_contains($expandedRuntimeMarkup, 'mounted-app'), 'app root receiving appended children remains addressable in generated markup');
$assert(array() === ($expandedRuntimeReport['findings'] ?? array()), 'expanded runtime target selectors do not emit missing-target findings');

$runtimeTagSelectorSite = $compiler->compile(
    array(
        'entrypoint' => 'index.html',
        'files'      => array(
            'index.html' => '<main><button type="button">Play</button><ul><li>Kick</li><li>Snare</li></ul><script src="js/runtime.js"></script></main>',
            'js/runtime.js' => 'document.querySelector("button").addEventListener("click", function () {}); document.querySelector("ul").classList.add("ready"); document.querySelector("li").addEventListener("pointerdown", function () {});',
        ),
    )
)->toArray();
$runtimeTagSelectorReport = $runtimeTagSelectorSite['source_reports']['runtime_dependency_parity'] ?? array();
$runtimeTagSelectorDependencies = array();
foreach ( $runtimeTagSelectorReport['dependencies'] ?? array() as $dependency ) {
    $runtimeTagSelectorDependencies[$dependency['selector'] ?? ''] = $dependency;
}
$runtimeTagSelectorMarkup = (string) ($runtimeTagSelectorSite['serialized_blocks'] ?? '');
$assert('pass' === ($runtimeTagSelectorReport['status'] ?? ''), 'tag-only runtime selectors pass dependency parity');
foreach ( array( 'button', 'ul', 'li' ) as $selector ) {
    $assert(true === ($runtimeTagSelectorDependencies[$selector]['generated_present'] ?? null), 'runtime dependency parity preserves tag selector ' . $selector);
}
$assert(str_contains($runtimeTagSelectorMarkup, '<button type="button">Play</button>'), 'runtime-targeted button keeps native button markup');

$nestedSelfRumSite = $compiler->compile(
    array(
        'entrypoint' => 'website/index.html',
        'files'      => array(
            'website/index.html' => '<main><h1>Telemetry</h1><script id="netlify-rum-container" src="js/rum.js" data-netlify-cwv-token="token"></script></main>',
            'website/js/rum.js' => 'document.querySelector("#netlify-rum-container")?.getAttribute("data-netlify-cwv-token");',
        ),
    )
)->toArray();
$nestedSelfRumFindings = $nestedSelfRumSite['source_reports']['runtime_dependency_parity']['findings'] ?? array();
$assert(
    array() === array_values(array_filter($nestedSelfRumFindings, static fn (array $finding): bool => '#netlify-rum-container' === ($finding['selector'] ?? ''))),
    'nested telemetry script self-target is not reported as a missing block DOM target'
);

$sharedScriptSite = $compiler->compile(
    array(
        'entrypoint' => 'index.html',
        'files'      => array(
            'index.html' => '<main><h1>Home</h1><script src="js/site.js"></script></main>',
            'js/site.js' => 'document.querySelectorAll(".only-on-shop").forEach(function (button) { button.addEventListener("click", function () {}); });',
        ),
    )
)->toArray();
$sharedScriptReport = $sharedScriptSite['source_reports']['runtime_dependency_parity'] ?? array();
$sharedScriptDependencies = $sharedScriptReport['dependencies'] ?? array();
$sharedScriptFindings = $sharedScriptReport['findings'] ?? array();
$sharedScriptDependency = array_values(array_filter($sharedScriptDependencies, static fn (array $dependency): bool => '.only-on-shop' === ($dependency['selector'] ?? '')))[0] ?? null;
$assert(
    null !== $sharedScriptDependency,
    'runtime dependency parity records shared-script selectors absent from the entry source'
);
$assert(
    array() === array_values(array_filter($sharedScriptFindings, static fn (array $finding): bool => '.only-on-shop' === ($finding['selector'] ?? ''))),
    'runtime dependency parity does not fail entry output for selectors absent from that entry source'
);

$sharedDrumScriptSite = $compiler->compile(
    array(
        'entrypoint' => 'index.html',
        'files'      => array(
            'index.html'     => '<main><h1>Drum machine</h1><script src="js/site.js"></script></main>',
            'patterns.html'  => '<main><button data-voice-demo="kick">Kick</button><button data-groove="classic">Classic</button><script src="js/site.js"></script></main>',
            'js/site.js'     => 'document.querySelectorAll("[data-groove], [data-voice-demo]"); document.querySelectorAll(".is-playing").forEach(function (button) { button.classList.remove("is-playing"); });',
        ),
    )
)->toArray();
$sharedDrumScriptReport = $sharedDrumScriptSite['source_reports']['runtime_dependency_parity'] ?? array();
$sharedDrumDependencies = $sharedDrumScriptReport['dependencies'] ?? array();
$sharedDrumDependency = array_values(array_filter($sharedDrumDependencies, static fn (array $dependency): bool => '[data-voice-demo]' === ($dependency['selector'] ?? '')))[0] ?? null;
$assert(
    null !== $sharedDrumDependency,
    'runtime dependency parity records shared data-attribute selectors absent from the entry source'
);
$assert(
    'first_party' === ($sharedDrumDependency['script_kind'] ?? ''),
    'runtime dependency parity does not classify drum scripts as RUM telemetry'
);
$assert(
    array() === array_values(array_filter($sharedDrumScriptReport['findings'] ?? array(), static fn (array $finding): bool => in_array($finding['selector'] ?? '', array('.is-playing', '[data-voice-demo]', '[data-groove]'), true))),
    'runtime dependency parity does not fail entry output for shared drum script selectors absent from that entry source'
);

$staticJsonRuntimeSite = $compiler->compile(
    array(
        'entrypoint' => 'index.html',
        'files' => array(
            'index.html' => '<main><script id="config" type="application/json">{"message":"Ready"}</script><script src="js/app.js"></script><h1>Home</h1></main>',
            'js/app.js' => 'JSON.parse(document.getElementById("config").textContent).message;',
        ),
    )
)->toArray();
$staticJsonRuntimeMarkup = (string) ($staticJsonRuntimeSite['serialized_blocks'] ?? '');
$staticJsonRuntimeDependency = array_values(array_filter($staticJsonRuntimeSite['source_reports']['runtime_dependency_parity']['dependencies'] ?? array(), static fn (array $dependency): bool => '#config' === ($dependency['selector'] ?? '')))[0] ?? array();
$assert('pass' === ($staticJsonRuntimeSite['source_reports']['runtime_dependency_parity']['status'] ?? '') && true === ($staticJsonRuntimeDependency['generated_present'] ?? null), 'ID-addressed static JSON remains an addressable runtime target for carried first-party scripts');
$assert(str_contains($staticJsonRuntimeMarkup, '<script id="config" type="application/json">{"message":"Ready"}</script>'), 'addressable static JSON is preserved as bounded non-executable block markup');
$assert(1 === count(array_filter($staticJsonRuntimeSite['source_reports']['runtime_islands'] ?? array(), static fn (array $island): bool => 'static_script' === ($island['kind'] ?? ''))), 'addressable static JSON target is recorded as a runtime configuration island');

$companionRenderReport = (new \Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDependencyParityReport())->fromArtifact(
    array(array('path' => 'js/app.js', 'kind' => 'js', 'content' => 'document.querySelector("a[data-anchor]").addEventListener("click", function () {});')),
    '<main><a data-anchor="docs">Docs</a></main>',
    '<!-- wp:custom/companion /-->',
    'index.html',
    array(),
    array(),
    array(),
    array(),
    array(array('block_json' => array('render' => 'file:./render.php'), 'render' => '<a data-anchor="docs">Docs</a>'))
);
$companionRenderDependency = $companionRenderReport['dependencies'][0] ?? array();
$assert('pass' === ($companionRenderReport['status'] ?? '') && true === ($companionRenderDependency['generated_present'] ?? null) && 'declared_companion_render' === ($companionRenderDependency['generated_target_evidence'] ?? ''), 'declared exact companion render HTML supplies data-attribute target evidence');
$undeclaredCompanionRenderReport = (new \Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDependencyParityReport())->fromArtifact(
    array(array('path' => 'js/app.js', 'kind' => 'js', 'content' => 'document.querySelector("a[data-anchor]").addEventListener("click", function () {});')),
    '<main><a data-anchor="docs">Docs</a></main>',
    '<!-- wp:custom/companion /-->',
    'index.html',
    array(),
    array(),
    array(),
    array(),
    array(array('block_json' => array(), 'render' => '<a data-anchor="docs">Docs</a>'))
);
$assert('warning' === ($undeclaredCompanionRenderReport['status'] ?? '') && 'runtime_dependency_target_missing' === ($undeclaredCompanionRenderReport['findings'][0]['code'] ?? ''), 'undeclared companion render strings cannot suppress missing-target failures');

$hamburgerOverlaySite = $compiler->compile(
    array(
        'entrypoint' => 'index.html',
        'files'      => array(
            'index.html' => '<nav aria-label="Main"><a href="/">Home</a><button class="menu-toggle" aria-label="Toggle menu"><span></span><span></span><span></span></button></nav><ul class="drawer-menu" aria-label="Mobile navigation"><li><a href="/">Home</a></li><li><a href="/music">Music</a></li></ul><main><h1>Home</h1><script src="js/site.js"></script></main>',
            'js/site.js' => 'const menu = document.querySelector(".drawer-menu"); document.querySelector(".menu-toggle")?.addEventListener("click", function () { menu?.classList.toggle("open"); });',
        ),
    )
)->toArray();
$hamburgerOverlayReport = $hamburgerOverlaySite['source_reports']['runtime_dependency_parity'] ?? array();
$hamburgerOverlaySuperseded = $hamburgerOverlaySite['source_reports']['superseded_selectors'] ?? array();
$assert(in_array('.drawer-menu', $hamburgerOverlaySuperseded, true), 'adjacent mobile navigation overlay is recorded as superseded when its hamburger toggle is removed');
$assert('pass' === ($hamburgerOverlayReport['status'] ?? ''), 'superseded adjacent mobile navigation overlay does not fail runtime dependency parity');

$decorativeCanvasSite = $compiler->compile(
    array(
        'entrypoint' => 'index.html',
        'files'      => array(
            'index.html' => '<main><canvas id="hero-canvas" aria-hidden="true"></canvas><canvas id="lab-canvas" class="stage" aria-label="Live pattern"></canvas><script src="js/app.js"></script></main>',
            'js/app.js' => 'const lab = document.getElementById("lab-canvas"); lab.getContext("2d"); document.getElementById("hero-canvas");',
        ),
    )
)->toArray();
$decorativeCanvasMarkup = (string) ($decorativeCanvasSite['serialized_blocks'] ?? '');
$decorativeCanvasFallbacks = $decorativeCanvasSite['fallbacks'] ?? array();
$assert(str_contains($decorativeCanvasMarkup, '<canvas id="lab-canvas" class="stage" aria-label="Live pattern"></canvas>'), 'artifact compiler emits runtime canvas markup in serialized blocks');
$assert(! str_contains($decorativeCanvasMarkup, 'hero-canvas'), 'artifact compiler omits decorative canvas touched by script without canvas API usage');
$assert(array() === $decorativeCanvasFallbacks, 'artifact compiler preserves runtime canvas without fallback diagnostics');
$assert(1 === count($decorativeCanvasSite['source_reports']['runtime_islands'] ?? array()), 'decorative canvas is not over-reported as a runtime island');
$assert('#lab-canvas' === ($decorativeCanvasSite['source_reports']['runtime_islands'][0]['selector'] ?? ''), 'runtime island provenance points to the interactive canvas');
$assert(str_contains((string) ($decorativeCanvasSite['source_reports']['runtime_islands'][0]['source_snippet'] ?? ''), '<canvas id="lab-canvas" class="stage" aria-label="Live pattern"></canvas>'), 'artifact compiler preserves direct canvas API target as runtime island metadata');

$decorativeSvgSite = $compiler->compile(
    array(
        'entrypoint' => 'index.html',
        'files'      => array(
            'index.html' => '<main><svg id="brand-mark" aria-hidden="true" viewBox="0 0 10 10"><path d="M0 0h10v10z"></path></svg><script src="js/app.js"></script></main>',
            'js/app.js' => 'document.getElementById("brand-mark"); document.createElementNS("http://www.w3.org/2000/svg", "circle");',
        ),
    )
)->toArray();
$decorativeSvgReport = $decorativeSvgSite['source_reports']['runtime_dependency_parity'] ?? array();
$decorativeSvgMarkup = (string) ($decorativeSvgSite['serialized_blocks'] ?? '');
$assert(str_contains($decorativeSvgMarkup, 'brand-mark'), 'decorative SVG markup remains preserved as normal inline SVG');
$assert(array() === ($decorativeSvgReport['findings'] ?? array()), 'decorative SVG referenced without mutation/listeners is not reported as a runtime target');
$assert(array() === array_values(array_filter($decorativeSvgSite['source_reports']['runtime_islands'] ?? array(), static fn (array $island): bool => 'svg' === ($island['kind'] ?? ''))), 'decorative SVG is not over-reported as a runtime island');

$runtimeTargetContainerSite = $compiler->compile(
    array(
        'entrypoint' => 'index.html',
        'files'      => array(
            'index.html' => '<main><section class="reveal"><h2>Reveal</h2></section><header><button class="nav-toggle" aria-label="Open navigation" aria-expanded="false">Menu</button><div class="menu-shell"><nav class="primary-nav"><a href="/">Home</a></nav></div><div class="mobile-nav-overlay"><div class="mobile-nav"><nav class="drawer-nav"><a href="/">Home</a></nav></div></div></header><div class="faq-item"><h3>Question</h3><p>Answer</p></div><div class="filter-bar"><div class="button-shell"><button class="filter-btn">All</button></div><div class="filter-chips"><span>Popular</span></div></div><div class="search-shell"><input id="note-search" class="search-input" type="search" placeholder="Search notes"></div><form class="filters"><select class="js-sort-select"><option>Newest</option></select><input class="js-filter-check" type="checkbox" name="available"></form><section id="contact-form"><h2>Contact</h2></section><div id="form-success"></div><script src="js/app.js"></script></main>',
            'js/app.js' => 'document.querySelectorAll(".reveal"); document.querySelector(".nav-toggle").addEventListener("click", function () {}); const menuShell = document.querySelector(".menu-shell"); menuShell.querySelector(".primary-nav"); document.querySelector(".mobile-nav-overlay"); document.querySelector(".mobile-nav"); document.querySelector(".faq-item"); document.querySelector(".filter-btn").addEventListener("click", function () {}); document.querySelector(".filter-btn").closest(".button-shell"); document.querySelector(".filter-bar"); document.querySelector(".filter-chips"); document.getElementById("note-search"); document.querySelector(".search-input"); document.querySelector(".js-sort-select"); document.querySelector(".js-filter-check"); document.getElementById("contact-form"); document.getElementById("form-success");',
        ),
    )
)->toArray();
$runtimeTargetContainerReport = $runtimeTargetContainerSite['source_reports']['runtime_dependency_parity'] ?? array();
$runtimeTargetDependencies = array();
foreach ( $runtimeTargetContainerReport['dependencies'] ?? array() as $dependency ) {
    $runtimeTargetDependencies[$dependency['selector'] ?? ''] = $dependency;
}
$assert('pass' === ($runtimeTargetContainerReport['status'] ?? ''), 'runtime dependency parity passes generic preserved JS target containers');
foreach ( array( '.nav-toggle', '.menu-shell', '.primary-nav', '.mobile-nav-overlay', '.mobile-nav', '.faq-item', '.filter-btn', '.button-shell', '.filter-bar', '.filter-chips', '#contact-form', '#form-success' ) as $selector ) {
    $assert(true === ($runtimeTargetDependencies[$selector]['generated_present'] ?? null), 'runtime dependency parity records preserved target ' . $selector);
}
$assert(! isset($runtimeTargetDependencies['.reveal']), 'presentational reveal animation targets are not reported as runtime dependencies');
$assert(str_contains((string) ($runtimeTargetContainerSite['serialized_blocks'] ?? ''), 'nav-toggle'), 'artifact block markup preserves runtime-targeted menu toggle class');
$assert(str_contains((string) ($runtimeTargetContainerSite['serialized_blocks'] ?? ''), 'mobile-nav-overlay'), 'artifact block markup preserves mobile nav overlay target class after navigation dedupe');
$assert(! str_contains((string) ($runtimeTargetContainerSite['serialized_blocks'] ?? ''), 'drawer-nav'), 'artifact block markup still removes duplicate drawer navigation links after preserving target wrapper');

$externalFormStatusTargetSite = $compiler->compile(
    array(
        'entrypoint' => 'index.html',
        'files'      => array(
            'index.html' => '<main><form class="contact-form"><label>Email<input type="email" name="email"></label><button type="submit">Send</button></form><div class="form-success js-form-success" role="status" aria-live="polite"></div><p class="form-error"></p></main>',
            'website/nav.js' => 'document.querySelector(".form-success"); document.querySelector(".form-error");',
        ),
    )
)->toArray();
$externalFormStatusMarkup = (string) ($externalFormStatusTargetSite['serialized_blocks'] ?? '');
$externalFormStatusReport = $externalFormStatusTargetSite['source_reports']['runtime_dependency_parity'] ?? array();
$externalFormStatusDependencies = array();
foreach ( $externalFormStatusReport['dependencies'] ?? array() as $dependency ) {
    $externalFormStatusDependencies[$dependency['selector'] ?? ''] = $dependency;
}
$assert('pass' === ($externalFormStatusReport['status'] ?? ''), 'runtime dependency parity passes external-script form feedback targets');
$assert(true === ($externalFormStatusDependencies['.form-success']['generated_present'] ?? null), 'external script .form-success target remains present in generated block markup');
$assert(true === ($externalFormStatusDependencies['.form-error']['generated_present'] ?? null), 'external script .form-error target remains present in generated block markup');
$assert(str_contains($externalFormStatusMarkup, 'form-success'), 'generated block markup preserves form success feedback class');
$assert(str_contains($externalFormStatusMarkup, 'form-error'), 'generated block markup preserves form error feedback class');
$assert(! str_contains($externalFormStatusMarkup, 'js-form-success'), 'generated block markup still omits behavior-hook feedback classes');
$assert(! str_contains($externalFormStatusMarkup, '<div class="form-success js-form-success"'), 'form feedback target is not preserved as raw HTML fallback markup');

$legacyFrontPageSite = $compiler->compile(
    array(
        'entrypoint' => 'index.html',
        'files'      => array(
            'index.html'    => '<main><h1>Home</h1></main>',
            'about-us.html' => '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd"><HTML><HEAD><META HTTP-EQUIV="Content-Type" CONTENT="text/html; charset=windows-1252"><TITLE>About Us</TITLE></HEAD><BODY BGCOLOR="#FFFFFF" TEXT="#003366"><CENTER><TABLE BORDER="0" WIDTH="600"><TR><TD><CENTER><FONT FACE="Times New Roman" SIZE="6"><B>About Hank\'s Tool Rental</B></FONT></CENTER><FONT FACE="Arial" SIZE="2">Family owned since 1987.<BR>We answer the phone.</FONT></TD></TR></TABLE></CENTER></BODY></HTML>',
        ),
    )
)->toArray();
$legacyPlanPage = null;
foreach ( $legacyFrontPageSite['source_reports']['wordpress_site_plan']['pages'] ?? array() as $planPage ) {
    if ( 'about-us.html' === ($planPage['source_path'] ?? '') ) {
        $legacyPlanPage = $planPage;
    }
}
$legacyBlockMarkup = (string) ($legacyPlanPage['canonical_block_markup'] ?? '');
$assert('' !== trim($legacyBlockMarkup), 'legacy HTML 4 FrontPage-era documents produce non-empty materialization block markup');
$assert(str_contains($legacyBlockMarkup, 'About Hank&#039;s Tool Rental'), 'legacy HTML 4 FrontPage-era table/font/center content is preserved');
$assert(str_contains($legacyBlockMarkup, '<!-- wp:table'), 'legacy HTML 4 layout tables convert to table block markup instead of empty fallback metadata');

$legacyInline = ( new HtmlTransformer() )->transform('<CENTER><FONT FACE="Arial" SIZE="2">Visible legacy inline copy</FONT></CENTER>')->toArray();
$assert(str_contains((string) ($legacyInline['serialized_blocks'] ?? ''), 'Visible legacy inline copy'), 'center/font-only legacy fragments preserve visible text');
$assert(str_contains((string) ($legacyInline['serialized_blocks'] ?? ''), '<!-- wp:paragraph'), 'center/font-only legacy fragments convert to semantic paragraph blocks');

$logoAssetPlanRow = null;
$cssAssetPlanRow = null;
foreach ( $staticPlan['assets'] ?? array() as $assetPlanRow ) {
    if ( 'assets/logo.png' === ($assetPlanRow['source_path'] ?? '') ) {
        $logoAssetPlanRow = $assetPlanRow;
    }
    if ( 'visual-repair.css' === ($assetPlanRow['source_path'] ?? '') ) {
        $cssAssetPlanRow = $assetPlanRow;
    }
}
$assert('assets/assets/logo.png' === ($logoAssetPlanRow['target_path'] ?? ''), 'canonical plan asset rows expose materialized target paths');
$assert(base64_encode("\x89PNG\r\n\x1a\n") === ($logoAssetPlanRow['content_base64'] ?? ''), 'canonical plan asset rows expose base64 payloads for binary assets');
$assert('image/png' === ($logoAssetPlanRow['mime_type'] ?? ''), 'canonical plan asset rows expose media types');
$assert(! empty($logoAssetPlanRow['content_hash'] ?? ''), 'canonical plan asset rows expose stable payload hashes');
$assert('.wp-site-blocks{min-height:100vh}' === ($cssAssetPlanRow['content'] ?? ''), 'canonical plan asset rows expose text payloads for writable assets');

$cssReferences = $compiler->compile(
    array(
        'entrypoint' => 'index.html',
        'files'      => array(
            'index.html' => '<main><link rel="stylesheet" href="theme/site.css"><h1>Fonts</h1></main>',
            'theme/site.css' => '@import "fonts/fonts.css"; body{background:url("../assets/paper.png")}',
            'theme/fonts/fonts.css' => '@font-face{font-family:"Fixture Sans";src:url("FixtureSans.woff2") format("woff2");font-weight:400}',
            'theme/fonts/FixtureSans.woff2' => array(
                'content_base64' => base64_encode('fixture-font'),
                'mime_type'      => 'font/woff2',
            ),
            'assets/paper.png' => array(
                'content_base64' => base64_encode("\x89PNG\r\n\x1a\n"),
                'mime_type'      => 'image/png',
            ),
        ),
    )
)->toArray();
$cssAssetReferences = $cssReferences['source_reports']['artifact']['asset_references'] ?? array();
$assert(4 === count($cssAssetReferences), 'CSS asset analysis reports linked stylesheet, @import, url(), and @font-face url references');
$assert('css-import' === ($cssAssetReferences[1]['context'] ?? ''), 'CSS @import references expose a neutral context');
$assert('theme/fonts/fonts.css' === ($cssAssetReferences[1]['asset_path'] ?? ''), 'CSS @import references resolve relative to the source stylesheet');
$assert('css:@import(1)' === ($cssAssetReferences[1]['selector'] ?? ''), 'CSS @import references expose a stable selector');
$assert('css-url' === ($cssAssetReferences[2]['context'] ?? ''), 'CSS url() references expose a neutral context');
$assert('assets/paper.png' === ($cssAssetReferences[2]['asset_path'] ?? ''), 'CSS url() references continue resolving asset paths');
$assert('css-font-face' === ($cssAssetReferences[3]['context'] ?? ''), 'CSS @font-face url references expose a neutral context');
$assert('theme/fonts/FixtureSans.woff2' === ($cssAssetReferences[3]['asset_path'] ?? ''), 'CSS @font-face url references resolve local font assets');
$fontCompiledAsset = null;
$fontPlanAsset = null;
foreach ( $cssReferences['source_reports']['compiled_site']['assets'] ?? array() as $asset ) {
    if ( 'theme/fonts/FixtureSans.woff2' === ($asset['path'] ?? '') ) {
        $fontCompiledAsset = $asset;
    }
}
foreach ( $cssReferences['source_reports']['wordpress_site_plan']['assets'] ?? array() as $asset ) {
    if ( 'theme/fonts/FixtureSans.woff2' === ($asset['source_path'] ?? '') ) {
        $fontPlanAsset = $asset;
    }
}
$assert('font/woff2' === ($fontCompiledAsset['media_type'] ?? ''), 'compiled site assets preserve local font media type');
$assert('css-font-face' === ($fontCompiledAsset['references'][0]['context'] ?? ''), 'compiled site assets expose structured reference metadata');
$assert('css-font-face' === ($fontPlanAsset['references'][0]['context'] ?? ''), 'materialization plan assets preserve structured reference metadata');

$imageReferenceSite = $compiler->compile(
    array(
        'entrypoint' => 'pages/index.html',
        'files'      => array(
            'pages/index.html' => '<main><picture><source srcset="../assets/hero-small.png 480w, ../assets/hero-large.png 960w"><img src="../assets/logo.png" alt="Logo"></picture><section style="background-image:url(../assets/panel.png)"></section><svg><image href="../assets/vector.png"></image></svg></main>',
            'assets/hero-small.png' => array('content_base64' => base64_encode('small'), 'mime_type' => 'image/png'),
            'assets/hero-large.png' => array('content_base64' => base64_encode('large'), 'mime_type' => 'image/png'),
            'assets/logo.png' => array('content_base64' => base64_encode('logo'), 'mime_type' => 'image/png'),
            'assets/panel.png' => array('content_base64' => base64_encode('panel'), 'mime_type' => 'image/png'),
            'assets/vector.png' => array('content_base64' => base64_encode('vector'), 'mime_type' => 'image/png'),
        ),
    )
)->toArray();
$imageReferencePlanAssets = array();
foreach ( $imageReferenceSite['source_reports']['wordpress_site_plan']['assets'] ?? array() as $asset ) {
    $imageReferencePlanAssets[$asset['source_path'] ?? ''] = $asset;
}
$assert('source' === ($imageReferencePlanAssets['assets/hero-small.png']['references'][0]['element'] ?? ''), 'materialization plan image rows preserve picture source references');
$assert('inline-style' === ($imageReferencePlanAssets['assets/panel.png']['references'][0]['context'] ?? ''), 'materialization plan image rows preserve inline background references');
$assert('image' === ($imageReferencePlanAssets['assets/vector.png']['references'][0]['element'] ?? ''), 'materialization plan image rows preserve SVG image href references');

$neutralPlan = ( new MaterializationPlanBuilder() )->fromCompiledSite(
    array(
        'products' => array(
            array('sku' => 'shirt-001', 'name' => 'Shirt'),
        ),
    )
);
$assert(! array_key_exists('products', $neutralPlan), 'materialization plan omits product-specific manifest buckets');

$fontMaterializationPlan = ( new FontMaterializationPlanBuilder() )->googleFonts(array(
    array('family' => 'Open Sans', 'weights' => array(400, 700)),
    array('family' => 'Poppins', 'weights' => array(500)),
    array('family' => 'Arial', 'weights' => array(400)),
    array('family' => 'inherit', 'weights' => array(400)),
    array('family' => 'INITIAL', 'weights' => array(400)),
    array('family' => 'unset', 'weights' => array(400)),
    array('family' => 'revert', 'weights' => array(400)),
    array('family' => 'revert-layer', 'weights' => array(400)),
));
$assert('blocks-engine/php-transformer/font-materialization-plan/v1' === ($fontMaterializationPlan['schema'] ?? null), 'font materialization exposes schema');
$assert('@import url("https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;700&family=Poppins:wght@500&display=swap");' === ($fontMaterializationPlan['css'] ?? null), 'font materialization builds deterministic google fonts css');
$assert(array('Open Sans', 'Poppins') === array_column($fontMaterializationPlan['fonts'] ?? array(), 'family'), 'font materialization excludes web-safe and CSS-wide family keywords');
$assert('assets/css/fonts.css' === ($fontMaterializationPlan['stylesheets'][0]['path'] ?? null), 'font materialization emits stylesheet asset plan');
$largeFontRoles = ( new FontMaterializationPlanBuilder() )->fontRolesFromCss(str_repeat('.utility{color:red}', 65536) . 'body{font-family:"Open Sans",sans-serif}h1{font-family:Poppins,sans-serif}');
$assert(array('heading' => 'Poppins', 'body' => 'Open Sans') === $largeFontRoles, 'font role discovery scans large stylesheets without materializing every rule');

$fontAwarePlan = ( new MaterializationPlanBuilder() )->fromCompiledSite(array(
    'theme' => array(
        'font_usage' => array(
            array('family' => 'Open Sans', 'weights' => array(400, 700)),
            array('family' => 'Poppins', 'weights' => array(500)),
        ),
    ),
));
$assert(array(array('family' => 'Open Sans', 'weights' => array(400, 700)), array('family' => 'Poppins', 'weights' => array(500))) === ($fontAwarePlan['theme']['font_usage'] ?? null), 'materialization plan preserves theme font usage');
$assert('blocks-engine/php-transformer/font-materialization-plan/v1' === ($fontAwarePlan['theme']['font_materialization']['schema'] ?? null), 'materialization plan builds font materialization plan');
$assert('@import url("https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;700&family=Poppins:wght@500&display=swap");' === ($fontAwarePlan['theme']['font_materialization']['css'] ?? null), 'materialization plan builds google font css from usage');

// Web-font detection from linked Google Fonts stylesheets + font-family declarations.
$webFontHtml = '<head><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&amp;family=Inter:wght@400;500;600&amp;display=swap"></head>';
$webFontCss = 'h1,h2,h3 { font-family: "Oswald", "Inter", system-ui, sans-serif; } body { font-family: "Inter", system-ui, sans-serif; }';
$webFontPlan = ( new FontMaterializationPlanBuilder() )->fromWebFontSources($webFontHtml, $webFontCss);
$webFontFamilies = array_map(static fn (array $font): string => (string) $font['family'], $webFontPlan['fonts'] ?? array());
$assert(array('Inter', 'Oswald') === $webFontFamilies, 'web-font detection captures both linked css2 families');
$assert(array(400, 500, 600, 700) === ($webFontPlan['fonts'][1]['weights'] ?? null), 'web-font detection parses :wght@ axis weights');
$assert('Oswald' === ($webFontPlan['roles']['heading'] ?? null), 'web-font detection maps heading typeface from font-family declaration');
$assert('Inter' === ($webFontPlan['roles']['body'] ?? null), 'web-font detection maps body typeface from font-family declaration');
$assert('@import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Oswald:wght@400;500;600;700&display=swap");' === ($webFontPlan['css'] ?? null), 'web-font detection materializes deterministic google fonts css');
$importantWebFontPlan = ( new FontMaterializationPlanBuilder() )->fromWebFontSources('<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins&family=Quicksand&family=Muli">', 'body{font-family:"Poppins",sans-serif}h2{font-family:"Quicksand" !important}.menu{font-family:"Muli" !IMPORTANT}');
$assert(array('Muli', 'Poppins', 'Quicksand') === array_column($importantWebFontPlan['fonts'] ?? array(), 'family'), 'web-font detection strips CSS important priority from family names');
$assert(array('heading' => 'Quicksand', 'body' => 'Poppins') === ($importantWebFontPlan['roles'] ?? null), 'web-font role discovery strips CSS important priority from family names');

$mixedFontPlan = ( new FontMaterializationPlanBuilder() )->fromWebFontSources(
    '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap">',
    'body{font-family:system-ui,sans-serif}h1{font-family:Inter,sans-serif}.custom{font-family:"Acme Custom",serif}.invalid{font-family:var(--missing),inherit}'
);
$assert(array(array('family' => 'Inter', 'weights' => array(400, 700))) === ($mixedFontPlan['fonts'] ?? null), 'Google font materialization remains provider-backed across mixed Google, system, custom, and invalid CSS families');
$assert('@import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap");' === ($mixedFontPlan['css'] ?? null), 'mixed CSS family usage cannot add unbacked families to the Google Fonts request');
$assert(array('heading' => 'Inter') === ($mixedFontPlan['roles'] ?? null), 'mixed CSS family roles retain the provider-backed Google family and omit system, custom, and invalid families');

$importedWebFontPlan = ( new FontMaterializationPlanBuilder() )->fromWebFontSources(
    '',
    '@import url(\'https://fonts.googleapis.com/css2?family=EB+Garamond:ital,wght@0,400;0,500;0,600;0,700;1,400;1,600&family=Noto+Sans+JP:wght@400;500;700&display=swap\'); body{font-family:"Noto Sans JP",sans-serif}h1{font-family:"EB Garamond",serif}'
);
$assert(array('EB Garamond', 'Noto Sans JP') === array_column($importedWebFontPlan['fonts'] ?? array(), 'family'), 'web-font detection captures families declared only by a CSS import');
$assert(array(400, 500, 600, 700) === ($importedWebFontPlan['fonts'][0]['weights'] ?? null), 'CSS-imported web-font detection preserves italic axis tuple weights');
$assert(array(400, 500, 700) === ($importedWebFontPlan['fonts'][1]['weights'] ?? null), 'CSS-imported web-font detection preserves repeated family weights');
$assert(str_contains((string) ($importedWebFontPlan['css'] ?? ''), 'family=EB+Garamond:ital,wght@0,400;0,500;0,600;0,700;1,400;1,600'), 'CSS-imported web-font materialization retains declared italic face tuples');

$webFontProofPlan = ( new FontMaterializationPlanBuilder() )->fromWebFontSources(
    '',
    '@import url("https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,100..900;1,100..900&display=swap");@import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap");',
    array(array('path' => 'styles/fonts.css', 'content' => '@import url("https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,100..900;1,100..900&display=swap");@import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap");', 'source_hash' => str_repeat('a', 64)))
);
$assert(2 === count($webFontProofPlan['imports'] ?? array()), 'web-font imports retain one deterministic provenance record per source import');
$assert('styles/fonts.css' === ($webFontProofPlan['imports'][0]['provenance']['source_path'] ?? null), 'web-font import proof retains the source stylesheet path');
$assert(str_repeat('a', 64) === ($webFontProofPlan['imports'][0]['provenance']['source_hash'] ?? null), 'web-font import proof retains the source stylesheet hash');
$assert(4 === count($webFontProofPlan['face_records'] ?? array()), 'web-font imports resolve static and variable declared faces without Cartesian style expansion');
$assert(2 === count(array_filter($webFontProofPlan['face_records'] ?? array(), static fn (array $face): bool => array('kind' => 'range', 'min' => 100, 'max' => 900) === ($face['weight'] ?? null))), 'variable web-font faces retain typed weight ranges');
$assert(4 === count($webFontProofPlan['receipts'] ?? array()) && 4 === count($webFontProofPlan['browser_readiness']['receipt_refs'] ?? array()), 'web-font proof retains durable face receipt references for browser readiness');
$assert(hash('sha256', (string) ($webFontProofPlan['stylesheets'][0]['content'] ?? '')) === ($webFontProofPlan['stylesheets'][0]['expected_content_hash'] ?? null), 'web-font stylesheet carries an expected content hash');
$assert('blocks-engine/webfont-materialization/v1' === ($webFontProofPlan['webfont_contract']['schema'] ?? null), 'web-font materialization emits the shared versioned consumer contract');
$firstWebFontSource = $webFontProofPlan['webfont_contract']['imports'][0]['source'] ?? array();
$assert('css' === ($firstWebFontSource['format'] ?? null) && array_key_exists('expected_digest', $firstWebFontSource) && null === $firstWebFontSource['expected_digest'] && array_key_exists('observed_digest', $firstWebFontSource) && null === $firstWebFontSource['observed_digest'], 'web-font import contract declares downloadable CSS sources with consumer-owned observed digests');
$assert(4 === count($webFontProofPlan['webfont_contract']['faces'] ?? array()) && 4 === count($webFontProofPlan['webfont_contract']['browser_readiness']['required_receipt_ids'] ?? array()), 'web-font contract binds typed faces and browser readiness to durable receipt IDs');
$contractImportsById = array_column($webFontProofPlan['webfont_contract']['imports'] ?? array(), null, 'id');
$assert(($webFontProofPlan['webfont_contract']['faces'][0]['import_id'] ?? null) === ($webFontProofPlan['face_records'][0]['import_ref'] ?? null) && ($webFontProofPlan['webfont_contract']['faces'][0]['sources'][0]['url'] ?? null) === ($contractImportsById[$webFontProofPlan['webfont_contract']['faces'][0]['import_id'] ?? '']['source']['url'] ?? null), 'legacy face records are an explicit contract projection with downloadable face sources');
$assert(($webFontProofPlan['webfont_contract']['receipts'][0]['id'] ?? null) === ($webFontProofPlan['receipts'][0]['id'] ?? null) && ($webFontProofPlan['webfont_contract']['browser_readiness']['required_receipt_ids'] ?? null) === ($webFontProofPlan['browser_readiness']['receipt_refs'] ?? null), 'legacy receipts and readiness are equivalent compatibility projections of the shared contract');
$svgConsumerPlan = (new FontMaterializationPlanBuilder())->withSvgConsumers($webFontProofPlan, array(array('source' => 'inline-svg', 'path' => 'assets/materialized-svg/fixture-37.svg', 'target_path' => 'assets/website/materialized-svg/fixture-37.svg', 'mime_type' => 'image/svg+xml', 'content' => '<svg><text font-family="Inter, sans-serif">Fixture 37</text></svg>'), array('source' => 'inline-svg', 'path' => 'assets/materialized-svg/icon.svg', 'target_path' => 'assets/website/materialized-svg/icon.svg', 'mime_type' => 'image/svg+xml', 'content' => '<svg><path d="M0 0"/></svg>')));
$svgConsumers = $svgConsumerPlan['webfont_contract']['svg_consumers'] ?? array();
$receiptFaces = array_column($svgConsumerPlan['webfont_contract']['receipts'] ?? array(), 'face_id', 'id');
$assert(1 === count($svgConsumers) && 'assets/materialized-svg/fixture-37.svg' === ($svgConsumers[0]['source_path'] ?? null) && 'assets/website/materialized-svg/fixture-37.svg' === ($svgConsumers[0]['write_path'] ?? null) && true === ($svgConsumers[0]['required'] ?? null) && array() !== ($svgConsumers[0]['face_ids'] ?? array()) && ($svgConsumers[0]['face_ids'] ?? array()) === array_values(array_unique($svgConsumers[0]['face_ids'] ?? array())), 'fixture 37 Inter SVG emits one canonical typed webfont consumer while non-text SVGs emit none');
$assert(($svgConsumers[0]['face_ids'] ?? array()) === array_values(array_map(static fn(string $receiptId): string => (string) ($receiptFaces[$receiptId] ?? ''), $svgConsumers[0]['receipt_ids'] ?? array())), 'fixture 37 multi-face SVG consumer preserves every face-to-receipt pair index-for-index.');
$projectedSvgConsumerPlan = (new FontMaterializationPlanBuilder())->withSvgConsumers($webFontProofPlan, array(array('source' => 'inline-svg', 'path' => 'assets/materialized-svg/fixture-37.svg', 'target_path' => 'wp-content/themes/example/assets/fixture-37.svg', 'mime_type' => 'image/svg+xml', 'content' => '<svg><text font-family="Inter, sans-serif">Fixture 37</text></svg>')));
$projectedSvgConsumer = $projectedSvgConsumerPlan['webfont_contract']['svg_consumers'][0] ?? array();
$assert(($svgConsumers[0]['source_path'] ?? null) === ($projectedSvgConsumer['source_path'] ?? null) && ($svgConsumers[0]['pre_transform_payload_hash'] ?? null) === ($projectedSvgConsumer['pre_transform_payload_hash'] ?? null) && ($svgConsumers[0]['write_path'] ?? null) !== ($projectedSvgConsumer['write_path'] ?? null), 'SVG consumer write intent may be projected downstream without changing source identity or payload hash.');
$assertRejectsSvgConsumer = static function (array $contract, string $message) use ($assert): void { try { FontMaterializationPlanBuilder::assertWebFontContract($contract, array(array('path' => 'assets/materialized-svg/fixture-37.svg', 'target_path' => 'assets/website/materialized-svg/fixture-37.svg', 'content' => '<svg><text font-family="Inter, sans-serif">Fixture 37</text></svg>'))); } catch (\InvalidArgumentException) { return; } $assert(false, $message); };
$invalidSvgConsumer = $svgConsumerPlan['webfont_contract']; $invalidSvgConsumer['svg_consumers'][0]['face_ids'][] = 'unknown-face'; $assertRejectsSvgConsumer($invalidSvgConsumer, 'webfont contract rejects unknown SVG consumer faces');
$staleSvgConsumer = $svgConsumerPlan['webfont_contract']; $staleSvgConsumer['svg_consumers'][0]['pre_transform_payload_hash'] = str_repeat('0', 64); $assertRejectsSvgConsumer($staleSvgConsumer, 'webfont contract rejects stale SVG consumer payload hashes');
$duplicateSvgConsumer = $svgConsumerPlan['webfont_contract']; $duplicateSvgConsumer['svg_consumers'][] = $duplicateSvgConsumer['svg_consumers'][0]; $assertRejectsSvgConsumer($duplicateSvgConsumer, 'webfont contract rejects duplicate or noncanonical SVG consumer arrays');
$noncanonicalSvgConsumer = $svgConsumerPlan['webfont_contract']; $noncanonicalSvgConsumer['svg_consumers'][0]['face_ids'] = array_reverse($noncanonicalSvgConsumer['svg_consumers'][0]['face_ids']); $assertRejectsSvgConsumer($noncanonicalSvgConsumer, 'webfont contract rejects noncanonical SVG consumer reference arrays');
$deduplicatedWebFontPlan = ( new FontMaterializationPlanBuilder() )->fromWebFontSources('', '', array(
    array('path' => 'styles/a.css', 'content' => '@import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap");', 'source_hash' => str_repeat('b', 64)),
    array('path' => 'styles/b.css', 'content' => '@import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap");', 'source_hash' => str_repeat('b', 64)),
));
$assert(1 === count($deduplicatedWebFontPlan['webfont_contract']['imports'] ?? array()) && 2 === count($deduplicatedWebFontPlan['webfont_contract']['faces'] ?? array()), 'repeated stylesheet references with the same source hash and href produce one deduplicated Inter import and face set');
$unsupportedWebFontPlan = ( new FontMaterializationPlanBuilder() )->fromWebFontSources('', '@import url("https://fonts.example.test/brand.css");');
$assert('webfont_import_unsupported_provider' === ($unsupportedWebFontPlan['diagnostics'][0]['code'] ?? null), 'unsupported web-font imports retain a reason-coded diagnostic');
$assert('unsupported' === ($unsupportedWebFontPlan['webfont_contract']['imports'][0]['state'] ?? null) && 'webfont_import_unsupported_provider' === ($unsupportedWebFontPlan['webfont_contract']['imports'][0]['diagnostics'][0]['code'] ?? null) && array() === ($unsupportedWebFontPlan['webfont_contract']['faces'] ?? null), 'zero-face web-font contracts retain required import diagnostics');

$directFaceCss = '@font-face{font-family:"Festival Display";font-style:italic;font-weight:700;src:url("https://cdn.example.test/fonts/festival-display.woff2") format("woff2")}h1{font-family:"Festival Display",serif}body{font-family:"Unproven Sans",sans-serif}';
$directFacePlan = ( new FontMaterializationPlanBuilder() )->fromWebFontSources('', $directFaceCss, array(array('path' => 'styles/typography.css', 'content' => $directFaceCss, 'source_hash' => str_repeat('d', 64))));
$assert(array(array('family' => 'Festival Display', 'weights' => array(700))) === ($directFacePlan['fonts'] ?? null) && 'Festival Display' === ($directFacePlan['roles']['heading'] ?? null) && ! isset($directFacePlan['roles']['body']), 'source-proven direct font faces materialize their family and matching role without CSS-only families');
$assert('@font-face{font-family:"Festival Display";font-style:italic;font-weight:700;src:url("https://cdn.example.test/fonts/festival-display.woff2");}' === ($directFacePlan['css'] ?? null), 'direct font materialization emits only the typed font-face declaration');
$directContract = $directFacePlan['webfont_contract'] ?? array();
$assert('direct' === ($directContract['imports'][0]['provider'] ?? null) && 'font' === ($directContract['imports'][0]['source']['format'] ?? null) && 'https://cdn.example.test/fonts/festival-display.woff2' === ($directContract['faces'][0]['sources'][0]['url'] ?? null) && 'styles/typography.css' === ($directContract['imports'][0]['provenance']['source_path'] ?? null) && 'css:@font-face(1)' === ($directContract['imports'][0]['provenance']['selector'] ?? null), 'direct font faces retain typed source URL and source provenance in the materialization contract');
$directMaterializationPlan = ( new MaterializationPlanBuilder() )->fromCompiledSite(array('theme' => array('static_css' => $directFaceCss, 'font_css_sources' => array(array('path' => 'styles/typography.css', 'content' => $directFaceCss, 'source_hash' => str_repeat('d', 64))))));
$assert('@font-face{font-family:"Festival Display";font-style:italic;font-weight:700;src:url("https://cdn.example.test/fonts/festival-display.woff2");}' . "\n" === ($directMaterializationPlan['theme']['font_materialization']['stylesheets'][0]['content'] ?? null), 'materialization plan carries the direct font declaration as its standalone stylesheet asset');
$eligibleDirectFaceCss = '@font-face{font-family:Eligible Woff;src:url("https://cdn.example.test/fonts/eligible.woff?download=1")}@font-face{font-family:Eligible Woff2;src:url("https://cdn.example.test:443/fonts/eligible.WOFF2#face")}';
$eligibleDirectFacePlan = ( new FontMaterializationPlanBuilder() )->fromWebFontSources('', $eligibleDirectFaceCss);
$eligibleDirectFaceUrls = array_column(array_map(static fn (array $import): array => $import['source'] ?? array(), $eligibleDirectFacePlan['webfont_contract']['imports'] ?? array()), 'url');
$expectedEligibleDirectFaceUrls = array('https://cdn.example.test/fonts/eligible.woff?download=1', 'https://cdn.example.test:443/fonts/eligible.WOFF2#face');
sort($eligibleDirectFaceUrls, SORT_STRING); sort($expectedEligibleDirectFaceUrls, SORT_STRING);
$assert($expectedEligibleDirectFaceUrls === $eligibleDirectFaceUrls && 2 === count($eligibleDirectFacePlan['webfont_contract']['faces'] ?? array()), 'HTTPS WOFF and WOFF2 direct faces with implicit or explicit port 443 retain the typed materialization contract');
foreach (array(
    'http://cdn.example.test/fonts/insecure.woff2',
    'https://user:password@cdn.example.test/fonts/credentials.woff2',
    'https://cdn.example.test:8443/fonts/nonstandard-port.woff2',
    'https://cdn.example.test/fonts/not-a-font.ttf',
    'https://',
) as $ineligibleDirectFaceUrl) {
    $ineligibleDirectFaceCss = '@font-face{font-family:Ineligible;src:url("' . $ineligibleDirectFaceUrl . '")}';
    $ineligibleDirectFacePlan = ( new FontMaterializationPlanBuilder() )->fromWebFontSources('', $ineligibleDirectFaceCss);
    $assert(array() === ($ineligibleDirectFacePlan['webfont_contract']['imports'] ?? null) && array() === ($ineligibleDirectFacePlan['webfont_contract']['faces'] ?? null), 'direct face eligibility rejects ' . $ineligibleDirectFaceUrl);
}

$rangeFontPlan = ( new FontMaterializationPlanBuilder() )->fromWebFontSources(
    '<head><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Crimson+Pro:ital,wght@0,300..900;1,300..900&amp;family=JetBrains+Mono:wght@400&amp;display=swap"></head>',
    'body { font-family: "Crimson Pro", Georgia, serif; } .mono { font-family: "JetBrains Mono", monospace; }'
);
$assert(array(300, 400, 500, 600, 700, 800, 900) === ($rangeFontPlan['fonts'][0]['weights'] ?? null), 'web-font detection expands css2 font-weight ranges');
$assert('@import url("https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400&display=swap");' === ($rangeFontPlan['css'] ?? null), 'web-font detection preserves ranged google font weights deterministically');

// Legacy css (v1) link syntax with `|`-separated families and comma weight lists.
$legacyFontPlan = ( new FontMaterializationPlanBuilder() )->fromWebFontSources(
    '<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:400,700|Lora">',
    ''
);
$assert(array('Lora', 'Roboto') === array_map(static fn (array $font): string => (string) $font['family'], $legacyFontPlan['fonts'] ?? array()), 'web-font detection handles legacy css family pipes');
$assert(array(400, 700) === ($legacyFontPlan['fonts'][1]['weights'] ?? null), 'web-font detection parses legacy comma weight lists');
$malformedLegacyFontPlan = ( new FontMaterializationPlanBuilder() )->fromWebFontSources(
    '<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Raleway:300|400">',
    ''
);
$assert(array(array('family' => 'Raleway', 'weights' => array(300))) === ($malformedLegacyFontPlan['fonts'] ?? null), 'web-font detection rejects numeric legacy pipe tokens as font families');

// Web-font sources flow through the full materialization plan theme contract.
$webFontMaterializationPlan = ( new MaterializationPlanBuilder() )->fromCompiledSite(array(
    'theme' => array(
        'font_link_html' => $webFontHtml,
        'static_css'     => $webFontCss,
    ),
));
$assert('Oswald' === ($webFontMaterializationPlan['theme']['font_materialization']['roles']['heading'] ?? null), 'materialization plan materializes heading font from web-font sources');

// CSS custom-property (var()) font-families resolve to their concrete typeface.
// Sources frequently apply fonts through `var(--font-body)` defined in :root.
// An unresolved `var(--font-body)` token must never reach the Google Fonts
// request: it is not a real family and corrupts the css2 endpoint (HTTP 400),
// which drops every linked font and renders the system fallback. The resolver
// must expand the variable to its real family and assign roles accordingly.
$varFontHtml = '<head><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&amp;family=Lora:wght@400;500&amp;display=swap"></head>';
$varFontCss  = ":root{--font-disp:'Playfair Display',Georgia,serif;--font-body:'Lora',Georgia,serif;}body{font-family:var(--font-body);}h1,h2,h3{font-family:var(--font-disp);}";
$varFontPlan = ( new FontMaterializationPlanBuilder() )->fromWebFontSources($varFontHtml, $varFontCss);
$varFontFamilies = array_map(static fn (array $font): string => (string) $font['family'], $varFontPlan['fonts'] ?? array());
$assert(array('Lora', 'Playfair Display') === $varFontFamilies, 'var() font-family resolves to concrete families and emits no var token family');
$assert('Lora' === ($varFontPlan['roles']['body'] ?? null), 'var() body font-family resolves to its defined typeface');
$assert('Playfair Display' === ($varFontPlan['roles']['heading'] ?? null), 'var() heading font-family resolves to its defined typeface');
$assert(! str_contains((string) ($varFontPlan['css'] ?? ''), 'var('), 'materialized google fonts css carries no unresolved var() token');
$assert(! str_contains((string) ($varFontPlan['css'] ?? ''), '%28'), 'materialized google fonts css carries no encoded parenthesis family');

// An unresolvable var() (no :root definition, no fallback) must be dropped, not
// emitted as a bogus family that would break the linked Google Fonts request.
$unresolvedVarPlan = ( new FontMaterializationPlanBuilder() )->fromWebFontSources(
    '<head><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Lora:wght@400&display=swap"></head>',
    'body{font-family:var(--font-undefined);}'
);
$assert(array('Lora') === array_map(static fn (array $font): string => (string) $font['family'], $unresolvedVarPlan['fonts'] ?? array()), 'unresolvable var() font-family is dropped from materialized fonts');
$assert(! str_contains((string) ($unresolvedVarPlan['css'] ?? ''), 'var('), 'unresolvable var() never reaches the materialized google fonts css');

// Typography/web-font parity diagnostic (semantic-parity finding family).
$semanticFindings = static function (array $result): array {
    return $result['source_reports']['semantic_parity']['findings'] ?? array();
};
$findingsByCode = static function (array $findings, string $code): array {
    return array_values(array_filter($findings, static fn (array $finding): bool => ($finding['code'] ?? '') === $code));
};

// Positive: a heading web-font declared only in an inline <style> block (no link, no static css)
// is genuinely dropped and must surface a typography parity finding.
$droppedHeadingFontResult = ( new HtmlTransformer() )->transform(
    '<!doctype html><html><head><style>h1,h2{font-family:"Display Custom",sans-serif}</style></head><body><main><h1>Heading</h1><p>Copy</p></main></body></html>',
    array()
)->toArray();
$droppedHeadingFindings = $findingsByCode($semanticFindings($droppedHeadingFontResult), 'typography_font_family_dropped');
$assert(array() !== $droppedHeadingFindings, 'dropped heading web-font emits typography_font_family_dropped finding');
$assert('Display Custom' === ($droppedHeadingFindings[0]['font_family'] ?? null), 'typography finding records the dropped font family generically');
$assert(str_contains((string) ($droppedHeadingFindings[0]['source_snippet'] ?? ''), 'Display Custom'), 'typography finding carries bounded source snippet');
$assert('none' === ($droppedHeadingFindings[0]['observed_block'] ?? null), 'dropped typography finding records explicit none observed_block');
$assert('typography_font_family_dropped' === ($droppedHeadingFindings[0]['reason_code'] ?? null), 'typography finding carries stable reason_code');

// Positive: a web-font family linked from a non-materializing provider surfaces web_font_not_materialized.
$nonMaterializedLinkResult = ( new HtmlTransformer() )->transform(
    '<!doctype html><html><head><link rel="stylesheet" href="https://use.typekit.net/css?family=Brand+Face:wght@400;700"></head><body><main><h1>Heading</h1></main></body></html>',
    array()
)->toArray();
$nonMaterializedFindings = $findingsByCode($semanticFindings($nonMaterializedLinkResult), 'web_font_not_materialized');
$assert(array() !== $nonMaterializedFindings, 'non-materializing linked web-font emits web_font_not_materialized finding');
$assert('Brand Face' === ($nonMaterializedFindings[0]['font_family'] ?? null), 'web_font_not_materialized finding records the linked family generically');
$assert(str_contains((string) ($nonMaterializedFindings[0]['source_snippet'] ?? ''), '<link'), 'web_font_not_materialized finding carries the source link snippet');

// Negative: a font that materializes (Google Fonts link + matching css) must NOT produce any typography finding.
$materializedFontResult = ( new HtmlTransformer() )->transform(
    '<!doctype html><html><head><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Display+Custom:wght@400;700&display=swap"></head><body><main><h1>Heading</h1></main></body></html>',
    array('static_css' => 'h1,h2,h3{font-family:"Display Custom",sans-serif}')
)->toArray();
$materializedTypographyFindings = array_filter(
    $semanticFindings($materializedFontResult),
    static fn (array $finding): bool => in_array($finding['code'] ?? '', array('typography_font_family_dropped', 'web_font_not_materialized'), true)
);
$assert(array() === $materializedTypographyFindings, 'materialized web-font produces no typography parity finding');

// Positive: a base/body family without a provider source cannot be represented
// by claiming it is a Google font, so it remains a reported typography drop.
$inlineBodyFontResult = ( new HtmlTransformer() )->transform(
    '<!doctype html><html><head><style>body{font-family:"Brand Sans",sans-serif}</style></head><body><main><h1>Heading</h1><p>Copy</p></main></body></html>',
    array()
)->toArray();
$inlineBodyDropped = $findingsByCode($semanticFindings($inlineBodyFontResult), 'typography_font_family_dropped');
$assert(array() !== $inlineBodyDropped, 'inline <style> base/body font-family without a provider is reported dropped');
$inlineBodyPlan = ( new FontMaterializationPlanBuilder() )->fromWebFontSources(
    '<head><style>body{font-family:"Brand Sans",sans-serif}</style></head>',
    ''
);
$assert(! array_key_exists('fonts', $inlineBodyPlan), 'inline <style> base/body font-family is not materialized without a provider source');
$assert(! array_key_exists('roles', $inlineBodyPlan), 'unbacked inline body family is omitted from materialized roles');

// Positive: a heading-only font in an inline <style> block (no body declaration)
// still requires a loaded web-font to render, so it remains a reported drop.
$inlineHeadingOnlyResult = ( new HtmlTransformer() )->transform(
    '<!doctype html><html><head><style>h1,h2{font-family:"Display Custom",sans-serif}</style></head><body><main><h1>Heading</h1></main></body></html>',
    array()
)->toArray();
$inlineHeadingOnlyDropped = $findingsByCode($semanticFindings($inlineHeadingOnlyResult), 'typography_font_family_dropped');
$assert(array() !== $inlineHeadingOnlyDropped, 'inline <style> heading-only font without a loaded web-font is still reported dropped');
$assert('heading' === ($inlineHeadingOnlyDropped[0]['font_role'] ?? null), 'inline <style> heading-only drop carries the heading role');

// Enrichment: every semantic-parity finding (landmark/navigation) carries source_snippet, observed_block, and reason_code.
$underSpecifiedResult = ( new HtmlTransformer() )->transform(
    '<body><header><nav><span>Menu</span></nav></header><main><p>Copy</p></main></body>',
    array()
)->toArray();
$semanticParityFindings = $semanticFindings($underSpecifiedResult);
$assert(array() !== $semanticParityFindings, 'navigation/landmark drop produces semantic-parity findings');
foreach ( $semanticParityFindings as $finding ) {
    $assert(isset($finding['reason_code']) && '' !== (string) $finding['reason_code'], 'semantic-parity finding carries reason_code');
    $assert(isset($finding['source_snippet']) && '' !== (string) $finding['source_snippet'], 'semantic-parity finding carries source_snippet');
    $assert(isset($finding['observed_block']), 'semantic-parity finding carries observed_block');
}
$navMissingFindings = $findingsByCode($semanticParityFindings, 'navigation_menu_missing');
$assert(array() !== $navMissingFindings, 'navigation_menu_missing finding is emitted for unrepresented nav');
$assert(str_contains((string) ($navMissingFindings[0]['source_snippet'] ?? ''), '<nav'), 'navigation_menu_missing finding source_snippet contains the source nav markup');

$fragment = $compiler->compileFragment('<main><h2>Fragment</h2><p>Copy</p></main>', 'fixture:fragment')->toArray();
$assert('success' === $fragment['status'], 'fragment compiles successfully', (string) $fragment['status']);
$assert('fixture:fragment' === ($fragment['provenance'][0]['source'] ?? ''), 'fragment compile exposes source provenance');
$assert('artifact-fragment' === ($fragment['provenance'][0]['scope'] ?? ''), 'fragment compile exposes source scope');
$assert(str_contains((string) $fragment['serialized_blocks'], '<!-- wp:heading'), 'fragment compile serializes heading block');

$missing = $compiler->compile(array('files' => array()))->toArray();
$assert('failed' === $missing['status'], 'missing HTML fails explicitly', (string) $missing['status']);
$assert('missing_entry_html' === ($missing['diagnostics'][0]['code'] ?? ''), 'missing entry diagnostic is exposed');

$unsafe = $compiler->compile(
    array(
        'entrypoints' => array('../unsafe.html'),
        'files'       => array(
            '../secret.html'          => '<main>Nope</main>',
            '/absolute.html'          => '<main>Nope</main>',
            'safe.html'              => '<main>Safe</main>',
            'assets//nested/style.css' => '.safe{}',
        ),
    )
)->toArray();
$assert('success_with_warnings' === $unsafe['status'], 'unsafe paths produce warning status', (string) $unsafe['status']);
$assert(2 === ($unsafe['source_reports']['artifact']['rejected_count'] ?? null), 'unsafe paths are rejected');
$assert('unsafe_entrypoint_path' === ($unsafe['diagnostics'][0]['code'] ?? ''), 'unsafe entrypoints are diagnosed');
$assert(! empty(array_filter($unsafe['assets'], static fn (array $asset): bool => 'assets/nested/style.css' === ($asset['path'] ?? ''))), 'safe artifact paths collapse duplicate separators');

$binary = $compiler->compile(
    array(
        'entrypoint' => 'pages/home.html',
        'files'      => array(
            array(
                'path'           => 'pages/home.html',
                'content_base64' => base64_encode('<main><h1>Encoded</h1></main>'),
                'mime_type'      => 'text/html',
                'role'           => 'entry',
            ),
            array(
                'path'           => 'assets/logo.png',
                'content_base64' => base64_encode("\x89PNG\r\n\x1a\n"),
                'mime_type'      => 'image/png',
                'role'           => 'brand-asset',
            ),
            array(
                'path'           => 'assets/bad.bin',
                'content_base64' => 'not-valid-base64',
            ),
        ),
    )
)->toArray();
$assert('success_with_warnings' === $binary['status'], 'invalid base64 is a non-blocking warning', (string) $binary['status']);
$assert('pages/home.html' === ($binary['source_reports']['artifact']['entry_path'] ?? ''), 'base64 HTML entry is decoded and selected');
$assert(1 === ($binary['source_reports']['artifact']['files_by_mime']['image/png'] ?? 0), 'MIME counts include binary assets');
$assert(1 === ($binary['source_reports']['artifact']['files_by_role']['brand-asset'] ?? 0), 'role counts include binary assets');
$assert(1 === ($binary['source_reports']['artifact']['rejected_count'] ?? null), 'invalid base64 file is rejected');
$assert('assets/logo.png' === ($binary['assets'][0]['path'] ?? ''), 'binary asset appears in manifest');
$assert(true === ($binary['assets'][0]['binary'] ?? null), 'binary asset is marked binary');
$assert(! empty($binary['assets'][0]['content_base64'] ?? ''), 'binary asset keeps base64 payload');

$blocks = $compiler->compile(
    array(
        'files' => array(
            'index.html'                    => '<main><section class="hero"><h1>Block type</h1></section><article class="card product-card" data-component="Product Card">A</article><article class="card product-card">B</article></main>',
            'blocks/hero/block.json'        => json_encode(
                array(
                    'apiVersion'   => 3,
                    'name'         => 'acme/hero',
                    'title'        => 'Hero',
                    'category'     => 'design',
                    'editorScript' => 'file:./index.js',
                    'viewScript'   => array('file:./view.js', 'wp-interactivity'),
                    'style'        => 'file:./style.css',
                    'editorStyle'  => 'file:./editor.css',
                    'render'       => 'file:./render.php',
                    'attributes'   => array(
                        'headline' => array('type' => 'string'),
                    ),
                    'supports'     => array('align' => true),
                ),
                JSON_UNESCAPED_SLASHES
            ),
            'blocks/hero/index.js'          => 'import metadata from "./block.json";',
            'blocks/hero/index.asset.php'   => '<?php return array("dependencies" => array("wp-blocks"), "version" => "1");',
            'blocks/hero/view.js'           => 'console.log("front");',
            'blocks/hero/style.css'         => '.wp-block-acme-hero{padding:2rem}',
            'blocks/hero/editor.css'        => '.wp-block-acme-hero{outline:1px solid}',
            'blocks/hero/render.php'        => '<?php echo $content;',
            'components/Hero.jsx'           => 'export default function Hero() { return <section />; }',
            'components/ProductGrid.tsx'    => 'export const ProductGrid = () => <div />;',
        ),
    )
)->toArray();
$assert(1 === count($blocks['block_types']), 'block.json roots are promoted into block type artifacts');
$heroBlock = $blocks['block_types'][0] ?? array();
$assert('chubes4/wordpress-block-type-artifact/v1' === ($heroBlock['schema'] ?? ''), 'block type exposes contract schema');
$assert('acme/hero' === ($heroBlock['name'] ?? ''), 'block type name is preserved');
$assert('hero' === ($heroBlock['slug'] ?? ''), 'block type slug is normalized');
$assert('blocks/hero' === ($heroBlock['directory'] ?? ''), 'block type exposes source directory');
$assert('blocks/hero/block.json' === ($heroBlock['block_json_path'] ?? ''), 'block type exposes block.json path');
$assert(3 === ($heroBlock['metadata']['apiVersion'] ?? null), 'block metadata preserves apiVersion');
$assert(array('align' => true) === ($heroBlock['metadata']['supports'] ?? null), 'block metadata preserves supports');
$assert('blocks/hero/index.js' === ($heroBlock['assets']['editor_script'][0]['path'] ?? ''), 'editor script file reference resolves to generated file');
$assert('wp-interactivity' === ($heroBlock['assets']['view_script'][1]['reference'] ?? ''), 'script handles are preserved as references');
$assert('blocks/hero/render.php' === ($heroBlock['assets']['render'][0]['path'] ?? ''), 'render file reference resolves to generated file');
$assert('blocks/hero/index.asset.php' === ($heroBlock['dependencies']['asset_files'][0]['path'] ?? ''), 'asset php dependency manifests are recorded');
$assert(array('wp-blocks') === ($heroBlock['dependencies']['asset_files'][0]['manifest']['dependencies'] ?? null), 'asset php dependencies are parsed when simple manifests are present');
$assert('1' === ($heroBlock['dependencies']['asset_files'][0]['manifest']['version'] ?? ''), 'asset php versions are parsed when simple manifests are present');
$assert(in_array('blocks/hero/style.css', $heroBlock['provenance']['files'] ?? array(), true), 'block provenance lists source files');
$assert(! empty($heroBlock['provenance']['source_hash'] ?? ''), 'block type exposes provenance hash');
$assert(! empty(array_filter($blocks['components'], static fn (array $component): bool => 'ProductGrid' === ($component['name'] ?? '') && 'jsx-component-file' === ($component['signal'] ?? ''))), 'TSX component declarations produce component candidates');
$assert(! empty(array_filter($blocks['components'], static fn (array $component): bool => 'product-card' === ($component['name'] ?? '') && 'class-token' === ($component['signal'] ?? ''))), 'repeated semantic classes produce component candidates');
$assert(! empty(array_filter($blocks['components'], static fn (array $component): bool => 'product-card' === ($component['name'] ?? '') && 'data-component' === ($component['signal'] ?? ''))), 'data-component markers produce component candidates');

$unnamedBlock = $compiler->compile(
    array(
        'files' => array(
            'index.html' => '<main>Fallback block</main>',
            'blocks/fallback/block.json' => '{"title":"Fallback"}',
        ),
    )
)->toArray();
$assert('generated/fallback' === ($unnamedBlock['block_types'][0]['name'] ?? ''), 'unnamed block.json receives stable generated name');
$assert(in_array('block_json_missing_name', array_column($unnamedBlock['diagnostics'], 'code'), true), 'unnamed block.json emits a diagnostic');

// Companion-plugin payload producer (issue #491 slice 2): generated blocks are
// packaged into a payload whose shape matches the SSI #492 scaffold() consumer.
$companion = $compiler->compile(
    array(
        'site'  => array( 'name' => 'Acme Co', 'slug' => 'acme' ),
        'files' => array(
            'index.html'             => '<main><section class="hero"><h1>Hi</h1></section></main>',
            'blocks/hero/block.json' => json_encode(
                array(
                    'apiVersion'   => 3,
                    'name'         => 'acme/hero',
                    'title'        => 'Hero',
                    'category'     => 'design',
                    'render'       => 'file:./render.php',
                    'viewScript'   => 'file:./view.js',
                    'style'        => 'file:./style.css',
                    'editorScript' => 'file:./index.js',
                ),
                JSON_UNESCAPED_SLASHES
            ),
            'blocks/hero/render.php' => '<?php echo "<div>hero</div>";',
            'blocks/hero/view.js'    => 'console.log("hero island");',
            'blocks/hero/style.css'  => '.wp-block-acme-hero{padding:2rem}',
            'blocks/hero/index.js'   => 'import metadata from "./block.json";',
        ),
    )
)->toArray();
$companionPayload = $companion['source_reports']['companion_plugin_payload'] ?? null;
$assert(is_array($companionPayload), 'companion_plugin_payload is emitted when a generated block is present');
$assert('blocks-engine/wordpress-companion-plugin/v1' === ($companionPayload['schema'] ?? ''), 'companion payload stamps the producer-owned WordPress contract');
$assert('acme' === ($companionPayload['site_slug'] ?? ''), 'companion payload derives site_slug from the artifact');
$assert('Acme Co' === ($companionPayload['site_name'] ?? ''), 'companion payload derives site_name from the artifact');
$assert(array() === ($companionPayload['preserved_js'] ?? null), 'companion payload exposes an empty preserved_js slot');
$assert(1 === count($companionPayload['blocks'] ?? array()), 'companion payload carries one block');
$companionBlock = $companionPayload['blocks'][0] ?? array();
$assert('hero' === ($companionBlock['name'] ?? ''), 'companion block name is the local slug for SSI namespacing');
$assert('acme/hero' === ($companionBlock['block_json']['name'] ?? ''), 'companion block carries the decoded block.json');
$assert(str_contains((string) ($companionBlock['render'] ?? ''), '<div>hero</div>'), 'companion block carries render content');
$assert(str_contains((string) ($companionBlock['view_js'] ?? ''), 'hero island'), 'companion block carries view JS content');
$assert(str_contains((string) ($companionBlock['assets']['style.css'] ?? ''), 'padding'), 'companion block carries non-render/view assets');
$assert(isset($companionBlock['assets']['index.js']), 'companion block carries editor script asset');
$assert(! isset($companionBlock['assets']['render.php']), 'render is not duplicated into the assets map');
$assert(! isset($companionBlock['assets']['view.js']), 'view JS is not duplicated into the assets map');
$assert(! isset($companionBlock['assets']['block.json']), 'block.json is not duplicated into the assets map');

$authoredControlsCompanion = $compiler->compile(
    array(
        'files' => array(
            'index.html' => '<link rel="stylesheet" href="controls.css"><main><select class="authored-select"><option>One</option></select><input class="authored-input" type="text"></main>',
            'controls.css' => '.authored-select{appearance:none;border:1px solid}.authored-input{border:1px solid;padding:1rem}',
        ),
    )
)->toArray();
$authoredControlsPayload = $authoredControlsCompanion['source_reports']['companion_plugin_payload'] ?? array();
$authoredControlBlocks = $authoredControlsPayload['blocks'] ?? array();
$assert(array( 'authored-select', 'authored-input' ) === array_column($authoredControlBlocks, 'name'), 'styled authored controls compile into companion entries using only their canonical short slugs');
$authoredSelectCompanion = $authoredControlBlocks[0] ?? array();
$authoredInputCompanion = $authoredControlBlocks[1] ?? array();
$assert('blocks-engine/authored-select' === ($authoredSelectCompanion['block_json']['name'] ?? null), 'authored-select companion metadata uses its canonical block name');
$assert('blocks-engine/authored-input' === ($authoredInputCompanion['block_json']['name'] ?? null), 'authored-input companion metadata uses its canonical block name');
$assert(array( 'index.js' => array( 'wp-blocks', 'wp-block-editor', 'wp-element' ) ) === ($authoredSelectCompanion['script_dependencies'] ?? null), 'authored-select companion dependency metadata survives payload compilation');
$assert(array( 'index.js' => array( 'wp-blocks', 'wp-block-editor', 'wp-element' ) ) === ($authoredInputCompanion['script_dependencies'] ?? null), 'authored-input companion dependency metadata survives payload compilation');
preg_match_all("/registerBlockType\\(\\s*'([^']+)'/", (string) ($authoredSelectCompanion['assets']['index.js'] ?? ''), $authoredSelectRegistrations);
preg_match_all("/registerBlockType\\(\\s*'([^']+)'/", (string) ($authoredInputCompanion['assets']['index.js'] ?? ''), $authoredInputRegistrations);
$assert(array( 'blocks-engine/authored-select' ) === ($authoredSelectRegistrations[1] ?? array()), 'authored-select companion editor script registers only its canonical block name');
$assert(array( 'blocks-engine/authored-input' ) === ($authoredInputRegistrations[1] ?? array()), 'authored-input companion editor script registers only its canonical block name');

$scriptCompanion = $compiler->compile(
    array(
        'site' => array( 'name' => 'Runtime Site', 'slug' => 'runtime-site' ),
        'files' => array(
            'index.html' => '<main><p class="status">Ready</p></main><script>document.querySelector(".status").dataset.ready="true";</script>',
        ),
    )
)->toArray();
$scriptPayload = $scriptCompanion['source_reports']['companion_plugin_payload'] ?? array();
$assert(array() === ($scriptPayload['blocks'] ?? null), 'script-only companion payload does not invent a custom block');
$assert(1 === count($scriptPayload['preserved_js'] ?? array()), 'script-only artifact emits one preserved companion script');
$assert(str_contains((string) ($scriptPayload['preserved_js'][0]['content'] ?? ''), 'dataset.ready'), 'companion payload carries the inline script body');
$assert('script:nth-of-type(1)' === ($scriptPayload['preserved_js'][0]['selector'] ?? ''), 'companion payload carries the source script selector');
$assert('index.html' === ($scriptPayload['preserved_js'][0]['source_path'] ?? ''), 'companion payload carries the source document path');

$rootedScriptCompanion = $compiler->compile(
    array(
        'site' => array( 'name' => 'Rooted Runtime Site', 'slug' => 'rooted-runtime-site' ),
        'root' => 'website',
        'entrypoint' => 'website/index.html',
        'files' => array(
            array( 'path' => 'website/index.html', 'content' => '<main><canvas id="canvas"></canvas></main><script src="/script.js"></script><script src="/.netlify/scripts/rum.js"></script>' ),
            array( 'path' => 'website/script.js', 'content' => 'const canvas = document.getElementById("canvas"); canvas.getContext("2d"); let totalAmplitude = 0; // Scale particles based on amplitude.' ),
            array( 'path' => 'website/.netlify/scripts/rum.js', 'content' => 'window.netlifyRum=true;' ),
        ),
    )
)->toArray();
$rootedScriptPayload = $rootedScriptCompanion['source_reports']['companion_plugin_payload'] ?? array();
$rootedPreservedJs = $rootedScriptPayload['preserved_js'] ?? array();
$assert(1 === count($rootedPreservedJs), 'root-relative first-party script is resolved against the artifact root and carried once');
$assert(str_contains((string) ($rootedPreservedJs[0]['content'] ?? ''), 'totalAmplitude'), 'application identifiers containing a telemetry vendor name remain first-party companion code');
$rootedPlan = $rootedScriptCompanion['source_reports']['wordpress_site_plan'] ?? array();
$rootedPageMarkup = (string) ($rootedPlan['pages'][0]['canonical_block_markup'] ?? '');
$assert(str_contains($rootedPageMarkup, '<canvas id="canvas"></canvas>'), 'root-relative first-party canvas runtime preserves its script-addressable markup');
$rootedPlanWriteSources = array_column($rootedPlan['writes'] ?? array(), 'source_path');
$assert(!in_array('website/script.js', $rootedPlanWriteSources, true), 'companion-owned first-party script is not duplicated into the theme plan');
$assert(!in_array('website/.netlify/scripts/rum.js', $rootedPlanWriteSources, true), 'dropped telemetry script is not written into the theme plan');
$rootedPlanScripts = array_merge(...array_map(static fn(array $page): array => $page['document_metadata']['scripts'] ?? array(), $rootedPlan['pages'] ?? array()));
$assert(array() === $rootedPlanScripts, 'companion-owned and dropped script declarations are absent from theme loading');

$companionNoSite = $compiler->compile(
    array(
        'files' => array(
            'index.html'             => '<main><p>Card</p></main>',
            'blocks/card/block.json' => json_encode(array( 'apiVersion' => 3, 'name' => 'x/card', 'render' => 'file:./render.php' )),
            'blocks/card/render.php' => '<?php echo "card";',
        ),
    )
)->toArray();
$companionNoSitePayload = $companionNoSite['source_reports']['companion_plugin_payload'] ?? null;
$assert(is_array($companionNoSitePayload), 'companion payload is emitted even without site identity');
$assert(! isset($companionNoSitePayload['site_slug']), 'companion payload omits site_slug when the artifact carries none (SSI fills it)');

$companionAbsent = $compiler->compile(
    array( 'files' => array( 'index.html' => '<main><h1>Plain</h1><p>No blocks</p></main>' ) )
)->toArray();
$assert(! array_key_exists('companion_plugin_payload', $companionAbsent['source_reports']), 'companion_plugin_payload is absent when no generated blocks exist');

$capturedDialog = $compiler->compile(array(
    'site' => array('name' => 'Captured Dialog Site', 'slug' => 'captured-dialog-site'),
    'entrypoint' => 'website/index.html',
    'files' => array(
        array('path' => 'website/index.html', 'content' => '<header class="data-liberation-semantic-header"><nav aria-label="Primary"><a class="brand" href="/">Home</a><a class="contact" role="button" aria-haspopup="dialog" data-popupid="contact">Contact</a><a class="about" href="/about/">About</a></nav></header>'),
        array('path' => 'capture-receipt.json', 'content' => json_encode(array(
            'schema' => 'data-liberation/capture-receipt/v1',
            'routes' => array(array('url' => 'https://example.com/', 'path' => 'website/index.html')),
        ), JSON_UNESCAPED_SLASHES)),
        array('path' => 'interaction-states.json', 'content' => json_encode(array(
            'schema' => 'data-liberation/captured-interactions/v1',
            'pages' => array(array(
                'sourceUrl' => 'https://example.com/',
                'states' => array(array(
                    'status' => 'captured',
                    'trigger' => array('selector' => 'body > header > nav > a:nth-of-type(2)', 'tag' => 'a', 'ariaHaspopup' => 'dialog', 'dataBindings' => array('data-popupid' => 'contact')),
                    'dialog' => array(
                        'html' => '<div role="dialog" aria-label="Contact"><form action="https://provider.example/forms"><label>Name<input name="name"></label><script>window.provider=true</script></form></div>',
                        'htmlBytes' => strlen('<div role="dialog" aria-label="Contact"><form action="https://provider.example/forms"><label>Name<input name="name"></label><script>window.provider=true</script></form></div>'),
                        'htmlTruncated' => false,
                    ),
                )),
            )),
        ), JSON_UNESCAPED_SLASHES)),
    ),
))->toArray();
$assert(1 === ($capturedDialog['source_reports']['captured_interactions']['projected_dialog_count'] ?? null), 'captured interaction reports project one matched dialog');
$assert(str_contains((string) ($capturedDialog['serialized_blocks'] ?? ''), '<!-- wp:ssi-captured-dialog-site/captured-dialog'), 'captured dialogs serialize as a site companion block', (string) ($capturedDialog['serialized_blocks'] ?? ''));
$assert(str_contains((string) ($capturedDialog['serialized_blocks'] ?? ''), '<dialog') && str_contains((string) ($capturedDialog['serialized_blocks'] ?? ''), 'data-blocks-engine-triggers='), 'captured dialog block preserves native dialog and trigger linkage');
$assert(1 === preg_match('/<!-- wp:navigation-link [^>]*"anchor":"blocks-engine-dialog-trigger-[a-f0-9]{16}-1"/', (string) ($capturedDialog['serialized_blocks'] ?? '')), 'captured dialog trigger identity survives navigation-link conversion', (string) ($capturedDialog['serialized_blocks'] ?? ''));
$assert(! str_contains((string) ($capturedDialog['serialized_blocks'] ?? ''), 'provider.example') && ! str_contains((string) ($capturedDialog['serialized_blocks'] ?? ''), 'window.provider'), 'captured dialogs remove provider endpoints and executable source code');
$capturedDialogBlocks = $capturedDialog['source_reports']['companion_plugin_payload']['blocks'] ?? array();
$capturedDialogBlock = current(array_filter($capturedDialogBlocks, static fn(array $block): bool => 'captured-dialog' === ($block['name'] ?? ''))) ?: array();
$assert('ssi-captured-dialog-site/captured-dialog' === ($capturedDialogBlock['block_json']['name'] ?? null), 'captured dialog companion metadata matches the serialized block namespace');
$assert(str_contains((string) ($capturedDialogBlock['view_js'] ?? ''), 'showModal'), 'captured dialog companion block carries scoped native dialog behavior');
$assert(isset($capturedDialogBlock['assets']['index.js']), 'captured dialog companion block carries an editable InnerBlocks editor');
$capturedDialogForms = array_values(array_filter($capturedDialog['source_reports']['artifact']['runtime_declarations'] ?? array(), static fn(array $declaration): bool => 'entity_collection' === ($declaration['kind'] ?? '') && 'forms' === ($declaration['type'] ?? '')));
$assert(1 === count($capturedDialogForms), 'captured dialog forms continue through the generic form materialization declaration');

// Runtime-island package producer (issue #491 slice 2): preserved runtime
// islands are packaged into a generic, product-neutral envelope a downstream
// materializer maps to its own runtime. The package names no host product.
$runtimeIslandPackageBuilder = new \Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeIslandPackageBuilder();
$assert('blocks-engine/php-transformer/runtime-island-package/v1' === \Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeIslandPackageBuilder::SCHEMA, 'runtime-island package uses a generic, product-neutral schema');
$assert(! str_contains(strtolower(\Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeIslandPackageBuilder::SCHEMA), 'static-site') && ! str_contains(strtolower(\Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeIslandPackageBuilder::SCHEMA), 'companion'), 'runtime-island package schema carries no consumer/product name');
$assert(array() === $runtimeIslandPackageBuilder->fromRuntimeIslands(array()), 'runtime-island package is empty when there are no islands');

$runtimeIslandFixture = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/contract/runtime-island-package.json'), true);
$assert('blocks-engine/php-transformer/runtime-island-package-fixture/v1' === ($runtimeIslandFixture['schema'] ?? ''), 'runtime-island package fixture exposes its schema');

$findIslandByKind = static function (array $package, string $kind): array {
    foreach ( $package['islands'] ?? array() as $island ) {
        if ( ($island['kind'] ?? '') === $kind ) {
            return $island;
        }
    }
    return array();
};
$findIslandByScriptRole = static function (array $package, string $role, string $kind = ''): array {
    foreach ( $package['islands'] ?? array() as $island ) {
        if ( '' !== $kind && ($island['kind'] ?? '') !== $kind ) {
            continue;
        }
        foreach ( $island['scripts'] ?? array() as $script ) {
            if ( ($script['role'] ?? '') === $role ) {
                return $island;
            }
        }
    }
    return array();
};

foreach ( $runtimeIslandFixture['cases'] as $runtimeIslandCase ) {
    $caseName = (string) ($runtimeIslandCase['name'] ?? '');
    $compiled = $compiler->compile($runtimeIslandCase['artifact'])->toArray();
    $package = $compiled['source_reports']['runtime_island_package'] ?? array();
    if ( true === ($runtimeIslandCase['expect_no_package'] ?? false) ) {
        $assert(array() === $package, 'runtime-island package is omitted for unavailable-script case ' . $caseName);
        continue;
    }
    $assert(is_array($package) && array() !== $package, 'runtime-island package is produced for fixture case ' . $caseName);
    $assert('blocks-engine/php-transformer/runtime-island-package/v1' === ($package['schema'] ?? ''), 'runtime-island package stamps the generic schema for case ' . $caseName);

    $expect = $runtimeIslandCase['expect_island'];
    if ( isset($expect['select_by_role']) ) {
        $island = $findIslandByScriptRole($package, (string) $expect['select_by_role'], (string) ($expect['kind'] ?? ''));
    } else {
        $island = $findIslandByKind($package, (string) ($expect['select_by_kind'] ?? $expect['kind']));
    }
    $assert(array() !== $island, 'runtime-island package exposes the expected island for case ' . $caseName);
    $assert(($expect['kind'] ?? null) === ($island['kind'] ?? ''), 'island kind matches for case ' . $caseName);
    $assert(($expect['disposition'] ?? null) === ($island['disposition'] ?? ''), 'island disposition matches for case ' . $caseName);
    $assert(($expect['js_handling'] ?? null) === ($island['js_handling'] ?? ''), 'island js_handling matches for case ' . $caseName);
    $assert(($expect['markup_fidelity'] ?? null) === ($island['markup_fidelity'] ?? ''), 'island markup is tagged verbatim for case ' . $caseName);
    $assert(isset($island['id']) && str_starts_with((string) $island['id'], 'island_'), 'island exposes a stable id for case ' . $caseName);
    $assert(isset($island['handle_hint']) && str_starts_with((string) $island['handle_hint'], 'runtime-island-'), 'island exposes a generic enqueue handle hint for case ' . $caseName);

    if ( isset($expect['markup_contains']) ) {
        $assert(str_contains((string) ($island['markup'] ?? ''), (string) $expect['markup_contains']), 'island carries verbatim markup for case ' . $caseName);
    }

    if ( isset($expect['script_source_kind']) ) {
        $script = $island['scripts'][0] ?? array();
        $assert(($expect['script_source_kind'] ?? null) === ($script['source_kind'] ?? ''), 'island script source kind matches for case ' . $caseName);
        $assert(($expect['script_role'] ?? null) === ($script['role'] ?? ''), 'island script role classification matches for case ' . $caseName);
        if ( isset($expect['content_contains']) ) {
            $assert(str_contains((string) ($script['content'] ?? ''), (string) $expect['content_contains']), 'island preserves verbatim inline JS for case ' . $caseName);
        }
        if ( array_key_exists('materialized', $expect) ) {
            $assert(($expect['materialized']) === ($script['materialized'] ?? null), 'island external script materialization flag matches for case ' . $caseName);
        }
        if ( true === ($expect['droppable'] ?? null) ) {
            $assert(true === ($script['droppable'] ?? null), 'telemetry island script is marked droppable for case ' . $caseName);
        }
        if ( false === ($expect['droppable'] ?? null) ) {
            $assert(! array_key_exists('droppable', $script), 'first-party island script is not marked droppable for case ' . $caseName);
        }
    }

    if ( isset($expect['has_external_script']) ) {
        $externalScripts = array_values(array_filter($island['scripts'] ?? array(), static fn (array $s): bool => 'external' === ($s['source_kind'] ?? '')));
        $inlineScripts = array_values(array_filter($island['scripts'] ?? array(), static fn (array $s): bool => 'inline' === ($s['source_kind'] ?? '')));
        $assert(array() !== $externalScripts, 'island carries an external script for case ' . $caseName);
        $assert(array() !== $inlineScripts, 'island carries an inline script for case ' . $caseName);
        $external = $externalScripts[0];
        if ( true === ($expect['external_materialized'] ?? null) ) {
            $assert(true === ($external['materialized'] ?? null), 'island external script is materialized for case ' . $caseName);
            $assert(str_contains((string) ($external['content'] ?? ''), (string) ($expect['external_content_contains'] ?? '')), 'island external script carries materialized content for case ' . $caseName);
        }
        $firstParty = array_values(array_filter($island['scripts'] ?? array(), static fn (array $s): bool => 'first_party' === ($s['role'] ?? '')));
        $assert(count($firstParty) >= (int) ($expect['first_party_scripts_min'] ?? 0), 'island carries the expected first-party scripts for case ' . $caseName);
    }
}

$normalized = $compiler->compile(
    array(
        'entry'   => 'public/index.html',
        'files'   => array(
            array(
                'name' => 'public/index.html',
                'body' => '<main><h1>Aliases</h1></main>',
            ),
            'public/index.html' => '<main><h1>Duplicate path</h1></main>',
            'data/settings.json' => '{"ok":true}',
            'docs/readme.mdx' => '# Hello',
        ),
        'styles'  => 'body { color: rebeccapurple; }',
        'script'  => 'console.log("artifact");',
        'outputs' => array(
            array(
                'name' => 'assets/icon.svg',
                'content' => '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
            ),
        ),
    )
)->toArray();
$assert('public/index.html' === ($normalized['source_reports']['artifact']['entry_path'] ?? ''), 'entry alias selects public index HTML');
$assetPaths = array_column($normalized['assets'], 'path');
$pagePaths = array_column($normalized['source_reports']['compiled_site']['pages'] ?? array(), 'source_path');
$assert(in_array('public/index-2.html', $pagePaths, true), 'duplicate document paths are deduped deterministically');
$assert(in_array('style.css', $assetPaths, true), 'styles shorthand becomes a CSS file');
$assert(in_array('site.js', $assetPaths, true), 'script shorthand becomes a JS file');
$assert(1 === ($normalized['source_reports']['artifact']['files_by_mime']['text/mdx'] ?? 0), 'MDX MIME is inferred');
$assert(1 === ($normalized['source_reports']['artifact']['files_by_role']['stylesheet'] ?? 0), 'CSS role is inferred');
$assert(1 === ($normalized['source_reports']['artifact']['files_by_intent']['behavior'] ?? 0), 'JS intent is inferred');
$assert(1 === ($normalized['source_reports']['artifact']['files_by_source']['styles'] ?? 0), 'source counts include top-level shorthand source');
$assert(! empty($normalized['source_reports']['artifact']['source_hash'] ?? ''), 'stable source hash is exposed in source reports');
$scriptAsset = null;
foreach ( $normalized['assets'] as $asset ) {
    if ( 'site.js' === ($asset['path'] ?? '') ) {
        $scriptAsset = $asset;
        break;
    }
}
$assert('script' === ($scriptAsset['role'] ?? ''), 'JS asset role is exposed in manifest');
$assert('behavior' === ($scriptAsset['intent'] ?? ''), 'JS asset intent is exposed in manifest');

$documents = $compiler->compile(
    array(
        'files' => array(
            'content/about.md' => "---\ntitle: About Us\nslug: about\npost_type: page\nexcerpt: Short summary\ndate: 2026-06-19\ntemplate: page-wide\ncategories: [News, Updates]\ntags: launch, artifact\n---\n# About\n\nMarkdown body.",
        ),
    )
)->toArray();
$assert('success' === $documents['status'], 'document-only Markdown compiles through canonical Markdown adapter', (string) $documents['status']);
$assert(1 === count($documents['documents']), 'Markdown source document is exposed');
$assert('content/about.md' === ($documents['documents'][0]['source_path'] ?? ''), 'document source path is preserved');
$assert('markdown' === ($documents['documents'][0]['body_format'] ?? ''), 'Markdown body format is exposed');
$assert('About Us' === ($documents['documents'][0]['title'] ?? ''), 'frontmatter title is parsed');
$assert('about' === ($documents['documents'][0]['slug'] ?? ''), 'frontmatter slug is parsed');
$assert('page' === ($documents['documents'][0]['post_type'] ?? ''), 'frontmatter post type is parsed');
$assert('Short summary' === ($documents['documents'][0]['excerpt'] ?? ''), 'frontmatter excerpt is parsed');
$assert('2026-06-19' === ($documents['documents'][0]['date'] ?? ''), 'frontmatter date is parsed');
$assert('page-wide' === ($documents['documents'][0]['template'] ?? ''), 'frontmatter template is parsed');
$assert(array( 'News', 'Updates' ) === ($documents['documents'][0]['taxonomies']['categories'] ?? null), 'frontmatter category list is parsed');
$assert('launch, artifact' === ($documents['documents'][0]['taxonomies']['tags'] ?? ''), 'frontmatter taxonomy scalar hints are preserved');
$assert(str_contains((string) ($documents['documents'][0]['block_markup'] ?? ''), '<!-- wp:heading'), 'Markdown heading block markup is exposed');
$assert(str_contains((string) $documents['serialized_blocks'], 'Markdown body.'), 'document fallback supplies serialized blocks when HTML is absent');
$assert(array() === ($documents['documents'][0]['diagnostics'] ?? null), 'Markdown document conversion does not depend on ambient wrapper diagnostics');

$mdx = $compiler->compile(
    array(
        'files' => array(
            'docs/page.mdx' => "---\ntitle: MDX Page\n---\nimport Hero from '../components/Hero';\nimport { Card as FeatureCard } from './FeatureCard';\n# MDX\n\n<Hero />\n<FeatureCard />\n<MissingThing />",
            'components/Hero.jsx' => 'export default function Hero() { return <section />; }',
        ),
    )
)->toArray();
$assert('success_with_warnings' === $mdx['status'], 'MDX documents compile with partial-support warnings', (string) $mdx['status']);
$assert('mdx' === ($mdx['documents'][0]['kind'] ?? ''), 'MDX source document is classified');
$assert('mdx' === ($mdx['documents'][0]['body_format'] ?? ''), 'MDX body format is exposed');
$assert(! empty(array_filter($mdx['components'], static fn (array $component): bool => 'Hero' === ($component['name'] ?? '') && 'mdx-jsx' === ($component['signal'] ?? ''))), 'MDX component candidate is exposed');
$assert(! empty(array_filter($mdx['components'], static fn (array $component): bool => 'Hero' === ($component['name'] ?? '') && 'components/Hero.jsx' === ($component['resolved_path'] ?? ''))), 'relative MDX imports resolve to artifact files');
$mdxDiagnosticCodes = array_column($mdx['diagnostics'], 'code');
$assert(in_array('mdx_source_document_detected', $mdxDiagnosticCodes, true), 'MDX detection diagnostic is emitted');
$assert(in_array('mdx_import_unresolved', $mdxDiagnosticCodes, true), 'unresolved relative MDX imports are diagnosed');
$assert(in_array('mdx_component_unresolved', $mdxDiagnosticCodes, true), 'unimported MDX component references are diagnosed');

$tooLarge = $compiler->compile(
    array(
		'files' => array(
			'index.html' => '<main>OK</main>',
			'huge.txt' => str_repeat('x', ArtifactNormalizer::DEFAULT_MAX_FILE_BYTES + 1),
		),
	)
)->toArray();
$assert('success_with_warnings' === $tooLarge['status'], 'oversized files are rejected with a warning status');
$assert(1 === ($tooLarge['source_reports']['artifact']['rejected_count'] ?? null), 'oversized file increments rejected count');
$assert('artifact_file_too_large' === ($tooLarge['diagnostics'][0]['code'] ?? ''), 'oversized file diagnostic is exposed');

$negotiatedLimits = (new ArtifactNormalizer())->normalize(array(
    'compiler_limits' => array(
        'max_files' => PHP_INT_MAX,
        'max_file_bytes' => ArtifactNormalizer::DEFAULT_MAX_FILE_BYTES + 1,
        'max_total_bytes' => PHP_INT_MAX,
    ),
    'files' => array(
        'index.html' => '<main>OK</main>',
        'large.txt' => str_repeat('x', ArtifactNormalizer::DEFAULT_MAX_FILE_BYTES + 1),
    ),
));
$assert(2 === count($negotiatedLimits['files']), 'artifact compiler accepts files within explicitly negotiated limits');
$assert(array(
    'max_files' => ArtifactNormalizer::MAX_FILES,
    'max_file_bytes' => ArtifactNormalizer::DEFAULT_MAX_FILE_BYTES + 1,
    'max_total_bytes' => ArtifactNormalizer::MAX_TOTAL_BYTES,
) === ($negotiatedLimits['limits'] ?? null), 'artifact compiler clamps negotiated limits to hard resource ceilings');

assertSame('core/group', $result['blocks'][0]['blockName'], 'main wrapper should preserve multiple supported child blocks in a group.');
assertSame('core/heading', $result['blocks'][0]['innerBlocks'][0]['blockName'], 'h1 should convert to a heading block.');
assertSame(1, $result['blocks'][0]['innerBlocks'][0]['attrs']['level'], 'h1 level should be preserved.');
assertSame('core/paragraph', $result['blocks'][0]['innerBlocks'][1]['blockName'], 'p should convert to a paragraph block.');
assertSame('core/list', $result['blocks'][1]['blockName'], 'ul should convert to a list block.');
assertSame('core/list-item', $result['blocks'][1]['innerBlocks'][0]['blockName'], 'li should convert to list-item blocks.');
assertSame(array(), $runtimeCanvasResult['fallbacks'], 'runtime-targeted canvas elements should be preserved without fallback diagnostics.');
assertSame('core/html', $runtimeCanvasResult['blocks'][0]['blockName'], 'runtime-targeted canvas elements should be materialized as bounded raw HTML.');
$assert(str_contains((string) ($runtimeCanvasResult['serialized_blocks'] ?? ''), 'id="fixture-canvas"'), 'runtime-targeted canvas serialized output should preserve the native target.');
assertContains('html_to_blocks_core_slice', array_column($result['diagnostics'], 'code'), 'expanded core-slice conversion diagnostic should be present.');
assertSame('html', $result['provenance'][0]['source_format'], 'source provenance should identify HTML input.');
assertSame(strlen($fixture . "\n<ul><li>One</li><li><strong>Two</strong></li></ul><canvas>Fallback</canvas>"), $result['metrics']['input_bytes'], 'HTML metrics should expose input bytes.');
assertSame(strlen($result['serialized_blocks']), $result['metrics']['output_bytes'], 'HTML metrics should expose output bytes.');
assertSame(6, $result['metrics']['block_count'], 'HTML metrics should count nested blocks.');
assertSame(0, $result['metrics']['fallback_count'], 'HTML metrics should not count non-runtime canvas as a runtime fallback.');
assertSame(count($result['diagnostics']), $result['metrics']['diagnostic_count'], 'HTML metrics should expose diagnostic count.');
$assert(is_float($result['metrics']['transform_duration_ms'] ?? null), 'HTML metrics expose transform duration');

if ( ! str_contains($result['serialized_blocks'], '<!-- wp:heading {"level":1} -->') ) {
    fwrite(STDERR, "Serialized blocks did not include the expected heading block.\n");
    exit(1);
}

// Canonical block style attributes (#261 / #259): core blocks must carry a
// structured `style` OBJECT (style.typography/color/spacing/border) plus the
// `layout` attribute, never a raw inline `style` STRING. Anything unmappable to
// a block support rides on `className`, and responsive/JS-revealed base hidden
// states (display:none) are never frozen onto content-bearing elements.
$canonicalStyleResult = ( new HtmlTransformer() )->transform(
    '<style>.class-owned-flex{display:flex;flex-direction:column;gap:1rem}</style>'
    . '<main>'
    . '<h2 class="eyebrow" style="font-size:2rem;color:#c0392b;font-weight:700">Styled heading</h2>'
    . '<p class="lede" style="color:#222;line-height:1.6;text-align:center;font-family:var(--font-mono)">Styled paragraph</p>'
    . '<div class="hero" style="display:flex;gap:1rem;padding:2rem;background:#101010;color:#fff;position:fixed;inset:0;overflow:hidden">'
    . '<h3>Hero heading</h3><p>Hero content</p></div>'
    . '<div class="class-owned-flex"><p>Class-owned layout</p></div>'
    . '<nav class="main-nav" style="display:none;gap:1.6rem"><a href="/a">Home</a></nav>'
    . '</main>'
)->toArray();

$collectStyleViolations = static function (array $blocks) use (&$collectStyleViolations): array {
    $violations = array();
    foreach ( $blocks as $block ) {
        if ( ! is_array($block) ) {
            continue;
        }
        $style = $block['attrs']['style'] ?? null;
        if ( is_string($style) ) {
            $violations[] = ($block['blockName'] ?? '?') . ' => ' . $style;
        }
        $violations = array_merge($violations, $collectStyleViolations($block['innerBlocks'] ?? array()));
    }
    return $violations;
};

$findBlock = static function (array $blocks, string $name) use (&$findBlock): ?array {
    foreach ( $blocks as $block ) {
        if ( ! is_array($block) ) {
            continue;
        }
        if ( ($block['blockName'] ?? '') === $name ) {
            return $block;
        }
        $found = $findBlock($block['innerBlocks'] ?? array(), $name);
        if ( null !== $found ) {
            return $found;
        }
    }
    return null;
};

$factory = new BlockFactory();

$listBlock = $factory->create(
    'core/list',
    array('style' => array('spacing' => array('blockGap' => '1.25rem'))),
    array($factory->create('core/list-item', array('content' => 'One')))
);
$listSerialized = serialize_blocks(array($listBlock));
$assert(! isset($listBlock['attrs']['style']['spacing']['blockGap']), 'core/list drops unsupported blockGap before serialization');
$assert('<ul class="wp-block-list"></ul>' === $listBlock['innerHTML'], 'core/list innerHTML carries the generated wp-block-list wrapper and no gap style');
$assert(! str_contains($listSerialized, 'blockGap'), 'core/list serialized attrs do not contain unsupported blockGap');
$assert(! str_contains($listSerialized, 'gap:'), 'core/list serialized markup does not contain unsupported gap style');
$assert(str_contains($listSerialized, '<ul class="wp-block-list"><!-- wp:list-item'), 'core/list serialized markup preserves child placeholders inside the generated wrapper');

$sizedListItem = $factory->create('core/list-item', array(
    'content' => 'Sized item',
    'style' => array('dimensions' => array('maxWidth' => '538.299px'), 'spacing' => array('padding' => array('bottom' => '43.646px'))),
));
$assert(! isset($sizedListItem['attrs']['style']['dimensions']['maxWidth']), 'core/list-item drops maxWidth that core save cannot reproduce');
$assert(! str_contains($sizedListItem['innerHTML'], 'max-width:'), 'core/list-item saved markup omits unsupported max-width');
$assert(str_contains($sizedListItem['innerHTML'], 'padding-bottom:43.646px'), 'core/list-item retains supported spacing styles');

$galleryImages = array(
    $factory->create('core/image', array('url' => '/one.jpg', 'alt' => 'One')),
    $factory->create('core/image', array('url' => '/two.jpg', 'alt' => 'Two')),
);
$defaultGallery = $factory->create('core/gallery', array('className' => 'source-gallery'), $galleryImages);
$assert(str_contains($defaultGallery['innerHTML'], 'class="wp-block-gallery has-nested-images columns-default is-cropped source-gallery"'), 'core/gallery emits core default structural classes');

$uncroppedGallery = $factory->create('core/gallery', array('columns' => 3, 'imageCrop' => false), $galleryImages);
$assert(str_contains($uncroppedGallery['innerHTML'], 'columns-3'), 'core/gallery emits its explicit column class');
$assert(! str_contains($uncroppedGallery['innerHTML'], 'is-cropped'), 'core/gallery respects disabled image cropping');

$borderedImage = $factory->create('core/image', array(
    'url' => '/bordered.jpg',
    'alt' => 'Bordered',
    'className' => 'source-image',
    'style' => array('border' => array('color' => '#ffffff', 'style' => 'solid')),
));
$assert(str_contains($borderedImage['innerHTML'], '<figure class="wp-block-image source-image"><img'), 'core/image omits metadata-skipped border classes from its canonical markup');
$assert(! str_contains($borderedImage['innerHTML'], 'has-border-color') && ! str_contains($borderedImage['innerHTML'], 'border-color:#ffffff'), 'core/image does not serialize metadata-skipped border styles without a source carrier');

$defaultTable = $factory->create(
    'core/table',
    array('body' => array(array('cells' => array(array('content' => 'A')))))
);
$assert(str_contains($defaultTable['innerHTML'], '<table class="has-fixed-layout">'), 'core/table defaults to has-fixed-layout in saved markup');

$nonFixedTable = $factory->create(
    'core/table',
    array('hasFixedLayout' => false, 'body' => array(array('cells' => array(array('content' => 'A')))))
);
$assert(str_contains($nonFixedTable['innerHTML'], '<table>'), 'core/table supports explicit non-fixed layout markup');
$assert(! str_contains($nonFixedTable['innerHTML'], 'has-fixed-layout'), 'core/table explicit non-fixed layout omits has-fixed-layout');

$separator = $factory->create('core/separator');
$assert('<hr class="wp-block-separator has-alpha-channel-opacity has-css-opacity" />' === $separator['innerHTML'], 'core/separator emits generated base and opacity classes exactly');

$search = $factory->create('core/search', array('label' => 'Find', 'placeholder' => 'Docs'));
$assert('' === $search['innerHTML'], 'core/search factory output is dynamic-save empty and cannot emit static form markup');
$assert(array('') === $search['innerContent'], 'core/search innerContent is empty static content for dynamic-save validity');
$assert('<!-- wp:search {"label":"Find","placeholder":"Docs"} --><!-- /wp:search -->' === serialize_blocks(array($search)), 'core/search serialization carries only block comments and attrs');

// Guard: no emitted core block carries a raw string `style` attribute.
$styleViolations = $collectStyleViolations($canonicalStyleResult['blocks']);
$assert(array() === $styleViolations, 'core blocks must never emit a raw style string', implode('; ', $styleViolations));
$assert(! str_contains($canonicalStyleResult['serialized_blocks'], 'style="display:'), 'serialized blocks must not carry a raw display style', $canonicalStyleResult['serialized_blocks']);

// Positive: a styled heading maps to canonical typography + color.
$heading = $findBlock($canonicalStyleResult['blocks'], 'core/heading');
$assert(is_array($heading), 'styled heading block is emitted');
$assert(is_array($heading['attrs']['style'] ?? null), 'heading style is a canonical object');
assertSame('2rem', $heading['attrs']['style']['typography']['fontSize'] ?? null, 'heading font-size maps to style.typography.fontSize');
assertSame('700', $heading['attrs']['style']['typography']['fontWeight'] ?? null, 'heading font-weight maps to style.typography.fontWeight');
assertSame('#c0392b', $heading['attrs']['style']['color']['text'] ?? null, 'heading color maps to style.color.text');

// Positive: a styled paragraph maps to canonical color.
$paragraph = $findBlock($canonicalStyleResult['blocks'], 'core/paragraph');
$assert(is_array($paragraph), 'styled paragraph block is emitted');
$assert(is_array($paragraph['attrs']['style'] ?? null), 'paragraph style is a canonical object');
assertSame('#222', $paragraph['attrs']['style']['color']['text'] ?? null, 'paragraph color maps to style.color.text');
assertSame('var(--font-mono)', $paragraph['attrs']['style']['typography']['fontFamily'] ?? null, 'paragraph font-family maps to style.typography.fontFamily');
assertSame('center', $paragraph['attrs']['align'] ?? null, 'paragraph text-align maps to native align attribute');
$assert(str_contains((string) ($paragraph['innerHTML'] ?? ''), 'has-text-align-center'), 'paragraph saved markup carries native text alignment class');
$assert(str_contains((string) ($paragraph['innerHTML'] ?? ''), 'font-family:var(--font-mono)'), 'paragraph saved markup carries canonical font-family style');

// Positive + negative: display:flex maps to layout; unmappable props (position,
// inset, overflow) drop to className instead of a raw style string; the mappable
// color/padding still ride canonically.
$findBlockByClass = static function (array $blocks, string $class) use (&$findBlockByClass): ?array {
    foreach ( $blocks as $block ) {
        if ( ! is_array($block) ) {
            continue;
        }
        $classes = preg_split('/\s+/', (string) ($block['attrs']['className'] ?? '')) ?: array();
        if ( in_array($class, $classes, true) ) {
            return $block;
        }
        $found = $findBlockByClass($block['innerBlocks'] ?? array(), $class);
        if ( null !== $found ) {
            return $found;
        }
    }
    return null;
};

$hero = $findBlockByClass($canonicalStyleResult['blocks'], 'hero');
$assert(is_array($hero), 'styled container block is emitted');
$assert('core/group' === ($hero['blockName'] ?? null) && ! isset($hero['attrs']['layout']), 'display:flex routes CSS-owned layout containers to core/group without core layout support');
$assert(str_contains((string) ($hero['attrs']['className'] ?? ''), 'blocks-engine-css-owned-layout'), 'CSS-owned core groups carry the scoped neutralization marker');
$assert(str_contains((string) ($hero['attrs']['className'] ?? ''), 'hero'), 'container className is preserved for unmappable CSS');

$cachedStyleTransformer = new HtmlTransformer();
$cachedStyleFirst = $cachedStyleTransformer->transform(
    '<main><section class="hero"><p>First</p></section></main>',
    array('static_css' => '.hero{color:#111}')
)->toArray();
$cachedStyleSecond = $cachedStyleTransformer->transform(
    '<main><section class="hero"><p>Second</p></section></main>',
    array('static_css' => '.hero{color:#222}')
)->toArray();
$cachedStyleFirstHero = $findBlockByClass($cachedStyleFirst['blocks'], 'hero');
$cachedStyleSecondHero = $findBlockByClass($cachedStyleSecond['blocks'], 'hero');
assertSame('#111', $cachedStyleFirstHero['attrs']['style']['color']['text'] ?? null, 'presentation cache resolves first transform static CSS');
assertSame('#222', $cachedStyleSecondHero['attrs']['style']['color']['text'] ?? null, 'presentation cache resets between transforms');

$responsiveClassResult = (new HtmlTransformer())->transform(
    '<main><section class="responsive-panel"><p>Responsive panel</p></section></main>',
    array('static_css' => '.responsive-panel{display:flex;flex-direction:row;padding:40px 80px;color:#111}@media (max-width:800px){.responsive-panel{flex-direction:column;padding-left:16px;color:#222}}')
)->toArray();
$responsivePanel = $findBlockByClass($responsiveClassResult['blocks'], 'responsive-panel');
$assert(is_array($responsivePanel), 'responsive class-owned container block is emitted');
$assert(! isset($responsivePanel['attrs']['layout']), 'responsive class-owned layout is not frozen into block supports', json_encode($responsivePanel['attrs'] ?? array()));
$assert(! isset($responsivePanel['attrs']['style']['spacing']['padding']), 'responsive class-owned padding is not frozen into block supports', json_encode($responsivePanel['attrs'] ?? array()));
$assert(! isset($responsivePanel['attrs']['style']['color']['text']), 'responsive class-owned color is not frozen into block supports', json_encode($responsivePanel['attrs'] ?? array()));
$assert(! str_contains((string) ($responsivePanel['innerHTML'] ?? ''), 'padding-'), 'responsive class-owned padding remains stylesheet-owned', (string) ($responsivePanel['innerHTML'] ?? ''));

$responsiveInlineResult = (new HtmlTransformer())->transform(
    '<main><section class="responsive-panel" style="padding-left:12px"><p>Inline override</p></section></main>',
    array('static_css' => '.responsive-panel{padding:40px 80px}@media (max-width:800px){.responsive-panel{padding-left:16px}}')
)->toArray();
$responsiveInlinePanel = $findBlockByClass($responsiveInlineResult['blocks'], 'responsive-panel');
assertSame('12px', $responsiveInlinePanel['attrs']['style']['spacing']['padding']['left'] ?? null, 'explicit inline padding retains canonical block support priority');
$assert(! isset($responsiveInlinePanel['attrs']['style']['spacing']['padding']['right']), 'class-owned padding shorthand is not promoted beside an inline responsive override', json_encode($responsiveInlinePanel['attrs'] ?? array()));

$classOwnedFlex = $findBlockByClass($canonicalStyleResult['blocks'], 'class-owned-flex');
$assert(is_array($classOwnedFlex), 'class-owned flex container block is emitted');
$assert(! isset($classOwnedFlex['attrs']['layout']), 'class-owned flex CSS does not synthesize a WordPress layout attribute');

// Hidden-state safety (#259): a base display:none on content-bearing nav is not
// frozen; it is normalized away and surfaced as a frozen_hidden_state finding.
$nav = $findBlock($canonicalStyleResult['blocks'], 'core/navigation');
$assert(is_array($nav), 'navigation block is emitted');
$assert(! is_string($nav['attrs']['style'] ?? null), 'navigation style is never a raw string');
$navStyle = $nav['attrs']['style'] ?? array();
$assert(! (is_array($navStyle) && isset($navStyle['display'])), 'navigation must not freeze display:none');
$frozen = $canonicalStyleResult['source_reports']['html']['frozen_hidden_state'] ?? array();
$assert(is_array($frozen) && array() !== $frozen, 'frozen hidden state finding is surfaced for the hidden nav');

$editorStaticStateResult = (new HtmlTransformer())->transform(
    '<main><section id="process"><p class="reveal feature-copy">Revealed copy</p><p class="animated-copy">Animated copy</p></section></main>',
    array('static_css' => '#process{background:#111;padding:4rem}@media(max-width:600px){#process{padding:2rem}}.reveal{opacity:0;transform:translateY(2rem);transition:opacity .5s}.reveal.is-visible{opacity:1;transform:none}.animated-copy{transform:translateY(115%);animation:slide-up .9s forwards}@keyframes slide-up{to{transform:none}}')
)->toArray();
$editorStaticStateAsset = current(array_filter(
    $editorStaticStateResult['assets'] ?? array(),
    static fn (array $asset): bool => 'editor-static-state' === ($asset['source'] ?? '')
));
$assert(is_array($editorStaticStateAsset) && 'editor' === ($editorStaticStateAsset['stylesheet_target'] ?? null), 'editor static-state repair is an explicit editor-only stylesheet asset');
$editorStaticStateCss = (string) ($editorStaticStateAsset['content'] ?? '');
$assert(str_contains($editorStaticStateCss, 'animation-delay:-999999s!important') && str_contains($editorStaticStateCss, ':root .reveal.feature-copy{opacity:1!important;transform:none!important}'), 'editor static-state CSS settles authored animation and restores conversion-proven hidden content', $editorStaticStateCss);
$assert(str_contains((string) ($editorStaticStateResult['serialized_blocks'] ?? ''), 'blocks-engine-editor-anchor-process') && str_contains($editorStaticStateCss, '.blocks-engine-editor-anchor-process{background:#111;padding:4rem}') && str_contains($editorStaticStateCss, '@media(max-width:600px){.blocks-engine-editor-anchor-process{padding:2rem}}'), 'editor static-state CSS projects authored anchor selectors onto deterministic Gutenberg wrapper classes', $editorStaticStateCss);

$hiddenRichTextMarker = (new HtmlTransformer())->transform('<style>.scroll-target span{display:none}</style><div class="scroll-target"><span>Bottom of page</span></div>')->toArray();
$hiddenRichTextCss = implode("\n", array_column($hiddenRichTextMarker['assets'] ?? array(), 'content'));
$assert(str_contains((string) ($hiddenRichTextMarker['serialized_blocks'] ?? ''), 'blocks-engine-hidden-richtext-marker') && str_contains($hiddenRichTextCss, ':where(.blocks-engine-hidden-richtext-marker){display:none}'), 'hidden RichText selector carriers collapse their synthetic paragraph instead of adding an empty editable line');

$hiddenEmptyResult = (new HtmlTransformer())->transform(
    '<main><div class="caption" style="display:none;font-size:90%"></div>'
    . '<div id="runtime-panel" style="display:none"></div>'
    . '<div id="anchor-panel" style="display:none"></div>'
    . '<div class="responsive-panel" style="display:none"></div></main>',
    array(
        'runtime_dom_selectors' => array('#runtime-panel'),
        'static_css' => '@media (min-width:600px){.responsive-panel{display:block}}',
    )
)->toArray();
$assert(null === $findBlockByClass($hiddenEmptyResult['blocks'], 'caption'), 'inert hidden empty elements are pruned instead of becoming empty groups');
$assert(is_array($findBlockByClass($hiddenEmptyResult['blocks'], 'responsive-panel')), 'responsive-revealed hidden empty elements remain available at their visible breakpoint');
$assert(str_contains($hiddenEmptyResult['serialized_blocks'], 'id="runtime-panel"') && str_contains($hiddenEmptyResult['serialized_blocks'], 'id="anchor-panel"'), 'runtime-targeted and anchored hidden empty elements preserve their identifiers');

$emptyFeatureShellResult = (new HtmlTransformer())->transform(
    '<header><div class="empty-search-shell"><div class="container"><span></span></div></div>'
    . '<div class="mini-cart"></div><div class="real-search-shell"><input type="search" aria-label="Search"></div>'
    . '<div class="cart-status">2 items</div><div id="runtime-cart" class="cart"></div></header>',
    array('runtime_dom_selectors' => array('#runtime-cart'))
)->toArray();
$emptyFeatureShellSerialized = (string) ($emptyFeatureShellResult['serialized_blocks'] ?? '');
$assert(! str_contains($emptyFeatureShellSerialized, 'empty-search-shell'), 'empty search chrome and its wrapper subtree are pruned');
$assert(! str_contains($emptyFeatureShellSerialized, 'mini-cart'), 'empty cart chrome is pruned instead of becoming an empty group');
$assert(str_contains($emptyFeatureShellSerialized, 'real-search-shell') && str_contains($emptyFeatureShellSerialized, 'aria-label="Search"'), 'a real search control remains on its existing safe conversion path');
$assert(str_contains($emptyFeatureShellSerialized, '2 items'), 'cart chrome carrying visible state remains authored content');
$assert(str_contains($emptyFeatureShellSerialized, 'runtime-cart'), 'runtime-bound empty cart shells remain available to their behavior owner');

$layoutFeatureShellResult = (new HtmlTransformer())->transform(
    '<header class="toolbar"><div class="empty-search-shell"></div><nav><a href="/">Home</a></nav><div class="mini-cart"></div></header>',
    array('static_css' => '.toolbar{display:flex;justify-content:space-between}.mini-cart{display:inline-block;vertical-align:middle}')
)->toArray();
$layoutFeatureShellSerialized = (string) ($layoutFeatureShellResult['serialized_blocks'] ?? '');
$assert(str_contains($layoutFeatureShellSerialized, 'empty-search-shell'), 'empty feature chrome participating in author-owned layout remains available to preserve sibling placement');
$assert(str_contains($layoutFeatureShellSerialized, 'mini-cart'), 'empty inline feature chrome with explicit vertical alignment remains available to preserve the inline baseline');

$runtimeGeometryResult = (new HtmlTransformer())->transform(
    '<main><div id="runtime-geometry" style="width:290px !important;height:62px !important"></div></main>',
    array('runtime_dom_selectors' => array('#runtime-geometry'))
)->toArray();
$findBlockByAnchor = static function (array $blocks, string $anchor) use (&$findBlockByAnchor): ?array {
    foreach ($blocks as $block) {
        if (! is_array($block)) continue;
        if ($anchor === ($block['attrs']['anchor'] ?? '')) return $block;
        $found = $findBlockByAnchor($block['innerBlocks'] ?? array(), $anchor);
        if (null !== $found) return $found;
    }
    return null;
};
$runtimeGeometryBlock = $findBlockByAnchor($runtimeGeometryResult['blocks'], 'runtime-geometry');
$runtimeGeometryCss = implode("\n", array_column(array_filter($runtimeGeometryResult['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
$assert(is_array($runtimeGeometryBlock) && 'core/group' === ($runtimeGeometryBlock['blockName'] ?? ''), 'runtime-targeted empty geometry remains represented as a Group');
$assert(! str_contains((string) ($runtimeGeometryBlock['innerHTML'] ?? ''), 'style='), 'runtime Group saved markup does not duplicate arbitrary geometry that core save cannot reproduce');
$assert(str_contains($runtimeGeometryCss, 'width:290px !important') && str_contains($runtimeGeometryCss, 'height:62px !important'), 'runtime Group geometry remains preserved by its generated carrier stylesheet');
$assert(! str_contains($runtimeGeometryCss, '!important !important'), 'runtime Group carrier emits valid priority for source-important geometry');

fwrite(STDOUT, "Canonical block style attributes contract passed.\n");

fwrite(STDOUT, "HTML-to-blocks contract passed.\n");

$bridge = new FormatBridge();

assertSame(array( 'blocks', 'html', 'markdown' ), $bridge->supportedFormats(), 'Default supported formats should be stable for adapter authors.');
assertSame(true, $bridge->supports('html'), 'Format bridge should expose adapter support checks.');
assertSame(false, $bridge->supports('xml'), 'Format bridge support checks should require a registered adapter.');
$markdownNormalizeResult = $bridge->convertResult("# Title\r\n\r\nBody\r\n", 'markdown', 'markdown')->toArray();
assertSame("# Title\n\nBody\n", $markdownNormalizeResult['documents'][0]['content'], 'Markdown line endings should normalize to LF through the result envelope.');
$htmlNormalizeResult = $bridge->convertResult('<main><h1>Hello</h1></main>', 'html', 'html')->toArray();
assertSame('<main><h1>Hello</h1></main>', $htmlNormalizeResult['documents'][0]['content'], 'HTML normalization should preserve valid HTML through the result envelope.');
$blocksNormalizeResult = $bridge->convertResult('<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->', 'blocks', 'blocks')->toArray();
assertSame('<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->', $blocksNormalizeResult['documents'][0]['content'], 'Serialized blocks should pass validation through the result envelope.');
$markdownToBlocksResult = $bridge->convertResult("# Title\n\nBody", 'markdown', 'blocks')->toArray();
assertSame('core/heading', $markdownToBlocksResult['blocks'][0]['blockName'], 'Markdown input should convert through the default markdown adapter.');
$blocksToHtmlResult = $bridge->convertResult('<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->', 'blocks', 'html')->toArray();
assertSame('<p>Hello</p>', $blocksToHtmlResult['documents'][0]['content'], 'Serialized blocks should render to HTML through the default blocks/html adapters.');
$markdownToHtmlResult = $bridge->convertResult("# Title\n\nBody", 'markdown', 'html')->toArray();
assertStringContains('<h1>Title</h1>', $markdownToHtmlResult['documents'][0]['content'], 'Markdown should convert directly to canonical HTML without block save-shape classes.');
assertSame(false, str_contains($markdownToHtmlResult['documents'][0]['content'], 'wp-block-heading'), 'Direct Markdown-to-HTML conversion should not round-trip through blocks.');
$htmlToMarkdownResult = $bridge->convertResult('<article><h2>Direct HTML</h2><p>Body with <strong>weight</strong>.</p></article>', 'html', 'markdown')->toArray();
assertStringContains("## Direct HTML\n\nBody with **weight**.", $htmlToMarkdownResult['documents'][0]['content'], 'HTML should convert directly to Markdown across the canonical HTML boundary.');
$blocksToMarkdownResult = $bridge->convertResult('<!-- wp:heading {"content":"Hello","level":1} --><h1>Hello</h1><!-- /wp:heading -->', 'blocks', 'markdown')->toArray();
assertStringContains('# Hello', $blocksToMarkdownResult['documents'][0]['content'], 'Serialized blocks should convert to markdown through rendered HTML.');
$htmlToBlocksResult = $bridge->convertResult('<h2>Hello</h2>', 'html', 'blocks')->toArray();
assertSame('success', $htmlToBlocksResult['status'], 'Format bridge result conversion should succeed for public default adapters.');
assertSame('blocks-engine/php-transformer/result/v1', $htmlToBlocksResult['schema'], 'Format bridge result conversion should use the shared result envelope.');
assertSame('core/heading', $htmlToBlocksResult['blocks'][0]['blockName'], 'Format bridge result conversion should expose block arrays.');
assertStringContains('<!-- wp:heading {"level":2} -->', $htmlToBlocksResult['serialized_blocks'], 'Format bridge result conversion should expose serialized blocks for block targets.');
assertSame('blocks', $htmlToBlocksResult['documents'][0]['format'], 'Format bridge result conversion should expose target document format.');
$htmlAssetResult = $bridge->convertResult('<style>.logo{display:inline-flex}</style><a class="logo" href="/"><span class="logo-mark"></span><span>Logo</span></a>', 'html', 'blocks')->toArray();
$htmlAssetCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $htmlAssetResult['assets'] ?? array()));
assertSame('core/paragraph', $htmlAssetResult['blocks'][0]['blockName'] ?? '', 'HTML format conversion should keep a classed-span text logo on the paragraph path.');
assertStringContains('.logo{display:inline-flex}', $htmlAssetCss, 'HTML format conversion should preserve generated author stylesheet assets.');
assertSame('blocks-engine/php-transformer/wp-block-validity-report/v1', $htmlAssetResult['source_reports']['wp_block_validity']['schema'] ?? '', 'HTML format conversion should preserve source transformer reports.');
$strictHtmlResult = $bridge->convertResult(
    '<main><applet code="clock.class"></applet></main>',
    'html',
    'blocks',
    array('context' => array('strict' => true, 'allow_fallbacks' => false))
)->toArray();
assertSame('failed', $strictHtmlResult['status'], 'Strict unsupported HTML conversion should remain failed through the format bridge.');
assertSame(count($strictHtmlResult['diagnostics']), $strictHtmlResult['metrics']['diagnostic_count'], 'HTML bridge diagnostics metric should include the completion diagnostic.');
assertSame($strictHtmlResult['metrics'], $strictHtmlResult['source_reports']['conversion_report']['metrics'], 'HTML bridge conversion-report metrics should match top-level metrics.');
$nestedHtml = '<main><h2>Nested</h2><p>Content</p></main>';
$nestedHtmlTransformerResult = ( new HtmlTransformer() )->transform($nestedHtml)->toArray();
$nestedHtmlBridgeResult = $bridge->convertResult($nestedHtml, 'html', 'blocks')->toArray();
$assert($nestedHtmlBridgeResult['metrics']['block_count'] > count($nestedHtmlBridgeResult['blocks']), 'HTML bridge metrics should recursively count nested blocks.');
assertSame($nestedHtmlTransformerResult['metrics']['block_count'], $nestedHtmlBridgeResult['metrics']['block_count'], 'HTML bridge should preserve the transformer recursive block count.');
$unsupportedSourceResult = $bridge->convertResult('<p>Hello</p>', 'xml', 'html')->toArray();
assertSame('failed', $unsupportedSourceResult['status'], 'Unsupported source formats should fail through diagnostics.');
assertSame('unsupported_source_format', $unsupportedSourceResult['diagnostics'][0]['code'], 'Unsupported source diagnostics should identify the source format.');
$unsupportedTargetResult = $bridge->convertResult('<p>Hello</p>', 'html', 'xml')->toArray();
assertSame('failed', $unsupportedTargetResult['status'], 'Unsupported target formats should fail through diagnostics.');
assertSame('unsupported_target_format', $unsupportedTargetResult['diagnostics'][0]['code'], 'Unsupported target diagnostics should identify the target format.');
assertThrows(static fn () => $bridge->normalize('<!-- wp:paragraph /-->', 'markdown'), 'Declared markdown content contains serialized block comments.');
assertThrows(static fn () => $bridge->normalize("# Title\n<p>Hello</p>", 'html'), 'Declared HTML content contains markdown markers.');
assertThrows(static fn () => $bridge->normalize('<p>Hello</p>', 'blocks'), 'Declared blocks content does not contain serialized block comments.');
assertThrows(static fn () => $bridge->normalize('<!-- wp:paragraph --><p>Hello</p>', 'blocks'), 'Serialized block markup contains an unclosed block comment.');
assertThrows(static fn () => $bridge->normalize('<!-- wp:paragraph --><p>Hello</p><!-- /wp:heading -->', 'blocks'), 'Mismatched serialized block closing comment.');
assertThrows(static fn () => $bridge->convert('<p>Hello</p>', 'html', 'xml'), 'No format adapter is registered for format "xml".');

$bridge->registerAdapter(new class implements FormatAdapterInterface {
    public function slug(): string
    {
        return 'plain';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toBlocks(string $content, array $options = array()): array
    {
        return array(
            array(
                'blockName'    => 'core/paragraph',
                'attrs'        => array(),
                'innerBlocks'  => array(),
                'innerHTML'    => '<p>' . $content . '</p>',
                'innerContent' => array( '<p>' . $content . '</p>' ),
            ),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    public function fromBlocks(array $blocks, array $options = array()): string
    {
        return 'plain output';
    }

    public function detect(string $content): bool
    {
        return '' !== trim($content);
    }
});

assertSame(array( 'blocks', 'html', 'markdown', 'plain' ), $bridge->supportedFormats(), 'Registered adapters should extend supported formats.');
$plainResult = $bridge->convertResult('<p>Hello</p>', 'html', 'plain')->toArray();
assertSame('plain output', $plainResult['documents'][0]['content'], 'Conversion stubs should hand block pivot to registered target adapters.');

$optionCalls = array();
$bridge->registerAdapter(new class($optionCalls) implements FormatAdapterInterface {
    /**
     * @param array<int, array<string, mixed>> $calls
     */
    public function __construct(private array &$calls)
    {
    }

    public function slug(): string
    {
        return 'optioned';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function toBlocks(string $content, array $options = array()): array
    {
        $this->calls[] = array('method' => 'toBlocks', 'options' => $options);

        return array(
            'sparse-key' => array(
                'blockName'    => 'core/paragraph',
                'attrs'        => array('content' => $options['marker'] ?? ''),
                'innerBlocks'  => array(),
                'innerHTML'    => '<p>' . $content . '</p>',
                'innerContent' => array('<p>' . $content . '</p>'),
            ),
        );
    }

    /**
     * @param array<int|string, array<string, mixed>> $blocks
     */
    public function fromBlocks(array $blocks, array $options = array()): string
    {
        $this->calls[] = array('method' => 'fromBlocks', 'options' => $options, 'block_keys' => array_keys($blocks));

        return (string) ($options['marker'] ?? '');
    }

    public function detect(string $content): bool
    {
        return '' !== trim($content);
    }
});

$optionedBlocks = $bridge->toBlocks('Optioned', 'optioned', array('marker' => 'forwarded'));
assertSame(array(0), array_keys($optionedBlocks), 'FormatBridge::toBlocks should return list-shaped block arrays.');
$optionedResult = $bridge->convertResult('Optioned', 'optioned', 'plain', array('marker' => 'forwarded'))->toArray();
assertSame('forwarded', $optionedResult['blocks'][0]['attrs']['content'], 'convertResult should forward options to source adapters.');
assertSame(2, count($optionCalls), 'convertResult should not call source adapters more than once after explicit toBlocks use.');
assertSame('toBlocks', $optionCalls[1]['method'], 'convertResult should use the source adapter directly for the block pivot.');
assertSame(array('marker' => 'forwarded'), $optionCalls[1]['options'], 'convertResult should preserve option arrays.');

$contextualBridgeResult = $bridge->convertResult(
    '<h2>Context</h2>',
    'html',
    'blocks',
    array(
        'context' => array(
            'strict'          => true,
            'allow_fallbacks' => false,
        ),
        'provenance' => array(
            'source' => 'fixture:format-bridge',
            'scope'  => 'contract-test',
        ),
    )
)->toArray();
assertSame(array('strict' => true, 'allow_fallbacks' => false), $contextualBridgeResult['context'], 'convertResult should expose normalized context flags.');
assertSame('fixture:format-bridge', $contextualBridgeResult['provenance'][0]['source'], 'convertResult should expose generic provenance source metadata.');
assertSame('contract-test', $contextualBridgeResult['provenance'][0]['scope'], 'convertResult should expose generic provenance scope metadata.');

$descriptionListArtifact = ( new ArtifactCompiler() )->compile(array(
    'site' => array( 'slug' => 'description-lists' ),
    'files' => array(
        'index.html' => '<main><dl><dt>Home</dt><dd>Primary</dd></dl></main>',
        'contact.html' => '<main><dl><dt>Office</dt><dd>North Hall</dd></dl></main>',
        'about.html' => '<main><dl><dt>Office</dt><dd>North Hall</dd></dl></main>',
    ),
))->toArray();
$descriptionListPayload = $descriptionListArtifact['source_reports']['companion_plugin_payload'] ?? array();
$descriptionListBlocks = $descriptionListPayload['blocks'] ?? array();
$assert(1 === count($descriptionListBlocks), 'multi-page description lists project one deduplicated companion definition');
$assert('blocks-engine/description-list' === ($descriptionListBlocks[0]['block_json']['name'] ?? null), 'companion payload projects the generated description-list block metadata');
$assert(str_contains((string) ($descriptionListBlocks[0]['assets']['index.js'] ?? ''), 'registerBlockType'), 'companion payload projects the installable editor asset');
$assert(array( 'index.js' => array( 'wp-blocks', 'wp-block-editor', 'wp-element' ) ) === ($descriptionListBlocks[0]['script_dependencies'] ?? null), 'description-list companion dependency metadata survives payload compilation');
$assert('semantic-description-list' === ($descriptionListArtifact['source_reports']['gutenberg_gaps'][0]['id'] ?? null), 'multi-page artifacts aggregate the Gutenberg gap once');
$assert('https://github.com/WordPress/gutenberg/pull/20760' === ($descriptionListArtifact['source_reports']['gutenberg_gaps'][0]['references'][1] ?? null), 'gap diagnostic records the stalled Gutenberg implementation context');

fwrite(STDOUT, "Format bridge scaffold passed.\n");

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ( $expected !== $actual ) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assertContains(mixed $needle, array $haystack, string $message): void
{
    if ( ! in_array($needle, $haystack, true) ) {
        fwrite(STDERR, $message . "\nNeedle: " . var_export($needle, true) . "\nHaystack: " . var_export($haystack, true) . "\n");
        exit(1);
    }
}

function assertStringContains(string $needle, string $haystack, string $message): void
{
    if ( ! str_contains($haystack, $needle) ) {
        fwrite(STDERR, $message . "\nNeedle: " . var_export($needle, true) . "\nHaystack: " . var_export($haystack, true) . "\n");
        exit(1);
    }
}

function assertThrows(callable $callback, string $expectedMessage): void
{
    try {
        $callback();
    } catch ( \InvalidArgumentException $exception ) {
        if ( $expectedMessage === $exception->getMessage() ) {
            return;
        }

        fwrite(STDERR, "Unexpected exception message.\n");
        fwrite(STDERR, 'Expected: ' . $expectedMessage . "\n");
        fwrite(STDERR, 'Actual: ' . $exception->getMessage() . "\n");
        exit(1);
    }

    fwrite(STDERR, 'Expected exception: ' . $expectedMessage . "\n");
    exit(1);
}
