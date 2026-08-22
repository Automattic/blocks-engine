<?php

declare(strict_types=1);

use Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter;
use Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmissionDiagnostics;
use Automattic\BlocksEngine\FigmaTransformer\Html\TransformDiagnosticsBuilder;

/**
 * @param callable(bool, string): void $assert
 */
function blocks_engine_figma_transformer_run_html_validity_contract(callable $assert, callable $fileContent): void
{
    $scenegraph = array(
        'name'  => 'HTML Validity Fixture',
        'nodes' => array(
            array(
                'id'       => 'validity:home',
                'type'     => 'FRAME',
                'name'     => 'Home Page',
                'width'    => 1440,
                'height'   => 900,
                'children' => array(
                    array(
                        'id'       => 'validity:nav',
                        'type'     => 'FRAME',
                        'name'     => 'Navigation',
                        'children' => array(
                            array(
                                'id'       => 'validity:news-item',
                                'type'     => 'FRAME',
                                'name'     => 'Menu Item',
                                'width'    => 80,
                                'height'   => 32,
                                'children' => array(
                                    array('id' => 'validity:news-text', 'type' => 'TEXT', 'name' => 'Text', 'characters' => 'News', 'fontSize' => 16),
                                ),
                            ),
                            array(
                                'id'         => 'validity:packed-nav',
                                'type'       => 'TEXT',
                                'name'       => 'Main Nav Links',
                                'characters' => 'News      About      Contact',
                                'fontSize'   => 16,
                            ),
                            array(
                                'id'         => 'validity:measured-packed-nav',
                                'type'       => 'TEXT',
                                'name'       => 'Measured Main Nav Links',
                                'characters' => 'News      About      Contact',
                                'fontSize'   => 16,
                                'width'      => 691,
                                'height'     => 26,
                            ),
                        ),
                    ),
                    array(
                        'id'       => 'validity:pagination',
                        'type'     => 'FRAME',
                        'name'     => 'Pagination',
                        'layout'   => array('display' => 'flex', 'flex_direction' => 'row'),
                        'children' => array(
                            array(
                                'id'       => 'validity:previous-button',
                                'type'     => 'FRAME',
                                'name'     => 'Button',
                                'width'    => 120,
                                'height'   => 44,
                                'children' => array(
                                    array('id' => 'validity:previous-text', 'type' => 'TEXT', 'name' => 'Text', 'characters' => 'Previous', 'fontSize' => 16),
                                ),
                            ),
                            array('id' => 'validity:page-1', 'type' => 'TEXT', 'name' => 'Number', 'characters' => '1', 'fontSize' => 16),
                            array('id' => 'validity:page-2', 'type' => 'TEXT', 'name' => 'Number', 'characters' => '2', 'fontSize' => 16),
                            array('id' => 'validity:page-3', 'type' => 'TEXT', 'name' => 'Number', 'characters' => '3', 'fontSize' => 16),
                            array(
                                'id'       => 'validity:next-button',
                                'type'     => 'FRAME',
                                'name'     => 'Button',
                                'width'    => 120,
                                'height'   => 44,
                                'children' => array(
                                    array('id' => 'validity:next-text', 'type' => 'TEXT', 'name' => 'Text', 'characters' => 'Next', 'fontSize' => 16),
                                ),
                            ),
                        ),
                    ),
                    array(
                        'id'       => 'validity:footer-links',
                        'type'     => 'FRAME',
                        'name'     => 'Frame 29',
                        'height'   => 24,
                        'children' => array(
                            array('id' => 'validity:footer-about', 'type' => 'TEXT', 'name' => 'Footer text', 'characters' => 'About', 'fontSize' => 16, 'height' => 20),
                            array('id' => 'validity:footer-contact', 'type' => 'TEXT', 'name' => 'Footer text', 'characters' => 'Contact', 'fontSize' => 16, 'height' => 20),
                            array('id' => 'validity:footer-privacy', 'type' => 'TEXT', 'name' => 'Footer text', 'characters' => 'Privacy Policy', 'fontSize' => 16, 'height' => 20),
                        ),
                    ),
                    array(
                        'id'       => 'validity:services',
                        'type'     => 'FRAME',
                        'name'     => 'Services Section',
                        'width'    => 1200,
                        'height'   => 320,
                        'children' => array(
                            array('id' => 'validity:services-heading', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Services', 'fontSize' => 48),
                            array('id' => 'validity:services-copy', 'type' => 'TEXT', 'name' => 'Description', 'characters' => 'Treatment options', 'fontSize' => 18),
                        ),
                    ),
                    array(
                        'id'       => 'validity:pricing',
                        'type'     => 'FRAME',
                        'name'     => 'Pricing',
                        'width'    => 1200,
                        'height'   => 320,
                        'children' => array(
                            array('id' => 'validity:pricing-heading', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Prices', 'fontSize' => 48),
                            array('id' => 'validity:pricing-copy', 'type' => 'TEXT', 'name' => 'Description', 'characters' => 'Plans and prices', 'fontSize' => 18),
                        ),
                    ),
                    array(
                        'id'       => 'validity:contact-map',
                        'type'     => 'FRAME',
                        'name'     => 'Contact Map',
                        'width'    => 1200,
                        'height'   => 320,
                        'children' => array(
                            array('id' => 'validity:contact-heading', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Contact', 'fontSize' => 48),
                            array('id' => 'validity:contact-copy', 'type' => 'TEXT', 'name' => 'Description', 'characters' => 'Visit our map location', 'fontSize' => 18),
                        ),
                    ),
                    array(
                        'id'       => 'validity:final-cta',
                        'type'     => 'FRAME',
                        'name'     => 'Final CTA',
                        'width'    => 1200,
                        'height'   => 320,
                        'children' => array(
                            array('id' => 'validity:cta-heading', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Book an appointment', 'fontSize' => 48),
                            array('id' => 'validity:cta-copy', 'type' => 'TEXT', 'name' => 'Description', 'characters' => 'Reserve now', 'fontSize' => 18),
                        ),
                    ),
                ),
            ),
            array(
                'id'       => 'validity:archive',
                'type'     => 'FRAME',
                'name'     => 'News',
                'width'    => 1440,
                'height'   => 900,
                'children' => array(
                    array('id' => 'validity:archive-heading', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'News', 'fontSize' => 48),
                ),
            ),
            array(
                'id'       => 'validity:about',
                'type'     => 'FRAME',
                'name'     => 'About Us',
                'width'    => 1440,
                'height'   => 900,
                'children' => array(
                    array('id' => 'validity:about-heading', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'About Us', 'fontSize' => 48),
                ),
            ),
            array(
                'id'       => 'validity:contact',
                'type'     => 'FRAME',
                'name'     => 'Contact',
                'width'    => 1440,
                'height'   => 900,
                'children' => array(
                    array('id' => 'validity:contact-heading', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Contact', 'fontSize' => 48),
                ),
            ),
        ),
    );

    $result = (new StaticHtmlEmitter())->emitSite($scenegraph, array(
        'pages' => array(
            array('frame_id' => 'validity:home', 'name' => 'Home Page', 'path' => 'index.html', 'entrypoint' => true, 'page_type' => 'front_page'),
            array('frame_id' => 'validity:archive', 'name' => 'News', 'path' => 'archive.html', 'page_type' => 'archive'),
            array('frame_id' => 'validity:about', 'name' => 'About Us', 'path' => 'page.html', 'page_type' => 'page'),
            array('frame_id' => 'validity:contact', 'name' => 'Contact', 'path' => 'contact.html', 'page_type' => 'page'),
        ),
    ));

    $html = $fileContent($result, 'index.html');
    $links = $result['source_report']['transform_diagnostics']['links'] ?? array();
    $assert(str_contains($html, '<main class="figma-root" data-figma-root="true" data-static-artifact-capture="ignore" data-page-title="Home Page" aria-label="Home Page" data-page-path="index.html"'), 'html-validity-document-root-carries-page-legibility-metadata');
    $assert(str_contains($html, 'data-static-artifact-capture="ignore"'), 'html-validity-document-root-ignored-by-dom-box-capture');
    $assert(1 === preg_match('/data-figma-node-id="validity:news-item"[^>]* data-source-node-type="FRAME" data-source-visual-width="80" data-source-visual-height="32"/', $html), 'html-validity-node-preserves-source-dom-quality-evidence');
    $assert(1 === preg_match('/data-figma-node-id="validity:nav" data-figma-node-name="Navigation"[^>]* data-figma-semantic-role="nav"/', $html), 'html-validity-nav-carries-semantic-role-metadata');
    $assert(1 === preg_match('/data-figma-node-id="validity:services" data-figma-node-name="Services Section"[^>]* data-figma-semantic-role="services"/', $html), 'html-validity-services-section-carries-semantic-role-metadata');
    $assert(1 === preg_match('/data-figma-node-id="validity:pricing" data-figma-node-name="Pricing"[^>]* data-figma-semantic-role="pricing"/', $html), 'html-validity-pricing-section-carries-semantic-role-metadata');
    $assert(1 === preg_match('/data-figma-node-id="validity:contact-map" data-figma-node-name="Contact Map"[^>]* data-figma-semantic-role="map"/', $html), 'html-validity-map-section-carries-semantic-role-metadata');
    $assert(1 === preg_match('/data-figma-node-id="validity:final-cta" data-figma-node-name="Final CTA"[^>]* data-figma-semantic-role="cta"/', $html), 'html-validity-cta-section-carries-semantic-role-metadata');
    $assert(str_contains($html, '<a class="figma-link" href="archive.html" data-figma-link-type="implicit-route"><div class="figma-node-validity-news-item-menu-item"'), 'html-validity-menu-item-container-linked');
    $assert(1 === preg_match('/class="figma-node-validity-packed-nav-main-nav-links" data-figma-node-id="validity:packed-nav" data-figma-node-name="Main Nav Links"[^>]*><a class="figma-link" href="archive\.html" data-figma-link-type="implicit-route">News<\/a>      <a class="figma-link" href="page\.html" data-figma-link-type="implicit-route">About<\/a>      <a class="figma-link" href="contact\.html" data-figma-link-type="implicit-route">Contact<\/a>/', $html), 'html-validity-packed-route-text-preserves-spacing-with-inline-links');
    $assert(! str_contains($html, '<a class="figma-link" href="archive.html" data-figma-link-type="implicit-route"><span class="figma-node-validity-news-text-text"'), 'html-validity-linked-menu-item-suppresses-descendant-anchor');
    $assert(1 === preg_match('/<a class="figma-link button" href="archive\.html" data-figma-link-type="implicit-route"><div class="[^"]*figma-node-validity-next-button-button/', $html), 'html-validity-linked-button-renders-structural-div');
    $assert(! str_contains($html, '<a class="figma-link button" href="archive.html" data-figma-link-type="implicit-route"><button'), 'html-validity-linked-button-not-anchor-wrapped-button');
    $assert(1 === preg_match('/<li class="figma-node-validity-footer-about-footer-text" data-figma-node-id="validity:footer-about" data-figma-node-name="Footer text"[^>]*><a class="figma-link" href="page\.html" data-figma-link-type="implicit-route">About<\/a><\/li>/', $html), 'html-validity-linked-list-item-anchor-inside-li');
    $assert(0 === preg_match('/<ul class="figma-node-validity-footer-links-frame-29" data-figma-node-id="validity:footer-links" data-figma-node-name="Frame 29"[^>]*><a /', $html), 'html-validity-list-has-no-direct-anchor-child');
    $assert(($links['implicit_route_links'] ?? 0) >= 3, 'html-validity-implicit-route-links-counted');
    $assert(($links['implicit_route_self_suppressed'] ?? 0) >= 1, 'html-validity-implicit-route-self-links-counted');
    $routeTargets = is_array($links['route_targets'] ?? null) ? $links['route_targets'] : array();
    $emptyRouteTargets = array_values(array_filter($routeTargets, static fn (array $target): bool => '' === trim((string) ($target['label'] ?? ''))));
    $assert(empty($emptyRouteTargets), 'html-validity-route-targets-require-labels');
    $newsTargets = array_values(array_filter($routeTargets, static fn (array $target): bool => 'News' === ($target['label'] ?? '') && 'archive.html' === ($target['path'] ?? '')));
    $assert(! empty($newsTargets) && in_array(($newsTargets[0]['confidence'] ?? ''), array('high', 'medium'), true) && '' !== ($newsTargets[0]['evidence'] ?? ''), 'html-validity-route-target-evidence-confidence-reported');

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xpath = new DOMXPath($dom);
    $assert(0 === $xpath->query('//a[.//a]')->length, 'html-validity-no-nested-anchors');
    $assert(0 === $xpath->query('//a[.//button]')->length, 'html-validity-no-anchor-wrapped-buttons');
    $assert(0 === $xpath->query('//ul/*[not(self::li)]')->length, 'html-validity-ul-direct-children-are-li');

    $artifactDiagnostics = (new StaticHtmlEmissionDiagnostics())->htmlArtifactDiagnostics(
        '<main><section class="hero"><div class="card">Hero</div><div class="media"></div><div class="tile-a"></div><div class="tile-b"></div><div class="tile-c"></div><div class="tile-d"></div><div class="tile-e"></div></section><ul><a href="#">Bad direct link</a><li>Good</li></ul><form><input type="email"><svg><path d="M0 0H10V10Z"></path></svg></form></main>',
        '.hero{width:1600px;height:2400px;overflow:hidden}.card{width:640px}.media{width:480px}.tile-a{width:360px}.tile-b{width:360px}.tile-c{width:360px}.tile-d{width:360px}.tile-e{width:360px}@media (max-width: 767px){.media{width:100%}}'
    );
    $assert(8 === ($artifactDiagnostics['fixed_width_declaration_count'] ?? null), 'html-validity-artifact-diagnostics-counts-fixed-width-declarations');
    $assert(1 === ($artifactDiagnostics['fixed_width_with_responsive_override_count'] ?? null), 'html-validity-artifact-diagnostics-counts-responsive-covered-widths');
    $assert(7 === ($artifactDiagnostics['fixed_width_without_responsive_override_count'] ?? null), 'html-validity-artifact-diagnostics-counts-uncovered-fixed-widths');
    $assert(0.125 === ($artifactDiagnostics['effective_responsive_coverage_ratio'] ?? null), 'html-validity-artifact-diagnostics-reports-effective-responsive-coverage');
    $assert(1 === ($artifactDiagnostics['giant_fixed_section_count'] ?? null), 'html-validity-artifact-diagnostics-flags-giant-fixed-section');
    $assert(1 === ($artifactDiagnostics['large_overflow_risk_count'] ?? null), 'html-validity-artifact-diagnostics-flags-large-overflow-risk');
    $assert(1 === ($artifactDiagnostics['fallback_prone_form_island_count'] ?? null), 'html-validity-artifact-diagnostics-counts-form-islands');
    $assert(1 === ($artifactDiagnostics['fallback_prone_svg_island_count'] ?? null), 'html-validity-artifact-diagnostics-counts-svg-islands');
    $assert(1 === ($artifactDiagnostics['fallback_prone_input_island_count'] ?? null), 'html-validity-artifact-diagnostics-counts-input-islands');
    $assert(1 === ($artifactDiagnostics['invalid_list_child_count'] ?? null), 'html-validity-artifact-diagnostics-detects-invalid-list-children');
    $assert(2 === ($artifactDiagnostics['missing_semantic_role_count'] ?? null), 'html-validity-artifact-diagnostics-detects-missing-semantic-role');

    $sharedResponsiveArtifactDiagnostics = (new StaticHtmlEmissionDiagnostics())->htmlArtifactDiagnostics(
        '<main><div class="shared-card figma-node-card-a">First card</div><div class="shared-card figma-node-card-b">Second card</div></main>',
        '.shared-card{width:640px}@media (max-width:390px){.figma-node-card-a{max-width:100%}}'
    );
    $assert(1 === ($sharedResponsiveArtifactDiagnostics['fixed_width_with_responsive_override_count'] ?? null), 'html-validity-artifact-diagnostics-attributes-node-override-to-shared-style-class');
    $assert(1 === ($sharedResponsiveArtifactDiagnostics['fixed_width_without_responsive_override_count'] ?? null), 'html-validity-artifact-diagnostics-keeps-unoverridden-shared-style-instance-uncovered');

    $artifactQuality = (new TransformDiagnosticsBuilder())->artifactQualityDiagnostics(
        array('missing_assets' => array(), 'image_block_count' => 0, 'total_node_count' => 0),
        array('placeholders' => 0, 'rendered_asset_fallbacks' => 0),
        array('missing_css' => array(), 'usage' => array()),
        array('emitted_files' => 0),
        array('count' => 0, 'bytes' => 0),
        array(),
        array(),
        array(),
        array(),
        array(),
        array(),
        array(),
        $artifactDiagnostics
    );
    $artifactSignalCodes = array_map(static fn (array $signal): string => (string) ($signal['code'] ?? ''), $artifactQuality['signals'] ?? array());
    foreach ( array('low_effective_responsive_coverage', 'giant_fixed_section_risk', 'large_overflow_risk', 'fallback_prone_html_islands', 'invalid_list_children', 'missing_semantic_roles') as $code ) {
        $assert(in_array($code, $artifactSignalCodes, true), 'html-validity-artifact-quality-signal-' . $code);
    }

    $entrypointFallbackResult = (new StaticHtmlEmitter())->emitSite(array(
        'name'  => 'Implicit Entrypoint Fallback Fixture',
        'nodes' => array(
            array(
                'id'       => 'entryfallback:home',
                'type'     => 'FRAME',
                'name'     => 'Welcome',
                'width'    => 1440,
                'height'   => 900,
                'children' => array(
                    array('id' => 'entryfallback:logo', 'type' => 'FRAME', 'name' => 'Logo', 'width' => 120, 'height' => 40),
                    array('id' => 'entryfallback:nav', 'type' => 'FRAME', 'name' => 'Navigation', 'width' => 240, 'height' => 40, 'children' => array(
                        array('id' => 'entryfallback:nav:welcome', 'type' => 'FRAME', 'name' => 'Menu Item', 'width' => 100, 'height' => 32, 'children' => array(
                            array('id' => 'entryfallback:nav:welcome:text', 'type' => 'TEXT', 'name' => 'Text', 'characters' => 'Welcome', 'fontSize' => 16),
                        )),
                        array('id' => 'entryfallback:nav:home', 'type' => 'FRAME', 'name' => 'Menu Item', 'width' => 80, 'height' => 32, 'children' => array(
                            array('id' => 'entryfallback:nav:home:text', 'type' => 'TEXT', 'name' => 'Text', 'characters' => 'Home', 'fontSize' => 16),
                        )),
                    )),
                ),
            ),
            array(
                'id'       => 'entryfallback:about',
                'type'     => 'FRAME',
                'name'     => 'About',
                'width'    => 1440,
                'height'   => 900,
                'children' => array(
                    array('id' => 'entryfallback:about:nav', 'type' => 'FRAME', 'name' => 'Navigation', 'width' => 240, 'height' => 40, 'children' => array(
                        array('id' => 'entryfallback:about:nav:welcome', 'type' => 'FRAME', 'name' => 'Menu Item', 'width' => 100, 'height' => 32, 'children' => array(
                            array('id' => 'entryfallback:about:nav:welcome:text', 'type' => 'TEXT', 'name' => 'Text', 'characters' => 'Welcome', 'fontSize' => 16),
                        )),
                        array('id' => 'entryfallback:about:nav:home', 'type' => 'FRAME', 'name' => 'Menu Item', 'width' => 80, 'height' => 32, 'children' => array(
                            array('id' => 'entryfallback:about:nav:home:text', 'type' => 'TEXT', 'name' => 'Text', 'characters' => 'Home', 'fontSize' => 16),
                        )),
                    )),
                ),
            ),
        ),
    ), array(
        'pages' => array(
            array('frame_id' => 'entryfallback:home', 'name' => 'Welcome', 'path' => 'index.html', 'entrypoint' => true, 'page_type' => 'front_page'),
            array('frame_id' => 'entryfallback:about', 'name' => 'About', 'path' => 'about.html', 'page_type' => 'page'),
        ),
    ));
    $entrypointFallbackHtml = $fileContent($entrypointFallbackResult, 'index.html');
    $entrypointFallbackAboutHtml = $fileContent($entrypointFallbackResult, 'about.html');
    $entrypointFallbackLinks = $entrypointFallbackResult['source_report']['transform_diagnostics']['links'] ?? array();
    $assert(str_contains($entrypointFallbackHtml, '<a class="figma-link" href="index.html" data-figma-link-type="implicit-route"><div class="figma-node-entryfallback-logo-logo"'), 'html-validity-logo-can-link-entrypoint');
    $assert(str_contains($entrypointFallbackAboutHtml, '<a class="figma-link" href="index.html" data-figma-link-type="implicit-route"><div class="figma-node-entryfallback-about-nav-home-menu-item"'), 'html-validity-home-label-can-link-entrypoint');
    $assert(! str_contains($entrypointFallbackHtml, '<a class="figma-link" href="index.html" data-figma-link-type="implicit-route"><div class="figma-node-entryfallback-nav-welcome-menu-item"'), 'html-validity-non-home-entrypoint-label-not-linked');
    $assert(($entrypointFallbackLinks['implicit_route_unresolved'] ?? 0) >= 1, 'html-validity-blocked-entrypoint-label-counted-unresolved');
    $assert('entrypoint_label_not_home' === ($entrypointFallbackLinks['implicit_route_unresolved_targets'][0]['reason'] ?? null), 'html-validity-blocked-entrypoint-label-records-reason');
    $assert('planned_page_identity' === ($entrypointFallbackLinks['implicit_route_unresolved_targets'][0]['route_evidence'] ?? null), 'html-validity-blocked-entrypoint-label-records-route-evidence');
}
