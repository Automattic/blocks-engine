<?php

declare(strict_types=1);

/**
 * @param callable(bool, string): void $assert
 */
function blocks_engine_figma_transformer_run_semantic_accessibility_contract(callable $assert, callable $fileContent): void
{
    $result = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Semantic Accessibility Fixture',
        'nodes' => array(
            array(
                'id'       => 'semantic:root',
                'type'     => 'FRAME',
                'name'     => 'Article Page',
                'children' => array(
                    array(
                        'id'       => 'semantic:comment',
                        'type'     => 'FRAME',
                        'name'     => 'Comment',
                        'children' => array(
                            array('id' => 'semantic:comment-author', 'type' => 'TEXT', 'name' => 'Author', 'characters' => 'Reader One', 'fontSize' => 16),
                            array('id' => 'semantic:comment-body', 'type' => 'TEXT', 'name' => 'Comment text', 'characters' => 'This review helped me decide.', 'fontSize' => 16),
                        ),
                    ),
                    array(
                        'id'       => 'semantic:quote',
                        'type'     => 'FRAME',
                        'name'     => 'Blockquote',
                        'children' => array(
                            array('id' => 'semantic:quote-text', 'type' => 'TEXT', 'name' => 'Quote', 'characters' => 'A useful sentence from the source.', 'fontSize' => 20),
                            array('id' => 'semantic:quote-author', 'type' => 'TEXT', 'name' => 'Text', 'characters' => 'Source Person', 'fontSize' => 14),
                        ),
                    ),
                    array(
                        'id'       => 'semantic:icon-wrap',
                        'type'     => 'FRAME',
                        'name'     => 'Search Icon Wrapper',
                        'children' => array(
                            array(
                                'id'           => 'semantic:icon',
                                'type'         => 'VECTOR',
                                'name'         => 'Icon',
                                'width'        => 16,
                                'height'       => 16,
                                'strokePaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 0, 'a' => 1))),
                                'pathData'     => 'M0 0H16V16H0Z',
                            ),
                        ),
                    ),
                    array(
                        'id'       => 'semantic:logo',
                        'type'     => 'FRAME',
                        'name'     => 'Logo',
                        'children' => array(
                            array(
                                'id'       => 'semantic:logo-icon',
                                'type'     => 'VECTOR',
                                'name'     => 'Icon',
                                'width'    => 16,
                                'height'   => 16,
                                'pathData' => 'M0 0H16V16H0Z',
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ));

    $html = $fileContent($result, 'index.html');
    $assert(str_contains($html, '<article class="figma-node-semantic-comment-comment'), 'semantic-accessibility-comment-emits-article');
    $assert(str_contains($html, 'data-figma-node-id="semantic:comment"') && str_contains($html, 'data-figma-semantic-role="comment"'), 'semantic-accessibility-comment-article-has-role-metadata');
    $assert(str_contains($html, '<blockquote class="figma-node-semantic-quote-blockquote'), 'semantic-accessibility-blockquote-emits-blockquote');
    $assert(str_contains($html, 'data-figma-node-id="semantic:icon"') && str_contains($html, 'aria-hidden="true" focusable="false"'), 'semantic-accessibility-generic-icon-decorative');
    $assert(str_contains($html, 'data-figma-node-id="semantic:logo-icon"') && str_contains($html, 'role="img" aria-label="Logo"'), 'semantic-accessibility-logo-icon-keeps-accessible-name');

    $largeBodyTextResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Large Body Text Semantics Fixture',
        'nodes' => array(
            array(
                'id'       => 'semantic:large-body-root',
                'type'     => 'FRAME',
                'name'     => 'Hero',
                'children' => array(
                    array('id' => 'semantic:large-body-title', 'type' => 'TEXT', 'name' => 'Title', 'characters' => 'Explicit heading', 'fontSize' => 64),
                    array('id' => 'semantic:large-body-body', 'type' => 'TEXT', 'name' => 'Body', 'characters' => 'Large supporting copy should remain paragraph text.', 'fontSize' => 48),
                    array('id' => 'semantic:large-body-supporting', 'type' => 'TEXT', 'name' => 'Supporting text', 'characters' => 'A second large paragraph-like text layer.', 'fontSize' => 40),
                    array('id' => 'semantic:large-body-small', 'type' => 'TEXT', 'name' => 'Caption', 'characters' => 'Caption metadata', 'fontSize' => 16),
                ),
            ),
        ),
    ));
    $largeBodyTextHtml = $fileContent($largeBodyTextResult, 'index.html');
    $assert(str_contains($largeBodyTextHtml, '<h1 class="figma-node-semantic-large-body-title-title"'), 'semantic-accessibility-explicit-title-keeps-heading');
    $assert(str_contains($largeBodyTextHtml, '<p class="figma-node-semantic-large-body-body-body"'), 'semantic-accessibility-large-body-text-paragraph');
    $assert(str_contains($largeBodyTextHtml, '<p class="figma-node-semantic-large-body-supporting-supporting-text"'), 'semantic-accessibility-large-supporting-text-paragraph');
    $assert(! str_contains($largeBodyTextHtml, '<h2 class="figma-node-semantic-large-body-body-body"'), 'semantic-accessibility-large-body-text-not-heading');

    $topLevelListLikeSectionResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Top Level List Like Section Fixture',
        'nodes' => array(
            array(
                'id'       => 'semantic:list-section-root',
                'type'     => 'FRAME',
                'name'     => 'Home',
                'width'    => 1200,
                'height'   => 1000,
                'children' => array(
                    array(
                        'id'       => 'semantic:list-section-hero',
                        'type'     => 'FRAME',
                        'name'     => 'Hero',
                        'width'    => 1200,
                        'height'   => 300,
                        'children' => array(array('id' => 'semantic:list-section-hero-title', 'type' => 'TEXT', 'name' => 'Title', 'characters' => 'Hero', 'fontSize' => 48)),
                    ),
                    array(
                        'id'       => 'semantic:pricing-section',
                        'type'     => 'FRAME',
                        'name'     => 'Pricing',
                        'width'    => 1200,
                        'height'   => 500,
                        'children' => array(
                            array('id' => 'semantic:pricing-card-a', 'type' => 'FRAME', 'name' => 'Basic Card', 'children' => array(array('id' => 'semantic:pricing-card-a-title', 'type' => 'TEXT', 'name' => 'Title', 'characters' => 'Basic', 'fontSize' => 24))),
                            array('id' => 'semantic:pricing-card-b', 'type' => 'FRAME', 'name' => 'Pro Card', 'children' => array(array('id' => 'semantic:pricing-card-b-title', 'type' => 'TEXT', 'name' => 'Title', 'characters' => 'Pro', 'fontSize' => 24))),
                            array('id' => 'semantic:pricing-card-c', 'type' => 'FRAME', 'name' => 'Team Card', 'children' => array(array('id' => 'semantic:pricing-card-c-title', 'type' => 'TEXT', 'name' => 'Title', 'characters' => 'Team', 'fontSize' => 24))),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $topLevelListLikeSectionHtml = $fileContent($topLevelListLikeSectionResult, 'index.html');
    $assert(str_contains($topLevelListLikeSectionHtml, '<section class="figma-node-semantic-pricing-section-pricing"'), 'semantic-accessibility-top-level-list-like-frame-keeps-section-landmark');
    $assert(! str_contains($topLevelListLikeSectionHtml, '<ul class="figma-node-semantic-pricing-section-pricing"'), 'semantic-accessibility-top-level-list-like-frame-not-ul');
    $assert(! str_contains($topLevelListLikeSectionHtml, '<li class="figma-node-semantic-pricing-card-a-basic-card"'), 'semantic-accessibility-top-level-list-like-frame-children-not-li');

    $articleCardRoleResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Article Card Role Fixture',
        'nodes' => array(
            array(
                'id'       => 'semantic:article-card-root',
                'type'     => 'FRAME',
                'name'     => 'Archive',
                'children' => array(
                    array(
                        'id'       => 'semantic:query-loop',
                        'type'     => 'FRAME',
                        'name'     => 'Query Loop',
                        'children' => array(
                            array(
                                'id'       => 'semantic:article-card',
                                'type'     => 'FRAME',
                                'name'     => 'Post Card',
                                'children' => array(
                                    array('id' => 'semantic:article-card-title', 'type' => 'TEXT', 'name' => 'Title', 'characters' => 'Post title', 'fontSize' => 24),
                                    array('id' => 'semantic:article-card-excerpt', 'type' => 'TEXT', 'name' => 'Excerpt', 'characters' => 'Post excerpt copy.', 'fontSize' => 16),
                                    array('id' => 'semantic:article-card-date', 'type' => 'TEXT', 'name' => 'Date', 'characters' => 'July 6, 2026', 'fontSize' => 14),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $articleCardRoleHtml = $fileContent($articleCardRoleResult, 'index.html');
    $assert(str_contains($articleCardRoleHtml, 'data-figma-node-id="semantic:query-loop"') && str_contains($articleCardRoleHtml, 'data-figma-semantic-role="query"'), 'semantic-accessibility-query-section-has-role-metadata');
    $assert(str_contains($articleCardRoleHtml, '<article class="figma-node-semantic-article-card-post-card'), 'semantic-accessibility-post-card-emits-article');
    $assert(str_contains($articleCardRoleHtml, 'data-figma-node-id="semantic:article-card"') && str_contains($articleCardRoleHtml, 'data-figma-semantic-role="post-card"'), 'semantic-accessibility-post-card-article-has-role-metadata');

    $booleanLabelResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Decorative Boolean Label Fixture',
        'nodes' => array(
            array(
                'id'       => 'boolean:subtract',
                'type'     => 'BOOLEAN_OPERATION',
                'name'     => 'Subtract',
                'width'    => 120,
                'height'   => 80,
                'children' => array(
                    array('id' => 'boolean:a', 'type' => 'RECTANGLE', 'name' => 'Rectangle', 'width' => 120, 'height' => 80, 'fills' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 0, 'a' => 1)))),
                    array('id' => 'boolean:b', 'type' => 'RECTANGLE', 'name' => 'Rectangle', 'x' => 20, 'y' => 20, 'width' => 40, 'height' => 40, 'fills' => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 1, 'b' => 1, 'a' => 1)))),
                ),
            ),
        ),
    ));
    $booleanLabelHtml = $fileContent($booleanLabelResult, 'index.html');
    $assert(str_contains($booleanLabelHtml, 'data-figma-node-id="boolean:subtract"') && str_contains($booleanLabelHtml, 'aria-hidden="true" focusable="false"'), 'semantic-accessibility-generic-subtract-vector-decorative');

    $largeVectorUnderlayResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Large Vector Underlay Fixture',
        'nodes' => array(
            array(
                'id'       => 'underlay:root',
                'type'     => 'FRAME',
                'name'     => 'Background Hero',
                'width'    => 1200,
                'height'   => 600,
                'children' => array(
                    array(
                        'id'       => 'underlay:vector-cluster',
                        'type'     => 'GROUP',
                        'name'     => 'Group 127',
                        'width'    => 1100,
                        'height'   => 560,
                        'children' => array(array('id' => 'underlay:shape', 'type' => 'VECTOR', 'name' => 'Vector 21', 'width' => 1100, 'height' => 560, 'fillGeometry' => array(array('path' => 'M0 0L1100 0L1100 560L0 560Z')), 'fills' => array(array('type' => 'SOLID', 'color' => array('r' => 0.1, 'g' => 0.2, 'b' => 1, 'a' => 0.03))))),
                    ),
                    array('id' => 'underlay:title', 'type' => 'TEXT', 'name' => 'Title', 'x' => 64, 'y' => 96, 'width' => 420, 'height' => 80, 'characters' => 'Readable foreground text', 'fontSize' => 40),
                ),
            ),
        ),
    ));
    $largeVectorUnderlayCss = $fileContent($largeVectorUnderlayResult, 'style.css');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $largeVectorUnderlayCss, '.figma-node-underlay-vector-cluster-group-127', array('position:absolute', 'z-index:0', 'pointer-events:none'), 'semantic-accessibility-large-vector-background-underlay');

    $layeringResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Header Layering Fixture',
        'nodes' => array(
            array(
                'id'       => 'layer:root',
                'type'     => 'FRAME',
                'name'     => 'Home',
                'width'    => 1440,
                'height'   => 1200,
                'children' => array(
                    array(
                        'id'     => 'layer:header',
                        'type'   => 'INSTANCE',
                        'name'   => 'Site Header',
                        'x'      => 0,
                        'y'      => 0,
                        'width'  => 1440,
                        'height' => 145,
                    ),
                    array(
                        'id'       => 'layer:hero',
                        'type'     => 'FRAME',
                        'name'     => 'Hero Artwork Group',
                        'x'        => 0,
                        'y'        => 61,
                        'width'    => 1440,
                        'height'   => 758,
                        'children' => array(
                            array(
                                'id'     => 'layer:hero:image',
                                'type'   => 'RECTANGLE',
                                'name'   => 'Hero Image',
                                'x'      => 0,
                                'y'      => 0,
                                'width'  => 1440,
                                'height' => 758,
                                'fills'  => array(array('type' => 'SOLID', 'color' => array('r' => 0.1, 'g' => 0.2, 'b' => 0.3, 'a' => 1))),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ));

    $layeringCss = $fileContent($layeringResult, 'style.css');
    $assert(1 === preg_match('/\.figma-node-layer-header-site-header\{[^}]*z-index:2/s', $layeringCss), 'semantic-accessibility-top-header-outranks-overlapping-hero');
    $assert(1 === preg_match('/\.figma-node-layer-hero-hero-artwork-group\{[^}]*z-index:1/s', $layeringCss), 'semantic-accessibility-overlapping-hero-keeps-base-stack-rank');

    $sourceZIndexLayeringResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Source Z Index Header Layering Fixture',
        'nodes' => array(
            array(
                'id'         => 'source-z:root',
                'type'       => 'FRAME',
                'name'       => 'Home',
                'width'      => 1440,
                'height'     => 1200,
                'layoutMode' => 'VERTICAL',
                'children'   => array(
                    array(
                        'id'     => 'source-z:hero',
                        'type'   => 'FRAME',
                        'name'   => 'Hero',
                        'width'  => 1440,
                        'height' => 600,
                        'layout' => array('z_index' => 1),
                    ),
                    array(
                        'id'     => 'source-z:header',
                        'type'   => 'FRAME',
                        'name'   => 'Site Header',
                        'width'  => 1440,
                        'height' => 120,
                        'layout' => array('z_index' => 9),
                    ),
                ),
            ),
        ),
    ));

    $sourceZIndexLayeringCss = $fileContent($sourceZIndexLayeringResult, 'style.css');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $sourceZIndexLayeringCss, '.figma-node-source-z-hero-hero', array('position:relative', 'z-index:1'), 'semantic-accessibility-source-z-index-hero-is-effective');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $sourceZIndexLayeringCss, '.figma-node-source-z-header-site-header', array('position:relative', 'z-index:9'), 'semantic-accessibility-source-z-index-header-is-effective');

    $chromeResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Generic Chrome Fixture',
        'nodes' => array(
            array(
                'id'       => 'chrome:root',
                'type'     => 'FRAME',
                'name'     => 'Home',
                'width'    => 1440,
                'height'   => 1200,
                'children' => array(
                    array(
                        'id'       => 'chrome:top',
                        'type'     => 'FRAME',
                        'name'     => 'Top Bar',
                        'x'        => 0,
                        'y'        => 0,
                        'width'    => 1440,
                        'height'   => 96,
                        'children' => array(
                            array('id' => 'chrome:brand', 'type' => 'TEXT', 'name' => 'Brand Logo', 'characters' => 'Brand', 'fontSize' => 20),
                            array('id' => 'chrome:home', 'type' => 'TEXT', 'name' => 'Menu Item', 'characters' => 'Home', 'fontSize' => 16, 'hyperlink' => '/'),
                        ),
                    ),
                    array(
                        'id'       => 'chrome:social',
                        'type'     => 'FRAME',
                        'name'     => 'Social Links',
                        'x'        => 0,
                        'y'        => 980,
                        'width'    => 120,
                        'height'   => 32,
                        'children' => array(
                            array('id' => 'chrome:facebook', 'type' => 'VECTOR', 'name' => 'Facebook', 'width' => 24, 'height' => 24, 'pathData' => 'M0 0H24V24H0Z', 'hyperlink' => 'https://facebook.example'),
                            array('id' => 'chrome:instagram', 'type' => 'VECTOR', 'name' => 'Instagram', 'width' => 24, 'height' => 24, 'pathData' => 'M0 0H24V24H0Z', 'hyperlink' => 'https://instagram.example'),
                        ),
                    ),
                    array(
                        'id'       => 'chrome:cta',
                        'type'     => 'FRAME',
                        'name'     => 'Call to Action',
                        'x'        => 0,
                        'y'        => 1030,
                        'width'    => 240,
                        'height'   => 56,
                        'children' => array(array('id' => 'chrome:cta-label', 'type' => 'TEXT', 'name' => 'CTA Label', 'characters' => 'Book now', 'fontSize' => 16)),
                    ),
                    array(
                        'id'       => 'chrome:bottom',
                        'type'     => 'FRAME',
                        'name'     => 'Bottom Bar',
                        'x'        => 0,
                        'y'        => 1120,
                        'width'    => 1440,
                        'height'   => 80,
                        'children' => array(array('id' => 'chrome:legal', 'type' => 'TEXT', 'name' => 'Legal', 'characters' => 'Copyright 2026. All rights reserved.', 'fontSize' => 12)),
                    ),
                ),
            ),
        ),
    ));

    $chromeHtml = $fileContent($chromeResult, 'index.html');
    $assert(str_contains($chromeHtml, '<header class="figma-node-chrome-top-top-bar"'), 'semantic-accessibility-generic-top-chrome-emits-header');
    $assert(str_contains($chromeHtml, '<nav class="figma-node-chrome-social-social-links"'), 'semantic-accessibility-social-cluster-emits-nav');
    $assert(str_contains($chromeHtml, '<a class="figma-link" href="https://facebook.example" data-figma-link-type="url">') && str_contains($chromeHtml, '<a class="figma-link" href="https://instagram.example" data-figma-link-type="url">'), 'semantic-accessibility-social-icons-with-real-urls-emit-anchors');
    $assert(! str_contains($chromeHtml, 'href="#" data-figma-link-type="url"'), 'semantic-accessibility-social-icons-do-not-emit-placeholder-anchors');
    $assert(str_contains($chromeHtml, '<div class="figma-node-chrome-cta-call-to-action"'), 'semantic-accessibility-cta-group-stays-structural');
    $assert(str_contains($chromeHtml, '<footer class="figma-node-chrome-bottom-bottom-bar"'), 'semantic-accessibility-generic-bottom-chrome-emits-footer');

    $footerOverflowResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Footer Overflow Fixture',
        'nodes' => array(
            array(
                'id'       => 'chrome-overflow:page',
                'type'     => 'FRAME',
                'name'     => 'Page',
                'width'    => 1440,
                'height'   => 720,
                'children' => array(
                    array(
                        'id'       => 'chrome-overflow:footer',
                        'type'     => 'FRAME',
                        'name'     => 'Footer',
                        'x'        => 0,
                        'y'        => 520,
                        'width'    => 1440,
                        'height'   => 120,
                        'children' => array(
                            array('id' => 'chrome-overflow:links', 'type' => 'TEXT', 'name' => 'Footer Links', 'x' => 320, 'y' => 24, 'width' => 900, 'height' => 24, 'characters' => 'News      About      Services      Contact', 'fontSize' => 18),
                            array('id' => 'chrome-overflow:bar', 'type' => 'RECTANGLE', 'name' => 'Rectangle', 'x' => 0, 'y' => 120, 'width' => 1440, 'height' => 32, 'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0.5, 'g' => 0.8, 'b' => 0.9, 'a' => 1), 'visible' => true))),
                            array('id' => 'chrome-overflow:legal', 'type' => 'TEXT', 'name' => 'Legal', 'x' => 520, 'y' => 96, 'width' => 860, 'height' => 14, 'characters' => 'Contact: 555-555-5555        Location: Example Clinic        Copyright 2026. All rights reserved.', 'fontSize' => 12),
                        ),
                    ),
                ),
            ),
        ),
    ));

    $footerOverflowHtml = $fileContent($footerOverflowResult, 'index.html');
    $footerOverflowCss = $fileContent($footerOverflowResult, 'style.css');
    $assert(str_contains($footerOverflowHtml, '<footer class="figma-node-chrome-overflow-footer-footer"'), 'semantic-accessibility-named-footer-with-legal-evidence-emits-footer');
    $assert(str_contains($footerOverflowCss, '.figma-node-chrome-overflow-footer-footer{') && str_contains($footerOverflowCss, 'min-height:152px'), 'semantic-accessibility-footer-reserves-protruding-bottom-bar');
    $assert(str_contains($footerOverflowCss, '.figma-node-chrome-overflow-links-footer-links{') && str_contains($footerOverflowCss, 'white-space:pre-wrap'), 'semantic-accessibility-footer-spaced-text-preserves-layout-spacing');
}
