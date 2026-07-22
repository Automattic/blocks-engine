<?php

declare(strict_types=1);

use Automattic\BlocksEngine\FigmaTransformer\Html\LayoutIntentClassifier;
use Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter;
use Automattic\BlocksEngine\FigmaTransformer\Html\TransformDiagnosticsBuilder;
use Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ScenegraphPagePlanner;
use Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ScenegraphNormalizer;

/**
 * @param callable(bool, string): void $assert
 */
function blocks_engine_figma_transformer_run_site_generation_quality_contract(callable $assert, callable $fileContent, callable $artifactQualitySignalCodes, callable $artifactQualitySignal): void
{
    $templateArtifactResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Template Artifact Fixture',
        'nodes' => array(
            array(
                'id'       => 'template:home',
                'type'     => 'FRAME',
                'name'     => 'Home Page Desktop',
                'width'    => 1440,
                'height'   => 900,
                'children' => array(
                    array('id' => 'template:home:header', 'type' => 'FRAME', 'name' => 'Header', 'width' => 1440, 'height' => 80),
                    array('id' => 'template:home:hero', 'type' => 'TEXT', 'name' => 'Heading', 'text' => 'Welcome Home', 'fontSize' => 48),
                    array('id' => 'template:home:footer', 'type' => 'FRAME', 'name' => 'Footer', 'width' => 1440, 'height' => 120),
                ),
            ),
            array(
                'id'       => 'template:single',
                'type'     => 'FRAME',
                'name'     => 'Blog Post Desktop',
                'width'    => 1440,
                'height'   => 1200,
                'children' => array(
                    array('id' => 'template:single:header', 'type' => 'FRAME', 'name' => 'Header', 'width' => 1440, 'height' => 80),
                    array('id' => 'template:single:title', 'type' => 'TEXT', 'name' => 'Heading', 'text' => 'Single Post Title', 'fontSize' => 48),
                    array(
                        'id'       => 'template:single:content',
                        'type'     => 'FRAME',
                        'name'     => 'Entry Content',
                        'width'    => 760,
                        'height'   => 640,
                        'children' => array(
                            array('id' => 'template:single:p1', 'type' => 'TEXT', 'name' => 'Paragraph', 'text' => 'Article paragraph one.', 'fontSize' => 18),
                            array('id' => 'template:single:p2', 'type' => 'TEXT', 'name' => 'Paragraph', 'text' => 'Article paragraph two.', 'fontSize' => 18),
                        ),
                    ),
                    array('id' => 'template:single:comments', 'type' => 'FRAME', 'name' => 'Comments', 'width' => 760, 'height' => 220),
                    array('id' => 'template:single:footer', 'type' => 'FRAME', 'name' => 'Footer', 'width' => 1440, 'height' => 120),
                ),
            ),
            array(
                'id'       => 'template:archive',
                'type'     => 'FRAME',
                'name'     => 'Archive Desktop',
                'width'    => 1440,
                'height'   => 900,
                'children' => array(
                    array('id' => 'template:archive:header', 'type' => 'FRAME', 'name' => 'Header', 'width' => 1440, 'height' => 80),
                    array('id' => 'template:archive:title', 'type' => 'TEXT', 'name' => 'Heading', 'text' => 'Archive', 'fontSize' => 44),
                    array('id' => 'template:archive:footer', 'type' => 'FRAME', 'name' => 'Footer', 'width' => 1440, 'height' => 120),
                ),
            ),
            array(
                'id'       => 'template:404',
                'type'     => 'FRAME',
                'name'     => '404 Page Desktop',
                'width'    => 1440,
                'height'   => 720,
                'children' => array(
                    array('id' => 'template:404:header', 'type' => 'FRAME', 'name' => 'Header', 'width' => 1440, 'height' => 80),
                    array('id' => 'template:404:title', 'type' => 'TEXT', 'name' => 'Heading', 'text' => 'Page not found', 'fontSize' => 44),
                    array('id' => 'template:404:footer', 'type' => 'FRAME', 'name' => 'Footer', 'width' => 1440, 'height' => 120),
                ),
            ),
        ),
    ), array('multi_page' => true, 'max_pages' => 10));
    $templatePaths = array_values(array_map(
        static fn (array $file): string => (string) ($file['path'] ?? ''),
        array_filter($templateArtifactResult['files'] ?? array(), static fn (array $file): bool => 'text/html' === ($file['mime_type'] ?? null))
    ));
    $assert(array_values(array_intersect(array('index.html', 'single.html', 'archive.html', '404.html'), $templatePaths)) === array('index.html', 'single.html', 'archive.html', '404.html'), 'multi-template-artifact-emits-canonical-html-paths');
    $singleTemplateHtml = $fileContent($templateArtifactResult, 'single.html');
    $assert(str_contains($singleTemplateHtml, 'data-template-type="single"') && str_contains($singleTemplateHtml, 'data-template-slug="single"'), 'single-template-root-carries-template-metadata');
    $assert(str_contains($singleTemplateHtml, 'data-template-area="header"') && str_contains($singleTemplateHtml, 'data-template-area="content"') && str_contains($singleTemplateHtml, 'data-template-area="comments"') && str_contains($singleTemplateHtml, 'data-template-area="footer"'), 'single-template-carries-semantic-area-metadata');
    $singleHtmlFile = null;
    foreach ( $templateArtifactResult['files'] ?? array() as $file ) {
        if ( is_array($file) && 'single.html' === ($file['path'] ?? null) ) {
            $singleHtmlFile = $file;
            break;
        }
    }
    $assert('single' === ($singleHtmlFile['page_type'] ?? null), 'single-template-html-file-carries-page-type');
    $assert('single' === ($singleHtmlFile['template_slug'] ?? null), 'single-template-html-file-carries-template-slug');
    $assert('single.html' === ($singleHtmlFile['canonical_template_path'] ?? null), 'single-template-html-file-carries-canonical-template-path');
    $assert('template:single' === ($singleHtmlFile['source_frame_identity']['primary_frame_id'] ?? null), 'single-template-html-file-carries-source-frame-identity');
    $reportedTemplatePaths = array();
    foreach ( $templateArtifactResult['source_reports']['figma']['html']['pages'] ?? array() as $pageReport ) {
        if ( is_array($pageReport) && isset($pageReport['canonical_template_path'], $pageReport['page_type']) ) {
            $reportedTemplatePaths[(string) $pageReport['canonical_template_path']] = (string) $pageReport['page_type'];
        }
    }
    $assert('single' === ($reportedTemplatePaths['single.html'] ?? null) && 'archive' === ($reportedTemplatePaths['archive.html'] ?? null) && '404' === ($reportedTemplatePaths['404.html'] ?? null), 'template-source-report-preserves-page-types');

    $responsivePrimaryResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Responsive Primary Selection Fixture',
        'nodes' => array(
            array(
                'id'       => 'responsive-primary:wide-short',
                'type'     => 'FRAME',
                'name'     => 'Home Page Desktop',
                'width'    => 2238,
                'height'   => 869,
                'children' => array(
                    array('id' => 'responsive-primary:wide-title', 'type' => 'TEXT', 'name' => 'Heading', 'text' => 'Wide exploration', 'fontSize' => 48),
                    array('id' => 'responsive-primary:wide-card', 'type' => 'TEXT', 'name' => 'Preview card', 'text' => 'Short preview', 'fontSize' => 18),
                ),
            ),
            array(
                'id'       => 'responsive-primary:desktop-long',
                'type'     => 'FRAME',
                'name'     => 'Home Page Desktop',
                'width'    => 1440,
                'height'   => 4421,
                'children' => array(
                    array('id' => 'responsive-primary:desktop-hero', 'type' => 'TEXT', 'name' => 'Heading', 'text' => 'Full homepage', 'fontSize' => 56),
                    array('id' => 'responsive-primary:desktop-intro', 'type' => 'TEXT', 'name' => 'Intro', 'text' => 'Intro copy', 'fontSize' => 20),
                    array('id' => 'responsive-primary:desktop-service-1', 'type' => 'TEXT', 'name' => 'Service', 'text' => 'Service one', 'fontSize' => 18),
                    array('id' => 'responsive-primary:desktop-service-2', 'type' => 'TEXT', 'name' => 'Service', 'text' => 'Service two', 'fontSize' => 18),
                    array('id' => 'responsive-primary:desktop-service-3', 'type' => 'TEXT', 'name' => 'Service', 'text' => 'Service three', 'fontSize' => 18),
                    array('id' => 'responsive-primary:desktop-feature-1', 'type' => 'TEXT', 'name' => 'Feature', 'text' => 'Feature one', 'fontSize' => 18),
                    array('id' => 'responsive-primary:desktop-feature-2', 'type' => 'TEXT', 'name' => 'Feature', 'text' => 'Feature two', 'fontSize' => 18),
                    array('id' => 'responsive-primary:desktop-feature-3', 'type' => 'TEXT', 'name' => 'Feature', 'text' => 'Feature three', 'fontSize' => 18),
                    array('id' => 'responsive-primary:desktop-quote', 'type' => 'TEXT', 'name' => 'Quote', 'text' => 'Testimonial', 'fontSize' => 22),
                    array('id' => 'responsive-primary:desktop-footer', 'type' => 'TEXT', 'name' => 'Footer', 'text' => 'Footer copy', 'fontSize' => 16),
                ),
            ),
            array(
                'id'       => 'responsive-primary:mobile',
                'type'     => 'FRAME',
                'name'     => 'Home Page Mobile',
                'width'    => 390,
                'height'   => 8142,
                'children' => array(
                    array('id' => 'responsive-primary:mobile-hero', 'type' => 'TEXT', 'name' => 'Heading', 'text' => 'Full homepage', 'fontSize' => 36),
                    array('id' => 'responsive-primary:mobile-copy', 'type' => 'TEXT', 'name' => 'Intro', 'text' => 'Mobile copy', 'fontSize' => 18),
                ),
            ),
        ),
    ), array('multi_page' => true));
    $responsivePrimaryPage = $responsivePrimaryResult['source_reports']['figma']['pages']['pages'][0] ?? array();
    $responsivePrimaryVariantIds = array_values(array_map(
        static fn (array $variant): string => (string) ($variant['frame_id'] ?? ''),
        is_array($responsivePrimaryPage['variants'] ?? null) ? $responsivePrimaryPage['variants'] : array()
    ));
    $assert('responsive-primary:desktop-long' === ($responsivePrimaryPage['frame_id'] ?? null), 'responsive-primary-selection-prefers-long-desktop-page');
    $assert(in_array('responsive-primary:mobile', $responsivePrimaryVariantIds, true), 'responsive-primary-selection-preserves-mobile-variant');

    $singlePageArtifactResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Single Page Artifact Fixture',
        'nodes' => array(
            array(
                'id'       => 'single-page:archive-like',
                'type'     => 'FRAME',
                'name'     => 'Updates',
                'width'    => 1440,
                'height'   => 1200,
                'children' => array(
                    array('id' => 'single-page:title', 'type' => 'TEXT', 'name' => 'Heading', 'text' => 'Updates', 'fontSize' => 48),
                    array('id' => 'single-page:card-1', 'type' => 'TEXT', 'name' => 'Card', 'text' => 'One', 'fontSize' => 18),
                    array('id' => 'single-page:card-2', 'type' => 'TEXT', 'name' => 'Card', 'text' => 'Two', 'fontSize' => 18),
                ),
            ),
        ),
    ), array('multi_page' => true));
    $singlePageHtmlPaths = array_values(array_map(
        static fn (array $file): string => (string) ($file['path'] ?? ''),
        array_filter($singlePageArtifactResult['files'] ?? array(), static fn (array $file): bool => 'text/html' === ($file['mime_type'] ?? null))
    ));
    $assert(array('index.html') === $singlePageHtmlPaths, 'single-page-artifact-does-not-emit-template-alias-html');

    $reservedAliasArtifactResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Reserved Alias Artifact Fixture',
        'nodes' => array(
            array(
                'id'       => 'reserved-alias:home',
                'type'     => 'FRAME',
                'name'     => 'Home Page',
                'width'    => 1440,
                'height'   => 900,
                'children' => array(array('id' => 'reserved-alias:home:title', 'type' => 'TEXT', 'name' => 'Heading', 'text' => 'Home', 'fontSize' => 48)),
            ),
            array(
                'id'       => 'reserved-alias:single-template',
                'type'     => 'FRAME',
                'name'     => 'Blog Post',
                'width'    => 1440,
                'height'   => 900,
                'children' => array(array('id' => 'reserved-alias:single-template:title', 'type' => 'TEXT', 'name' => 'Heading', 'text' => 'Canonical single', 'fontSize' => 48)),
            ),
            array(
                'id'       => 'reserved-alias:archive-template',
                'type'     => 'FRAME',
                'name'     => 'News',
                'width'    => 1440,
                'height'   => 900,
                'children' => array(array('id' => 'reserved-alias:archive-template:title', 'type' => 'TEXT', 'name' => 'Heading', 'text' => 'Canonical archive', 'fontSize' => 48)),
            ),
            array(
                'id'       => 'reserved-alias:404-template',
                'type'     => 'FRAME',
                'name'     => '404 Page',
                'width'    => 1440,
                'height'   => 900,
                'children' => array(array('id' => 'reserved-alias:404-template:title', 'type' => 'TEXT', 'name' => 'Heading', 'text' => 'Canonical not found', 'fontSize' => 48)),
            ),
            array(
                'id'       => 'reserved-alias:normal-single',
                'type'     => 'FRAME',
                'name'     => 'Services Page',
                'width'    => 1440,
                'height'   => 900,
                'children' => array(array('id' => 'reserved-alias:normal-single:title', 'type' => 'TEXT', 'name' => 'Heading', 'text' => 'Normal single route', 'fontSize' => 48)),
            ),
            array(
                'id'       => 'reserved-alias:normal-archive',
                'type'     => 'FRAME',
                'name'     => 'Team Page',
                'width'    => 1440,
                'height'   => 900,
                'children' => array(array('id' => 'reserved-alias:normal-archive:title', 'type' => 'TEXT', 'name' => 'Heading', 'text' => 'Normal archive route', 'fontSize' => 48)),
            ),
            array(
                'id'       => 'reserved-alias:normal-404',
                'type'     => 'FRAME',
                'name'     => 'Pricing Page',
                'width'    => 1440,
                'height'   => 900,
                'children' => array(array('id' => 'reserved-alias:normal-404:title', 'type' => 'TEXT', 'name' => 'Heading', 'text' => 'Normal 404 route', 'fontSize' => 48)),
            ),
        ),
    ), array(
        'multi_page' => true,
        'frame_slug_map' => array(
            'reserved-alias:normal-single' => 'single',
            'reserved-alias:normal-archive' => 'archive',
            'reserved-alias:normal-404' => '404',
        ),
    ));
    $reservedAliasFilesByPath = array();
    foreach ( $reservedAliasArtifactResult['files'] ?? array() as $file ) {
        if ( is_array($file) && isset($file['path']) && is_scalar($file['path']) ) {
            $reservedAliasFilesByPath[(string) $file['path']][] = $file;
        }
    }
    foreach ( array('single.html', 'archive.html', '404.html') as $canonicalPath ) {
        $assert(1 === count($reservedAliasFilesByPath[$canonicalPath] ?? array()), 'reserved-alias-artifact-emits-one-' . $canonicalPath);
    }
    $assert('reserved-alias:single-template' === ($reservedAliasFilesByPath['single.html'][0]['source_frame_identity']['primary_frame_id'] ?? null), 'reserved-alias-artifact-single-alias-is-not-overwritten-by-normal-route');
    $assert('reserved-alias:archive-template' === ($reservedAliasFilesByPath['archive.html'][0]['source_frame_identity']['primary_frame_id'] ?? null), 'reserved-alias-artifact-archive-alias-is-not-overwritten-by-normal-route');
    $assert('reserved-alias:404-template' === ($reservedAliasFilesByPath['404.html'][0]['source_frame_identity']['primary_frame_id'] ?? null), 'reserved-alias-artifact-404-alias-is-not-overwritten-by-normal-route');
    $assert(isset($reservedAliasFilesByPath['single-2.html'], $reservedAliasFilesByPath['archive-2.html'], $reservedAliasFilesByPath['404-2.html']), 'reserved-alias-artifact-normal-routes-are-deduplicated');

    $qualityAssets = array();
    for ( $i = 1; $i <= 20; $i++ ) {
        $qualityAssets['quality-image-' . $i] = array('mime_type' => 'image/png', 'content' => 'image ' . $i);
    }
    $qualityChildren = array(
        array(
            'id'       => 'quality:header',
            'type'     => 'GROUP',
            'name'     => 'Site Header Raster Group',
            'width'    => 1440,
            'height'   => 120,
            'children' => array(
                array('id' => 'quality:header:1', 'type' => 'RECTANGLE', 'name' => 'Header slice 1', 'width' => 300, 'height' => 80, 'asset_id' => 'quality-image-1'),
                array('id' => 'quality:header:2', 'type' => 'RECTANGLE', 'name' => 'Header slice 2', 'width' => 300, 'height' => 80, 'asset_id' => 'quality-image-2'),
                array('id' => 'quality:header:3', 'type' => 'RECTANGLE', 'name' => 'Header slice 3', 'width' => 300, 'height' => 80, 'asset_id' => 'quality-image-3'),
            ),
        ),
        array('id' => 'quality:offcanvas', 'type' => 'RECTANGLE', 'name' => 'Off canvas promo', 'x' => 2000, 'y' => -180, 'width' => 200, 'height' => 100, 'layoutPositioning' => 'ABSOLUTE', 'asset_id' => 'quality-image-4'),
    );
    for ( $i = 5; $i <= 12; $i++ ) {
        $qualityChildren[] = array('id' => 'quality:image:' . $i, 'type' => 'RECTANGLE', 'name' => 'Raster card ' . $i, 'width' => 80, 'height' => 60, 'asset_id' => 'quality-image-' . $i);
    }
    for ( $i = 13; $i <= 20; $i++ ) {
        $qualityChildren[] = array('id' => 'quality:vector:' . $i, 'type' => 'VECTOR', 'name' => 'Vector fallback ' . $i, 'width' => 24, 'height' => 24, 'asset_id' => 'quality-image-' . $i);
    }
    $qualityDiagnosticsResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'   => 'Quality Diagnostics Fixture',
        'assets' => $qualityAssets,
        'nodes'  => array(
            array(
                'id'       => 'quality:root',
                'type'     => 'FRAME',
                'name'     => 'Desktop fixed root',
                'width'    => 1440,
                'height'   => 1200,
                'layoutMode' => 'VERTICAL',
                'children' => $qualityChildren,
            ),
        ),
    ));
    $qualitySignalCodes = $artifactQualitySignalCodes($qualityDiagnosticsResult);
    $assert(! in_array('fixed_root_width', $qualitySignalCodes, true), 'quality-diagnostics-fixed-root-width-retired');
    $qualityCss = $fileContent($qualityDiagnosticsResult, 'style.css');
    $qualityRootRule = blocks_engine_figma_transformer_contract_css_rule($qualityCss, '.figma-node-quality-root-desktop-fixed-root');
    $assert(str_contains($qualityRootRule, 'width:100%') && ! str_contains($qualityRootRule, 'max-width:1440px'), 'quality-diagnostics-root-fills-viewport-without-implicit-canvas-cap');

    $flowScaffoldResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Flow Scaffold Fixture',
        'nodes' => array(
            array(
                'id'       => 'flow-scaffold:root',
                'type'     => 'FRAME',
                'name'     => 'Content Section',
                'width'    => 720,
                'height'   => 420,
                'children' => array(
                    array(
                        'id'                => 'flow-scaffold:intro',
                        'type'              => 'TEXT',
                        'name'              => 'Intro heading',
                        'text'              => 'Flow content heading',
                        'x'                 => 48,
                        'y'                 => 64,
                        'width'             => 360,
                        'height'            => 48,
                        'layoutPositioning' => 'ABSOLUTE',
                    ),
                    array(
                        'id'                => 'flow-scaffold:body',
                        'type'              => 'TEXT',
                        'name'              => 'Body copy',
                        'text'              => 'Body copy that follows the heading in source order.',
                        'x'                 => 48,
                        'y'                 => 136,
                        'width'             => 420,
                        'height'            => 64,
                        'layoutPositioning' => 'ABSOLUTE',
                    ),
                    array(
                        'id'                => 'flow-scaffold:cta',
                        'type'              => 'TEXT',
                        'name'              => 'Call to action',
                        'text'              => 'Read more',
                        'x'                 => 48,
                        'y'                 => 232,
                        'width'             => 120,
                        'height'            => 32,
                        'layoutPositioning' => 'ABSOLUTE',
                    ),
                ),
            ),
        ),
    ));
    $flowScaffoldHtml = $fileContent($flowScaffoldResult, 'index.html');
    $flowScaffoldCss = $fileContent($flowScaffoldResult, 'style.css');
    $flowScaffoldRootRule = blocks_engine_figma_transformer_contract_css_rule($flowScaffoldCss, '.figma-node-flow-scaffold-root-content-section');
    $flowScaffoldIntroRule = blocks_engine_figma_transformer_contract_css_rule($flowScaffoldCss, '.figma-node-flow-scaffold-intro-intro-heading');
    $flowScaffoldBodyRule = blocks_engine_figma_transformer_contract_css_rule($flowScaffoldCss, '.figma-node-flow-scaffold-body-body-copy');
    $assert(str_contains($flowScaffoldHtml, 'data-figma-layout-intent="nav-row"'), 'flow-scaffold-section-emits-layout-intent');
    $assert(str_contains($flowScaffoldRootRule, 'display:flex') && str_contains($flowScaffoldRootRule, 'flex-direction:row'), 'flow-scaffold-section-emits-flow-css');
    $assert(! str_contains($flowScaffoldIntroRule, 'position:absolute') && ! str_contains($flowScaffoldIntroRule, 'left:') && ! str_contains($flowScaffoldIntroRule, 'top:'), 'flow-scaffold-absolute-heading-enters-normal-flow');
    $assert(! str_contains($flowScaffoldBodyRule, 'position:absolute') && ! str_contains($flowScaffoldBodyRule, 'left:') && ! str_contains($flowScaffoldBodyRule, 'top:'), 'flow-scaffold-absolute-body-enters-normal-flow');

    $socialIconResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'   => 'Social Icon Fixture',
        'assets' => array(
            'social-mask-image' => array('mime_type' => 'image/png', 'content' => 'social icon'),
        ),
        'nodes'  => array(
            array(
                'id'       => 'social:page',
                'type'     => 'FRAME',
                'name'     => 'Home',
                'width'    => 1440,
                'height'   => 320,
                'children' => array(
                    array(
                        'id'       => 'social:footer',
                        'type'     => 'FRAME',
                        'name'     => 'Footer',
                        'width'    => 1440,
                        'height'   => 160,
                        'children' => array(
                            array(
                                'id'       => 'social:icons',
                                'type'     => 'FRAME',
                                'name'     => 'Social',
                                'x'        => 1200,
                                'y'        => 64,
                                'width'    => 54,
                                'height'   => 24,
                                'children' => array(
                                    array(
                                        'id'       => 'social:facebook-object',
                                        'type'     => 'FRAME',
                                        'name'     => 'Object',
                                        'width'    => 24,
                                        'height'   => 24,
                                        'children' => array(
                                            array('id' => 'social:facebook-image', 'type' => 'RECTANGLE', 'name' => '001-facebook-logo', 'width' => 24, 'height' => 24, 'asset_id' => 'social-mask-image'),
                                            array('id' => 'social:facebook-color', 'type' => 'RECTANGLE', 'name' => '001-facebook-logo', 'width' => 24, 'height' => 24, 'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 1, 'b' => 1, 'a' => 1)))),
                                        ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $socialIconHtml = $fileContent($socialIconResult, 'index.html');
    $socialIconCss = $fileContent($socialIconResult, 'style.css');
    $assert(! str_contains($socialIconHtml, '<a class="figma-link" href="index.html" data-figma-link-type="implicit-route"><div class="figma-node-social-facebook-image-001-facebook-logo'), 'social-icon-logo-name-does-not-create-implicit-home-route');
    $assert(str_contains($socialIconCss, '.figma-node-social-facebook-color-001-facebook-logo{width:24px;height:24px;') && str_contains($socialIconCss, 'background:#ffffff') && str_contains($socialIconCss, 'mask-image:url("assets/social-mask-image'), 'social-icon-solid-overlay-uses-image-mask');

    $zeroAreaScaffoldResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Zero Area Scaffold Fixture',
        'nodes' => array(
            array(
                'id'       => 'zero-scaffold:root',
                'type'     => 'FRAME',
                'name'     => 'Desktop page',
                'width'    => 320,
                'height'   => 160,
                'children' => array(
                    array(
                        'id'      => 'zero-scaffold:helper',
                        'type'    => 'FRAME',
                        'name'    => 'Invisible crop helper',
                        'width'   => 120,
                        'height'  => 0.0002,
                        'opacity' => 0,
                    ),
                    array(
                        'id'      => 'zero-scaffold:line',
                        'type'    => 'VECTOR',
                        'name'    => 'Vector separator',
                        'width'   => 120,
                        'height'  => 0,
                        'pathData' => 'M0 0H120',
                        'strokes' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 0, 'a' => 1))),
                    ),
                ),
            ),
        ),
    ));
    $zeroAreaScaffoldHtml = $fileContent($zeroAreaScaffoldResult, 'index.html');
    $zeroAreaScaffoldCss = $fileContent($zeroAreaScaffoldResult, 'style.css');
    $assert(! str_contains($zeroAreaScaffoldHtml, 'data-figma-node-id="zero-scaffold:helper"'), 'invisible-zero-area-scaffold-suppressed-from-html');
    $assert(! str_contains($zeroAreaScaffoldCss, '.figma-node-zero-scaffold-helper-invisible-crop-helper'), 'invisible-zero-area-scaffold-suppressed-from-css');
    $assert(str_contains($zeroAreaScaffoldHtml, 'data-figma-node-id="zero-scaffold:line"'), 'zero-height-vector-line-still-renders');

    $normalizer = new ScenegraphNormalizer();
    $localVectorStack = $normalizer->normalize(array(
        'nodes' => array(
            array(
                'id'       => 'local-vector-stack:root',
                'type'     => 'FRAME',
                'name'     => 'Desktop page',
                'width'    => 320,
                'height'   => 320,
                'children' => array(
                    array(
                        'id'                 => 'local-vector-stack:timeline',
                        'type'               => 'GROUP',
                        'name'               => 'Timeline dots',
                        'x'                  => 120,
                        'y'                  => 64,
                        'width'              => 20,
                        'height'             => 120,
                        'stackReverseZIndex' => true,
                        'children'           => array(
                            array('id' => 'local-vector-stack:track', 'type' => 'RECTANGLE', 'name' => 'Track', 'x' => 8, 'y' => 0, 'width' => 4, 'height' => 120),
                            array('id' => 'local-vector-stack:dot', 'type' => 'ELLIPSE', 'name' => 'Dot', 'x' => 5, 'y' => 8, 'width' => 10, 'height' => 10),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $localTrackLayout = $localVectorStack['node_map']['local-vector-stack:track']['layout'] ?? array();
    $localDotLayout = $localVectorStack['node_map']['local-vector-stack:dot']['layout'] ?? array();
    $assert(! isset($localTrackLayout['z_index'], $localDotLayout['z_index']), 'local-vector-stack-reverse-z-index-does-not-override-freeform-source-order');

    $layoutIntent = new LayoutIntentClassifier();
    $trackNode = array(
        'id'   => 'local-vector-stack:track',
        'type' => 'ROUNDED_RECTANGLE',
        'name' => 'Track',
        'box'  => array('x' => 0, 'y' => 0, 'width' => 17, 'height' => 920, 'coordinate_space' => 'local'),
        'figma_paints' => array('fills' => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 1, 'b' => 1, 'a' => 1)))),
    );
    $dotNode = array(
        'id'   => 'local-vector-stack:dot',
        'type' => 'ELLIPSE',
        'name' => 'Dot',
        'box'  => array('x' => 4, 'y' => 7, 'width' => 10, 'height' => 10, 'coordinate_space' => 'local'),
        'figma_paints' => array('fills' => array(array('type' => 'SOLID', 'color' => array('r' => 0.8, 'g' => 0.88, 'b' => 0.53, 'a' => 1)))),
    );
    $localTimelineParent = array(
        'id'       => 'local-vector-stack:timeline',
        'type'     => 'GROUP',
        'name'     => 'Timeline dots',
        'box'      => array('x' => 177, 'y' => 1168, 'width' => 17, 'height' => 923, 'coordinate_space' => 'local'),
        'children' => array($trackNode, $dotNode),
    );
    $trackStackPlan = $layoutIntent->stackingContextPlan($trackNode, $localTimelineParent);
    $dotStackPlan = $layoutIntent->stackingContextPlan($dotNode, $localTimelineParent);
    $assert(LayoutIntentClassifier::LAYER_ROLE_UNDERLAY === ($trackStackPlan['sibling_role'] ?? null), 'local-vector-track-classifies-as-decorative-underlay');
    $assert(($dotStackPlan['z_index'] ?? null) > ($trackStackPlan['z_index'] ?? null), 'local-vector-marker-layers-above-track');

    $autoLayoutStack = $normalizer->normalize(array(
        'nodes' => array(
            array(
                'id'       => 'auto-stack:root',
                'type'     => 'FRAME',
                'name'     => 'Desktop page',
                'width'    => 320,
                'height'   => 320,
                'children' => array(
                    array(
                        'id'                 => 'auto-stack:row',
                        'type'               => 'FRAME',
                        'name'               => 'Auto row',
                        'width'              => 120,
                        'height'             => 40,
                        'layoutMode'         => 'HORIZONTAL',
                        'stackReverseZIndex' => true,
                        'children'           => array(
                            array('id' => 'auto-stack:first', 'type' => 'RECTANGLE', 'name' => 'First', 'width' => 40, 'height' => 40),
                            array('id' => 'auto-stack:second', 'type' => 'RECTANGLE', 'name' => 'Second', 'width' => 40, 'height' => 40),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $firstLayout = $autoLayoutStack['node_map']['auto-stack:first']['layout'] ?? array();
    $secondLayout = $autoLayoutStack['node_map']['auto-stack:second']['layout'] ?? array();
    $assert(2 === ($firstLayout['z_index'] ?? null) && 1 === ($secondLayout['z_index'] ?? null), 'auto-layout-stack-reverse-z-index-still-applies');

    $explicitMaxWidthResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Explicit Root Max Width Fixture',
        'nodes' => array(
            array(
                'id'       => 'explicit-max:root',
                'type'     => 'FRAME',
                'name'     => 'Desktop constrained root',
                'width'    => 1440,
                'height'   => 900,
                'maxWidth' => 1200,
            ),
        ),
    ));
    $explicitMaxWidthCss = $fileContent($explicitMaxWidthResult, 'style.css');
    $assert(str_contains($explicitMaxWidthCss, '.figma-node-explicit-max-root-desktop-constrained-root{width:100%;height:900px;max-width:1200px'), 'quality-diagnostics-root-honors-explicit-max-width');

    $fullWidthBandResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Full Width Band Fixture',
        'nodes' => array(
            array(
                'id'       => 'fluid-band:root',
                'type'     => 'FRAME',
                'name'     => 'Desktop page',
                'width'    => 1440,
                'height'   => 1200,
                'layoutMode' => 'VERTICAL',
                'layoutSizingHorizontal' => 'FILL',
                'paddingLeft' => 112,
                'paddingRight' => 112,
                'children' => array(
                    array(
                        'id'       => 'fluid-band:hero',
                        'type'     => 'FRAME',
                        'name'     => 'Hero band',
                        'width'    => 1440,
                        'height'   => 520,
                        'layoutSizingHorizontal' => 'FILL',
                        'children' => array(
                            array('id' => 'fluid-band:bg', 'type' => 'RECTANGLE', 'name' => 'Full bleed background', 'x' => 0, 'y' => 0, 'width' => 1440, 'height' => 520),
                            array('id' => 'fluid-band:content', 'type' => 'FRAME', 'name' => 'Centered content shell', 'x' => 112, 'y' => 80, 'width' => 1216, 'height' => 240),
                            array('id' => 'fluid-band:title', 'type' => 'TEXT', 'name' => 'Hero title', 'characters' => 'Fluid hero', 'width' => 320, 'height' => 48, 'fontSize' => 36),
                        ),
                    ),
                    array(
                        'id'       => 'fluid-band:card',
                        'type'     => 'FRAME',
                        'name'     => 'Narrow card',
                        'width'    => 420,
                        'height'   => 240,
                    ),
                ),
            ),
        ),
    ));
    $fullWidthBandCss = $fileContent($fullWidthBandResult, 'style.css');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $fullWidthBandCss, '.figma-node-fluid-band-root-desktop-page', array('width:100%', 'padding-right:clamp(24px,calc((100% - 1216px) / 2),112px)', 'padding-left:clamp(24px,calc((100% - 1216px) / 2),112px)'), 'quality-diagnostics-fluid-root-uses-clamped-gutters');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $fullWidthBandCss, '.figma-node-fluid-band-root-desktop-page', array('min-height:1200px'), 'quality-diagnostics-normal-fluid-root-keeps-source-height-floor');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $fullWidthBandCss, '.figma-node-fluid-band-hero-hero-band', array('width:100%'), 'quality-diagnostics-full-width-band-renders-fluid');
    $assert(str_contains($fullWidthBandCss, '.figma-node-fluid-band-bg-full-bleed-background{width:100vw;height:520px;position:absolute;top:0px;left:50%;margin-left:-50vw'), 'quality-diagnostics-fluid-band-full-bleed-absolute-child-breaks-out-to-viewport');
    $assert(str_contains($fullWidthBandCss, '.figma-node-fluid-band-content-centered-content-shell{width:1216px;height:240px;position:absolute;left:calc(50% - 608px);top:80px'), 'quality-diagnostics-fluid-band-absolute-child-centers-in-intrinsic-canvas');
    $assert(str_contains($fullWidthBandCss, '.figma-node-fluid-band-card-narrow-card{width:420px;height:240px;'), 'quality-diagnostics-narrow-band-keeps-intrinsic-width');

    $responsiveFullBleedBandResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Responsive Full Bleed Band Fixture',
        'nodes' => array(
            array(
                'id'       => 'responsive-band:desktop',
                'type'     => 'FRAME',
                'name'     => 'Services Desktop',
                'width'    => 1440,
                'height'   => 900,
                'children' => array(
                    array('id' => 'responsive-band:desktop-band', 'type' => 'RECTANGLE', 'name' => 'Section background band', 'x' => 0, 'y' => 320, 'width' => 1443, 'height' => 149, 'layoutPositioning' => 'ABSOLUTE'),
                    array('id' => 'responsive-band:desktop-title', 'type' => 'TEXT', 'name' => 'Page title', 'characters' => 'Services', 'x' => 120, 'y' => 360, 'width' => 280, 'height' => 48, 'fontSize' => 36),
                ),
            ),
            array(
                'id'       => 'responsive-band:mobile',
                'type'     => 'FRAME',
                'name'     => 'Services Mobile',
                'width'    => 390,
                'height'   => 900,
                'children' => array(
                    array('id' => 'responsive-band:mobile-band', 'type' => 'RECTANGLE', 'name' => 'Section background band', 'x' => 0, 'y' => 260, 'width' => 390, 'height' => 149, 'layoutPositioning' => 'ABSOLUTE'),
                    array('id' => 'responsive-band:mobile-title', 'type' => 'TEXT', 'name' => 'Page title', 'characters' => 'Services', 'x' => 24, 'y' => 300, 'width' => 180, 'height' => 42, 'fontSize' => 32),
                ),
            ),
        ),
    ), array(
        'responsive_variants' => array(
            array('frame_id' => 'responsive-band:desktop', 'viewport_width' => 1440, 'primary' => true),
            array('frame_id' => 'responsive-band:mobile', 'viewport_width' => 390),
        ),
    ));
    $responsiveFullBleedBandCss = $fileContent($responsiveFullBleedBandResult, 'style.css');
    $responsiveFullBleedBandMobileBlock = substr($responsiveFullBleedBandCss, strpos($responsiveFullBleedBandCss, '@media'));
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $responsiveFullBleedBandCss, '.figma-node-responsive-band-desktop-band-section-background-band', array('width:100vw', 'left:50%', 'margin-left:-50vw'), 'responsive-full-bleed-band-base-uses-viewport-breakout');
    blocks_engine_figma_transformer_contract_assert_css_rule_omits($assert, $responsiveFullBleedBandMobileBlock, '.figma-node-responsive-band-desktop-band-section-background-band', array('width:100%', 'width:390px', 'left:0px', 'margin-left:0px'), 'responsive-full-bleed-band-mobile-keeps-viewport-breakout');

    $responsiveShellResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Responsive Centered Shell Fixture',
        'nodes' => array(
            array(
                'id'       => 'responsive-shell:root',
                'type'     => 'FRAME',
                'name'     => 'Desktop page',
                'width'    => 1440,
                'height'   => 900,
                'layoutMode' => 'VERTICAL',
                'layoutSizingHorizontal' => 'FILL',
                'children' => array(
                    array(
                        'id'       => 'responsive-shell:band',
                        'type'     => 'FRAME',
                        'name'     => 'Full bleed band',
                        'width'    => 1440,
                        'height'   => 520,
                        'layoutSizingHorizontal' => 'FILL',
                        'layoutMode' => 'VERTICAL',
                        'paddingLeft' => 135,
                        'paddingRight' => 135,
                        'children' => array(
                            array('id' => 'responsive-shell:centered', 'type' => 'FRAME', 'name' => 'Centered content shell', 'x' => 135, 'y' => 16, 'width' => 1170, 'height' => 48),
                            array('id' => 'responsive-shell:padded', 'type' => 'FRAME', 'name' => 'Padded content shell', 'x' => 0, 'y' => 88, 'width' => 1170, 'height' => 48),
                            array('id' => 'responsive-shell:off-center', 'type' => 'FRAME', 'name' => 'Off center card', 'x' => 64, 'y' => 88, 'width' => 420, 'height' => 48),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $responsiveShellCss = $fileContent($responsiveShellResult, 'style.css');
    $responsiveRootRule = blocks_engine_figma_transformer_contract_css_rule($responsiveShellCss, '.figma-node-responsive-shell-root-desktop-page');
    $assert(str_contains($responsiveRootRule, 'width:100%') && ! str_contains($responsiveRootRule, 'max-width:1440px'), 'quality-diagnostics-responsive-shell-root-fills-viewport-without-implicit-canvas-cap');
    $assert(str_contains($responsiveShellCss, '.figma-node-responsive-shell-band-full-bleed-band{width:100%;min-height:520px;'), 'quality-diagnostics-responsive-shell-band-stays-full-bleed');
    $assert(str_contains($responsiveShellCss, 'padding-right:clamp(24px,calc((100% - 1170px) / 2),135px)'), 'quality-diagnostics-responsive-shell-band-uses-responsive-right-gutter');
    $assert(str_contains($responsiveShellCss, 'padding-left:clamp(24px,calc((100% - 1170px) / 2),135px)'), 'quality-diagnostics-responsive-shell-band-uses-responsive-left-gutter');
    $assert(str_contains($responsiveShellCss, '.figma-node-responsive-shell-centered-centered-content-shell{width:100%;max-width:1170px;height:48px;'), 'quality-diagnostics-centered-flow-shell-renders-responsive-width');
    $assert(str_contains($responsiveShellCss, 'margin-left:auto;margin-right:auto'), 'quality-diagnostics-centered-flow-shell-centers-with-auto-margins');
    $assert(str_contains($responsiveShellCss, '.figma-node-responsive-shell-padded-padded-content-shell{width:100%;max-width:1170px;height:48px;'), 'quality-diagnostics-padded-centered-flow-shell-renders-responsive-width');
    $assert(str_contains($responsiveShellCss, '.figma-node-responsive-shell-off-center-off-center-card{width:420px;height:48px;'), 'quality-diagnostics-off-center-flow-child-keeps-intrinsic-width');

    $desktopOnlyResponsiveRowsResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Desktop Only Responsive Rows Fixture',
        'nodes' => array(
            array(
                'id'       => 'responsive-rows:root',
                'type'     => 'FRAME',
                'name'     => 'Desktop page',
                'width'    => 1440,
                'height'   => 1200,
                'layoutMode' => 'VERTICAL',
                'children' => array(
                    array(
                        'id'       => 'responsive-rows:hero',
                        'type'     => 'FRAME',
                        'name'     => 'Hero row',
                        'width'    => 1216,
                        'height'   => 420,
                        'layoutMode' => 'HORIZONTAL',
                        'paddingLeft' => 64,
                        'paddingRight' => 64,
                        'itemSpacing' => 48,
                        'children' => array(
                            array('id' => 'responsive-rows:hero-copy', 'type' => 'FRAME', 'name' => 'Hero copy', 'width' => 560, 'height' => 320, 'children' => array(
                                array('id' => 'responsive-rows:hero-title', 'type' => 'TEXT', 'name' => 'Hero title', 'characters' => 'Fisiostetic', 'width' => 420, 'height' => 64, 'fontSize' => 48),
                            )),
                            array('id' => 'responsive-rows:hero-media', 'type' => 'FRAME', 'name' => 'Hero media', 'width' => 520, 'height' => 320),
                        ),
                    ),
                    array(
                        'id'       => 'responsive-rows:pricing',
                        'type'     => 'FRAME',
                        'name'     => 'Pricing cards',
                        'width'    => 1216,
                        'height'   => 360,
                        'layoutMode' => 'HORIZONTAL',
                        'itemSpacing' => 24,
                        'children' => array(
                            array('id' => 'responsive-rows:price-a', 'type' => 'FRAME', 'name' => 'Price card', 'width' => 380, 'height' => 280),
                            array('id' => 'responsive-rows:price-b', 'type' => 'FRAME', 'name' => 'Price card', 'width' => 380, 'height' => 280),
                            array('id' => 'responsive-rows:price-c', 'type' => 'FRAME', 'name' => 'Price card', 'width' => 380, 'height' => 280),
                        ),
                    ),
                    array(
                        'id'       => 'responsive-rows:services',
                        'type'     => 'FRAME',
                        'name'     => 'Services Section',
                        'width'    => 1440,
                        'height'   => 900,
                        'layoutMode' => 'VERTICAL',
                        'children' => array(
                            array(
                                'id'       => 'responsive-rows:services-content',
                                'type'     => 'FRAME',
                                'name'     => 'Services content',
                                'width'    => 1216,
                                'height'   => 420,
                                'layoutMode' => 'VERTICAL',
                                'children' => array(
                                    array('id' => 'responsive-rows:services-copy', 'type' => 'TEXT', 'name' => 'Services copy', 'characters' => 'Services', 'width' => 420, 'height' => 64, 'fontSize' => 48),
                                ),
                            ),
                        ),
                    ),
                    array(
                        'id'       => 'responsive-rows:form-row',
                        'type'     => 'FRAME',
                        'name'     => 'Contact fields',
                        'width'    => 640,
                        'height'   => 75,
                        'layoutMode' => 'HORIZONTAL',
                        'itemSpacing' => 24,
                        'children' => array(
                            array('id' => 'responsive-rows:name', 'type' => 'INSTANCE', 'name' => 'Name input', 'width' => 308, 'height' => 75),
                            array('id' => 'responsive-rows:email', 'type' => 'INSTANCE', 'name' => 'Email input', 'width' => 308, 'height' => 75),
                        ),
                    ),
                    array(
                        'id'       => 'responsive-rows:text-row',
                        'type'     => 'FRAME',
                        'name'     => 'Centered text row',
                        'width'    => 820,
                        'height'   => 44,
                        'layoutMode' => 'HORIZONTAL',
                        'children' => array(
                            array('id' => 'responsive-rows:text', 'type' => 'TEXT', 'name' => 'Centered text', 'characters' => 'Responsive copy', 'width' => 820, 'height' => 44, 'layoutPositioning' => 'ABSOLUTE'),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $desktopOnlyResponsiveRowsCss = $fileContent($desktopOnlyResponsiveRowsResult, 'style.css');
    $desktopOnlyResponsiveRowsFluidBlockPosition = strpos($desktopOnlyResponsiveRowsCss, '@media (max-width:1439px)');
    $assert(false !== $desktopOnlyResponsiveRowsFluidBlockPosition, 'desktop-only-responsive-rows-emits-fluid-containment-block');
    $desktopOnlyResponsiveRowsFluidBlock = false === $desktopOnlyResponsiveRowsFluidBlockPosition ? '' : substr($desktopOnlyResponsiveRowsCss, $desktopOnlyResponsiveRowsFluidBlockPosition, strpos($desktopOnlyResponsiveRowsCss, '@media (max-width:767px)') - $desktopOnlyResponsiveRowsFluidBlockPosition);
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $desktopOnlyResponsiveRowsFluidBlock, '.figma-node-responsive-rows-hero-hero-row', array('width:100%', 'max-width:100%', 'height:auto', 'padding-right:24px', 'padding-left:24px'), 'desktop-only-responsive-hero-row-is-fluid-below-source-canvas-width');
    blocks_engine_figma_transformer_contract_assert_css_rule_omits($assert, $desktopOnlyResponsiveRowsFluidBlock, '.figma-node-responsive-rows-hero-hero-row', array('flex-direction:column', 'align-items:stretch', 'flex-wrap:nowrap'), 'desktop-only-responsive-hero-row-keeps-desktop-structure-in-fluid-range');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $desktopOnlyResponsiveRowsFluidBlock, '.figma-node-responsive-rows-price-a-price-card', array('max-width:100%', 'min-width:0', 'flex-shrink:1', 'flex-basis:0', 'flex-grow:1'), 'desktop-only-responsive-card-row-items-share-available-fluid-width');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $desktopOnlyResponsiveRowsFluidBlock, '.figma-node-responsive-rows-services-services-section', array('min-height:0'), 'desktop-only-responsive-flow-section-releases-oversized-source-min-height');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $desktopOnlyResponsiveRowsFluidBlock, '.figma-node-responsive-rows-hero-title-hero-title', array('font-size:clamp(26.4px,3.333vw,48px)'), 'desktop-only-responsive-text-scales-between-source-and-narrow-viewports');
    $desktopOnlyResponsiveRowsMobileBlockPosition = strpos($desktopOnlyResponsiveRowsCss, '@media (max-width:767px)');
    $assert(false !== $desktopOnlyResponsiveRowsMobileBlockPosition, 'desktop-only-responsive-rows-emits-mobile-safety-block');
    $desktopOnlyResponsiveRowsMobileBlock = false === $desktopOnlyResponsiveRowsMobileBlockPosition ? '' : substr($desktopOnlyResponsiveRowsCss, $desktopOnlyResponsiveRowsMobileBlockPosition);
    $assert(str_contains($desktopOnlyResponsiveRowsMobileBlock, '.figma-root [data-source-node-type="TEXT"]{max-width:calc(100vw - 48px)}'), 'desktop-only-responsive-mobile-contains-opaque-component-clone-text');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $desktopOnlyResponsiveRowsMobileBlock, '.figma-node-responsive-rows-hero-hero-row', array('width:100%', 'max-width:100%', 'height:auto', 'flex-direction:column', 'align-items:stretch', 'flex-wrap:nowrap', 'padding-right:24px', 'padding-left:24px'), 'desktop-only-responsive-hero-row-stacks-at-mobile');
    blocks_engine_figma_transformer_contract_assert_css_rule_omits($assert, $desktopOnlyResponsiveRowsMobileBlock, '.figma-node-responsive-rows-hero-hero-row', array('min-height:420px', 'flex-wrap:wrap', 'flex-shrink:1'), 'desktop-only-responsive-hero-row-has-no-fixed-min-height-floor-wrap-only-layout-or-column-axis-shrink');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $desktopOnlyResponsiveRowsMobileBlock, '.figma-node-responsive-rows-pricing-pricing-cards', array('flex-direction:column', 'align-items:stretch', 'flex-wrap:nowrap'), 'desktop-only-responsive-pricing-cards-stack-at-mobile');
    blocks_engine_figma_transformer_contract_assert_css_rule_omits($assert, $desktopOnlyResponsiveRowsMobileBlock, '.figma-node-responsive-rows-pricing-pricing-cards', array('min-height:360px', 'flex-wrap:wrap', 'flex-shrink:1'), 'desktop-only-responsive-pricing-cards-have-no-fixed-min-height-floor-wrap-only-layout-or-column-axis-shrink');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $desktopOnlyResponsiveRowsMobileBlock, '.figma-node-responsive-rows-price-a-price-card', array('flex-shrink:0'), 'desktop-only-responsive-stacked-card-restores-non-shrinking-column-flow');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $desktopOnlyResponsiveRowsMobileBlock, '.figma-node-responsive-rows-form-row-contact-fields', array('flex-direction:column', 'align-items:stretch', 'flex-wrap:nowrap'), 'desktop-only-responsive-form-row-stacks-without-wrapping-into-horizontal-columns');
    blocks_engine_figma_transformer_contract_assert_css_rule_omits($assert, $desktopOnlyResponsiveRowsMobileBlock, '.figma-node-responsive-rows-form-row-contact-fields', array('flex-wrap:wrap'), 'desktop-only-responsive-form-row-removes-mobile-wrap-conflict');
    blocks_engine_figma_transformer_contract_assert_css_rule_omits($assert, $desktopOnlyResponsiveRowsMobileBlock, '.figma-node-responsive-rows-text-row-centered-text-row', array('height:auto'), 'desktop-only-responsive-absolute-text-row-keeps-its-height-floor');

    // Desktop-only canvas sections (no auto-layout) position every child
    // absolutely, so the mobile `height:auto` relaxation must keep a
    // source-height floor or the whole section collapses to zero height.
    $desktopOnlyCanvasSectionsResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Desktop Only Canvas Sections Fixture',
        'nodes' => array(
            array(
                'id'       => 'canvas-sections:root',
                'type'     => 'FRAME',
                'name'     => 'Desktop page',
                'width'    => 1440,
                'height'   => 1917,
                'layoutMode' => 'VERTICAL',
                'children' => array(
                    array(
                        'id'       => 'canvas-sections:hero',
                        'type'     => 'FRAME',
                        'name'     => 'Hero canvas band',
                        'width'    => 1440,
                        'height'   => 453,
                        'children' => array(
                            array(
                                'id'     => 'canvas-sections:hero-copy',
                                'type'   => 'FRAME',
                                'name'   => 'Hero copy frame',
                                'width'  => 644,
                                'height' => 218,
                                'x'      => 398,
                                'y'      => 117,
                                'layoutPositioning' => 'ABSOLUTE',
                                'children' => array(
                                    array('id' => 'canvas-sections:hero-title', 'type' => 'TEXT', 'name' => 'Hero title', 'characters' => 'Tell your story', 'width' => 644, 'height' => 64, 'fontSize' => 56),
                                ),
                            ),
                        ),
                    ),
                    array(
                        'id'       => 'canvas-sections:about',
                        'type'     => 'FRAME',
                        'name'     => 'About canvas band',
                        'width'    => 1440,
                        'height'   => 732,
                        'children' => array(
                            array(
                                'id'     => 'canvas-sections:about-copy',
                                'type'   => 'FRAME',
                                'name'   => 'About copy frame',
                                'width'  => 441,
                                'height' => 279,
                                'x'      => 811,
                                'y'      => 227,
                                'layoutPositioning' => 'ABSOLUTE',
                                'children' => array(
                                    array('id' => 'canvas-sections:about-text', 'type' => 'TEXT', 'name' => 'About text', 'characters' => 'Fleurs is a flower delivery business.', 'width' => 441, 'height' => 223, 'fontSize' => 28),
                                ),
                            ),
                        ),
                    ),
                    array(
                        'id'       => 'canvas-sections:cta',
                        'type'     => 'FRAME',
                        'name'     => 'CTA canvas band',
                        'width'    => 1440,
                        'height'   => 460,
                        'children' => array(
                            array(
                                'id'     => 'canvas-sections:cta-copy',
                                'type'   => 'FRAME',
                                'name'   => 'CTA copy frame',
                                'width'  => 521,
                                'height' => 54,
                                'x'      => 459,
                                'y'      => 292,
                                'layoutPositioning' => 'ABSOLUTE',
                                'children' => array(
                                    array('id' => 'canvas-sections:cta-text', 'type' => 'TEXT', 'name' => 'CTA text', 'characters' => 'Sign up to get daily stories', 'width' => 487, 'height' => 54, 'fontSize' => 38),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $desktopOnlyCanvasSectionsCss = $fileContent($desktopOnlyCanvasSectionsResult, 'style.css');
    $desktopOnlyCanvasSectionsFluidBlockPosition = strpos($desktopOnlyCanvasSectionsCss, '@media (max-width:1439px)');
    $assert(false !== $desktopOnlyCanvasSectionsFluidBlockPosition, 'desktop-only-canvas-sections-emit-fluid-containment-block');
    $desktopOnlyCanvasSectionsFluidBlock = false === $desktopOnlyCanvasSectionsFluidBlockPosition ? '' : substr($desktopOnlyCanvasSectionsCss, $desktopOnlyCanvasSectionsFluidBlockPosition, strpos($desktopOnlyCanvasSectionsCss, '@media (max-width:767px)') - $desktopOnlyCanvasSectionsFluidBlockPosition);
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $desktopOnlyCanvasSectionsFluidBlock, '.figma-node-canvas-sections-hero-hero-canvas-band', array('height:auto', 'min-height:453px'), 'desktop-only-canvas-hero-band-keeps-source-height-floor-in-fluid-range');
    $desktopOnlyCanvasSectionsMobileBlockPosition = strpos($desktopOnlyCanvasSectionsCss, '@media (max-width:767px)');
    $assert(false !== $desktopOnlyCanvasSectionsMobileBlockPosition, 'desktop-only-canvas-sections-emit-mobile-safety-block');
    $desktopOnlyCanvasSectionsMobileBlock = false === $desktopOnlyCanvasSectionsMobileBlockPosition ? '' : substr($desktopOnlyCanvasSectionsCss, $desktopOnlyCanvasSectionsMobileBlockPosition);
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $desktopOnlyCanvasSectionsMobileBlock, '.figma-node-canvas-sections-hero-hero-canvas-band', array('height:auto', 'min-height:453px'), 'desktop-only-canvas-hero-band-keeps-source-height-floor-at-mobile');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $desktopOnlyCanvasSectionsMobileBlock, '.figma-node-canvas-sections-about-about-canvas-band', array('height:auto', 'min-height:720px'), 'desktop-only-canvas-about-band-keeps-capped-height-floor-at-mobile');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $desktopOnlyCanvasSectionsMobileBlock, '.figma-node-canvas-sections-cta-cta-canvas-band', array('height:auto', 'min-height:460px'), 'desktop-only-canvas-cta-band-keeps-source-height-floor-at-mobile');

    // Semantic grids inferred from freeform component instances still emit their
    // children in desktop canvas coordinates. Keep the section floor below the
    // source width, then release direct content children into the mobile grid.
    $desktopOnlyInferredGridResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Desktop Only Inferred Grid Fixture',
        'nodes' => array(
            array(
                'id'       => 'inferred-grid:root',
                'type'     => 'FRAME',
                'name'     => 'Desktop page',
                'width'    => 1440,
                'height'   => 764,
                'layoutMode' => 'VERTICAL',
                'children' => array(
                    array(
                        'id'       => 'inferred-grid:pricing',
                        'type'     => 'INSTANCE',
                        'name'     => 'Pricing: 2-column boxed pricing table',
                        'width'    => 1440,
                        'height'   => 764,
                        'children' => array(
                            array('id' => 'inferred-grid:title', 'type' => 'TEXT', 'name' => 'Pricing', 'characters' => 'Pricing', 'x' => 656, 'y' => 69, 'width' => 128, 'height' => 46, 'layoutPositioning' => 'ABSOLUTE'),
                            array('id' => 'inferred-grid:card-a', 'type' => 'FRAME', 'name' => 'Free plan', 'x' => 282, 'y' => 252, 'width' => 413, 'height' => 433, 'layoutPositioning' => 'ABSOLUTE', 'children' => array(
                                array('id' => 'inferred-grid:card-a-text', 'type' => 'TEXT', 'name' => 'Free', 'characters' => 'Free membership plan', 'width' => 313, 'height' => 148),
                                array('id' => 'inferred-grid:card-a-price', 'type' => 'TEXT', 'name' => 'Price', 'characters' => '0 per month', 'width' => 313, 'height' => 32),
                            )),
                            array('id' => 'inferred-grid:card-b', 'type' => 'FRAME', 'name' => 'Single plan', 'x' => 745, 'y' => 252, 'width' => 413, 'height' => 433, 'layoutPositioning' => 'ABSOLUTE', 'children' => array(
                                array('id' => 'inferred-grid:card-b-text', 'type' => 'TEXT', 'name' => 'Single', 'characters' => 'Single membership plan', 'width' => 313, 'height' => 148),
                                array('id' => 'inferred-grid:card-b-price', 'type' => 'TEXT', 'name' => 'Price', 'characters' => '20 per month', 'width' => 313, 'height' => 32),
                            )),
                            array('id' => 'inferred-grid:card-c', 'type' => 'FRAME', 'name' => 'Family plan', 'x' => 1208, 'y' => 252, 'width' => 413, 'height' => 433, 'layoutPositioning' => 'ABSOLUTE', 'children' => array(
                                array('id' => 'inferred-grid:card-c-text', 'type' => 'TEXT', 'name' => 'Family', 'characters' => 'Family membership plan', 'width' => 313, 'height' => 148),
                                array('id' => 'inferred-grid:card-c-price', 'type' => 'TEXT', 'name' => 'Price', 'characters' => '40 per month', 'width' => 313, 'height' => 32),
                            )),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $desktopOnlyInferredGridCss = $fileContent($desktopOnlyInferredGridResult, 'style.css');
    $desktopOnlyInferredGridFluidBlockPosition = strpos($desktopOnlyInferredGridCss, '@media (max-width:1439px)');
    $assert(false !== $desktopOnlyInferredGridFluidBlockPosition, 'desktop-only-inferred-grid-emits-fluid-containment-block');
    $desktopOnlyInferredGridFluidBlock = false === $desktopOnlyInferredGridFluidBlockPosition ? '' : substr($desktopOnlyInferredGridCss, $desktopOnlyInferredGridFluidBlockPosition, strpos($desktopOnlyInferredGridCss, '@media (max-width:767px)') - $desktopOnlyInferredGridFluidBlockPosition);
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $desktopOnlyInferredGridCss, '.figma-node-inferred-grid-pricing-pricing-2-column-boxed-pricing-table', array('min-height:764px'), 'desktop-only-inferred-grid-has-source-height-floor');
    blocks_engine_figma_transformer_contract_assert_css_rule_omits($assert, $desktopOnlyInferredGridFluidBlock, '.figma-node-inferred-grid-pricing-pricing-2-column-boxed-pricing-table', array('min-height:0'), 'desktop-only-inferred-grid-does-not-collapse-in-fluid-range');
    $desktopOnlyInferredGridMobileBlockPosition = strpos($desktopOnlyInferredGridCss, '@media (max-width:767px)');
    $assert(false !== $desktopOnlyInferredGridMobileBlockPosition, 'desktop-only-inferred-grid-emits-mobile-safety-block');
    $desktopOnlyInferredGridMobileBlock = false === $desktopOnlyInferredGridMobileBlockPosition ? '' : substr($desktopOnlyInferredGridCss, $desktopOnlyInferredGridMobileBlockPosition);
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $desktopOnlyInferredGridMobileBlock, '.figma-node-inferred-grid-pricing-pricing-2-column-boxed-pricing-table', array('grid-template-columns:1fr'), 'desktop-only-inferred-grid-stacks-at-mobile');
    blocks_engine_figma_transformer_contract_assert_css_rule_omits($assert, $desktopOnlyInferredGridMobileBlock, '.figma-node-inferred-grid-pricing-pricing-2-column-boxed-pricing-table', array('min-height:0'), 'desktop-only-inferred-grid-keeps-base-height-floor-at-mobile');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $desktopOnlyInferredGridMobileBlock, '.figma-node-inferred-grid-card-a-free-plan', array('position:relative', 'left:auto', 'right:auto', 'top:auto', 'bottom:auto', 'width:100%', 'height:auto'), 'desktop-only-inferred-grid-card-enters-mobile-flow');

    $fluidManagedStackResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Fluid Managed Stack Fixture',
        'nodes' => array(
            array(
                'id'       => 'fluid-stack:root',
                'type'     => 'FRAME',
                'name'     => 'Desktop page',
                'width'    => 1440,
                'height'   => 900,
                'layoutMode' => 'VERTICAL',
                'children' => array(
                    array(
                        'id'       => 'fluid-stack:footer',
                        'type'     => 'FRAME',
                        'name'     => 'Footer shell',
                        'width'    => 1440,
                        'height'   => 483,
                        'children' => array(
                            array('id' => 'fluid-stack:bg', 'type' => 'RECTANGLE', 'name' => 'Footer background', 'x' => 0, 'y' => -64, 'width' => 1440, 'height' => 195, 'layoutPositioning' => 'ABSOLUTE'),
                            array('id' => 'fluid-stack:card', 'type' => 'FRAME', 'name' => 'Centered card', 'x' => 112, 'y' => 0, 'width' => 1216, 'height' => 352, 'layoutPositioning' => 'ABSOLUTE', 'constraints' => array('horizontal' => 'LEFT_RIGHT', 'vertical' => 'TOP')),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $fluidManagedStackCss = $fileContent($fluidManagedStackResult, 'style.css');
    $assert(str_contains($fluidManagedStackCss, '.figma-node-fluid-stack-footer-footer-shell{width:100%;height:483px;'), 'quality-diagnostics-fluid-managed-stack-renders-full-width');
    $assert(str_contains($fluidManagedStackCss, '.figma-node-fluid-stack-bg-footer-background{width:100vw;height:195px;position:absolute;top:-64px;left:50%;margin-left:-50vw'), 'quality-diagnostics-fluid-managed-stack-full-bleed-child-breaks-out-to-viewport');
    $assert(str_contains($fluidManagedStackCss, '.figma-node-fluid-stack-card-centered-card{width:1216px;height:352px;position:absolute;left:calc(50% - 608px);top:0px'), 'quality-diagnostics-fluid-managed-stack-centered-child-uses-canvas-center');

    $fluidInstanceStackResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Fluid Instance Managed Stack Fixture',
        'nodes' => array(
            array(
                'id'       => 'fluid-instance:root',
                'type'     => 'FRAME',
                'name'     => 'Desktop page',
                'width'    => 1440,
                'height'   => 900,
                'layoutMode' => 'VERTICAL',
                'children' => array(
                    array(
                        'id'       => 'fluid-instance:footer',
                        'type'     => 'INSTANCE',
                        'name'     => 'Footer shell',
                        'width'    => 1440,
                        'height'   => 483,
                        'children' => array(
                            array('id' => 'fluid-instance:bg', 'type' => 'RECTANGLE', 'name' => 'Footer background', 'x' => 0, 'y' => -64, 'width' => 1440, 'height' => 195, 'layoutPositioning' => 'ABSOLUTE'),
                            array('id' => 'fluid-instance:card', 'type' => 'INSTANCE', 'name' => 'Newsletter signup', 'x' => 112, 'y' => 0, 'width' => 1216, 'height' => 352, 'layoutPositioning' => 'ABSOLUTE', 'stackMode' => 'VERTICAL', 'stackCounterSizing' => 'RESIZE_TO_FIT_WITH_IMPLICIT_SIZE'),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $fluidInstanceStackCss = $fileContent($fluidInstanceStackResult, 'style.css');
    $assert(str_contains($fluidInstanceStackCss, '.figma-node-fluid-instance-footer-footer-shell{width:100%;height:483px;'), 'quality-diagnostics-fluid-instance-stack-renders-full-width');
    $assert(str_contains($fluidInstanceStackCss, '.figma-node-fluid-instance-card-newsletter-signup{width:1216px;min-height:352px;position:absolute;left:calc(50% - 608px);top:0px'), 'quality-diagnostics-fluid-instance-centered-child-uses-canvas-center');

    $sharedFooterComponent = array(
        'id'       => 'shared-footer:component',
        'type'     => 'COMPONENT',
        'name'     => 'Footer',
        'width'    => 1440,
        'height'   => 483,
        'children' => array(
            array('id' => 'shared-footer:newsletter', 'type' => 'FRAME', 'name' => 'Newsletter Signup', 'x' => 112, 'y' => 0, 'width' => 1216, 'height' => 352, 'layoutPositioning' => 'ABSOLUTE'),
            array('id' => 'shared-footer:bottom', 'type' => 'FRAME', 'name' => 'Frame 19', 'x' => 0, 'y' => 352, 'width' => 1440, 'height' => 131, 'layoutPositioning' => 'ABSOLUTE', 'children' => array(
                array('id' => 'shared-footer:legal', 'type' => 'TEXT', 'name' => 'Footer text', 'characters' => 'Proudly powered by WordPress.com', 'width' => 281, 'height' => 26, 'fontSize' => 16),
            )),
        ),
    );
    $sharedFooterSemanticResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Shared Footer Semantic Fixture',
        'nodes' => array(
            $sharedFooterComponent,
            array('id' => 'shared-footer:page-a', 'type' => 'FRAME', 'name' => 'Article Page Desktop', 'width' => 1440, 'height' => 900, 'layoutMode' => 'VERTICAL', 'children' => array(
                array('id' => 'shared-footer:a-copy', 'type' => 'TEXT', 'name' => 'Body copy', 'characters' => 'Small body copy', 'width' => 320, 'height' => 20, 'fontSize' => 14),
                array('id' => 'shared-footer:a-footer', 'type' => 'INSTANCE', 'name' => 'Footer', 'componentId' => 'shared-footer:component', 'width' => 1440, 'height' => 483),
            )),
            array('id' => 'shared-footer:page-b', 'type' => 'FRAME', 'name' => 'Archive Page Desktop', 'width' => 1440, 'height' => 900, 'layoutMode' => 'VERTICAL', 'children' => array(
                array('id' => 'shared-footer:b-copy', 'type' => 'TEXT', 'name' => 'Body copy', 'characters' => 'Regular body copy', 'width' => 320, 'height' => 24, 'fontSize' => 16),
                array('id' => 'shared-footer:b-footer', 'type' => 'INSTANCE', 'name' => 'Footer', 'componentId' => 'shared-footer:component', 'width' => 1440, 'height' => 483),
            )),
        ),
    ), array('multi_page' => true, 'frame_ids' => array('shared-footer:page-a', 'shared-footer:page-b')));
    $sharedFooterHtml = $fileContent($sharedFooterSemanticResult, 'index.html') . $fileContent($sharedFooterSemanticResult, 'archive-page-desktop.html');
    $assert(str_contains($sharedFooterHtml, '<p class="figma-node-shared-footer-a-footer-shared-footer-legal-footer-text'), 'shared-footer-legal-copy-page-a-stays-paragraph');
    $assert(str_contains($sharedFooterHtml, '<p class="figma-node-shared-footer-b-footer-shared-footer-legal-footer-text'), 'shared-footer-legal-copy-page-b-stays-paragraph');
    $assert(! str_contains($sharedFooterHtml, '<h6 class="figma-node-shared-footer-a-footer-shared-footer-legal-footer-text"') && ! str_contains($sharedFooterHtml, '<h6 class="figma-node-shared-footer-b-footer-shared-footer-legal-footer-text"'), 'shared-footer-legal-copy-not-page-relative-heading');

    $responsiveFooterResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Responsive Footer Shell Fixture',
        'nodes' => array(
            $sharedFooterComponent,
            array('id' => 'responsive-footer:desktop', 'type' => 'FRAME', 'name' => 'Landing Page Desktop', 'width' => 1440, 'height' => 900, 'children' => array(
                array('id' => 'responsive-footer:desktop-footer', 'type' => 'INSTANCE', 'name' => 'Footer', 'componentId' => 'shared-footer:component', 'width' => 1440, 'height' => 483),
            )),
            array('id' => 'responsive-footer:mobile', 'type' => 'FRAME', 'name' => 'Landing Page Mobile', 'width' => 390, 'height' => 900, 'children' => array(
                array('id' => 'responsive-footer:mobile-footer', 'type' => 'INSTANCE', 'name' => 'Footer', 'componentId' => 'shared-footer:component', 'width' => 390, 'height' => 483),
            )),
        ),
    ), array(
        'responsive_variants' => array(
            array('frame_id' => 'responsive-footer:desktop', 'viewport_width' => 1440, 'primary' => true),
            array('frame_id' => 'responsive-footer:mobile', 'viewport_width' => 390),
        ),
        'page_name' => 'Landing Page',
    ));
    $responsiveFooterCss = $fileContent($responsiveFooterResult, 'style.css');
    $assert(str_contains($responsiveFooterCss, '.figma-node-responsive-footer-desktop-footer-footer{height:auto}'), 'responsive-footer-shell-safety-uses-component-structure');
    $assert(str_contains($responsiveFooterCss, '.figma-node-responsive-footer-desktop-footer-shared-footer-newsletter-newsletter-signup{width:calc(100% - 48px);max-width:342px;height:auto;left:24px}'), 'responsive-footer-newsletter-safety-uses-source-clone');
    $assert(str_contains($responsiveFooterCss, '.figma-node-responsive-footer-desktop-footer-shared-footer-bottom-frame-19{height:auto;position:relative;left:auto;top:auto;justify-content:center;flex-wrap:wrap'), 'responsive-footer-bottom-row-safety-uses-source-clone');

    $geometryFooterComponent = array(
        'id'       => 'geometry-footer:component',
        'type'     => 'COMPONENT',
        'name'     => 'Footer',
        'width'    => 1440,
        'height'   => 483,
        'children' => array(
            array('id' => 'geometry-footer:newsletter', 'type' => 'FRAME', 'name' => 'Newsletter Signup', 'x' => 112, 'y' => 0, 'width' => 1216, 'height' => 352),
            array('id' => 'geometry-footer:bottom', 'type' => 'FRAME', 'name' => 'Frame 19', 'x' => 0, 'y' => 352, 'width' => 1440, 'height' => 131, 'children' => array(
                array('id' => 'geometry-footer:legal', 'type' => 'TEXT', 'name' => 'Footer text', 'characters' => 'Footer links', 'width' => 120, 'height' => 24, 'fontSize' => 16),
            )),
        ),
    );
    $geometryResponsiveFooterResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Geometry Responsive Footer Shell Fixture',
        'nodes' => array(
            $geometryFooterComponent,
            array('id' => 'geometry-footer:desktop', 'type' => 'FRAME', 'name' => 'Landing Page Desktop', 'width' => 1440, 'height' => 900, 'children' => array(
                array('id' => 'geometry-footer:desktop-footer', 'type' => 'INSTANCE', 'name' => 'Footer', 'componentId' => 'geometry-footer:component', 'width' => 1440, 'height' => 483),
            )),
            array('id' => 'geometry-footer:mobile', 'type' => 'FRAME', 'name' => 'Landing Page Mobile', 'width' => 390, 'height' => 900, 'children' => array(
                array('id' => 'geometry-footer:mobile-footer', 'type' => 'INSTANCE', 'name' => 'Footer', 'componentId' => 'geometry-footer:component', 'width' => 390, 'height' => 483),
            )),
        ),
    ), array(
        'responsive_variants' => array(
            array('frame_id' => 'geometry-footer:desktop', 'viewport_width' => 1440, 'primary' => true),
            array('frame_id' => 'geometry-footer:mobile', 'viewport_width' => 390),
        ),
        'page_name' => 'Landing Page',
    ));
    $geometryResponsiveFooterCss = $fileContent($geometryResponsiveFooterResult, 'style.css');
    $assert(str_contains($geometryResponsiveFooterCss, '.figma-node-geometry-footer-desktop-footer-footer{width:100%;height:483px;min-height:483px;position:relative}'), 'geometry-responsive-footer-shell-preserves-freeform-reserved-height');
    $assert(str_contains($geometryResponsiveFooterCss, '.figma-node-geometry-footer-desktop-footer-footer{height:auto}'), 'geometry-responsive-footer-shell-safety-uses-freeform-children');

    $responsiveHeaderChromeResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Responsive Header Chrome Fixture',
        'nodes' => array(
            array('id' => 'chrome-header:desktop', 'type' => 'FRAME', 'name' => 'Landing Page Desktop', 'width' => 1440, 'height' => 900, 'children' => array(
                array('id' => 'chrome-header:desktop-header', 'type' => 'FRAME', 'name' => 'Site Header', 'x' => 0, 'y' => 0, 'width' => 1440, 'height' => 127, 'children' => array(
                    array('id' => 'chrome-header:desktop-row', 'type' => 'FRAME', 'name' => 'Header row', 'x' => 112, 'y' => 24, 'width' => 1216, 'height' => 79, 'layoutMode' => 'HORIZONTAL', 'children' => array(
                        array('id' => 'chrome-header:desktop-logo', 'type' => 'TEXT', 'name' => 'Logo', 'characters' => 'Dr Aarti', 'width' => 96, 'height' => 24, 'fontSize' => 20),
                        array('id' => 'chrome-header:desktop-nav', 'type' => 'FRAME', 'name' => 'Primary nav', 'width' => 420, 'height' => 24, 'layoutMode' => 'HORIZONTAL', 'children' => array(
                            array('id' => 'chrome-header:desktop-nav-home', 'type' => 'TEXT', 'name' => 'Home link', 'characters' => 'Home', 'width' => 48, 'height' => 20, 'fontSize' => 16),
                            array('id' => 'chrome-header:desktop-nav-contact', 'type' => 'TEXT', 'name' => 'Contact link', 'characters' => 'Contact', 'width' => 72, 'height' => 20, 'fontSize' => 16),
                        )),
                    )),
                )),
            )),
            array('id' => 'chrome-header:mobile', 'type' => 'FRAME', 'name' => 'Landing Page Mobile', 'width' => 390, 'height' => 900, 'children' => array(
                array('id' => 'chrome-header:mobile-header', 'type' => 'FRAME', 'name' => 'Site Header', 'x' => 0, 'y' => 0, 'width' => 390, 'height' => 160, 'children' => array(
                    array('id' => 'chrome-header:mobile-row', 'type' => 'FRAME', 'name' => 'Header row', 'x' => 24, 'y' => 24, 'width' => 342, 'height' => 112, 'layoutMode' => 'HORIZONTAL', 'children' => array(
                        array('id' => 'chrome-header:mobile-logo', 'type' => 'TEXT', 'name' => 'Logo', 'characters' => 'Dr Aarti', 'width' => 96, 'height' => 24, 'fontSize' => 20),
                        array('id' => 'chrome-header:mobile-nav', 'type' => 'FRAME', 'name' => 'Primary nav', 'width' => 210, 'height' => 48, 'layoutMode' => 'HORIZONTAL', 'children' => array(
                            array('id' => 'chrome-header:mobile-nav-home', 'type' => 'TEXT', 'name' => 'Home link', 'characters' => 'Home', 'width' => 48, 'height' => 20, 'fontSize' => 16),
                            array('id' => 'chrome-header:mobile-nav-contact', 'type' => 'TEXT', 'name' => 'Contact link', 'characters' => 'Contact', 'width' => 72, 'height' => 20, 'fontSize' => 16),
                        )),
                    )),
                )),
            )),
        ),
    ), array(
        'responsive_variants' => array(
            array('frame_id' => 'chrome-header:desktop', 'viewport_width' => 1440, 'primary' => true),
            array('frame_id' => 'chrome-header:mobile', 'viewport_width' => 390),
        ),
        'page_name' => 'Landing Page',
    ));
    $responsiveHeaderChromeCss = $fileContent($responsiveHeaderChromeResult, 'style.css');
    $assert(str_contains($responsiveHeaderChromeCss, '.figma-node-chrome-header-desktop-header-site-header{max-width:100%;height:auto;display:flex;flex-direction:column;align-items:stretch;justify-content:flex-start;min-height:160px}'), 'responsive-header-shell-safety-matches-semantic-header-name');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $responsiveHeaderChromeCss, '.figma-node-chrome-header-desktop-row-header-row', array('left:24px', 'top:24px'), 'responsive-header-inner-preserves-matched-variant-geometry');
    blocks_engine_figma_transformer_contract_assert_css_rule_omits($assert, $responsiveHeaderChromeCss, '.figma-node-chrome-header-desktop-row-header-row', array('position:relative', 'left:auto', 'top:auto'), 'responsive-header-inner-skips-generic-flow-safety-with-matched-variant');
    $assert(str_contains($responsiveHeaderChromeCss, '.figma-node-chrome-header-desktop-nav-primary-nav{width:100%;max-width:100%;height:auto;justify-content:flex-start;flex-wrap:wrap;gap:16px'), 'responsive-navigation-shell-safety-matches-nav-name');

    $responsiveHeaderDuplicateKeyResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Responsive Header Duplicate Key Fixture',
        'nodes' => array(
            array('id' => 'chrome-leak:desktop', 'type' => 'FRAME', 'name' => 'Clinic Desktop', 'width' => 1440, 'height' => 900, 'children' => array(
                array('id' => 'chrome-leak:desktop-header', 'type' => 'FRAME', 'name' => 'Site Header', 'x' => 0, 'y' => 0, 'width' => 1440, 'height' => 112, 'children' => array(
                    array('id' => 'chrome-leak:desktop-actions', 'source_id' => 'component:header-actions', 'type' => 'FRAME', 'name' => 'Actions', 'x' => 760, 'y' => 32, 'width' => 500, 'height' => 48, 'layoutMode' => 'HORIZONTAL', 'children' => array(
                        array('id' => 'chrome-leak:desktop-actions-label', 'type' => 'TEXT', 'name' => 'Actions label', 'characters' => 'Book now', 'width' => 72, 'height' => 20, 'fontSize' => 16),
                    )),
                )),
            )),
            array('id' => 'chrome-leak:mobile', 'type' => 'FRAME', 'name' => 'Clinic Mobile', 'width' => 390, 'height' => 900, 'children' => array(
                array('id' => 'chrome-leak:mobile-header', 'type' => 'FRAME', 'name' => 'Site Header', 'x' => 0, 'y' => 0, 'width' => 390, 'height' => 144, 'children' => array(
                    array('id' => 'chrome-leak:mobile-actions', 'type' => 'FRAME', 'name' => 'Actions', 'x' => 24, 'y' => 48, 'width' => 342, 'height' => 48, 'layoutMode' => 'HORIZONTAL', 'children' => array(
                        array('id' => 'chrome-leak:mobile-actions-label', 'type' => 'TEXT', 'name' => 'Actions label', 'characters' => 'Book now', 'width' => 72, 'height' => 20, 'fontSize' => 16),
                    )),
                )),
            )),
        ),
    ), array(
        'responsive_variants' => array(
            array('frame_id' => 'chrome-leak:desktop', 'viewport_width' => 1440, 'primary' => true),
            array('frame_id' => 'chrome-leak:mobile', 'viewport_width' => 390),
        ),
        'page_name' => 'Clinic Page',
    ));
    $responsiveHeaderDuplicateKeyCss = $fileContent($responsiveHeaderDuplicateKeyResult, 'style.css');
    $responsiveHeaderDuplicateKeyMobileBlock = substr($responsiveHeaderDuplicateKeyCss, strpos($responsiveHeaderDuplicateKeyCss, '@media'));
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $responsiveHeaderDuplicateKeyMobileBlock, '.figma-node-chrome-leak-desktop-actions-actions', array('width:calc(100% - 48px)', 'left:24px', 'top:48px'), 'responsive-header-duplicate-key-actions-preserves-matched-geometry');
    blocks_engine_figma_transformer_contract_assert_css_rule_omits($assert, $responsiveHeaderDuplicateKeyMobileBlock, '.figma-node-chrome-leak-desktop-actions-actions', array('position:relative', 'left:auto', 'top:auto', 'padding-top:24px'), 'responsive-header-duplicate-key-actions-skips-unmatched-source-flow-safety');

    $responsiveMixedHeaderResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Responsive Mixed Header Chrome Fixture',
        'nodes' => array(
            array('id' => 'mixed-header:desktop', 'type' => 'FRAME', 'name' => 'Clinic Desktop', 'width' => 1440, 'height' => 900, 'children' => array(
                array('id' => 'mixed-header:desktop-header', 'type' => 'FRAME', 'name' => 'Site Header', 'x' => 0, 'y' => 0, 'width' => 1440, 'height' => 112, 'children' => array(
                    array('id' => 'mixed-header:desktop-logo', 'type' => 'TEXT', 'name' => 'Logo', 'characters' => 'Dr Aarti', 'x' => 112, 'y' => 32, 'width' => 120, 'height' => 24, 'fontSize' => 20),
                    array('id' => 'mixed-header:desktop-nav', 'type' => 'FRAME', 'name' => 'Primary Navigation', 'x' => 760, 'y' => 32, 'width' => 420, 'height' => 24, 'layoutMode' => 'HORIZONTAL', 'children' => array(
                        array('id' => 'mixed-header:desktop-nav-home', 'type' => 'TEXT', 'name' => 'Home link', 'characters' => 'Home', 'width' => 48, 'height' => 20, 'fontSize' => 16),
                        array('id' => 'mixed-header:desktop-nav-services', 'type' => 'TEXT', 'name' => 'Services link', 'characters' => 'Services', 'width' => 72, 'height' => 20, 'fontSize' => 16),
                    )),
                    array('id' => 'mixed-header:desktop-cta', 'type' => 'FRAME', 'name' => 'Book CTA', 'x' => 1210, 'y' => 22, 'width' => 118, 'height' => 44, 'children' => array(
                        array('id' => 'mixed-header:desktop-cta-label', 'type' => 'TEXT', 'name' => 'CTA Label', 'characters' => 'Book now', 'width' => 72, 'height' => 20, 'fontSize' => 16),
                    )),
                )),
            )),
            array('id' => 'mixed-header:mobile', 'type' => 'FRAME', 'name' => 'Clinic Mobile', 'width' => 390, 'height' => 900, 'children' => array(
                array('id' => 'mixed-header:mobile-header', 'type' => 'FRAME', 'name' => 'Site Header', 'x' => 0, 'y' => 0, 'width' => 390, 'height' => 196, 'children' => array(
                    array('id' => 'mixed-header:mobile-logo', 'type' => 'TEXT', 'name' => 'Logo', 'characters' => 'Dr Aarti', 'x' => 24, 'y' => 24, 'width' => 120, 'height' => 24, 'fontSize' => 20),
                    array('id' => 'mixed-header:mobile-nav', 'type' => 'FRAME', 'name' => 'Primary Navigation', 'x' => 24, 'y' => 72, 'width' => 342, 'height' => 48, 'layoutMode' => 'HORIZONTAL', 'children' => array(
                        array('id' => 'mixed-header:mobile-nav-home', 'type' => 'TEXT', 'name' => 'Home link', 'characters' => 'Home', 'width' => 48, 'height' => 20, 'fontSize' => 16),
                        array('id' => 'mixed-header:mobile-nav-services', 'type' => 'TEXT', 'name' => 'Services link', 'characters' => 'Services', 'width' => 72, 'height' => 20, 'fontSize' => 16),
                    )),
                    array('id' => 'mixed-header:mobile-cta', 'type' => 'FRAME', 'name' => 'Book CTA', 'x' => 24, 'y' => 144, 'width' => 118, 'height' => 44, 'children' => array(
                        array('id' => 'mixed-header:mobile-cta-label', 'type' => 'TEXT', 'name' => 'CTA Label', 'characters' => 'Book now', 'width' => 72, 'height' => 20, 'fontSize' => 16),
                    )),
                )),
            )),
        ),
    ), array(
        'responsive_variants' => array(
            array('frame_id' => 'mixed-header:desktop', 'viewport_width' => 1440, 'primary' => true),
            array('frame_id' => 'mixed-header:mobile', 'viewport_width' => 390),
        ),
        'page_name' => 'Clinic Page',
    ));
    $responsiveMixedHeaderCss = $fileContent($responsiveMixedHeaderResult, 'style.css');
    $responsiveMixedHeaderMobileBlock = substr($responsiveMixedHeaderCss, strpos($responsiveMixedHeaderCss, '@media'));
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $responsiveMixedHeaderMobileBlock, '.figma-node-mixed-header-desktop-header-site-header', array('height:auto', 'display:flex', 'flex-direction:column', 'align-items:stretch', 'justify-content:flex-start', 'min-height:196px'), 'responsive-header-mixed-absolute-flow-parent-becomes-flow-shell');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $responsiveMixedHeaderMobileBlock, '.figma-node-mixed-header-desktop-logo-logo', array('left:24px', 'top:24px'), 'responsive-header-mixed-logo-preserves-matched-variant-position');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $responsiveMixedHeaderMobileBlock, '.figma-node-mixed-header-desktop-nav-primary-navigation', array('left:24px', 'top:72px'), 'responsive-header-mixed-nav-preserves-matched-variant-position');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $responsiveMixedHeaderMobileBlock, '.figma-node-mixed-header-desktop-cta-book-cta', array('left:24px', 'top:144px'), 'responsive-header-mixed-cta-preserves-matched-variant-position');
    blocks_engine_figma_transformer_contract_assert_css_rule_omits($assert, $responsiveMixedHeaderMobileBlock, '.figma-node-mixed-header-desktop-logo-logo', array('position:relative', 'left:auto', 'right:auto', 'top:auto'), 'responsive-header-mixed-logo-skips-generic-flow-safety-with-matched-variant');
    blocks_engine_figma_transformer_contract_assert_css_rule_omits($assert, $responsiveMixedHeaderMobileBlock, '.figma-node-mixed-header-desktop-nav-primary-navigation', array('position:relative', 'left:auto', 'top:auto'), 'responsive-header-mixed-nav-skips-generic-flow-safety-with-matched-variant');
    blocks_engine_figma_transformer_contract_assert_css_rule_omits($assert, $responsiveMixedHeaderMobileBlock, '.figma-node-mixed-header-desktop-cta-book-cta', array('position:relative', 'left:auto', 'right:auto', 'top:auto'), 'responsive-header-mixed-cta-skips-generic-flow-safety-with-matched-variant');
    $mixedHeaderNavMobileRule = blocks_engine_figma_transformer_contract_css_rule($responsiveMixedHeaderMobileBlock, '.figma-node-mixed-header-desktop-nav-primary-navigation');
    $mixedHeaderCtaMobileRule = blocks_engine_figma_transformer_contract_css_rule($responsiveMixedHeaderMobileBlock, '.figma-node-mixed-header-desktop-cta-book-cta');
    $assert(! str_contains($mixedHeaderNavMobileRule, 'left:760px') && ! str_contains($mixedHeaderCtaMobileRule, 'left:1210px'), 'responsive-header-mixed-no-desktop-left-overflow-at-mobile');

    $rootAbsoluteChromeResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Root Absolute Chrome Fixture',
        'nodes' => array(
            array(
                'id'       => 'root-chrome:page',
                'type'     => 'FRAME',
                'name'     => 'Desktop page',
                'width'    => 1440,
                'height'   => 1200,
                'layoutMode' => 'VERTICAL',
                'children' => array(
                    array('id' => 'root-chrome:header', 'type' => 'FRAME', 'name' => 'Site header', 'x' => 0, 'y' => 0, 'width' => 1440, 'height' => 96, 'layoutPositioning' => 'ABSOLUTE'),
                    array('id' => 'root-chrome:hero', 'type' => 'GROUP', 'name' => 'Clipped hero group', 'x' => 0, 'y' => 96, 'width' => 1440, 'height' => 640, 'layoutPositioning' => 'ABSOLUTE', 'clipsContent' => true),
                ),
            ),
        ),
    ));
    $rootAbsoluteChromeCss = $fileContent($rootAbsoluteChromeResult, 'style.css');
    $assert(str_contains($rootAbsoluteChromeCss, '.figma-node-root-chrome-header-site-header{width:100vw;height:96px;position:absolute;top:0px;left:50%;margin-left:-50vw'), 'quality-diagnostics-root-absolute-header-breaks-out-to-viewport');
    $assert(str_contains($rootAbsoluteChromeCss, '.figma-node-root-chrome-hero-clipped-hero-group{width:100vw;height:640px;overflow:hidden;position:absolute;top:96px;left:50%;margin-left:-50vw'), 'quality-diagnostics-root-absolute-clipped-hero-breaks-out-to-viewport');

    $fixedSocialFooterResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Fixed Social Footer Breakpoint Fixture',
        'nodes' => array(
            array('id' => 'fixed-social:desktop', 'type' => 'FRAME', 'name' => 'Landing Page Desktop', 'width' => 1440, 'height' => 900, 'children' => array(
                array('id' => 'fixed-social:desktop-footer', 'type' => 'FRAME', 'name' => 'Footer', 'x' => 0, 'y' => 650, 'width' => 1440, 'height' => 251, 'children' => array(
                    array('id' => 'fixed-social:desktop-social', 'type' => 'FRAME', 'name' => 'Social', 'x' => 1345, 'y' => 91, 'width' => 54, 'height' => 23, 'children' => array(
                        array('id' => 'fixed-social:desktop-facebook', 'type' => 'RECTANGLE', 'name' => 'Facebook logo', 'x' => 0, 'y' => 0, 'width' => 23, 'height' => 23),
                        array('id' => 'fixed-social:desktop-instagram', 'type' => 'RECTANGLE', 'name' => 'Instagram logo', 'x' => 31, 'y' => 0, 'width' => 23, 'height' => 23),
                    )),
                )),
            )),
            array('id' => 'fixed-social:mobile', 'type' => 'FRAME', 'name' => 'Landing Page Mobile', 'width' => 390, 'height' => 900, 'children' => array(
                array('id' => 'fixed-social:mobile-footer', 'type' => 'FRAME', 'name' => 'Footer', 'x' => 0, 'y' => 430, 'width' => 390, 'height' => 469, 'children' => array(
                    array('id' => 'fixed-social:mobile-social', 'type' => 'FRAME', 'name' => 'Social', 'x' => 24, 'y' => 312, 'width' => 54, 'height' => 23, 'children' => array(
                        array('id' => 'fixed-social:mobile-facebook', 'type' => 'RECTANGLE', 'name' => 'Facebook logo', 'x' => 0, 'y' => 0, 'width' => 23, 'height' => 23),
                        array('id' => 'fixed-social:mobile-instagram', 'type' => 'RECTANGLE', 'name' => 'Instagram logo', 'x' => 31, 'y' => 0, 'width' => 23, 'height' => 23),
                    )),
                )),
            )),
        ),
    ), array(
        'responsive_variants' => array(
            array('frame_id' => 'fixed-social:desktop', 'viewport_width' => 1440, 'primary' => true),
            array('frame_id' => 'fixed-social:mobile', 'viewport_width' => 390),
        ),
        'page_name' => 'Landing Page',
    ));
    $fixedSocialFooterCss = $fileContent($fixedSocialFooterResult, 'style.css');
    $assert(str_contains($fixedSocialFooterCss, '.figma-node-fixed-social-desktop-social-social{left:24px;top:312px}'), 'fixed-social-footer-breakpoint-moves-fixed-width-icon-row');
    $assert(! str_contains($fixedSocialFooterCss, '.figma-node-fixed-social-desktop-social-social{width:calc(100% - 336px)'), 'fixed-social-footer-breakpoint-keeps-fixed-icon-row-width');

    $assert(in_array('large_absolute_offsets', $qualitySignalCodes, true), 'quality-diagnostics-large-absolute-offsets');
    $assert(in_array('large_css_offsets', $qualitySignalCodes, true), 'quality-diagnostics-large-css-offsets');
    $assert(in_array('image_heavy_landmark_candidate', $qualitySignalCodes, true), 'quality-diagnostics-image-heavy-landmark');
    $assert(in_array('excessive_image_blocks', $qualitySignalCodes, true), 'quality-diagnostics-excessive-image-blocks');
    $assert(in_array('excessive_vector_image_fallbacks', $qualitySignalCodes, true), 'quality-diagnostics-excessive-vector-fallbacks');
    $largeOffsetSignal = $artifactQualitySignal($qualityDiagnosticsResult, 'large_absolute_offsets');
    $assert('quality:offcanvas' === ($largeOffsetSignal['sample_nodes'][0]['node_id'] ?? null), 'quality-diagnostics-large-absolute-offset-sample-node');
    $assert('quality:root' === ($largeOffsetSignal['sample_nodes'][0]['parent_id'] ?? null), 'quality-diagnostics-large-absolute-offset-sample-parent');
    $assert('figma-node-quality-offcanvas-off-canvas-promo' === ($largeOffsetSignal['sample_nodes'][0]['class'] ?? null), 'quality-diagnostics-large-absolute-offset-sample-class');
    $assert(2000.0 === (float) ($largeOffsetSignal['sample_nodes'][0]['left'] ?? 0), 'quality-diagnostics-large-absolute-offset-sample-left');
    $largeCssOffsetSignal = $artifactQualitySignal($qualityDiagnosticsResult, 'large_css_offsets');
    $assert('quality:offcanvas' === ($largeCssOffsetSignal['sample_nodes'][0]['node_id'] ?? null), 'quality-diagnostics-large-css-offset-sample-node');
    $assert('figma-node-quality-offcanvas-off-canvas-promo' === ($largeCssOffsetSignal['sample_nodes'][0]['class'] ?? null), 'quality-diagnostics-large-css-offset-sample-class');
    $assert(2000.0 === (float) ($largeCssOffsetSignal['sample_nodes'][0]['left'] ?? 0), 'quality-diagnostics-large-css-offset-sample-left');
    $visualOffsetSignal = $artifactQualitySignal($qualityDiagnosticsResult, 'off_canvas_visual_nodes');
    $assert('quality:offcanvas' === ($visualOffsetSignal['sample_nodes'][0]['node_id'] ?? null), 'quality-diagnostics-off-canvas-visual-sample-node');
    $assert('figma-node-quality-offcanvas-off-canvas-promo' === ($visualOffsetSignal['sample_nodes'][0]['class'] ?? null), 'quality-diagnostics-off-canvas-visual-sample-class');
    $assert('needs_review' === ($qualityDiagnosticsResult['source_reports']['figma']['html']['transform_diagnostics']['artifact_quality']['status'] ?? null), 'quality-diagnostics-status-needs-review');
    $assert('warn' === ($qualityDiagnosticsResult['source_reports']['figma']['html']['transform_diagnostics']['artifact_quality']['quality_status'] ?? null), 'quality-diagnostics-quality-status-warn');
    $artifactAnalyzer = new ReflectionMethod(StaticHtmlEmitter::class, 'htmlArtifactDiagnostics');
    $sparseCanvasHtml = '<!doctype html><html><body>' . str_repeat('<div class="figma-node-layer"></div>', 90) . '<svg viewBox="0 0 1 1">' . str_repeat('<path d="M0 0L1 1"/>', 3000) . '</svg></body></html>';
    $responsiveLeakCss = '.figma-node-card{position:absolute;left:1200px;top:0px;width:1440px}@media (max-width:390px){.figma-node-card{position:relative;left:auto;top:auto;width:1440px}.figma-node-shell{max-width:1440px}}';
    $htmlArtifactDiagnostics = $artifactAnalyzer->invoke(new StaticHtmlEmitter(), $sparseCanvasHtml, $responsiveLeakCss);
    $assert(true === ($htmlArtifactDiagnostics['canvas_like_dom'] ?? null), 'quality-diagnostics-html-canvas-like-dom');
    $assert(true === ($htmlArtifactDiagnostics['semantic_sparsity'] ?? null), 'quality-diagnostics-html-semantic-sparsity');
    $assert(true === ($htmlArtifactDiagnostics['overlarge_inline_svg_ratio'] ?? null), 'quality-diagnostics-html-overlarge-inline-svg-ratio');
    $assert(2 === ($htmlArtifactDiagnostics['breakpoint_override_leak_count'] ?? null), 'quality-diagnostics-html-breakpoint-override-leaks');
    $assert(1 === ($htmlArtifactDiagnostics['absolute_to_flow_conversion_count'] ?? null), 'quality-diagnostics-html-absolute-to-flow-conversion');
    $htmlArtifactQuality = (new TransformDiagnosticsBuilder())->artifactQualityDiagnostics(array(), array(), array(), array(), array(), array(), array(), array(), array(), array(), array(), array(), $htmlArtifactDiagnostics, array(
        'samples' => array(
            array(
                'class' => 'figma-node-card',
                'reason_code' => 'responsive_generic_mobile_safety',
                'node_id' => 'quality:card',
                'evidence' => array(
                    'source' => 'class_safety_fallback',
                    'matched_breakpoint_geometry' => false,
                    'absolute_to_flow_conversion' => true,
                ),
                'count' => 1,
            ),
        ),
    ));
    $htmlArtifactSignalCodes = array_values(array_map(static fn (array $signal): string => (string) ($signal['code'] ?? ''), $htmlArtifactQuality['signals'] ?? array()));
    $assert(in_array('canvas_like_dom', $htmlArtifactSignalCodes, true), 'quality-diagnostics-html-canvas-like-signal');
    $assert(in_array('semantic_sparsity', $htmlArtifactSignalCodes, true), 'quality-diagnostics-html-semantic-sparsity-signal');
    $assert(in_array('overlarge_inline_svg_ratio', $htmlArtifactSignalCodes, true), 'quality-diagnostics-html-inline-svg-ratio-signal');
    $assert(in_array('breakpoint_override_leak', $htmlArtifactSignalCodes, true), 'quality-diagnostics-html-breakpoint-leak-signal');
    $assert(in_array('suspicious_absolute_to_flow_conversion', $htmlArtifactSignalCodes, true), 'quality-diagnostics-html-absolute-to-flow-signal');
    $absoluteToFlowSignal = $artifactQualitySignal(array('source_reports' => array('figma' => array('html' => array('transform_diagnostics' => array('artifact_quality' => $htmlArtifactQuality))))), 'suspicious_absolute_to_flow_conversion');
    $assert(1 === ($absoluteToFlowSignal['decision_trace_source_counts']['class_safety_fallback'] ?? null), 'quality-diagnostics-html-absolute-to-flow-source-count');
    $assert('class_safety_fallback' === ($absoluteToFlowSignal['sample_rules'][0]['decision_trace']['source'] ?? null), 'quality-diagnostics-html-absolute-to-flow-sample-source');
    $assert(false === ($absoluteToFlowSignal['sample_rules'][0]['decision_trace']['matched_breakpoint_geometry'] ?? null), 'quality-diagnostics-html-absolute-to-flow-sample-matched-geometry');
    $assert('warn' === ($htmlArtifactQuality['quality_status'] ?? null), 'quality-diagnostics-html-quality-status-warn');
    $qualitySignals = $qualityDiagnosticsResult['source_reports']['figma']['html']['transform_diagnostics']['artifact_quality']['signals'] ?? array();
    $excessiveImageSignal = null;
    foreach ( is_array($qualitySignals) ? $qualitySignals : array() as $signal ) {
        if ( is_array($signal) && 'excessive_image_blocks' === ($signal['code'] ?? null) ) {
            $excessiveImageSignal = $signal;
            break;
        }
    }
    $assert(12 === ($excessiveImageSignal['threshold'] ?? null), 'quality-diagnostics-excessive-image-threshold');
    $assert(($excessiveImageSignal['image_node_density'] ?? 0) > 0.35, 'quality-diagnostics-excessive-image-density');
    $assert(! empty($excessiveImageSignal['sample_nodes']) && count($excessiveImageSignal['sample_nodes']) <= 10, 'quality-diagnostics-excessive-image-samples');
    $assert(20 === ($qualityDiagnosticsResult['source_reports']['figma']['html']['transform_diagnostics']['artifact_quality']['summary']['image_block_count'] ?? null), 'quality-diagnostics-image-block-summary-count');
    $assert(22 === ($qualityDiagnosticsResult['source_reports']['figma']['html']['transform_diagnostics']['artifact_quality']['summary']['total_node_count'] ?? null), 'quality-diagnostics-total-node-summary-count');
    $assert('quality:root' === ($qualityDiagnosticsResult['source_reports']['figma']['html']['transform_diagnostics']['selection']['selected_frames'][0]['frame_id'] ?? null), 'quality-diagnostics-selected-frame-id');
    $assert(22 === ($qualityDiagnosticsResult['source_reports']['figma']['html']['transform_diagnostics']['selection']['selected_frames'][0]['node_count'] ?? null), 'quality-diagnostics-selected-frame-node-count');
    
    $normalImageAssets = array();
    $normalImageChildren = array();
    for ( $i = 1; $i <= 12; $i++ ) {
        $normalImageAssets['normal-image-' . $i] = array('mime_type' => 'image/png', 'content' => 'normal image ' . $i);
        $normalImageChildren[] = array('id' => 'normal:image:' . $i, 'type' => 'RECTANGLE', 'name' => 'Gallery image ' . $i, 'width' => 120, 'height' => 90, 'asset_id' => 'normal-image-' . $i);
    }
    for ( $i = 1; $i <= 32; $i++ ) {
        $normalImageChildren[] = array('id' => 'normal:text:' . $i, 'type' => 'TEXT', 'name' => 'Body copy ' . $i, 'characters' => 'Paragraph ' . $i, 'fontSize' => 16);
    }
    $normalImageResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'   => 'Normal Image Count Fixture',
        'assets' => $normalImageAssets,
        'nodes'  => array(
            array(
                'id'       => 'normal:root',
                'type'     => 'FRAME',
                'name'     => 'Editorial gallery page',
                'width'    => 960,
                'height'   => 1600,
                'layoutMode' => 'VERTICAL',
                'children' => $normalImageChildren,
            ),
        ),
    ));
    $normalImageQuality = $normalImageResult['source_reports']['figma']['html']['transform_diagnostics']['artifact_quality'] ?? array();
    $assert(! in_array('excessive_image_blocks', $artifactQualitySignalCodes($normalImageResult), true), 'quality-diagnostics-normal-image-count-no-excessive-signal');
    $assert(12 === ($normalImageQuality['summary']['image_block_count'] ?? null), 'quality-diagnostics-normal-image-count-summary');
    $assert(($normalImageQuality['summary']['image_node_density'] ?? 1) < 0.35, 'quality-diagnostics-normal-image-density-summary');
    
    $normalLocalPlacementResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Normal Local Placement Fixture',
        'nodes' => array(
            array(
                'id'       => 'normal-local:root',
                'type'     => 'FRAME',
                'name'     => 'Normal local root',
                'width'    => 480,
                'height'   => 320,
                'children' => array(
                    array('id' => 'normal-local:card', 'type' => 'FRAME', 'name' => 'Local card', 'x' => 40, 'y' => 60, 'width' => 180, 'height' => 90, 'layoutPositioning' => 'ABSOLUTE', 'children' => array(
                        array('id' => 'normal-local:copy', 'type' => 'TEXT', 'name' => 'Card copy', 'characters' => 'Normal placement', 'fontSize' => 16),
                    )),
                ),
            ),
        ),
    ));
    $normalLocalSignalCodes = $artifactQualitySignalCodes($normalLocalPlacementResult);
    $assert(! in_array('large_absolute_offsets', $normalLocalSignalCodes, true), 'quality-diagnostics-normal-local-no-large-offset-signal');
    $assert(! in_array('off_canvas_visual_nodes', $normalLocalSignalCodes, true), 'quality-diagnostics-normal-local-no-visual-off-canvas-signal');
    $assert(0 === ($normalLocalPlacementResult['source_reports']['figma']['html']['transform_diagnostics']['layout']['large_absolute_offset_count'] ?? null), 'quality-diagnostics-normal-local-large-offset-count-zero');
    $assert(0 === ($normalLocalPlacementResult['source_reports']['figma']['html']['transform_diagnostics']['layout']['off_canvas_visual_node_count'] ?? null), 'quality-diagnostics-normal-local-visual-offset-count-zero');

    $absoluteChildReserveResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Absolute Child Flow Reserve Fixture',
        'nodes' => array(
            array(
                'id'       => 'reserve:root',
                'type'     => 'FRAME',
                'name'     => 'Page root',
                'width'    => 640,
                'height'   => 700,
                'children' => array(
                    array(
                        'id'       => 'reserve:footer',
                        'type'     => 'FRAME',
                        'name'     => 'Footer shell',
                        'x'        => 0,
                        'y'        => 120,
                        'width'    => 640,
                        'height'   => 483,
                        'children' => array(
                            array('id' => 'reserve:newsletter', 'type' => 'FRAME', 'name' => 'Promoted card', 'x' => 112, 'y' => 0, 'width' => 416, 'height' => 352, 'children' => array(
                                array('id' => 'reserve:newsletter:text', 'type' => 'TEXT', 'name' => 'Card heading', 'characters' => 'Promoted content', 'width' => 240, 'height' => 32, 'fontSize' => 24),
                            )),
                            array('id' => 'reserve:bottom', 'type' => 'FRAME', 'name' => 'Bottom bar', 'x' => 0, 'y' => 352, 'width' => 640, 'height' => 131, 'children' => array(
                                array('id' => 'reserve:bottom:text', 'type' => 'TEXT', 'name' => 'Bottom text', 'characters' => 'Footer links', 'width' => 120, 'height' => 24, 'fontSize' => 16),
                            )),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $absoluteChildReserveCss = $fileContent($absoluteChildReserveResult, 'style.css');
    $assert(str_contains($absoluteChildReserveCss, '.figma-node-reserve-footer-footer-shell{') && str_contains($absoluteChildReserveCss, 'height:483px;min-height:483px;'), 'absolute-child-reserve-parent-min-height');
    $assert(str_contains($absoluteChildReserveCss, '.figma-node-reserve-newsletter-promoted-card{') && str_contains($absoluteChildReserveCss, 'width:416px;height:352px;position:absolute;left:112px;top:0px'), 'absolute-child-reserve-positioned-card-preserved');
    $assert(str_contains($absoluteChildReserveCss, '.figma-node-reserve-bottom-bottom-bar{') && str_contains($absoluteChildReserveCss, 'width:640px;height:131px;position:absolute;left:0px;top:352px'), 'absolute-child-reserve-positioned-bottom-preserved');

    $overlappingFooterLayerResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Overlapping Footer Layer Fixture',
        'nodes' => array(
            array(
                'id'       => 'footer-layer:root',
                'type'     => 'FRAME',
                'name'     => 'Page root',
                'width'    => 640,
                'height'   => 700,
                'children' => array(
                    array(
                        'id'       => 'footer-layer:footer',
                        'type'     => 'FRAME',
                        'name'     => 'Footer shell',
                        'x'        => 0,
                        'y'        => 120,
                        'width'    => 640,
                        'height'   => 483,
                        'children' => array(
                            array('id' => 'footer-layer:bottom', 'type' => 'FRAME', 'name' => 'Yellow footer area', 'x' => 0, 'y' => 300, 'width' => 640, 'height' => 183, 'parentIndex' => array('position' => 'a'), 'children' => array(
                                array('id' => 'footer-layer:bottom:text', 'type' => 'TEXT', 'name' => 'Bottom text', 'characters' => 'Footer links', 'width' => 120, 'height' => 24, 'fontSize' => 16),
                            )),
                            array('id' => 'footer-layer:newsletter', 'type' => 'FRAME', 'name' => 'Newsletter card', 'x' => 112, 'y' => 0, 'width' => 416, 'height' => 352, 'parentIndex' => array('position' => 'b'), 'children' => array(
                                array('id' => 'footer-layer:newsletter:text', 'type' => 'TEXT', 'name' => 'Card heading', 'characters' => 'Get the newsletter', 'width' => 240, 'height' => 32, 'fontSize' => 24),
                            )),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $overlappingFooterLayerCss = $fileContent($overlappingFooterLayerResult, 'style.css');
    $assert(str_contains($overlappingFooterLayerCss, '.figma-node-footer-layer-newsletter-newsletter-card{') && str_contains($overlappingFooterLayerCss, 'position:absolute;left:112px;top:0px;z-index:2'), 'overlapping-footer-newsletter-layer-on-top');
    $assert(str_contains($overlappingFooterLayerCss, '.figma-node-footer-layer-bottom-yellow-footer-area{') && str_contains($overlappingFooterLayerCss, 'position:absolute;left:0px;top:300px;z-index:1'), 'overlapping-footer-bottom-layer-under-newsletter');

    $kiwiSourceGapReserveResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Kiwi Source Gap Reserve Fixture',
        'nodes' => array(
            array(
                'id'           => 'kiwi-gap:root',
                'type'         => 'FRAME',
                'name'         => 'Article template',
                'width'        => 960,
                'height'       => 720,
                'stackMode'    => 'VERTICAL',
                'stackSpacing' => 24,
                'children'     => array(
                    array('id' => 'kiwi-gap:read-next', 'type' => 'FRAME', 'name' => 'Read next section', 'width' => 960, 'height' => 200, 'transform' => array('m02' => 0, 'm12' => 0), 'children' => array(
                        array('id' => 'kiwi-gap:read-next:title', 'type' => 'TEXT', 'name' => 'Read next title', 'characters' => 'Read Next', 'width' => 160, 'height' => 32, 'fontSize' => 24),
                    )),
                    array('id' => 'kiwi-gap:footer', 'type' => 'FRAME', 'name' => 'Footer', 'width' => 960, 'height' => 120, 'transform' => array('m02' => 0, 'm12' => 280), 'children' => array(
                        array('id' => 'kiwi-gap:footer:text', 'type' => 'TEXT', 'name' => 'Footer text', 'characters' => 'Footer links', 'width' => 140, 'height' => 24, 'fontSize' => 16),
                    )),
                ),
            ),
        ),
    ));
    $kiwiSourceGapReserveCss = $fileContent($kiwiSourceGapReserveResult, 'style.css');
    $kiwiSourceGapReserveMap = $kiwiSourceGapReserveResult['source_reports']['figma']['html']['visual_node_map'] ?? array();
    $kiwiSourceGapReserveFooter = array_values(array_filter($kiwiSourceGapReserveMap, static fn (array $entry): bool => 'kiwi-gap:footer' === ($entry['id'] ?? null)))[0] ?? array();
    $kiwiSourceGapReserveFooterText = array_values(array_filter($kiwiSourceGapReserveMap, static fn (array $entry): bool => 'kiwi-gap:footer:text' === ($entry['id'] ?? null)))[0] ?? array();
    $assert(str_contains($kiwiSourceGapReserveCss, '.figma-node-kiwi-gap-root-article-template{') && str_contains($kiwiSourceGapReserveCss, 'display:flex;flex-direction:column;gap:24px'), 'kiwi-source-gap-reserve-parent-auto-layout-gap');
    $assert(str_contains($kiwiSourceGapReserveCss, '.figma-node-kiwi-gap-footer-footer{') && str_contains($kiwiSourceGapReserveCss, 'margin-top:56px'), 'kiwi-source-gap-reserve-footer-residual-margin');
    $assert(280.0 === ($kiwiSourceGapReserveFooter['rect']['y'] ?? null), 'kiwi-source-gap-reserve-footer-visual-map-y');
    $assert(280.0 === ($kiwiSourceGapReserveFooterText['rect']['y'] ?? null), 'kiwi-source-gap-reserve-child-visual-map-y');

    $sourceGapEligibilityResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Source Gap Reservation Eligibility Fixture',
        'nodes' => array(
            array(
                'id' => 'source-gap:root', 'type' => 'FRAME', 'name' => 'Source gap cases', 'width' => 400, 'height' => 500,
                'children' => array(
                    array('id' => 'source-gap:row', 'type' => 'FRAME', 'name' => 'Row parent', 'width' => 400, 'height' => 80, 'layoutMode' => 'HORIZONTAL', 'itemSpacing' => 20, 'children' => array(
                        array('id' => 'source-gap:row:first', 'type' => 'RECTANGLE', 'name' => 'Row first', 'width' => 100, 'height' => 40, 'transform' => array('m02' => 0, 'm12' => 0)),
                        array('id' => 'source-gap:row:second', 'type' => 'RECTANGLE', 'name' => 'Row second', 'width' => 100, 'height' => 40, 'transform' => array('m02' => 150, 'm12' => 0)),
                    )),
                    array('id' => 'source-gap:absolute', 'type' => 'FRAME', 'name' => 'Absolute parent', 'width' => 400, 'height' => 80, 'layoutMode' => 'HORIZONTAL', 'itemSpacing' => 20, 'children' => array(
                        array('id' => 'source-gap:absolute:first', 'type' => 'RECTANGLE', 'name' => 'Absolute first', 'width' => 100, 'height' => 40, 'transform' => array('m02' => 0, 'm12' => 0)),
                        array('id' => 'source-gap:absolute:second', 'type' => 'RECTANGLE', 'name' => 'Absolute second', 'width' => 100, 'height' => 40, 'layoutPositioning' => 'ABSOLUTE', 'transform' => array('m02' => 150, 'm12' => 0)),
                    )),
                    array('id' => 'source-gap:wrap', 'type' => 'FRAME', 'name' => 'Wrap parent', 'width' => 400, 'height' => 80, 'layoutMode' => 'HORIZONTAL', 'layoutWrap' => 'WRAP', 'itemSpacing' => 20, 'children' => array(
                        array('id' => 'source-gap:wrap:first', 'type' => 'RECTANGLE', 'name' => 'Wrap first', 'width' => 100, 'height' => 40, 'transform' => array('m02' => 0, 'm12' => 0)),
                        array('id' => 'source-gap:wrap:second', 'type' => 'RECTANGLE', 'name' => 'Wrap second', 'width' => 100, 'height' => 40, 'transform' => array('m02' => 150, 'm12' => 0)),
                    )),
                    array('id' => 'source-gap:center', 'type' => 'FRAME', 'name' => 'Center parent', 'width' => 400, 'height' => 80, 'layoutMode' => 'HORIZONTAL', 'primaryAxisAlignItems' => 'CENTER', 'itemSpacing' => 20, 'children' => array(
                        array('id' => 'source-gap:center:first', 'type' => 'RECTANGLE', 'name' => 'Center first', 'width' => 100, 'height' => 40, 'transform' => array('m02' => 0, 'm12' => 0)),
                        array('id' => 'source-gap:center:second', 'type' => 'RECTANGLE', 'name' => 'Center second', 'width' => 100, 'height' => 40, 'transform' => array('m02' => 250, 'm12' => 0)),
                    )),
                    array('id' => 'source-gap:end', 'type' => 'FRAME', 'name' => 'End parent', 'width' => 400, 'height' => 80, 'layoutMode' => 'HORIZONTAL', 'primaryAxisAlignItems' => 'MAX', 'itemSpacing' => 20, 'children' => array(
                        array('id' => 'source-gap:end:first', 'type' => 'RECTANGLE', 'name' => 'End first', 'width' => 100, 'height' => 40, 'transform' => array('m02' => 0, 'm12' => 0)),
                        array('id' => 'source-gap:end:second', 'type' => 'RECTANGLE', 'name' => 'End second', 'width' => 100, 'height' => 40, 'transform' => array('m02' => 320, 'm12' => 0)),
                    )),
                    array('id' => 'source-gap:interleaved', 'type' => 'FRAME', 'name' => 'Interleaved parent', 'width' => 400, 'height' => 80, 'layoutMode' => 'HORIZONTAL', 'itemSpacing' => 20, 'children' => array(
                        array('id' => 'source-gap:interleaved:first', 'type' => 'RECTANGLE', 'name' => 'Interleaved first', 'width' => 100, 'height' => 40, 'transform' => array('m02' => 0, 'm12' => 0)),
                        array('id' => 'source-gap:interleaved:absolute', 'type' => 'RECTANGLE', 'name' => 'Interleaved absolute', 'width' => 20, 'height' => 20, 'layoutPositioning' => 'ABSOLUTE', 'transform' => array('m02' => 120, 'm12' => 0)),
                        array('id' => 'source-gap:interleaved:second', 'type' => 'RECTANGLE', 'name' => 'Interleaved second', 'width' => 101, 'height' => 40, 'transform' => array('m02' => 150, 'm12' => 0)),
                    )),
                ),
            ),
        ),
    ));
    $sourceGapEligibilityCss = $fileContent($sourceGapEligibilityResult, 'style.css');
    $sourceGapEligibilityMap = $sourceGapEligibilityResult['source_reports']['figma']['html']['visual_node_map'] ?? array();
    $sourceGapEligibilityNode = static function (string $id) use ($sourceGapEligibilityMap): array {
        foreach ( $sourceGapEligibilityMap as $entry ) {
            if ( is_array($entry) && $id === ($entry['id'] ?? null) ) {
                return $entry;
            }
        }
        return array();
    };
    $hasSourceGapMargin = static fn (string $class): bool => 1 === preg_match('/\.' . preg_quote($class, '/') . '\{[^}]*margin-left:/', $sourceGapEligibilityCss);
    $assert($hasSourceGapMargin('figma-node-source-gap-row-second-row-second'), 'source-gap-reserve-row-emits-residual-margin');
    $assert(150.0 === ($sourceGapEligibilityNode('source-gap:row:second')['rect']['x'] ?? null), 'source-gap-reserve-row-visual-map-x');
    $assert(! $hasSourceGapMargin('figma-node-source-gap-absolute-second-absolute-second'), 'source-gap-reserve-absolute-excluded-from-css');
    $assert(150.0 === ($sourceGapEligibilityNode('source-gap:absolute:second')['rect']['x'] ?? null), 'source-gap-reserve-absolute-excluded-from-map-accumulator');
    $assert(! $hasSourceGapMargin('figma-node-source-gap-wrap-second-wrap-second'), 'source-gap-reserve-wrap-excluded-from-css');
    $assert(120.0 === ($sourceGapEligibilityNode('source-gap:wrap:second')['rect']['x'] ?? null), 'source-gap-reserve-wrap-excluded-from-map-accumulator');
    $assert(! $hasSourceGapMargin('figma-node-source-gap-center-second-center-second'), 'source-gap-reserve-center-excluded-from-css');
    $assert(210.0 === ($sourceGapEligibilityNode('source-gap:center:second')['rect']['x'] ?? null), 'source-gap-reserve-center-excluded-from-map-accumulator');
    $assert(! $hasSourceGapMargin('figma-node-source-gap-end-second-end-second'), 'source-gap-reserve-flex-end-excluded-from-css');
    $assert(300.0 === ($sourceGapEligibilityNode('source-gap:end:second')['rect']['x'] ?? null), 'source-gap-reserve-flex-end-excluded-from-map-accumulator');
    $assert($hasSourceGapMargin('figma-node-source-gap-interleaved-second-interleaved-second'), 'source-gap-reserve-interleaved-flow-skips-absolute-sibling-in-css');
    $assert(150.0 === ($sourceGapEligibilityNode('source-gap:interleaved:second')['rect']['x'] ?? null), 'source-gap-reserve-interleaved-flow-skips-absolute-sibling-in-map');

    $inlineFlexSourceGapResult = (new StaticHtmlEmitter())->emit(array(
        'name' => 'Inline Flex Source Gap Fixture',
        'nodes' => array(
            array(
                'id' => 'source-gap:inline-parent', 'type' => 'FRAME', 'name' => 'Inline parent',
                'box' => array('x' => 0, 'y' => 0, 'width' => 400, 'height' => 80, 'coordinate_space' => 'local'),
                'layout' => array('display' => 'inline-flex', 'flex_direction' => 'row', 'item_spacing' => 20),
                'children' => array(
                    array('id' => 'source-gap:inline-first', 'type' => 'RECTANGLE', 'name' => 'Inline first', 'box' => array('x' => 0, 'y' => 0, 'width' => 100, 'height' => 40, 'coordinate_space' => 'local')),
                    array('id' => 'source-gap:inline-second', 'type' => 'RECTANGLE', 'name' => 'Inline second', 'box' => array('x' => 150, 'y' => 0, 'width' => 100, 'height' => 40, 'coordinate_space' => 'local')),
                ),
            ),
        ),
    ));
    $inlineFlexSourceGapCss = $fileContent($inlineFlexSourceGapResult, 'style.css');
    $inlineFlexSourceGapMap = $inlineFlexSourceGapResult['source_report']['visual_node_map'] ?? array();
    $inlineFlexSourceGapSecond = array_values(array_filter($inlineFlexSourceGapMap, static fn (array $entry): bool => 'source-gap:inline-second' === ($entry['id'] ?? null)))[0] ?? array();
    $assert(str_contains($inlineFlexSourceGapCss, '.figma-node-source-gap-inline-second-inline-second{') && str_contains($inlineFlexSourceGapCss, 'margin-left:30px'), 'source-gap-reserve-inline-flex-emits-residual-margin');
    $assert(150.0 === ($inlineFlexSourceGapSecond['rect']['x'] ?? null), 'source-gap-reserve-inline-flex-visual-map-x');

    $stickyGhostResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Sticky Ghost Fixture',
        'nodes' => array(
            array(
                'id'       => 'sticky:root',
                'type'     => 'FRAME',
                'name'     => 'Sticky page',
                'width'    => 960,
                'height'   => 1800,
                'children' => array(
                    array(
                        'id'         => 'sticky:row',
                        'type'       => 'FRAME',
                        'name'       => 'Article row',
                        'width'      => 960,
                        'height'     => 1600,
                        'layoutMode' => 'HORIZONTAL',
                        'itemSpacing' => 24,
                        'children'   => array(
                            array('id' => 'sticky:toc-primary', 'type' => 'FRAME', 'name' => 'Table of contents', 'width' => 240, 'height' => 320, 'children' => array(
                                array('id' => 'sticky:toc-title', 'type' => 'TEXT', 'name' => 'TOC title', 'characters' => 'Contents', 'width' => 120, 'height' => 24, 'fontSize' => 18),
                                array('id' => 'sticky:toc-link', 'type' => 'TEXT', 'name' => 'TOC link', 'characters' => 'Introduction', 'width' => 120, 'height' => 20, 'fontSize' => 14),
                            )),
                            array('id' => 'sticky:article', 'type' => 'FRAME', 'name' => 'Article body', 'width' => 696, 'height' => 1200, 'children' => array(
                                array('id' => 'sticky:article-title', 'type' => 'TEXT', 'name' => 'Article title', 'characters' => 'Long article', 'width' => 320, 'height' => 48, 'fontSize' => 36),
                            )),
                            array('id' => 'sticky:toc-ghost', 'type' => 'FRAME', 'name' => 'Table of contents', 'x' => 0, 'y' => 1280, 'width' => 240, 'height' => 320, 'layoutPositioning' => 'ABSOLUTE', 'constraints' => array('horizontal' => 'LEFT', 'vertical' => 'BOTTOM'), 'opacity' => 0.1, 'children' => array(
                                array('id' => 'sticky:toc-ghost-title', 'type' => 'TEXT', 'name' => 'TOC title', 'characters' => 'Contents', 'width' => 120, 'height' => 24, 'fontSize' => 18),
                                array('id' => 'sticky:toc-ghost-link', 'type' => 'TEXT', 'name' => 'TOC link', 'characters' => 'Introduction', 'width' => 120, 'height' => 20, 'fontSize' => 14),
                            )),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $stickyGhostHtml = $fileContent($stickyGhostResult, 'index.html');
    $stickyGhostCss = $fileContent($stickyGhostResult, 'style.css');
    $stickyGhostLayout = $stickyGhostResult['source_reports']['figma']['html']['transform_diagnostics']['layout'] ?? array();
    $assert(str_contains($stickyGhostHtml, 'data-figma-node-id="sticky:toc-primary"'), 'sticky-ghost-primary-emitted');
    $assert(! str_contains($stickyGhostHtml, 'data-figma-node-id="sticky:toc-ghost"'), 'sticky-ghost-duplicate-suppressed');
    $assert(str_contains($stickyGhostCss, '.figma-node-sticky-toc-primary-table-of-contents{') && str_contains($stickyGhostCss, 'position:sticky;top:0;align-self:flex-start'), 'sticky-ghost-primary-css-sticky');
    $assert(1 === ($stickyGhostLayout['sticky_ghosts']['count'] ?? null), 'sticky-ghost-diagnostic-count');
    $assert('sticky:toc-primary' === ($stickyGhostLayout['sticky_ghosts']['candidates'][0]['primary_id'] ?? null), 'sticky-ghost-diagnostic-primary');
    $assert('sticky:toc-ghost' === ($stickyGhostLayout['sticky_ghosts']['candidates'][0]['ghost_id'] ?? null), 'sticky-ghost-diagnostic-ghost');

    $stickyGhostSourceMismatchResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Sticky Ghost Source Mismatch Fixture',
        'nodes' => array(
            array(
                'id'       => 'sticky-real:root',
                'type'     => 'FRAME',
                'name'     => 'Blog Post Desktop',
                'width'    => 1440,
                'height'   => 4800,
                'clipsContent' => true,
                'children' => array(
                    array(
                        'id'         => '4194:2157',
                        'type'       => 'FRAME',
                        'name'       => 'Frame 51076550',
                        'width'      => 1216,
                        'height'     => 4163.5,
                        'clipsContent' => true,
                        'layoutMode' => 'HORIZONTAL',
                        'itemSpacing' => 105,
                        'children'   => array(
                            array('id' => '4212:3087', 'figma_component_source_id' => '4212:3087', 'type' => 'INSTANCE', 'name' => 'Table of Contents', 'width' => 315, 'height' => 510, 'children' => array(
                                array('id' => '4212:3087/4198:8360', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Table of Contents', 'width' => 180, 'height' => 24, 'fontSize' => 18),
                                array('id' => '4212:3087/4188:11209', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'The Unboxing Experience', 'width' => 240, 'height' => 28, 'fontSize' => 20),
                                array('id' => '4212:3087/4188:11211', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'The Build Process', 'width' => 240, 'height' => 28, 'fontSize' => 20),
                                array('id' => '4212:3087/4188:11213', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Step-By-Step Construction', 'width' => 240, 'height' => 24, 'fontSize' => 16),
                                array('id' => '4212:3087/4188:11220', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Conclusion', 'width' => 240, 'height' => 28, 'fontSize' => 20),
                            )),
                            array('id' => 'sticky-real:article', 'type' => 'FRAME', 'name' => 'Article body', 'width' => 796, 'height' => 3000, 'clipsContent' => true, 'children' => array(
                                array('id' => 'sticky-real:title', 'type' => 'TEXT', 'name' => 'Article title', 'characters' => 'Long article', 'width' => 420, 'height' => 56, 'fontSize' => 42),
                                array('id' => 'sticky-real:unboxing', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'The Unboxing Experience', 'width' => 520, 'height' => 44, 'fontSize' => 28),
                                array('id' => 'sticky-real:build', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'The Build Process', 'width' => 520, 'height' => 44, 'fontSize' => 28),
                                array('id' => 'sticky-real:steps', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Step-By-Step Construction', 'width' => 480, 'height' => 32, 'fontSize' => 22),
                                array('id' => 'sticky-real:conclusion', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Conclusion', 'width' => 520, 'height' => 44, 'fontSize' => 28),
                            )),
                            array('id' => '4210:12595', 'figma_component_source_id' => '4210:12595', 'type' => 'INSTANCE', 'name' => 'Table of Contents', 'x' => 0, 'y' => 3654, 'width' => 315, 'height' => 510, 'layoutPositioning' => 'ABSOLUTE', 'constraints' => array('horizontal' => 'LEFT', 'vertical' => 'BOTTOM'), 'opacity' => 0.1, 'children' => array(
                                array('id' => '4210:12595/4198:8360', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Table of Contents', 'width' => 180, 'height' => 24, 'fontSize' => 18),
                                array('id' => '4210:12595/4188:11209', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'The Unboxing Experience', 'width' => 240, 'height' => 28, 'fontSize' => 20),
                                array('id' => '4210:12595/4188:11211', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'The Build Process', 'width' => 240, 'height' => 28, 'fontSize' => 20),
                                array('id' => '4210:12595/4188:11213', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Step-By-Step Construction', 'width' => 240, 'height' => 24, 'fontSize' => 16),
                                array('id' => '4210:12595/4188:11220', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Conclusion', 'width' => 240, 'height' => 28, 'fontSize' => 20),
                            )),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $stickyGhostSourceMismatchHtml = $fileContent($stickyGhostSourceMismatchResult, 'index.html');
    $stickyGhostSourceMismatchCss = $fileContent($stickyGhostSourceMismatchResult, 'style.css');
    $stickyGhostSourceMismatchLayout = $stickyGhostSourceMismatchResult['source_reports']['figma']['html']['transform_diagnostics']['layout'] ?? array();
    $assert(str_contains($stickyGhostSourceMismatchHtml, 'data-figma-node-id="4212:3087"'), 'sticky-ghost-source-mismatch-primary-emitted');
    $assert(! str_contains($stickyGhostSourceMismatchHtml, 'data-figma-node-id="4210:12595"'), 'sticky-ghost-source-mismatch-duplicate-suppressed');
    $assert(str_contains($stickyGhostSourceMismatchCss, '.figma-node-4212-3087-table-of-contents{') && str_contains($stickyGhostSourceMismatchCss, 'position:sticky;top:0;align-self:flex-start'), 'sticky-ghost-source-mismatch-primary-css-sticky');
    $assert(! preg_match('/\.figma-node-sticky-real-root-blog-post-desktop\{[^}]*overflow:hidden/', $stickyGhostSourceMismatchCss), 'sticky-ghost-source-mismatch-root-no-overflow-hidden');
    $assert(! preg_match('/\.figma-node-4194-2157-frame-51076550\{[^}]*overflow:hidden/', $stickyGhostSourceMismatchCss), 'sticky-ghost-source-mismatch-row-no-overflow-hidden');
    $assert(1 === preg_match('/\.figma-node-sticky-real-article-article-body\{[^}]*overflow:hidden/', $stickyGhostSourceMismatchCss), 'sticky-ghost-source-mismatch-sibling-clipping-preserved');
    $assert(1 === preg_match('/<a class="figma-link figma-toc-link" href="#the-unboxing-experience" data-figma-link-type="toc"><p class="[^"]*figma-node-4212-3087-4188-11209-heading[^"]*"/', $stickyGhostSourceMismatchHtml), 'toc-entry-links-to-matching-heading');
    $assert(1 === preg_match('/<h2 class="[^"]*figma-node-sticky-real-unboxing-heading[^"]*"[^>]*id="the-unboxing-experience"[^>]*>The Unboxing Experience<\/h2>/', $stickyGhostSourceMismatchHtml), 'matching-heading-receives-text-anchor');
    $assert(1 === preg_match('/<h3 class="[^"]*figma-node-sticky-real-steps-heading[^"]*"[^>]*id="step-by-step-construction"[^>]*>Step-By-Step Construction<\/h3>/', $stickyGhostSourceMismatchHtml), 'nested-heading-keeps-structural-level');
    $assert(! str_contains($stickyGhostSourceMismatchHtml, '<h2 class="figma-node-4212-3087-4188-11209-heading"'), 'toc-entry-not-rendered-as-heading');
    $assert(1 === ($stickyGhostSourceMismatchLayout['sticky_ghosts']['count'] ?? null), 'sticky-ghost-source-mismatch-diagnostic-count');
    $assert('4212:3087' === ($stickyGhostSourceMismatchLayout['sticky_ghosts']['candidates'][0]['primary_id'] ?? null), 'sticky-ghost-source-mismatch-diagnostic-primary');
    $assert('4210:12595' === ($stickyGhostSourceMismatchLayout['sticky_ghosts']['candidates'][0]['ghost_id'] ?? null), 'sticky-ghost-source-mismatch-diagnostic-ghost');

    $stickyGhostMultiPageResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Sticky Ghost Multi Page Fixture',
        'nodes' => array(
            array(
                'id'       => 'sticky-multi:canvas',
                'type'     => 'CANVAS',
                'name'     => 'Site',
                'children' => array(
                    array(
                        'id'       => 'sticky-multi:home',
                        'type'     => 'FRAME',
                        'name'     => 'Home',
                        'width'    => 1440,
                        'height'   => 900,
                        'children' => array(
                            array('id' => 'sticky-multi:home-title', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Home', 'width' => 180, 'height' => 48, 'fontSize' => 36),
                        ),
                    ),
                    array(
                        'id'       => 'sticky-multi:section',
                        'type'     => 'SECTION',
                        'name'     => 'Blog Post Section',
                        'children' => array(
                            array(
                                'id'       => 'sticky-multi:blog-desktop',
                                'type'     => 'FRAME',
                                'name'     => 'Blog Post Desktop',
                                'width'    => 1440,
                                'height'   => 4800,
                                'children' => array(
                                    array(
                                        'id'         => '4194:2157',
                                        'type'       => 'FRAME',
                                        'name'       => 'Frame 51076550',
                                        'width'      => 1216,
                                        'height'     => 4163.5,
                                        'layoutMode' => 'HORIZONTAL',
                                        'itemSpacing' => 105,
                                        'children'   => array(
                                            array('id' => '4212:3087', 'figma_component_source_id' => '4212:3087', 'type' => 'INSTANCE', 'name' => 'Table of Contents', 'width' => 315, 'height' => 510, 'children' => array(
                                                array('id' => '4212:3087/4198:8360', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Table of Contents', 'width' => 180, 'height' => 24, 'fontSize' => 18),
                                                array('id' => '4212:3087/4188:11209', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'The Unboxing Experience', 'width' => 240, 'height' => 28, 'fontSize' => 20),
                                                array('id' => '4212:3087/4188:11211', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'The Build Process', 'width' => 240, 'height' => 28, 'fontSize' => 20),
                                                array('id' => '4212:3087/4188:11213', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Step-By-Step Construction', 'width' => 240, 'height' => 24, 'fontSize' => 16),
                                                array('id' => '4212:3087/4188:11220', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Conclusion', 'width' => 240, 'height' => 28, 'fontSize' => 20),
                                            )),
                                            array('id' => 'sticky-multi:article', 'type' => 'FRAME', 'name' => 'Article body', 'width' => 796, 'height' => 3000, 'children' => array(
                                                array('id' => 'sticky-multi:title', 'type' => 'TEXT', 'name' => 'Article title', 'characters' => 'Long article', 'width' => 420, 'height' => 56, 'fontSize' => 42),
                                            )),
                                            array('id' => '4210:12595', 'figma_component_source_id' => '4210:12595', 'type' => 'INSTANCE', 'name' => 'Table of Contents', 'x' => 0, 'y' => 3654, 'width' => 315, 'height' => 510, 'layoutPositioning' => 'ABSOLUTE', 'constraints' => array('horizontal' => 'LEFT', 'vertical' => 'BOTTOM'), 'opacity' => 0.1, 'children' => array(
                                                array('id' => '4210:12595/4198:8360', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Table of Contents', 'width' => 180, 'height' => 24, 'fontSize' => 18),
                                                array('id' => '4210:12595/4188:11209', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'The Unboxing Experience', 'width' => 240, 'height' => 28, 'fontSize' => 20),
                                                array('id' => '4210:12595/4188:11211', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'The Build Process', 'width' => 240, 'height' => 28, 'fontSize' => 20),
                                                array('id' => '4210:12595/4188:11213', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Step-By-Step Construction', 'width' => 240, 'height' => 24, 'fontSize' => 16),
                                                array('id' => '4210:12595/4188:11220', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Conclusion', 'width' => 240, 'height' => 28, 'fontSize' => 20),
                                            )),
                                        ),
                                    ),
                                ),
                            ),
                            array(
                                'id'       => 'sticky-multi:blog-mobile',
                                'type'     => 'FRAME',
                                'name'     => 'Blog Post Mobile',
                                'width'    => 390,
                                'height'   => 4200,
                                'children' => array(
                                    array('id' => 'sticky-multi:mobile-title', 'type' => 'TEXT', 'name' => 'Article title', 'characters' => 'Long article', 'width' => 320, 'height' => 44, 'fontSize' => 32),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ), array(
        'multi_page'     => true,
        'frame_ids'      => array('sticky-multi:home', 'sticky-multi:blog-desktop', 'sticky-multi:blog-mobile'),
        'entry_frame_id' => 'sticky-multi:home',
    ));
    $stickyGhostMultiPageHtml = $fileContent($stickyGhostMultiPageResult, 'blog-post.html');
    $stickyGhostMultiPageCss = $fileContent($stickyGhostMultiPageResult, 'style.css');
    $stickyGhostMultiPageLayout = $stickyGhostMultiPageResult['source_reports']['figma']['html']['transform_diagnostics']['layout'] ?? array();
    $assert('' !== $stickyGhostMultiPageHtml, 'sticky-ghost-multi-page-blog-post-emitted');
    $assert(str_contains($stickyGhostMultiPageHtml, 'data-figma-node-id="4212:3087"'), 'sticky-ghost-multi-page-primary-emitted');
    $assert(! str_contains($stickyGhostMultiPageHtml, 'data-figma-node-id="4210:12595"'), 'sticky-ghost-multi-page-ghost-absent-from-final-html');
    $assert(str_contains($stickyGhostMultiPageCss, '.figma-node-4212-3087-table-of-contents{') && str_contains($stickyGhostMultiPageCss, 'position:sticky;top:0;align-self:flex-start'), 'sticky-ghost-multi-page-primary-sticky-in-final-css');
    $assert(! str_contains($stickyGhostMultiPageCss, '.figma-node-4210-12595-table-of-contents'), 'sticky-ghost-multi-page-ghost-absent-from-final-css');
    $assert(1 === ($stickyGhostMultiPageLayout['sticky_ghosts']['count'] ?? null), 'sticky-ghost-multi-page-diagnostic-count');
    $assert('4212:3087' === ($stickyGhostMultiPageLayout['sticky_ghosts']['candidates'][0]['primary_id'] ?? null), 'sticky-ghost-multi-page-diagnostic-primary');
    $assert('4210:12595' === ($stickyGhostMultiPageLayout['sticky_ghosts']['candidates'][0]['ghost_id'] ?? null), 'sticky-ghost-multi-page-diagnostic-ghost');

    $repeatedCardsResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Repeated Cards Fixture',
        'nodes' => array(
            array(
                'id'       => 'repeat:root',
                'type'     => 'FRAME',
                'name'     => 'Repeated cards root',
                'width'    => 640,
                'height'   => 900,
                'children' => array(
                    array(
                        'id'         => 'repeat:row',
                        'type'       => 'FRAME',
                        'name'       => 'Cards row',
                        'width'      => 640,
                        'height'     => 720,
                        'layoutMode' => 'HORIZONTAL',
                        'itemSpacing' => 24,
                        'children'   => array(
                            array('id' => 'repeat:card-a', 'type' => 'FRAME', 'name' => 'Promo card', 'width' => 240, 'height' => 180, 'children' => array(
                                array('id' => 'repeat:card-a-title', 'type' => 'TEXT', 'name' => 'Card title', 'characters' => 'Featured', 'width' => 120, 'height' => 24, 'fontSize' => 18),
                            )),
                            array('id' => 'repeat:card-b', 'type' => 'FRAME', 'name' => 'Promo card', 'width' => 240, 'height' => 180, 'children' => array(
                                array('id' => 'repeat:card-b-title', 'type' => 'TEXT', 'name' => 'Card title', 'characters' => 'Featured', 'width' => 120, 'height' => 24, 'fontSize' => 18),
                            )),
                            array('id' => 'repeat:card-c', 'type' => 'FRAME', 'name' => 'Promo card', 'x' => 0, 'y' => 540, 'width' => 240, 'height' => 180, 'layoutPositioning' => 'ABSOLUTE', 'constraints' => array('horizontal' => 'LEFT', 'vertical' => 'BOTTOM'), 'opacity' => 1, 'children' => array(
                                array('id' => 'repeat:card-c-title', 'type' => 'TEXT', 'name' => 'Card title', 'characters' => 'Featured', 'width' => 120, 'height' => 24, 'fontSize' => 18),
                            )),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $repeatedCardsHtml = $fileContent($repeatedCardsResult, 'index.html');
    $repeatedCardsLayout = $repeatedCardsResult['source_reports']['figma']['html']['transform_diagnostics']['layout'] ?? array();
    $assert(str_contains($repeatedCardsHtml, 'data-figma-node-id="repeat:card-a"'), 'repeated-cards-flow-card-a-emitted');
    $assert(str_contains($repeatedCardsHtml, 'data-figma-node-id="repeat:card-b"'), 'repeated-cards-flow-card-b-emitted');
    $assert(str_contains($repeatedCardsHtml, 'data-figma-node-id="repeat:card-c"'), 'repeated-cards-full-opacity-absolute-emitted');
    $assert(0 === ($repeatedCardsLayout['sticky_ghosts']['count'] ?? null), 'repeated-cards-no-sticky-ghost-diagnostic');
    
    $emptyVisibleContainerResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Empty Visible Container Classification Fixture',
        'nodes' => array(
            array(
                'id'       => 'empty-container:root',
                'type'     => 'FRAME',
                'name'     => 'Empty visible root',
                'width'    => 480,
                'height'   => 320,
                'children' => array(
                    array('id' => 'empty-container:separator', 'type' => 'FRAME', 'name' => '–', 'width' => 320, 'height' => 0.0002),
                    array('id' => 'empty-container:instance', 'type' => 'INSTANCE', 'name' => 'Missing component body', 'width' => 180, 'height' => 48),
                    array('id' => 'empty-container:frame', 'type' => 'FRAME', 'name' => 'Empty visual frame', 'width' => 24, 'height' => 24),
                    array('id' => 'empty-container:checkbox-row', 'type' => 'FRAME', 'name' => 'Checkbox row', 'width' => 240, 'height' => 24, 'layoutMode' => 'HORIZONTAL', 'itemSpacing' => 8, 'children' => array(
                        array('id' => 'empty-container:checkbox', 'type' => 'FRAME', 'name' => 'Checkbox', 'width' => 24, 'height' => 24, 'strokeAlign' => 'INSIDE', 'strokeWeight' => 1, 'strokes' => array(array('type' => 'SOLID', 'color' => array('r' => 0.5, 'g' => 0.5, 'b' => 0.5, 'a' => 1)))),
                        array('id' => 'empty-container:checkbox-label', 'type' => 'TEXT', 'name' => 'Checkbox label', 'characters' => 'Save my details', 'width' => 120, 'height' => 21, 'fontSize' => 14),
                    )),
                ),
            ),
        ),
    ));
    $emptyVisibleLayout = $emptyVisibleContainerResult['source_reports']['figma']['html']['transform_diagnostics']['layout'] ?? array();
    $assert(4 === ($emptyVisibleLayout['empty_visible_container_count'] ?? null), 'empty-visible-container-classification-count');
    $assert(2 === ($emptyVisibleLayout['empty_visible_container_blocker_count'] ?? null), 'empty-visible-container-classification-blocker-count');
    $assert(1 === ($emptyVisibleLayout['empty_visible_container_categories']['decorative_zero_height_separator'] ?? null), 'empty-visible-container-classification-decorative-count');
    $assert(1 === ($emptyVisibleLayout['empty_visible_container_categories']['missing_instance_descendants'] ?? null), 'empty-visible-container-classification-instance-count');
    $assert(1 === ($emptyVisibleLayout['empty_visible_container_categories']['empty_visible_container'] ?? null), 'empty-visible-container-classification-frame-count');
    $assert(1 === ($emptyVisibleLayout['empty_visible_container_categories']['form_control_chrome'] ?? null), 'empty-visible-container-classification-form-control-count');
    $emptyVisibleById = array();
    foreach ( is_array($emptyVisibleLayout['empty_visible_containers'] ?? null) ? $emptyVisibleLayout['empty_visible_containers'] : array() as $sample ) {
        if ( is_array($sample) ) {
            $emptyVisibleById[(string) ($sample['node_id'] ?? '')] = $sample;
        }
    }
    $assert(false === ($emptyVisibleById['empty-container:separator']['blocks_parity'] ?? null), 'empty-visible-container-decorative-non-blocking');
    $assert(false === ($emptyVisibleById['empty-container:checkbox']['blocks_parity'] ?? null), 'empty-visible-container-form-control-non-blocking');
    $assert('form_control_chrome' === ($emptyVisibleById['empty-container:checkbox']['category'] ?? null), 'empty-visible-container-form-control-category');
    $assert(true === ($emptyVisibleById['empty-container:instance']['blocks_parity'] ?? null), 'empty-visible-container-instance-blocking');
    $assert('missing_instance_descendants' === ($emptyVisibleById['empty-container:instance']['category'] ?? null), 'empty-visible-container-instance-category');
    
    $multiPageEmptyVisibleResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Multi Page Empty Visible Classification Fixture',
        'nodes' => array(
            array(
                'id'       => 'multi-empty:canvas',
                'type'     => 'CANVAS',
                'name'     => 'Pages',
                'children' => array(
                    array('id' => 'multi-empty:one', 'type' => 'FRAME', 'name' => 'One', 'width' => 320, 'height' => 240, 'children' => array(
                        array('id' => 'multi-empty:one-separator', 'type' => 'FRAME', 'name' => '–', 'width' => 280, 'height' => 0.0002),
                    )),
                    array('id' => 'multi-empty:two', 'type' => 'FRAME', 'name' => 'Two', 'width' => 320, 'height' => 240, 'children' => array(
                        array('id' => 'multi-empty:two-instance', 'type' => 'INSTANCE', 'name' => 'Missing component body', 'width' => 120, 'height' => 32),
                    )),
                ),
            ),
        ),
    ), array('multi_page' => true, 'frame_ids' => array('multi-empty:one', 'multi-empty:two'), 'entry_frame_id' => 'multi-empty:one'));
    $multiPageEmptyVisibleLayout = $multiPageEmptyVisibleResult['source_reports']['figma']['html']['transform_diagnostics']['layout'] ?? array();
    $assert(2 === ($multiPageEmptyVisibleLayout['empty_visible_container_count'] ?? null), 'empty-visible-container-multi-page-count');
    $assert(1 === ($multiPageEmptyVisibleLayout['empty_visible_container_blocker_count'] ?? null), 'empty-visible-container-multi-page-blocker-count');
    $assert(1 === ($multiPageEmptyVisibleLayout['empty_visible_container_categories']['decorative_zero_height_separator'] ?? null), 'empty-visible-container-multi-page-decorative-count');
    $assert(1 === ($multiPageEmptyVisibleLayout['empty_visible_container_categories']['missing_instance_descendants'] ?? null), 'empty-visible-container-multi-page-instance-count');
    
    $multiPageOffCanvasResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Multi Page Off Canvas Diagnostics Fixture',
        'nodes' => array(
            array(
                'id'       => 'multi-offset:canvas',
                'type'     => 'CANVAS',
                'name'     => 'Site pages',
                'children' => array(
                    array(
                        'id'       => 'multi-offset:home',
                        'type'     => 'FRAME',
                        'name'     => 'Home',
                        'width'    => 400,
                        'height'   => 300,
                        'children' => array(
                            array('id' => 'multi-offset:home-title', 'type' => 'TEXT', 'name' => 'Home title', 'characters' => 'Home', 'fontSize' => 20),
                        ),
                    ),
                    array(
                        'id'       => 'multi-offset:about',
                        'type'     => 'FRAME',
                        'name'     => 'About',
                        'width'    => 400,
                        'height'   => 300,
                        'children' => array(
                            array('id' => 'multi-offset:hero', 'type' => 'FRAME', 'name' => 'Drifted hero', 'x' => 1200, 'y' => 0, 'width' => 120, 'height' => 80, 'layoutPositioning' => 'ABSOLUTE', 'children' => array(
                                array('id' => 'multi-offset:hero-copy', 'type' => 'TEXT', 'name' => 'Hero copy', 'characters' => 'About', 'fontSize' => 18),
                            )),
                        ),
                    ),
                ),
            ),
        ),
    ), array('multi_page' => true, 'frame_ids' => array('multi-offset:home', 'multi-offset:about'), 'entry_frame_id' => 'multi-offset:home'));
    $multiPageOffCanvasDiagnostics = $multiPageOffCanvasResult['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
    $multiPageOffCanvasSignal = null;
    foreach ( is_array($multiPageOffCanvasDiagnostics['artifact_quality']['signals'] ?? null) ? $multiPageOffCanvasDiagnostics['artifact_quality']['signals'] : array() as $signal ) {
        if ( is_array($signal) && 'off_canvas_visual_nodes' === ($signal['code'] ?? null) ) {
            $multiPageOffCanvasSignal = $signal;
            break;
        }
    }
    $assert('multi_page' === ($multiPageOffCanvasDiagnostics['scope'] ?? null), 'quality-diagnostics-multi-page-off-canvas-scope');
    $assert(1 === ($multiPageOffCanvasDiagnostics['layout']['off_canvas_visual_node_count'] ?? null), 'quality-diagnostics-multi-page-off-canvas-count');
    $assert('multi-offset:hero' === ($multiPageOffCanvasSignal['sample_nodes'][0]['node_id'] ?? null), 'quality-diagnostics-multi-page-off-canvas-sample-node');
    $assert('about.html' === ($multiPageOffCanvasSignal['sample_nodes'][0]['page_path'] ?? null), 'quality-diagnostics-multi-page-off-canvas-sample-page');
    
    $cleanQualityResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Clean Quality Fixture',
        'nodes' => array(
            array(
                'id'       => 'clean:root',
                'type'     => 'FRAME',
                'name'     => 'Simple content',
                'width'    => 720,
                'height'   => 320,
                'layoutMode' => 'VERTICAL',
                'children' => array(
                    array('id' => 'clean:title', 'type' => 'TEXT', 'name' => 'Page title', 'characters' => 'Clean page', 'fontSize' => 24),
                    array('id' => 'clean:copy', 'type' => 'TEXT', 'name' => 'Page copy', 'characters' => 'A simple responsive-friendly page.', 'fontSize' => 16),
                ),
            ),
        ),
    ));
    $assert(array() === $artifactQualitySignalCodes($cleanQualityResult), 'quality-diagnostics-clean-page-no-signals');
    $assert('clean' === ($cleanQualityResult['source_reports']['figma']['html']['transform_diagnostics']['artifact_quality']['status'] ?? null), 'quality-diagnostics-clean-page-status');
    $assert('pass' === ($cleanQualityResult['source_reports']['figma']['html']['transform_diagnostics']['artifact_quality']['quality_status'] ?? null), 'quality-diagnostics-clean-page-quality-status-pass');
    
    $incompleteQualityResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Incomplete Quality Fixture',
        'nodes' => array(
            array(
                'id'       => 'incomplete:root',
                'type'     => 'FRAME',
                'name'     => 'Incomplete page',
                'width'    => 720,
                'height'   => 320,
                'children' => array(
                    array('id' => 'incomplete:image', 'type' => 'RECTANGLE', 'name' => 'Missing hero asset', 'width' => 320, 'height' => 180, 'asset_id' => 'missing-hero'),
                    array('id' => 'incomplete:vector', 'type' => 'VECTOR', 'name' => 'Unsupported logo mark', 'width' => 24, 'height' => 24),
                ),
            ),
        ),
    ));
    $incompleteQuality = $incompleteQualityResult['source_reports']['figma']['html']['transform_diagnostics']['artifact_quality'] ?? array();
    $assert('fail' === ($incompleteQuality['quality_status'] ?? null), 'quality-diagnostics-incomplete-quality-status-fail');
    $assert(1 === ($incompleteQuality['summary']['missing_asset_nodes'] ?? null), 'quality-diagnostics-incomplete-missing-asset-count');
    $assert(1 === ($incompleteQuality['summary']['vector_placeholders'] ?? null), 'quality-diagnostics-incomplete-vector-placeholder-count');
    
    $offsetPageResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'     => 'Offset Board Fixture',
        'document' => array(
            'id'       => 'doc:1',
            'type'     => 'DOCUMENT',
            'name'     => 'Document',
            'children' => array(
                array(
                    'id'       => 'canvas:1',
                    'type'     => 'CANVAS',
                    'name'     => 'Page 1',
                    'children' => array(
                        array(
                            'id'                  => 'frame:selected',
                            'type'                => 'FRAME',
                            'name'                => 'Selected Website Page',
                            'absoluteBoundingBox' => array('x' => 3497, 'y' => 212, 'width' => 1440, 'height' => 900),
                            'children'            => array(
                                array(
                                    'id'                  => 'frame:selected-card',
                                    'type'                => 'FRAME',
                                    'name'                => 'Hero Card',
                                    'absoluteBoundingBox' => array('x' => 3537, 'y' => 252, 'width' => 320, 'height' => 160),
                                    'layoutPositioning'   => 'ABSOLUTE',
                                    'children'            => array(
                                        array(
                                            'id'                  => 'text:selected-title',
                                            'type'                => 'TEXT',
                                            'name'                => 'Hero title',
                                            'text'                => 'Selected page content',
                                            'fontSize'            => 32,
                                            'absoluteBoundingBox' => array('x' => 3561, 'y' => 280, 'width' => 220, 'height' => 44),
                                        ),
                                    ),
                                ),
                            ),
                        ),
                        array(
                            'id'                  => 'frame:off-canvas-one',
                            'type'                => 'FRAME',
                            'name'                => 'Off Canvas One',
                            'absoluteBoundingBox' => array('x' => 4680, 'y' => 212, 'width' => 1440, 'height' => 900),
                        ),
                        array(
                            'id'                  => 'frame:off-canvas-two',
                            'type'                => 'FRAME',
                            'name'                => 'Off Canvas Two',
                            'absoluteBoundingBox' => array('x' => -2200, 'y' => 212, 'width' => 1440, 'height' => 900),
                        ),
                    ),
                ),
            ),
        ),
    ), array('frame_id' => 'frame:selected'));
    $offsetPageHtml = $fileContent($offsetPageResult, 'index.html');
    $offsetPageCss = $fileContent($offsetPageResult, 'style.css');
    $assert('success' === ($offsetPageResult['status'] ?? null), 'offset-page-transform-success');
    $assert(str_contains($offsetPageHtml, 'Selected page content'), 'offset-page-selected-content-rendered');
    $assert(! str_contains($offsetPageHtml, 'Off Canvas One') && ! str_contains($offsetPageHtml, 'Off Canvas Two'), 'offset-page-off-canvas-siblings-omitted');
    $assert(str_contains($offsetPageCss, '.figma-root{position:relative;width:100%;display:flex;flex-direction:column;align-items:center}'), 'offset-page-root-shell-is-fluid');
    $offsetPageRule = blocks_engine_figma_transformer_contract_css_rule($offsetPageCss, '.figma-node-frame-selected-selected-website-page');
    $assert(str_contains($offsetPageRule, 'width:100%') && ! str_contains($offsetPageRule, 'max-width:1440px'), 'offset-page-root-fills-viewport-without-implicit-canvas-cap');
    $assert(str_contains($offsetPageCss, '.figma-node-frame-selected-card-hero-card{width:320px;height:160px;position:absolute;left:40px;top:40px}'), 'offset-page-child-rebased-position');
    $assert(! str_contains($offsetPageCss, 'left:3497px') && ! str_contains($offsetPageCss, 'left:3537px') && ! str_contains($offsetPageCss, 'left:4680px'), 'offset-page-avoids-board-left-values');
}

/**
 * @param callable(bool, string): void $assert
 */
function blocks_engine_figma_transformer_run_site_generation_planning_contract(callable $assert, callable $fileContent, string $externalizedVectorPath): void
{
    $frameInspection = blocks_engine_figma_transformer_inspect_frames_scenegraph(array(
        'nodes' => array(
            array(
                'id' => 'page:1',
                'type' => 'CANVAS',
                'name' => 'Page One',
                'children' => array(
                    array(
                        'id' => 'section:1',
                        'type' => 'SECTION',
                        'name' => 'Marketing Pages',
                        'width' => 2000,
                        'height' => 1600,
                        'children' => array(
                            array(
                                'id' => 'frame:home',
                                'type' => 'FRAME',
                                'name' => 'Home Page',
                                'width' => 1440,
                                'height' => 1200,
                                'children' => array(
                                    array('id' => 'text:hero', 'type' => 'TEXT', 'name' => 'Hero', 'characters' => 'Hello'),
                                    array('id' => 'image:hero', 'type' => 'RECTANGLE', 'name' => 'Hero image', 'fillPaints' => array(array('type' => 'IMAGE', 'imageRef' => 'hero'))),
                                    array('id' => 'frame:home-map-label', 'type' => 'FRAME', 'name' => 'Home Page', 'width' => 149.85, 'height' => 43.65),
                                ),
                            ),
                            array(
                                'id' => 'frame:home-mobile',
                                'type' => 'FRAME',
                                'name' => 'Home Page Mobile',
                                'width' => 390,
                                'height' => 1200,
                                'children' => array(
                                    array('id' => 'text:hero-mobile', 'type' => 'TEXT', 'name' => 'Hero Mobile', 'characters' => 'Hello'),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ), array('frame_inspection_limit' => 3));
    $frameInspectionHome = null;
    foreach ( $frameInspection['candidates'] ?? array() as $candidate ) {
        if ( is_array($candidate) && 'frame:home' === ($candidate['id'] ?? null) ) {
            $frameInspectionHome = $candidate;
            break;
        }
    }
    $assert('blocks-engine/figma-transformer/frame-inspection/v1' === ($frameInspection['schema'] ?? null), 'frame-inspection-schema');
    $assert(3 === ($frameInspection['returned_count'] ?? null), 'frame-inspection-limit');
    $assert('Page One' === ($frameInspectionHome['page']['name'] ?? null), 'frame-inspection-page-ancestor');
    $assert('Marketing Pages' === ($frameInspectionHome['section']['name'] ?? null), 'frame-inspection-section-ancestor');
    $assert(1 === ($frameInspectionHome['text_count'] ?? null), 'frame-inspection-text-count');
    $assert(1 === ($frameInspectionHome['asset_reference_count'] ?? null), 'frame-inspection-asset-count');
    $assert('desktop' === ($frameInspectionHome['device_hint'] ?? null), 'frame-inspection-desktop-device-hint');
    $assert('frame:home-mobile' === ($frameInspectionHome['responsive_siblings'][0]['id'] ?? null), 'frame-inspection-responsive-sibling-id');
    $assert('mobile' === ($frameInspectionHome['responsive_siblings'][0]['device_hint'] ?? null), 'frame-inspection-responsive-sibling-device-hint');
    $assert(! in_array('frame:home-map-label', array_map(static fn (array $sibling): string => (string) ($sibling['id'] ?? ''), $frameInspectionHome['responsive_siblings'] ?? array()), true), 'frame-inspection-tiny-descendant-not-responsive-sibling');
    $tinyDescendantRejection = null;
    foreach ( $frameInspectionHome['responsive_sibling_rejections'] ?? array() as $rejection ) {
        if ( is_array($rejection) && 'frame:home-map-label' === ($rejection['id'] ?? null) ) {
            $tinyDescendantRejection = $rejection;
            break;
        }
    }
    $assert(null !== $tinyDescendantRejection, 'frame-inspection-tiny-descendant-rejection-recorded');
    $assert(in_array('implausible_dimensions', $tinyDescendantRejection['reasons'] ?? array(), true), 'frame-inspection-tiny-descendant-rejects-dimensions');
    $assert(in_array('implausible_ancestry', $tinyDescendantRejection['reasons'] ?? array(), true), 'frame-inspection-tiny-descendant-rejects-ancestry');
    $tinyDescendantDiagnostic = null;
    foreach ( $frameInspection['diagnostics'] ?? array() as $diagnostic ) {
        if ( is_array($diagnostic) && 'responsive_sibling_candidates_rejected' === ($diagnostic['code'] ?? null) ) {
            $tinyDescendantDiagnostic = $diagnostic;
            break;
        }
    }
    $tinyDescendantDiagnosticSamples = is_array($tinyDescendantDiagnostic['sample_nodes'] ?? null) ? $tinyDescendantDiagnostic['sample_nodes'] : array();
    $assert(0 < count(array_filter($tinyDescendantDiagnosticSamples, static fn (array $sample): bool => 'frame:home-map-label' === ($sample['candidate_id'] ?? null))), 'frame-inspection-tiny-descendant-rejection-diagnostic');
    
    $responsivePagePlanSource = array(
        'nodes' => array(
            array(
                'id'       => 'page:responsive',
                'type'     => 'CANVAS',
                'name'     => 'Responsive Pages',
                'children' => array(
                    array(
                        'id'       => 'section:responsive',
                        'type'     => 'SECTION',
                        'name'     => 'Marketing',
                        'width'    => 4000,
                        'height'   => 5000,
                        'children' => array(
                            array(
                                'id'       => 'frame:home-desktop',
                                'type'     => 'FRAME',
                                'name'     => 'Home Page Desktop',
                                'width'    => 1440,
                                'height'   => 3200,
                                'children' => array(
                                    array('id' => 'text:home-desktop', 'type' => 'TEXT', 'name' => 'Hero', 'characters' => 'Welcome'),
                                ),
                            ),
                            array(
                                'id'       => 'frame:home-tablet',
                                'type'     => 'FRAME',
                                'name'     => 'Home Page Tablet',
                                'width'    => 834,
                                'height'   => 3200,
                                'children' => array(
                                    array('id' => 'text:home-tablet', 'type' => 'TEXT', 'name' => 'Hero', 'characters' => 'Welcome'),
                                ),
                            ),
                            array(
                                'id'       => 'frame:home-mobile',
                                'type'     => 'FRAME',
                                'name'     => 'Home Page Mobile',
                                'width'    => 390,
                                'height'   => 3200,
                                'children' => array(
                                    array('id' => 'text:home-mobile', 'type' => 'TEXT', 'name' => 'Hero', 'characters' => 'Welcome'),
                                ),
                            ),
                            array(
                                'id'       => 'frame:about-desktop',
                                'type'     => 'FRAME',
                                'name'     => 'About Page',
                                'width'    => 1440,
                                'height'   => 3000,
                                'children' => array(
                                    array('id' => 'text:about', 'type' => 'TEXT', 'name' => 'About', 'characters' => 'About us'),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    );
    $responsivePagePlan = ( new ScenegraphPagePlanner() )->plan($responsivePagePlanSource, array('include_all_pages' => true));
    $responsivePageByFrame = static function (array $plan, string $frameId): ?array {
        foreach ( $plan['pages'] ?? array() as $page ) {
            if ( is_array($page) && $frameId === ($page['frame_id'] ?? null) ) {
                return $page;
            }
        }
    
        return null;
    };
    $responsiveHomePage = $responsivePageByFrame($responsivePagePlan, 'frame:home-desktop');
    $responsiveAboutPage = $responsivePageByFrame($responsivePagePlan, 'frame:about-desktop');
    $responsiveSourceFrameEvidence = is_array($responsivePagePlan['source_frame_evidence'] ?? null) ? $responsivePagePlan['source_frame_evidence'] : array();
    $assert(4 === ($responsivePagePlan['candidate_count'] ?? null), 'page-plan-responsive-candidate-count');
    $assert(2 === ($responsivePagePlan['page_count'] ?? null), 'page-plan-responsive-collapses-to-two-pages');
    $assert('blocks-engine/figma-transformer/source-frame-evidence/v1' === ($responsiveSourceFrameEvidence['schema'] ?? null), 'page-plan-source-frame-evidence-schema');
    $responsiveEvidencePrimaryIds = is_array($responsiveSourceFrameEvidence['emitted_primary_frame_ids'] ?? null) ? $responsiveSourceFrameEvidence['emitted_primary_frame_ids'] : array();
    sort($responsiveEvidencePrimaryIds);
    $assert(array('frame:about-desktop', 'frame:home-desktop') === $responsiveEvidencePrimaryIds, 'page-plan-source-frame-evidence-primary-ids');
    $assert(null !== $responsiveHomePage, 'page-plan-responsive-home-primary-is-desktop');
    $assert(true === ($responsiveHomePage['responsive'] ?? null), 'page-plan-responsive-home-flagged-responsive');
    $assert(3 === ($responsiveHomePage['breakpoint_count'] ?? null), 'page-plan-responsive-home-three-breakpoints');
    $assert('frame:home-desktop' === ($responsiveHomePage['source_frame_identity']['selected_frame_id'] ?? null), 'page-plan-source-frame-identity-selected-frame');
    $assert('frame:home-desktop' === ($responsiveHomePage['source_frame_identity']['primary_frame_id'] ?? null), 'page-plan-source-frame-identity-primary-frame');
    $assert(1440.0 === ($responsiveHomePage['source_frame_identity']['width'] ?? null), 'page-plan-source-frame-identity-width');
    $assert(array('frame:home-tablet', 'frame:home-mobile') === ($responsiveHomePage['source_frame_identity']['variant_sibling_frame_ids'] ?? null), 'page-plan-source-frame-identity-variant-siblings');
    // A responsive page's slug reflects the PAGE, not its widest variant: the
    // breakpoint token ("Desktop") is stripped so desktop+mobile collapse to one
    // stable slug.
    $assert('home-page' === ($responsiveHomePage['slug'] ?? null), 'page-plan-responsive-slug-strips-breakpoint-token');
    $assert(array('frame:home-desktop', 'frame:home-tablet', 'frame:home-mobile')
        === array_map(static fn (array $variant): string => (string) ($variant['frame_id'] ?? ''), $responsiveHomePage['variants'] ?? array()), 'page-plan-responsive-variants-ordered-widest-first');
    $assert(array('desktop', 'tablet', 'mobile')
        === array_map(static fn (array $variant): string => (string) ($variant['device_hint'] ?? ''), $responsiveHomePage['variants'] ?? array()), 'page-plan-responsive-variant-device-hints');
    $assert(true === ($responsiveHomePage['variants'][0]['primary'] ?? null)
        && false === ($responsiveHomePage['variants'][1]['primary'] ?? null)
        && false === ($responsiveHomePage['variants'][2]['primary'] ?? null), 'page-plan-responsive-only-widest-is-primary');
    $assert(1440.0 === ($responsiveHomePage['variants'][0]['viewport_width'] ?? null)
        && 390.0 === ($responsiveHomePage['variants'][2]['viewport_width'] ?? null), 'page-plan-responsive-variant-viewport-widths');
    $assert(array('home-page', 'home-page', 'home-page')
        === array_map(static fn (array $variant): string => (string) ($variant['responsive_identity'] ?? ''), $responsiveHomePage['variants'] ?? array()), 'page-plan-responsive-variant-identity-evidence-strips-breakpoint-qualifiers');
    $assert(array('section:responsive:home-page', 'section:responsive:home-page', 'section:responsive:home-page')
        === array_map(static fn (array $variant): string => (string) ($variant['sibling_group_key'] ?? ''), $responsiveHomePage['variants'] ?? array()), 'page-plan-responsive-variant-sibling-group-evidence');
    $assert(null !== $responsiveAboutPage, 'page-plan-responsive-about-stays-its-own-page');
    $assert(false === ($responsiveAboutPage['responsive'] ?? null) && 1 === ($responsiveAboutPage['breakpoint_count'] ?? null), 'page-plan-non-responsive-frame-single-variant');
    $assert(1 === count($responsiveAboutPage['variants'] ?? array()) && 'frame:about-desktop' === ($responsiveAboutPage['variants'][0]['frame_id'] ?? null), 'page-plan-non-responsive-frame-self-variant');
    
    // RESPONSIVE EMISSION (#247 item 2): StaticHtmlEmitter::emitSite consumes a
    // page plan's `variants[]` and renders the primary (widest) variant as the base
    // layout, then emits `@media (max-width: …)` blocks carrying ONLY the per-node
    // style declarations that differ at each narrower breakpoint. A single-variant
    // page emits no `@media` at all. Variant frames are matched onto the base frame
    // by stable source identity where possible so reordered component children keep
    // their geometry on the correct base class names.
    $responsiveEmitFrame = static function (string $id, string $name, float $width, float $height, array $children): array {
        return array(
            'id'         => $id,
            'type'       => 'FRAME',
            'name'       => $name,
            'box'        => array('width' => $width, 'height' => $height),
            'background' => '#ffffff',
            'children'   => $children,
        );
    };
    $responsiveEmitCard = static function (string $id, string $name, float $width, float $height, string $background): array {
        return array(
            'id'         => $id,
            'type'       => 'RECTANGLE',
            'name'       => $name,
            'box'        => array('width' => $width, 'height' => $height),
            'background' => $background,
        );
    };
    $responsiveEmitCardFrame = static function (string $id, string $name, float $width, float $height, string $label): array {
        return array(
            'id'       => $id,
            'type'     => 'FRAME',
            'name'     => $name,
            'box'      => array('width' => $width, 'height' => $height),
            'layout'   => array('display' => 'flex', 'flex_direction' => 'column', 'item_spacing' => 12.0),
            'children' => array(
                array('id' => $id . ':image', 'type' => 'RECTANGLE', 'name' => 'Card image', 'box' => array('width' => max(1.0, $width - 32.0), 'height' => 180.0), 'background' => '#d2d3d4'),
                array('id' => $id . ':copy', 'type' => 'TEXT', 'name' => 'Card copy', 'characters' => $label, 'box' => array('width' => max(1.0, $width - 32.0), 'height' => 24.0), 'fontSize' => 16),
            ),
        );
    };
    $responsiveEmitCardRow = static function (string $id, string $name, float $width, float $height, string $direction) use ($responsiveEmitCardFrame): array {
        return array(
            'id'       => $id,
            'type'     => 'FRAME',
            'name'     => $name,
            'box'      => array('width' => $width, 'height' => $height),
            'layout'   => array('display' => 'flex', 'flex_direction' => $direction, 'item_spacing' => 20.0),
            'children' => array(
                $responsiveEmitCardFrame($id . ':card-a', 'Article card', 'row' === $direction ? 360.0 : $width, 'row' === $direction ? 280.0 : 360.0, 'First responsive card'),
                $responsiveEmitCardFrame($id . ':card-b', 'Article card', 'row' === $direction ? 360.0 : $width, 'row' === $direction ? 280.0 : 360.0, 'Second responsive card'),
                array('id' => $id . ':decor', 'type' => 'RECTANGLE', 'name' => 'Absolute decorative rail', 'box' => array('x' => 0.0, 'y' => 0.0, 'width' => 24.0, 'height' => $height), 'layout' => array('positioning' => 'absolute'), 'background' => '#ffcf00'),
            ),
        );
    };
    $responsiveEmitScenegraph = array(
        'name'  => 'Responsive Emission Site',
        'nodes' => array(
            $responsiveEmitFrame('frame:home-desktop', 'Home Desktop', 1440.0, 3000.0, array(
                $responsiveEmitCard('card:desktop', 'Hero Card', 1200.0, 400.0, '#ff0000'),
                $responsiveEmitCardRow('cards:desktop', 'Article cards', 760.0, 320.0, 'row'),
            )),
            $responsiveEmitFrame('frame:home-tablet', 'Home Tablet', 834.0, 3000.0, array(
                $responsiveEmitCard('card:tablet', 'Hero Card', 700.0, 400.0, '#ff0000'),
                $responsiveEmitCardRow('cards:tablet', 'Article cards', 700.0, 320.0, 'row'),
            )),
            $responsiveEmitFrame('frame:home-mobile', 'Home Mobile', 390.0, 3200.0, array(
                $responsiveEmitCard('card:mobile', 'Hero Card', 350.0, 500.0, '#00ff00'),
                $responsiveEmitCardRow('cards:mobile', 'Article cards', 350.0, 900.0, 'column'),
            )),
            $responsiveEmitFrame('frame:about', 'About', 1440.0, 2000.0, array(
                $responsiveEmitCard('card:about', 'About Card', 1100.0, 300.0, '#0000ff'),
            )),
        ),
    );
    $responsiveEmitPagePlan = array(
        'pages' => array(
            array(
                'frame_id'         => 'frame:home-desktop',
                'name'             => 'Home',
                'path'             => 'index.html',
                'entrypoint'       => true,
                'responsive'       => true,
                'breakpoint_count' => 3,
                'variants'         => array(
                    array('frame_id' => 'frame:home-desktop', 'device_hint' => 'desktop', 'viewport_width' => 1440.0, 'primary' => true, 'order' => 0),
                    array('frame_id' => 'frame:home-tablet', 'device_hint' => 'tablet', 'viewport_width' => 834.0, 'primary' => false, 'order' => 1),
                    array('frame_id' => 'frame:home-mobile', 'device_hint' => 'mobile', 'viewport_width' => 390.0, 'primary' => false, 'order' => 2),
                ),
            ),
            array(
                'frame_id'         => 'frame:about',
                'name'             => 'About',
                'path'             => 'about.html',
                'entrypoint'       => false,
                'responsive'       => false,
                'breakpoint_count' => 1,
                'variants'         => array(
                    array('frame_id' => 'frame:about', 'device_hint' => 'desktop', 'viewport_width' => 1440.0, 'primary' => true, 'order' => 0),
                ),
            ),
        ),
    );
    $responsiveEmitResult = ( new Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter() )->emitSite($responsiveEmitScenegraph, $responsiveEmitPagePlan);
    $responsiveEmitCss = '';
    foreach ( $responsiveEmitResult['files'] ?? array() as $responsiveEmitFile ) {
        if ( is_array($responsiveEmitFile) && 'style.css' === ($responsiveEmitFile['path'] ?? null) ) {
            $responsiveEmitCss = (string) ($responsiveEmitFile['content'] ?? '');
        }
    }
    $assert('success' === ($responsiveEmitResult['status'] ?? null), 'responsive-emit-status-success');
    $assert('' !== $responsiveEmitCss, 'responsive-emit-stylesheet-present');
    // Two narrower breakpoints plus wide desktop-only/generic safety fallbacks
    // produce at least four media blocks. Variant breakpoints are keyed at the MIDPOINT between
    // each variant and the primary source width (not the narrow variant's own width).
    // desktop=1440, tablet=834, mobile=390:
    //   tablet breakpoint = round((1440+834)/2) = 1137
    //   mobile breakpoint = round((1440+390)/2) = 915
    $assert(substr_count($responsiveEmitCss, '@media') >= 4, 'responsive-emit-two-variant-media-blocks-plus-desktop-fallback');
    $assert(str_contains($responsiveEmitCss, '@media (max-width:1137px){'), 'responsive-emit-tablet-media-query');
    $assert(str_contains($responsiveEmitCss, '@media (max-width:915px){'), 'responsive-emit-mobile-media-query');
    // Base layout uses the primary (desktop) variant styles, emitted before media.
    $responsiveEmitBasePos = strpos($responsiveEmitCss, '.figma-node-card-desktop-hero-card{width:1200px;height:400px;background:#ff0000}');
    $assert(false !== $responsiveEmitBasePos, 'responsive-emit-base-uses-primary-variant');
    $responsiveEmitFirstMediaPos = strpos($responsiveEmitCss, '@media');
    $assert(false !== $responsiveEmitFirstMediaPos && $responsiveEmitBasePos < $responsiveEmitFirstMediaPos, 'responsive-emit-base-precedes-media');
    // Narrower-wins cascade: tablet block precedes mobile block.
    $assert(strpos($responsiveEmitCss, '@media (max-width:1137px)') < strpos($responsiveEmitCss, '@media (max-width:915px)'), 'responsive-emit-cascade-widest-first');
    // Media blocks override on the BASE class names, carrying only changed props.
    $responsiveEmitTabletBlock = substr($responsiveEmitCss, strpos($responsiveEmitCss, '@media (max-width:1137px)'), strpos($responsiveEmitCss, '@media (max-width:915px)') - strpos($responsiveEmitCss, '@media (max-width:1137px)'));
    $assert(str_contains($responsiveEmitTabletBlock, '.figma-node-card-desktop-hero-card{width:calc(100% - 134px);max-width:1200px}'), 'responsive-emit-tablet-card-width-diff-only');
    $assert(! str_contains($responsiveEmitTabletBlock, 'background:'), 'responsive-emit-tablet-omits-unchanged-background');
    $responsiveEmitMobileBlock = substr($responsiveEmitCss, strpos($responsiveEmitCss, '@media (max-width:915px)'));
    $assert(str_contains($responsiveEmitMobileBlock, '.figma-node-frame-home-desktop-home-desktop{height:3200px}'), 'responsive-emit-mobile-root-keeps-fluid-width');
    $assert(str_contains($responsiveEmitMobileBlock, '.figma-node-card-desktop-hero-card{width:calc(100% - 40px);max-width:1200px;height:500px;background:#00ff00}'), 'responsive-emit-mobile-card-diffs-width-height-background');
    $assert(str_contains($responsiveEmitMobileBlock, '.figma-node-cards-desktop-article-cards{width:calc(100% - 40px);max-width:760px;margin-left:auto;margin-right:auto;height:auto;flex-direction:column}'), 'responsive-emit-mobile-row-container-fluid-auto-height-centered');
    $assert(str_contains($responsiveEmitMobileBlock, '.figma-node-cards-desktop-card-a-article-card{width:100%;height:auto}'), 'responsive-emit-mobile-card-frame-fluid-auto-height');
    $assert(str_contains($responsiveEmitMobileBlock, '.figma-node-cards-desktop-card-a-image-card-image{width:calc(100% - 32px);max-width:328px;left:16px;right:auto}'), 'responsive-emit-mobile-card-image-fluid-width');
    $assert(! preg_match('/\.figma-node-cards-desktop-card-a-image-card-image\{[^}]*height:auto/', $responsiveEmitMobileBlock), 'responsive-emit-mobile-leaf-image-keeps-fixed-height');
    $assert(! preg_match('/\.figma-node-cards-desktop-decor-absolute-decorative-rail\{[^}]*height:auto/', $responsiveEmitMobileBlock), 'responsive-emit-mobile-absolute-decoration-keeps-fixed-height');
    $assert(! str_contains($responsiveEmitMobileBlock, '.figma-node-frame-home-desktop-home-desktop{width:390px'), 'responsive-emit-mobile-root-does-not-pin-variant-width');
    // The single-variant About page contributes a non-structural fluid
    // containment layer plus the narrow-screen structural safety block.
    $assert(1 === preg_match('/@media \(max-width:1439px\)\{[\s\S]*figma-node-frame-about-about/s', $responsiveEmitCss), 'responsive-emit-single-variant-page-source-width-fluid-media');
    $assert(1 === preg_match('/@media \(max-width:767px\)\{[\s\S]*figma-node-frame-about-about/s', $responsiveEmitCss), 'responsive-emit-single-variant-page-desktop-fallback-media');

    $responsiveMismatchScenegraph = array(
        'name'  => 'Responsive Mismatched Mobile Site',
        'nodes' => array(
            $responsiveEmitFrame('mismatch:desktop', 'Mismatch Desktop', 1440.0, 1800.0, array(
                array(
                    'id'       => 'mismatch:desktop:shell',
                    'type'     => 'FRAME',
                    'name'     => 'Content shell',
                    'box'      => array('width' => 1180.0, 'height' => 420.0),
                    'layout'   => array('display' => 'flex', 'flex_direction' => 'row', 'item_spacing' => 24.0, 'padding' => array('top' => 48.0, 'right' => 48.0, 'bottom' => 48.0, 'left' => 48.0)),
                    'children' => array(
                        array('id' => 'mismatch:desktop:card-a', 'type' => 'FRAME', 'name' => 'Feature card A', 'box' => array('width' => 360.0, 'height' => 260.0), 'children' => array(
                            array('id' => 'mismatch:desktop:card-a-copy', 'type' => 'TEXT', 'name' => 'Card copy', 'characters' => 'Desktop card A', 'box' => array('width' => 260.0, 'height' => 24.0), 'fontSize' => 16),
                        )),
                        array('id' => 'mismatch:desktop:card-b', 'type' => 'FRAME', 'name' => 'Feature card B', 'box' => array('width' => 360.0, 'height' => 260.0), 'children' => array(
                            array('id' => 'mismatch:desktop:card-b-copy', 'type' => 'TEXT', 'name' => 'Card copy', 'characters' => 'Desktop card B', 'box' => array('width' => 260.0, 'height' => 24.0), 'fontSize' => 16),
                        )),
                    ),
                ),
                array(
                    'id'       => 'mismatch:desktop:absolute-card',
                    'type'     => 'FRAME',
                    'name'     => 'Floating promo card',
                    'box'      => array('x' => 112.0, 'y' => 520.0, 'width' => 980.0, 'height' => 240.0),
                    'layout'   => array('positioning' => 'absolute', 'display' => 'flex', 'flex_direction' => 'row'),
                    'children' => array(
                        array('id' => 'mismatch:desktop:absolute-inner', 'type' => 'FRAME', 'name' => 'Promo inner', 'box' => array('width' => 420.0, 'height' => 120.0)),
                    ),
                ),
            )),
            $responsiveEmitFrame('mismatch:mobile', 'Mismatch Mobile', 390.0, 2200.0, array(
                array(
                    'id'       => 'mismatch:mobile:renamed-stack',
                    'type'     => 'FRAME',
                    'name'     => 'Mobile stack',
                    'box'      => array('width' => 342.0, 'height' => 720.0),
                    'layout'   => array('display' => 'flex', 'flex_direction' => 'column', 'item_spacing' => 20.0, 'padding' => array('top' => 24.0, 'right' => 24.0, 'bottom' => 24.0, 'left' => 24.0)),
                    'children' => array(
                        array('id' => 'mismatch:mobile:renamed-card-one', 'type' => 'FRAME', 'name' => 'Mobile feature one', 'box' => array('width' => 342.0, 'height' => 300.0)),
                        array('id' => 'mismatch:mobile:renamed-card-two', 'type' => 'FRAME', 'name' => 'Mobile feature two', 'box' => array('width' => 342.0, 'height' => 300.0)),
                    ),
                ),
                array(
                    'id'       => 'mismatch:mobile:renamed-promo',
                    'type'     => 'FRAME',
                    'name'     => 'Mobile promo',
                    'box'      => array('x' => 24.0, 'y' => 780.0, 'width' => 342.0, 'height' => 280.0),
                    'layout'   => array('positioning' => 'absolute', 'display' => 'flex', 'flex_direction' => 'column'),
                    'children' => array(
                        array('id' => 'mismatch:mobile:promo-inner', 'type' => 'FRAME', 'name' => 'Promo inner mobile', 'box' => array('width' => 294.0, 'height' => 180.0)),
                    ),
                ),
            )),
        ),
    );
    $responsiveMismatchResult = ( new Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter() )->emitSite($responsiveMismatchScenegraph, array(
        'pages' => array(
            array(
                'frame_id'   => 'mismatch:desktop',
                'path'       => 'index.html',
                'entrypoint' => true,
                'responsive' => true,
                'variants'   => array(
                    array('frame_id' => 'mismatch:desktop', 'device_hint' => 'desktop', 'viewport_width' => 1440.0, 'primary' => true, 'order' => 0),
                    array('frame_id' => 'mismatch:mobile', 'device_hint' => 'mobile', 'viewport_width' => 390.0, 'primary' => false, 'order' => 1),
                ),
            ),
        ),
    ));
    $responsiveMismatchCss = '';
    foreach ( $responsiveMismatchResult['files'] ?? array() as $responsiveMismatchFile ) {
        if ( is_array($responsiveMismatchFile) && 'style.css' === ($responsiveMismatchFile['path'] ?? null) ) {
            $responsiveMismatchCss = (string) ($responsiveMismatchFile['content'] ?? '');
        }
    }
    $responsiveMismatchMobileBlock = substr($responsiveMismatchCss, strpos($responsiveMismatchCss, '@media'));
    $assert(str_contains($responsiveMismatchMobileBlock, '.figma-node-mismatch-desktop-shell-content-shell{width:calc(100% - 48px);max-width:342px;left:24px;right:auto;height:auto;flex-direction:column;align-items:stretch;flex-wrap:nowrap;padding-top:24px;padding-right:24px;padding-bottom:24px;padding-left:24px}'), 'responsive-emit-mobile-generic-mismatched-row-stacks-and-clamps-padding');
    $assert(preg_match('/\.figma-node-mismatch-desktop-card-a-feature-card-a\{[^}]*width:100%[^}]*max-width:100%/s', $responsiveMismatchMobileBlock) === 1, 'responsive-emit-mobile-generic-mismatched-fixed-card-fluidizes');
    $assert(str_contains($responsiveMismatchMobileBlock, '.figma-node-mismatch-desktop-absolute-card-floating-promo-card{width:calc(100% - 48px);max-width:342px;left:24px;right:auto;height:auto;'), 'responsive-emit-mobile-generic-mismatched-absolute-card-insets');

    $responsiveHeroGeometryScenegraph = array(
        'name'  => 'Responsive Hero Geometry Site',
        'nodes' => array(
            $responsiveEmitFrame('dr-geometry:desktop', 'Dr Aarti Desktop', 1440.0, 900.0, array(
                array(
                    'id'     => 'dr-geometry:desktop-headline',
                    'type'   => 'TEXT',
                    'name'   => 'Hero headline',
                    'box'    => array('x' => 165.0, 'y' => 176.0, 'width' => 563.0, 'height' => 168.0),
                    'layout' => array('positioning' => 'absolute', 'constraints' => array('horizontal' => 'CENTER')),
                    'characters' => 'Doctor-led skin treatments tailored to you',
                    'fontSize' => 56,
                ),
            )),
            $responsiveEmitFrame('dr-geometry:mobile', 'Dr Aarti Mobile', 390.0, 960.0, array(
                array(
                    'id'     => 'dr-geometry:mobile-headline',
                    'type'   => 'TEXT',
                    'name'   => 'Hero headline',
                    'box'    => array('x' => 24.0, 'y' => 144.0, 'width' => 342.0, 'height' => 132.0),
                    'layout' => array('positioning' => 'absolute'),
                    'characters' => 'Doctor-led skin treatments tailored to you',
                    'fontSize' => 38,
                ),
            )),
        ),
    );
    $responsiveHeroGeometryResult = ( new Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter() )->emitSite($responsiveHeroGeometryScenegraph, array(
        'pages' => array(
            array(
                'frame_id'   => 'dr-geometry:desktop',
                'path'       => 'index.html',
                'entrypoint' => true,
                'responsive' => true,
                'variants'   => array(
                    array('frame_id' => 'dr-geometry:desktop', 'device_hint' => 'desktop', 'viewport_width' => 1440.0, 'primary' => true),
                    array('frame_id' => 'dr-geometry:mobile', 'device_hint' => 'mobile', 'viewport_width' => 390.0, 'primary' => false),
                ),
            ),
        ),
    ));
    $responsiveHeroGeometryCss = '';
    foreach ( $responsiveHeroGeometryResult['files'] ?? array() as $responsiveHeroGeometryFile ) {
        if ( is_array($responsiveHeroGeometryFile) && 'style.css' === ($responsiveHeroGeometryFile['path'] ?? null) ) {
            $responsiveHeroGeometryCss = (string) ($responsiveHeroGeometryFile['content'] ?? '');
        }
    }
    $responsiveHeroGeometryBaseRule = blocks_engine_figma_transformer_contract_css_rule($responsiveHeroGeometryCss, '.figma-node-dr-geometry-desktop-headline-hero-headline');
    $responsiveHeroGeometryMobileBlock = substr($responsiveHeroGeometryCss, strpos($responsiveHeroGeometryCss, '@media'));
    $responsiveHeroGeometryMobileRule = blocks_engine_figma_transformer_contract_css_rule($responsiveHeroGeometryMobileBlock, '.figma-node-dr-geometry-desktop-headline-hero-headline');
    $assert(str_contains($responsiveHeroGeometryBaseRule, 'left:calc(50% - 555px)'), 'responsive-emit-hero-text-base-keeps-desktop-centered-canvas-left');
    $assert(str_contains($responsiveHeroGeometryMobileRule, 'width:calc(100% - 48px)') && str_contains($responsiveHeroGeometryMobileRule, 'left:24px'), 'responsive-emit-hero-text-mobile-matched-geometry-safe');
    $responsiveHeroGeometryComputedMobileX = str_contains($responsiveHeroGeometryMobileRule, 'left:24px') ? 24.0 : -360.0;
    $assert($responsiveHeroGeometryComputedMobileX >= 0.0, 'responsive-emit-hero-text-mobile-computed-x-non-negative');

    $responsiveHeroFallbackScenegraph = array(
        'name'  => 'Responsive Hero Fallback Site',
        'nodes' => array(
            $responsiveEmitFrame('hero-fallback:desktop', 'Hero Fallback Desktop', 1440.0, 900.0, array(
                array('id' => 'hero-fallback:desktop-headline', 'type' => 'TEXT', 'name' => 'Hero headline', 'box' => array('x' => 165.0, 'y' => 176.0, 'width' => 563.0, 'height' => 168.0), 'layout' => array('positioning' => 'absolute', 'constraints' => array('horizontal' => 'CENTER')), 'characters' => 'Desktop centered headline', 'fontSize' => 56),
            )),
            $responsiveEmitFrame('hero-fallback:mobile', 'Hero Fallback Mobile', 390.0, 960.0, array(
                array('id' => 'hero-fallback:mobile-card', 'type' => 'FRAME', 'name' => 'Hero card', 'box' => array('x' => 24.0, 'y' => 144.0, 'width' => 342.0, 'height' => 220.0), 'layout' => array('positioning' => 'absolute')),
            )),
        ),
    );
    $responsiveHeroFallbackResult = ( new Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter() )->emitSite($responsiveHeroFallbackScenegraph, array(
        'pages' => array(
            array(
                'frame_id'   => 'hero-fallback:desktop',
                'path'       => 'index.html',
                'entrypoint' => true,
                'responsive' => true,
                'variants'   => array(
                    array('frame_id' => 'hero-fallback:desktop', 'device_hint' => 'desktop', 'viewport_width' => 1440.0, 'primary' => true),
                    array('frame_id' => 'hero-fallback:mobile', 'device_hint' => 'mobile', 'viewport_width' => 390.0, 'primary' => false),
                ),
            ),
        ),
    ));
    $responsiveHeroFallbackCss = '';
    foreach ( $responsiveHeroFallbackResult['files'] ?? array() as $responsiveHeroFallbackFile ) {
        if ( is_array($responsiveHeroFallbackFile) && 'style.css' === ($responsiveHeroFallbackFile['path'] ?? null) ) {
            $responsiveHeroFallbackCss = (string) ($responsiveHeroFallbackFile['content'] ?? '');
        }
    }
    $responsiveHeroFallbackMobileBlock = substr($responsiveHeroFallbackCss, strpos($responsiveHeroFallbackCss, '@media'));
    $responsiveHeroFallbackMobileRule = blocks_engine_figma_transformer_contract_css_rule($responsiveHeroFallbackMobileBlock, '.figma-node-hero-fallback-desktop-headline-hero-headline');
    $assert(str_contains($responsiveHeroFallbackMobileRule, 'width:calc(100% - 48px)') && str_contains($responsiveHeroFallbackMobileRule, 'left:24px') && str_contains($responsiveHeroFallbackMobileRule, 'right:auto'), 'responsive-emit-hero-text-mobile-fallback-clamps-offcanvas-centered-left');

    $reflectedFullBleedScenegraph = array(
        'name'  => 'Reflected Full Bleed Geometry Site',
        'nodes' => array(
            $responsiveEmitFrame('reflected:root', 'Reflected Root', 1440.0, 900.0, array(
                array(
                    'id'        => 'reflected:hero-image',
                    'type'      => 'RECTANGLE',
                    'name'      => 'Hero image',
                    'box'       => array('x' => 1440.0, 'y' => 0.0, 'width' => 1440.0, 'height' => 620.0),
                    'figma_box' => array('transform' => array(array(-1.0, 0.0, 0.0), array(0.0, 1.0, 0.0))),
                    'layout'    => array('positioning' => 'absolute'),
                    'background' => '#d8e7ef',
                ),
            )),
        ),
    );
    $reflectedFullBleedResult = ( new Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter() )->emitSite($reflectedFullBleedScenegraph, array(
        'pages' => array(array('frame_id' => 'reflected:root', 'path' => 'index.html', 'entrypoint' => true)),
    ));
    $reflectedFullBleedCss = '';
    foreach ( $reflectedFullBleedResult['files'] ?? array() as $reflectedFullBleedFile ) {
        if ( is_array($reflectedFullBleedFile) && 'style.css' === ($reflectedFullBleedFile['path'] ?? null) ) {
            $reflectedFullBleedCss = (string) ($reflectedFullBleedFile['content'] ?? '');
        }
    }
    $reflectedFullBleedRule = blocks_engine_figma_transformer_contract_css_rule($reflectedFullBleedCss, '.figma-node-reflected-hero-image-hero-image');
    $assert(str_contains($reflectedFullBleedRule, 'left:0px') && str_contains($reflectedFullBleedRule, 'transform:matrix(-1,0,0,1,0,0)') && 1 === substr_count($reflectedFullBleedRule, 'left:'), 'responsive-emit-reflected-full-bleed-source-position-preserves-visual-viewport-x');

    $responsiveChromeScenegraph = array(
        'name'  => 'Responsive Top Chrome Site',
        'nodes' => array(
            $responsiveEmitFrame('chrome:desktop', 'Chrome Desktop', 1440.0, 900.0, array(
                array(
                    'id'       => 'chrome:desktop:header',
                    'type'     => 'FRAME',
                    'name'     => 'Header',
                    'box'      => array('width' => 1440.0, 'height' => 96.0),
                    'layout'   => array('display' => 'flex', 'flex_direction' => 'row', 'justify_content' => 'center', 'align_items' => 'center'),
                    'children' => array(
                        array(
                            'id'       => 'chrome:desktop:header-row',
                            'type'     => 'FRAME',
                            'name'     => 'Primary chrome row',
                            'box'      => array('width' => 1200.0, 'height' => 48.0),
                            'layout'   => array('display' => 'flex', 'flex_direction' => 'row', 'justify_content' => 'space-between', 'align_items' => 'center', 'item_spacing' => 32.0),
                            'children' => array(
                                array('id' => 'chrome:desktop:logo', 'type' => 'TEXT', 'name' => 'Brand logo', 'characters' => 'Dr Aarti', 'box' => array('width' => 140.0, 'height' => 24.0), 'fontSize' => 20),
                                array(
                                    'id'       => 'chrome:desktop:navigation',
                                    'type'     => 'FRAME',
                                    'name'     => 'Primary navigation',
                                    'box'      => array('width' => 420.0, 'height' => 24.0),
                                    'layout'   => array('display' => 'flex', 'flex_direction' => 'row', 'item_spacing' => 28.0),
                                    'children' => array(
                                        array('id' => 'chrome:desktop:nav-home', 'type' => 'TEXT', 'name' => 'Menu item', 'characters' => 'Home', 'box' => array('width' => 48.0, 'height' => 20.0), 'figma_link' => array('url' => '/')),
                                        array('id' => 'chrome:desktop:nav-services', 'type' => 'TEXT', 'name' => 'Menu item', 'characters' => 'Services', 'box' => array('width' => 72.0, 'height' => 20.0), 'figma_link' => array('url' => '/services')),
                                    ),
                                ),
                                array('id' => 'chrome:desktop:cta', 'type' => 'FRAME', 'name' => 'Book now CTA', 'box' => array('width' => 132.0, 'height' => 44.0), 'children' => array(
                                    array('id' => 'chrome:desktop:cta-label', 'type' => 'TEXT', 'name' => 'Button label', 'characters' => 'Book now', 'box' => array('width' => 72.0, 'height' => 20.0)),
                                )),
                            ),
                        ),
                    ),
                ),
                array('id' => 'chrome:desktop:hero', 'type' => 'FRAME', 'name' => 'Hero', 'box' => array('width' => 1440.0, 'height' => 600.0)),
            )),
            $responsiveEmitFrame('chrome:mobile', 'Chrome Mobile', 390.0, 1000.0, array(
                array('id' => 'chrome:mobile:header', 'type' => 'FRAME', 'name' => 'Mobile header', 'box' => array('width' => 390.0, 'height' => 156.0)),
                array('id' => 'chrome:mobile:hero', 'type' => 'FRAME', 'name' => 'Hero', 'box' => array('width' => 390.0, 'height' => 620.0)),
            )),
        ),
    );
    $responsiveChromeResult = ( new Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter() )->emitSite($responsiveChromeScenegraph, array(
        'pages' => array(
            array(
                'frame_id'   => 'chrome:desktop',
                'path'       => 'index.html',
                'entrypoint' => true,
                'responsive' => true,
                'variants'   => array(
                    array('frame_id' => 'chrome:desktop', 'device_hint' => 'desktop', 'viewport_width' => 1440.0, 'primary' => true, 'order' => 0),
                    array('frame_id' => 'chrome:mobile', 'device_hint' => 'mobile', 'viewport_width' => 390.0, 'primary' => false, 'order' => 1),
                ),
            ),
        ),
    ));
    $responsiveChromeCss = '';
    foreach ( $responsiveChromeResult['files'] ?? array() as $responsiveChromeFile ) {
        if ( is_array($responsiveChromeFile) && 'style.css' === ($responsiveChromeFile['path'] ?? null) ) {
            $responsiveChromeCss = (string) ($responsiveChromeFile['content'] ?? '');
        }
    }
    $responsiveChromeMobileBlock = substr($responsiveChromeCss, strpos($responsiveChromeCss, '@media'));
    $assert(str_contains($responsiveChromeMobileBlock, '.figma-node-chrome-desktop-header-header{max-width:100%;height:auto;flex-direction:column;align-items:stretch;justify-content:flex-start;min-height:156px}'), 'responsive-emit-mobile-top-chrome-header-keeps-source-height-floor');
    $assert(preg_match('/\.figma-node-chrome-desktop-header-row-primary-chrome-row\{[^}]*height:auto[^}]*position:relative[^}]*left:auto[^}]*right:auto[^}]*top:auto[^}]*justify-content:flex-start[^}]*flex-wrap:wrap[^}]*gap:16px[^}]*padding-top:24px[^}]*padding-right:24px[^}]*padding-bottom:24px[^}]*padding-left:24px/s', $responsiveChromeMobileBlock) === 1, 'responsive-emit-mobile-top-chrome-inner-row-wraps-with-normal-gutters');
    $assert(str_contains($responsiveChromeMobileBlock, '.figma-node-chrome-desktop-navigation-primary-navigation{width:100%;max-width:100%;height:auto;justify-content:flex-start;flex-wrap:wrap;gap:16px}'), 'responsive-emit-mobile-top-chrome-navigation-wraps');
    $assert(! str_contains($responsiveChromeMobileBlock, 'padding-top:72px'), 'responsive-emit-mobile-top-chrome-no-instance-specific-header-offset');

    $responsiveIdentityScenegraph = array(
        'name'  => 'Responsive Source Identity Site',
        'nodes' => array(
            $responsiveEmitFrame('identity:desktop', 'Identity Desktop', 1440.0, 300.0, array(
                array('id' => 'identity:group:desktop', 'type' => 'FRAME', 'name' => 'Logo parts', 'box' => array('width' => 200.0, 'height' => 80.0), 'children' => array(
                    array('id' => 'identity:a:desktop', 'type' => 'RECTANGLE', 'name' => 'Part A', 'source_id' => 'component:part-a', 'box' => array('width' => 96.0, 'height' => 96.0), 'background' => '#ffcf00'),
                    array('id' => 'identity:b:desktop', 'type' => 'RECTANGLE', 'name' => 'Part B', 'source_id' => 'component:part-b', 'box' => array('width' => 32.0, 'height' => 12.0), 'background' => '#1f1f1f'),
                )),
            )),
            $responsiveEmitFrame('identity:mobile', 'Identity Mobile', 390.0, 300.0, array(
                array('id' => 'identity:group:mobile', 'type' => 'FRAME', 'name' => 'Logo parts', 'box' => array('width' => 72.0, 'height' => 72.0), 'children' => array(
                    array('id' => 'identity:b:mobile', 'type' => 'RECTANGLE', 'name' => 'Part B', 'source_id' => 'component:part-b', 'box' => array('width' => 19.0, 'height' => 2.0), 'background' => '#1f1f1f'),
                    array('id' => 'identity:a:mobile', 'type' => 'RECTANGLE', 'name' => 'Part A', 'source_id' => 'component:part-a', 'box' => array('width' => 72.0, 'height' => 72.0), 'background' => '#ffcf00'),
                )),
            )),
        ),
    );
    $responsiveIdentityResult = ( new Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter() )->emitSite($responsiveIdentityScenegraph, array(
        'pages' => array(
            array(
                'frame_id'   => 'identity:desktop',
                'path'       => 'index.html',
                'entrypoint' => true,
                'responsive' => true,
                'variants'   => array(
                    array('frame_id' => 'identity:desktop', 'device_hint' => 'desktop', 'viewport_width' => 1440.0, 'primary' => true),
                    array('frame_id' => 'identity:mobile', 'device_hint' => 'mobile', 'viewport_width' => 390.0, 'primary' => false),
                ),
            ),
        ),
    ));
    $responsiveIdentityCss = '';
    foreach ( $responsiveIdentityResult['files'] ?? array() as $responsiveIdentityFile ) {
        if ( is_array($responsiveIdentityFile) && 'style.css' === ($responsiveIdentityFile['path'] ?? null) ) {
            $responsiveIdentityCss = (string) ($responsiveIdentityFile['content'] ?? '');
        }
    }
    $assert(str_contains($responsiveIdentityCss, '.figma-node-identity-a-desktop-part-a{width:72px;height:72px}'), 'responsive-emit-source-identity-keeps-reordered-part-a-geometry');
    $assert(str_contains($responsiveIdentityCss, '.figma-node-identity-b-desktop-part-b{width:19px;height:2px}'), 'responsive-emit-source-identity-keeps-reordered-part-b-geometry');
    $assert(! str_contains($responsiveIdentityCss, '.figma-node-identity-a-desktop-part-a{width:19px;height:2px}'), 'responsive-emit-source-identity-avoids-ordinal-part-a-mismatch');
    $assert(! str_contains($responsiveIdentityCss, '.figma-node-identity-b-desktop-part-b{width:72px;height:72px}'), 'responsive-emit-source-identity-avoids-ordinal-part-b-mismatch');

    $responsiveDuplicateIdentityScenegraph = array(
        'name'  => 'Responsive Duplicate Source Identity Site',
        'nodes' => array(
            $responsiveEmitFrame('duplicate-identity:desktop', 'Duplicate Identity Desktop', 1440.0, 420.0, array(
                array('id' => 'duplicate-identity:group:desktop', 'type' => 'FRAME', 'name' => 'Repeated cards', 'box' => array('width' => 960.0, 'height' => 240.0), 'layout' => array('display' => 'flex', 'flex_direction' => 'row'), 'children' => array(
                    array('id' => 'duplicate-identity:a:desktop', 'type' => 'FRAME', 'name' => 'Card', 'source_id' => 'component:article-a', 'box' => array('width' => 460.0, 'height' => 220.0)),
                    array('id' => 'duplicate-identity:b:desktop', 'type' => 'FRAME', 'name' => 'Card', 'source_id' => 'component:article-b', 'box' => array('width' => 220.0, 'height' => 120.0)),
                )),
            )),
            $responsiveEmitFrame('duplicate-identity:mobile', 'Duplicate Identity Mobile', 390.0, 620.0, array(
                array('id' => 'duplicate-identity:group:mobile', 'type' => 'FRAME', 'name' => 'Repeated cards', 'box' => array('width' => 342.0, 'height' => 500.0), 'layout' => array('display' => 'flex', 'flex_direction' => 'column'), 'children' => array(
                    array('id' => 'duplicate-identity:b:mobile', 'type' => 'FRAME', 'name' => 'Card', 'source_id' => 'component:article-b', 'box' => array('width' => 198.0, 'height' => 88.0)),
                    array('id' => 'duplicate-identity:a:mobile', 'type' => 'FRAME', 'name' => 'Card', 'source_id' => 'component:article-a', 'box' => array('width' => 342.0, 'height' => 300.0)),
                )),
            )),
        ),
    );
    $responsiveDuplicateIdentityResult = ( new Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter() )->emitSite($responsiveDuplicateIdentityScenegraph, array(
        'pages' => array(
            array(
                'frame_id'   => 'duplicate-identity:desktop',
                'path'       => 'index.html',
                'entrypoint' => true,
                'responsive' => true,
                'variants'   => array(
                    array('frame_id' => 'duplicate-identity:desktop', 'device_hint' => 'desktop', 'viewport_width' => 1440.0, 'primary' => true),
                    array('frame_id' => 'duplicate-identity:mobile', 'device_hint' => 'mobile', 'viewport_width' => 390.0, 'primary' => false),
                ),
            ),
        ),
    ));
    $responsiveDuplicateIdentityCss = '';
    foreach ( $responsiveDuplicateIdentityResult['files'] ?? array() as $responsiveDuplicateIdentityFile ) {
        if ( is_array($responsiveDuplicateIdentityFile) && 'style.css' === ($responsiveDuplicateIdentityFile['path'] ?? null) ) {
            $responsiveDuplicateIdentityCss = (string) ($responsiveDuplicateIdentityFile['content'] ?? '');
        }
    }
    $assert(preg_match('/\.figma-node-duplicate-identity-a-desktop-card\{[^}]*width:100%/s', $responsiveDuplicateIdentityCss) === 1, 'responsive-emit-duplicate-source-identity-keeps-card-a-mobile-width');
    $assert(str_contains($responsiveDuplicateIdentityCss, '.figma-node-duplicate-identity-b-desktop-card{width:calc(100% - 144px);max-width:220px;height:88px}'), 'responsive-emit-duplicate-source-identity-keeps-card-b-mobile-width');
    $assert(! str_contains($responsiveDuplicateIdentityCss, '.figma-node-duplicate-identity-a-desktop-card{width:198px;height:88px}'), 'responsive-emit-duplicate-source-identity-avoids-ordinal-card-a-mispatch');
    $assert(! str_contains($responsiveDuplicateIdentityCss, '.figma-node-duplicate-identity-b-desktop-card{width:100%;height:auto}'), 'responsive-emit-duplicate-source-identity-avoids-ordinal-card-b-mispatch');

    $responsiveCenteredGridScenegraph = array(
        'name'  => 'Responsive Centered Grid Safety Site',
        'nodes' => array(
            array('id' => 'centered-grid:desktop', 'type' => 'FRAME', 'name' => 'Centered Grid Desktop', 'box' => array('width' => 1440.0, 'height' => 900.0), 'layout' => array('display' => 'flex', 'flex_direction' => 'column'), 'children' => array(
                array('id' => 'centered-grid:shell:desktop', 'type' => 'FRAME', 'name' => 'Cards grid', 'box' => array('x' => 130.0, 'width' => 1180.0, 'height' => 360.0), 'layout' => array('display' => 'grid'), 'children' => array(
                    array('id' => 'centered-grid:card-a:desktop', 'type' => 'FRAME', 'name' => 'Card', 'box' => array('width' => 360.0, 'height' => 280.0)),
                    array('id' => 'centered-grid:card-b:desktop', 'type' => 'FRAME', 'name' => 'Card', 'box' => array('width' => 360.0, 'height' => 280.0)),
                )),
            )),
            array('id' => 'centered-grid:mobile', 'type' => 'FRAME', 'name' => 'Centered Grid Mobile', 'box' => array('width' => 390.0, 'height' => 980.0), 'layout' => array('display' => 'flex', 'flex_direction' => 'column'), 'children' => array()),
        ),
    );
    $responsiveCenteredGridResult = ( new Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter() )->emitSite($responsiveCenteredGridScenegraph, array(
        'pages' => array(
            array(
                'frame_id'   => 'centered-grid:desktop',
                'path'       => 'index.html',
                'entrypoint' => true,
                'responsive' => true,
                'variants'   => array(
                    array('frame_id' => 'centered-grid:desktop', 'device_hint' => 'desktop', 'viewport_width' => 1440.0, 'primary' => true),
                    array('frame_id' => 'centered-grid:mobile', 'device_hint' => 'mobile', 'viewport_width' => 390.0, 'primary' => false),
                ),
            ),
        ),
    ));
    $responsiveCenteredGridCss = '';
    foreach ( $responsiveCenteredGridResult['files'] ?? array() as $responsiveCenteredGridFile ) {
        if ( is_array($responsiveCenteredGridFile) && 'style.css' === ($responsiveCenteredGridFile['path'] ?? null) ) {
            $responsiveCenteredGridCss = (string) ($responsiveCenteredGridFile['content'] ?? '');
        }
    }
    $assert(preg_match('/\.figma-node-centered-grid-shell-desktop-cards-grid\{[^}]*margin-left:auto[^}]*margin-right:auto/s', $responsiveCenteredGridCss) === 1, 'responsive-emit-mobile-centered-grid-shell-base-centered');
    $assert(preg_match('/@media \(max-width:390px\)\{[\s\S]*\.figma-node-centered-grid-shell-desktop-cards-grid\{[^}]*max-width:100%[^}]*grid-template-columns:1fr/s', $responsiveCenteredGridCss) === 1, 'responsive-emit-mobile-centered-grid-shell-keeps-centered-fluid-role');

    // SINGLE-VARIANT PAGE RESPONSIVENESS: a page plan with only a primary wide
    // desktop frame gets a conservative mobile fallback media query rather than
    // shipping a fixed-width canvas with no responsive layer.
    $singleVariantPagePlan = array(
        'pages' => array(
            array(
                'frame_id'         => 'frame:about',
                'name'             => 'About',
                'path'             => 'index.html',
                'entrypoint'       => true,
                'responsive'       => false,
                'breakpoint_count' => 1,
                'variants'         => array(
                    array('frame_id' => 'frame:about', 'device_hint' => 'desktop', 'viewport_width' => 1440.0, 'primary' => true, 'order' => 0),
                ),
            ),
        ),
    );
    $singleVariantResult = ( new Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter() )->emitSite($responsiveEmitScenegraph, $singleVariantPagePlan);
    $singleVariantCss = '';
    foreach ( $singleVariantResult['files'] ?? array() as $singleVariantFile ) {
        if ( is_array($singleVariantFile) && 'style.css' === ($singleVariantFile['path'] ?? null) ) {
            $singleVariantCss = (string) ($singleVariantFile['content'] ?? '');
        }
    }
    $assert('' !== $singleVariantCss, 'responsive-emit-single-variant-stylesheet-present');
    $assert(str_contains($singleVariantCss, '@media (max-width:767px)'), 'responsive-emit-single-variant-mobile-fallback-media-query');
    $assert(preg_match('/@media \(max-width:767px\)\{[\s\S]*\.figma-node-frame-about-about\{[^}]*max-width:100%/s', $singleVariantCss) === 1, 'responsive-emit-single-variant-root-fallback-width');
    
    $planDiagnosticByCode = static function (array $plan, string $code): ?array {
        foreach ( $plan['diagnostics'] ?? array() as $diagnostic ) {
            if ( is_array($diagnostic) && $code === ($diagnostic['code'] ?? null) ) {
                return $diagnostic;
            }
        }
    
        return null;
    };
    
    // (b) A genuine desktop/tablet/mobile group STILL collapses AND records its
    // grouping rationale (distinct device hints).
    $responsiveGroupDiagnostic = $planDiagnosticByCode($responsivePagePlan, 'responsive_group_formed');
    $assert(null !== $responsiveGroupDiagnostic, 'page-plan-responsive-group-rationale-emitted');
    $assert(in_array('device_hint_diversity', $responsiveGroupDiagnostic['reasons'] ?? array(), true), 'page-plan-responsive-group-rationale-device-diversity');
    $assert('frame:home-desktop' === ($responsiveGroupDiagnostic['primary_frame_id'] ?? null), 'page-plan-responsive-group-rationale-primary');
    
    // (a) FALSE-POSITIVE GUARD: same-name, same-device-hint (desktop),
    // same-size frames are duplicate/iteration drafts, NOT responsive
    // breakpoints. Site generation emits only the canonical route and surfaces
    // a duplicate_draft_frames diagnostic for the rejected draft.
    $duplicateDraftSource = array(
        'nodes' => array(
            array(
                'id'       => 'page:hosts',
                'type'     => 'CANVAS',
                'name'     => 'Hosts Pages',
                'children' => array(
                    array(
                        'id'       => 'section:hosts',
                        'type'     => 'SECTION',
                        'name'     => 'Marketing',
                        'width'    => 4000,
                        'height'   => 40000,
                        'children' => array(
                            array(
                                'id'       => 'frame:hosts-a',
                                'type'     => 'FRAME',
                                'name'     => 'For Hosts',
                                'width'    => 1440,
                                'height'   => 9777,
                                'children' => array(array('id' => 'text:hosts-a', 'type' => 'TEXT', 'name' => 'Headline', 'characters' => 'For Hosts')),
                            ),
                            array(
                                'id'       => 'frame:hosts-b',
                                'type'     => 'FRAME',
                                'name'     => 'For Hosts',
                                'width'    => 1440,
                                'height'   => 8613,
                                'children' => array(array('id' => 'text:hosts-b', 'type' => 'TEXT', 'name' => 'Headline', 'characters' => 'For Hosts')),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    );
    $duplicateDraftPlan = ( new ScenegraphPagePlanner() )->plan($duplicateDraftSource, array('include_all_pages' => true));
    $assert(1 === ($duplicateDraftPlan['page_count'] ?? null), 'page-plan-duplicate-drafts-emit-canonical-page-only');
    $duplicateDraftResponsive = false;
    foreach ( $duplicateDraftPlan['pages'] ?? array() as $duplicatePage ) {
        if ( is_array($duplicatePage) && true === ($duplicatePage['responsive'] ?? null) ) {
            $duplicateDraftResponsive = true;
        }
    }
    $assert(false === $duplicateDraftResponsive, 'page-plan-duplicate-drafts-not-responsive');
    $duplicateDraftDiagnostic = $planDiagnosticByCode($duplicateDraftPlan, 'duplicate_draft_frames');
    $assert(null !== $duplicateDraftDiagnostic, 'page-plan-duplicate-drafts-diagnostic-emitted');
    $assert('desktop' === ($duplicateDraftDiagnostic['device_hint'] ?? null), 'page-plan-duplicate-drafts-diagnostic-device-hint');
    $assert(2 === count($duplicateDraftDiagnostic['frame_ids'] ?? array()), 'page-plan-duplicate-drafts-diagnostic-frame-count');
    $assert(array('frame:hosts-b') === ($duplicateDraftDiagnostic['draft_frame_ids'] ?? null), 'page-plan-duplicate-drafts-diagnostic-draft-ids');
    $assert(null === $planDiagnosticByCode($duplicateDraftPlan, 'responsive_group_formed'), 'page-plan-duplicate-drafts-not-grouped');

    $utilityFrameSource = array(
        'nodes' => array(
            array(
                'id'       => 'page:site',
                'type'     => 'CANVAS',
                'name'     => 'Site',
                'children' => array(
                    array(
                        'id'       => 'section:web',
                        'type'     => 'SECTION',
                        'name'     => 'Responsive Pages',
                        'children' => array(
                            array(
                                'id'       => 'frame:home-desktop',
                                'type'     => 'FRAME',
                                'name'     => 'Home',
                                'width'    => 1440,
                                'height'   => 1800,
                                'children' => array(array('id' => 'text:home-desktop', 'type' => 'TEXT', 'name' => 'Home', 'characters' => 'Home')),
                            ),
                            array(
                                'id'       => 'frame:home-mobile',
                                'type'     => 'FRAME',
                                'name'     => 'Home',
                                'width'    => 390,
                                'height'   => 1800,
                                'children' => array(array('id' => 'text:home-mobile', 'type' => 'TEXT', 'name' => 'Home', 'characters' => 'Home')),
                            ),
                            array(
                                'id'       => 'frame:desktop-mockup',
                                'type'     => 'FRAME',
                                'name'     => 'Desktop - 1440px',
                                'width'    => 1440,
                                'height'   => 1400,
                                'children' => array(array('id' => 'text:desktop-mockup', 'type' => 'TEXT', 'name' => 'Label', 'characters' => 'Desktop')),
                            ),
                            array(
                                'id'       => 'frame:mobile-mockup',
                                'type'     => 'FRAME',
                                'name'     => 'Mobile - 375px',
                                'width'    => 375,
                                'height'   => 812,
                                'children' => array(array('id' => 'text:mobile-mockup', 'type' => 'TEXT', 'name' => 'Label', 'characters' => 'Mobile')),
                            ),
                            array(
                                'id'       => 'frame:presentation-cover',
                                'type'     => 'FRAME',
                                'name'     => 'Presentation Cover',
                                'width'    => 1440,
                                'height'   => 1024,
                                'children' => array(array('id' => 'text:presentation-cover', 'type' => 'TEXT', 'name' => 'Label', 'characters' => 'Cover')),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    );
    $utilityFramePlan = ( new ScenegraphPagePlanner() )->plan($utilityFrameSource, array('include_all_pages' => true));
    $assert(1 === ($utilityFramePlan['page_count'] ?? null), 'page-plan-utility-route-frames-filtered');
    $assert('frame:home-desktop' === ($utilityFramePlan['pages'][0]['frame_id'] ?? null), 'page-plan-utility-route-keeps-responsive-page');
    $utilityFilterDiagnostic = $planDiagnosticByCode($utilityFramePlan, 'low_confidence_route_frame_filtered');
    $assert(null !== $utilityFilterDiagnostic, 'page-plan-utility-route-filter-diagnostic-emitted');
    $utilityFilteredReasons = array_map(
        static fn (array $evidence): string => (string) ($evidence['reason'] ?? ''),
        is_array($utilityFramePlan['source_frame_evidence']['filtered_candidates'] ?? null) ? $utilityFramePlan['source_frame_evidence']['filtered_candidates'] : array()
    );
    $assert(in_array('low_confidence_route_frame', $utilityFilteredReasons, true), 'page-plan-source-frame-evidence-filtered-utility-route');

    $frontPageUtilityNameSource = array(
        'name'  => 'Landing Utility Name Site',
        'nodes' => array(
            array(
                'id'       => 'canvas:landing-utility',
                'type'     => 'CANVAS',
                'name'     => 'Pages',
                'children' => array(
                    array(
                        'id'       => 'frame:landing-desktop',
                        'type'     => 'FRAME',
                        'name'     => 'Landing page V1 / desktop / 1920px',
                        'width'    => 1920,
                        'height'   => 3200,
                        'children' => array(array('id' => 'text:landing-desktop', 'type' => 'TEXT', 'name' => 'Hero', 'characters' => 'Landing page hero')),
                    ),
                    array(
                        'id'       => 'frame:landing-tablet',
                        'type'     => 'FRAME',
                        'name'     => 'Landing page V1 / Tablet / 768px',
                        'width'    => 768,
                        'height'   => 3400,
                        'children' => array(array('id' => 'text:landing-tablet', 'type' => 'TEXT', 'name' => 'Hero', 'characters' => 'Landing page hero')),
                    ),
                    array(
                        'id'       => 'frame:desktop-mockup-only',
                        'type'     => 'FRAME',
                        'name'     => 'Desktop - 1440px',
                        'width'    => 1440,
                        'height'   => 1400,
                        'children' => array(array('id' => 'text:desktop-mockup-only', 'type' => 'TEXT', 'name' => 'Label', 'characters' => 'Desktop mockup')),
                    ),
                ),
            ),
        ),
    );
    $frontPageUtilityNamePlan = ( new ScenegraphPagePlanner() )->plan($frontPageUtilityNameSource, array('include_all_pages' => true));
    $frontPageUtilityNameFrameIds = array_map(
        static fn (array $page): string => (string) ($page['frame_id'] ?? ''),
        is_array($frontPageUtilityNamePlan['pages'] ?? null) ? $frontPageUtilityNamePlan['pages'] : array()
    );
    $assert(in_array('frame:landing-desktop', $frontPageUtilityNameFrameIds, true), 'page-plan-utility-route-keeps-front-page-device-name');
    $assert(! in_array('frame:desktop-mockup-only', $frontPageUtilityNameFrameIds, true), 'page-plan-utility-route-still-filters-non-front-page-device-name');

    $reservedEntrypointPathSource = array(
        'nodes' => array(
            array(
                'id'       => 'canvas:reserved-entrypoint',
                'type'     => 'CANVAS',
                'name'     => 'Pages',
                'children' => array(
                    array(
                        'id'       => 'frame:index',
                        'type'     => 'FRAME',
                        'name'     => 'Index',
                        'width'    => 1440,
                        'height'   => 1800,
                        'children' => array(array('id' => 'text:index', 'type' => 'TEXT', 'name' => 'Index', 'characters' => 'Article index')),
                    ),
                    array(
                        'id'       => 'frame:home',
                        'type'     => 'FRAME',
                        'name'     => 'Home Page',
                        'width'    => 1440,
                        'height'   => 1800,
                        'children' => array(array('id' => 'text:home', 'type' => 'TEXT', 'name' => 'Home', 'characters' => 'Home')),
                    ),
                ),
            ),
        ),
    );
    $reservedEntrypointPathPlan = ( new ScenegraphPagePlanner() )->plan($reservedEntrypointPathSource, array(
        'frame_ids' => array('frame:index', 'frame:home'),
        'entry_frame_id' => 'frame:home',
    ));
    $reservedEntrypointPaths = array_column($reservedEntrypointPathPlan['pages'] ?? array(), 'path', 'frame_id');
    $reservedEntrypointSlugs = array_column($reservedEntrypointPathPlan['pages'] ?? array(), 'slug', 'frame_id');
    $assert('index.html' === ($reservedEntrypointPaths['frame:home'] ?? null), 'page-plan-reserves-index-path-for-entrypoint');
    $assert('index-2.html' === ($reservedEntrypointPaths['frame:index'] ?? null), 'page-plan-deduplicates-index-frame-output-path');
    $assert('index' === ($reservedEntrypointSlugs['frame:index'] ?? null), 'page-plan-path-deduplication-preserves-route-identity');
    $assert(count($reservedEntrypointPaths) === count(array_unique($reservedEntrypointPaths)), 'page-plan-output-paths-are-unique');

    // (c) FRAME-CANDIDATE BOUND: detection now scales with the number of FRAME
    // candidates, not total node count. The `responsive_detection_bounded`
    // diagnostic only fires in pathological cases (here forced via a frame-limit of
    // 1 against 4 frames). When bounded, detection is skipped — no second index is
    // built — and frames fall back to one-page-per-frame.
    $boundedPlan = ( new ScenegraphPagePlanner() )->plan(
        $responsivePagePlanSource,
        array('include_all_pages' => true, 'responsive_detection_frame_limit' => 1)
    );
    $boundedDiagnostic = $planDiagnosticByCode($boundedPlan, 'responsive_detection_bounded');
    $assert(null !== $boundedDiagnostic, 'page-plan-bounded-detection-diagnostic-emitted');
    $assert(1 === ($boundedDiagnostic['frame_candidate_limit'] ?? null), 'page-plan-bounded-detection-frame-limit');
    $assert(4 === ($boundedDiagnostic['frame_candidate_count'] ?? null), 'page-plan-bounded-detection-frame-count');
    $assert(4 === ($boundedPlan['page_count'] ?? null), 'page-plan-bounded-detection-one-page-per-frame');
    $boundedHomePage = $responsivePageByFrame($boundedPlan, 'frame:home-desktop');
    $assert(null !== $boundedHomePage && false === ($boundedHomePage['responsive'] ?? null), 'page-plan-bounded-detection-no-collapse');
    
    // (c2) SCALE: a design whose descendant node count is WELL ABOVE the old 25k
    // ceiling — but with only a handful of FRAME candidates — must STILL run
    // detection and form a genuine desktop/tablet/mobile responsive group, with NO
    // `responsive_detection_bounded` skip. This proves grouping stays ON at
    // "Automattic scale" and that detection reads frame-level data, not all nodes.
    $largeDescendantCount = 26000; // > the retired RESPONSIVE_DETECTION_NODE_LIMIT of 25000.
    $bulkChildren = static function (string $prefix, int $count): array {
        $children = array();
        for ( $i = 0; $i < $count; $i++ ) {
            $children[] = array(
                'id'         => $prefix . ':text:' . $i,
                'type'       => 'TEXT',
                'name'       => 'Body ' . $i,
                'characters' => 'Lorem ipsum dolor sit amet number ' . $i,
            );
        }
    
        return $children;
    };
    $scaleSource = array(
        'nodes' => array(
            array(
                'id'       => 'page:scale',
                'type'     => 'CANVAS',
                'name'     => 'Scale Pages',
                'children' => array(
                    array(
                        'id'       => 'section:scale',
                        'type'     => 'SECTION',
                        'name'     => 'Marketing',
                        'width'    => 4000,
                        'height'   => 40000,
                        'children' => array(
                            array(
                                'id'       => 'frame:landing-desktop',
                                'type'     => 'FRAME',
                                'name'     => 'Landing Page Desktop',
                                'width'    => 1440,
                                'height'   => 9000,
                                // This single frame alone carries more descendants
                                // than the entire old 25k node ceiling.
                                'children' => $bulkChildren('desktop', $largeDescendantCount),
                            ),
                            array(
                                'id'       => 'frame:landing-tablet',
                                'type'     => 'FRAME',
                                'name'     => 'Landing Page Tablet',
                                'width'    => 834,
                                'height'   => 9000,
                                'children' => array(array('id' => 'tablet:text', 'type' => 'TEXT', 'name' => 'Body', 'characters' => 'Lorem')),
                            ),
                            array(
                                'id'       => 'frame:landing-mobile',
                                'type'     => 'FRAME',
                                'name'     => 'Landing Page Mobile',
                                'width'    => 390,
                                'height'   => 9000,
                                'children' => array(array('id' => 'mobile:text', 'type' => 'TEXT', 'name' => 'Body', 'characters' => 'Lorem')),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    );
    $scalePlan = ( new ScenegraphPagePlanner() )->plan($scaleSource, array('include_all_pages' => true));
    $assert(null === $planDiagnosticByCode($scalePlan, 'responsive_detection_bounded'), 'page-plan-scale-detection-not-bounded');
    $assert(3 === ($scalePlan['candidate_count'] ?? null), 'page-plan-scale-three-frame-candidates');
    $assert(1 === ($scalePlan['page_count'] ?? null), 'page-plan-scale-collapses-to-one-page');
    $scaleHomePage = $responsivePageByFrame($scalePlan, 'frame:landing-desktop');
    $assert(null !== $scaleHomePage, 'page-plan-scale-primary-is-desktop');
    $assert(true === ($scaleHomePage['responsive'] ?? null), 'page-plan-scale-flagged-responsive');
    $assert(3 === ($scaleHomePage['breakpoint_count'] ?? null), 'page-plan-scale-three-breakpoints');
    // The primary frame's own subtree exceeds the retired node ceiling, proving
    // grouping is active at a scale that previously auto-disabled it.
    $assert(($scaleHomePage['node_count'] ?? 0) > 25000, 'page-plan-scale-primary-above-old-ceiling');
    $scaleGroupDiagnostic = $planDiagnosticByCode($scalePlan, 'responsive_group_formed');
    $assert(null !== $scaleGroupDiagnostic, 'page-plan-scale-group-formed');
    $assert(in_array('device_hint_diversity', $scaleGroupDiagnostic['reasons'] ?? array(), true), 'page-plan-scale-group-device-diversity');
    $assert(array('desktop', 'tablet', 'mobile')
        === array_map(static fn (array $variant): string => (string) ($variant['device_hint'] ?? ''), $scaleHomePage['variants'] ?? array()), 'page-plan-scale-variant-device-hints');
    
    // (c3) DETECTION IS FRAME-LEVEL: the lightweight detection path produces the
    // full detection contract (device_hint / sibling_group_key /
    // responsive_siblings) from frame-level records ALONE — no source, no
    // ScenegraphIndex. This is the memory-efficient primitive the planner reuses.
    $frameLevelDetection = ( new Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ScenegraphFrameInspector() )->detectResponsiveFrames(array(
        array('id' => 'f:desktop', 'name' => 'Pricing Desktop', 'width' => 1440.0, 'height' => 3200.0, 'page_id' => 'p:1', 'section_id' => 's:1', 'parent_id' => 's:1'),
        array('id' => 'f:tablet', 'name' => 'Pricing Tablet', 'width' => 834.0, 'height' => 3200.0, 'page_id' => 'p:1', 'section_id' => 's:1', 'parent_id' => 's:1'),
        array('id' => 'f:mobile', 'name' => 'Pricing Mobile', 'width' => 390.0, 'height' => 3200.0, 'page_id' => 'p:1', 'section_id' => 's:1', 'parent_id' => 's:1'),
    ));
    $assert('desktop' === ($frameLevelDetection['f:desktop']['device_hint'] ?? null), 'frame-level-detection-desktop-hint');
    $assert('mobile' === ($frameLevelDetection['f:mobile']['device_hint'] ?? null), 'frame-level-detection-mobile-hint');
    $assert(isset($frameLevelDetection['f:desktop']['sibling_group_key']), 'frame-level-detection-sibling-group-key');
    $frameLevelSiblingIds = array_map(
        static fn (array $sibling): string => (string) ($sibling['id'] ?? ''),
        $frameLevelDetection['f:desktop']['responsive_siblings'] ?? array()
    );
    $assert(in_array('f:tablet', $frameLevelSiblingIds, true) && in_array('f:mobile', $frameLevelSiblingIds, true), 'frame-level-detection-links-siblings');
    
    $matrixWebsiteCandidate = array(
        'id'         => 'matrix:site:home',
        'type'       => 'FRAME',
        'name'       => 'Homepage',
        'page'       => array('name' => 'Website Pages'),
        'parent'     => array('type' => 'CANVAS'),
        'width'      => 1440,
        'height'     => 4200,
        'text_count' => 24,
        'score'      => 700,
    );
    $matrixResearchCandidate = array(
        'id'         => 'matrix:research:screen',
        'type'       => 'FRAME',
        'name'       => 'Desktop - 12',
        'page'       => array('name' => 'Research/Screens'),
        'parent'     => array('type' => 'CANVAS'),
        'width'      => 1440,
        'height'     => 4600,
        'text_count' => 40,
        'score'      => 820,
    );
    $matrixSelection = matrix_select_frame_ids(array('candidates' => array($matrixResearchCandidate, $matrixWebsiteCandidate)), 2);
    $assert(array('matrix:site:home', 'matrix:research:screen') === $matrixSelection, 'fixture-matrix-research-screens-demoted');
    $assert(matrix_candidate_rank($matrixWebsiteCandidate) > matrix_candidate_rank($matrixResearchCandidate), 'fixture-matrix-reference-page-rank-penalty');
    $assert(! in_array('page_collection', matrix_candidate_selection_reasons($matrixResearchCandidate), true), 'fixture-matrix-reference-page-not-page-collection');
    
    $multiPageResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'   => 'Multi Page Fixture',
        'assets' => array(
            'home-image' => array('mime_type' => 'image/png', 'content' => 'home image'),
            'about-image' => array('mime_type' => 'image/png', 'content' => 'about image'),
            'unused-image' => array('mime_type' => 'image/png', 'content' => 'unused image'),
        ),
        'nodes'  => array(
            array(
                'id'       => 'page:multi',
                'type'     => 'CANVAS',
                'name'     => 'Site Pages',
                'children' => array(
                    array(
                        'id'       => 'frame:home',
                        'type'     => 'FRAME',
                        'name'     => 'Home',
                        'width'    => 1440,
                        'height'   => 1200,
                        'children' => array(
                            array('id' => 'text:home', 'type' => 'TEXT', 'name' => 'Home title', 'characters' => 'Home Hero', 'fontName' => array('family' => 'Example Sans', 'style' => 'Regular'), 'fontSize' => 20),
                            array('id' => 'image:home', 'type' => 'RECTANGLE', 'name' => 'Home image', 'width' => 100, 'height' => 60, 'fillPaints' => array(array('type' => 'IMAGE', 'imageRef' => 'home-image'))),
                            array('id' => 'button:home:one', 'type' => 'TEXT', 'name' => 'Button', 'characters' => 'Read more', 'width' => 80, 'height' => 24, 'fontSize' => 16, 'fontWeight' => 700, 'prototypeInteractions' => array(array('actions' => array(array('connectionType' => 'INTERNAL_NODE', 'transitionNodeID' => 'text:about', 'navigationType' => 'NAVIGATE'))))),
                            array('id' => 'button:home:two', 'type' => 'TEXT', 'name' => 'Button', 'characters' => 'Subscribe', 'width' => 80, 'height' => 24, 'fontSize' => 16, 'fontWeight' => 700),
                            array('id' => 'vector:home-large', 'type' => 'VECTOR', 'name' => 'Home Large Vector', 'width' => 10, 'height' => 10, 'figma_vector_paths' => array(array('data' => $externalizedVectorPath, 'source' => 'strokeGeometry'))),
                        ),
                    ),
                    array(
                        'id'       => 'frame:about',
                        'type'     => 'FRAME',
                        'name'     => 'About',
                        'width'    => 1440,
                        'height'   => 900,
                        'children' => array(
                            array('id' => 'text:about', 'type' => 'TEXT', 'name' => 'About title', 'characters' => 'About Hero', 'fontName' => array('family' => 'Example Sans', 'style' => 'Bold'), 'fontSize' => 20),
                            array('id' => 'image:about', 'type' => 'RECTANGLE', 'name' => 'About image', 'width' => 100, 'height' => 60, 'fillPaints' => array(array('type' => 'IMAGE', 'imageRef' => 'about-image'))),
                            array('id' => 'button:about:one', 'type' => 'TEXT', 'name' => 'Button', 'characters' => 'Read more', 'width' => 96, 'height' => 28, 'fontSize' => 18, 'fontWeight' => 500),
                            array('id' => 'button:about:two', 'type' => 'TEXT', 'name' => 'Button', 'characters' => 'Subscribe', 'width' => 96, 'height' => 28, 'fontSize' => 18, 'fontWeight' => 500),
                        ),
                    ),
                ),
            ),
        ),
    ), array(
        'multi_page' => true,
        'frame_ids' => array('frame:home', 'frame:about'),
        'entry_frame_id' => 'frame:home',
        'site_url' => 'https://example.com/site',
        'site_metadata' => array(
            'favicon_href' => '/favicon.svg',
            'og_image' => 'https://example.com/social.png',
            'twitter_card' => 'summary_large_image',
        ),
        'page_metadata' => array(
            'index.html' => array('description' => 'Explicit home description from source metadata.', 'og_title' => 'Explicit Home Social Title'),
            'about.html' => array('description' => 'Explicit about description from source metadata.'),
        ),
        'font_css' => '@font-face{font-family:"Example Sans";src:url("assets/example-sans.woff2") format("woff2")}',
        'generated_render_evidence' => array(
            'schema' => 'homeboy/static-artifact-render-evidence/v1',
            'entrypoints' => array(
                array(
                    'page_path' => 'index.html',
                    'elements' => array(
                        array('node_id' => 'text:home', 'computed_style' => array('font-family' => 'Example Sans', 'font-size' => '20px', 'font-weight' => '400')),
                    ),
                ),
                array(
                    'page_path' => 'about.html',
                    'elements' => array(
                        array('node_id' => 'text:about', 'computed_style' => array('font-family' => 'Arial, sans-serif', 'font-size' => '20px', 'font-weight' => '700')),
                    ),
                ),
            ),
        ),
    ));
    $multiPageIndex = $fileContent($multiPageResult, 'index.html');
    $multiPageAbout = $fileContent($multiPageResult, 'about.html');
    $multiPageStyle = $fileContent($multiPageResult, 'style.css');
    $multiPageAssetPaths = array_map(static fn (array $asset): string => (string) ($asset['path'] ?? ''), $multiPageResult['assets'] ?? array());
    $assert('success' === ($multiPageResult['status'] ?? null), 'multi-page-transform-success');
    $assert(str_contains($multiPageIndex, 'Home Hero'), 'multi-page-index-renders-entry-frame');
    $assert(! str_contains($multiPageIndex, 'About Hero'), 'multi-page-index-omits-other-frame');
    $assert(str_contains($multiPageAbout, 'About Hero'), 'multi-page-about-renders-second-frame');
    $assert(1 === preg_match('/<a class="figma-link" href="about\.html" data-figma-link-type="node"><p class="[^"]*figma-node-button-home-one-button[^"]*"/', $multiPageIndex), 'multi-page-descendant-prototype-link-resolves-to-page');
    $assert(! str_contains($multiPageIndex, 'href="#" data-figma-link-type="node"'), 'multi-page-prototype-link-not-placeholder');
    $assert(str_contains($multiPageIndex, '<meta name="description" content="Explicit home description from source metadata.">'), 'multi-page-index-description-from-explicit-metadata');
    $assert(str_contains($multiPageIndex, '<link rel="canonical" href="https://example.com/site/">'), 'multi-page-index-canonical-from-site-url');
    $assert(str_contains($multiPageAbout, '<link rel="canonical" href="https://example.com/site/about.html">'), 'multi-page-about-canonical-from-site-url');
    $assert(str_contains($multiPageIndex, '<link rel="icon" href="/favicon.svg">'), 'multi-page-index-favicon-from-explicit-metadata');
    $assert(str_contains($multiPageIndex, '<meta property="og:title" content="Explicit Home Social Title">'), 'multi-page-index-social-title-from-explicit-metadata');
    $assert(str_contains($multiPageIndex, '<meta property="og:image" content="https://example.com/social.png">'), 'multi-page-index-social-image-from-explicit-metadata');
    $assert(str_contains($multiPageIndex, '<meta name="twitter:card" content="summary_large_image">'), 'multi-page-index-twitter-card-from-explicit-metadata');
    $assert(! str_contains($multiPageIndex, '<style data-figma-transformer-css="true">'), 'multi-page-index-links-shared-css-without-inline-duplication');
    $assert(str_contains($multiPageIndex, '<img class="figma-vector-asset"') && str_contains($multiPageIndex, ' width="10" height="10" decoding="async" data-figma-vector="true"'), 'multi-page-external-vector-image-has-dimensions');
    $assert(str_contains($multiPageStyle, '.figma-node-frame-home-home'), 'multi-page-shared-css-home');
    $assert(str_contains($multiPageStyle, '.figma-node-frame-about-about'), 'multi-page-shared-css-about');
    preg_match_all('/\.button-[0-9a-f]{8}\{/', $multiPageStyle, $multiPageButtonSharedClasses);
    $assert(2 === count(array_unique($multiPageButtonSharedClasses[0] ?? array())), 'multi-page-shared-readable-classes-are-hashed');
    $assert(! str_contains($multiPageStyle, '.button{'), 'multi-page-shared-readable-class-base-not-reused');
    $assert(2 === ($multiPageResult['metrics']['page_count'] ?? null), 'multi-page-page-count');
    $assert(2 === ($multiPageResult['source_reports']['compiled_site']['totals']['page_count'] ?? null), 'multi-page-compiled-site-page-count');
    $assert('about.html' === ($multiPageResult['source_reports']['compiled_site']['pages'][1]['path'] ?? null), 'multi-page-compiled-site-page-path');
    $assert(3 === ($multiPageResult['source_reports']['compiled_site']['totals']['asset_count'] ?? null), 'multi-page-compiled-site-asset-count');
    $assert(array('Example Sans') === ($multiPageResult['source_reports']['figma']['html']['font_families'] ?? null), 'multi-page-font-families-aggregated');
    $multiPageFontUsage = $multiPageResult['source_reports']['figma']['html']['font_usage'] ?? array();
    $multiPageCompiledFontUsage = $multiPageResult['source_reports']['compiled_site']['theme']['font_usage'] ?? array();
    $assert('Example Sans' === ($multiPageFontUsage[0]['family'] ?? null) && array(400, 700) === ($multiPageFontUsage[0]['weights'] ?? null), 'multi-page-font-usage-aggregated');
    $assert(2 === ($multiPageFontUsage[0]['text_node_count'] ?? null), 'multi-page-font-usage-node-count-aggregated');
    $assert('Example Sans' === ($multiPageCompiledFontUsage[0]['family'] ?? null) && array(400, 700) === ($multiPageCompiledFontUsage[0]['weights'] ?? null), 'multi-page-compiled-site-font-usage');
    $assert(in_array('assets/home-image.png', $multiPageAssetPaths, true), 'multi-page-home-asset');
    $assert(in_array('assets/about-image.png', $multiPageAssetPaths, true), 'multi-page-about-asset');
    $assert(! in_array('assets/unused-image.png', $multiPageAssetPaths, true), 'multi-page-unused-asset-filtered');
    $multiPageTransformDiagnostics = $multiPageResult['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
    $assert('blocks-engine/figma-transformer/transform-diagnostics/v1' === ($multiPageTransformDiagnostics['schema'] ?? null), 'multi-page-transform-diagnostics-schema');
    $assert('multi_page' === ($multiPageTransformDiagnostics['scope'] ?? null), 'multi-page-transform-diagnostics-scope');
    $assert(2 === count($multiPageTransformDiagnostics['pages'] ?? array()), 'multi-page-transform-diagnostics-pages');
    $assert('about.html' === ($multiPageTransformDiagnostics['pages'][1]['page_path'] ?? null), 'multi-page-transform-diagnostics-page-path');
    $assert(2 === ($multiPageTransformDiagnostics['images']['paint_refs'] ?? null), 'multi-page-transform-diagnostics-image-paints');
    $assert(2 === ($multiPageTransformDiagnostics['images']['resolved_assets'] ?? null), 'multi-page-transform-diagnostics-resolved-assets');
    $assert(3 === ($multiPageTransformDiagnostics['assets']['emitted_files'] ?? null), 'multi-page-transform-diagnostics-emitted-assets');
    $assert(1 === ($multiPageTransformDiagnostics['generated_svg_assets']['count'] ?? null), 'multi-page-transform-diagnostics-generated-svg-count');
    $assert(($multiPageTransformDiagnostics['generated_svg_assets']['gzip_bytes'] ?? 0) > 0, 'multi-page-transform-diagnostics-generated-svg-gzip-bytes');
    $assert(1 === ($multiPageTransformDiagnostics['generated_svg_assets']['path_element_count'] ?? null), 'multi-page-transform-diagnostics-generated-svg-path-element-count');
    $assert(($multiPageTransformDiagnostics['generated_svg_assets']['path_data_bytes'] ?? 0) > 65536, 'multi-page-transform-diagnostics-generated-svg-path-data-bytes');
    $assert(1 === ($multiPageTransformDiagnostics['generated_svg_assets']['unique_path_data_count'] ?? null), 'multi-page-transform-diagnostics-generated-svg-unique-path-data-count');
    $assert(0 === ($multiPageTransformDiagnostics['generated_svg_assets']['duplicate_path_data_count'] ?? null), 'multi-page-transform-diagnostics-generated-svg-duplicate-path-data-count');

    $multiPageTokenScopeResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Multi Page Token Scope Fixture',
        'nodes' => array(
            array(
                'id'       => 'token-scope:canvas',
                'type'     => 'CANVAS',
                'name'     => 'Site Pages',
                'children' => array(
                    array(
                        'id'       => 'token-scope:home',
                        'type'     => 'FRAME',
                        'name'     => 'Home',
                        'width'    => 1440,
                        'height'   => 900,
                        'children' => array(
                            array(
                                'id'       => 'token-scope:home:tokens',
                                'type'     => 'FRAME',
                                'name'     => 'Typography',
                                'width'    => 320,
                                'height'   => 160,
                                'children' => array(
                                    array('id' => 'token-scope:home:display', 'type' => 'TEXT', 'name' => 'Display', 'characters' => 'Display', 'fontSize' => 72, 'fontWeight' => 700),
                                    array('id' => 'token-scope:home:body', 'type' => 'TEXT', 'name' => 'Body', 'characters' => 'Body', 'fontSize' => 18, 'fontWeight' => 400),
                                ),
                            ),
                        ),
                    ),
                    array(
                        'id'       => 'token-scope:about',
                        'type'     => 'FRAME',
                        'name'     => 'About',
                        'width'    => 1440,
                        'height'   => 900,
                        'children' => array(
                            array(
                                'id'       => 'token-scope:about:tokens',
                                'type'     => 'FRAME',
                                'name'     => 'Typography',
                                'width'    => 320,
                                'height'   => 160,
                                'children' => array(
                                    array('id' => 'token-scope:about:display', 'type' => 'TEXT', 'name' => 'Display', 'characters' => 'Display', 'fontSize' => 36, 'fontWeight' => 700),
                                    array('id' => 'token-scope:about:body', 'type' => 'TEXT', 'name' => 'Body', 'characters' => 'Body', 'fontSize' => 14, 'fontWeight' => 400),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ), array('multi_page' => true, 'frame_ids' => array('token-scope:home', 'token-scope:about'), 'entry_frame_id' => 'token-scope:home'));
    $multiPageTokenScopeCss = $fileContent($multiPageTokenScopeResult, 'style.css');
    $assert(! str_contains($multiPageTokenScopeCss, ':root{--'), 'multi-page-design-tokens-not-global-root');
    $assert(str_contains($multiPageTokenScopeCss, '.figma-node-token-scope-home-home{--') && str_contains($multiPageTokenScopeCss, '.figma-node-token-scope-about-about{--'), 'multi-page-design-tokens-scoped-to-page-roots');
    $assert('selected_frames' === ($multiPageTransformDiagnostics['selection']['mode'] ?? null), 'multi-page-transform-diagnostics-selection-mode');
    $assert('frame:home' === ($multiPageTransformDiagnostics['selection']['selected_frames'][0]['frame_id'] ?? null), 'multi-page-transform-diagnostics-entry-frame-selection');
    $assert('about.html' === ($multiPageTransformDiagnostics['selection']['selected_frames'][1]['path'] ?? null), 'multi-page-transform-diagnostics-about-selection-path');
    $assert(1 === ($multiPageTransformDiagnostics['layout']['render_style_mismatch_count'] ?? null), 'multi-page-render-style-mismatch-aggregated');
    $assert('fail' === ($multiPageTransformDiagnostics['layout']['render_style_mismatch_status'] ?? null), 'multi-page-render-style-status-aggregated');
    $assert(1 === ($multiPageTransformDiagnostics['layout']['render_style']['summary']['font_mismatch_count'] ?? null), 'multi-page-render-style-font-count-aggregated');
    $assert(in_array('render_style_mismatch', array_map(static fn (array $signal): string => (string) ($signal['code'] ?? ''), $multiPageTransformDiagnostics['artifact_quality']['signals'] ?? array()), true), 'multi-page-render-style-artifact-quality-signal');
    $assert('warn' === ($multiPageTransformDiagnostics['artifact_quality']['quality_status'] ?? null), 'multi-page-transform-diagnostics-quality-status-warn');

    $semanticRoutesResult = ( new Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter() )->emitSite(array(
        'name'  => 'Semantic Route Fixture',
        'nodes' => array(
            array(
                'id'       => 'semantic:canvas',
                'type'     => 'CANVAS',
                'name'     => 'Site',
                'children' => array(
            array(
                'id'       => 'semantic:home',
                'type'     => 'FRAME',
                'name'     => 'Home Page - Desktop',
                'width'    => 1440,
                'height'   => 1200,
                'children' => array(
                    array('id' => 'semantic:logo', 'type' => 'FRAME', 'name' => 'Logo', 'width' => 160, 'height' => 40),
                    array('id' => 'semantic:nav', 'type' => 'FRAME', 'name' => 'Navigation', 'width' => 360, 'height' => 40, 'children' => array(
                        array('id' => 'semantic:nav:news', 'type' => 'FRAME', 'name' => 'Menu Item', 'width' => 80, 'height' => 32, 'children' => array(
                            array('id' => 'semantic:nav:news:text', 'type' => 'TEXT', 'name' => 'Text', 'characters' => 'News', 'fontSize' => 16),
                        )),
                        array('id' => 'semantic:nav:about', 'type' => 'FRAME', 'name' => 'Menu Item', 'width' => 80, 'height' => 32, 'children' => array(
                            array('id' => 'semantic:nav:about:text', 'type' => 'TEXT', 'name' => 'Text', 'characters' => 'About', 'fontSize' => 16),
                        )),
                        array('id' => 'semantic:nav:reviews', 'type' => 'FRAME', 'name' => 'Menu Item', 'width' => 80, 'height' => 32, 'children' => array(
                            array('id' => 'semantic:nav:reviews:text', 'type' => 'TEXT', 'name' => 'Text', 'characters' => 'Reviews', 'fontSize' => 16),
                        )),
                    )),
                    array('id' => 'semantic:reviews-heading', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Reviews', 'fontSize' => 48),
                    array('id' => 'semantic:card-title', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Review Title', 'fontSize' => 36),
                    array('id' => 'semantic:pagination', 'type' => 'FRAME', 'name' => 'Pagination', 'width' => 360, 'height' => 48, 'layout' => array('display' => 'flex', 'flex_direction' => 'row'), 'children' => array(
                        array('id' => 'semantic:pagination:previous', 'type' => 'FRAME', 'name' => 'Button', 'width' => 96, 'height' => 40, 'layout' => array('grow' => 1), 'children' => array(
                            array('id' => 'semantic:pagination:previous:text', 'type' => 'TEXT', 'name' => 'Text', 'characters' => 'Previous', 'fontSize' => 16),
                        )),
                        array('id' => 'semantic:pagination:one', 'type' => 'TEXT', 'name' => 'Page number', 'characters' => '1', 'fontSize' => 16),
                        array('id' => 'semantic:pagination:two', 'type' => 'TEXT', 'name' => 'Page number', 'characters' => '2', 'fontSize' => 16),
                        array('id' => 'semantic:pagination:three', 'type' => 'TEXT', 'name' => 'Page number', 'characters' => '3', 'fontSize' => 16),
                        array('id' => 'semantic:pagination:next', 'type' => 'FRAME', 'name' => 'Button', 'width' => 96, 'height' => 40, 'layout' => array('grow' => 1), 'children' => array(
                            array('id' => 'semantic:pagination:next:text', 'type' => 'TEXT', 'name' => 'Text', 'characters' => 'Next', 'fontSize' => 16),
                        )),
                    )),
                    array('id' => 'semantic:search-form', 'type' => 'FRAME', 'name' => 'Search form', 'width' => 320, 'height' => 56, 'children' => array(
                        array('id' => 'semantic:search-field', 'type' => 'FRAME', 'name' => 'Search field', 'width' => 240, 'height' => 44, 'cornerRadius' => 4, 'fills' => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 1, 'b' => 1, 'a' => 1))), 'children' => array(
                            array('id' => 'semantic:search-placeholder', 'type' => 'TEXT', 'name' => 'Placeholder', 'characters' => 'Search for...', 'fontSize' => 16),
                        )),
                    )),
                    array('id' => 'semantic:newsletter-form', 'type' => 'FRAME', 'name' => 'Newsletter form', 'width' => 420, 'height' => 56, 'children' => array(
                        array('id' => 'semantic:email-field', 'type' => 'FRAME', 'name' => 'Email input', 'width' => 240, 'height' => 44, 'cornerRadius' => 4, 'fills' => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 1, 'b' => 1, 'a' => 1))), 'children' => array(
                            array('id' => 'semantic:email-placeholder', 'type' => 'TEXT', 'name' => 'Placeholder', 'characters' => 'Email address', 'fontSize' => 16),
                        )),
                        array('id' => 'semantic:newsletter-submit', 'type' => 'FRAME', 'name' => 'Button', 'width' => 128, 'height' => 44, 'cornerRadius' => 999, 'fills' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 0, 'a' => 1))), 'children' => array(
                            array('id' => 'semantic:newsletter-submit:text', 'type' => 'TEXT', 'name' => 'Subscribe', 'characters' => 'Subscribe', 'fontSize' => 16),
                        )),
                    )),
                    array('id' => 'semantic:footer-links', 'type' => 'FRAME', 'name' => 'Frame 29', 'width' => 300, 'height' => 32, 'children' => array(
                        array('id' => 'semantic:footer:about', 'type' => 'TEXT', 'name' => 'Footer text', 'characters' => 'About', 'fontSize' => 16, 'width' => 80, 'height' => 20),
                        array('id' => 'semantic:footer:contact', 'type' => 'TEXT', 'name' => 'Footer text', 'characters' => 'Contact', 'fontSize' => 16, 'width' => 80, 'height' => 20),
                        array('id' => 'semantic:footer:privacy', 'type' => 'TEXT', 'name' => 'Footer text', 'characters' => 'Privacy Policy', 'fontSize' => 16, 'width' => 120, 'height' => 20),
                    )),
                ),
            ),
            array(
                'id'       => 'semantic:archive',
                'type'     => 'FRAME',
                'name'     => 'Archive - Desktop',
                'width'    => 1440,
                'height'   => 1000,
                'children' => array(array('id' => 'semantic:archive:title', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'News', 'fontSize' => 96)),
            ),
            array(
                'id'       => 'semantic:about',
                'type'     => 'FRAME',
                'name'     => 'Page - Desktop',
                'width'    => 1440,
                'height'   => 1000,
                'children' => array(array('id' => 'semantic:about:title', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'About Us', 'fontSize' => 96)),
            ),
            array(
                'id'       => 'semantic:single',
                'type'     => 'FRAME',
                'name'     => 'Blog Post - Desktop',
                'width'    => 1440,
                'height'   => 1000,
                'children' => array(array('id' => 'semantic:single:title', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Review Title', 'fontSize' => 96)),
            ),
                ),
            ),
        ),
    ), array(
        'pages' => array(
            array('frame_id' => 'semantic:home', 'name' => 'Home Page - Desktop', 'path' => 'index.html', 'entrypoint' => true, 'page_type' => 'front_page'),
            array('frame_id' => 'semantic:archive', 'name' => 'Archive - Desktop', 'path' => 'archive.html', 'entrypoint' => false, 'page_type' => 'archive'),
            array('frame_id' => 'semantic:about', 'name' => 'Page - Desktop', 'path' => 'page.html', 'entrypoint' => false, 'page_type' => 'page'),
            array('frame_id' => 'semantic:single', 'name' => 'Blog Post - Desktop', 'path' => 'blog-post.html', 'entrypoint' => false, 'page_type' => 'single'),
        ),
    ));
    $semanticHome = $fileContent($semanticRoutesResult, 'index.html');
    $semanticArchive = $fileContent($semanticRoutesResult, 'archive.html');
    $semanticAbout = $fileContent($semanticRoutesResult, 'page.html');
    $assert(str_contains($semanticHome, '<a class="figma-link" href="index.html" data-figma-link-type="implicit-route"><div class="figma-node-semantic-logo-logo"'), 'semantic-route-logo-links-entrypoint');
    $assert(str_contains($semanticHome, 'href="archive.html" data-figma-link-type="implicit-route"') && str_contains($semanticHome, '>News</span>'), 'semantic-route-nav-news-links-archive');
    $assert(str_contains($semanticHome, 'href="page.html" data-figma-link-type="implicit-route"') && str_contains($semanticHome, '>About</span>'), 'semantic-route-nav-about-links-page-heading');
    $assert(str_contains($semanticHome, 'href="#reviews" data-figma-link-type="implicit-route"') && str_contains($semanticHome, '>Reviews</span>'), 'semantic-route-nav-current-page-section-links-heading-anchor');
    $assert(str_contains($semanticHome, 'href="blog-post.html" data-figma-link-type="implicit-route"') && str_contains($semanticHome, '>Review Title</h'), 'semantic-route-card-heading-links-single-title');
    $assert(str_contains($semanticHome, 'href="archive.html" data-figma-link-type="implicit-route"') && str_contains($semanticHome, '>Next</span>'), 'semantic-route-pagination-next-links-archive');
    $assert(! str_contains($semanticHome, '<a class="figma-link button" href="archive.html" data-figma-link-type="implicit-route"><button'), 'semantic-route-linked-pagination-button-does-not-wrap-button-element');
    $assert(! preg_match('/<a\b[^>]*><div\b[^>]*><a\b/s', $semanticHome), 'semantic-route-menu-items-do-not-emit-nested-anchors');
    $assert(! preg_match('/<a\b[^>]*><li\b/s', $semanticHome), 'semantic-route-list-items-keep-anchor-inside-li');
    $assert(! str_contains($semanticArchive, 'href="archive.html" data-figma-link-type="implicit-route"><h1'), 'semantic-route-current-archive-title-not-self-linked');
    $assert(! str_contains($semanticAbout, 'href="page.html" data-figma-link-type="implicit-route"><h1'), 'semantic-route-current-page-title-not-self-linked');
    $assert(str_contains($semanticHome, '<form') && str_contains($semanticHome, 'method="get" action="index.html" role="search"'), 'semantic-route-search-form-action');
    $assert(str_contains($semanticHome, '<form') && str_contains($semanticHome, 'method="post" action="index.html"'), 'semantic-route-newsletter-form-action');
    $assert(str_contains($semanticHome, 'type="search" name="s"'), 'semantic-route-search-input-name');
    $assert(str_contains($semanticHome, 'type="email" name="email"'), 'semantic-route-email-input-name');
    
    // RESPONSIVE PAGE ASSEMBLY — LIVE WIRING (#247): a source whose section holds
    // "Home – Desktop" + "Home – Mobile" sibling frames must (1) PAIR into ONE
    // responsive page in the planner (generic name-normalization + device hint +
    // width signals, NOT hardcoded ids) and (2) flow that grouping through the LIVE
    // transform so the emitted page carries the base (desktop) layout PLUS an
    // `@media (max-width: …)` block for the mobile breakpoint. This exercises the
    // full path end-to-end (planner grouping -> transformScenegraphPages ->
    // transformResponsivePage -> StaticHtmlEmitter::emitSite -> merged style.css),
    // proving emitSite is no longer dormant in production.
    $responsiveLiveResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Responsive Live Fixture',
        'nodes' => array(
            array(
                'id'       => 'page:responsive-live',
                'type'     => 'CANVAS',
                'name'     => 'Site',
                'children' => array(
                    array(
                        'id'       => 'section:responsive-live',
                        'type'     => 'SECTION',
                        'name'     => 'Home Section',
                        'children' => array(
                            array(
                                'id'       => 'frame:home-desktop-live',
                                'type'     => 'FRAME',
                                'name'     => 'Home – Desktop',
                                'width'    => 1440,
                                'height'   => 3000,
                                'children' => array(
                                    array('id' => 'card:home-desktop', 'type' => 'RECTANGLE', 'name' => 'Hero Card', 'width' => 1200, 'height' => 400, 'backgroundColor' => array('r' => 1.0, 'g' => 0.0, 'b' => 0.0, 'a' => 1.0)),
                                    array('id' => 'text:home-desktop', 'type' => 'TEXT', 'name' => 'Hero copy', 'characters' => 'Welcome home', 'fontSize' => 32),
                                ),
                            ),
                            array(
                                'id'       => 'frame:home-mobile-live',
                                'type'     => 'FRAME',
                                'name'     => 'Home – Mobile',
                                'width'    => 390,
                                'height'   => 3200,
                                'children' => array(
                                    array('id' => 'card:home-mobile', 'type' => 'RECTANGLE', 'name' => 'Hero Card', 'width' => 350, 'height' => 500, 'backgroundColor' => array('r' => 0.0, 'g' => 1.0, 'b' => 0.0, 'a' => 1.0)),
                                    array('id' => 'text:home-mobile', 'type' => 'TEXT', 'name' => 'Hero copy', 'characters' => 'Welcome home', 'fontSize' => 32),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ), array(
        'multi_page'        => true,
        'include_all_pages' => true,
    ));
    $responsiveLiveIndex = $fileContent($responsiveLiveResult, 'index.html');
    $responsiveLiveStyle = $fileContent($responsiveLiveResult, 'style.css');
    $assert(in_array($responsiveLiveResult['status'] ?? null, array('success', 'success_with_warnings'), true), 'responsive-live-transform-success');
    // Desktop + mobile siblings paired into ONE page (not two).
    $assert(1 === ($responsiveLiveResult['metrics']['page_count'] ?? null), 'responsive-live-single-page');
    $assert('' !== $responsiveLiveIndex, 'responsive-live-index-emitted');
    $assert('' !== $responsiveLiveStyle, 'responsive-live-stylesheet-emitted');
    // Base styles present (the widest/desktop variant drives the base layout).
    $assert(str_contains($responsiveLiveStyle, '.figma-root'), 'responsive-live-base-styles-present');
    $assert(str_contains($responsiveLiveStyle, 'width:1200px') || str_contains($responsiveLiveStyle, 'width:1200px;'), 'responsive-live-base-uses-desktop-width');
    // The merged stylesheet carries an `@media` block (the mobile breakpoint),
    // proving the responsive emission fired through the LIVE transform path.
    // desktop=1440, mobile=390: midpoint = round((1440+390)/2) = 915.
    $assert(str_contains($responsiveLiveStyle, '@media'), 'responsive-live-media-block-present');
    $assert(str_contains($responsiveLiveStyle, '@media (max-width:915px)'), 'responsive-live-mobile-media-query');
    // `@media` block survived chunk merging intact (balanced braces, base before media).
    $assert(strpos($responsiveLiveStyle, '.figma-root') < strpos($responsiveLiveStyle, '@media'), 'responsive-live-base-precedes-media');
    $assert(substr_count($responsiveLiveStyle, '{') === substr_count($responsiveLiveStyle, '}'), 'responsive-live-balanced-braces');
    $responsiveLivePagePlan = $responsiveLiveResult['source_reports']['figma']['pages'] ?? array();
    $assert(true === ($responsiveLivePagePlan['pages'][0]['responsive'] ?? null), 'responsive-live-plan-flagged-responsive');
    $assert(2 === ($responsiveLivePagePlan['pages'][0]['breakpoint_count'] ?? null), 'responsive-live-plan-two-breakpoints');
    
    // SINGLE-FRAME RESPONSIVENESS: a source with one wide desktop frame still
    // produces one non-responsive page, plus a conservative mobile fallback
    // media block for the fixed-width canvas.
    $singleFrameLiveResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Single Frame Live Fixture',
        'nodes' => array(
            array(
                'id'       => 'page:single-live',
                'type'     => 'CANVAS',
                'name'     => 'Site',
                'children' => array(
                    array(
                        'id'       => 'frame:about-live',
                        'type'     => 'FRAME',
                        'name'     => 'About',
                        'width'    => 1440,
                        'height'   => 2000,
                        'children' => array(
                            array('id' => 'card:about-live', 'type' => 'RECTANGLE', 'name' => 'About Card', 'width' => 1100, 'height' => 300, 'backgroundColor' => array('r' => 0.0, 'g' => 0.0, 'b' => 1.0, 'a' => 1.0)),
                            array('id' => 'text:about-live', 'type' => 'TEXT', 'name' => 'About copy', 'characters' => 'About us', 'fontSize' => 24),
                        ),
                    ),
                ),
            ),
        ),
    ), array(
        'multi_page'        => true,
        'include_all_pages' => true,
    ));
    $singleFrameLiveStyle = $fileContent($singleFrameLiveResult, 'style.css');
    $assert(in_array($singleFrameLiveResult['status'] ?? null, array('success', 'success_with_warnings'), true), 'single-frame-live-transform-success');
    $assert(1 === ($singleFrameLiveResult['metrics']['page_count'] ?? null), 'single-frame-live-single-page');
    $assert('' !== $singleFrameLiveStyle, 'single-frame-live-stylesheet-emitted');
    $assert(str_contains($singleFrameLiveStyle, '@media (max-width:767px)'), 'single-frame-live-desktop-fallback-media-block');
    $assert(false === ($singleFrameLiveResult['source_reports']['figma']['pages']['pages'][0]['responsive'] ?? true), 'single-frame-live-plan-not-responsive');
}
