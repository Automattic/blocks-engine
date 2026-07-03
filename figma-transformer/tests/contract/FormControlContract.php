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
    $assert(str_contains($html, '<input class="figma-node-form-search-icon-search-field-with-icon__control" data-figma-synthetic-control="input" type="search" name="s" placeholder="Search for..."'), 'form-control-search-icon-emits-nested-input');
    $assert(! str_contains($html, 'data-figma-node-id="form:search-icon:text"'), 'form-control-search-icon-suppresses-presentational-placeholder');
    $assert(str_contains($css, '.figma-node-form-search-icon-search-field-with-icon__control{border:0;background:transparent;padding:0;margin:0;min-width:0;flex:1;font:inherit;color:inherit;outline:none}'), 'form-control-search-icon-input-reset-css');
    $assert(str_contains($html, '<textarea class="figma-node-form-comment-comment-textarea"'), 'form-control-comment-emits-textarea-tag');
    $assert(str_contains($html, 'placeholder="Leave a comment"'), 'form-control-comment-uses-placeholder');
    $assert(! str_contains($html, 'data-figma-node-id="form:comment:text"'), 'form-control-comment-suppresses-presentational-text-child');
    $assert(str_contains($html, '<button class="figma-node-form-submit-submit-button"'), 'form-control-submit-emits-button-tag');
    $assert(str_contains($html, 'type="submit"'), 'form-control-submit-infers-submit-type');
    $assert(str_contains($css, '.figma-node-form-input-input{width:280px;height:46px;'), 'form-control-input-preserves-visual-css');
    $assert(str_contains($css, '.figma-node-form-comment-comment-textarea{width:420px;height:128px;'), 'form-control-textarea-preserves-visual-css');

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
}
