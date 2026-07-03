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
    $assert(str_contains($html, '<blockquote class="figma-node-semantic-quote-blockquote'), 'semantic-accessibility-blockquote-emits-blockquote');
    $assert(str_contains($html, 'data-figma-node-id="semantic:icon"') && str_contains($html, 'aria-hidden="true" focusable="false"'), 'semantic-accessibility-generic-icon-decorative');
    $assert(str_contains($html, 'data-figma-node-id="semantic:logo-icon"') && str_contains($html, 'role="img" aria-label="Logo"'), 'semantic-accessibility-logo-icon-keeps-accessible-name');

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
}
