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
    $assert(str_contains($css, '.figma-node-form-input-input{width:280px;height:46px;'), 'form-control-input-preserves-visual-css');
}
