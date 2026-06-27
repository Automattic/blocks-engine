<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use Automattic\BlocksEngine\PhpTransformer\Contract\VisualParityReportContract;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactNormalizer;
use Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\ReferenceAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\FormatBridge\FormatAdapterInterface;
use Automattic\BlocksEngine\PhpTransformer\FormatBridge\FormatBridge;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternRecognizerInterface;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternRecognizerRegistry;
use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;
use Automattic\BlocksEngine\PhpTransformer\StaticSite\FontMaterialization\FontMaterializationPlanBuilder;
use Automattic\BlocksEngine\PhpTransformer\StaticSite\MaterializationView;
use Automattic\BlocksEngine\PhpTransformer\StaticSite\MaterializationPlanBuilder;

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

            $serialized .= '<!-- wp:' . substr($name, 5) . $attrs . ' -->' . $inner . '<!-- /wp:' . substr($name, 5) . ' -->';
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
    'findings'              => array(
        array('id' => 'style-button-radius', 'severity' => 'warning', 'category' => 'style', 'summary' => 'Button radius changed.', 'kind' => 'button', 'recommendation_ids' => array('rec-button-radius')),
    ),
    'recommendations'       => array(
        array('id' => 'rec-button-radius', 'priority' => 'medium', 'summary' => 'Align target button radius with the source button treatment.', 'finding_ids' => array('style-button-radius')),
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

$assert('assets/logo.png' === ArtifactPath::safeRelativePath(' ./assets//logo.png '), 'artifact paths trim relative markers and duplicate separators');
$assert('' === ArtifactPath::safeRelativePath('/assets/logo.png'), 'artifact paths reject root-absolute paths');
$assert('' === ArtifactPath::safeRelativePath('C:\\assets\\logo.png'), 'artifact paths reject drive-absolute paths');
$assert('' === ArtifactPath::safeRelativePath('../secrets/logo.png'), 'artifact paths reject traversal paths');
$assert('assets/logo.png' === ArtifactPath::resolveRelativePath('../assets/logo.png?version=1#hash', 'pages/home.html'), 'artifact references resolve relative paths without query or fragment');
$assert('' === ArtifactPath::resolveRelativePath('https://example.com/logo.png', 'pages/home.html'), 'artifact references reject URL references');
$assert('' === ArtifactPath::resolveRelativePath('../../logo.png', 'pages/home.html'), 'artifact references reject traversal above the artifact root');

$registryDocument = new DOMDocument();
$registryDocument->loadHTML('<div></div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
$registryElement = $registryDocument->getElementsByTagName('div')->item(0);
$registry = new PatternRecognizerRegistry(array(
    new class implements PatternRecognizerInterface {
        public function match(DOMElement $element, PatternContext $context): ?array
        {
            return 'div' === strtolower($element->tagName) ? array('blockName' => 'core/group') : null;
        }
    },
));
$registryContext = new PatternContext(
    static fn (DOMElement $element): array => array(),
    static fn (DOMElement $element): string => '',
    static fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => array('blockName' => $name, 'attrs' => $attrs, 'innerBlocks' => $innerBlocks)
);
$assert($registryElement instanceof DOMElement, 'pattern registry fixture element parses');
$assert('core/group' === ($registry->firstMatch($registryElement, $registryContext)['blockName'] ?? null), 'pattern registry returns the first recognizer match');

$navigationResult = ( new HtmlTransformer() )->transform('<nav class="primary"><a href="/about">About</a><a href="/contact">Contact</a></nav>')->toArray();
$navigationBlock = $navigationResult['blocks'][0] ?? array();
$assert('core/navigation' === ($navigationBlock['blockName'] ?? null), 'navigation conversion still emits a navigation block');
$assert(2 === count($navigationBlock['innerBlocks'] ?? array()), 'navigation conversion still preserves direct navigation links');
$assert('About' === ($navigationBlock['innerBlocks'][0]['attrs']['label'] ?? null), 'navigation conversion still preserves link labels');
$assert('/about' === ($navigationBlock['innerBlocks'][0]['attrs']['url'] ?? null), 'navigation conversion still preserves link URLs');

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
$runtimeCanvasResult = ( new HtmlTransformer() )->transform('<main><canvas id="fixture-canvas">Fallback</canvas></main>', array('runtime_canvas_selectors' => array('#fixture-canvas')))->toArray();
$assert('canvas' === ($runtimeCanvasResult['source_reports']['runtime_islands'][0]['kind'] ?? ''), 'HTML transform reports runtime-targeted canvas fallback as a runtime island');
$assert('canvas_requires_runtime' === ($runtimeCanvasResult['source_reports']['runtime_islands'][0]['preservation_reason'] ?? ''), 'runtime island exposes canvas preservation reason');
$assert(str_contains((string) ($runtimeCanvasResult['source_reports']['runtime_islands'][0]['source_snippet'] ?? ''), '<canvas id="fixture-canvas">Fallback</canvas>'), 'runtime island exposes bounded source snippet');
$assert($runtimeCanvasResult['source_reports']['runtime_islands'] === ($runtimeCanvasResult['source_reports']['conversion_report']['runtime_islands'] ?? array()), 'conversion report projects runtime islands');

$invalidStatus = $result;
$invalidStatus['status'] = 'ok';
$assertInvalidCanonicalEnvelope($invalidStatus, 'unsupported status', 'canonical validation rejects unsupported status values');

$invalidConversionReport = $result;
$invalidConversionReport['source_reports']['conversion_report']['source_format'] = '';
$assertInvalidCanonicalEnvelope($invalidConversionReport, 'source_format', 'canonical validation rejects conversion reports without a source format');

$missingConversionReport = $result;
unset($missingConversionReport['source_reports']['conversion_report']);
$assertInvalidCanonicalEnvelope($missingConversionReport, 'source_reports.conversion_report', 'canonical validation rejects results without conversion reports');

$assertInvalidCanonicalEnvelope($result, 'source_reports.materialization_plan', 'canonical validation can require materialization plans for downstream artifact consumers', true);

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
$assert('failed' === $contextual['status'], 'strict HTML transform fails when fallbacks are disallowed', (string) $contextual['status']);
$assert(true === ($contextual['context']['strict'] ?? null), 'HTML transform context exposes strict mode');
$assert(false === ($contextual['context']['allow_fallbacks'] ?? null), 'HTML transform context exposes fallback policy');
$assert('fixture:contextual-html' === ($contextual['provenance'][0]['source'] ?? ''), 'HTML provenance exposes generic source metadata');
$assert('contract-test' === ($contextual['provenance'][0]['scope'] ?? ''), 'HTML provenance exposes generic scope metadata');

$formFallback = ( new HtmlTransformer() )->transform(
    '<main><form action="/contact" method="post" data-action="contact-submit"><label for="email">Email</label><input id="email" name="email" type="email" required><select name="topic"><option value="support" selected>Support</option></select><button type="submit">Send</button></form></main>'
)->toArray();
$formDiagnostic = $formFallback['source_reports']['conversion_report']['fallback_diagnostics'][0] ?? array();
$formFallbackBlocks = $formFallback['blocks'][0]['innerBlocks'] ?? array();
$assert('core/group' === ($formFallback['blocks'][0]['blockName'] ?? ''), 'form fallback materializes readable control blocks');
$assert('core/paragraph' === ($formFallbackBlocks[0]['blockName'] ?? ''), 'form fallback exposes readable input text');
$assert('core/group' === ($formFallbackBlocks[1]['blockName'] ?? ''), 'form fallback exposes readable select options');
$assert('core/buttons' === ($formFallbackBlocks[2]['blockName'] ?? ''), 'form fallback exposes readable submit button');
$assertNormalizedFallbackDiagnostic($formDiagnostic, 'html_form_fallback', 'warning', 'server_or_client_form_handler', 'form');
$assert('form' === ($formFallback['source_reports']['interaction_candidates'][0]['kind'] ?? ''), 'HTML source report exposes form interaction candidate');
$assert('form' === ($formFallback['source_reports']['conversion_report']['interaction_candidates'][0]['kind'] ?? ''), 'conversion report projects interaction candidates');
$assert('/contact' === ($formFallback['source_reports']['interaction_candidates'][0]['target'] ?? ''), 'form interaction candidate exposes action target');
$assert('html_form_fallback' === ($formDiagnostic['diagnostic_code'] ?? ''), 'conversion report exposes form fallback diagnostic code');
$assert('/contact' === ($formDiagnostic['form']['action'] ?? ''), 'conversion report exposes form action metadata');
$assert('post' === ($formDiagnostic['form']['method'] ?? ''), 'conversion report exposes normalized form method metadata');
$assert(3 === ($formDiagnostic['control_count'] ?? null), 'conversion report exposes form control count');
$assert('email' === ($formDiagnostic['controls'][0]['name'] ?? ''), 'conversion report exposes form control names');
$assert('Email' === ($formDiagnostic['controls'][0]['label'] ?? ''), 'conversion report exposes form control labels');
$assert(true === ($formDiagnostic['controls'][0]['required'] ?? null), 'conversion report exposes required form controls');
$assert('support' === ($formDiagnostic['controls'][1]['options'][0]['value'] ?? ''), 'conversion report exposes select option values');
$assert(is_int($formDiagnostic['html_bytes'] ?? null), 'conversion report exposes bounded fallback HTML byte size');

$rangeControlResult = ( new HtmlTransformer() )->transform(
    '<main><section><label for="density">Density</label><input type="range" id="density" min="6" max="60" step="2" value="28"></section></main>'
)->toArray();
$rangeControlText = (string) ($rangeControlResult['blocks'][0]['innerBlocks'][1]['attrs']['content'] ?? '');
$assert(array() === ($rangeControlResult['fallbacks'] ?? array()), 'standalone readable range input converts without unsupported-element fallback');
$assert(str_contains($rangeControlText, 'Density: 28'), 'range input summary preserves current value');
$assert(str_contains($rangeControlText, 'min 6, max 60, step 2'), 'range input summary preserves bounds');

$standaloneControls = ( new HtmlTransformer() )->transform(
    '<main><input id="donation" type="number" aria-label="Custom donation amount" placeholder="Enter amount"><select aria-label="Sort products"><option selected>Featured</option><option>Price: Low to High</option></select><select class="js-sort-select" aria-label="Runtime sort"><option>Newest</option></select></main>',
    array('runtime_dom_selectors' => array('.js-sort-select'))
)->toArray();
$standaloneControlBlocks = $standaloneControls['blocks'][0]['innerBlocks'] ?? array();
$assert(array() === ($standaloneControls['fallbacks'] ?? array()), 'standalone readable controls convert without unsupported-element fallback');
$assert('core/paragraph' === ($standaloneControlBlocks[0]['blockName'] ?? ''), 'standalone non-runtime input converts to readable paragraph');
$assert('core/list' === ($standaloneControlBlocks[1]['innerBlocks'][1]['blockName'] ?? ''), 'standalone non-runtime select options convert to readable list');
$assert('core/list' === ($standaloneControlBlocks[2]['innerBlocks'][1]['blockName'] ?? ''), 'runtime-targeted select converts to readable list output');
$assert(str_contains((string) ($standaloneControls['serialized_blocks'] ?? ''), 'Featured (selected)'), 'readable select summary preserves selected option state');
$assert(str_contains((string) ($standaloneControls['serialized_blocks'] ?? ''), 'Runtime sort'), 'runtime-targeted select readable output preserves label text');
$assert(str_contains((string) ($standaloneControls['serialized_blocks'] ?? ''), 'id="donation"'), 'readable input output preserves source id as a block anchor');
$assert(! str_contains((string) ($standaloneControls['serialized_blocks'] ?? ''), 'js-sort-select'), 'readable select output omits behavior-hook classes from generated blocks');
$assert(! str_contains((string) ($standaloneControls['serialized_blocks'] ?? ''), '<select class="js-sort-select"'), 'runtime-targeted select native markup is preserved in runtime metadata instead of serialized blocks');
$assert(1 === count($standaloneControls['source_reports']['runtime_islands'] ?? array()), 'runtime islands report only the explicitly runtime-targeted standalone control');
$assert('control' === ($standaloneControls['source_reports']['runtime_islands'][0]['kind'] ?? ''), 'runtime-targeted standalone control reports as a control island');
$assert('.js-sort-select' === ($standaloneControls['source_reports']['runtime_islands'][0]['selector'] ?? ''), 'runtime-targeted standalone control reports selector metadata');
$assert('select' === ($standaloneControls['source_reports']['runtime_islands'][0]['control']['tag'] ?? ''), 'runtime-targeted standalone control reports control metadata');
$assert(str_contains((string) ($standaloneControls['source_reports']['runtime_islands'][0]['source_snippet'] ?? ''), '<select class="js-sort-select"'), 'runtime-targeted standalone control preserves source snippet metadata');

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
$assert(! str_contains($artifactControlMarkup, '<select id="sort-select"'), 'artifact compiler converts generically queried static select to readable block output');
$assert(str_contains($artifactControlMarkup, 'you@example.com'), 'artifact static input readable output preserves placeholder text');
$assert(str_contains($artifactControlMarkup, 'Featured (selected)'), 'artifact static select readable output preserves selected option state');
$assert(! str_contains($artifactControlMarkup, '<input id="live-filter"'), 'artifact compiler preserves behavior-bearing control native DOM in runtime metadata instead of serialized blocks');
$assert(str_contains($artifactControlMarkup, 'Filter'), 'artifact behavior-bearing control readable output preserves placeholder text');
$assert(str_contains($artifactControlMarkup, 'id="newsletter-email"'), 'artifact readable static input preserves source id as a block anchor');
$assert(str_contains($artifactControlMarkup, 'sort-select'), 'artifact readable static select preserves source class on generated markup');
$assert(str_contains($artifactControlMarkup, 'id="live-filter"'), 'artifact readable runtime control preserves source id on generated markup');
$artifactControlIslands = $artifactControlSelectors['source_reports']['runtime_islands'] ?? array();
$assert(1 === count($artifactControlIslands), 'artifact compiler reports only behavior-bearing controls as runtime islands');
$assert('#live-filter' === ($artifactControlIslands[0]['selector'] ?? ''), 'artifact runtime control island points at behavior-bearing control selector');
$assert(str_contains((string) ($artifactControlIslands[0]['source_snippet'] ?? ''), '<input id="live-filter"'), 'artifact runtime control island preserves source snippet metadata');
$artifactControlRuntimeReport = $artifactControlSelectors['source_reports']['runtime_dependency_parity'] ?? array();
$assert('pass' === ($artifactControlRuntimeReport['status'] ?? ''), 'runtime parity does not flag readable static controls as missing runtime targets');

$buttonResult = ( new HtmlTransformer() )->transform(
    '<main><a class="primary-button" href="#"><h3>Reserve now</h3><span aria-hidden="true"></span></a><button><strong>Call us</strong></button></main>'
)->toArray();
$buttonBlocks = $buttonResult['blocks'][0]['innerBlocks'] ?? array();
$assert('core/buttons' === ($buttonBlocks[0]['blockName'] ?? ''), 'anchor converts to buttons block');
$assert(str_contains((string) ($buttonBlocks[0]['innerBlocks'][0]['attrs']['text'] ?? ''), 'Reserve now'), 'anchor button text preserves visible label');
$assert('Reserve now' === ($buttonBlocks[0]['innerBlocks'][0]['attrs']['text'] ?? ''), 'anchor button text unwraps block-level label markup for valid inline RichText');
$assert(str_contains((string) ($buttonBlocks[1]['innerBlocks'][0]['attrs']['text'] ?? ''), 'Call us'), 'button text preserves visible label');
$assert(! str_contains((string) $buttonResult['serialized_blocks'], '\\u003c'), 'button serialization avoids escaped nested HTML attrs');
$assert(! str_contains((string) $buttonResult['serialized_blocks'], '<h3>Reserve now</h3>'), 'button serialization avoids block-level markup inside link text');
$assert('pass' === ($buttonResult['source_reports']['wp_block_validity']['status'] ?? ''), 'HTML transform exposes passing WordPress block validity report for generated buttons');

$rubyResult = ( new HtmlTransformer() )->transform(
    '<main><blockquote><ruby>翻訳<rt>ほんやく</rt></ruby> keeps pronunciation visible.</blockquote></main>'
)->toArray();
$rubyQuote = $rubyResult['blocks'][0] ?? array();
$assert(array() === ($rubyResult['fallbacks'] ?? array()), 'ruby phrasing content does not create unsupported fallbacks');
$assert('core/quote' === ($rubyQuote['blockName'] ?? ''), 'ruby phrasing content remains inside quote block');
$assert(str_contains((string) ($rubyResult['serialized_blocks'] ?? ''), '<ruby>翻訳<rt>ほんやく</rt></ruby>'), 'ruby markup is preserved in quote content');

$plaintextResult = ( new HtmlTransformer() )->transform(
    '<main><plaintext>Plain legacy text with &lt;b&gt;literal tags&lt;/b&gt;</plaintext></main>'
)->toArray();
$plaintextBlock = $plaintextResult['blocks'][0] ?? array();
$assert(array() === ($plaintextResult['fallbacks'] ?? array()), 'plaintext content does not create unsupported fallbacks');
$assert('core/preformatted' === ($plaintextBlock['blockName'] ?? ''), 'plaintext content converts to a preformatted block');
$assert(str_contains((string) ($plaintextBlock['innerHTML'] ?? ''), '&lt;b&gt;literal tags&lt;/b&gt;'), 'plaintext literal tags are escaped in preformatted content');

$linkedLogoResult = ( new HtmlTransformer() )->transform(
    '<main><a class="site-logo" href="/">Mara Vale</a></main>'
)->toArray();
$linkedLogoBlock = $linkedLogoResult['blocks'][0] ?? array();
$linkedLogoSerialized = (string) ($linkedLogoResult['serialized_blocks'] ?? '');
$assert('core/paragraph' === ($linkedLogoBlock['blockName'] ?? ''), 'linked logo text converts to a paragraph block');
$assert(! array_key_exists('content', is_array($linkedLogoBlock['attrs'] ?? null) ? $linkedLogoBlock['attrs'] : array()), 'paragraph source content is not serialized as a block comment attribute');
$assert(str_contains($linkedLogoSerialized, '<p class="site-logo"><a class="site-logo" href="/">Mara Vale</a></p>'), 'linked logo paragraph preserves anchor markup in saved HTML');
$assert(! str_contains($linkedLogoSerialized, '\\u003ca'), 'linked logo paragraph avoids raw anchor HTML in delimiter JSON');
$assert('pass' === ($linkedLogoResult['source_reports']['wp_block_validity']['status'] ?? ''), 'linked logo paragraph passes generated block validity checks');

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

$inlineSvgVisualWrapper = ( new HtmlTransformer() )->transform(
    '<main><section class="visual-region"><div class="map-layer"><div class="map-image" style="background-image:url(assets/map.png)"><svg><path d="M0 0h1v1z"></path></svg></div></div></section></main>'
)->toArray();
$serializedInlineSvgVisualWrapper = (string) ($inlineSvgVisualWrapper['serialized_blocks'] ?? '');
$assert(str_contains($serializedInlineSvgVisualWrapper, 'visual-region'), 'HTML transform preserves CSS-addressable visual wrapper classes');
$assert(str_contains($serializedInlineSvgVisualWrapper, 'map-layer'), 'HTML transform preserves nested visual wrapper classes');
$assert(str_contains($serializedInlineSvgVisualWrapper, 'map-image'), 'HTML transform preserves background-image visual leaf classes when inline SVG children are present');

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
$assert(! str_contains($safeInlineSvgSerialized, '<!-- wp:html'), 'safe inline SVG conversion avoids raw HTML blocks');
$assert(! str_contains($safeInlineSvgSerialized, 'data:image/svg+xml,'), 'decorative inline SVG avoids image data URI noise');
$assert(! str_contains(rawurldecode($safeInlineSvgSerialized), '<svg'), 'decorative inline SVG markup is omitted from serialized blocks');

$safeInlineSvgAsset = ( new HtmlTransformer() )->transform(
    '<svg role="img" aria-label="Status badge" viewBox="0 0 10 10"><title>Status badge</title><circle cx="5" cy="5" r="4"></circle></svg>'
)->toArray();
$safeInlineSvgAssetPath = (string) ($safeInlineSvgAsset['blocks'][0]['attrs']['url'] ?? '');
$assert(str_starts_with($safeInlineSvgAssetPath, 'assets/inline-svg-'), 'safe accessible inline SVG image references a generated SVG asset');
$assert('image/svg+xml' === ($safeInlineSvgAsset['assets'][0]['mime_type'] ?? ''), 'safe accessible inline SVG exposes a materializable SVG asset');
$assert(str_contains((string) ($safeInlineSvgAsset['assets'][0]['content'] ?? ''), 'aria-label="Status badge"'), 'safe accessible inline SVG asset preserves accessible label');
$assert(! str_contains((string) ($safeInlineSvgAsset['serialized_blocks'] ?? ''), 'data:image/svg+xml,'), 'safe accessible inline SVG avoids data URI serialization');

$unsafeInlineSvg = ( new HtmlTransformer() )->transform('<main><svg onload="alert(1)"><path d="M0 0h1v1z"></path></svg></main>')->toArray();
$assert('html_unsafe_inline_svg' === ($unsafeInlineSvg['fallbacks'][0]['diagnostic_code'] ?? ''), 'unsafe inline SVG remains a fallback diagnostic');

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
    '<header><nav class="main-nav" aria-label="Main navigation"><div class="nav-item"><a href="/shop" class="nav-link">Shop All</a></div><div class="nav-item"><a href="/outing" class="nav-link">By Outing <svg aria-hidden="true"><path d="M0 0h1v1z"></path></svg></a><div class="dropdown"><a href="/outing#day" class="dropdown__link">Day Hike</a><a href="/outing#camp" class="dropdown__link">Weekend Camp</a></div></div><div class="nav-item"><a href="/bundles" class="nav-link">Bundles</a></div></nav></header>'
)->toArray();
$dropdownHeaderParity = $dropdownHeaderNavigation['source_reports']['semantic_parity'] ?? array();
$dropdownHeaderBlockMenu = $dropdownHeaderParity['navigation_menus']['blocks'][0] ?? array();
$assert('pass' === ($dropdownHeaderParity['status'] ?? ''), 'dropdown header nav wrappers preserve semantic parity');
$assert(5 === ($dropdownHeaderBlockMenu['item_count'] ?? null), 'dropdown header nav counts parent and submenu items consistently');
$assert('Day Hike' === ($dropdownHeaderBlockMenu['items'][2]['label'] ?? ''), 'dropdown header nav preserves submenu item labels');

$runtimeTargetNavigation = ( new HtmlTransformer() )->transform(
    '<nav aria-label="Docs"><ul><li><a class="nav-link" href="/guide">Guide</a></li></ul></nav>',
    array('runtime_dom_selectors' => array('.nav-link'))
)->toArray();
$runtimeTargetNavigationSerialized = (string) ($runtimeTargetNavigation['serialized_blocks'] ?? '');
$runtimeTargetNavigationItemAttrs = $runtimeTargetNavigation['blocks'][0]['innerBlocks'][0]['attrs'] ?? array();
$assert('nav-link' === ($runtimeTargetNavigationItemAttrs['className'] ?? ''), 'runtime-target navigation link classes are preserved on navigation item attrs');
$assert(str_contains($runtimeTargetNavigationSerialized, '<li class="wp-block-navigation-item wp-block-navigation-link nav-link">'), 'runtime-target navigation link classes are exposed on navigation item markup');

$headerCluster = ( new HtmlTransformer() )->transform(
    '<header class="site-header"><a class="site-logo" href="/">Acme Lab</a><nav class="primary-nav" aria-label="Primary"><a class="nav-link" href="/work">Work</a><a class="nav-link" href="/docs"><span>Docs</span></a></nav><form class="site-search" role="search" action="/search"><label for="q">Search</label><input id="q" type="search" name="q" placeholder="Search docs"><button type="submit">Search</button></form><div class="header-actions"><a class="cta" href="/start">Get started</a></div></header>'
)->toArray();
$headerClusterSerialized = (string) ($headerCluster['serialized_blocks'] ?? '');
$headerClusterParity = $headerCluster['source_reports']['semantic_parity'] ?? array();
$assert('pass' === ($headerClusterParity['status'] ?? ''), 'header logo/nav/search/CTA clusters preserve source navigation semantic parity');
$assert(str_contains($headerClusterSerialized, 'site-logo'), 'header cluster preserves logo link wrapper');
$assert(str_contains($headerClusterSerialized, 'nav-link'), 'header cluster preserves nav link class target');
$assert(str_contains($headerClusterSerialized, '<!-- wp:search'), 'header cluster converts search form to core/search');
$assert(str_contains($headerClusterSerialized, '<!-- wp:buttons'), 'header cluster converts CTA action to buttons');

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
$scriptRuntimeIslands = array_values(array_filter($normalizedFallbacks['source_reports']['runtime_islands'] ?? array(), static fn (array $island): bool => 'script' === ($island['kind'] ?? '')));
$assert(1 === count($scriptRuntimeIslands), 'runtime script fallback projects as a runtime island');
$assert('script_requires_runtime' === ($scriptRuntimeIslands[0]['preservation_reason'] ?? ''), 'runtime script island exposes preservation reason');
$preservedRuntimeDiagnostics = array_values(array_filter($normalizedFallbacks['diagnostics'] ?? array(), static fn (array $diagnostic): bool => 'preserved_runtime_island' === ($diagnostic['code'] ?? '')));
$assert(1 <= count($preservedRuntimeDiagnostics), 'runtime script fallback emits preserved_runtime_island diagnostics');
$assert('runtime_island_preserved' === ($preservedRuntimeDiagnostics[0]['diagnostic_class'] ?? ''), 'preserved_runtime_island diagnostic exposes runtime-island diagnostic class');
$assertNormalizedFallbackDiagnostic($diagnosticsByCode['html_iframe_embed_fallback'] ?? array(), 'html_iframe_embed_fallback', 'warning', 'third_party_embed_runtime', 'embed');
$assert(! isset($diagnosticsByCode['html_inline_svg_fallback']), 'safe inline SVGs convert to image blocks instead of fallback diagnostics');
$assert(! isset($diagnosticsByCode['html_canvas_runtime_fallback']), 'non-runtime canvas does not emit runtime canvas fallback diagnostics');

$canvasFallback = ( new HtmlTransformer() )->transform(
    '<main><canvas id="bonsai" class="stage" width="640" height="360">Fallback</canvas><script src="/js/script.js"></script></main>',
    array('runtime_canvas_selectors' => array('#bonsai'))
)->toArray();
$canvasDiagnostic = $canvasFallback['source_reports']['conversion_report']['fallback_diagnostics'][0] ?? array();
$assertNormalizedFallbackDiagnostic($canvasDiagnostic, 'html_canvas_runtime_fallback', 'warning', 'canvas_element_and_client_script_execution', 'runtime_canvas', 'runtime_island_preserved');
$assert('runtime_island_preserved' === ($canvasDiagnostic['diagnostic_class'] ?? ''), 'canvas runtime fallback exposes preserved runtime island diagnostic class');
$assert('preserve_runtime_island' === ($canvasDiagnostic['suggested_repair_class'] ?? ''), 'canvas runtime fallback routes to runtime island preservation');
$assert('canvas_requires_runtime' === ($canvasDiagnostic['reason'] ?? ''), 'canvas fallback exposes runtime-specific reason');
$assert('bonsai' === ($canvasFallback['fallbacks'][0]['attributes']['id'] ?? ''), 'canvas fallback preserves id for runtime mapping');
$assert(str_contains((string) ($canvasFallback['fallbacks'][0]['html'] ?? ''), '<canvas id="bonsai"'), 'canvas fallback preserves bounded safe canvas markup');
$assert(str_contains((string) ($canvasDiagnostic['script_dependency_hint'] ?? ''), '#bonsai'), 'canvas diagnostic flags id-based script dependency risk');
$assert(str_contains((string) ($canvasFallback['serialized_blocks'] ?? ''), '<!-- wp:html'), 'runtime canvas emits bounded core/html preservation island');
$assert(str_contains((string) ($canvasFallback['serialized_blocks'] ?? ''), '<canvas id="bonsai"'), 'runtime canvas preserves native canvas markup for script execution');

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
$assert('runtime_island_preserved' === ($runtimeClassifications['canvas'] ?? ''), 'runtime-preserved canvas core/html block is classified as runtime island preservation');
$assert('runtime_island_preserved' === ($runtimeClassifications['input'] ?? ''), 'runtime-preserved control metadata is classified as runtime island preservation');
$runtimePreservedIslandKinds = array_map(static fn (array $island): string => (string) ($island['kind'] ?? ''), $runtimePreserved['source_reports']['runtime_islands'] ?? array());
$assert(in_array('dom', $runtimePreservedIslandKinds, true), 'runtime-preserved DOM target projects as a runtime island');
$runtimeSummary = $runtimePreserved['source_reports']['conversion_report']['conversion_classification_summary']['by_classification'] ?? array();
$assert(3 <= ($runtimeSummary['runtime_island_preserved'] ?? 0), 'conversion report summarizes runtime island preservation counts');

$unsupportedLoss = ( new HtmlTransformer() )->transform('<main><applet code="clock.class"></applet></main>')->toArray();
$unsupportedDiagnostic = $unsupportedLoss['source_reports']['conversion_report']['fallback_diagnostics'][0] ?? array();
$assert('html_unsupported_element' === ($unsupportedDiagnostic['diagnostic_code'] ?? ''), 'unsupported element emits fallback diagnostic');
$assert('unsupported_loss' === ($unsupportedDiagnostic['conversion_classification'] ?? ''), 'true unsupported fallback is classified as unsupported loss');

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
$assert(! str_contains((string) ($safeDecorativeSvg['serialized_blocks'] ?? ''), 'data:image/svg+xml,'), 'safe decorative inline SVGs do not serialize as image data URIs');
$assert(! str_contains(rawurldecode((string) ($safeDecorativeSvg['serialized_blocks'] ?? '')), '<svg'), 'safe decorative inline SVG markup is omitted');
$assert(str_contains((string) ($safeDecorativeSvg['serialized_blocks'] ?? ''), 'site-logo'), 'safe logo-like inline SVG context preserves its wrapper class');

$unsafeDecorativeSvg = ( new HtmlTransformer() )->transform(
    '<main><svg aria-hidden="true" viewBox="0 0 10 10"><script>alert(1)</script><circle onclick="alert(1)" cx="5" cy="5" r="5"></circle></svg></main>'
)->toArray();
$unsafeDecorativeDiagnostics = $unsafeDecorativeSvg['source_reports']['conversion_report']['fallback_diagnostics'] ?? array();
$unsafeDecorativeDiagnostic = $unsafeDecorativeDiagnostics[0] ?? array();
$assert(array() === ($unsafeDecorativeSvg['blocks'] ?? array()), 'unsafe decorative inline SVG does not materialize as a block');
$assertNormalizedFallbackDiagnostic($unsafeDecorativeDiagnostic, 'html_unsafe_inline_svg', 'warning', 'sanitization_review', 'image_asset');
$assert(! str_contains((string) ($unsafeDecorativeDiagnostic['html'] ?? ''), '<script'), 'unsafe inline SVG fallback metadata strips scripts');
$assert(! str_contains((string) ($unsafeDecorativeDiagnostic['html'] ?? ''), 'onclick='), 'unsafe inline SVG fallback metadata strips event attributes');

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
$assert(str_contains((string) ($emptyRuntimeControl['serialized_blocks'] ?? ''), 'aria-expanded="false"'), 'empty runtime control button ARIA state is preserved');

$assetMetadataOptions = array(
    'context' => array(
        'asset_metadata' => array(
            'assets/hero.jpg' => array(
                'id'  => 42,
                'url' => 'https://example.test/wp-content/uploads/hero.jpg',
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

$linkedRuntimeImage = ( new HtmlTransformer() )->transform(
    '<main><a id="productHero" class="product-detail__main-image" href="/product"><img src="assets/product.jpg" alt="Product"></a></main>'
)->toArray();
$linkedRuntimeImageSerialized = (string) ($linkedRuntimeImage['serialized_blocks'] ?? '');
$assert(str_contains($linkedRuntimeImageSerialized, 'id="productHero"'), 'linked image conversion preserves linked media anchor IDs for runtime selectors');
$assert(str_contains($linkedRuntimeImageSerialized, 'class="product-detail__main-image"'), 'linked image conversion preserves linked media classes for runtime selectors');

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
$assert(MaterializationPlanBuilder::SCHEMA === ($simple['source_reports']['materialization_plan']['schema'] ?? ''), 'artifact exposes canonical materialization plan');
$assert('index.html' === ($simple['source_reports']['materialization_plan']['entry_path'] ?? ''), 'materialization plan exposes entry path');

$artifactInlineSvg = $compiler->compile(
    array(
        'schema'         => ArtifactCompiler::INPUT_SCHEMA,
        'generated_html' => '<svg role="img" aria-label="Inline logo" viewBox="0 0 12 12"><title>Inline logo</title><path d="M0 0h12v12H0z"></path></svg>',
    )
)->toArray();
$artifactInlineSvgPath = (string) ($artifactInlineSvg['blocks'][0]['attrs']['url'] ?? '');
$artifactInlineSvgAsset = $artifactInlineSvg['source_reports']['materialization_plan']['assets'][0] ?? array();
$assert(str_starts_with($artifactInlineSvgPath, 'assets/inline-svg-'), 'artifact safe inline SVG block references a generated SVG asset');
$assert($artifactInlineSvgPath === ($artifactInlineSvgAsset['path'] ?? ''), 'artifact materialization plan includes generated inline SVG asset path');
$assert('image/svg+xml' === ($artifactInlineSvgAsset['mime_type'] ?? ''), 'artifact generated inline SVG asset has SVG MIME type');
$assert(str_contains((string) ($artifactInlineSvgAsset['content'] ?? ''), 'aria-label="Inline logo"'), 'artifact generated inline SVG asset preserves sanitized SVG content');
$assert(! str_contains((string) ($artifactInlineSvg['serialized_blocks'] ?? ''), 'data:image/svg+xml,'), 'artifact safe inline SVG avoids data URI serialization');

$artifactNonEntryInlineSvg = $compiler->compile(
    array(
        'entry' => 'index.html',
        'files' => array(
            'index.html' => '<main><h1>Home</h1></main>',
            'about.html' => '<main><svg role="img" aria-label="About icon" viewBox="0 0 8 8"><title>About icon</title><circle cx="4" cy="4" r="3"></circle></svg></main>',
        ),
    )
)->toArray();
$artifactNonEntryInlineSvgPage = $artifactNonEntryInlineSvg['source_reports']['materialization_plan']['pages'][1] ?? array();
$artifactNonEntryInlineSvgAsset = $artifactNonEntryInlineSvg['source_reports']['materialization_plan']['assets'][0] ?? array();
$assert(str_contains((string) ($artifactNonEntryInlineSvgPage['block_markup'] ?? ''), 'assets/inline-svg-'), 'non-entry artifact page references generated inline SVG asset');
$assert('image/svg+xml' === ($artifactNonEntryInlineSvgAsset['mime_type'] ?? ''), 'non-entry artifact generated inline SVG asset is materialized');
$assert(str_contains((string) ($artifactNonEntryInlineSvgAsset['content'] ?? ''), 'aria-label="About icon"'), 'non-entry artifact generated inline SVG asset preserves safe SVG content');
$assert(1 === ($simple['source_reports']['materialization_plan']['totals']['pages'] ?? null), 'materialization plan counts pages');
$assert('index' === ($simple['source_reports']['materialization_plan']['pages'][0]['slug'] ?? ''), 'materialization plan exposes page slug');
$assert('blocks' === ($simple['source_reports']['materialization_plan']['pages'][0]['body_format'] ?? ''), 'materialization plan exposes converted block body format');

$missingMaterializationPlan = $simple;
unset($missingMaterializationPlan['source_reports']['materialization_plan']);
$assertInvalidCanonicalEnvelope($missingMaterializationPlan, 'source_reports.materialization_plan', 'canonical validation rejects artifact results without materialization plans');

$invalidMaterializationPlan = $simple;
$invalidMaterializationPlan['source_reports']['materialization_plan']['schema'] = 'legacy/materialization-plan/v1';
$assertInvalidCanonicalEnvelope($invalidMaterializationPlan, 'materialization plan schema', 'canonical validation rejects materialization plans with unsupported schemas');

$incompleteMaterializationPlan = $simple;
unset($incompleteMaterializationPlan['source_reports']['materialization_plan']['routes']);
$assertInvalidCanonicalEnvelope($incompleteMaterializationPlan, 'materialization plan routes', 'canonical validation rejects incomplete materialization plans');

$rebuiltPlan = ( new MaterializationPlanBuilder() )->fromResult($simple);
$assert($simple['source_reports']['materialization_plan'] === $rebuiltPlan, 'materialization plan builder preserves canonical plans from result envelopes');

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
$staticPlan = $staticSite['source_reports']['materialization_plan'] ?? array();
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
$assert(str_contains((string) ($aboutPlanPage['block_markup'] ?? ''), '<!-- wp:heading'), 'materialization plan preserves transformed non-entry HTML page markup');
$assert('parts/header.html' === ($staticPlan['template_part_writes'][0]['source_path'] ?? ''), 'materialization plan exposes template part writes');
$assert('wp_template_part' === ($staticPlan['template_part_writes'][0]['type'] ?? ''), 'template part writes identify the WordPress write target');
$assert(str_contains((string) ($staticPlan['visual_repair_css'] ?? ''), 'min-height:100vh'), 'materialization plan exposes visual repair CSS');
$assert(! empty(array_filter($staticPlan['asset_rewrite_candidates'] ?? array(), static fn (array $candidate): bool => 'template_part' === ($candidate['scope'] ?? '') && 'assets/logo.png' === ($candidate['asset_path'] ?? ''))), 'materialization plan exposes template part asset rewrite candidates');
$assert('/' === ($staticPlan['routes'][0]['target_path'] ?? ''), 'materialization plan exposes entry route path');
$assert('/about' === ($staticPlan['routes'][1]['target_path'] ?? ''), 'materialization plan exposes document route path');
$assert(empty(array_filter($staticPlan['assets'] ?? array(), static fn (array $asset): bool => 'html' === ($asset['kind'] ?? '') || str_ends_with((string) ($asset['path'] ?? ''), '.html'))), 'materialization plan omits HTML documents from asset rows');
$assert('navigation_link' === ($staticPlan['navigation_links'][0]['kind'] ?? ''), 'materialization plan exposes generic navigation link rows');
$assert('About' === ($staticPlan['navigation_links'][1]['label'] ?? ''), 'materialization plan exposes navigation link labels');
$assert('/about' === ($staticPlan['navigation_links'][1]['target_path'] ?? ''), 'materialization plan exposes navigation target paths');
$assert('menu' === ($staticPlan['menus'][0]['kind'] ?? ''), 'materialization plan exposes generic menu rows');
$assert(2 === ($staticPlan['menus'][0]['items'] ?? null), 'materialization plan counts menu items');
$staticSummary = $staticSite['source_reports']['conversion_report']['source_summary'] ?? array();
$assert(($staticPlan['totals']['pages'] ?? null) === ($staticSummary['page_count'] ?? null), 'conversion report page count matches materialization plan totals');
$assert(($staticPlan['totals']['assets'] ?? null) === ($staticSummary['asset_count'] ?? null), 'conversion report asset count matches materialization plan totals');
$assert(($staticPlan['totals']['routes'] ?? null) === ($staticSummary['route_count'] ?? null), 'conversion report route count matches materialization plan totals');
$assert(($staticPlan['totals']['navigation_links'] ?? null) === ($staticSummary['navigation_link_count'] ?? null), 'conversion report navigation link count matches materialization plan totals');
$assert(($staticPlan['totals']['menus'] ?? null) === ($staticSummary['menu_count'] ?? null), 'conversion report menu count matches materialization plan totals');

$runtimeDependencySite = $compiler->compile(
    array(
        'entrypoint' => 'index.html',
        'files'      => array(
            'index.html' => '<main><canvas id="canvas" class="stage"></canvas><canvas id="unused-canvas"></canvas><div id="status-container"><h2>Status</h2><p>Ready</p></div><script src="js/script.js"></script><script src="js/rum.js"></script></main>',
            'js/script.js' => 'const canvas = document.getElementById("canvas"); canvas.getContext("2d"); const stage = document.querySelector(".stage"); stage.getContext("2d"); const status = document.querySelector("#status-container"); status.addEventListener("click", function () {});',
            'js/rum.js' => 'document.querySelector("#netlify-rum-target");',
        ),
    )
)->toArray();
$runtimeDependencyReport = $runtimeDependencySite['source_reports']['runtime_dependency_parity'] ?? array();
$runtimeDependencyConversionReport = $runtimeDependencySite['source_reports']['conversion_report']['runtime_dependency_parity'] ?? array();
$runtimeFindings = $runtimeDependencyReport['findings'] ?? array();
$canvasFinding = null;
$rumFinding = null;
foreach ( $runtimeFindings as $finding ) {
    if ( '#canvas' === ($finding['selector'] ?? '') ) {
        $canvasFinding = $finding;
    }
    if ( '#netlify-rum-target' === ($finding['selector'] ?? '') ) {
        $rumFinding = $finding;
    }
}
$canvasDependency = null;
$stageDependency = null;
$statusDependency = null;
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
$assert(str_contains($decorativeCanvasMarkup, '<canvas id="lab-canvas" class="stage" aria-label="Live pattern"></canvas>'), 'artifact compiler preserves canvas markup only for selectors with direct canvas API usage');
$assert(! str_contains($decorativeCanvasMarkup, 'hero-canvas'), 'artifact compiler omits decorative canvas touched by script without canvas API usage');
$assert(1 === count($decorativeCanvasFallbacks), 'artifact compiler records one runtime canvas fallback for the interactive canvas only');
$assert('lab-canvas' === ($decorativeCanvasFallbacks[0]['attributes']['id'] ?? ''), 'runtime canvas fallback provenance points to the interactive canvas');
$assert(1 === count($decorativeCanvasSite['source_reports']['runtime_islands'] ?? array()), 'decorative canvas is not over-reported as a runtime island');
$assert('#lab-canvas' === ($decorativeCanvasSite['source_reports']['runtime_islands'][0]['selector'] ?? ''), 'runtime island provenance points to the interactive canvas');

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
foreach ( array( '.reveal', '.nav-toggle', '.menu-shell', '.primary-nav', '.mobile-nav-overlay', '.mobile-nav', '.faq-item', '.filter-btn', '.button-shell', '.filter-bar', '.filter-chips', '#contact-form', '#form-success' ) as $selector ) {
    $assert(true === ($runtimeTargetDependencies[$selector]['generated_present'] ?? null), 'runtime dependency parity records preserved target ' . $selector);
}
$assert(str_contains((string) ($runtimeTargetContainerSite['serialized_blocks'] ?? ''), 'nav-toggle'), 'artifact block markup preserves runtime-targeted menu toggle class');
$assert(str_contains((string) ($runtimeTargetContainerSite['serialized_blocks'] ?? ''), 'mobile-nav-overlay'), 'artifact block markup preserves mobile nav overlay target class after navigation dedupe');
$assert(! str_contains((string) ($runtimeTargetContainerSite['serialized_blocks'] ?? ''), 'drawer-nav'), 'artifact block markup still removes duplicate drawer navigation links after preserving target wrapper');

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
foreach ( $legacyFrontPageSite['source_reports']['materialization_plan']['pages'] ?? array() as $planPage ) {
    if ( 'about-us.html' === ($planPage['source_path'] ?? '') ) {
        $legacyPlanPage = $planPage;
    }
}
$legacyBlockMarkup = (string) ($legacyPlanPage['block_markup'] ?? '');
$assert('' !== trim($legacyBlockMarkup), 'legacy HTML 4 FrontPage-era documents produce non-empty materialization block markup');
$assert(str_contains($legacyBlockMarkup, 'About Hank\'s Tool Rental'), 'legacy HTML 4 FrontPage-era table/font/center content is preserved');
$assert(str_contains($legacyBlockMarkup, '<!-- wp:table'), 'legacy HTML 4 layout tables convert to table block markup instead of empty fallback metadata');

$legacyInline = ( new HtmlTransformer() )->transform('<CENTER><FONT FACE="Arial" SIZE="2">Visible legacy inline copy</FONT></CENTER>')->toArray();
$assert(str_contains((string) ($legacyInline['serialized_blocks'] ?? ''), 'Visible legacy inline copy'), 'center/font-only legacy fragments preserve visible text');
$assert(str_contains((string) ($legacyInline['serialized_blocks'] ?? ''), '<!-- wp:paragraph'), 'center/font-only legacy fragments convert to semantic paragraph blocks');

$logoAssetPlanRow = null;
$cssAssetPlanRow = null;
foreach ( $staticPlan['assets'] ?? array() as $assetPlanRow ) {
    if ( 'assets/logo.png' === ($assetPlanRow['path'] ?? '') ) {
        $logoAssetPlanRow = $assetPlanRow;
    }
    if ( 'visual-repair.css' === ($assetPlanRow['path'] ?? '') ) {
        $cssAssetPlanRow = $assetPlanRow;
    }
}
$assert('assets/logo.png' === ($logoAssetPlanRow['target_path'] ?? ''), 'materialization plan asset rows expose generic target paths');
$assert('base64' === ($logoAssetPlanRow['content_encoding'] ?? ''), 'materialization plan asset rows expose binary content encoding');
$assert(base64_encode("\x89PNG\r\n\x1a\n") === ($logoAssetPlanRow['content_base64'] ?? ''), 'materialization plan asset rows expose base64 payloads for binary assets');
$assert('image/png' === ($logoAssetPlanRow['media_type'] ?? ''), 'materialization plan asset rows expose generic media types');
$assert(! empty($logoAssetPlanRow['hash'] ?? ''), 'materialization plan asset rows expose stable payload hashes');
$assert('text' === ($cssAssetPlanRow['content_encoding'] ?? ''), 'materialization plan asset rows expose text content encoding');
$assert('.wp-site-blocks{min-height:100vh}' === ($cssAssetPlanRow['content'] ?? ''), 'materialization plan asset rows expose text payloads for writable assets');

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
foreach ( $cssReferences['source_reports']['materialization_plan']['assets'] ?? array() as $asset ) {
    if ( 'theme/fonts/FixtureSans.woff2' === ($asset['path'] ?? '') ) {
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
foreach ( $imageReferenceSite['source_reports']['materialization_plan']['assets'] ?? array() as $asset ) {
    $imageReferencePlanAssets[$asset['path'] ?? ''] = $asset;
}
$assert('source' === ($imageReferencePlanAssets['assets/hero-small.png']['references'][0]['element'] ?? ''), 'materialization plan image rows preserve picture source references');
$assert('inline-style' === ($imageReferencePlanAssets['assets/panel.png']['references'][0]['context'] ?? ''), 'materialization plan image rows preserve inline background references');
$assert('image' === ($imageReferencePlanAssets['assets/vector.png']['references'][0]['element'] ?? ''), 'materialization plan image rows preserve SVG image href references');

$materializationView = ( new MaterializationView() )->fromResult($staticSite);
$assert(MaterializationView::SCHEMA === ($materializationView['schema'] ?? ''), 'materialization view exposes its own schema');
$assert(TransformerResult::SCHEMA === ($materializationView['result_schema'] ?? ''), 'materialization view exposes transformer result schema');
$assert($staticSite['status'] === ($materializationView['status'] ?? ''), 'materialization view exposes result status');
$assert($staticSite['source_reports']['artifact'] === ($materializationView['artifact_summary'] ?? null), 'materialization view exposes artifact summary');
$assert($staticPlan === ($materializationView['materialization_plan'] ?? null), 'materialization view exposes materialization plan');
$assert($staticSite['source_reports']['compiled_site'] === ($materializationView['compiled_site'] ?? null), 'materialization view exposes compiled site report');
$assert($staticSite['assets'] === ($materializationView['assets'] ?? null), 'materialization view exposes assets');
$assert($staticSite['documents'] === ($materializationView['documents'] ?? null), 'materialization view exposes documents');
$assert($staticSite['serialized_blocks'] === ($materializationView['block_markup'] ?? null), 'materialization view exposes block markup');
$assert($staticSite['blocks'] === ($materializationView['blocks'] ?? null), 'materialization view exposes blocks');
$assert($staticSite['block_types'] === ($materializationView['block_types'] ?? null), 'materialization view exposes block types');
$assert($staticSite['components'] === ($materializationView['components'] ?? null), 'materialization view exposes components');
$assert($staticSite['diagnostics'] === ($materializationView['diagnostics'] ?? null), 'materialization view exposes diagnostics');
$assert($staticSite['provenance'] === ($materializationView['provenance'] ?? null), 'materialization view exposes provenance');
$assert($staticSite['source_reports']['conversion_report'] === ($materializationView['conversion_report'] ?? null), 'materialization view exposes conversion report');

$objectMaterializationView = ( new MaterializationView() )->fromResult($compiler->compile(array('generated_html' => '<main><h1>Object</h1></main>')));
$assert('success' === ($objectMaterializationView['status'] ?? ''), 'materialization view accepts TransformerResult objects');
$assert('index.html' === ($objectMaterializationView['materialization_plan']['entry_path'] ?? ''), 'materialization view exposes plans from TransformerResult objects');
assertThrows(static fn () => ( new MaterializationView() )->fromResult((object) array('status' => 'success')), 'Materialization view expects a TransformerResult, result array, or object with toArray().');

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
));
$assert('blocks-engine/php-transformer/font-materialization-plan/v1' === ($fontMaterializationPlan['schema'] ?? null), 'font materialization exposes schema');
$assert('@import url("https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;700&family=Poppins:wght@500&display=swap");' === ($fontMaterializationPlan['css'] ?? null), 'font materialization builds deterministic google fonts css');
$assert('assets/css/fonts.css' === ($fontMaterializationPlan['stylesheets'][0]['path'] ?? null), 'font materialization emits stylesheet asset plan');

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
$pagePaths = array_column($normalized['source_reports']['materialization_plan']['pages'] ?? array(), 'source_path');
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

assertSame('core/group', $result['blocks'][0]['blockName'], 'main wrapper should preserve multiple supported child blocks in a group.');
assertSame('core/heading', $result['blocks'][0]['innerBlocks'][0]['blockName'], 'h1 should convert to a heading block.');
assertSame(1, $result['blocks'][0]['innerBlocks'][0]['attrs']['level'], 'h1 level should be preserved.');
assertSame('core/paragraph', $result['blocks'][0]['innerBlocks'][1]['blockName'], 'p should convert to a paragraph block.');
assertSame('core/list', $result['blocks'][1]['blockName'], 'ul should convert to a list block.');
assertSame('core/list-item', $result['blocks'][1]['innerBlocks'][0]['blockName'], 'li should convert to list-item blocks.');
assertSame('html', $runtimeCanvasResult['fallbacks'][0]['type'], 'runtime-targeted canvas elements should be reported as HTML runtime fallbacks.');
assertSame('canvas_requires_runtime', $runtimeCanvasResult['fallbacks'][0]['reason'], 'runtime-targeted canvas fallbacks should expose a runtime-specific reason.');
assertSame('html_canvas_runtime_fallback', $runtimeCanvasResult['fallbacks'][0]['diagnostic_code'], 'runtime-targeted canvas fallbacks should expose a runtime-specific diagnostic code for cross-process consumers.');
assertSame('html', $runtimeCanvasResult['fallbacks'][0]['source_format'], 'fallbacks should expose the source format.');
assertSame('canvas', $runtimeCanvasResult['fallbacks'][0]['tag'], 'fallback should identify the unsupported tag.');
assertContains('html_to_blocks_core_slice', array_column($result['diagnostics'], 'code'), 'expanded core-slice conversion diagnostic should be present.');
assertSame('html', $result['provenance'][0]['source_format'], 'source provenance should identify HTML input.');
assertSame(strlen($fixture . "\n<ul><li>One</li><li><strong>Two</strong></li></ul><canvas>Fallback</canvas>"), $result['metrics']['input_bytes'], 'HTML metrics should expose input bytes.');
assertSame(strlen($result['serialized_blocks']), $result['metrics']['output_bytes'], 'HTML metrics should expose output bytes.');
assertSame(6, $result['metrics']['block_count'], 'HTML metrics should count nested blocks.');
assertSame(0, $result['metrics']['fallback_count'], 'HTML metrics should not count non-runtime canvas as a runtime fallback.');
assertSame(count($result['diagnostics']), $result['metrics']['diagnostic_count'], 'HTML metrics should expose diagnostic count.');
$assert(is_float($result['metrics']['transform_duration_ms'] ?? null), 'HTML metrics expose transform duration');

if ( ! str_contains($result['serialized_blocks'], '<!-- wp:heading {"content":"Hello blocks","level":1} -->') ) {
    fwrite(STDERR, "Serialized blocks did not include the expected heading block.\n");
    exit(1);
}

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
assertStringContains('<h1>Title</h1>', $markdownToHtmlResult['documents'][0]['content'], 'Markdown should convert to HTML through the block pivot.');
$blocksToMarkdownResult = $bridge->convertResult('<!-- wp:heading {"content":"Hello","level":1} --><h1>Hello</h1><!-- /wp:heading -->', 'blocks', 'markdown')->toArray();
assertStringContains('# Hello', $blocksToMarkdownResult['documents'][0]['content'], 'Serialized blocks should convert to markdown through rendered HTML.');
$htmlToBlocksResult = $bridge->convertResult('<h2>Hello</h2>', 'html', 'blocks')->toArray();
assertSame('success', $htmlToBlocksResult['status'], 'Format bridge result conversion should succeed for public default adapters.');
assertSame('blocks-engine/php-transformer/result/v1', $htmlToBlocksResult['schema'], 'Format bridge result conversion should use the shared result envelope.');
assertSame('core/heading', $htmlToBlocksResult['blocks'][0]['blockName'], 'Format bridge result conversion should expose block arrays.');
assertStringContains('<!-- wp:heading {"content":"Hello","level":2} -->', $htmlToBlocksResult['serialized_blocks'], 'Format bridge result conversion should expose serialized blocks for block targets.');
assertSame('blocks', $htmlToBlocksResult['documents'][0]['format'], 'Format bridge result conversion should expose target document format.');
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
