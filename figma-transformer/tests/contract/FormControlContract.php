<?php

declare(strict_types=1);

/**
 * @param callable(bool, string): void $assert
 */
function blocks_engine_figma_transformer_run_form_control_contract(callable $assert, callable $fileContent): void
{
    $result = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Form Control Fixture',
        'nodes' => array(
            array(
                'id'         => 'form:root',
                'type'       => 'FRAME',
                'name'       => 'Newsletter section',
                'width'      => 640,
                'height'     => 160,
                'layoutMode' => 'HORIZONTAL',
                'itemSpacing' => 16,
                'children'   => array(
                    array(
                        'id'              => 'form:input',
                        'type'            => 'FRAME',
                        'name'            => 'Input',
                        'width'           => 280,
                        'height'          => 46,
                        'layoutMode'      => 'HORIZONTAL',
                        'primaryAxisAlignItems' => 'CENTER',
                        'counterAxisAlignItems' => 'CENTER',
                        'paddingTop'      => 12,
                        'paddingRight'    => 18,
                        'paddingBottom'   => 12,
                        'paddingLeft'     => 18,
                        'cornerRadius'    => 4,
                        'fills'           => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 1, 'b' => 1, 'a' => 1))),
                        'children'        => array(
                            array(
                                'id'         => 'form:input:text',
                                'type'       => 'TEXT',
                                'name'       => 'Text',
                                'characters' => 'Your email address',
                                'fontSize'   => 16,
                            ),
                        ),
                    ),
                    array(
                        'id'           => 'form:button',
                        'type'         => 'FRAME',
                        'name'         => 'Button',
                        'width'        => 108,
                        'height'       => 46,
                        'cornerRadius' => 999,
                        'fills'        => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 0.8, 'b' => 0, 'a' => 1))),
                        'children'     => array(
                            array(
                                'id'         => 'form:button:text',
                                'type'       => 'TEXT',
                                'name'       => 'Subscribe',
                                'characters' => 'Sign Up',
                                'fontSize'   => 16,
                            ),
                        ),
                    ),
                    array(
                        'id'              => 'form:search',
                        'type'            => 'FRAME',
                        'name'            => 'Search field',
                        'width'           => 220,
                        'height'          => 46,
                        'layoutMode'      => 'HORIZONTAL',
                        'paddingTop'      => 12,
                        'paddingRight'    => 18,
                        'paddingBottom'   => 12,
                        'paddingLeft'     => 18,
                        'cornerRadius'    => 4,
                        'fills'           => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 1, 'b' => 1, 'a' => 1))),
                        'children'        => array(
                            array(
                                'id'         => 'form:search:text',
                                'type'       => 'TEXT',
                                'name'       => 'Search placeholder',
                                'characters' => 'Search this site',
                                'fontSize'   => 16,
                            ),
                        ),
                    ),
                    array(
                        'id'              => 'form:search-icon',
                        'type'            => 'FRAME',
                        'name'            => 'Search field with icon',
                        'width'           => 220,
                        'height'          => 46,
                        'layoutMode'      => 'HORIZONTAL',
                        'primaryAxisAlignItems' => 'CENTER',
                        'counterAxisAlignItems' => 'CENTER',
                        'paddingTop'      => 12,
                        'paddingRight'    => 18,
                        'paddingBottom'   => 12,
                        'paddingLeft'     => 18,
                        'cornerRadius'    => 4,
                        'fills'           => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 1, 'b' => 1, 'a' => 1))),
                        'children'        => array(
                            array(
                                'id'           => 'form:search-icon:icon',
                                'type'         => 'ELLIPSE',
                                'name'         => 'Search icon',
                                'width'        => 16,
                                'height'       => 16,
                                'strokePaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 0, 'a' => 1))),
                            ),
                            array(
                                'id'         => 'form:search-icon:text',
                                'type'       => 'TEXT',
                                'name'       => 'Search placeholder',
                                'characters' => 'Search for...',
                                'fontSize'   => 16,
                            ),
                        ),
                    ),
                    array(
                        'id'              => 'form:comment',
                        'type'            => 'FRAME',
                        'name'            => 'Comment textarea',
                        'width'           => 420,
                        'height'          => 128,
                        'layoutMode'      => 'VERTICAL',
                        'paddingTop'      => 14,
                        'paddingRight'    => 18,
                        'paddingBottom'   => 14,
                        'paddingLeft'     => 18,
                        'cornerRadius'    => 4,
                        'fills'           => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 1, 'b' => 1, 'a' => 1))),
                        'children'        => array(
                            array(
                                'id'         => 'form:comment:text',
                                'type'       => 'TEXT',
                                'name'       => 'Comment placeholder',
                                'characters' => 'Leave a comment',
                                'fontSize'   => 16,
                            ),
                        ),
                    ),
                    array(
                        'id'           => 'form:submit',
                        'type'         => 'FRAME',
                        'name'         => 'Submit button',
                        'width'        => 132,
                        'height'       => 46,
                        'cornerRadius' => 999,
                        'fills'        => array(array('type' => 'SOLID', 'color' => array('r' => 0.1, 'g' => 0.1, 'b' => 0.1, 'a' => 1))),
                        'children'     => array(
                            array(
                                'id'         => 'form:submit:text',
                                'type'       => 'TEXT',
                                'name'       => 'Submit label',
                                'characters' => 'Post Comment',
                                'fontSize'   => 16,
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ));

    $html = $fileContent($result, 'index.html');
    $css = $fileContent($result, 'style.css');

    $assert(str_contains($html, '<input class="figma-node-form-input-input"'), 'form-control-input-emits-input-tag');
    $assert(str_contains($html, 'type="email"'), 'form-control-input-infers-email-type');
    $assert(str_contains($html, 'placeholder="Your email address"'), 'form-control-input-uses-placeholder');
    $assert(! str_contains($html, '<button class="figma-node-form-input-input"'), 'form-control-input-does-not-emit-button');
    $assert(! str_contains($html, 'data-figma-node-id="form:input:text"'), 'form-control-input-suppresses-presentational-text-child');
    $assert(str_contains($html, '<button class="figma-node-form-button-button"'), 'form-control-button-still-emits-button');
    $assert(str_contains($html, '<input class="figma-node-form-search-search-field"'), 'form-control-search-emits-input-tag');
    $assert(str_contains($html, 'type="search"'), 'form-control-search-infers-search-type');
    $assert(str_contains($html, 'placeholder="Search this site"'), 'form-control-search-uses-placeholder');
    $assert(str_contains($html, '<div class="figma-node-form-search-icon-search-field-with-icon"'), 'form-control-search-icon-keeps-visual-wrapper');
    $assert(str_contains($html, 'data-figma-node-id="form:search-icon:icon"'), 'form-control-search-icon-preserves-icon-child');
    $assert(str_contains($html, '<input class="figma-node-form-search-icon-search-field-with-icon__control" data-figma-synthetic-control="input" type="search" name="s-form-search-icon" placeholder="Search for..."'), 'form-control-search-icon-emits-nested-input');
    $assert(! str_contains($html, 'data-figma-node-id="form:search-icon:text"'), 'form-control-search-icon-suppresses-presentational-placeholder');
    $assert(str_contains($css, '.figma-node-form-search-icon-search-field-with-icon__control{border:0;background:transparent;padding:0;margin:0;min-width:0;flex:1;font:inherit;color:inherit;outline:none}'), 'form-control-search-icon-input-reset-css');
    $assert(str_contains($html, '<textarea class="figma-node-form-comment-comment-textarea"'), 'form-control-comment-emits-textarea-tag');
    $assert(str_contains($html, 'placeholder="Leave a comment"'), 'form-control-comment-uses-placeholder');
    $assert(! str_contains($html, 'data-figma-node-id="form:comment:text"'), 'form-control-comment-suppresses-presentational-text-child');
    $assert(str_contains($html, '<button class="figma-node-form-submit-submit-button"'), 'form-control-submit-emits-button-tag');
    $assert(str_contains($html, 'type="submit"'), 'form-control-submit-infers-submit-type');
    $assert(str_contains($css, '.figma-node-form-input-input{width:280px;height:46px;'), 'form-control-input-preserves-visual-css');
    $assert(str_contains($css, '.figma-node-form-comment-comment-textarea{width:420px;height:128px;'), 'form-control-textarea-preserves-visual-css');

    $nestedInputShellResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Nested Input Shell Fixture',
        'nodes' => array(
            array(
                'id'       => 'nested-input:root',
                'type'     => 'FRAME',
                'name'     => 'Newsletter Signup',
                'width'    => 480,
                'height'   => 120,
                'children' => array(
                    array(
                        'id'           => 'nested-input:field',
                        'type'         => 'FRAME',
                        'name'         => 'Input',
                        'width'        => 280,
                        'height'       => 46,
                        'layoutMode'   => 'HORIZONTAL',
                        'cornerRadius' => 4,
                        'fills'        => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 1, 'b' => 1, 'a' => 1))),
                        'children'     => array(
                            array(
                                'id'           => 'nested-input:inner-field',
                                'type'         => 'FRAME',
                                'name'         => 'Input',
                                'width'        => 278,
                                'height'       => 44,
                                'layoutMode'   => 'HORIZONTAL',
                                'cornerRadius' => 4,
                                'fills'        => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 1, 'b' => 1, 'a' => 1))),
                                'children'     => array(
                                    array('id' => 'nested-input:inner-placeholder', 'type' => 'TEXT', 'name' => 'Text', 'characters' => 'Your email address', 'fontSize' => 16),
                                ),
                            ),
                        ),
                    ),
                    array('id' => 'nested-input:button', 'type' => 'FRAME', 'name' => 'Submit button', 'width' => 120, 'height' => 46, 'children' => array(
                        array('id' => 'nested-input:button-text', 'type' => 'TEXT', 'name' => 'Text', 'characters' => 'Subscribe'),
                    )),
                ),
            ),
        ),
    ));
    $nestedInputShellHtml = $fileContent($nestedInputShellResult, 'index.html');
    $assert(1 === substr_count($nestedInputShellHtml, 'data-figma-synthetic-control="input"'), 'form-control-nested-input-shell-emits-single-control');
    $assert(! str_contains($nestedInputShellHtml, 'data-figma-node-id="nested-input:inner-field"'), 'form-control-nested-input-shell-suppresses-child-control-shell');

    $nestedFormResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Nested Form Guard Fixture',
        'nodes' => array(
            array(
                'id'       => 'nested-form:newsletter',
                'type'     => 'FRAME',
                'name'     => 'Newsletter Signup',
                'children' => array(
                    array('id' => 'nested-form:input', 'type' => 'FRAME', 'name' => 'Input', 'width' => 240, 'height' => 44, 'layoutMode' => 'HORIZONTAL', 'cornerRadius' => 4, 'fills' => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 1, 'b' => 1, 'a' => 1))), 'children' => array(
                        array('id' => 'nested-form:input:text', 'type' => 'TEXT', 'name' => 'Text', 'characters' => 'Your email address'),
                    )),
                    array('id' => 'nested-form:row', 'type' => 'FRAME', 'name' => 'Signup form row', 'width' => 360, 'height' => 44, 'children' => array(
                        array('id' => 'nested-form:button', 'type' => 'FRAME', 'name' => 'Submit button', 'width' => 100, 'height' => 44, 'children' => array(
                            array('id' => 'nested-form:button:text', 'type' => 'TEXT', 'name' => 'Subscribe', 'characters' => 'Subscribe'),
                        )),
                    )),
                ),
            ),
        ),
    ));
    $nestedFormHtml = $fileContent($nestedFormResult, 'index.html');
    $assert(1 === substr_count($nestedFormHtml, '<form '), 'form-control-nested-form-guard-single-form');
    $assert(str_contains($nestedFormHtml, '<div class="figma-node-nested-form-row-signup-form-row"'), 'form-control-nested-form-guard-descendant-form-row-div');

    $labeledResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Labeled Control Fixture',
        'nodes' => array(
            array(
                'id'       => 'labeled:root',
                'type'     => 'FRAME',
                'name'     => 'Comment Form',
                'children' => array(
                    array(
                        'id'       => 'labeled:name-field',
                        'type'     => 'FRAME',
                        'name'     => 'Input',
                        'children' => array(
                            array('id' => 'labeled:name-label', 'type' => 'TEXT', 'name' => 'Label', 'characters' => 'Name *', 'fontSize' => 14),
                            array('id' => 'labeled:name-input', 'type' => 'FRAME', 'name' => 'Input', 'width' => 280, 'height' => 46, 'cornerRadius' => 4, 'fills' => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 1, 'b' => 1, 'a' => 1))), 'children' => array(
                                array('id' => 'labeled:name-empty', 'type' => 'TEXT', 'name' => 'Text', 'characters' => '', 'fontSize' => 16),
                            )),
                        ),
                    ),
                    array(
                        'id'       => 'labeled:email-field',
                        'type'     => 'FRAME',
                        'name'     => 'Input',
                        'children' => array(
                            array('id' => 'labeled:email-label', 'type' => 'TEXT', 'name' => 'Label', 'characters' => 'Email *', 'fontSize' => 14),
                            array('id' => 'labeled:email-input', 'type' => 'FRAME', 'name' => 'Input', 'width' => 280, 'height' => 46, 'cornerRadius' => 4, 'fills' => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 1, 'b' => 1, 'a' => 1))), 'children' => array(
                                array('id' => 'labeled:email-empty', 'type' => 'TEXT', 'name' => 'Text', 'characters' => '', 'fontSize' => 16),
                            )),
                        ),
                    ),
                    array(
                        'id'       => 'labeled:comment-field',
                        'type'     => 'FRAME',
                        'name'     => 'Input',
                        'children' => array(
                            array('id' => 'labeled:comment-label', 'type' => 'TEXT', 'name' => 'Label', 'characters' => 'Comment', 'fontSize' => 14),
                            array('id' => 'labeled:comment-input', 'type' => 'FRAME', 'name' => 'Input', 'width' => 420, 'height' => 128, 'cornerRadius' => 4, 'fills' => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 1, 'b' => 1, 'a' => 1))), 'children' => array(
                                array('id' => 'labeled:comment-empty', 'type' => 'TEXT', 'name' => 'Text', 'characters' => '', 'fontSize' => 16),
                            )),
                        ),
                    ),
                    array('id' => 'labeled:submit', 'type' => 'FRAME', 'name' => 'Submit button', 'width' => 140, 'height' => 46, 'cornerRadius' => 999, 'fills' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 0, 'a' => 1))), 'children' => array(
                        array('id' => 'labeled:submit-text', 'type' => 'TEXT', 'name' => 'Text', 'characters' => 'Post Comment', 'fontSize' => 16),
                    )),
                ),
            ),
        ),
    ));
    $labeledHtml = $fileContent($labeledResult, 'index.html');
    $assert(str_contains($labeledHtml, 'data-figma-node-id="labeled:name-input"') && str_contains($labeledHtml, 'aria-label="Name *"'), 'form-control-nearby-label-names-text-input');
    $assert(str_contains($labeledHtml, 'data-figma-node-id="labeled:email-input"') && str_contains($labeledHtml, 'type="email" name="email"') && str_contains($labeledHtml, 'aria-label="Email *"'), 'form-control-nearby-label-infers-email-input');
    $assert(str_contains($labeledHtml, '<textarea class="figma-node-labeled-comment-input-input"') && str_contains($labeledHtml, 'aria-label="Comment"'), 'form-control-nearby-label-infers-comment-textarea');

    $layeredButtonResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Layered Button Fixture',
        'nodes' => array(
            array(
                'id'       => 'layered:root',
                'type'     => 'FRAME',
                'name'     => 'Buttons',
                'width'    => 520,
                'height'   => 140,
                'children' => array(
                    array(
                        'id'       => 'layered:button',
                        'type'     => 'FRAME',
                        'name'     => 'Book now button',
                        'width'    => 180,
                        'height'   => 48,
                        'children' => array(
                            array(
                                'id'           => 'layered:button:bg',
                                'type'         => 'RECTANGLE',
                                'name'         => 'Button background rectangle',
                                'width'        => 180,
                                'height'       => 48,
                                'x'            => 0,
                                'y'            => 0,
                                'cornerRadius' => 12,
                                'fillPaints'   => array(
                                    array('type' => 'SOLID', 'color' => array('r' => 0.05, 'g' => 0.32, 'b' => 0.48, 'a' => 1)),
                                    array('type' => 'SOLID', 'color' => array('r' => 0.05, 'g' => 0.32, 'b' => 0.48, 'a' => 0.4)),
                                ),
                                'strokePaints' => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 1, 'b' => 1, 'a' => 1))),
                                'strokeWeight' => 2,
                                'strokeAlign'  => 'INSIDE',
                            ),
                            array(
                                'id'           => 'layered:button:icon',
                                'type'         => 'ELLIPSE',
                                'name'         => 'Calendar icon',
                                'width'        => 14,
                                'height'       => 14,
                                'x'            => 16,
                                'y'            => 17,
                                'strokePaints' => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 1, 'b' => 1, 'a' => 1))),
                            ),
                            array(
                                'id'         => 'layered:button:text',
                                'type'       => 'TEXT',
                                'name'       => 'Button label',
                                'characters' => 'Book now',
                                'fontSize'   => 16,
                                'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 1, 'b' => 1, 'a' => 1))),
                            ),
                        ),
                    ),
                    array(
                        'id'       => 'layered:submit',
                        'type'     => 'FRAME',
                        'name'     => 'Subscribe button',
                        'width'    => 140,
                        'height'   => 44,
                        'children' => array(
                            array('id' => 'layered:submit:bg', 'type' => 'RECTANGLE', 'name' => 'Rectangle', 'width' => 140, 'height' => 44, 'x' => 0, 'y' => 0, 'cornerRadius' => 999, 'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 1, 'b' => 1, 'a' => 1)))),
                            array('id' => 'layered:submit:text', 'type' => 'TEXT', 'name' => 'button one', 'characters' => 'Subscribe', 'fontSize' => 16),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $layeredButtonHtml = $fileContent($layeredButtonResult, 'index.html');
    $layeredButtonCss = $fileContent($layeredButtonResult, 'style.css');
    $assert(str_contains($layeredButtonHtml, '<button class="figma-node-layered-button-book-now-button"') && str_contains($layeredButtonHtml, 'type="button"'), 'layered-button-emits-legitimate-button');
    $assert(str_contains($layeredButtonHtml, 'data-figma-node-id="layered:button:text"') && str_contains($layeredButtonHtml, '>Book now</span>'), 'layered-button-keeps-text-label');
    $assert(str_contains($layeredButtonHtml, 'data-figma-node-id="layered:button:icon"'), 'layered-button-preserves-meaningful-icon-layer');
    $assert(! str_contains($layeredButtonHtml, 'data-figma-node-id="layered:button:bg"'), 'layered-button-suppresses-decorative-background-child');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $layeredButtonCss, '.figma-node-layered-button-book-now-button', array('background:#0d527a', 'border-radius:12px', 'border:2px solid #ffffff', 'box-sizing:border-box'), 'layered-button-composes-background-child-styles');
    $assert(str_contains($layeredButtonHtml, '<button class="figma-node-layered-submit-subscribe-button"') && str_contains($layeredButtonHtml, 'type="button" data-figma-action-intent="submit"'), 'layered-button-outside-form-emits-safe-action-metadata');
    $assert(! str_contains($layeredButtonHtml, 'data-figma-node-id="layered:submit:bg"'), 'layered-submit-suppresses-decorative-background-child');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $layeredButtonCss, '.figma-node-layered-submit-subscribe-button', array('background:#ffffff', 'border-radius:999px'), 'layered-submit-composes-background-child-styles');

    $newsletterResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Newsletter Spatial Labels Fixture',
        'nodes' => array(
            array(
                'id'       => 'newsletter:root',
                'type'     => 'FRAME',
                'name'     => 'Newsletter Signup',
                'width'    => 720,
                'height'   => 220,
                'children' => array(
                    array('id' => 'newsletter:bg', 'type' => 'RECTANGLE', 'name' => 'Background', 'x' => 0, 'y' => 0, 'width' => 720, 'height' => 220, 'fills' => array(array('type' => 'SOLID', 'color' => array('r' => 0.2, 'g' => 0.3, 'b' => 0.1, 'a' => 1)))),
                    array('id' => 'newsletter:title', 'type' => 'TEXT', 'name' => 'Heading', 'x' => 220, 'y' => 36, 'width' => 280, 'height' => 32, 'characters' => 'Subscribe to Updates', 'fontSize' => 28),
                    array('id' => 'newsletter:helper', 'type' => 'TEXT', 'name' => 'Description', 'x' => 90, 'y' => 78, 'width' => 540, 'height' => 24, 'characters' => 'Get occasional updates from our team.', 'fontSize' => 16),
                    array('id' => 'newsletter:name-box', 'type' => 'RECTANGLE', 'name' => 'Rectangle', 'x' => 90, 'y' => 130, 'width' => 220, 'height' => 44, 'fills' => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 1, 'b' => 1, 'a' => 1)))),
                    array('id' => 'newsletter:email-box', 'type' => 'RECTANGLE', 'name' => 'Rectangle', 'x' => 330, 'y' => 130, 'width' => 220, 'height' => 44, 'fills' => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 1, 'b' => 1, 'a' => 1)))),
                    array('id' => 'newsletter:name-label', 'type' => 'TEXT', 'name' => 'Skolar Latin Regular', 'x' => 112, 'y' => 140, 'width' => 48, 'height' => 20, 'characters' => 'Name', 'fontSize' => 16),
                    array('id' => 'newsletter:email-label', 'type' => 'TEXT', 'name' => 'Skolar Latin Regular', 'x' => 352, 'y' => 140, 'width' => 48, 'height' => 20, 'characters' => 'Email', 'fontSize' => 16),
                    array('id' => 'newsletter:submit', 'type' => 'FRAME', 'name' => 'Button One', 'x' => 570, 'y' => 130, 'width' => 110, 'height' => 44, 'cornerRadius' => 4, 'fills' => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 1, 'b' => 1, 'a' => 1))), 'children' => array(
                        array('id' => 'newsletter:submit-text', 'type' => 'TEXT', 'name' => 'button one', 'characters' => 'Subscribe', 'fontSize' => 16),
                    )),
                ),
            ),
        ),
    ));
    $newsletterHtml = $fileContent($newsletterResult, 'index.html');
    $assert(str_contains($newsletterHtml, '<form class="figma-node-newsletter-root-newsletter-signup"'), 'form-control-newsletter-spatial-labels-wraps-form');
    $assert(str_contains($newsletterHtml, '<input class="figma-node-newsletter-name-box-rectangle"') && str_contains($newsletterHtml, 'placeholder="Name"'), 'form-control-newsletter-spatial-label-name-input');
    $assert(str_contains($newsletterHtml, '<input class="figma-node-newsletter-email-box-rectangle"') && str_contains($newsletterHtml, 'type="email" name="email" placeholder="Email"'), 'form-control-newsletter-spatial-label-email-input');
    $assert(! str_contains($newsletterHtml, 'data-figma-node-id="newsletter:name-label"') && ! str_contains($newsletterHtml, 'data-figma-node-id="newsletter:email-label"'), 'form-control-newsletter-spatial-label-text-suppressed');
    $assert(str_contains($newsletterHtml, '<button class="figma-node-newsletter-submit-button-one"') && str_contains($newsletterHtml, 'type="submit"'), 'form-control-newsletter-spatial-label-submit-button');

    $fieldShellResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Field Shell Fixture',
        'nodes' => array(
            array(
                'id'       => 'field-shell:root',
                'type'     => 'FRAME',
                'name'     => 'Contact Form',
                'width'    => 520,
                'height'   => 240,
                'children' => array(
                    array('id' => 'field-shell:email', 'type' => 'FRAME', 'name' => 'Light/Field/Default', 'width' => 374, 'height' => 48, 'children' => array(
                        array('id' => 'field-shell:email-bg', 'type' => 'RECTANGLE', 'name' => 'Rectangle', 'width' => 374, 'height' => 48, 'cornerRadius' => 10, 'fills' => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 1, 'b' => 1, 'a' => 1))), 'strokePaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0.8, 'g' => 0.8, 'b' => 0.8, 'a' => 1)))),
                        array('id' => 'field-shell:email-placeholder', 'type' => 'TEXT', 'name' => 'Placeholder', 'characters' => 'Your email', 'fontSize' => 16),
                    )),
                    array('id' => 'field-shell:message', 'type' => 'FRAME', 'name' => 'Light/Field/Default', 'width' => 374, 'height' => 96, 'children' => array(
                        array('id' => 'field-shell:message-bg', 'type' => 'RECTANGLE', 'name' => 'Rectangle', 'width' => 374, 'height' => 96, 'cornerRadius' => 10, 'fills' => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 1, 'b' => 1, 'a' => 1))), 'strokePaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0.8, 'g' => 0.8, 'b' => 0.8, 'a' => 1)))),
                        array('id' => 'field-shell:message-placeholder', 'type' => 'TEXT', 'name' => 'Placeholder', 'characters' => 'Message', 'fontSize' => 16),
                    )),
                    array('id' => 'field-shell:submit', 'type' => 'FRAME', 'name' => 'Button', 'width' => 120, 'height' => 48, 'children' => array(
                        array('id' => 'field-shell:submit-text', 'type' => 'TEXT', 'name' => 'Button', 'characters' => 'Submit', 'fontSize' => 16),
                    )),
                ),
            ),
        ),
    ));
    $fieldShellHtml = $fileContent($fieldShellResult, 'index.html');
    $assert(str_contains($fieldShellHtml, 'data-figma-synthetic-control="input"') && str_contains($fieldShellHtml, 'type="email" name="email" placeholder="Your email"'), 'form-control-field-shell-emits-synthetic-email-input');
    $assert(str_contains($fieldShellHtml, 'data-figma-node-id="field-shell:email-bg"'), 'form-control-field-shell-keeps-email-chrome');
    $assert(! str_contains($fieldShellHtml, 'data-figma-node-id="field-shell:email-placeholder"'), 'form-control-field-shell-suppresses-email-placeholder');
    $assert(str_contains($fieldShellHtml, 'data-figma-synthetic-control="textarea"') && str_contains($fieldShellHtml, 'name="message-field-shell-message" placeholder="Message"'), 'form-control-field-shell-emits-synthetic-message-textarea');

    $duplicateTextareaResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Duplicate Textarea Names Fixture',
        'nodes' => array(
            array(
                'id'       => 'duplicate-textarea:root',
                'type'     => 'FRAME',
                'name'     => 'Comment Form',
                'width'    => 640,
                'height'   => 360,
                'children' => array(
                    array('id' => 'duplicate-textarea:first', 'type' => 'FRAME', 'name' => 'Comment textarea', 'width' => 420, 'height' => 128, 'cornerRadius' => 4, 'fills' => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 1, 'b' => 1, 'a' => 1))), 'children' => array(
                        array('id' => 'duplicate-textarea:first-placeholder', 'type' => 'TEXT', 'name' => 'Placeholder', 'characters' => 'Leave a comment'),
                    )),
                    array('id' => 'duplicate-textarea:second', 'type' => 'FRAME', 'name' => 'Comment textarea', 'width' => 420, 'height' => 128, 'cornerRadius' => 4, 'fills' => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 1, 'b' => 1, 'a' => 1))), 'children' => array(
                        array('id' => 'duplicate-textarea:second-placeholder', 'type' => 'TEXT', 'name' => 'Placeholder', 'characters' => 'Leave a comment'),
                    )),
                    array('id' => 'duplicate-textarea:submit', 'type' => 'FRAME', 'name' => 'Submit button', 'width' => 120, 'height' => 44, 'cornerRadius' => 8, 'fills' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 0, 'a' => 1))), 'children' => array(
                        array('id' => 'duplicate-textarea:submit-text', 'type' => 'TEXT', 'name' => 'Button', 'characters' => 'Post Comment'),
                    )),
                ),
            ),
        ),
    ));
    $duplicateTextareaHtml = $fileContent($duplicateTextareaResult, 'index.html');
    $assert(str_contains($duplicateTextareaHtml, 'name="comment-duplicate-textarea-first"') && str_contains($duplicateTextareaHtml, 'name="comment-duplicate-textarea-second"'), 'form-control-textarea-names-derive-from-node-id');
    $assert(0 === preg_match('/name="comment"/', $duplicateTextareaHtml), 'form-control-textarea-does-not-emit-duplicate-generic-name');

    $outsideSubmitResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Action Button Outside Form Fixture',
        'nodes' => array(
            array(
                'id'       => 'outside-submit:root',
                'type'     => 'FRAME',
                'name'     => 'Hero actions',
                'children' => array(
                    array('id' => 'outside-submit:button', 'type' => 'FRAME', 'name' => 'Subscribe button', 'width' => 140, 'height' => 44, 'cornerRadius' => 999, 'fills' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 0, 'a' => 1))), 'children' => array(
                        array('id' => 'outside-submit:button-text', 'type' => 'TEXT', 'name' => 'Button', 'characters' => 'Subscribe'),
                    )),
                ),
            ),
        ),
    ));
    $outsideSubmitHtml = $fileContent($outsideSubmitResult, 'index.html');
    $assert(str_contains($outsideSubmitHtml, '<button class="figma-node-outside-submit-button-subscribe-button"') && str_contains($outsideSubmitHtml, 'type="button" data-figma-action-intent="submit"'), 'form-control-outside-submit-button-emits-safe-action-metadata');
}
