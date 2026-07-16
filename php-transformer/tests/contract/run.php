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
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\BlockFactory;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\TableClassificationPolicy;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternRecognizerInterface;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternRecognizerRegistry;
use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;
use Automattic\BlocksEngine\PhpTransformer\StaticSite\FontMaterialization\FontMaterializationPlanBuilder;
use Automattic\BlocksEngine\PhpTransformer\StaticSite\MaterializationView;
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
$nestedTableResult = ( new HtmlTransformer() )->transform('<table><tr><td>Outer<table><tr><td>Inner</td></tr></table></td></tr></table>')->toArray();
$assert('core/html' === ($nestedTableResult['blocks'][0]['blockName'] ?? null), 'descendant table falls back to core/html');
$assert(str_contains((string) ($nestedTableResult['serialized_blocks'] ?? ''), '<table><tr><td>Outer<table>'), 'descendant table fallback preserves nested table markup');
$colspanTableResult = ( new HtmlTransformer() )->transform('<table><tr><td colspan="2">Merged</td></tr><tr><td>A</td><td>B</td></tr></table>')->toArray();
$assert('core/html' === ($colspanTableResult['blocks'][0]['blockName'] ?? null), 'colspan table falls back to core/html');
$rowspanTableResult = ( new HtmlTransformer() )->transform('<table><tr><td rowspan="2">Merged</td><td>A</td></tr><tr><td>B</td></tr></table>')->toArray();
$assert('core/html' === ($rowspanTableResult['blocks'][0]['blockName'] ?? null), 'rowspan table falls back to core/html');

$navigationResult = ( new HtmlTransformer() )->transform('<nav class="primary"><a href="/about">About</a><a href="/contact">Contact</a></nav>')->toArray();
$navigationBlock = $navigationResult['blocks'][0] ?? array();
$assert('core/navigation' === ($navigationBlock['blockName'] ?? null), 'navigation conversion still emits a navigation block');
$assert(2 === count($navigationBlock['innerBlocks'] ?? array()), 'navigation conversion still preserves direct navigation links');
$assert('About' === ($navigationBlock['innerBlocks'][0]['attrs']['label'] ?? null), 'navigation conversion still preserves link labels');
$assert('/about' === ($navigationBlock['innerBlocks'][0]['attrs']['url'] ?? null), 'navigation conversion still preserves link URLs');

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

$complexAccordionResult = ( new HtmlTransformer() )->transform('<section class="faq"><div class="faq-item"><button aria-controls="a">Question A</button><div id="a"><script src="accordion.js"></script><p>Answer A</p></div></div><div class="faq-item"><button aria-controls="b">Question B</button><div id="b"><p>Answer B</p></div></div></section>')->toArray();
$assert('core/accordion' !== (($complexAccordionResult['blocks'][0] ?? array())['blockName'] ?? null), 'runtime-heavy accordion markup is not forced into native accordion');

$detailsAccordionResult = ( new HtmlTransformer() )->transform('<div class="accordion"><details open><summary>Can I reschedule?</summary><p>Yes, with notice.</p></details><details><summary>Do you take cards?</summary><p>Yes.</p></details></div>')->toArray();
$detailsAccordionItems = $detailsAccordionResult['blocks'][0]['innerBlocks'] ?? array();
$assert('core/accordion' === (($detailsAccordionResult['blocks'][0] ?? array())['blockName'] ?? null), 'repeated details inside accordion wrappers convert to core accordion');
$assert(true === ($detailsAccordionItems[0]['attrs']['openByDefault'] ?? null), 'details open state maps to accordion item open state');
$assert('Can I reschedule?' === ($detailsAccordionItems[0]['innerBlocks'][0]['attrs']['title'] ?? null), 'details summary text maps to accordion heading');
$assert('Yes, with notice.' === ($detailsAccordionItems[0]['innerBlocks'][1]['innerBlocks'][0]['attrs']['content'] ?? null), 'details body text maps to accordion panel');

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

// A heading-wrapped toggle (button nested inside the header) is recognized by
// the same structural signal.
$headingDisclosureResult = ( new HtmlTransformer() )->transform('<div class="item"><h3><button aria-expanded="false" aria-controls="panel-1">Shipping times?</button></h3><div id="panel-1" role="region"><p>Ships in 2 days.</p></div></div>')->toArray();
$assert('core/details' === (($headingDisclosureResult['blocks'][0] ?? array())['blockName'] ?? null), 'a heading-wrapped disclosure toggle converts to core/details');
$assert('Shipping times?' === (($headingDisclosureResult['blocks'][0] ?? array())['attrs']['summary'] ?? null), 'heading-wrapped disclosure toggle text maps to the details summary');

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
$nativeTargetBlocks = $coverage['native_target_blocks'] ?? array();
$availableCoreBlocks = $coverage['available_core_blocks'] ?? array();
$conversionReportNativeTargetBlocks = $result['source_reports']['conversion_report']['native_target_blocks'] ?? array();
$assert(in_array('core/paragraph', $supportedBlocks, true), 'coverage preserves existing supported block metadata');
$assert(in_array('core/accordion', $nativeTargetBlocks, true), 'coverage exposes core/accordion as an available native target');
$assert(in_array('core/icon', $nativeTargetBlocks, true), 'coverage exposes core/icon as an available native target');
$assert(in_array('core/math', $nativeTargetBlocks, true), 'coverage exposes core/math as an available native target');
$assert($nativeTargetBlocks === $availableCoreBlocks, 'coverage aliases available core blocks to native target blocks');
$assert($nativeTargetBlocks === $conversionReportNativeTargetBlocks, 'conversion report exposes native target block metadata');
$assert(! in_array('core/accordion', $supportedBlocks, true), 'coverage does not claim unsupported native targets as converted support');
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
    array('runtime_dom_selectors' => array('.qty-display'))
)->toArray();
$inlineSemanticIsland = $inlineSemanticRuntime['source_reports']['runtime_islands'][0] ?? array();
$assert('core/html' === ($inlineSemanticRuntime['blocks'][0]['blockName'] ?? null), 'runtime-targeted inline semantic HTML remains a bounded core/html island to preserve attributes');
$assert(str_contains((string) ($inlineSemanticRuntime['serialized_blocks'] ?? ''), 'aria-live="polite"'), 'runtime-targeted inline semantic HTML preserves aria-live in serialized markup');
$assert('inline_semantic_html' === ($inlineSemanticIsland['pattern_family'] ?? ''), 'runtime-targeted inline semantic HTML reports a specific fallback pattern family');
$assert('preserve_runtime_island' === ($inlineSemanticIsland['suggested_generic_repair_class'] ?? ''), 'runtime-targeted inline semantic HTML is classified as an attribute-preserving runtime island, not a generic unsupported span');
$assert('preserved_runtime_island' === ($inlineSemanticIsland['reason_code'] ?? ''), 'runtime-targeted inline semantic HTML keeps the runtime-island reason code');

$runtimeSvgRoot = ( new HtmlTransformer() )->transform(
    '<main><svg id="graph" viewBox="0 0 640 360"></svg></main>',
    array('runtime_dom_selectors' => array('#graph'))
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
$assert('success' === $contextual['status'], 'strict HTML transform succeeds when runtime-targeted canvas is preserved without fallbacks', (string) $contextual['status']);
$assert(true === ($contextual['context']['strict'] ?? null), 'HTML transform context exposes strict mode');
$assert(false === ($contextual['context']['allow_fallbacks'] ?? null), 'HTML transform context exposes fallback policy');
$assert('fixture:contextual-html' === ($contextual['provenance'][0]['source'] ?? ''), 'HTML provenance exposes generic source metadata');
$assert('contract-test' === ($contextual['provenance'][0]['scope'] ?? ''), 'HTML provenance exposes generic scope metadata');

$formFallback = ( new HtmlTransformer() )->transform(
    '<main><form action="/contact" method="post" data-action="contact-submit"><label for="email">Email</label><input id="email" name="email" type="email" required><select name="topic"><option value="support" selected>Support</option></select><button type="submit">Send</button></form></main>'
)->toArray();
$formFallbackDiagnostic = $formFallback['fallbacks'][0] ?? array();
$assert(1 === count($formFallback['fallbacks'] ?? array()), 'data-entry runtime form surfaces a materializable form fallback finding');
$assert('html_form_fallback' === ($formFallbackDiagnostic['diagnostic_code'] ?? ''), 'data-entry runtime form fallback carries the form diagnostic code');
$assert('email' === ($formFallbackDiagnostic['controls'][0]['name'] ?? ''), 'data-entry runtime form fallback carries generic control metadata');
$assert('/contact' === ($formFallbackDiagnostic['form']['action'] ?? ''), 'data-entry runtime form fallback carries form action metadata');
$assert('form' === ($formFallbackDiagnostic['materialization_target']['capability'] ?? ''), 'data-entry runtime form targets a form materializer capability');
$assert('form_provider' === ($formFallbackDiagnostic['materialization_target']['provider_role'] ?? ''), 'data-entry runtime form targets a form provider role');
$assertNormalizedFallbackDiagnostic($formFallback['source_reports']['conversion_report']['fallback_diagnostics'][0] ?? array(), 'html_form_fallback', 'warning', 'server_or_client_form_handler', 'form');
$assert('form_provider' === ($formFallback['source_reports']['conversion_report']['fallback_diagnostics'][0]['materialization_target']['provider_role'] ?? ''), 'conversion report preserves form provider materialization target');
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

$newsletterFallback = ( new HtmlTransformer() )->transform(
    '<main><section><h2>Newsletter</h2><form class="newsletter-form" action="#" method="post" novalidate><input type="email" name="email" placeholder="your@email.com" autocomplete="email" required aria-label="Email address"><button type="submit">Subscribe</button></form></section></main>'
)->toArray();
$newsletterFallbackDiagnostic = $newsletterFallback['fallbacks'][0] ?? array();
$assert('html_form_fallback' === ($newsletterFallbackDiagnostic['diagnostic_code'] ?? ''), 'static newsletter form stays classified as a provider-materializable form target');
$assert('interactive_form' === ($newsletterFallbackDiagnostic['pattern_family'] ?? ''), 'static newsletter form uses the interactive_form family');
$assert('form' === ($newsletterFallbackDiagnostic['suggested_primitive'] ?? ''), 'static newsletter form suggests a form primitive, not a fake native layout');
$assert('form_provider' === ($newsletterFallbackDiagnostic['materialization_target']['provider_role'] ?? ''), 'static newsletter form declares the form provider materialization role');
$assert(0 === substr_count((string) ($newsletterFallback['serialized_blocks'] ?? ''), '<!-- wp:html'), 'readable newsletter form output avoids core/html while keeping fallback metadata explicit');

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

$inlineSvgArtwork = ( new HtmlTransformer() )->transform(
    '<main><svg class="album-art" viewBox="0 0 100 100" role="img" aria-label="Album art"><rect width="100" height="100" fill="#111"/><circle cx="50" cy="50" r="30" fill="#c4581a"/></svg></main>'
)->toArray();
$inlineSvgMarkup = (string) ($inlineSvgArtwork['serialized_blocks'] ?? '');
$assert('core/image' === ($inlineSvgArtwork['blocks'][0]['blockName'] ?? ''), 'passive meaningful inline SVG artwork materializes as native core/image');
$assert(str_contains($inlineSvgMarkup, '<!-- wp:image'), 'passive meaningful inline SVG artwork serializes as core/image');
$assert(str_contains($inlineSvgMarkup, 'assets/materialized-svg/'), 'passive meaningful inline SVG artwork uses a materialized SVG asset URL');
$assert(str_contains((string) ($inlineSvgArtwork['assets'][0]['content'] ?? ''), '<svg'), 'passive meaningful inline SVG artwork carries sanitized SVG asset content');
$assert(str_contains($inlineSvgMarkup, 'class="wp-block-image is-resized album-art"'), 'passive meaningful inline SVG artwork preserves source class and core/image resize class on the image block wrapper');
$assert(str_contains($inlineSvgMarkup, 'alt="Album art"'), 'passive meaningful inline SVG artwork maps accessible label to image alt text');

$cssSizedInlineSvgArtwork = ( new HtmlTransformer() )->transform(
    '<style>.album-cover{width:100%;max-width:380px;aspect-ratio:1;display:block;box-shadow:0 40px 80px rgba(0,0,0,.6)}</style><main><div class="album-card"><svg class="album-cover" viewBox="0 0 500 500" role="img" aria-label="Album cover"><rect width="500" height="500" fill="#111"/></svg></div></main>'
)->toArray();
$cssSizedInlineSvgArtworkMarkup = (string) ($cssSizedInlineSvgArtwork['serialized_blocks'] ?? '');
$assert(str_contains($cssSizedInlineSvgArtworkMarkup, 'class="wp-block-image album-cover"'), 'CSS-sized inline SVG artwork preserves the media class on the native image wrapper');
$assert(! str_contains($cssSizedInlineSvgArtworkMarkup, 'is-resized album-cover'), 'CSS-sized inline SVG artwork does not add resized wrapper geometry over source CSS');
$assert(! str_contains($cssSizedInlineSvgArtworkMarkup, 'style="width:500px;height:500px"'), 'CSS-sized inline SVG artwork does not force intrinsic SVG dimensions over source CSS sizing');

$largeCssSizedInlineSvgArtwork = ( new HtmlTransformer() )->transform(
    '<style>.hero-cover{width:100%;max-width:380px;aspect-ratio:1;display:block}</style><main><svg class="hero-cover" viewBox="0 0 500 500" role="img" aria-label="Hero cover">' . str_repeat('<rect width="500" height="500" fill="#111"/>', 2000) . '</svg></main>'
)->toArray();
$largeCssSizedInlineSvgArtworkMarkup = (string) ($largeCssSizedInlineSvgArtwork['serialized_blocks'] ?? '');
$assert(str_contains($largeCssSizedInlineSvgArtworkMarkup, '<!-- wp:image'), 'large CSS-sized inline SVG artwork materializes as native image without a data URI budget');
$assert(! str_contains($largeCssSizedInlineSvgArtworkMarkup, '<svg class="hero-cover" viewBox="0 0 500 500" role="img" aria-label="Hero cover" width="500" height="500"'), 'large CSS-sized inline SVG artwork does not inject intrinsic SVG dimensions over source CSS sizing');

$fixedBackgroundLayer = ( new HtmlTransformer() )->transform(
    '<style>.page-bg{position:fixed;inset:0;z-index:-1;background:linear-gradient(180deg,#211,#000)}</style><main><div class="page-bg" aria-hidden="true"></div><section class="hero"><h1>Hero</h1></section></main>'
)->toArray();
$fixedBackgroundLayerMarkup = (string) ($fixedBackgroundLayer['serialized_blocks'] ?? '');
$assert(str_contains($fixedBackgroundLayerMarkup, 'page-bg'), 'fixed background visual layer keeps its CSS-addressable class');
$assert(str_contains($fixedBackgroundLayerMarkup, '<div class="wp-block-group page-bg"'), 'fixed background visual layer materializes as an empty group wrapper for source CSS');

$classOwnedGrid = ( new HtmlTransformer() )->transform('<style>.hero-inner{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(260px,.9fr);gap:4rem}</style><main><div class="hero-inner"><div>Text</div><div>Art</div></div></main>')->toArray();
$classOwnedGridMarkup = (string) ($classOwnedGrid['serialized_blocks'] ?? '');
$assert(str_contains($classOwnedGridMarkup, 'hero-inner'), 'class-owned CSS grid keeps the source class');
$assert(! str_contains($classOwnedGridMarkup, 'is-layout-grid'), 'class-owned CSS grid avoids WP layout classes that override exact source tracks');

$classOwnedFlex = ( new HtmlTransformer() )->transform('<style>.hero{display:flex;align-items:center;min-height:100vh}</style><main><section class="hero"><div>Text</div></section></main>')->toArray();
$classOwnedFlexMarkup = (string) ($classOwnedFlex['serialized_blocks'] ?? '');
$assert(str_contains($classOwnedFlexMarkup, 'hero'), 'class-owned CSS flex keeps the source class');
$assert(! str_contains($classOwnedFlexMarkup, 'is-layout-flex'), 'class-owned CSS flex avoids WP layout classes that override exact source layout');

$outlineButton = ( new HtmlTransformer() )->transform(
    '<main><a class="btn btn-secondary" style="display:inline-block;padding:1rem 2rem;border:1px solid #c4a070;background:transparent;color:#eee;text-transform:uppercase" href="/tickets"><span>Tickets</span></a></main>'
)->toArray();
$outlineButtonMarkup = (string) ($outlineButton['serialized_blocks'] ?? '');
$assert(str_contains($outlineButtonMarkup, '<!-- wp:button'), 'styled anchor with presentational span materializes as core/button');
$assert(str_contains($outlineButtonMarkup, 'background-color:transparent'), 'outline button emits transparent background to suppress default theme fill');
$assert(str_contains($outlineButtonMarkup, 'border-radius:0'), 'outline button with no source radius emits square radius to suppress default rounded inner button chrome');
$assert(! str_contains($outlineButtonMarkup, '<div class="wp-block-button btn btn-secondary'), 'outline button with native styles avoids duplicating source button chrome on the outer wrapper');
$assert(! str_contains($outlineButtonMarkup, '<span>Tickets</span>'), 'button label unwraps presentational span to avoid nested default styling');

$roundedOutlineButton = ( new HtmlTransformer() )->transform(
    '<main><a class="btn btn-secondary" style="display:inline-block;padding:1rem 2rem;border:1px solid #c4a070;border-radius:12px;background:transparent;color:#eee" href="/tickets">Tickets</a></main>'
)->toArray();
$roundedOutlineButtonMarkup = (string) ($roundedOutlineButton['serialized_blocks'] ?? '');
$assert(str_contains($roundedOutlineButtonMarkup, 'border-radius:12px'), 'outline button preserves an explicit source border radius');
$assert(! str_contains($roundedOutlineButtonMarkup, 'border-radius:0'), 'outline button does not override an explicit source border radius');

$wrapperOwnedButton = ( new HtmlTransformer() )->transform(
    '<main><div class="hero-cta" role="button" style="display:inline-flex;align-items:center;padding:14px 24px;border-radius:999px;background:#2563eb;color:#ffffff;font-weight:700"><a href="/start"><span>Start free</span></a></div></main>'
)->toArray();
$wrapperOwnedButtonMarkup = (string) ($wrapperOwnedButton['serialized_blocks'] ?? '');
$assert(str_contains($wrapperOwnedButtonMarkup, '<!-- wp:button'), 'button-like wrapper with a single simple anchor materializes as core/button');
$assert(str_contains($wrapperOwnedButtonMarkup, 'href="/start"'), 'button-like wrapper preserves the nested anchor URL on the native button');
$assert(str_contains($wrapperOwnedButtonMarkup, 'Start free'), 'button-like wrapper preserves the nested anchor label');
$assert(str_contains($wrapperOwnedButtonMarkup, 'background-color:#2563eb'), 'button-like wrapper applies wrapper-owned fill color to the native button chrome');
$assert(str_contains($wrapperOwnedButtonMarkup, 'color:#ffffff'), 'button-like wrapper applies wrapper-owned text color to the native button chrome');
$assert(str_contains($wrapperOwnedButtonMarkup, 'border-radius:999px'), 'button-like wrapper applies wrapper-owned radius to the native button chrome');
$assert(str_contains($wrapperOwnedButtonMarkup, 'padding-top:14px'), 'button-like wrapper applies wrapper-owned padding to the native button chrome');
$assert('pass' === ($wrapperOwnedButton['source_reports']['wp_block_validity']['status'] ?? ''), 'button-like wrapper conversion emits valid native button markup');

$fullWidthButton = ( new HtmlTransformer() )->transform(
    '<main><a class="btn tier-cta" style="display:inline-flex;width:100%;justify-content:center;padding:10px 18px;background:#111827;color:#ffffff" href="/pricing">Start free</a></main>'
)->toArray();
$fullWidthButtonMarkup = (string) ($fullWidthButton['serialized_blocks'] ?? '');
$assert(100 === ($fullWidthButton['blocks'][0]['innerBlocks'][0]['attrs']['width'] ?? null), '100% source button width maps to the native core/button width attribute');
$assert(str_contains($fullWidthButtonMarkup, '<div class="wp-block-button has-custom-width wp-block-button__width-100 btn tier-cta">'), '100% source button width emits canonical core/button width wrapper classes');
$assert('pass' === ($fullWidthButton['source_reports']['wp_block_validity']['status'] ?? ''), 'full-width button serialization passes generated WordPress block validity checks');

$cssVariableButton = ( new HtmlTransformer() )->transform(
    '<style>:root{--amber:#f0ac22;--ink:#050d1a;--radius:6px}.btn-primary{padding:9px 20px;border-radius:var(--radius);background:var(--amber);color:var(--ink)}</style><main><a class="btn btn-primary" href="/start">Start free</a></main>'
)->toArray();
$cssVariableButtonMarkup = (string) ($cssVariableButton['serialized_blocks'] ?? '');
$assert(str_contains($cssVariableButtonMarkup, 'background-color:#f0ac22'), 'button CSS variable fill resolves to a concrete canonical background color');
$assert(str_contains($cssVariableButtonMarkup, 'color:#050d1a'), 'button CSS variable text color resolves to a concrete canonical text color');
$assert(str_contains($cssVariableButtonMarkup, 'border-radius:6px'), 'button CSS variable radius resolves to a concrete canonical radius');
$assert(! str_contains($cssVariableButtonMarkup, 'var(--amber)'), 'button fill avoids leaking source-local CSS custom properties into standalone block markup');
$assert('pass' === ($cssVariableButton['source_reports']['wp_block_validity']['status'] ?? ''), 'CSS-variable button serialization passes generated WordPress block validity checks');

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
    '<main><input id="donation" type="number" aria-label="Custom donation amount" placeholder="Enter amount"><select aria-label="Sort products"><option selected>Featured</option><option>Price: Low to High</option></select><select class="js-sort-select" aria-label="Runtime sort"><option>Newest</option></select></main>',
    array('runtime_dom_selectors' => array('.js-sort-select'))
)->toArray();
$standaloneControlBlocks = $standaloneControls['blocks'][0]['innerBlocks'] ?? array();
$assert(array() === ($standaloneControls['fallbacks'] ?? array()), 'standalone readable controls convert without unsupported-element fallback');
$assert('core/paragraph' === ($standaloneControlBlocks[0]['blockName'] ?? ''), 'standalone non-runtime input converts to readable paragraph');
$assert('core/list' === ($standaloneControlBlocks[1]['innerBlocks'][1]['blockName'] ?? ''), 'standalone non-runtime select options convert to readable list');
$assert('core/html' === ($standaloneControlBlocks[2]['blockName'] ?? ''), 'runtime-targeted select preserves native DOM output');
$assert(str_contains((string) ($standaloneControls['serialized_blocks'] ?? ''), 'Featured (selected)'), 'readable select summary preserves selected option state');
$assert(str_contains((string) ($standaloneControls['serialized_blocks'] ?? ''), '<select class="js-sort-select"'), 'runtime-targeted select preserves native markup in serialized blocks');
$assert(str_contains((string) ($standaloneControls['serialized_blocks'] ?? ''), 'id="donation"'), 'readable input output preserves source id as a block anchor');
$assert(str_contains((string) ($standaloneControls['serialized_blocks'] ?? ''), 'js-sort-select'), 'runtime-targeted select keeps behavior-hook class on native markup');
$assert(1 === count($standaloneControls['source_reports']['runtime_islands'] ?? array()), 'runtime islands report only the explicitly runtime-targeted standalone control');
$assert('control' === ($standaloneControls['source_reports']['runtime_islands'][0]['kind'] ?? ''), 'runtime-targeted standalone control reports as a control island');
$assert('.js-sort-select' === ($standaloneControls['source_reports']['runtime_islands'][0]['selector'] ?? ''), 'runtime-targeted standalone control reports selector metadata');
$assert('select' === ($standaloneControls['source_reports']['runtime_islands'][0]['control']['tag'] ?? ''), 'runtime-targeted standalone control reports control metadata');
$assert(str_contains((string) ($standaloneControls['source_reports']['runtime_islands'][0]['source_snippet'] ?? ''), '<select class="js-sort-select"'), 'runtime-targeted standalone control preserves source snippet metadata');

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
$assert(! str_contains($artifactControlMarkup, '<select id="sort-select"'), 'artifact compiler converts generically queried static select to readable block output');
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

$buttonCustomFontSizeResult = ( new HtmlTransformer() )->transform(
    '<main><a class="artist-button" href="/music" style="font-size:1rem;color:#fdf0d5;border:1px solid #fdf0d5">Listen now</a></main>'
)->toArray();
$buttonCustomFontSizeMarkup = (string) ($buttonCustomFontSizeResult['serialized_blocks'] ?? '');
$assert(str_contains($buttonCustomFontSizeMarkup, 'has-custom-font-size'), 'button custom font-size emits the WordPress support class required by core/button save markup');
$assert(str_contains($buttonCustomFontSizeMarkup, 'font-size:1rem'), 'button custom font-size preserves the inline style declaration');
$assert('pass' === ($buttonCustomFontSizeResult['source_reports']['wp_block_validity']['status'] ?? ''), 'button custom font-size serialization passes generated WordPress block validity checks');

$rubyResult = ( new HtmlTransformer() )->transform(
    '<main><blockquote><ruby>翻訳<rt>ほんやく</rt></ruby> keeps pronunciation visible.</blockquote></main>'
)->toArray();
$rubyQuote = $rubyResult['blocks'][0] ?? array();
$assert(array() === ($rubyResult['fallbacks'] ?? array()), 'ruby phrasing content does not create unsupported fallbacks');
$assert('core/quote' === ($rubyQuote['blockName'] ?? ''), 'ruby phrasing content remains inside quote block');
$assert(str_contains((string) ($rubyResult['serialized_blocks'] ?? ''), '<ruby>翻訳<rt>ほんやく</rt></ruby>'), 'ruby markup is preserved in quote content');

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
$assert(str_contains($linkedLogoSerialized, '<p class="site-logo"><a href="/">Mara Vale</a></p>'), 'linked logo paragraph hoists link styling hooks to the paragraph wrapper and keeps valid anchor markup');
$assert(! str_contains($linkedLogoSerialized, '<a class="site-logo"'), 'linked logo paragraph does not leave className on the RichText anchor');
$assert(! str_contains($linkedLogoSerialized, '\\u003ca'), 'linked logo paragraph avoids raw anchor HTML in delimiter JSON');
$assert('pass' === ($linkedLogoResult['source_reports']['wp_block_validity']['status'] ?? ''), 'linked logo paragraph passes generated block validity checks');

$canonicalWrapperAttrsResult = ( new HtmlTransformer() )->transform(
    '<main><section class="menu-grid" style="display:grid;gap:2rem"><h2 class="section-title" style="color:red">Menu</h2><p class="card-desc" style="margin-bottom:1rem">Fresh daily.</p></section></main>'
)->toArray();
$canonicalWrapperAttrsSerialized = (string) ($canonicalWrapperAttrsResult['serialized_blocks'] ?? '');
$assert(str_contains($canonicalWrapperAttrsSerialized, '<section class="wp-block-group is-layout-grid wp-block-group-is-layout-grid menu-grid"'), 'group wrappers preserve semantic tag, canonical classes, and source classes');
$assert(! str_contains($canonicalWrapperAttrsSerialized, 'style="display:grid'), 'group wrappers omit raw layout styles that core/group save validation does not reproduce');
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
$assert('core/html' === ($paragraphSvgBlock['blockName'] ?? ''), 'paragraph content with inline SVG falls back to core/html instead of invalid RichText');
$assert(str_contains($paragraphSvgSerialized, '<!-- wp:html'), 'paragraph inline SVG serializes as a bounded custom HTML block');
$assert(! preg_match('/<!-- wp:paragraph[^>]*-->.*<svg\b.*<!-- \/wp:paragraph -->/s', $paragraphSvgSerialized), 'paragraph inline SVG is not stored inside core/paragraph RichText');

$coffeeHtml = (string) file_get_contents(dirname(__DIR__, 3) . '/fixtures/websites/2-onepager-coffee/index.html');
$coffeeResult = ( new HtmlTransformer() )->transform($coffeeHtml, array())->toArray();
$coffeeSerialized = (string) ($coffeeResult['serialized_blocks'] ?? '');
$coffeeStylesheets = array_values(array_filter($coffeeResult['assets'] ?? array(), static fn (array $asset): bool => 'stylesheet' === ($asset['role'] ?? '') && 'text/css' === ($asset['mime_type'] ?? '')));
$coffeeStylesheetCss = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $coffeeStylesheets));
$coffeeRiskCount = 0;
if ( preg_match_all('/<!-- wp:(paragraph|heading|list-item)[^>]*-->(.*?)<!-- \/wp:\\1 -->/s', $coffeeSerialized, $coffeeBlocks, PREG_SET_ORDER) ) {
    foreach ( $coffeeBlocks as $coffeeBlock ) {
        if ( preg_match('/<(?:span|a)\b[^>]*(?:class|style)=|<svg\b/i', $coffeeBlock[2]) ) {
            ++$coffeeRiskCount;
        }
    }
}
$assert(0 === $coffeeRiskCount, '2-onepager-coffee emits no class/style anchors/spans or SVG inside RichText core blocks', (string) $coffeeRiskCount);
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
$assert(1 === count($safeInlineSvgAsset['assets'] ?? array()), 'safe accessible inline SVG icon generates one image asset');

$complexSvgAsset = ( new HtmlTransformer() )->transform(
    '<svg role="img" aria-label="Site illustration" viewBox="0 0 400 200"><title>Site illustration</title><path d="M0 0h400v200H0z"></path></svg>'
)->toArray();
$complexSvgContent = (string) ($complexSvgAsset['assets'][0]['content'] ?? '');
$assert('core/image' === ($complexSvgAsset['blocks'][0]['blockName'] ?? ''), 'large passive illustrative inline SVG is represented as native core/image');
$assert(1 === count($complexSvgAsset['assets'] ?? array()), 'inline illustrative SVG is externalized to one generated .svg image asset');
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

$nestedNavMenu = ( new HtmlTransformer() )->transform(
    '<nav aria-label="Main"><ul><li><a href="/coffee">Coffee</a><nav id="nav-links" class="wp-block-navigation nav-links" style="display:none;align-items:flex-start;gap:1.4rem;background:var(--cream);flex-direction:column;padding:1.8rem var(--gutter) 2rem;box-shadow:0 10px 20px rgba(0,0,0,.2)"><a href="#espresso">Espresso</a><a href="#latte">Latte</a></nav></li><li><a href="/visit">Visit</a></li></ul></nav>'
)->toArray();
$nestedNavMenuSerialized = (string) ($nestedNavMenu['serialized_blocks'] ?? '');
$nestedNavMenuParity = $nestedNavMenu['source_reports']['semantic_parity'] ?? array();
$nestedNavMenuBlock = $nestedNavMenuParity['navigation_menus']['blocks'][0] ?? array();
$assert('pass' === ($nestedNavMenu['source_reports']['wp_block_validity']['status'] ?? ''), 'nested nav/menu serializes to valid WordPress navigation blocks');
$assert(str_contains($nestedNavMenuSerialized, '<!-- wp:navigation-submenu'), 'nested nav/menu emits a canonical navigation-submenu block');
$assert(! str_contains($nestedNavMenuSerialized, '<nav id="nav-links"'), 'nested nav/menu does not embed a raw nav wrapper inside core/navigation content');
$assert(! str_contains($nestedNavMenuSerialized, 'style="display:none'), 'nested nav/menu does not freeze hidden raw inline nav styles into serialized block markup');
$assert(! str_contains($nestedNavMenuSerialized, 'wp-block-navigation nav-links'), 'nested nav/menu strips core wrapper classes while preserving custom nav classes');
$assert(str_contains($nestedNavMenuSerialized, 'nav-links'), 'nested nav/menu preserves the custom submenu class for styling');
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
$assert('underline' === ($activeNavigationLinks[0]['attrs']['style']['typography']['textDecoration'] ?? ''), 'active navigation link carries native underline style intent');
$assert(! isset($activeNavigationLinks[1]['attrs']['style']['typography']['textDecoration']), 'inactive navigation link does not get active underline styling');
$assert(str_contains((string) ($activeNavigation['serialized_blocks'] ?? ''), '"textDecoration":"underline"'), 'active navigation underline intent is serialized into the dynamic navigation-link block attrs');

$activeNavigationColor = ( new HtmlTransformer() )->transform(
    '<style>.nav-links a{color:var(--bone)}.nav-links a.active{color:var(--bone);text-decoration:underline}.nav-links a.active::after{content:"";display:block;background:var(--ember);height:2px;width:100%}</style><nav aria-label="Primary"><ul class="nav-links"><li><a href="/" class="active">Home</a></li><li><a href="/music">Music</a></li></ul></nav>'
)->toArray();
$activeNavigationColorLinks = $activeNavigationColor['blocks'][0]['innerBlocks'] ?? array();
$activeNavigationColorSerialized = (string) ($activeNavigationColor['serialized_blocks'] ?? '');
$assert('var(--ember)' === ($activeNavigationColorLinks[0]['attrs']['style']['typography']['textDecorationColor'] ?? ''), 'active navigation underline color carries source pseudo underline paint');
$assert(! isset($activeNavigationColorLinks[1]['attrs']['style']['typography']['textDecorationColor']), 'inactive navigation link does not get underline color styling');
$assert(str_contains($activeNavigationColorSerialized, '<!-- wp:navigation-link'), 'active navigation color case keeps canonical navigation-link serialization');
$assert(str_contains($activeNavigationColorSerialized, '"textDecorationColor":"var(--ember)"'), 'active navigation underline color is serialized into the dynamic navigation-link block attrs');
$assert(! str_contains($activeNavigationColorSerialized, '<li class="wp-block-navigation-item'), 'active navigation color serialization emits no invalid static navigation item markup');

$headerCluster = ( new HtmlTransformer() )->transform(
    '<header class="site-header"><a class="site-logo" href="/">Acme Lab</a><nav class="primary-nav" aria-label="Primary"><a class="nav-link" href="/work">Work</a><a class="nav-link" href="/docs"><span>Docs</span></a></nav><form class="site-search" role="search" action="/search"><label for="q">Search</label><input id="q" type="search" name="q" placeholder="Search docs"><button type="submit">Search</button></form><div class="header-actions"><a class="cta" href="/start">Get started</a></div></header>'
)->toArray();
$headerClusterSerialized = (string) ($headerCluster['serialized_blocks'] ?? '');
$headerClusterParity = $headerCluster['source_reports']['semantic_parity'] ?? array();
$assert('pass' === ($headerClusterParity['status'] ?? ''), 'header logo/nav/search/CTA clusters preserve source navigation semantic parity');
$assert(str_contains($headerClusterSerialized, 'site-logo'), 'header cluster preserves logo link wrapper');
$assert(str_contains($headerClusterSerialized, 'nav-link'), 'header cluster preserves nav link class target');
$assert(str_contains($headerClusterSerialized, '<form class="site-search"'), 'header cluster preserves arbitrary search form markup instead of invalid static core/search');
$assert(! str_contains($headerClusterSerialized, '<!-- wp:search'), 'header cluster does not emit static core/search markup for arbitrary HTML search forms');
$assert(str_contains($headerClusterSerialized, '<!-- wp:buttons'), 'header cluster converts CTA action to buttons');

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

$unknownIframe = ( new HtmlTransformer() )->transform(
    '<main><section><h2>Playground</h2><p>Before embed.</p><iframe title="Interactive demo" src="https://example.test/playground" width="640" height="360" allow="fullscreen"></iframe><p>After embed.</p></section></main>'
)->toArray();
$unknownDiagnostics = $unknownIframe['source_reports']['conversion_report']['fallback_diagnostics'] ?? array();
$unknownIframeDiagnostics = array_values(array_filter($unknownDiagnostics, static fn (array $diagnostic): bool => 'html_iframe_embed_fallback' === ($diagnostic['diagnostic_code'] ?? '')));
$assert(1 === count($unknownIframeDiagnostics), 'unknown iframe emits one iframe fallback diagnostic');
$assert('runtime_island_preserved' === ($unknownIframeDiagnostics[0]['conversion_classification'] ?? ''), 'unknown iframe fallback is classified as runtime island preservation');
$assert('https://example.test/playground' === ($unknownIframe['fallbacks'][0]['attributes']['src'] ?? ''), 'unknown iframe fallback preserves bounded safe src metadata');
$unknownIframeIslands = array_values(array_filter($unknownIframe['source_reports']['runtime_islands'] ?? array(), static fn (array $island): bool => 'iframe' === ($island['kind'] ?? '')));
$assert(1 === count($unknownIframeIslands), 'unknown iframe projects as a runtime island');
$assert('iframe_requires_embed_runtime' === ($unknownIframeIslands[0]['preservation_reason'] ?? ''), 'unknown iframe runtime island exposes preservation reason');
$unknownSerialized = (string) ($unknownIframe['serialized_blocks'] ?? '');
$assert(! str_contains($unknownSerialized, '<!-- wp:embed'), 'unknown iframe does not become a provider embed block');
$assert(! str_contains($unknownSerialized, '<!-- wp:html'), 'unknown iframe does not force raw HTML fallback materialization');
$assert(str_contains($unknownSerialized, 'Playground'), 'ancestor content around unknown iframe still converts heading content');
$assert(str_contains($unknownSerialized, 'Before embed.'), 'ancestor content before unknown iframe still converts');
$assert(str_contains($unknownSerialized, 'After embed.'), 'ancestor content after unknown iframe still converts');

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
$assert(1 === count($behaviorLossCollect($ariaToggleNoNav)), 'an aria-controls toggle with no associated navigation that becomes a dead core/button emits a behavior-loss finding');

$roleButtonControl = ( new HtmlTransformer() )->transform('<main><div role="button" data-action="open">Open</div></main>')->toArray();
$assert(1 === count($behaviorLossCollect($roleButtonControl)), 'a non-button element with role=button plus a declarative handler emits a behavior-loss finding');

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
$artifactPlanCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $artifactGeometry['source_reports']['materialization_plan']['assets'] ?? array()));
$assert(str_contains($artifactPlanCss, 'width:75%') && str_contains($artifactPlanCss, 'max-width:72rem'), 'artifact materialization plan carries the primary geometry asset');

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
$artifactInlineSvgAssets = $artifactInlineSvg['source_reports']['materialization_plan']['assets'] ?? array();
$assert('core/image' === ($artifactInlineSvg['blocks'][0]['blockName'] ?? ''), 'artifact safe passive inline SVG is represented as native core/image');
$assert(1 === count($artifactInlineSvgAssets), 'artifact safe inline SVG is externalized to one generated .svg image asset');
$assert(str_contains((string) ($artifactInlineSvgAssets[0]['content'] ?? ''), 'aria-label="Inline logo"'), 'artifact inline SVG asset preserves sanitized SVG content');
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
$artifactNonEntryInlineSvgPage = $artifactNonEntryInlineSvg['source_reports']['materialization_plan']['pages'][1] ?? array();
$artifactNonEntryInlineSvgAssets = $artifactNonEntryInlineSvg['source_reports']['materialization_plan']['assets'] ?? array();
$assert(str_contains((string) ($artifactNonEntryInlineSvgPage['block_markup'] ?? ''), '<!-- wp:image'), 'non-entry artifact simple icon SVG is represented as native core/image, not a dynamic core/icon');
$assert(str_contains((string) ($artifactNonEntryInlineSvgAssets[0]['content'] ?? ''), 'aria-label="About icon"') && str_contains((string) ($artifactNonEntryInlineSvgAssets[0]['content'] ?? ''), 'viewBox="0 0 8 8"'), 'non-entry artifact faithful SVG preserves its accessible label and correct-case viewBox in the generated asset');
$assert(1 === count($artifactNonEntryInlineSvgAssets), 'non-entry artifact simple icon SVG materializes one generated image asset');

$artifactInlineScript = $compiler->compile(
    array(
        'schema'         => ArtifactCompiler::INPUT_SCHEMA,
        'generated_html' => '<!doctype html><html><head><script type="application/ld+json">{"name":"metadata"}</script></head><body><main><h1>Cafe</h1></main><script defer>document.documentElement.classList.add("hydrated");</script></body></html>',
    )
)->toArray();
$artifactInlineScriptAssets = $artifactInlineScript['source_reports']['materialization_plan']['assets'] ?? array();
$artifactInlineScriptAsset = array_values(array_filter($artifactInlineScriptAssets, static fn (array $asset): bool => 'inline-script' === ($asset['source'] ?? '')))[0] ?? array();
$assert('js' === ($artifactInlineScriptAsset['kind'] ?? ''), 'artifact inline executable script becomes a JS materialization asset');
$assert('script' === ($artifactInlineScriptAsset['role'] ?? ''), 'artifact inline executable script asset has script role');
$assert('behavior' === ($artifactInlineScriptAsset['intent'] ?? ''), 'artifact inline executable script asset has behavior intent');
$assert('body' === ($artifactInlineScriptAsset['placement'] ?? ''), 'artifact inline executable script placement is preserved');
$assert(true === ($artifactInlineScriptAsset['defer'] ?? false), 'artifact inline executable script defer metadata is preserved');
$assert('index.inline-2.js' === ($artifactInlineScriptAsset['path'] ?? ''), 'artifact inline executable script path is stable and indexed by source script position');
$assert('script:nth-of-type(2)' === ($artifactInlineScriptAsset['selector'] ?? ''), 'artifact inline executable script selector is preserved');
$assert(str_contains((string) ($artifactInlineScriptAsset['content'] ?? ''), 'classList.add'), 'artifact inline executable script content is preserved');
$assert(in_array('index.inline-2.js', $artifactInlineScript['source_reports']['materialization_plan']['theme']['scripts'] ?? array(), true), 'artifact inline executable script is exposed as a theme script');
$assert(! str_contains((string) ($artifactInlineScript['serialized_blocks'] ?? ''), '<!-- wp:html'), 'artifact materialized inline script does not become a core/html fallback block');
$assert(! str_contains((string) ($artifactInlineScript['serialized_blocks'] ?? ''), 'classList.add'), 'artifact materialized inline script body is removed from serialized block content');

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

// Negative: the base/body font-family is the document's foundational typography
// and must survive into materialized output even when declared only in an inline
// <style> block (no link, no static css). It is carried into the base typography
// the transformer emits, so it must NOT surface a typography_font_family_dropped:body finding.
$inlineBodyFontResult = ( new HtmlTransformer() )->transform(
    '<!doctype html><html><head><style>body{font-family:"Brand Sans",sans-serif}</style></head><body><main><h1>Heading</h1><p>Copy</p></main></body></html>',
    array()
)->toArray();
$inlineBodyDropped = $findingsByCode($semanticFindings($inlineBodyFontResult), 'typography_font_family_dropped');
$assert(array() === $inlineBodyDropped, 'inline <style> base/body font-family is materialized and not reported dropped');
$inlineBodyPlan = ( new FontMaterializationPlanBuilder() )->fromWebFontSources(
    '<head><style>body{font-family:"Brand Sans",sans-serif}</style></head>',
    ''
);
$assert('Brand Sans' === ($inlineBodyPlan['roles']['body'] ?? null), 'inline <style> base/body font-family flows into materialized body role');
$assert('Brand Sans' === ($inlineBodyPlan['fonts'][0]['family'] ?? null), 'inline <style> base/body font-family is preserved in materialized fonts');

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
$assert('static-site-importer/companion-plugin/v1' === ($companionPayload['schema'] ?? ''), 'companion payload stamps the shared consumer schema');
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

$companionNoSite = $compiler->compile(
    array(
        'files' => array(
            'index.html'             => '<main></main>',
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

if ( ! str_contains($result['serialized_blocks'], '<!-- wp:heading {"content":"Hello blocks","level":1} -->') ) {
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
    . '<p class="lede" style="color:#222;line-height:1.6">Styled paragraph</p>'
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
assertSame('flex', $hero['attrs']['layout']['type'] ?? null, 'display:flex maps to the layout attribute');
$assert(! is_string($hero['attrs']['style'] ?? null), 'container style is never a raw string');
assertSame('#fff', $hero['attrs']['style']['color']['text'] ?? null, 'container color maps to style.color.text');
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
assertStringContains('<h1 class="wp-block-heading">Title</h1>', $markdownToHtmlResult['documents'][0]['content'], 'Markdown should convert to HTML through the block pivot with the canonical heading class core save() emits.');
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
